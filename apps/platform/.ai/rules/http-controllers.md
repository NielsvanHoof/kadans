---
paths:
  - 'app/ClientPortal/Http/Controllers/**'
---

# Http Controllers

## Portal access recording is deferred; the DSR export audit is not
ClientPortalController wraps RecordClientPortalAccessAction in defer() (imported as `use function Illuminate\Support\defer;`) — the rendered page reads none of its three writes.

The fragile part is the dossier stamp: it uses Dossier::query()->...->toBase()->update(), and toBase() applies global scopes, so TenantScope must still resolve a current tenant or the update silently matches zero rows. Deferred callbacks run in InvokeDeferredCallbacks terminate middleware, before Octane's RequestTerminated, so the tenant is still current. Covered by the "records portal access after the response" test — keep it.

Do NOT defer the audit write in DataSubjectRequestController before the export download: defer() skips on 4xx/5xx and a worker crash after flush would silently drop an AVG access record.

Under FrankenPHP there is no fastcgi_finish_request, so defer() improves TTFB, not throughput.
