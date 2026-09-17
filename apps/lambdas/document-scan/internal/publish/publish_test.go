package publish_test

import (
	"context"
	"encoding/json"
	"errors"
	"testing"
	"time"

	"github.com/aws/aws-sdk-go-v2/service/sqs"

	"github.com/NielsvanHoof/qompose/apps/lambdas/document-scan/internal/publish"
)

type fakeSQS struct {
	input *sqs.SendMessageInput
	err   error
}

func (f *fakeSQS) SendMessage(_ context.Context, params *sqs.SendMessageInput, _ ...func(*sqs.Options)) (*sqs.SendMessageOutput, error) {
	f.input = params

	return &sqs.SendMessageOutput{}, f.err
}

func TestSendWritesTheAgreedWireFormat(t *testing.T) {
	client := &fakeSQS{}
	publisher := publish.NewPublisher(client, "https://sqs.eu-west-1.amazonaws.com/1/scan-results")

	err := publisher.Send(context.Background(), publish.Verdict{
		Key:          "tenants/12/dossiers/34/01JBQ.pdf",
		VersionID:    "v1",
		Status:       "NO_THREATS_FOUND",
		ScannedAt:    time.Date(2026, 9, 16, 10, 30, 0, 0, time.UTC),
		ThumbnailKey: "thumbnails/tenants/12/dossiers/34/01JBQ.jpg",
	})
	if err != nil {
		t.Fatalf("Send() returned %v", err)
	}

	if got := *client.input.QueueUrl; got != "https://sqs.eu-west-1.amazonaws.com/1/scan-results" {
		t.Errorf("QueueUrl = %q", got)
	}

	var body map[string]any
	if err := json.Unmarshal([]byte(*client.input.MessageBody), &body); err != nil {
		t.Fatalf("message body is not JSON: %v", err)
	}

	want := map[string]any{
		"key":          "tenants/12/dossiers/34/01JBQ.pdf",
		"versionId":    "v1",
		"status":       "NO_THREATS_FOUND",
		"scannedAt":    "2026-09-16T10:30:00Z",
		"thumbnailKey": "thumbnails/tenants/12/dossiers/34/01JBQ.jpg",
	}

	for field, expected := range want {
		if body[field] != expected {
			t.Errorf("%s = %v, want %v", field, body[field], expected)
		}
	}
}

// The consumer distinguishes "no thumbnail" from "a thumbnail at an empty key",
// so the field must be absent rather than empty.
func TestSendOmitsTheThumbnailKeyWhenThereIsNone(t *testing.T) {
	client := &fakeSQS{}
	publisher := publish.NewPublisher(client, "queue")

	if err := publisher.Send(context.Background(), publish.Verdict{
		Key:    "tenants/12/dossiers/34/01JBQ.docx",
		Status: "NO_THREATS_FOUND",
	}); err != nil {
		t.Fatalf("Send() returned %v", err)
	}

	var body map[string]any
	if err := json.Unmarshal([]byte(*client.input.MessageBody), &body); err != nil {
		t.Fatalf("message body is not JSON: %v", err)
	}

	if _, present := body["thumbnailKey"]; present {
		t.Error("thumbnailKey is present, want it omitted")
	}
}

// A verdict that never arrives leaves a document showing "scanning" forever, so
// the failure must surface and let the event source retry.
func TestSendReportsAFailureToDeliver(t *testing.T) {
	publisher := publish.NewPublisher(&fakeSQS{err: errors.New("throttled")}, "queue")

	if err := publisher.Send(context.Background(), publish.Verdict{Key: "k", Status: "FAILED"}); err == nil {
		t.Fatal("Send() succeeded, want an error")
	}
}
