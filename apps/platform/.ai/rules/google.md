---
paths:
  - 'app/Integrations/Http/Connectors/Google/**'
---

# Google

## Calendar unlink is GET+PATCH then Drive delete
Unlinking a transcript from a Google Calendar event is GET event (supportsAttachments=true so attachments[] is present), drop our attachment by fileId (fallback: fileUrl contains the Drive id), PATCH remaining attachments (array fields replace the whole list — never send fileId, it is read-only), then DELETE the Drive file. Catch Saloon NotFoundException on the event or file as already-unlinked and continue; other RequestExceptions still bubble.
