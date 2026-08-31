---
paths:
  - 'compose.yaml'
  - 'package.json'
---

# Local Environment

## The container has its own node_modules — install in both places
`compose.yaml` mounts a named volume (`sail-node-modules`) over `/var/www/html/node_modules`, so the container's `node_modules` is separate from the host's.

This is deliberate. Vite+ (rolldown, Oxlint, Oxfmt), Tailwind oxide and lightningcss all ship platform-specific native bindings, and npm only installs the ones matching the machine running the install — it skips any package whose `os`/`cpu` fields do not match, even when listed in `optionalDependencies`. Sharing one tree gave the linux/arm64 container the host's darwin-arm64 binaries and `Cannot find native binding` on every `vp` start.

Consequences:
- After cloning, or after adding/removing any npm package, run `npm install` **and** `vendor/bin/sail npm install`. The host copy backs your editor, `tsc` and `vp test`; the container copy backs `vp dev` / `vp build` and `artisan dev`. Do not install a global `vp` into the Sail image — npm scripts use `node_modules/.bin/vp`.
- A brand-new volume is root-owned and the container runs as `sail`, so the first install fails with `EACCES`. Fix once with:
  `docker exec -u root <app-container> chown -R sail:sail /var/www/html/node_modules`
- Docker Desktop sometimes does not apply the nested volume until the container is recreated — writes then fall through to the host and clobber its bindings. Verify with `docker exec <app-container> grep www/html /proc/mounts`: you want a second line mounting `/var/www/html/node_modules`. If it is missing, `docker compose up -d --force-recreate laravel.test`.
- Do not "fix" a missing binding by adding cross-platform packages to `optionalDependencies`; npm will not install them. An `optionalDependencies` block listing `@rollup/*`, `@tailwindcss/oxide-*` and `lightningcss-*` variants used to live in `package.json` for exactly this reason and was removed: it never put a file on disk, and `package-lock.json` already records every platform variant via each parent package's own `optionalDependencies`, so npm picks the right one per machine on its own. `rollup` itself is no longer in the tree at all — Vite 8 bundles with rolldown.
