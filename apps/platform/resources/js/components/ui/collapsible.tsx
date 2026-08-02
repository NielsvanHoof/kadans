import type { VariantProps } from "class-variance-authority"
import {
  DisclosurePanel as CollapsibleContentPrimitive,
  Disclosure as CollapsiblePrimitive,
  Button as CollapsibleTriggerPrimitive,
  type ButtonProps,
  type DisclosurePanelProps,
  type DisclosureProps,
} from "react-aria-components"

import { buttonVariants } from "@/components/ui/button"
import { cn } from "@/lib/utils"

function Collapsible({ ...props }: DisclosureProps) {
  return <CollapsiblePrimitive data-slot="collapsible" {...props} />
}

/**
 * The trigger already *is* a button, so it takes the button variants directly
 * rather than wrapping a `<Button>` — nesting one would emit a `<button>`
 * inside a `<button>`, which is invalid HTML and breaks hydration.
 */
function CollapsibleTrigger({
  className,
  variant,
  size,
  ...props
}: Omit<ButtonProps, "className"> &
  VariantProps<typeof buttonVariants> & { className?: string }) {
  return (
    <CollapsibleTriggerPrimitive
      slot="trigger"
      data-slot="collapsible-trigger"
      className={cn(buttonVariants({ variant, size, className }))}
      {...props}
    />
  )
}

function CollapsibleContent({ ...props }: DisclosurePanelProps) {
  return (
    <CollapsibleContentPrimitive data-slot="collapsible-content" {...props} />
  )
}

export { Collapsible, CollapsibleTrigger, CollapsibleContent }
