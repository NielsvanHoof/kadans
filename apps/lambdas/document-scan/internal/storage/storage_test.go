package storage_test

import (
	"bytes"
	"context"
	"errors"
	"io"
	"testing"

	"github.com/aws/aws-sdk-go-v2/aws"
	"github.com/aws/aws-sdk-go-v2/service/s3"

	"github.com/NielsvanHoof/qompose/apps/lambdas/document-scan/internal/handler"
	"github.com/NielsvanHoof/qompose/apps/lambdas/document-scan/internal/storage"
)

type fakeS3 struct {
	body          []byte
	contentLength *int64
	put           *s3.PutObjectInput
	getErr        error
}

func (f *fakeS3) GetObject(context.Context, *s3.GetObjectInput, ...func(*s3.Options)) (*s3.GetObjectOutput, error) {
	if f.getErr != nil {
		return nil, f.getErr
	}

	return &s3.GetObjectOutput{
		Body:          io.NopCloser(bytes.NewReader(f.body)),
		ContentLength: f.contentLength,
	}, nil
}

func (f *fakeS3) PutObject(_ context.Context, params *s3.PutObjectInput, _ ...func(*s3.Options)) (*s3.PutObjectOutput, error) {
	f.put = params

	return &s3.PutObjectOutput{}, nil
}

func TestDownloadReturnsTheObject(t *testing.T) {
	client := &fakeS3{body: []byte("document"), contentLength: aws.Int64(8)}

	data, err := storage.NewStore(client, 1024).Download(context.Background(), "documents", "tenants/1/dossiers/2/a.pdf")
	if err != nil {
		t.Fatalf("Download() returned %v", err)
	}

	if string(data) != "document" {
		t.Errorf("data = %q", data)
	}
}

func TestDownloadRefusesAnObjectAboveTheCap(t *testing.T) {
	client := &fakeS3{body: bytes.Repeat([]byte("x"), 200), contentLength: aws.Int64(200)}

	_, err := storage.NewStore(client, 100).Download(context.Background(), "documents", "tenants/1/dossiers/2/a.pdf")

	if !errors.Is(err, handler.ErrTooLarge) {
		t.Fatalf("Download() returned %v, want ErrTooLarge", err)
	}
}

// Content-Length is a claim by the caller's peer, not a fact. If it is missing
// or wrong, the reader still has to hold the line.
func TestDownloadRefusesAnOversizedBodyWhenContentLengthIsAbsent(t *testing.T) {
	client := &fakeS3{body: bytes.Repeat([]byte("x"), 200)}

	_, err := storage.NewStore(client, 100).Download(context.Background(), "documents", "tenants/1/dossiers/2/a.pdf")

	if !errors.Is(err, handler.ErrTooLarge) {
		t.Fatalf("Download() returned %v, want ErrTooLarge", err)
	}
}

func TestDownloadAllowsAnObjectExactlyAtTheCap(t *testing.T) {
	client := &fakeS3{body: bytes.Repeat([]byte("x"), 100), contentLength: aws.Int64(100)}

	if _, err := storage.NewStore(client, 100).Download(context.Background(), "documents", "tenants/1/dossiers/2/a.pdf"); err != nil {
		t.Fatalf("Download() returned %v, want the boundary allowed", err)
	}
}

func TestDownloadReportsAFailedGet(t *testing.T) {
	client := &fakeS3{getErr: errors.New("no such key")}

	if _, err := storage.NewStore(client, 100).Download(context.Background(), "documents", "k"); err == nil {
		t.Fatal("Download() succeeded, want an error")
	}
}

// No encryption parameters: the bucket's default encryption is SSE-KMS under
// the documents CMK, so naming the key here would only add a second place for
// it to be wrong.
func TestUploadWritesTheBodyAndContentTypeAndLeavesEncryptionToTheBucket(t *testing.T) {
	client := &fakeS3{}

	err := storage.NewStore(client, 1024).Upload(
		context.Background(), "documents", "thumbnails/tenants/1/dossiers/2/a.jpg", "image/jpeg", []byte("jpeg"),
	)
	if err != nil {
		t.Fatalf("Upload() returned %v", err)
	}

	if got := *client.put.Key; got != "thumbnails/tenants/1/dossiers/2/a.jpg" {
		t.Errorf("Key = %q", got)
	}
	if got := *client.put.ContentType; got != "image/jpeg" {
		t.Errorf("ContentType = %q", got)
	}
	if client.put.ServerSideEncryption != "" || client.put.SSEKMSKeyId != nil {
		t.Error("upload named an encryption key, want the bucket default to apply")
	}

	body, err := io.ReadAll(client.put.Body)
	if err != nil {
		t.Fatalf("read body: %v", err)
	}
	if string(body) != "jpeg" {
		t.Errorf("body = %q", body)
	}
}
