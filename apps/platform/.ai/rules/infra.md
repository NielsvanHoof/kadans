---
paths:
  - 'infra/**'
---

# Infra

## One Pulumi program, one stack per environment; knobs are data, never name checks
`infra/src/shared/` builds every component (the `docker/base` equivalent); `infra/src/environments/{production,staging}.ts` are pure data. A component reads a field from `EnvironmentSettings` — it must never branch on `environment.name`, which hides the difference where nobody comparing environments will find it. The whole delta should be a two-file diff.

`environment.resourceName()` output is a wire format: Pulumi keys state by it, so changing the format reads as delete-then-create. A pure file move must preview as zero changes.

`pulumi up` destroys anything the program no longer declares — read the preview diff after any refactor.

`npm run build` does `rm -rf bin && tsc`. Without the clean, a renamed or deleted source leaves stale JS in `bin/` that keeps running and keeps passing; that masked the pre-move stack test for a whole restructure. Tests are `node --test "bin/**/*.test.js"` (quoted — Node expands it, and the tests live in subdirectories now). Never pass a bare directory: `node --test bin/` executes `bin/index.js` as a test.

`stack.production.test.ts` and `stack.staging.test.ts` are separate files on purpose. `pulumi.runtime.setMocks` is process-global and `index.js` is module-cached, so two stacks in one process silently share the first one's resources; `node --test` forks per file. The shared mock harness is `src/testing/harness.ts`, and its `named()` is an exact lookup through the naming scheme — a substring match returned `alb-sg` for `"alb"` and the assertions then read `undefined`.

`bootstrap/` stays a separate project: its `DenyModifyingOwnAccess` statement is why CI cannot widen its own access. Keep it intact and grow `deployPolicy.ts` whenever `src/` grows — a missing permission fails a deploy halfway.
