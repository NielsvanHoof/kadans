package thumbnail_test

import (
	"bytes"
	"context"
	"image"
	"image/color"
	"image/jpeg"
	"image/png"
	"os"
	"testing"

	"github.com/NielsvanHoof/qompose/apps/lambdas/document-scan/internal/thumbnail"
)

// One renderer for the package, as in the Lambda: PDFium is expensive to start
// and is built once at cold start and reused across invocations.
var renderer *thumbnail.Renderer

func TestMain(m *testing.M) {
	created, err := thumbnail.NewRenderer(context.Background())
	if err != nil {
		panic("start renderer: " + err.Error())
	}

	renderer = created
	code := m.Run()
	_ = renderer.Close()

	os.Exit(code)
}

func TestRenderableCoversTheFormatsWeAccept(t *testing.T) {
	tests := map[string]bool{
		"tenants/1/dossiers/2/a.pdf":  true,
		"tenants/1/dossiers/2/a.jpg":  true,
		"tenants/1/dossiers/2/a.jpeg": true,
		"tenants/1/dossiers/2/a.png":  true,
		"tenants/1/dossiers/2/a.webp": true,
		"tenants/1/dossiers/2/A.PDF":  true,

		// Office formats fall back to the file-type icon; rendering them means
		// LibreOffice, a container image and a different order of complexity.
		"tenants/1/dossiers/2/a.docx": false,
		"tenants/1/dossiers/2/a.xlsx": false,
		"tenants/1/dossiers/2/a.doc":  false,
		"tenants/1/dossiers/2/a.mp3":  false,
		"tenants/1/dossiers/2/a":      false,
	}

	for key, want := range tests {
		if got := thumbnail.Renderable(key); got != want {
			t.Errorf("Renderable(%q) = %v, want %v", key, got, want)
		}
	}
}

func TestRenderRasterisesTheFirstPageOfAPDF(t *testing.T) {
	data, err := os.ReadFile("../../testdata/document.pdf")
	if err != nil {
		t.Fatalf("read fixture: %v", err)
	}

	rendered, err := renderer.Render(context.Background(), "tenants/1/dossiers/2/a.pdf", data)
	if err != nil {
		t.Fatalf("Render() returned %v", err)
	}

	bounds := decode(t, rendered)

	if bounds.Dx() > thumbnail.MaxWidth || bounds.Dy() > thumbnail.MaxHeight {
		t.Errorf("thumbnail is %dx%d, want it inside %dx%d",
			bounds.Dx(), bounds.Dy(), thumbnail.MaxWidth, thumbnail.MaxHeight)
	}

	// A4 is portrait, and fitting must preserve that rather than stretch to the box.
	if bounds.Dy() <= bounds.Dx() {
		t.Errorf("thumbnail is %dx%d, want it taller than wide for an A4 page", bounds.Dx(), bounds.Dy())
	}
}

// A rendering failure must cost the thumbnail and nothing else — the caller
// still has a verdict to publish.
func TestRenderFailsOnAMalformedPDFWithoutPanicking(t *testing.T) {
	data, err := os.ReadFile("../../testdata/malformed.pdf")
	if err != nil {
		t.Fatalf("read fixture: %v", err)
	}

	if _, err := renderer.Render(context.Background(), "tenants/1/dossiers/2/a.pdf", data); err == nil {
		t.Fatal("Render() succeeded on a malformed PDF, want an error")
	}
}

func TestRenderRejectsFormatsItCannotDraw(t *testing.T) {
	if _, err := renderer.Render(context.Background(), "tenants/1/dossiers/2/a.docx", []byte("PK\x03\x04")); err == nil {
		t.Fatal("Render() succeeded on a .docx, want an error")
	}
}

func TestRenderScalesALargeImageIntoTheBox(t *testing.T) {
	source := image.NewRGBA(image.Rect(0, 0, 2000, 1000))
	for x := range 2000 {
		for y := range 1000 {
			source.Set(x, y, color.RGBA{R: uint8(x % 256), G: uint8(y % 256), B: 120, A: 255})
		}
	}

	var encoded bytes.Buffer
	if err := jpeg.Encode(&encoded, source, nil); err != nil {
		t.Fatalf("encode fixture: %v", err)
	}

	rendered, err := renderer.Render(context.Background(), "tenants/1/dossiers/2/a.jpg", encoded.Bytes())
	if err != nil {
		t.Fatalf("Render() returned %v", err)
	}

	bounds := decode(t, rendered)

	if bounds.Dx() != thumbnail.MaxWidth {
		t.Errorf("width = %d, want %d — a 2:1 image should be bounded by width", bounds.Dx(), thumbnail.MaxWidth)
	}
	if bounds.Dy() != thumbnail.MaxWidth/2 {
		t.Errorf("height = %d, want %d — aspect ratio should be preserved", bounds.Dy(), thumbnail.MaxWidth/2)
	}
}

// An image already smaller than the box is left alone; blowing it up looks worse.
func TestRenderDoesNotEnlargeASmallImage(t *testing.T) {
	source := image.NewRGBA(image.Rect(0, 0, 40, 30))

	var encoded bytes.Buffer
	if err := png.Encode(&encoded, source); err != nil {
		t.Fatalf("encode fixture: %v", err)
	}

	rendered, err := renderer.Render(context.Background(), "tenants/1/dossiers/2/a.png", encoded.Bytes())
	if err != nil {
		t.Fatalf("Render() returned %v", err)
	}

	if bounds := decode(t, rendered); bounds.Dx() != 40 || bounds.Dy() != 30 {
		t.Errorf("thumbnail is %dx%d, want the original 40x30", bounds.Dx(), bounds.Dy())
	}
}

// JPEG has no alpha channel, so a transparent source must be flattened onto
// white first. Without that, a scan on a transparent background arrives as a
// black rectangle.
func TestRenderFlattensTransparencyOntoWhiteRatherThanBlack(t *testing.T) {
	source := image.NewRGBA(image.Rect(0, 0, 60, 60)) // Zero value: fully transparent.

	var encoded bytes.Buffer
	if err := png.Encode(&encoded, source); err != nil {
		t.Fatalf("encode fixture: %v", err)
	}

	rendered, err := renderer.Render(context.Background(), "tenants/1/dossiers/2/a.png", encoded.Bytes())
	if err != nil {
		t.Fatalf("Render() returned %v", err)
	}

	decoded, err := jpeg.Decode(bytes.NewReader(rendered))
	if err != nil {
		t.Fatalf("decode thumbnail: %v", err)
	}

	r, g, b, _ := decoded.At(30, 30).RGBA()
	if r>>8 < 250 || g>>8 < 250 || b>>8 < 250 {
		t.Errorf("transparent pixel rendered as rgb(%d,%d,%d), want near-white", r>>8, g>>8, b>>8)
	}
}

// decode asserts the output really is a JPEG and returns its bounds.
func decode(t *testing.T, data []byte) image.Rectangle {
	t.Helper()

	if len(data) == 0 {
		t.Fatal("thumbnail is empty")
	}

	config, format, err := image.DecodeConfig(bytes.NewReader(data))
	if err != nil {
		t.Fatalf("decode thumbnail: %v", err)
	}

	if format != "jpeg" {
		t.Errorf("thumbnail format = %q, want jpeg", format)
	}

	return image.Rect(0, 0, config.Width, config.Height)
}
