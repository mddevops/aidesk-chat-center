// Adapted from the free ReUI badge, MIT; https://reui.io/r/badge.json
// Native span replaces Base UI polymorphism; only application token variants retained.
import type { HTMLAttributes } from "react";
import { cva, type VariantProps } from "class-variance-authority";
import { cn } from "@/lib/utils";
const badgeVariants = cva("inline-flex shrink-0 items-center justify-center w-fit border font-medium whitespace-nowrap", {
  variants: {
    variant: {
      default: "border-transparent bg-primary text-primary-foreground",
      outline: "border-border bg-transparent",
      "primary-light": "border-primary/10 bg-primary/10 text-primary dark:border-primary/25 dark:bg-primary/15",
    },
    size: { default: "px-1.25 py-0.5 text-xs min-h-5 gap-1", xl: "px-2 py-0.75 text-sm min-h-6 gap-1.5" },
    radius: { default: "rounded-sm", full: "rounded-full" },
  },
  defaultVariants: { variant: "default", size: "default", radius: "default" },
});
type BadgeProps = HTMLAttributes<HTMLSpanElement> & VariantProps<typeof badgeVariants>;
export function Badge({className, variant, size, radius, ...props}: BadgeProps) {
  return <span data-slot="badge" className={cn(badgeVariants({variant, size, radius, className}))} {...props} />;
}
