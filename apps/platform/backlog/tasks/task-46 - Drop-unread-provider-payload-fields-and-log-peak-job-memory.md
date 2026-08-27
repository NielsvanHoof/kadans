---
id: TASK-46
title: Drop unread provider payload fields and log peak job memory
status: Done
assignee:
  - '@claude'
created_date: '2026-08-27 17:58'
updated_date: '2026-08-27 18:09'
labels: []
dependencies: []
type: enhancement
ordinal: 104000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The OCR and transcription result paths both hold a full provider payload in PHP arrays, most of which is never read.

TextractJobBlockFetcher accumulates every GetDocumentAnalysis block whole, and TextractFormsTablesMapper then indexes all of them by id, so the entire set stays resident for the duration of the map. The mapper reads only Id, BlockType, EntityTypes, Confidence, RowIndex, ColumnIndex, Text, SelectionStatus and Relationships. It never touches Geometry, which Textract attaches to every block as a BoundingBox of four floats plus a Polygon of four X/Y pairs -- six nested arrays per block, each a separate PHP hashtable.

TranscribeExtractionPipeline is the same shape and worse at the peak: it holds the whole transcript JSON as a string and the decoded arrays at the same time. TranscribeTranscriptMapper reads only the transcript text, speaker_labels.segments (speaker_label, start_time, end_time, items[].item_id) and items (id, type, start_time, end_time, alternatives.0.content), so per-word confidence and every unread results key are decoded and retained for nothing.

Project both payloads down to the fields their mappers actually read, and add peak memory to the jobs' completion logs so the next sizing decision is made on measurements rather than arithmetic. Sized wrong, this is what pushed the 2 GiB app cluster to a 90% memory alert (TASK-45).
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 Textract blocks are projected to the keys TextractFormsTablesMapper reads, before the page is accumulated
- [x] #2 A test fails if the mapper starts reading a block key the projection drops
- [x] #3 Projection does not change either mapper's output for a representative payload
- [x] #4 Transcribe payloads are projected to the fields TranscribeTranscriptMapper reads
- [x] #5 Every heavy job logs peak memory on completion
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Add RETAINED_BLOCK_KEYS to TextractJobBlockFetcher and project each block with array_intersect_key as the page arrives; log peak memory beside the existing block counts.
2. Add a TranscribeTranscriptProjection service (the projection is a nested rebuild, unlike the flat Textract one) and call it in TranscribeExtractionPipeline right after json_decode, releasing the raw body first.
3. Add App\\Platform\\Support\\PeakMemory so every job logs the same peak_memory_mb key.
4. Log peak memory in ProcessUploadedDocumentJob, ApplyTextractResultJob, ProcessAudioRecordingJob, ApplyTranscribeResultJob, GenerateDossierExportJob and GenerateDataSubjectExportJob.
5. Drop JSON_PRETTY_PRINT from the stored extracted_text, and set nice => 10 on supervisor-heavy so OCR yields the single vCPU to Octane.
6. Tests: scan the mapper source for block keys and assert the retained list covers them; assert both mappers produce identical output for projected and unprojected payloads.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Verified by 534 tests across tests/Unit, tests/Feature/Platform, tests/Feature/DocumentProcessing and tests/Feature/AudioRecordings, all passing. Pint clean.

Textract: TextractJobBlockFetcher::RETAINED_BLOCK_KEYS is an array_intersect_key mask applied per block as the page arrives, before it joins the set that stays resident. TextractBlockProjectionTest scans the mapper source for the block keys it reads and fails if the mask stops covering them (same technique WorkerQueueCoverageTest uses for jobs), asserts the mapper's output is byte-identical for projected and unprojected input, and asserts Geometry / Page / TextType / RowSpan / ColumnSpan are gone.

Transcribe: TranscribeTranscriptProjection is a separate class because the projection is a nested rebuild rather than a flat mask -- what to keep sits three levels deep and differs per section. It drops per-word alternatives[].confidence, unread result sections (audio_segments, language_code, channel labels) and job metadata. Absent sections stay absent rather than becoming empty arrays, because the mapper treats a missing speaker_labels as 'no diarization' and falls back to timed word items; there is a test for exactly that. The raw JSON string is released before the projection walks the decoded arrays so the two peaks do not stack.

Measurement: PeakMemory::megabytes() plus a pair of discovered queue listeners. StartJobPeakMemoryWindow calls memory_reset_peak_usage() on JobProcessing and LogHeavyJobMemoryUsage logs on JobProcessed. The reset is the load-bearing half -- the process peak never decreases, so without it every job reports the high-water mark of the jobs before it. Verified safe against worker recycling: Worker::memoryExceeded reads currentMemoryUsage() (memory_get_usage), not the peak.

Scoped to WorkerQueues::HEAVY. The interactive queues arrive in floods (one bulk upload emits several hundred broadcast events) and a line each would bury the readings worth having. Only successful jobs are seen; a job killed by PHP's memory_limit never reaches JobProcessed, so a missing reading for a restarted worker is itself the signal.

Also: dropped JSON_PRETTY_PRINT from the stored extracted_text, and set nice => 10 on supervisor-heavy so OCR yields the single vCPU to Octane.

Unmeasured as yet: the actual saving. The projection is provably output-neutral and provably drops the fields, but the megabytes only show up against a real multi-page document on staging. peak_memory_mb in the log stream is how that gets answered.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Projected both provider payloads down to the fields their mappers read, and added per-job peak memory logging so worker sizing is measured rather than derived.

TextractJobBlockFetcher masks each block to the nine keys TextractFormsTablesMapper reads as the page arrives; Geometry (a BoundingBox plus a four-point Polygon, six nested PHP hashtables on every block) was being accumulated and indexed for nothing. TranscribeTranscriptProjection does the equivalent nested rebuild for Transcribe, dropping per-word confidence and unread result sections, and the raw JSON is released before the decoded arrays are walked.

Peak memory now reaches the logs through PeakMemory plus two discovered queue listeners rather than six per-job edits: the export jobs delegate straight to actions and have no completion log of their own. memory_reset_peak_usage() on JobProcessing is what makes the reading per-job instead of per-worker.

Verified with 534 tests: projection is output-neutral for representative payloads of both providers, a source scan fails if either mapper starts reading a dropped key, and the peak window demonstrably falls back after a reset.
<!-- SECTION:FINAL_SUMMARY:END -->
