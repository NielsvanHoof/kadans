---
paths:
  - 'resources/js/layouts/**'
---

# Layouts

## Use min-w-0, not overflow-x-hidden, on the main content column
`AppContent`/`SidebarInset` is a flex item in a row container (`ui/sidebar.tsx` sidebar-wrapper) and carries no `min-w-0` of its own. Per the flexbox spec, `min-width: auto` only resolves to the content-based minimum when the item's overflow is `visible` — so setting `overflow-x-hidden` there was silently standing in for `min-w-0`.

Both stop the page scrolling sideways, but they differ where it matters: `overflow-x-hidden` **clips** anything too wide, while `min-w-0` lets inner `overflow-x-auto` regions **scroll**. Tables already ship their own scroller (`ui/table.tsx` wraps every Table in `relative w-full overflow-x-auto`), so with `min-w-0` a wide table stays reachable instead of being cut off with no affordance.

Rule: reach for `min-w-0` on the content column. Only use `overflow-x-hidden` when you actually intend to clip.
