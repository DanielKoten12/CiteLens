import type { VariantProps } from "class-variance-authority";
import { cva } from "class-variance-authority";

export { default as Alert } from "./Alert.vue";
export { default as AlertTitle } from "./AlertTitle.vue";
export { default as AlertDescription } from "./AlertDescription.vue";

export const alertVariants = cva(
  "relative w-full rounded-lg border px-4 py-3 text-sm [&>svg]:size-4 [&>svg]:shrink-0 [&>svg+div]:translate-y-[-3px] [&:has(svg)]:pl-11 [&>svg]:absolute [&>svg]:left-4 [&>svg]:top-4",
  {
    variants: {
      variant: {
        default: "bg-background text-foreground",
        destructive: "border-destructive/40 bg-destructive/10 text-destructive [&>svg]:text-destructive",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  },
);

export type AlertVariants = VariantProps<typeof alertVariants>;
