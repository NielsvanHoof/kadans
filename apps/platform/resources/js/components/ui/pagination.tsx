import * as React from "react"

import { cn } from "@/lib/utils"
import { useTranslation } from "@/hooks/use-translation"
import { LinkButton } from "@/components/ui/button"
import { ChevronLeftIcon, ChevronRightIcon, MoreHorizontalIcon } from "lucide-react"

function Pagination({ className, ...props }: React.ComponentProps<"nav">) {
  const { t } = useTranslation()

  return (
    <nav
      role="navigation"
      aria-label={t("Pagination")}
      data-slot="pagination"
      className={cn("mx-auto flex w-full justify-center", className)}
      {...props}
    />
  )
}

function PaginationContent({
  className,
  ...props
}: React.ComponentProps<"ul">) {
  return (
    <ul
      data-slot="pagination-content"
      className={cn("flex items-center gap-0.5", className)}
      {...props}
    />
  )
}

function PaginationItem({ ...props }: React.ComponentProps<"li">) {
  return <li data-slot="pagination-item" {...props} />
}

type PaginationLinkProps = {
  isActive?: boolean
} & Omit<React.ComponentProps<typeof LinkButton>, "variant">

function PaginationLink({
  className,
  isActive,
  size = "icon",
  ...props
}: PaginationLinkProps) {
  return (
    <LinkButton
      variant={isActive ? "outline" : "ghost"}
      size={size}
      className={cn(className)}
      aria-current={isActive ? "page" : undefined}
      data-slot="pagination-link"
      data-active={isActive}
      {...props}
    />
  )
}

function PaginationPrevious({
  className,
  text,
  ...props
}: React.ComponentProps<typeof PaginationLink> & { text?: string }) {
  const { t } = useTranslation()

  return (
    <PaginationLink
      aria-label={t("Go to previous page")}
      size="default"
      className={cn("pl-2!", className)}
      {...props}
    >
      <ChevronLeftIcon data-icon="inline-start" />
      <span className="hidden sm:block">{text ?? t("Previous")}</span>
    </PaginationLink>
  )
}

function PaginationNext({
  className,
  text,
  ...props
}: React.ComponentProps<typeof PaginationLink> & { text?: string }) {
  const { t } = useTranslation()

  return (
    <PaginationLink
      aria-label={t("Go to next page")}
      size="default"
      className={cn("pr-2!", className)}
      {...props}
    >
      <span className="hidden sm:block">{text ?? t("Next")}</span>
      <ChevronRightIcon data-icon="inline-end" />
    </PaginationLink>
  )
}

function PaginationEllipsis({
  className,
  ...props
}: React.ComponentProps<"span">) {
  const { t } = useTranslation()

  return (
    <span
      aria-hidden
      data-slot="pagination-ellipsis"
      className={cn(
        "flex size-7 items-center justify-center [&_svg:not([class*='size-'])]:size-3.5",
        className
      )}
      {...props}
    >
      <MoreHorizontalIcon
      />
      <span className="sr-only">{t("More pages")}</span>
    </span>
  )
}

export {
  Pagination,
  PaginationContent,
  PaginationEllipsis,
  PaginationItem,
  PaginationLink,
  PaginationNext,
  PaginationPrevious,
}
