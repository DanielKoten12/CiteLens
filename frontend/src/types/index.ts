export type RefStatus = "valid" | "warning" | "halu";
export type FilterStatus = "all" | RefStatus;

export type DoiCheckStatus =
  | "resolves_match"
  | "resolves_mismatch"
  | "resolves_partial"
  | "not_found"
  | "no_doi";

export interface DoiCheck {
  status: DoiCheckStatus;
  resolvedUrl?: string;
  resolvedTitle?: string;
  titleSimilarity?: number; // 0–100
  note?: string;
}

export interface Reference {
  id: number;
  color: string;
  title: string;
  authors: string;
  year: number;
  doi: string;
  status: RefStatus;
  citedAt: number[];
  doiCheck: DoiCheck;
  haluReasons?: string[];
  warningReasons?: string[];
  citedInText: boolean;
  /** Optional confirmation note shown for a manually-verified valid reference. */
  crossrefMatch?: string;
}

export interface CitationSpan {
  text: string;
  refId: number | null;
  isHalu?: boolean;
}

export interface DocumentSegment {
  type: "text" | "citation";
  content: string;
  refId?: number | null;
  isHalu?: boolean;
}

export interface PipelineStep {
  num: string;
  title: string;
  desc: string;
}

export interface HistoryEntry {
  id: string;
  fileName: string;
  uploadedAt: string;
  date: string;
  timestamp: number;
  totalRefs: number;
  valid: number;
  warning: number;
  halu: number;
  trustScore: number;
  size: string;
  pages: number;
}

export interface AuthUser {
  name: string;
  email: string;
}

export interface HighlightColor {
  bg: string;
  border: string;
  text: string;
}
