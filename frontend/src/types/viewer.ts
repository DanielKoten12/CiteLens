import type { CitationStatus, FindingStatus, Location } from "@/types/api";

export type HighlightKind = "reference" | "citation";

export type HighlightItem = {
  id: string;
  kind: HighlightKind;
  status: FindingStatus | CitationStatus | string;
  locations: Location[];
};
