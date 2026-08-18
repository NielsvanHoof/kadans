---
id: TASK-18
title: Run local AWS via Floci and retire MinIO
status: Done
assignee:
  - '@nielsvanhoof'
created_date: '2026-08-07 08:07'
updated_date: '2026-08-07 13:39'
labels: []
dependencies: []
references:
  - 'https://github.com/floci-io/floci'
  - 'https://floci.io/floci/services/textract/'
  - 'https://floci.io/floci/services/transcribe/'
  - compose.yaml
  - .env.example
  - app/DocumentProcessing/DocumentProcessingServiceProvider.php
  - app/AudioProcessing/AudioProcessingServiceProvider.php
  - app/Dossiers/Actions/DocumentRequests/ResolveDocumentTemporaryUrlAction.php
documentation:
  - docs/testing.md
priority: medium
type: enhancement
ordinal: 62000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Local development currently uses MinIO only for S3 (`AWS_ENDPOINT=http://minio:9000`), while Textract, SQS, and Transcribe SDK clients hit real AWS when drivers are enabled. That split makes "AWS on" local demos awkward: Textract cannot read objects from MinIO, so real OCR/transcription still depends on cloud credentials and Pulumi outputs.

Add [Floci](https://github.com/floci-io/floci) (LocalStack-style emulator on `:4566`) to Sail so one local endpoint can serve S3 plus the AWS APIs we exercise. Retire the MinIO Compose service once Floci S3 covers uploads, downloads, and browser presigned URLs (including the internal Docker hostname vs public localhost split already handled in `ResolveDocumentTemporaryUrlAction`).

Keep `OCR_DRIVER=mock` / `TRANSCRIPTION_DRIVER=mock` as the CI and default local path so Pest does not require Floci. Enabling textract/transcribe locally against Floci is optional and must be proven end-to-end where possible.

Important fidelity limits (do not oversell): Floci Textract/Transcribe return stub payloads (not real OCR/STT); Transcribe does not write real transcript objects to S3. Our production completion path is SNS→SQS (OCR) and EventBridge→SQS (transcription). Those fanouts must be spiked against Floci; if they are incomplete, document the gap and keep mock (or real AWS) for that path rather than shipping a broken "local AWS" profile.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Sail runs Floci; MinIO is removed from Compose/volumes once Floci S3 replaces it
- [x] #2 Local env/docs point Storage at Floci with path-style S3; browser temporary URLs and CSP still work (Docker-internal vs localhost public URL)
- [x] #3 When a local AWS endpoint is configured, Textract, SQS, and Transcribe SDK clients use it; production with an empty endpoint still talks to real AWS
- [x] #4 CI and default local drivers stay mock; enabling Floci is opt-in and documented
- [x] #5 OCR and/or transcription completion against Floci is either proven (Start → queue → consume) or explicitly documented as unsupported with the remaining gap named
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Replace MinIO in compose.yaml with Floci (`floci/floci:latest-compat` on :4566), FLOCI_HOSTNAME=floci, persistent volume, ready.d init to create the `local` S3 bucket; drop sail-minio volume/ports.
2. Point local Storage at Floci: AWS_ENDPOINT=http://floci:4566, AWS_URL=http://localhost:4566, path-style; keep FILESYSTEM_DISK=local and OCR/TRANSCRIPTION mock as CI defaults.
3. Wire TextractClient, SqsClient, and TranscribeServiceClient to use config endpoint when AWS_ENDPOINT is set; empty endpoint keeps real AWS (production).
4. Update .env.example, getting-started, infra sync script comments, CSP/temporary-URL comments for Floci (not MinIO).
5. Document fidelity: Floci Textract/Transcribe are stubs; SNS→SQS (OCR) and EventBridge→SQS + S3 transcript objects (transcription) are unsupported locally — keep mock or real AWS for completion paths.
6. Tests: assert SDK clients pick up a configured endpoint; update CSP public-URL example port if needed.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Implemented Floci Sail service (latest-compat), ready.d bucket bootstrap, SDK endpoint wiring, and docs. Verified: `sail up -d floci` healthy; init created `local` bucket; Pest DomainServiceProvidersTest + SecurityHeadersTest passed (22). Spike: StartDocumentAnalysis SUCCEEDED on Floci but NotificationChannel did not publish to SNS→SQS (manual SNS publish did) — completion path stays mock/real AWS.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Replaced MinIO with Floci on :4566 for local S3 + stub AWS APIs; Textract/SQS/Transcribe clients honor AWS_ENDPOINT when set. CI/default drivers remain mock. Documented that Floci does not fan out Textract SNS→SQS or Transcribe EventBridge/S3 completions. Verified with Floci health/bucket smoke, SNS spike, and Pest (DomainServiceProvidersTest, SecurityHeadersTest).
<!-- SECTION:FINAL_SUMMARY:END -->
