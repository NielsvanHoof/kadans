// Package publish carries a scan verdict back to the application.
//
// Delivery is SQS rather than an HTTPS call to the application, matching how
// Textract and Transcribe results already reach it. That buys three things a
// webhook would not: no new public endpoint to authenticate and rate-limit,
// messages that queue up rather than vanish while the application is deploying
// or down, and a redrive policy plus dead-letter alarm that already exist in
// this stack rather than retry logic invented here.
package publish

import (
	"context"
	"encoding/json"
	"fmt"
	"time"

	"github.com/aws/aws-sdk-go-v2/aws"
	"github.com/aws/aws-sdk-go-v2/service/sqs"
)

// Verdict is the message the application consumes.
//
// The field names are the wire format between this function and
// ConsumeScanResultsCommand. Status holds GuardDuty's own vocabulary so the
// consumer can parse it straight into App\Dossiers\Enums\MalwareScanStatus.
//
// ThumbnailKey is omitted rather than empty when there is no thumbnail, so the
// consumer can tell "not rendered" from "rendered to nowhere". There are three
// ordinary reasons for its absence — the verdict was not clean, the format has
// no renderer, or rendering failed — and none of them should block the verdict.
type Verdict struct {
	Key          string    `json:"key"`
	VersionID    string    `json:"versionId,omitempty"`
	Status       string    `json:"status"`
	ScannedAt    time.Time `json:"scannedAt"`
	ThumbnailKey string    `json:"thumbnailKey,omitempty"`
}

// SQSAPI is the slice of the SQS client this package uses, so callers can
// substitute a double without reaching for a live queue.
type SQSAPI interface {
	SendMessage(ctx context.Context, params *sqs.SendMessageInput, optFns ...func(*sqs.Options)) (*sqs.SendMessageOutput, error)
}

// Publisher sends verdicts to the results queue.
type Publisher struct {
	client   SQSAPI
	queueURL string
}

func NewPublisher(client SQSAPI, queueURL string) *Publisher {
	return &Publisher{client: client, queueURL: queueURL}
}

// Send puts one verdict on the queue.
//
// Errors are returned rather than swallowed: a verdict that never arrives is
// a document stuck showing "scanning" forever, so the invocation should fail
// and let the event source retry it.
func (p *Publisher) Send(ctx context.Context, verdict Verdict) error {
	body, err := json.Marshal(verdict)
	if err != nil {
		return fmt.Errorf("encode verdict: %w", err)
	}

	if _, err := p.client.SendMessage(ctx, &sqs.SendMessageInput{
		QueueUrl:    aws.String(p.queueURL),
		MessageBody: aws.String(string(body)),
	}); err != nil {
		return fmt.Errorf("send verdict for %q: %w", verdict.Key, err)
	}

	return nil
}
