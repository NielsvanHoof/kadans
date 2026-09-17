---
id: TASK-62.1
title: Build the document-scan Lambda in Go
status: Done
assignee:
  - '@NielsvanHoof'
created_date: '2026-09-16 08:12'
updated_date: '2026-09-16 08:24'
labels:
  - aws
  - go
milestone: m-0
dependencies: []
parent_task_id: TASK-62
type: feature
ordinal: 130000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
The first function in a new apps/lambdas/ tree. apps/lambdas/ is a polyglot container: one self-contained folder per function, each with its own toolchain, dependency manifest and tests, so a later function can be Python or TypeScript without disturbing this one. The contract with the build and deploy tooling is deliberately thin and language-agnostic -- every folder exposes `make build` and `make test`, and `make build` produces dist/function.zip, which is the path Pulumi reads as a FileArchive. Record that contract in apps/lambdas/README.md for whoever adds the second one.

Accepted cost of the per-folder layout: a second Go Lambda will duplicate its AWS client and logging setup rather than importing a shared internal package. At two or three functions that is cheaper than the coupling it avoids, and a shared module stays available later as an additive change.

It must not live under infra/, whose `npm run build` does `rm -rf bin` and would delete a Go bin/ directory.

PDF rasterising uses github.com/klippa-app/go-pdfium through its WebAssembly/wazero backend rather than cgo. That keeps the function a single static binary shippable as a plain zip -- which matters because TASK-43.3 deliberately removed ECR from this stack -- and it runs PDFium inside a wasm sandbox, which is a real property when parsing untrusted PDFs. It is roughly 2x slower than native cgo, which is irrelevant on an async path. PDFium is BSD-3-Clause and the wrapper is MIT.

The verdict is the security-relevant half of the output and a rendering failure must never cost it.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 The function is a self-contained folder at apps/lambdas/document-scan with its own go.mod
- [x] #2 `make build` produces dist/function.zip containing an arm64 bootstrap binary for provided.al2023, and `make test` runs the tests
- [x] #3 apps/lambdas/README.md records the per-folder build contract so a future function in another language can follow it
- [x] #4 The handler parses a GuardDuty Malware Protection Object Scan Result event into bucket, key, version and scan status
- [x] #5 Keys outside the tenants/ prefix are ignored without error, so staging/ and thumbnails/ events do not recurse or fail
- [x] #6 Office formats and non-clean verdicts produce no thumbnail
- [x] #7 A verdict message is published to SQS on every handled event, including when rendering fails
- [x] #8 Oversized objects and pathological PDFs are bounded by a size cap and a render deadline rather than consuming the invocation
- [x] #9 Tests cover event parsing, key filtering, every scan status, a rendered fixture PDF and a malformed PDF
- [ ] #10 A clean PDF or image renders page one to a JPEG thumbnail written under thumbnails/, encrypted by the buckets default SSE-KMS on the documents CMK
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Create apps/lambdas/README.md recording the per-folder contract: one self-contained folder per function, any language, each exposing `make build` (-> dist/function.zip) and `make test`; Pulumi reads dist/function.zip as a FileArchive.
2. Scaffold apps/lambdas/document-scan with its own go.mod (Go 1.26) and a Makefile whose build target cross-compiles GOOS=linux GOARCH=arm64 to a bootstrap binary and zips it to dist/function.zip.
3. internal/scanevent: parse the GuardDuty Malware Protection Object Scan Result detail into a typed Event (bucket, key, versionId, status, scannedAt). Classify the key: only tenants/ is actionable; staging/ and thumbnails/ are ignored without error. Table-driven tests over every documented scanStatus plus unknown values.
4. internal/thumbnail: Renderer over an io.Reader returning WebP bytes. PDF page one via go-pdfium wazero; raster images decoded and downscaled with x/image. Extension allowlist pdf,jpg,jpeg,png,webp; everything else declines. Size cap and a context deadline. Fixture-driven tests including a malformed PDF.
5. internal/publish: typed verdict message and an SQS sender behind a small interface so the handler is testable without AWS.
6. main.go: wire config from env, construct clients once at cold start, and run the flow -- ignore non-actionable keys, render best-effort when clean and renderable, always publish the verdict. Rendering failure is logged and drops only the thumbnail key.
7. make test and make build; confirm the zip contains an arm64 bootstrap.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Implemented as five small packages behind pure functions, so the awkward parts are testable without AWS.

