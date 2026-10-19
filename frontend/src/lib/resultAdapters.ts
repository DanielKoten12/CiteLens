import type { ApiReference, CitationStatus, FindingStatus } from "@/types/api";

export type UiStatus = "valid" | "warning" | "halu";

export type ReferenceRow = {
  id: string;
  no: number;
  title: string;
  authors: string;
  year: number | null;
  doi: string | null;
  status: UiStatus;
  apiStatus: FindingStatus | null;
  confidence: number | null;
  reason: string;
};

export function toUiStatus(status?: FindingStatus | null): UiStatus {
  if (status === "valid") return "valid";
  if (status === "invalid" || status === "not_found") return "halu";
  return "warning";
}

export function citationGroup(status: CitationStatus): UiStatus {
  if (status === "valid") return "valid";
  if (status === "pending") return "warning";
  return "halu";
}

export function toReferenceRow(reference: ApiReference, index: number): ReferenceRow {
  const finding = reference.finding;
  return {
    id: reference.id,
    no: index + 1,
    title: reference.title ?? reference.raw_text,
    authors: reference.authors ?? "",
    year: reference.publication_year,
    doi: reference.doi,
    status: toUiStatus(finding?.status),
    apiStatus: finding?.status ?? null,
    confidence: finding?.confidence == null ? null : Math.round(finding.confidence * 100),
    reason: finding?.reason ?? "Belum ada hasil verifikasi.",
  };
}

export function countByStatus(rows: ReferenceRow[]) {
  return rows.reduce(
    (counts, row) => {
      counts[row.status] += 1;
      return counts;
    },
    { valid: 0, warning: 0, halu: 0 },
  );
}
