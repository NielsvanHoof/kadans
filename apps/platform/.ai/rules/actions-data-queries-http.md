---
paths:
  - 'app/**/{Actions,Data,Queries,Http}/**'
---

# Actions Data Queries Http

## Mixed domains nest concerns under the layer
When a domain mixes unrelated product concerns, nest by concern under the layer: app/{Domain}/Actions/{Concern}/Create… (same for Data, Queries, Http/Controllers, Http/Requests). Mixed today: Dossiers, Accounts, ClientPortal, Tenancy, Platform, Reporting, Questionnaires, Integrations, DocumentProcessing. Thin domains stay flat: Clients, Pipelines, Audit, AudioProcessing, WorkspaceNotifications, Shared. Models, Policies, and Enums stay at the domain root. Do not put concern folders at the domain root.
