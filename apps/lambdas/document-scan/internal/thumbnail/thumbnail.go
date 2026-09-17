// Package thumbnail renders the first page of a document to a small JPEG.
//
// This is the work the platform deliberately does not do in PHP: there is no
// Imagick, Ghostscript or PDF library in the application, and uploaded bytes go
// straight from the browser to S3 without a PHP process ever seeing them. The
// bytes handled here are untrusted by definition, which drives most of the
// decisions below.
package thumbnail

import (
	"bytes"
	"context"
	"fmt"
	"image"
	"image/color"
	"image/draw"
	"image/jpeg"
	"path"
	"strings"
	"time"

	"github.com/klippa-app/go-pdfium"
	"github.com/klippa-app/go-pdfium/requests"
	"github.com/klippa-app/go-pdfium/webassembly"
	"github.com/tetratelabs/wazero"

	// Decoders for the formats we accept. JPEG and PNG register themselves with
	// image.Decode; WebP is decode-only in x/image, which is also why thumbnails
	// are written as JPEG rather than WebP.
	_ "golang.org/x/image/webp"
	_ "image/jpeg"
	_ "image/png"

	xdraw "golang.org/x/image/draw"
)

// Bounds of the rendered thumbnail. The image is fitted inside this box with
// its aspect ratio preserved, so a portrait A4 page comes out taller than wide.
const (
	MaxWidth  = 400
	MaxHeight = 560
)

// jpegQuality trades a few kilobytes against visible artefacts on the thin
// strokes that dominate a page of text.
const jpegQuality = 82

// instanceTimeout bounds waiting for a PDFium worker, not the render itself.
const instanceTimeout = 10 * time.Second

// renderable is the set of extensions worth attempting.
//
// Office formats are absent on purpose. Rendering them means LibreOffice, which
// means a container image and a different order of complexity; the application
// falls back to a file-type icon for them, which is what it already shows today.
var renderable = map[string]bool{
	".pdf":  true,
	".jpg":  true,
	".jpeg": true,
	".png":  true,
	".webp": true,
}

// Renderable reports whether a thumbnail is worth attempting for this key.
func Renderable(key string) bool {
	return renderable[strings.ToLower(path.Ext(key))]
}

// Renderer turns document bytes into thumbnail bytes.
//
// It owns a PDFium pool, which is expensive to build and safe to keep, so one
// Renderer is constructed at cold start and reused across invocations.
type Renderer struct {
	pool pdfium.Pool
}

// NewRenderer starts PDFium.
//
// PDFium here is WebAssembly running under wazero rather than the usual cgo
// build. That keeps the function a single static binary shippable as a plain
// zip — which matters, because this stack deliberately has no ECR to push a
// container image to — and it cross-compiles to arm64 from any host. It costs
// roughly 2x the render time of native cgo, which is nothing on an async path.
//
// The sandbox is the other half of the argument. A malformed PDF is the classic
// way into a C rendering library, and under wazero that code runs inside a
// WebAssembly module: a crash cannot take down the Go process, and nothing
// reaches the host beyond the memory the module was given.
//
// FSConfig is set to an empty configuration deliberately. Left nil, go-pdfium
// mounts the host's entire root filesystem into the sandbox — a sensible
// default for a library that opens files by path, and exactly wrong for a
// process whose only input is an untrusted download. We open documents from
// memory, so the module needs no filesystem at all.
func NewRenderer(ctx context.Context) (*Renderer, error) {
	pool, err := webassembly.Init(webassembly.Config{
		Context:  ctx,
		MinIdle:  1,
		MaxIdle:  1,
		MaxTotal: 1,
		FSConfig: wazero.NewFSConfig(),
	})
	if err != nil {
		return nil, fmt.Errorf("start pdfium: %w", err)
	}

	return &Renderer{pool: pool}, nil
}

// Close releases the PDFium pool.
func (r *Renderer) Close() error {
	return r.pool.Close()
}

