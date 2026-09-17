package handler_test

import (
	"context"
	"errors"
	"fmt"
	"io"
	"log/slog"
	"testing"

	"github.com/NielsvanHoof/qompose/apps/lambdas/document-scan/internal/handler"
	"github.com/NielsvanHoof/qompose/apps/lambdas/document-scan/internal/publish"
	"github.com/NielsvanHoof/qompose/apps/lambdas/document-scan/internal/scanevent"
)

const documentKey = "tenants/12/dossiers/34/01JBQ8F7N3K9X2M5P6R7S8T9V0.pdf"

type fakeDownloader struct {
	called bool
	err    error
}

func (f *fakeDownloader) Download(context.Context, string, string) ([]byte, error) {
	f.called = true

	return []byte("pdf bytes"), f.err
}

type fakeUploader struct {
	key         string
	contentType string
	body        []byte
	err         error
}

func (f *fakeUploader) Upload(_ context.Context, _, key, contentType string, body []byte) error {
	f.key, f.contentType, f.body = key, contentType, body

	return f.err
}

type fakeRenderer struct {
	err error
}

func (f *fakeRenderer) Render(context.Context, string, []byte) ([]byte, error) {
	return []byte("jpeg bytes"), f.err
}

type fakePublisher struct {
	verdicts []publish.Verdict
	err      error
}

func (f *fakePublisher) Send(_ context.Context, verdict publish.Verdict) error {
	f.verdicts = append(f.verdicts, verdict)

	return f.err
}

type harness struct {
	downloader *fakeDownloader
	uploader   *fakeUploader
	renderer   *fakeRenderer
	publisher  *fakePublisher
	handler    *handler.Handler
}

func newHarness() *harness {
	h := &harness{
		downloader: &fakeDownloader{},
		uploader:   &fakeUploader{},
		renderer:   &fakeRenderer{},
		publisher:  &fakePublisher{},
	}
	h.handler = handler.New(h.downloader, h.uploader, h.renderer, h.publisher,
		slog.New(slog.NewTextHandler(io.Discard, nil)))

	return h
}

func event(scanResultStatus, key string) []byte {
	return []byte(fmt.Sprintf(`{
	  "detail-type": %q,
	  "time": "2026-09-16T10:30:00Z",
	  "detail": {
	    "scanStatus": "COMPLETED",
	    "s3ObjectDetails": {"bucketName": "documents", "objectKey": %q, "versionId": "v1"},
	    "scanResultDetails": {"scanResultStatus": %q}
	  }
	}`, scanevent.DetailType, key, scanResultStatus))
}

func TestACleanDocumentIsRenderedAndReported(t *testing.T) {
	h := newHarness()

	if err := h.handler.Handle(context.Background(), event("NO_THREATS_FOUND", documentKey)); err != nil {
		t.Fatalf("Handle() returned %v", err)
	}

	if len(h.publisher.verdicts) != 1 {
		t.Fatalf("published %d verdicts, want 1", len(h.publisher.verdicts))
	}

	verdict := h.publisher.verdicts[0]

	if verdict.Status != "NO_THREATS_FOUND" || verdict.Key != documentKey || verdict.VersionID != "v1" {
		t.Errorf("verdict = %+v", verdict)
	}
	if want := scanevent.ThumbnailKey(documentKey); verdict.ThumbnailKey != want {
		t.Errorf("ThumbnailKey = %q, want %q", verdict.ThumbnailKey, want)
	}
	if h.uploader.key != verdict.ThumbnailKey {
		t.Errorf("uploaded to %q, want %q", h.uploader.key, verdict.ThumbnailKey)
	}
	if h.uploader.contentType != "image/jpeg" {
		t.Errorf("content type = %q, want image/jpeg", h.uploader.contentType)
	}
}

// The ordering is the security argument for triggering on the scan rather than
// on the upload: bytes are never fetched or parsed until GuardDuty says clean.
func TestBytesAreNeverFetchedForANonCleanVerdict(t *testing.T) {
	for _, status := range []string{"THREATS_FOUND", "UNSUPPORTED", "ACCESS_DENIED", "FAILED"} {
		t.Run(status, func(t *testing.T) {
			h := newHarness()

			if err := h.handler.Handle(context.Background(), event(status, documentKey)); err != nil {
				t.Fatalf("Handle() returned %v", err)
			}

			if h.downloader.called {
				t.Error("downloaded an object GuardDuty did not declare clean")
			}
			if len(h.publisher.verdicts) != 1 {
				t.Fatalf("published %d verdicts, want 1", len(h.publisher.verdicts))
			}
			if verdict := h.publisher.verdicts[0]; verdict.ThumbnailKey != "" {
				t.Errorf("ThumbnailKey = %q, want it empty", verdict.ThumbnailKey)
			}
		})
	}
}

