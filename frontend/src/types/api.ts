// Tipe kanonis sesuai docs/API_SPEC.md. Jangan diubah tanpa mengubah spec.

export interface Envelope<T> {
  data: T;
  message?: string;
}

export interface Paginated<T> {
  data: T[];
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
  };
}

/* ---------- Enum ---------- */

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

export type FindingStatus =
  | "pending"
  | "valid"
  | "suspicious"
  | "invalid"
  | "not_found";

/** Status sitasi bersifat derived (dihitung backend, tidak disimpan). */
export type CitationStatus =
  | "valid"
  | "unreliable"
  | "pending"
  | "hallucination";

export type Severity = "high" | "medium" | "low" | "info";

export type FindingType =
  | "reference_invalid"
  | "reference_suspicious"
  | "reference_not_found"
  | "citation_unreliable"
  | "citation_hallucination";

export type ReportStatus = "pending" | "processing" | "completed" | "failed";

/* ---------- Dokumen ---------- */

export interface DocumentSummary {
  total_references: number;
  valid: number;
  suspicious: number;
  invalid: number;
  not_found: number;
  total_citations: number;
  valid_citations: number;
  unreliable_citations: number;
  pending_citations: number;
  hallucination_citations: number;
}

export interface ApiDocument {
  id: string;
  name: string;
  status: DocumentStatus;
  progress: number; // 0-100
  current_step?: AnalysisStep;
  error?: string | null;
  file?: {
    filename: string;
    mime_type: string;
    size: number; // byte
    url: string;
  };
  /** Ada setelah status = completed. */
  summary?: DocumentSummary;
  created_at: string;
  updated_at: string;
}

export interface DocumentStatusData {
  id: string;
  status: DocumentStatus;
  progress: number;
  current_step: AnalysisStep;
  error: string | null;
  updated_at: string;
}

/* ---------- Lokasi sorotan (pdf_points_top_left, halaman mulai 1) ---------- */

export interface Location {
  page_number: number;
  x: number;
  y: number;
  width: number;
  height: number;
  page_width?: number;
  page_height?: number;
  coordinate_system?: "pdf_points_top_left";
  location_index: number;
}

/* ---------- Referensi ---------- */

export interface Candidate {
  id: string;
  rank: number;
  confidence: number; // 0-1
  doi: string | null;
  title: string;
  authors: string;
  publication_name: string;
  publication_year: number;
  url: string;
  match_reason: string;
}

export interface Finding {
  id: string;
  status: FindingStatus;
  confidence: number; // 0-1, tampilkan sebagai persen
  reason: string;
  selected_candidate_id: string | null;
  is_manual?: boolean;
  reviewed_by?: string | null;
  reviewed_at?: string | null;
  candidates?: Candidate[];
}

export interface ApiReference {
  id: string;
  raw_text: string;
  doi: string | null;
  title: string | null;
  authors: string | null;
  publication_name: string | null;
  publication_year: number | null;
  text_start_offset: number;
  text_end_offset: number;
  locations?: Location[];
  finding: Finding | null;
  citations?: {
    id: string;
    citation_text: string;
    occurrence_index: number;
  }[];
}

/* ---------- Sitasi ---------- */

export interface ApiCitation {
  id: string;
  citation_text: string;
  citation_marker: string;
  context_before: string | null;
  context_after: string | null;
  text_start_offset: number;
  text_end_offset: number;
  occurrence_index: number;
  status: CitationStatus;
  reference: { id: string; title: string } | null;
  locations?: Location[];
}

/* ---------- Feed temuan / sorotan ---------- */

export interface ApiFinding {
  id: string;
  type: FindingType;
  severity: Severity;
  message: string;
  reference_id: string | null;
  citation_id: string | null;
  text: string;
  start_offset: number;
  end_offset: number;
  locations: Location[];
}

/* ---------- Laporan ---------- */

export interface ApiReport {
  id: string;
  document_id: string;
  status: ReportStatus;
  download_url: string | null;
  error?: string | null;
  generated_at: string | null;
}