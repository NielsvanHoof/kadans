---
paths:
  - 'app/*/{Data,Http/Requests,Enums,Policies}/**'
---

# Requests Enums Policies

## Spatie Data for payloads, Form Requests for validation, policies for authz
Payloads are `final` classes extending Spatie `Data` under `app/{Domain}/Data/`, with TypeScript attributes so types generate. **No Eloquent API Resources** — return `response()->json([...])` built from Data (`fromModel`/`toArray`). Plain `final readonly` classes are for internal value objects never sent to the client.

Validate HTTP writes in Form Requests under `app/{Domain}/Http/Requests/`, using the `LocalizesValidationAttributes` trait. Never validate inline in a controller. Fortify contracts and console commands are the documented exception. No `app/Rules` custom rule classes.

Enums are string-backed with TitleCase case names. Permission enums implement `TenantPermission` (`value`, `label`, `description`, `group()`, `defaultRoles()`) and must be registered in `config/tenant-permissions.php` or `PermissionRegistry` will not see them. **Never list Owner in `defaultRoles()`** — owners implicitly hold every registered permission.

Policies use `AuthorizesTenantPermission` and are registered with `Gate::policy()` in the owning domain's `*ServiceProvider`. Controllers call `$this->authorize()`; Form Request `authorize()` and Inertia capability props use `$user->can()`.
