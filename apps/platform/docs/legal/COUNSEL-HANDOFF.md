# Counsel handoff — Qompose EU/NL legal pack

**Status:** Draft for Dutch counsel review (not legal advice; not final).  
**Prepared:** 2026-08-11  
**Product:** Qompose — multi-tenant SaaS for law firms (dossiers, document requests, client portal, AI-assisted features).

## Documents in this pack

| Document | Path | Public page? |
| --- | --- | --- |
| Cookie inventory | [`cookie-inventory.md`](cookie-inventory.md) | Basis for cookie statement |
| Privacy policy (EN/NL) | [`../resources/legal/en/privacy.md`](../../resources/legal/en/privacy.md) / [`nl`](../../resources/legal/nl/privacy.md) | Yes — `/legal/privacy` |
| Cookie statement (EN/NL) | `resources/legal/{en,nl}/cookies.md` | Yes — `/legal/cookies` |
| Terms of service (EN/NL) | `resources/legal/{en,nl}/terms.md` | Yes — `/legal/terms` |
| DPA / verwerkersovereenkomst draft | [`drafts/dpa-verwerkersovereenkomst.md`](drafts/dpa-verwerkersovereenkomst.md) | No — customer contract annex |
| Processor RoPA starter | [`drafts/processor-ropa-starter.md`](drafts/processor-ropa-starter.md) | No — internal |

## Placeholders counsel must fill

- Legal entity name, address, KvK, VAT
- Privacy contact / DPO (if appointed)
- Sub-processor list (hosting, email, AI providers, object storage, etc.) with locations and transfer tools (adequacy / SCCs)
- Governing law and competent court for ToS
- Exact controller/processor role split between Qompose and customer firms for each processing activity
- Whether persistent preference cookies require consent under NL practice

## Product facts already reflected in drafts

- Staff accounts, tenants/workspaces, clients, dossiers, document uploads, questionnaires
- Client portal with device binding (fingerprint stored on access grants)
- Data subject export / erasure flows (`DataSubjectRequestType`)
- Retention defaults in `config/retention.php` (e.g. archived records ~1095 days; portal grants ~90 days; DSAR response 30 days)
- AI features (e.g. audio transcription / document assistance) — disclosed at a high level; refine with actual model vendors
- Cookies listed in the inventory; **no consent banner** shipped until non-essential trackers are added

## Review checklist for counsel

1. Confirm Art. 13/14 completeness of the privacy notice for staff, firm admins, and portal end-clients (roles).
2. Confirm Art. 28 DPA mandatory clauses and sub-processor cascade.
3. Finalize customer ToS + portal acceptable-use terms (commercial; not covered by privacy skills).
4. Confirm cookie classifications and whether a CMP is required for preference cookies.
5. Approve EN/NL wording or supply replacements for `resources/legal/**`.
6. After approval, remove “Draft — pending legal review” banners from public pages (search for `legal_document_is_draft`).

## Skills used to draft

From `mukul975/Privacy-Data-Protection-Skills` (installed under `.agents/skills/`):  
`cookie-audit`, `eprivacy-essential-cookies`, `cnil-compliant-cookies`, `gdpr-policy-framework`, `direct-collection-notice`, `indirect-collection-notice`, `gdpr-dpa-art28`, `dpa-drafting`, `processor-ropa-creation`, `retention-schedule`, `ai-transparency-reqs`, `dsar-processing`, `right-to-erasure`.
