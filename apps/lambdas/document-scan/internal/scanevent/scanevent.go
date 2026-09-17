// Package scanevent parses GuardDuty Malware Protection scan results and
// decides which of them this function is allowed to act on.
//
// It is deliberately free of AWS clients and I/O: everything here is a pure
// function over bytes, which is what makes the awkward parts — the status
// vocabulary and the key filtering — cheap to test exhaustively.
package scanevent

import (
	"encoding/json"
	"fmt"
	"path"
	"strings"
	"time"
)

// DetailType is the EventBridge detail-type GuardDuty publishes per scanned object.
const DetailType = "GuardDuty Malware Protection Object Scan Result"

// Status is GuardDuty's verdict for one object.
//
// These are the values GuardDuty writes onto the object as the
// GuardDutyMalwareScanStatus tag, and they are deliberately the same strings as
// the application's App\Dossiers\Enums\MalwareScanStatus. The consumer parses
// them straight back into that enum, so the two vocabularies must not drift.
//
// The EventBridge payload does not use this vocabulary directly — see
// statusFrom for the mapping and why it is not a straight field read.
type Status string

const (
	StatusNoThreatsFound Status = "NO_THREATS_FOUND"
	StatusThreatsFound   Status = "THREATS_FOUND"
	StatusUnsupported    Status = "UNSUPPORTED"
	StatusAccessDenied   Status = "ACCESS_DENIED"
	StatusFailed         Status = "FAILED"
)

// IsClean reports whether the object may be rendered.
//
// Only one verdict passes. Everything else — including "could not scan it" —
// fails, because an unscanned object and an infected one are indistinguishable
// from here. This mirrors MalwareScanStatus::isClean() on the application side.
func (s Status) IsClean() bool { return s == StatusNoThreatsFound }

// Result is everything downstream needs from one scan event.
type Result struct {
	Bucket    string
	Key       string
	VersionID string
	Status    Status
	ScannedAt time.Time
}

type envelope struct {
	DetailType string    `json:"detail-type"`
	Time       time.Time `json:"time"`
	Detail     detail    `json:"detail"`
}

type detail struct {
	ScanStatus      string `json:"scanStatus"`
	S3ObjectDetails struct {
		BucketName string `json:"bucketName"`
		ObjectKey  string `json:"objectKey"`
		VersionID  string `json:"versionId"`
	} `json:"s3ObjectDetails"`
	ScanResultDetails struct {
		ScanResultStatus string `json:"scanResultStatus"`
	} `json:"scanResultDetails"`
}

// Parse turns a raw EventBridge event into a Result.
//
// It errors only on input this function cannot act on at all — malformed JSON,
// the wrong detail-type, or a missing bucket or key. An unrecognised status is
// not an error: it becomes StatusFailed, which is a refusal, so a GuardDuty
// vocabulary change degrades to "not established clean" instead of crashing the
// invocation and parking a message on the dead-letter queue.
func Parse(raw []byte) (Result, error) {
	var event envelope

	if err := json.Unmarshal(raw, &event); err != nil {
		return Result{}, fmt.Errorf("decode event: %w", err)
	}

	if event.DetailType != DetailType {
		return Result{}, fmt.Errorf("unexpected detail-type %q", event.DetailType)
	}

	object := event.Detail.S3ObjectDetails

	if object.BucketName == "" || object.ObjectKey == "" {
		return Result{}, fmt.Errorf("event carries no S3 object")
	}

	scannedAt := event.Time
	if scannedAt.IsZero() {
		scannedAt = time.Now().UTC()
	}

	return Result{
		Bucket:    object.BucketName,
		Key:       object.ObjectKey,
		VersionID: object.VersionID,
		Status: statusFrom(
			event.Detail.ScanStatus,
			event.Detail.ScanResultDetails.ScanResultStatus,
		),
		ScannedAt: scannedAt.UTC(),
	}, nil
}

// statusFrom reduces GuardDuty's two status fields to the one tag vocabulary.
//
// The event and the object tag do not speak the same language. The tag holds a
// single value out of five; the event splits it in two — detail.scanStatus says
// whether the scan ran (COMPLETED, SKIPPED, FAILED) and, when it did,
// scanResultDetails.scanResultStatus says what it found.
//
// scanResultStatus wins whenever it holds a value the tag vocabulary knows,
// because that is the same string the tag will carry and the application
// already understands it. Only when it is absent or unfamiliar does scanStatus
// decide, and a skipped scan maps to UNSUPPORTED — GuardDuty skips objects whose
// type or size it cannot handle, which is exactly what that tag value means.
//
// Anything left over becomes FAILED rather than an empty string, so an
// unrecognised verdict is refused by both this function and the application
// instead of being quietly treated as absent.
func statusFrom(scanStatus, scanResultStatus string) Status {
	switch candidate := Status(strings.ToUpper(strings.TrimSpace(scanResultStatus))); candidate {
	case StatusNoThreatsFound, StatusThreatsFound, StatusUnsupported, StatusAccessDenied, StatusFailed:
		return candidate
	}

	switch strings.ToUpper(strings.TrimSpace(scanStatus)) {
	case "SKIPPED":
		return StatusUnsupported
	case "ACCESS_DENIED":
		return StatusAccessDenied
	default:
		return StatusFailed
	}
}

// actionablePrefix is the root of the dossier tree, and the only place an object
// has a row in the database to attach a verdict to.
const actionablePrefix = "tenants/"

// ThumbnailPrefix is where rendered thumbnails are written.
const ThumbnailPrefix = "thumbnails/"

// IsActionable reports whether a verdict for this key should be handled at all.
//
// Two prefixes must be ignored, for different reasons, and both are ordinary
// traffic rather than error cases:
//
//   - staging/ is where a presigned PUT lands. A claimed upload is server-side
//     copied into the dossier tree and the staged object deleted, so GuardDuty
//     fires twice per upload and the first event names a key that has no
//     database row yet.
//   - thumbnails/ is this function's own output. Writing an object to the
//     scanned bucket gets it scanned, so acting on that verdict would render a
//     thumbnail of a thumbnail, forever.
//
// Allow-listing one prefix rather than denying those two means a future prefix
// is ignored by default instead of silently entering the loop.
func IsActionable(key string) bool {
	return strings.HasPrefix(key, actionablePrefix)
}

// ThumbnailExt is the format thumbnails are written in.
//
// JPEG rather than WebP, which is the more obvious choice for the job. WebP has
// no encoder in the standard library or in golang.org/x/image, which decodes it
// but cannot write it, and every Go encoder that does is either cgo — undoing
// the static binary this function is packaged as — or a young pure-Go
// implementation. At thumbnail dimensions WebP would save perhaps a quarter of
// a few kilobytes, which does not buy either of those.
const ThumbnailExt = ".jpg"

// ThumbnailKey is where the thumbnail for an object key belongs.
//
// The source key is kept whole and the extension replaced, so a thumbnail is
// traceable back to its document by inspection, and two documents cannot
// collide.
func ThumbnailKey(key string) string {
	return ThumbnailPrefix + strings.TrimSuffix(key, path.Ext(key)) + ThumbnailExt
}
