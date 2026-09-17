// Package handler holds the flow this function performs, free of AWS wiring.
package handler

import (
	"context"
	"encoding/json"
	"errors"
	"log/slog"
	"time"

	"github.com/NielsvanHoof/qompose/apps/lambdas/document-scan/internal/publish"
	"github.com/NielsvanHoof/qompose/apps/lambdas/document-scan/internal/scanevent"
	"github.com/NielsvanHoof/qompose/apps/lambdas/document-scan/internal/thumbnail"
)

// ErrTooLarge is returned by a Downloader asked for an object above the cap.
//
// It is a skip, not a failure: the verdict still goes out, the document simply
// keeps its file-type icon.
var ErrTooLarge = errors.New("object is larger than the render cap")

// thumbnailBudget bounds the whole optional half of the work — fetching the
// object, rendering it and storing the result.
//
// Without it the only bound is the Lambda timeout, and a single pathological
// document would spend the entire invocation before the verdict is published.
// The verdict is what must not be lost, so the thumbnail gets a budget and the
// remainder of the invocation belongs to delivering the verdict.
const thumbnailBudget = 30 * time.Second

type Downloader interface {
	Download(ctx context.Context, bucket, key string) ([]byte, error)
}

type Uploader interface {
	Upload(ctx context.Context, bucket, key, contentType string, body []byte) error
}

type Renderer interface {
	Render(ctx context.Context, key string, data []byte) ([]byte, error)
}

type Publisher interface {
	Send(ctx context.Context, verdict publish.Verdict) error
}

type Handler struct {
	downloader Downloader
	uploader   Uploader
	renderer   Renderer
	publisher  Publisher
	logger     *slog.Logger
}

func New(downloader Downloader, uploader Uploader, renderer Renderer, publisher Publisher, logger *slog.Logger) *Handler {
	return &Handler{
		downloader: downloader,
		uploader:   uploader,
		renderer:   renderer,
		publisher:  publisher,
		logger:     logger,
	}
}

// Handle processes one GuardDuty scan result.
//
// The ordering is the whole point of triggering on the scan rather than on the
// upload: bytes are only ever downloaded and parsed after GuardDuty has
// declared them clean.
//
// Two kinds of outcome are deliberately not errors. An event for a key outside
// the dossier tree is ordinary traffic — a claimed upload is copied out of
// staging/, and this function's own thumbnails get scanned in turn — and is
// dropped. A thumbnail that cannot be produced is logged and omitted. Only a
// verdict that cannot be understood or cannot be delivered fails the
// invocation, because both leave a document showing "scanning" forever, and the
// dead-letter queue is alarmed precisely so someone finds out.
func (h *Handler) Handle(ctx context.Context, raw json.RawMessage) error {
	result, err := scanevent.Parse(raw)
	if err != nil {
		return err
	}

	logger := h.logger.With(
		slog.String("bucket", result.Bucket),
		slog.String("key", result.Key),
		slog.String("status", string(result.Status)),
	)

	if !scanevent.IsActionable(result.Key) {
		logger.Debug("ignoring a scan result outside the dossier tree")

		return nil
	}

	verdict := publish.Verdict{
		Key:       result.Key,
		VersionID: result.VersionID,
		Status:    string(result.Status),
		ScannedAt: result.ScannedAt,
	}

	if thumbnailKey, ok := h.thumbnail(ctx, result, logger); ok {
		verdict.ThumbnailKey = thumbnailKey
	}

	if err := h.publisher.Send(ctx, verdict); err != nil {
		return err
	}

	logger.Info("published scan verdict", slog.Bool("thumbnail", verdict.ThumbnailKey != ""))

	return nil
}

// thumbnail renders and stores a thumbnail, reporting whether one exists.
//
// Every failure here is swallowed after logging. The verdict is the
// security-relevant half of this function's output and must not be lost because
// a PDF was malformed or S3 was briefly unhappy.
func (h *Handler) thumbnail(ctx context.Context, result scanevent.Result, logger *slog.Logger) (string, bool) {
	if !result.Status.IsClean() {
		return "", false
	}

	if !thumbnail.Renderable(result.Key) {
		return "", false
	}

	ctx, cancel := context.WithTimeout(ctx, thumbnailBudget)
	defer cancel()

	data, err := h.downloader.Download(ctx, result.Bucket, result.Key)
	if err != nil {
		if errors.Is(err, ErrTooLarge) {
			logger.Info("skipping thumbnail, object is above the render cap")
		} else {
			logger.Warn("skipping thumbnail, download failed", slog.Any("error", err))
		}

		return "", false
	}

	rendered, err := h.renderer.Render(ctx, result.Key, data)
	if err != nil {
		logger.Warn("skipping thumbnail, render failed", slog.Any("error", err))

		return "", false
	}

	thumbnailKey := scanevent.ThumbnailKey(result.Key)

	if err := h.uploader.Upload(ctx, result.Bucket, thumbnailKey, "image/jpeg", rendered); err != nil {
		logger.Warn("skipping thumbnail, upload failed", slog.Any("error", err))

		return "", false
	}

	return thumbnailKey, true
}
