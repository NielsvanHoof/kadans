---
id: decision-12
title: Give client-level portal links their own shorter lifetime
date: '2026-09-15 10:03'
status: accepted
---
## Context

A `ClientAccessGrant` used to be scoped to one dossier. It can now also be scoped
to a client, opening every dossier the firm has shared with them and picking up
each new one as it is shared. That is the point of the feature — a client with
three cases open should not juggle three links — but it changes what a leaked
link is worth. A leaked dossier link exposes one case; a leaked client link
exposes the client's whole book with the firm.

`decision-4` accepted re-exchangeable, watched-but-not-bound portal tokens for
v1.0, with an explicit reopen trigger: *"before the first real client document
enters the system, reassess whether `block` should become the default for
new-device redemption, or whether tighter binding (or shorter grant TTL) is
required."*

Widening the scope is that trigger, reached earlier than expected and for a
different reason than anticipated.

## Decision

Client-scoped grants get their own lifetime, separate from dossier-scoped ones:

- `portal.client_grant_ttl_days` defaults to **30** days, against 7 for a dossier
  link. A standing link is issued once and has to outlive a single exchange of
  emails, so it cannot simply inherit the dossier default.
- `portal.client_grant_max_ttl_days` caps it at **60**, against 90 for a dossier
  link, and `StoreClientPortalLinkRequest` validates against it. Longer-lived is
  the point; unbounded is not.

`portal.new_device_action` stays `notify` for both scopes. `block` refuses with a
404 and no explanation, and a client opening their link on a phone on a new
network is the exact support burden that made single-use tokens untenable —
inflicting it on the scope clients use most would trade a real usability failure
for a marginal security gain.

The compensating controls are strengthened instead, all in the shipping slice:

- `AuditEvent::DossierSharedWithClient` records which live client links gained
  reach each time a dossier is shared, so "what could see this, and from when" is
  answerable after the fact.
- `RecordClientPortalAccessAction` logs the reachable-dossier count at the moment
  a link is used, so the trail shows its blast radius at that time rather than
  whenever someone reads the entry.
- The new-device alert now fires for client-scoped links too. It previously
  resolved the grant's dossier and returned early when there was none, which
  would have switched the signal off for exactly the scope that opens the most.
- The staff UI states plainly that the link opens every shared dossier and grows
  as more are shared. A staff member who does not know that is the actual risk;
  the mechanism is no weaker than a per-dossier link.

## Consequences

- Two TTLs to explain rather than one. Accepted: they protect different things,
  and a single number would be wrong for one of them.
- A client-scoped link expires sooner than the 90 days a firm can choose for a
  dossier link. Firms wanting a longer standing link must re-issue, which is one
  click on the client page.
- **Reopen trigger:** if a firm asks for client links longer than 60 days, or the
  first real client documents arrive, revisit whether reach should be frozen at
  issue time (a link opening only the dossiers shared when it was sent) instead
  of evaluated live. Freezing it would remove the feature's main convenience, so
  it is not the default, but it is the next lever if the current bound proves too
  loose.
- `decision-4` is not superseded. Its subject — re-exchangeable tokens with
  device watching — still stands for both scopes. This record answers the part of
  its reopen trigger that scope widening forced, and leaves the rest open.
