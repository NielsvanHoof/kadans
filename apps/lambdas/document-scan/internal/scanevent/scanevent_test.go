package scanevent_test

import (
	"fmt"
	"testing"
	"time"

	"github.com/NielsvanHoof/qompose/apps/lambdas/document-scan/internal/scanevent"
)

// eventJSON builds a payload in the shape EventBridge delivers.
func eventJSON(scanStatus, scanResultStatus, key string) []byte {
	return []byte(fmt.Sprintf(`{
	  "version": "0",
	  "detail-type": %q,
	  "source": "aws.guardduty",
	  "time": "2026-09-16T10:30:00Z",
	  "detail": {
	    "schemaVersion": "1.0",
	    "scanStatus": %q,
	    "resourceType": "S3_OBJECT",
	    "s3ObjectDetails": {
	      "bucketName": "qompose-staging-documents",
	      "objectKey": %q,
	      "eTag": "d41d8cd98f00b204e9800998ecf8427e",
	      "versionId": "7NxMPuAaXkTTqPGGvoLZaHGPqOtAGYcK"
	    },
	    "scanResultDetails": { "scanResultStatus": %q }
	  }
	}`, scanevent.DetailType, scanStatus, key, scanResultStatus))
}

const documentKey = "tenants/12/dossiers/34/01JBQ8F7N3K9X2M5P6R7S8T9V0.pdf"

func TestParseReadsTheObjectAndTimestamp(t *testing.T) {
	result, err := scanevent.Parse(eventJSON("COMPLETED", "NO_THREATS_FOUND", documentKey))
	if err != nil {
		t.Fatalf("Parse() returned %v", err)
	}

	if result.Bucket != "qompose-staging-documents" {
		t.Errorf("Bucket = %q", result.Bucket)
	}
	if result.Key != documentKey {
		t.Errorf("Key = %q", result.Key)
	}
	if result.VersionID != "7NxMPuAaXkTTqPGGvoLZaHGPqOtAGYcK" {
		t.Errorf("VersionID = %q", result.VersionID)
	}
	if want := time.Date(2026, 9, 16, 10, 30, 0, 0, time.UTC); !result.ScannedAt.Equal(want) {
		t.Errorf("ScannedAt = %v, want %v", result.ScannedAt, want)
	}
}

// The status vocabulary is the contract with the PHP enum, so every documented
// combination is pinned rather than spot-checked.
func TestParseMapsEveryStatusOntoTheTagVocabulary(t *testing.T) {
	tests := []struct {
		name             string
		scanStatus       string
		scanResultStatus string
		want             scanevent.Status
	}{
		{"clean", "COMPLETED", "NO_THREATS_FOUND", scanevent.StatusNoThreatsFound},
		{"infected", "COMPLETED", "THREATS_FOUND", scanevent.StatusThreatsFound},
		{"unsupported reported as a result", "COMPLETED", "UNSUPPORTED", scanevent.StatusUnsupported},
		{"access denied reported as a result", "COMPLETED", "ACCESS_DENIED", scanevent.StatusAccessDenied},
		{"failure reported as a result", "COMPLETED", "FAILED", scanevent.StatusFailed},

		// No result to read: the outer status has to decide.
		{"skipped scan means unsupported", "SKIPPED", "", scanevent.StatusUnsupported},
		{"failed scan", "FAILED", "", scanevent.StatusFailed},
		{"access denied scan", "ACCESS_DENIED", "", scanevent.StatusAccessDenied},

		// Degrade to a refusal rather than erroring, so a GuardDuty vocabulary
		// change cannot park messages on the dead-letter queue.
		{"unknown result status", "COMPLETED", "SOMETHING_NEW", scanevent.StatusFailed},
		{"unknown scan status", "SOMETHING_NEW", "", scanevent.StatusFailed},
		{"both empty", "", "", scanevent.StatusFailed},

		{"lowercase is tolerated", "completed", "no_threats_found", scanevent.StatusNoThreatsFound},
		{"surrounding space is tolerated", "COMPLETED", "  THREATS_FOUND  ", scanevent.StatusThreatsFound},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			result, err := scanevent.Parse(eventJSON(test.scanStatus, test.scanResultStatus, documentKey))
			if err != nil {
				t.Fatalf("Parse() returned %v", err)
			}

			if result.Status != test.want {
				t.Errorf("Status = %q, want %q", result.Status, test.want)
			}
		})
	}
}

