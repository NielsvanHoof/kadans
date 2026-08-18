---
paths:
  - 'resources/js/**/tests/**'
---

# Js Tests

## Do not spread Inertia-only props onto DOM in mocks
When mocking @inertiajs/react Form or Link, strip Inertia-only props (resetOnSuccess, preserveState, preserveScroll, only, options, onSuccess, etc.) before rendering <form> or <a>. Spreading them causes React "does not recognize the prop" warnings. Prefer explicit allowlists (action, method, className, href, aria-*) over ...props.
