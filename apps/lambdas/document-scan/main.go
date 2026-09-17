// Command document-scan renders a thumbnail for documents GuardDuty has
// declared clean, and reports every scan verdict back to the platform.
//
// See ../README.md for how this folder is built and deployed, and
// internal/handler for what it actually does.
package main

import (
	"context"
	"encoding/json"
	"fmt"
	"log/slog"
	"os"
	"strconv"

	"github.com/aws/aws-lambda-go/lambda"
	awsconfig "github.com/aws/aws-sdk-go-v2/config"
	"github.com/aws/aws-sdk-go-v2/service/s3"
	"github.com/aws/aws-sdk-go-v2/service/sqs"

	"github.com/NielsvanHoof/qompose/apps/lambdas/document-scan/internal/handler"
	"github.com/NielsvanHoof/qompose/apps/lambdas/document-scan/internal/publish"
	"github.com/NielsvanHoof/qompose/apps/lambdas/document-scan/internal/storage"
	"github.com/NielsvanHoof/qompose/apps/lambdas/document-scan/internal/thumbnail"
)

// defaultMaxRenderBytes bounds what this function will pull into memory.
//
// Comfortably above a scanned bank statement and comfortably below anything
// that would threaten the function's memory. An object above it keeps its
// file-type icon; it is not an error.
const defaultMaxRenderBytes int64 = 50 << 20

func main() {
	logger := slog.New(slog.NewJSONHandler(os.Stdout, nil))

	// Everything here runs once per cold start, not once per event. PDFium in
	// particular is expensive to start and cheap to keep.
	handle, err := build(context.Background(), logger)
	if err != nil {
		logger.Error("startup failed", slog.Any("error", err))
		os.Exit(1)
	}

	lambda.Start(handle)
}

func build(ctx context.Context, logger *slog.Logger) (func(context.Context, json.RawMessage) error, error) {
	queueURL := os.Getenv("SCAN_RESULTS_QUEUE_URL")
	if queueURL == "" {
		return nil, fmt.Errorf("SCAN_RESULTS_QUEUE_URL is not set")
	}

	awsConfig, err := awsconfig.LoadDefaultConfig(ctx)
	if err != nil {
		return nil, fmt.Errorf("load aws config: %w", err)
	}

	renderer, err := thumbnail.NewRenderer(ctx)
	if err != nil {
		return nil, err
	}

	store := storage.NewStore(s3.NewFromConfig(awsConfig), maxRenderBytes(logger))
	publisher := publish.NewPublisher(sqs.NewFromConfig(awsConfig), queueURL)

	return handler.New(store, store, renderer, publisher, logger).Handle, nil
}

// maxRenderBytes reads the cap from the environment, falling back to the
// default. A value that will not parse is reported and ignored rather than
// taken as zero, which would silently stop every thumbnail.
func maxRenderBytes(logger *slog.Logger) int64 {
	raw := os.Getenv("MAX_RENDER_BYTES")
	if raw == "" {
		return defaultMaxRenderBytes
	}

	parsed, err := strconv.ParseInt(raw, 10, 64)
	if err != nil || parsed <= 0 {
		logger.Warn("ignoring an unusable MAX_RENDER_BYTES", slog.String("value", raw))

		return defaultMaxRenderBytes
	}

	return parsed
}
