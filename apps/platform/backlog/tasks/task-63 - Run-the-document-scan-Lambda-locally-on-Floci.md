---
id: TASK-63
title: Run the document-scan Lambda locally on Floci
status: Done
assignee:
  - '@NielsvanHoof'
created_date: '2026-09-16 09:25'
updated_date: '2026-09-16 09:30'
labels:
  - infra
  - aws
  - local
  - documents
milestone: m-0
dependencies: []
type: feature
ordinal: 134000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Sail already runs Floci, which emulates AWS, and the compose comment notes the Docker socket is mounted so it can spawn Lambda sidecars. That makes the document-scan function runnable locally, which matters because until now the only way to see a thumbnail was to deploy.

The catch is the trigger. Floci answers the GuardDuty API, but nothing there scans an object or emits a verdict, so no scan result arrives on its own. Everything downstream can be genuine -- the EventBridge rule, the function, the S3 write, the queue -- provided the event is fired by hand with the same shape GuardDuty sends.

The Lambda zip also lives outside the Sail mount (apps/lambdas is not under apps/platform), and building it needs the Go toolchain, which the containers do not carry -- so deployment has to be driven from the host rather than from a Floci init script.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 One command builds every function in apps/lambdas and deploys it into Floci, and is safe to re-run
- [x] #2 A helper fires a GuardDuty-shaped verdict for a given object key, so the chain can be exercised without GuardDuty
- [x] #3 The local EventBridge rule carries the same event pattern the Pulumi program declares
- [x] #4 A clean verdict renders a thumbnail into the local bucket and publishes to the local queue; a non-clean one publishes without rendering
- [x] #5 scan:consume starts under `php artisan dev` when a results queue is configured, and stays out of it when none is
- [x] #6 The function needs no local-only code path to talk to Floci instead of AWS
- [x] #7 The local workflow is documented where someone adding the next function will find it
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. Confirm empirically what Floci can actually do -- query its health endpoint, then deploy and invoke the real function rather than reasoning about support.
2. scripts/floci-lambdas.sh on the host: build each function, create the queue, role, function and EventBridge rule in Floci. Idempotent.
3. scripts/floci-scan-event.sh: fire a GuardDuty-shaped verdict at EventBridge, since GuardDuty never emits one locally.
4. Register scan:consume as a dev process, gated on the queue URL alone.
5. Correct the config comment that claimed the Lambda only exists in deployed AWS, and give .env.example the deterministic local queue URL.
6. Document it in apps/lambdas/README.md, where whoever adds the next function will look.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Floci runs Lambda for real -- it spawns a container per function over the mounted Docker socket and attaches it to the Sail network, so the function reaches S3 and SQS at http://floci:4566 the same way it would reach AWS. Its health endpoint reports 100 services including lambda, events, s3, sqs, iam, kms and logs.

**No local-only code path was needed.** AWS_ENDPOINT_URL is read natively by the AWS SDK for Go v2, so the same binary that ships to AWS talks to Floci with one environment variable. Path-style S3 turned out not to be needed either -- an earlier attempt set AWS_USE_PATH_STYLE_ENDPOINT, and removing it changed nothing, so it was dropped rather than left in as cargo.

**GuardDuty is the one thing that cannot be emulated.** Floci answers the GuardDuty API -- it reports guardduty as running -- but nothing there scans an object or emits a verdict. That is why the helper script exists. Everything downstream of the trigger is genuine: the local rule carries the same event pattern infra/src/shared/documentScanHandler.ts declares, so the hand-fired event goes through the real rule to the real function.

**Deployment is driven from the host, not from a Floci init script.** Two reasons, both structural: apps/lambdas sits outside the Sail mount (compose mounts apps/platform at /var/www/html), and building needs Go, which the containers do not carry. `--zip-file fileb://` uploads the bytes over the API, so no extra mount is required.

**Verified end to end against the running stack**, after tearing down everything created by hand so the script proved itself from scratch:
- clean verdict -> rule -> function -> 13,596-byte JPEG at s3://local/thumbnails/... and a verdict on local-scan-results carrying thumbnailKey;
- THREATS_FOUND -> no thumbnail written, verdict still published with no thumbnailKey;
- scan:consume --once received that real message, parsed it and drained the queue to zero. It logged "no matching file" because the fixture key has no row in the local database, which is the correct branch and proves the parse.

The throwaway fixture object and its thumbnail were removed afterwards; the function, queue and rule are left deployed in Floci.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
The document-scan Lambda now runs locally on Floci, which executes Lambda for real rather than stubbing it. `composer floci:lambdas` builds every function in apps/lambdas and deploys it, and ./scripts/floci-scan-event.sh fires the one event GuardDuty will never send locally.

The same binary that ships to AWS runs unmodified against Floci -- AWS_ENDPOINT_URL is enough, and the local EventBridge rule carries the same event pattern the Pulumi program declares, so this is a rehearsal of the production chain rather than a parallel implementation of it.

Verified against the running stack, from a torn-down starting point: a clean verdict produced a 13,596-byte JPEG in s3://local/thumbnails/ and a verdict on the queue; a THREATS_FOUND verdict wrote no thumbnail and still published; and scan:consume drained that real message, proving the wire format across a boundary the unit tests on both sides could only assume.

1,927 Pest tests passing, with a new one pinning that scan:consume stays out of `php artisan dev` when no queue is configured -- a consumer polling a queue that does not exist is noise rather than signal. PHPStan, Pint, Rector and lang:organize all clean.

Also corrected a comment this work made false: config/scanning.php claimed thumbnails require a deployed AWS account. They no longer do.
<!-- SECTION:FINAL_SUMMARY:END -->
