import citationsJson from "@/mocks/citations.json";
import findingsJson from "@/mocks/findings.json";
import referencesJson from "@/mocks/references.json";
import type {
  ApiCitation,
  ApiDocument,
  ApiFinding,
  ApiReference,
  Paginated,
} from "@/types/api";

const documentId = "mock-document";

export const mockDocument: ApiDocument = {
  id: documentId,
  name: "sample.pdf",
  status: "completed",
  progress: 100,
  current_step: "completed",
  error: null,
  file: {
    filename: "sample.pdf",
    mime_type: "application/pdf",
    size: 0,
    url: "/sample.pdf",
  },
  summary: undefined,
  created_at: "2026-01-01T00:00:00Z",
  updated_at: "2026-01-01T00:00:00Z",
};

export const mockReferences = referencesJson as ApiReference[];
export const mockCitations = citationsJson as ApiCitation[];
export const mockFindings = findingsJson as ApiFinding[];

export function mockPage<T>(items: T[]): Paginated<T> {
  return {
    data: items,
    meta: {
      current_page: 1,
      per_page: items.length,
      total: items.length,
      last_page: 1,
    },
  };
}

export { documentId as mockDocumentId };
