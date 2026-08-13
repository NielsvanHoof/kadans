---
paths:
  - 'app/Platform/Actions/Retention/**'
---

# Retention

## Purge collects audio blobs before cascade delete
Collect audio recordings (disk and path) before forceDelete — audio_recordings.dossier_id cascades and would orphan S3 objects. Delete those blobs after commit via DeleteStoredAudioFilesAction, the same ordering as documents and exports. Do not share a deleter with DocumentProcessing.
