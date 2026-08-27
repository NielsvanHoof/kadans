---
id: TASK-34.3
title: Add CloudFront edge (optional WAF) for platform hostnames
status: Done
assignee:
  - '@NielsvanHoof'
created_date: '2026-08-11 16:54'
updated_date: '2026-08-25 21:14'
labels:
  - infra
  - aws
  - edge
milestone: m-0
dependencies:
  - TASK-34.2
references:
  - >-
    .cursor/projects/Users-nielsvanhoof-Documents-projects-qompose-apps-platform/canvases/lean-security-extras.canvas.tsx
documentation:
  - infra/README.md
parent_task_id: TASK-34
priority: medium
type: feature
ordinal: 5000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
Edge extras from the Lean + security extras canvas: CloudFront in front of the ALB, with optional AWS managed WAF rules.

Serves app.qompose.nl and *.qompose.nl at the edge (TLS, cache static /build assets where safe, hide origin). Marketing www remains on its existing host.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [ ] #1 CloudFront distribution fronts the platform ALB for app.qompose.nl and *.qompose.nl
- [ ] #2 TLS works for central and tenant hostnames via ACM at the edge
- [ ] #3 Static build assets can be cached at the edge without breaking authenticated app traffic
- [ ] #4 Optional managed WAF rules can be enabled without blocking legitimate staff or portal use
- [x] #5 DNS cutover path for app and tenant wildcard is documented
- [x] #6 Infra docs cover CloudFront/WAF configuration and operational notes
<!-- AC:END -->

## Implementation Plan

<!-- SECTION:PLAN:BEGIN -->
1. edge.ts: CloudFront distribution over the ALB with a us-east-1 provider for the ACM certificate and the CLOUDFRONT-scoped WAF; /build/* cached, default and /app/* pass-through.
2. Origin lockdown: ALB security group restricted to CloudFront's origin-facing prefix list, plus a shared origin header the listener requires with a fixed 403 default.
3. dns.ts: alias records follow the edge when it is on, and never point at the ALB as well.
4. WAF: managed common/bad-inputs/ip-reputation rule groups, with SizeRestrictions_BODY and CrossSiteScripting_BODY counted so uploads and pasted client text are not blocked.
5. Knobs: edgeEnabled and wafEnabled per environment; wafEnabled overridable as Pulumi config for incident response.
6. Tests and docs: stack assertions for all of the above, plus the DNS cutover path in infra/README.md.
<!-- SECTION:PLAN:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
CloudFront edge implemented, production only (staging stays edge-free; the saving is real and staging does not need edge caching, but it means staging's ALB is its own public surface — asserted in the staging stack test rather than left implied).

**Three things that would each have broken a real deploy, now asserted by tests.**

1. *The certificate must be in us-east-1.* CloudFront reads its certificate from that region only, whatever region it serves, so `edge.ts` carries its own `aws.Provider` and there are two certificates for the same two names. Reusing the regional one fails at apply time with an error naming the certificate rather than the region. Same constraint applies to the CLOUDFRONT-scoped WebACL.
2. *AWSManagedRulesCommonRuleSet breaks this app unmodified.* `SizeRestrictions_BODY` rejects bodies over 8KB and uploads go to 20MB; `CrossSiteScripting_BODY` trips on client correspondence pasted into notes and questionnaire answers. Both overridden to `count` rather than removed, so they still report what they would have blocked. The symptom would have been an unexplained 403 from CloudFront.
3. *Caching scope.* Only `/build/*` is cached (`Managed-CachingOptimized`), safe because Vite emits content-hashed filenames so no deploy invalidation is needed. Default and `/app/*` use CachingDisabled + AllViewer: an Inertia response carries the signed-in user's workspace, and a shared cache entry would hand one firm's dossier list to another.

**Origin lockdown needs both halves.** The `com.amazonaws.global.cloudfront.origin-facing` prefix list narrows the ALB to CloudFront, but that covers everyone's distributions — so CloudFront also adds a shared secret header and the listener forwards only to rules requiring it, with a fixed 403 default. ALB rules match conditions positively, so "reject without the header" has to be written as "forward only with it". Port 80 is closed in this mode; the redirect happens at the edge.

**Two latent problems found and fixed while building.** `ORIGIN_VERIFY_HEADER` started in `edge.ts`, which `loadBalancer.ts` needed as a runtime value while `edge.ts` needed the `LoadBalancer` type back — a require cycle that was inert only because TypeScript elides type-only imports, and would have made the constant undefined the moment anyone used a value in the other direction. Moved to `config.ts`. And the test harness was silently mis-reading inputs: a single secret anywhere inside a property wraps the *whole* property in a secret envelope, so one secret in a listener rule's conditions turned the conditions array into an object and assertions read undefined. The harness now unwraps them.

`originVerifySecret` is required only when `edgeEnabled`, so staging is not made to invent a secret nothing reads; `requireOriginVerifySecret()` states that invariant once and fails during preview with a sentence rather than a TypeError inside a resource definition.

**Acceptance criteria evidence.** #5 and #6 checked: infra/README.md has a "The edge" section covering the certificate region, cache scope, origin lockdown and WAF exclusions, plus a four-step DNS cutover with the propagation gap called out (DNS resolves to the ALB for up to the record TTL while the ALB has already started refusing direct traffic — lower the TTL a day ahead, and use `edgeDomainName` to verify before DNS catches up). #1-#4 are declared by the program and asserted by 51 infra tests but need a deploy to be true; nothing has been applied.

Verified: 51 infra tests, 8 bootstrap tests. No application code changed.

Closed as superseded, not delivered (2026-08-25 reconciliation).

CloudFront and the optional WAF fronted the ALB that TASK-34.2 was going to build. Neither the ALB nor the edge was ever deployed, and TASK-43.3 deleted edge.ts along with the rest of the compute plane. Laravel Cloud fronts the application with its own Cloudflare edge, so the need this task served — TLS and an edge for app.qompose.nl — is met, just not by us.

ACs #1-#4 describe resources that no longer exist. #5 and #6 (DNS cutover path, infra docs) were met and have since been rewritten for the data plane; dns.ts still owns the hostnames, minus the ALB/CloudFront alias wiring.

One piece of this task's reasoning outlived it and is worth not losing: the AWSManagedRulesCommonRuleSet SizeRestrictions_BODY override existed because 20 MB uploads passed through the app. Since TASK-43.1 the browser PUTs straight to S3 and no large body reaches the origin, so if a WAF is ever reintroduced in front of Laravel Cloud that override is no longer needed for uploads — only CrossSiteScripting_BODY, which trips on client correspondence pasted into notes.

Recorded as decision-9 (supersedes decision-8).
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Superseded by TASK-43 / decision-9, not delivered. CloudFront/WAF fronted an ALB that was never deployed; edge.ts was deleted in TASK-43.3 and Laravel Cloud provides the edge. Verified no CloudFront or wafv2 resource remains in infra/src.
<!-- SECTION:FINAL_SUMMARY:END -->