// Render produces JPEG thumbnail bytes for a document.
//
// The key is used only for its extension, to pick a decoder.
func (r *Renderer) Render(ctx context.Context, key string, data []byte) ([]byte, error) {
	if !Renderable(key) {
		return nil, fmt.Errorf("no renderer for %q", path.Ext(key))
	}

	var (
		source image.Image
		err    error
	)

	if strings.EqualFold(path.Ext(key), ".pdf") {
		var cleanup func()

		source, cleanup, err = r.renderFirstPage(ctx, data)
		if cleanup != nil {
			// Under WebAssembly the pixel buffer is only valid until cleanup
			// runs, so the image is copied into the thumbnail before this fires.
			defer cleanup()
		}
	} else {
		source, _, err = image.Decode(bytes.NewReader(data))
	}

	if err != nil {
		return nil, err
	}

	return encode(fit(source))
}

// renderFirstPage rasterises page one of a PDF.
//
// Only the first page: a thumbnail shows what a document looks like, and a
// hundred-page annual report costs the same as a one-page invoice this way.
func (r *Renderer) renderFirstPage(ctx context.Context, data []byte) (image.Image, func(), error) {
	instance, err := r.pool.GetInstanceWithContext(ctx)
	if err != nil {
		return nil, nil, fmt.Errorf("acquire pdfium instance: %w", err)
	}

	closeInstance := func() { _ = instance.Close() }

	document, err := instance.OpenDocument(&requests.OpenDocument{File: &data})
	if err != nil {
		closeInstance()

		return nil, nil, fmt.Errorf("open pdf: %w", err)
	}

	closeDocument := func() {
		_, _ = instance.FPDF_CloseDocument(&requests.FPDF_CloseDocument{Document: document.Document})
		closeInstance()
	}

	rendered, err := instance.RenderPageInPixels(&requests.RenderPageInPixels{
		Page: requests.Page{
			ByIndex: &requests.PageByIndex{Document: document.Document, Index: 0},
		},
		Width:  MaxWidth,
		Height: MaxHeight,
	})
	if err != nil {
		closeDocument()

		return nil, nil, fmt.Errorf("render first page: %w", err)
	}

	return rendered.Result.RenderedImage, func() {
		rendered.Cleanup()
		closeDocument()
	}, nil
}

// fit scales an image down to sit inside the thumbnail box and flattens it onto
// white.
//
// The flattening is not cosmetic. JPEG has no alpha channel, and encoding a
// transparent PNG straight to JPEG renders every transparent pixel black — a
// scanned receipt on a transparent background would arrive as a black rectangle.
//
// Images already smaller than the box are not scaled up; a small image blown up
// looks worse than a small image.
func fit(source image.Image) image.Image {
	bounds := source.Bounds()
	width, height := bounds.Dx(), bounds.Dy()

	if width <= 0 || height <= 0 {
		width, height = 1, 1
	}

	scale := min(
		float64(MaxWidth)/float64(width),
		float64(MaxHeight)/float64(height),
	)
	if scale > 1 {
		scale = 1
	}

	target := image.Rect(0, 0, max(int(float64(width)*scale), 1), max(int(float64(height)*scale), 1))
	canvas := image.NewRGBA(target)

	draw.Draw(canvas, target, image.NewUniform(color.White), image.Point{}, draw.Src)
	// Over, not Src, so the white underneath shows through transparency.
	xdraw.CatmullRom.Scale(canvas, target, source, bounds, xdraw.Over, nil)

	return canvas
}

func encode(img image.Image) ([]byte, error) {
	var buffer bytes.Buffer

	if err := jpeg.Encode(&buffer, img, &jpeg.Options{Quality: jpegQuality}); err != nil {
		return nil, fmt.Errorf("encode thumbnail: %w", err)
	}

	return buffer.Bytes(), nil
}
