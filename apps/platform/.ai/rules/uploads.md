---
paths:
  - 'app/Dossiers/Actions/Uploads/**'
---

# Uploads

## The sealed ticket, not the presigned URL, is what authorises an upload
A presigned PUT only proves the browser was allowed to write *somewhere*. If the follow-up request named the object key, a client could name a key it was never given — so `IssueUploadTicketAction` mints the key itself, seals it into a `Crypt` payload with tenant, purpose and deadline, and `ClaimUploadTicketAction` re-derives everything from that seal. Never accept an object key from a request.

Two consequences that are easy to get wrong:

- **Content-Type is not signed.** A presigned S3 URL signs `host` and nothing else (`X-Amz-SignedHeaders=host`), so the Content-Type S3 records is whatever the browser chose to send. The stored mime type is therefore derived from the sealed filename extension via `UploadPurpose::mimeTypeForExtension()`, never read back off the object. Do not "simplify" that to `$disk->mimeType()`.
- **Size and type are only knowable after the fact.** The values quoted at issue time are promises about a file that has not been sent. The Form Requests validate ticket strings only; the real checks run in `ClaimUploadTicketAction` against the stored object.

Uploads land under `staging/` and are moved into the dossier tree on claim. That is what makes the bucket's one-day `expire-unclaimed-staged-uploads` lifecycle rule safe — an object left in `staging/` is unambiguously abandoned, whereas an orphan signed straight to the dossier tree would not be.
