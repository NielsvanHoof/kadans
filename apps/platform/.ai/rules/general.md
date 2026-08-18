---
paths:
  - rector.php
---

# General

## Skip Rector rules that break listeners, Health Status, and Pest env
Skip RemoveUnusedPublicMethodParameterRector globally (path globs in withSkip do not match Listeners). Also skip SpatieEnumMethodCallToEnumConstRector (Health Status::ok() is Spatie\Enum, not Status::OK) and EnvVariableToEnvHelperRector (unset($_ENV[$key]) cannot become unset(Env::get())).