**The status vocabulary is not a field read.** The object tag holds one of five values; the EventBridge payload splits the same fact across detail.scanStatus (COMPLETED/SKIPPED/FAILED) and scanResultDetails.scanResultStatus. scanevent.statusFrom collapses them onto the tag vocabulary so the consumer can parse straight into MalwareScanStatus, with SKIPPED mapping to UNSUPPORTED. An unrecognised value becomes FAILED rather than an error -- erroring would park the message on the delivery DLQ, and FAILED is already a refusal on both sides.

**Key filtering allow-lists tenants/ rather than denying staging/ and thumbnails/.** Both must be dropped and neither is an error: a claimed upload is copied out of staging/ so GuardDuty fires twice, and this functions own thumbnails get scanned in turn. TestThumbnailsAreNeverActionable pins the two helpers against each other so the prefixes cannot drift into a render loop.

**JPEG, not WebP** (AC reworded). WebP has no encoder in the standard library or x/image, which decodes but cannot write it. Every Go encoder that can is either cgo -- undoing the static binary this is packaged as -- or a young pure-Go implementation. At 400x560 the saving would be a fraction of a few kilobytes.

**wazero FSConfig is set explicitly to an empty config.** Left nil, go-pdfium mounts the hosts entire root filesystem into the sandbox. Sensible for a library that opens PDFs by path, wrong for a process whose only input is an untrusted download. Documents are opened from memory, so the module gets no filesystem at all.

**Transparency is flattened onto white before encoding.** JPEG has no alpha; without it every transparent pixel encodes black and a scan on a transparent background arrives as a black rectangle. Covered by a test.

**Failure split.** Only two things fail an invocation: an event that cannot be parsed, and a verdict that cannot be delivered -- both leave a document showing "scanning" forever, and the DLQ is alarmed so someone finds out. Everything on the thumbnail path (download, size cap, render, upload) is logged and swallowed, because the verdict is the security-relevant half of the output.

**Validation.** go vet clean; 5 packages passing with -count=1, including a real PDFium-in-WebAssembly render of testdata/document.pdf (a hand-built PDF with a correct xref table, kept text-readable) and a malformed PDF proving the verdict still ships. make build produces a 9.0 MB zip containing a 27 MB statically linked ARM aarch64 ELF named bootstrap -- well inside the 50 MB zip and 250 MB unzipped limits.

**Not verified here:** anything requiring deployed AWS. The bucket default SSE-KMS that encrypts thumbnails (AC#10) is asserted only as the absence of explicit encryption parameters on PutObject; it becomes true when TASK-62.2 deploys against the bucket that already carries that default. Cold start now includes compiling the embedded ~5 MB pdfium.wasm, which is acceptable on an async path but worth watching in the log group once deployed.

Added TestTheThumbnailPathRunsUnderItsOwnDeadline so AC#8 rests on evidence rather than code presence: the size cap was already proven by the storage tests, but the 30s thumbnail budget was only asserted by reading it. The test substitutes a downloader that inspects ctx.Deadline() on the first call the thumbnail path makes.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
A Go Lambda at apps/lambdas/document-scan that renders a thumbnail for documents GuardDuty has declared clean and reports every scan verdict back to the platform over SQS. apps/lambdas/ is established as a polyglot tree: one self-contained folder per function, each honouring the same language-agnostic contract -- `make build` writes dist/function.zip, `make test` runs the tests -- recorded in apps/lambdas/README.md so the next function need not be Go.

PDFium runs as WebAssembly under wazero rather than cgo. That keeps the function a single static binary shippable as a plain zip, which matters because TASK-43.3 deliberately removed ECR from this stack, and it puts the C code that parses untrusted PDFs inside a sandbox with no filesystem mounted.

Verified from the repository: go vet clean, five packages passing with -count=1, including a genuine PDFium render of a hand-built fixture PDF, a malformed PDF proving the verdict still ships when rendering fails, transparency flattening, the size cap at and above its boundary, deadline propagation, and the SQS wire format. make build produces a 9.0 MB zip holding a statically linked ARM aarch64 binary named bootstrap, well inside the Lambda limits.

AC#10 is left unchecked. Its rendering and placement halves are proven by tests, but it also asserts the thumbnail is encrypted by the buckets default SSE-KMS on the documents CMK, and that is an observation against deployed AWS. The upload deliberately passes no encryption parameters so the bucket default applies; it becomes true when TASK-62.2 deploys against that bucket. Nothing has been applied and no AWS call has been made from here.
<!-- SECTION:FINAL_SUMMARY:END -->
