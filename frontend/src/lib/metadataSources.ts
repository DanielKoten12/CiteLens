import type { Candidate } from "@/types/api";

/**
 * Metadata sources a candidate can come from (`docs/API_SPEC.md` §5).
 *
 * The backend owns the verdict and the `source` value; this map is presentation
 * copy only, used for chips and candidate badges. It must stay in sync with the
 * `Candidate["source"]` union.
 */
export type MetadataSource = Candidate["source"];

export const METADATA_SOURCE_LABELS: Record<MetadataSource, string> = {
  crossref: "Crossref",
  garuda: "Garuda",
  semantic_scholar: "Semantic Scholar",
  openalex: "OpenAlex",
};

export function metadataSourceLabel(source: MetadataSource): string {
  return METADATA_SOURCE_LABELS[source] ?? source;
}
