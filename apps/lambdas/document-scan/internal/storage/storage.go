// Package storage reads documents from and writes thumbnails to the documents
// bucket.
package storage

import (
	"context"
	"fmt"
	"io"

	"github.com/aws/aws-sdk-go-v2/aws"
	"github.com/aws/aws-sdk-go-v2/service/s3"

	"github.com/NielsvanHoof/qompose/apps/lambdas/document-scan/internal/handler"
)

// S3API is the slice of the S3 client this package uses.
type S3API interface {
	GetObject(ctx context.Context, params *s3.GetObjectInput, optFns ...func(*s3.Options)) (*s3.GetObjectOutput, error)
	PutObject(ctx context.Context, params *s3.PutObjectInput, optFns ...func(*s3.Options)) (*s3.PutObjectOutput, error)
}

// Store reads and writes objects, refusing to read more than maxBytes.
type Store struct {
	client   S3API
	maxBytes int64
}

func NewStore(client S3API, maxBytes int64) *Store {
	return &Store{client: client, maxBytes: maxBytes}
}

// Download fetches an object, up to the configured cap.
//
// The cap is checked twice on purpose. Content-Length avoids streaming a large
// object only to throw it away, and the LimitReader holds the line if that
// header is absent or lying — the only thing standing between a hostile object
// and this function's memory is what it agrees to read.
func (s *Store) Download(ctx context.Context, bucket, key string) ([]byte, error) {
	object, err := s.client.GetObject(ctx, &s3.GetObjectInput{
		Bucket: aws.String(bucket),
		Key:    aws.String(key),
	})
	if err != nil {
		return nil, fmt.Errorf("get %q: %w", key, err)
	}
	defer object.Body.Close()

	if object.ContentLength != nil && *object.ContentLength > s.maxBytes {
		return nil, fmt.Errorf("%q is %d bytes: %w", key, *object.ContentLength, handler.ErrTooLarge)
	}

	data, err := io.ReadAll(io.LimitReader(object.Body, s.maxBytes+1))
	if err != nil {
		return nil, fmt.Errorf("read %q: %w", key, err)
	}

	if int64(len(data)) > s.maxBytes {
		return nil, fmt.Errorf("%q exceeds the cap while streaming: %w", key, handler.ErrTooLarge)
	}

	return data, nil
}

// Upload writes a thumbnail.
//
// No encryption parameters are passed. The bucket's default encryption is
// SSE-KMS under the documents CMK, so an object written without them is
// encrypted with the same key as the document it was rendered from. Naming the
// key here as well would add a second place for it to be wrong without adding
// any protection.
func (s *Store) Upload(ctx context.Context, bucket, key, contentType string, body []byte) error {
	if _, err := s.client.PutObject(ctx, &s3.PutObjectInput{
		Bucket:      aws.String(bucket),
		Key:         aws.String(key),
		Body:        newReader(body),
		ContentType: aws.String(contentType),
	}); err != nil {
		return fmt.Errorf("put %q: %w", key, err)
	}

	return nil
}
