---
id: TASK-36.9
title: Stream export zip entries instead of buffering files in memory
status: Done
assignee:
  - '@claude'
created_date: '2026-08-13 11:35'
updated_date: '2026-08-14 22:36'
labels:
  - audit
dependencies: []
references:
  - app/Dossiers/Actions/GenerateDossierExportAction.php
  - app/Platform/Actions/DataSubject/GenerateDataSubjectExportAction.php
modified_files:
  - app/Dossiers/Actions/GenerateDossierExportAction.php
  - app/Platform/Actions/DataSubject/GenerateDataSubjectExportAction.php
  - tests/Feature/Dossiers/DossierExportStreamingTest.php
  - tests/Feature/Retention/DataSubjectExportTest.php
parent_task_id: TASK-36
priority: medium
type: enhancement
ordinal: 9000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
GenerateDossierExportAction and GenerateDataSubjectExportAction add zip entries from in-memory strings, so every file is retained until close. 400 MB of scans is ~400 MB RSS. GenerateDossierExportAction also bare-unlinks the temp path in finally, which warns if the file was never created; GenerateDataSubjectExportAction already guards that.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Dossier and data-subject export zips do not retain every file's contents in memory until close
- [x] #2 A missing dossier-export temp file does not warn on cleanup
- [x] #3 A focused test or documented memory-bound check covers a multi-file export
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Implemented.

Both exports assembled the zip with ZipArchive::addFromString(), which retains every entry's bytes until close(). Replaced with a streaming stage-then-addFile(): each source object is copied to local disk with readStream/writeStream (peak memory is one stream buffer, not one whole document) and added with addFile(), which ZipArchive reads lazily at close().

Staging lives in a run-unique directory per export, deleted with one deleteDirectory() in the finally — so cleanup is correct wherever the build threw, without threading a list of temp paths back out of a method that may not return.

GenerateDataSubjectExportAction needed the staging directory threaded through five signatures (buildArchive -> dossiers -> documentRequests/audioRecordings -> addFile). It travels beside $archive, which those methods already carry, since the two share a lifetime.

AC#2: GenerateDossierExportAction's finally did a bare unlink(). buildArchive() throws before ZipArchive::open() creates the file when a dossier has no client, so the warning landed on top of the real exception. Now guarded with file_exists(), matching what GenerateDataSubjectExportAction already did.

Tests:
- tests/Feature/Dossiers/DossierExportStreamingTest.php, covers(GenerateDossierExportAction): peak-memory bound over 12 x 4 MB; archive entries byte-identical to their sources (lazily-added entries must still be correct); staged copies cleaned up; and no unlink warning when the archive was never created (asserted with a real set_error_handler on E_WARNING).
- tests/Feature/Retention/DataSubjectExportTest.php gained the same peak-memory test over 6 documents + 6 audio recordings, this export being the worse of the two — it can hold every document AND every recording about one person, on a job with a 600s timeout and no memory hint.

Both memory assertions were confirmed to discriminate by restoring addFromString: 54.5 MB peak growth vs a 16 MB bound for the dossier export, 50.4 MB for the data-subject export. The unlink test likewise fails with the bare unlink() restored.

Worth recording: the FIRST memory assertion did not discriminate. It used memory_get_usage(true) over 8 x 1 MB, which reports arena size — the fixture had already warmed the allocator, so the number did not move. Switching to memory_get_peak_usage(true) and a payload large enough to exceed any pre-warmed arena is what made it a real check.

Verified: 170 tests pass across Retention, Dossiers and Architecture; PHPStan level 8, Rector, Pint clean.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Both export archives now stream instead of buffering every file until close().

Each source object is copied to local disk with readStream/writeStream and added with addFile(), which ZipArchive reads lazily at close() — so peak memory is one stream buffer rather than the whole archive. Staged copies live in a run-unique directory removed by a single deleteDirectory() in the finally, which stays correct wherever the build threw. The dossier export's bare unlink() is now guarded, matching the data-subject export that already had it.

Verified with peak-memory bounds that were confirmed to discriminate: restoring addFromString gives 54.5 MB growth for the dossier export and 50.4 MB for the data-subject export against a 16 MB bound. Archive contents are separately asserted byte-identical, staged copies asserted cleaned up, and the unlink warning asserted absent via a real E_WARNING handler.

The first memory assertion was not a real check — memory_get_usage(true) reports arena size and the fixture had warmed it, so the number never moved. memory_get_peak_usage(true) with a payload larger than any pre-warmed arena is what made it meaningful. 170 tests pass across Retention, Dossiers and Architecture; PHPStan level 8, Rector and Pint clean.
<!-- SECTION:FINAL_SUMMARY:END -->
