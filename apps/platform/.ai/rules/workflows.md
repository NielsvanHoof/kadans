---
paths:
  - '.github/workflows/**'
---

# Workflows

## Platform CI uses setup-vp
platform.yml quality and coverage jobs use voidzero-dev/setup-vp@v1.18.0 (exact tag, never @v1) with working-directory: apps/platform and vp install --frozen-lockfile. The action ignores defaults.run. Leave infra-drift.yml on setup-node; Pulumi is not Vite+.
