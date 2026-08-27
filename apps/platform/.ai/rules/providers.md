---
paths:
  - 'app/Providers/**'
---

# Providers

## Automatic eager loading is production-only, so N+1s fail in CI
`Model::automaticallyEagerLoadRelationships()` resolves a lazy relation access on a collection member, which silently neutralizes the `preventLazyLoading()` that `Model::shouldBeStrict()` enables. Having both on unconditionally meant the N+1 guard never fired.

AppServiceProvider now gates automatic eager loading behind `$this->app->isProduction()`: production keeps the safety net, everywhere else a missing preload throws. Do not "fix" the asymmetry by enabling it in tests — that is the guard.

Consequence for callers: a policy or DTO that walks a relation must be handed it preloaded. See `FetchDossierShowQuery::audioRecordingRows()`, which assigns the dossier it already holds onto each recording rather than letting `AudioRecordingPolicy::delete()` re-fetch it per row. `AudioRecordingPolicyTest` covers both halves and re-enables automatic eager loading for the one test that is specifically about that mechanism.