// Keys outside the dossier tree are ordinary traffic, not errors: staging/ is
// the presigned landing zone, and thumbnails/ is this function's own output.
func TestKeysOutsideTheDossierTreeAreDroppedSilently(t *testing.T) {
	keys := []string{
		"staging/tenants/12/01JBQ8F7N3K9X2M5P6R7S8T9V0.pdf",
		scanevent.ThumbnailKey(documentKey),
	}

	for _, key := range keys {
		t.Run(key, func(t *testing.T) {
			h := newHarness()

			if err := h.handler.Handle(context.Background(), event("NO_THREATS_FOUND", key)); err != nil {
				t.Fatalf("Handle() returned %v, want it dropped without error", err)
			}

			if len(h.publisher.verdicts) != 0 {
				t.Errorf("published %d verdicts, want none", len(h.publisher.verdicts))
			}
			if h.downloader.called {
				t.Error("downloaded an object outside the dossier tree")
			}
		})
	}
}

func TestAFormatWithNoRendererStillReportsItsVerdict(t *testing.T) {
	h := newHarness()
	key := "tenants/12/dossiers/34/01JBQ8F7N3K9X2M5P6R7S8T9V0.docx"

	if err := h.handler.Handle(context.Background(), event("NO_THREATS_FOUND", key)); err != nil {
		t.Fatalf("Handle() returned %v", err)
	}

	if h.downloader.called {
		t.Error("downloaded a format with no renderer")
	}
	if len(h.publisher.verdicts) != 1 || h.publisher.verdicts[0].ThumbnailKey != "" {
		t.Errorf("verdicts = %+v, want one with no thumbnail", h.publisher.verdicts)
	}
}

// Every way a thumbnail can fail costs the thumbnail and nothing else.
func TestAThumbnailFailureNeverCostsTheVerdict(t *testing.T) {
	tests := map[string]func(*harness){
		"download failed": func(h *harness) { h.downloader.err = errors.New("no such key") },
		"object too big":  func(h *harness) { h.downloader.err = handler.ErrTooLarge },
		"render failed":   func(h *harness) { h.renderer.err = errors.New("malformed pdf") },
		"upload failed":   func(h *harness) { h.uploader.err = errors.New("access denied") },
	}

	for name, breakIt := range tests {
		t.Run(name, func(t *testing.T) {
			h := newHarness()
			breakIt(h)

			if err := h.handler.Handle(context.Background(), event("NO_THREATS_FOUND", documentKey)); err != nil {
				t.Fatalf("Handle() returned %v, want the verdict published anyway", err)
			}

			if len(h.publisher.verdicts) != 1 {
				t.Fatalf("published %d verdicts, want 1", len(h.publisher.verdicts))
			}
			if verdict := h.publisher.verdicts[0]; verdict.ThumbnailKey != "" {
				t.Errorf("ThumbnailKey = %q, want it empty", verdict.ThumbnailKey)
			}
		})
	}
}

// A verdict that never arrives leaves the document showing "scanning" forever,
// so this is one of only two things that may fail the invocation.
func TestAnUndeliverableVerdictFailsTheInvocation(t *testing.T) {
	h := newHarness()
	h.publisher.err = errors.New("throttled")

	if err := h.handler.Handle(context.Background(), event("NO_THREATS_FOUND", documentKey)); err == nil {
		t.Fatal("Handle() succeeded, want an error so the event source retries")
	}
}

func TestAnUnreadableEventFailsTheInvocation(t *testing.T) {
	h := newHarness()

	if err := h.handler.Handle(context.Background(), []byte(`{"detail-type":`)); err == nil {
		t.Fatal("Handle() succeeded on malformed input, want an error")
	}
}

// The thumbnail path gets its own budget so one pathological document cannot
// spend the whole invocation before the verdict is published. Asserting the
// deadline is passed down is the cheap half; the expensive half is that it
// applies to the download and the render alike, which is why it is checked on
// the first call the path makes.
func TestTheThumbnailPathRunsUnderItsOwnDeadline(t *testing.T) {
	h := newHarness()
	deadlines := &deadlineRecorder{}
	h.handler = handler.New(deadlines, h.uploader, h.renderer, h.publisher,
		slog.New(slog.NewTextHandler(io.Discard, nil)))

	if err := h.handler.Handle(context.Background(), event("NO_THREATS_FOUND", documentKey)); err != nil {
		t.Fatalf("Handle() returned %v", err)
	}

	if !deadlines.sawDeadline {
		t.Error("the download ran without a deadline, so a slow object would consume the invocation")
	}
}

type deadlineRecorder struct {
	sawDeadline bool
}

func (d *deadlineRecorder) Download(ctx context.Context, _, _ string) ([]byte, error) {
	_, d.sawDeadline = ctx.Deadline()

	return []byte("pdf bytes"), nil
}
