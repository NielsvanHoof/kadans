# Lambdas

AWS Lambda functions that run next to the data plane, one folder per function.

The application itself runs on Laravel Cloud and the Pulumi stack in
`apps/platform/infra` is otherwise a pure data plane, so these are the only
compute in the AWS account. They exist for work the application deliberately
does not do: handling bytes.

## One folder per function, any language

Each function is **fully self-contained** — its own toolchain, its own
dependency manifest, its own tests. `document-scan` is Go; the next one can be
Python or TypeScript without disturbing it, and nothing here forces a shared
language or a shared dependency graph.

The trade is that two functions in the same language duplicate their client and
logging setup instead of sharing an internal package. At this many functions
that is cheaper than the coupling it avoids, and a shared module is an additive
change if the duplication ever starts to hurt.

## The contract

So the build and deploy tooling never needs to know what language a function is
written in, every folder honours the same three rules:

| | |
| --- | --- |
| `make build` | produces `dist/function.zip` in that folder |
| `make test` | runs that function's tests |
| `dist/function.zip` | is what Pulumi reads as a `FileArchive` |

CI runs `make test` over a matrix of the folder names here, and the infra deploy
runs `make build` before `pulumi up`. Adding a function is adding a directory
that honours the contract — neither workflow needs editing.

Go functions target the `provided.al2023` runtime on `arm64`, which requires the
executable inside the zip to be named `bootstrap`.

## Running them locally

Sail runs **Floci**, which emulates AWS and — importantly for this folder —
executes Lambda for real, spawning a container per function over the Docker
socket and attaching it to the Sail network. A function talks to S3 and SQS at
`http://floci:4566` exactly as it would talk to AWS, and needs no local-only code
path to do it: `AWS_ENDPOINT_URL` is read natively by the AWS SDK for Go v2.

```bash
composer floci:lambdas    # build every function and deploy it into Floci
```

Re-run that after any change to a function; it is idempotent.

**The one thing Floci cannot emulate is the trigger.** It answers the GuardDuty
API, but nothing there scans an object or emits a verdict, so no scan result will
ever arrive on its own. Everything downstream of the trigger is real — the
EventBridge rule the script creates carries the same event pattern the Pulumi
program declares — so a hand-fired event exercises the genuine chain:

```bash
./scripts/floci-scan-event.sh tenants/1/dossiers/1/01JBQ....pdf
./scripts/floci-scan-event.sh tenants/1/dossiers/1/01JBQ....pdf THREATS_FOUND
```

That reaches the rule, invokes the function, writes a thumbnail to
`s3://local/thumbnails/...` on a clean verdict, and publishes the verdict to
`local-scan-results`, which `scan:consume` drains. `php artisan dev` starts that
consumer automatically once `SCAN_RESULTS_QUEUE_URL` is set.

**To see the chain run without firing anything, use real AWS instead.** GuardDuty
is created on every stack, so the `local` Pulumi stack scans each upload for
real and the verdict arrives on its own:

```bash
cd apps/platform/infra && pulumi up -s local
cd apps/platform && composer env:aws     # sets SCAN_RESULTS_QUEUE_URL from the stack
composer env:floci                        # back to Floci; blanks it again
```

Floci is the faster loop and the one that needs no cloud resources; the `local`
stack is the one where nothing is simulated. See `infra/README.md`.

Watch a function's output with:

```bash
aws --endpoint-url http://localhost:4566 logs tail /aws/lambda/local-scan-handler --follow
```

## Functions

| Folder | Language | Trigger |
| --- | --- | --- |
| `document-scan` | Go | GuardDuty malware scan result on the documents bucket |
