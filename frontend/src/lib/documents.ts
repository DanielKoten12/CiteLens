import { api } from "@/lib/api";
import type {
  ApiCitation,
  ApiDocument,
  ApiFinding,
  ApiReference,
  ApiReport,
  DocumentStatusData,
  Envelope,
  Paginated,
} from "@/types/api";

/**
 * Ubah objek parameter jadi query string. Parameter bertipe `object`
 * (bukan Record<string, unknown>) supaya interface seperti PageParams
 * diterima TypeScript. Nilai undefined / null / "" dilewati.
 */
function qs(params: object = {}): string {
  const s = new URLSearchParams();
  for (const [k, v] of Object.entries(params)) {
    if (v !== undefined && v !== null && v !== "") s.set(k, String(v));
  }
  const str = s.toString();
  return str ? `?${str}` : "";
}

interface PageParams {
  page?: number;
  per_page?: number; // default 15, maks 100
}

export const documentsApi = {
  /* ----- Dokumen ----- */

  /** POST /documents (multipart). Balasan 202; analisis berjalan asinkron. */
  upload(file: File, name?: string) {
    const fd = new FormData();
    fd.append("file", file);
    if (name) fd.append("name", name);
    return api<Envelope<ApiDocument>>("/documents", {
      method: "POST",
      body: fd,
    });
  },

  list: (p?: PageParams & { status?: string; q?: string; sort?: string }) =>
    api<Paginated<ApiDocument>>(`/documents${qs(p)}`),

  get: (id: string) => api<Envelope<ApiDocument>>(`/documents/${id}`),

  /** Endpoint polling ringan. Batas 120 request/menit/pengguna. */
  status: (id: string) =>
    api<Envelope<DocumentStatusData>>(`/documents/${id}/status`),

  /** Hanya untuk dokumen berstatus failed, selain itu 409. */
  retry: (id: string) =>
    api<Envelope<ApiDocument>>(`/documents/${id}/retry`, { method: "POST" }),

  remove: (id: string) => api<null>(`/documents/${id}`, { method: "DELETE" }),

  /** Hapus seluruh riwayat milik pengguna. */
  purge: () => api<null>("/documents", { method: "DELETE" }),

  /* ----- Referensi, sitasi, temuan per dokumen ----- */

  references: (
    id: string,
    p?: PageParams & { status?: string; has_doi?: boolean },
  ) => api<Paginated<ApiReference>>(`/documents/${id}/references${qs(p)}`),

  citations: (
    id: string,
    p?: PageParams & { status?: string; reference_id?: string },
  ) => api<Paginated<ApiCitation>>(`/documents/${id}/citations${qs(p)}`),

  findings: (
    id: string,
    p?: PageParams & { type?: string; severity?: string },
  ) => api<Paginated<ApiFinding>>(`/documents/${id}/findings${qs(p)}`),

  /* ----- Detail + review manual ----- */

  reference: (id: string) => api<Envelope<ApiReference>>(`/references/${id}`),

  citation: (id: string) => api<Envelope<ApiCitation>>(`/citations/${id}`),

  /** PATCH /references/{id}/finding. status: valid|suspicious|invalid|not_found */
  reviewReference: (
    id: string,
    body: {
      status: string;
      selected_candidate_id?: string | null;
      reason?: string | null;
    },
  ) =>
    api<Envelope<unknown>>(`/references/${id}/finding`, {
      method: "PATCH",
      body: JSON.stringify(body),
    }),

  /** Pasangkan sitasi ke referensi (satu dokumen), atau null untuk melepas. */
  pairCitation: (id: string, referenceId: string | null) =>
    api<Envelope<unknown>>(`/citations/${id}`, {
      method: "PATCH",
      body: JSON.stringify({ researched_document_reference_id: referenceId }),
    }),

  /* ----- Laporan PDF ----- */

  /** Hanya untuk dokumen completed, selain itu 409. */
  createReport: (docId: string) =>
    api<Envelope<ApiReport>>(`/documents/${docId}/reports`, { method: "POST" }),

  reports: (docId: string) =>
    api<Paginated<ApiReport>>(`/documents/${docId}/reports`),

  report: (id: string) => api<Envelope<ApiReport>>(`/reports/${id}`),

  removeReport: (id: string) =>
    api<null>(`/reports/${id}`, { method: "DELETE" }),
};