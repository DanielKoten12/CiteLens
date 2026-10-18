import { computed, type MaybeRefOrGetter, toValue } from "vue";
import type { Citation, HighlightItem, Reference } from "@/types/viewer";

export function useHighlights(
  references: MaybeRefOrGetter<Reference[]>,
  citations: MaybeRefOrGetter<Citation[]>,
) {
  const highlights = computed<HighlightItem[]>(() => [
    ...toValue(references).map((reference) => ({
      id: reference.id,
      kind: "reference" as const,
      status: reference.finding.status,
      locations: reference.locations ?? [],
    })),
    ...toValue(citations).map((citation) => ({
      id: citation.id,
      kind: "citation" as const,
      status: citation.status,
      locations: citation.locations ?? [],
    })),
  ]);

  return { highlights };
}
