---
paths:
  - 'app/{DocumentProcessing,Dossiers}/**'
---

# Document Processing Dossiers

## OCR query-update must reindex; embed overview only
UploadedDocumentProcessingTransitions::complete() (and mock/demo extractors) use query()->update(), which bypasses Scout observers. Write searchable_text in that update, then refresh() + searchable() so the keyword index and Titan embedding are generated. Embed filename + document_type + summary + notes only — never field values, table cells, IBAN, or BSN. Identifiers stay in searchable_text.