func TestOnlyNoThreatsFoundIsClean(t *testing.T) {
	clean := map[scanevent.Status]bool{
		scanevent.StatusNoThreatsFound: true,
		scanevent.StatusThreatsFound:   false,
		scanevent.StatusUnsupported:    false,
		scanevent.StatusAccessDenied:   false,
		scanevent.StatusFailed:         false,
	}

	for status, want := range clean {
		if got := status.IsClean(); got != want {
			t.Errorf("%q.IsClean() = %v, want %v", status, got, want)
		}
	}
}

func TestParseRejectsInputItCannotActOn(t *testing.T) {
	tests := []struct {
		name string
		raw  []byte
	}{
		{"malformed JSON", []byte(`{"detail-type":`)},
		{"another rule's event", []byte(`{"detail-type":"GuardDuty Finding","detail":{}}`)},
		{"no object", []byte(fmt.Sprintf(`{"detail-type":%q,"detail":{"scanStatus":"COMPLETED"}}`, scanevent.DetailType))},
		{"no key", eventJSON("COMPLETED", "NO_THREATS_FOUND", "")},
	}

	for _, test := range tests {
		t.Run(test.name, func(t *testing.T) {
			if _, err := scanevent.Parse(test.raw); err == nil {
				t.Fatal("Parse() succeeded, want an error")
			}
		})
	}
}

// A claimed upload is copied out of staging/ into the dossier tree, so GuardDuty
// fires twice; and this function's own thumbnails get scanned in turn. Both must
// be dropped, and neither is an error.
func TestIsActionableAllowsOnlyTheDossierTree(t *testing.T) {
	tests := []struct {
		key  string
		want bool
	}{
		{documentKey, true},
		{"tenants/12/dossiers/34/audio/01JBQ8F7N3K9X2M5P6R7S8T9V0.mp3", true},
		{"staging/tenants/12/01JBQ8F7N3K9X2M5P6R7S8T9V0.pdf", false},
		{"thumbnails/tenants/12/dossiers/34/01JBQ8F7N3K9X2M5P6R7S8T9V0.jpg", false},
		{"transcripts/tenants/12/whatever.json", false},
		{"", false},
		// Near-misses: an allow-list on a prefix must not be fooled by one.
		{"tenants", false},
		{"other-tenants/12/dossiers/34/x.pdf", false},
	}

	for _, test := range tests {
		t.Run(test.key, func(t *testing.T) {
			if got := scanevent.IsActionable(test.key); got != test.want {
				t.Errorf("IsActionable(%q) = %v, want %v", test.key, got, test.want)
			}
		})
	}
}

func TestThumbnailKeyReplacesTheExtensionUnderItsOwnPrefix(t *testing.T) {
	tests := []struct {
		key  string
		want string
	}{
		{documentKey, "thumbnails/tenants/12/dossiers/34/01JBQ8F7N3K9X2M5P6R7S8T9V0.jpg"},
		{"tenants/1/dossiers/2/a.jpeg", "thumbnails/tenants/1/dossiers/2/a.jpg"},
		{"tenants/1/dossiers/2/no-extension", "thumbnails/tenants/1/dossiers/2/no-extension.jpg"},
	}

	for _, test := range tests {
		t.Run(test.key, func(t *testing.T) {
			if got := scanevent.ThumbnailKey(test.key); got != test.want {
				t.Errorf("ThumbnailKey(%q) = %q, want %q", test.key, got, test.want)
			}
		})
	}
}

// The thumbnail of a document must itself be ignored, or the function feeds
// itself. This pins the two helpers against each other rather than trusting
// that the prefixes happen to line up.
func TestThumbnailsAreNeverActionable(t *testing.T) {
	if scanevent.IsActionable(scanevent.ThumbnailKey(documentKey)) {
		t.Fatal("a rendered thumbnail is actionable, which would render thumbnails forever")
	}
}
