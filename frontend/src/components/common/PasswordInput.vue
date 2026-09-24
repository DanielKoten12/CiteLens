<script setup lang="ts">
import { ref } from "vue";
import { Eye, EyeOff } from "lucide-vue-next";
import { Input } from "@/components/ui/input";
import { cn } from "@/lib/utils";

withDefaults(
  defineProps<{
    modelValue: string;
    id?: string;
    placeholder?: string;
    autocomplete?: string;
    error?: boolean;
  }>(),
  { error: false },
);

const emit = defineEmits<{ (e: "update:modelValue", value: string): void }>();

const visible = ref(false);
</script>

<template>
  <div class="relative">
    <Input
      :id="id"
      :type="visible ? 'text' : 'password'"
      :model-value="modelValue"
      :placeholder="placeholder"
      :autocomplete="autocomplete"
      :class="cn('pr-10', error && 'border-destructive focus-visible:ring-destructive')"
      @update:model-value="(v) => emit('update:modelValue', String(v))"
    />
    <button
      type="button"
      tabindex="-1"
      class="absolute right-3 top-1/2 -translate-y-1/2 transition-colors"
      :style="{ color: 'var(--muted-foreground)' }"
      @click="visible = !visible"
    >
      <EyeOff v-if="visible" class="h-4 w-4" />
      <Eye v-else class="h-4 w-4" />
    </button>
  </div>
</template>
