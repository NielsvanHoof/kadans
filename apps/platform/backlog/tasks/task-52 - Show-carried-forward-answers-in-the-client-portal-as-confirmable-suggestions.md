---
id: TASK-52
title: Show carried-forward answers in the client portal as confirmable suggestions
status: Done
assignee: []
created_date: '2026-09-02 19:53'
updated_date: '2026-09-11 12:31'
labels: []
dependencies:
  - TASK-51
priority: medium
type: feature
ordinal: 110000
---

## Description

<!-- SECTION:DESCRIPTION:BEGIN -->
TASK-51 carries stable answers into the next period and stamps carried_forward_at on those document requests, but leaves them Pending. In the portal they currently render as ordinary prefilled fields, which is indistinguishable from an answer the client already gave this period. For KYC that is the wrong signal: a fact from last year is a suggestion, not a submission.

The portal should show a carried-forward answer as last periods value with a clear confirm-or-change affordance, so the client actively reconfirms rather than silently inheriting.

Depends on TASK-51 for the carried_forward_at column and the rollover itself.
<!-- SECTION:DESCRIPTION:END -->

## Acceptance Criteria
<!-- AC:BEGIN -->
- [x] #1 A document request with carried_forward_at set renders in the portal with its previous answer visible and marked as coming from the previous period
- [x] #2 The client can confirm the shown answer in one action, which submits it as this periods answer and clears the carried-forward marking
- [x] #3 The client can change the shown answer, and changing it submits normally and clears the carried-forward marking
- [x] #4 Portal progress does not count a carried-forward answer as complete until the client confirms or changes it
- [x] #5 Requests with no carried_forward_at are unchanged in look and behaviour
- [x] #6 All new copy exists in both lang/en.json and lang/nl.json
<!-- AC:END -->

## Implementation Notes

<!-- SECTION:NOTES:BEGIN -->
Portal payload now includes is_carried_forward. Carried answers render as last year’s labelled suggestion with Still correct / Change answer, stay incomplete until the client acts, and submitting (confirm or change) clears carried_forward_at.
<!-- SECTION:NOTES:END -->

## Final Summary

<!-- SECTION:FINAL_SUMMARY:BEGIN -->
Carried-forward answers no longer look like this period’s submission. The portal labels last year’s value, offers one-click confirm or edit, and progress stays incomplete until the client responds. Verified with PortalCarriedForwardAnswerTest, DocumentRequestTransitionsTest, ClientPortalTest, and portal frontend tests.
<!-- SECTION:FINAL_SUMMARY:END -->
