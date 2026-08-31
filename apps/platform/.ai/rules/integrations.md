---
paths:
  - 'app/Integrations/**'
---

# Integrations

## Integrations is L1 sibling of Accounts
OAuth connections, Socialite drivers, and encrypted tokens live in App\Integrations (L1 with Accounts). Login/settings may import it; calendar product UI stays higher and calls Integrations. Never put tenant/dossier calendar features inside Integrations — that would force raising the whole domain past Tenancy/Dossiers. Explicit $table = 'oauth_connections' on OAuthConnection (Eloquent would otherwise invent o_auth_connections).

## Google is built-in Socialite; Microsoft stays via socialiteproviders
OAuthProvider::Google uses Laravel Socialite's native google driver (no extra package). Microsoft remains socialiteproviders/microsoft for Entra/Graph. Google first in the enum so login/settings list it before Microsoft when both are configured. Partial client_id without secret/redirect fails ProductionConfigurationValidator for both providers.

## Saloon owns outbound Google/Microsoft API HTTP
Browser login/link stays Socialite. Calendar, Drive, and future third-party APIs use Saloon connectors under Http/Connectors/{System}/ — see connectors.md.

## Google Workspace API client is a Service
Google Calendar/Drive Saloon mapping lives in Services\GoogleWorkspaceClient (multi-method infrastructure driver), not Actions. Use-case Actions (Suggest/Attach) call ResolveGoogleConnectorAction then the client. Do not put multi-operation API facades in Actions/ — Actions use a single handle().
