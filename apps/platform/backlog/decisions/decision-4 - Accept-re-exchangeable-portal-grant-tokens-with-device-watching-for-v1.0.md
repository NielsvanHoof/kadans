---
id: decision-4
title: Accept re-exchangeable portal grant tokens with device watching for v1.0
date: '2026-08-11 10:05'
status: accepted
---
## Context

Portal grant tokens remain re-exchangeable until expiry (operator choice, up to 90 days)
rather than single-use. Clients lose emails and re-open links from multiple devices;
single-use tokens generated a support burden that outweighed the benefit. Redemption
from an unfamiliar device is now *watched* (`RegisterClientPortalDeviceAction` +
`portal.new_device_action`: notify / block / ignore), but a leaked link used from a
matching network and browser family can still pass.

The Client portal "Known tradeoff" wording in `docs/security.md` is the canonical
product rationale and must stay intact.

## Decision

Accept re-exchangeable, watched-but-not-bound portal grants for v1.0. Do not bind
tokens to a single device or IP by default.

## Consequences

- **Reopen trigger:** before the first real client document enters the system, reassess
  whether `block` should become the default for new-device redemption, or whether
  tighter binding (or shorter grant TTL) is required by the first paying firm.
- Compensating controls remain: hashed tokens, short server-side sessions, rate limits,
  revoke/prune, and new-device notify/block modes.
