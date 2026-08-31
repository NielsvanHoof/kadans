---
paths:
  - app/Dossiers/Http/Controllers/AudioRecordingCalendarController.php
---

# Dossiers Http Controllers

## Do not scopeBindings calendar links under tenant
workspaces/{tenant}/audio-recordings/{audioRecording}/calendar-links/{calendarLink} must not use Route::scopeBindings(). Laravel would resolve audioRecording as a child of Tenant, and Tenant has no audioRecordings() (Tenancy cannot depend on Dossiers). Implicit-bind both models and abort_unless the link belongs to the recording so a cross-recording id 404s.
