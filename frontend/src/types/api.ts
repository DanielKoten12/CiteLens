export type ApiSuccess<T> = {
  data: T;
  message?: string;
};

export type ApiCollection<T> = {
  data: T[];
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
};

export type ApiError = {
  error: {
    code: string;
    message: string;
    details?: Record<string, string[] | string>;
  };
};

export type DocumentStatus = "pending" | "processing" | "completed" | "failed";
export type AnalysisStep =
  | "queued"
  | "extracting"
  | "persisting"
  | "crossref_validation"
  | "embedding"
  | "scoring"
  | "resolving_citations"
  | "generating_report"
  | "completed";
export type FindingStatus = "pending" | "valid" | "suspicious" | "invalid" | "not_found";
export type CitationStatus = "valid" | "unreliable" | "pending" | "hallucination";
export type Severity = "high" | "medium" | "low" | "info";
export type FindingType =
  | "invalid_doi"
  | "unverified_reference"
  | "doi_suggestion"
  | "metadata_mismatch"
  | "citation_mismatch"
  | "reference_pending";

export type HighlightItem = {
  id: string;
  kind: "reference" | "citation";
  status: FindingStatus | CitationStatus;
  locations: HighlightLocation[];
};

export type HighlightLocation = {
  page_number: number;
  x: number;
  y: number;
  width: number;
  height: number;
  page_width: number;
  page_height: number;
  coordinate_system: "pdf_points_top_left";
  location_index: number;
};

export type DocumentSummary = {
  total_references: number;
  valid: number;
  suspicious: number;
  invalid: number;
  not_found: number;
  pending: number;
  total_citations: number;
  citation_valid: number;
  citation_unreliable: number;
  citation_pending: number;
  citation_hallucination: number;
};

export type FileResource = {
  id: string;
  filename: string;
  mime_type: string | null;
  size: number | null;
  url: string;
};

export type Finding = {
  id: string;
  type: FindingType;
  status: FindingStatus | CitationStatus;
  severity: Severity;
  reason: string;
  reference_id?: string;
  citation_id?: string;
  text_start_offset: number;
};

export type Reference = {
  id: string;
  raw_text: string;
  doi: string | null;
  title: string | null;
  authors: string | null;
  publication_name: string | null;
  publication_year: number | null;
  text_start_offset: number;
  text_end_offset: number;
  finding: {
    id: string;
    status: FindingStatus;
    confidence: number | null;
    reason: string | null;
    selected_candidate_id: string | null;
  };
  /** TODO: konfirmasi backend apakah endpoint list memuat locations. */
  locations?: HighlightLocation[];
};

export type ReferenceDetail = Reference & {
  locations: HighlightLocation[];
  finding: Reference["finding"] & {
    is_manual: boolean;
    reviewed_by: string | null;
    reviewed_at: string | null;
  };
  citations: Array<{ id: string; citation_text: string; occurrence_index: number | null }>;
};

export type Citation = {
  id: string;
  citation_text: string;
  citation_marker: string | null;
  context_before: string | null;
  context_after: string | null;
  text_start_offset: number;
  text_end_offset: number;
  occurrence_index: number;
  status: CitationStatus;
  reference: { id: string; title: string | null } | null;
  /** TODO: konfirmasi backend apakah endpoint list memuat locations. */
  locations?: HighlightLocation[];
};

export type CitationDetail = Citation & {
  locations: HighlightLocation[];
};

export type DocumentResource = {
  id: string;
  name: string;
  status: DocumentStatus;
  progress: number;
  analysis_step: AnalysisStep | null;
  analysis_error: string | null;
  summary: DocumentSummary | null;
  file: FileResource;
  created_at: string;
  updated_at: string;
};
