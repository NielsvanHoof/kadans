---
paths:
  - 'app/Integrations/Http/Connectors/**'
---

# Connectors

## Saloon connectors own Integrations HTTP
Outbound third-party HTTP uses Saloon: one Connector per system under app/Integrations/Http/Connectors/{System}/, one Request per operation. Authenticate from OAuthConnection; refresh tokens via Integrations actions before send. Domain Actions call connectors — controllers do not. Tests use Saloon MockClient. Socialite stays for browser OAuth login/link only.

## Google API query booleans must be string true/false
Guzzle encodes PHP true as query value 1. Google Calendar/Drive reject that (Invalid boolean value: '1'). Always pass 'true'/'false' strings in Saloon defaultQuery() for Google boolean params (e.g. singleEvents, supportsAttachments).

## Saloon Google responses use typed DTOs
Google Saloon Requests implement createDtoFromResponse returning App\Integrations\Data\Google\* wire DTOs. Prefer $request->createDtoFromResponse($response) after throw() for typed PHPStan results — Response::dto() is mixed. Keep product CalendarEventData separate from Google wire shapes.
