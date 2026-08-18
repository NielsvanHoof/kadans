---
id: TASK-15
title: Low-severity hardening and cleanups
status: Done
assignee:
  - '@Cursor'
created_date: '2026-08-06 09:56'
updated_date: '2026-08-06 17:51'
labels: []
dependencies: []
priority: low
type: chore
ordinal: 54000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Findings worth fixing but not worth blocking anything on. Grouped so they can be picked up opportunistically rather than tracked individually.
<!-- SECTION:DESCRIPTION:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
Work subtasks in order:
1. 15.1 SNS signature verification on Textract (and shared) result consumers
2. 15.2 Align upload post_max_size with validation; appearance allowlist; hide portal firm slug
3. 15.3 Per-grant portal upload quota (confirm trusted-proxies prereq)
4. 15.4 Derive state (no effect copy); Wayfinder builder URL; thin pipeline show page
5. 15.5 2FA timer cleanup; defer dossier show payloads; list prefetch; drop unused per_page meta
Finalize each then parent.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
All subtasks 15.1–15.5 finalized with AC evidence in their task records.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
## Summary
Completed all five low-severity hardening subtasks:
- **15.1** SNS signature verification on Textract result consumption
- **15.2** Aligned upload size limits + PostTooLarge messaging; appearance allowlist; portal firm slug omitted
- **15.3** Per-dossier 500 MB portal upload quota (+ ProcessUploadedDocumentJob timeout fix for unique locks)
- **15.4** Derived state / Wayfinder builder URL / thinned pipeline show page
- **15.5** 2FA timer cleanup; deferred dossier exports/audio; list prefetch; dropped unused toolbar per_page

## Follow-ups
- Restart Sail/Octane so Caddyfile `post_max_size=210M` applies to HTTP (CLI may still show 100M).
- Optional: fix remaining prop→state effects in export/audio cards if desired for consistency.
<!-- SECTION:FINAL_SUMMARY:END -->
