<script setup lang="ts">
import type { HTMLAttributes } from "vue";
import { Label, type LabelProps } from "reka-ui";
import { cn } from "@/lib/utils";

const props = defineProps<LabelProps & { class?: HTMLAttributes["class"] }>();
</script>

<template>
  <Label
    v-bind="props"
    :class="cn(
      'text-xs font-bold leading-none peer-disabled:cursor-not-allowed peer-disabled:opacity-70',
      props.class,
    )"
  >
    <slot />
  </Label>
</template>
