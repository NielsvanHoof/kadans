---
paths:
  - 'app/Platform/Ai/**'
---

# Ai

## ConversationStore stays the tenant-aware wrapper
Laravel AI's DatabaseConversationStore writes via DB::table(), so BelongsToTenant never fires. Bind TenantAwareDatabaseConversationStore over ConversationStore in PlatformServiceProvider. Never rebind the vendor class. Do not cache tenant_id on the singleton — Octane reuses it. UUID v7 string ids stay because the SDK returns them as conversationId.
