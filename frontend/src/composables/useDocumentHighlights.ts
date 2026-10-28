import { computed, ref } from "vue";
import { ApiError } from "@/lib/api";
import { documentsApi } from "@/lib/documents";
import {
  mockCitations,
  mockDocument,
  mockFindings,
  mockPage,
  mockReferences,
} from "@/mocks";
import type {
  ApiCitation,
  ApiDocument,
  ApiFinding,
  ApiReference,
  Paginated,
} from "@/types/api";
import type { HighlightItem } from "@/types/viewer";

type HighlightSource = {
  get: (id: string) => Promise<{ data: ApiDocument }>;
  references: (id: string, params?: { page?: number; per_page?: number }) => Promise<Paginated<ApiReference>>;
  citations: (id: string, params?: { page?: number; per_page?: number }) => Promise<Paginated<ApiCitation>>;
  findings: (id: string, params?: { page?: number; per_page?: number }) => Promise<Paginated<ApiFinding>>;
  reference: (id: string) => Promise<{ data: ApiReference }>;
  citation: (id: string) => Promise<{ data: ApiCitation }>;
};

const cache = new Map<string, DocumentHighlightsResult>();

function wait(milliseconds: number) {
  return new Promise((resolve) => window.setTimeout(resolve, milliseconds));
}

async function withRateLimitRetry<T>(request: () => Promise<T>): Promise<T> {
  for (let attempt = 0; attempt < 3; attempt += 1) {
    try {
      return await request();
    } catch (error) {
      if (!(error instanceof ApiError) || error.status !== 429 || attempt === 2) throw error;
      await wait(1100);
    }
  }
  throw new Error("Permintaan API gagal setelah percobaan ulang.");
}

async function allPages<T>(
  request: (page: number) => Promise<Paginated<T>>,
): Promise<T[]> {
  const items: T[] = [];
  let page = 1;
  let lastPage = 1;

  do {
    const response = await withRateLimitRetry(() => request(page));
    items.push(...response.data);
    lastPage = response.meta.last_page;
    page += 1;
  } while (page <= lastPage);

  return items;
}

async function mapWithConcurrency<T, R>(
  values: T[],
  limit: number,
  mapper: (value: T) => Promise<R>,
): Promise<R[]> {
  const results = new Array<R>(values.length);
  let next = 0;

  async function worker() {
    while (next < values.length) {
      const index = next;
      next += 1;
      results[index] = await mapper(values[index]);
    }
  }

  await Promise.all(Array.from({ length: Math.min(limit, values.length) }, worker));
  return results;
}

function createMockSource(): HighlightSource {
  return {
    get: async () => ({ data: mockDocument }),
    references: async () => mockPage(mockReferences.map(({ locations: _locations, ...reference }) => reference)),
    citations: async () => mockPage(mockCitations.map(({ locations: _locations, ...citation }) => citation)),
    findings: async () => mockPage(mockFindings),
    reference: async (id) => ({
      data: mockReferences.find((reference) => reference.id === id) ?? mockReferences[0],
    }),
    citation: async (id) => ({
      data: mockCitations.find((citation) => citation.id === id) ?? mockCitations[0],
    }),
  };
}

function createApiSource(): HighlightSource {
  return documentsApi;
}

function locationsByEntity(findings: ApiFinding[]) {
  const references = new Map<string, ApiFinding["locations"]>();
  const citations = new Map<string, ApiFinding["locations"]>();
  for (const finding of findings) {
    if (finding.reference_id) references.set(finding.reference_id, finding.locations);
    if (finding.citation_id) citations.set(finding.citation_id, finding.locations);
  }
  return { references, citations };
}

function frontendFileUrl(document: ApiDocument): ApiDocument {
  if (!document.file?.url) return document;

  try {
    const url = new URL(document.file.url, window.location.origin);
    if (url.pathname.startsWith("/storage/")) {
      return {
        ...document,
        file: { ...document.file, url: `${url.pathname}${url.search}` },
      };
    }
  } catch {
    // Keep the backend URL unchanged if it is not a valid absolute/relative URL.
  }

  return document;
}

export type DocumentHighlightsResult = {
  document: ApiDocument | null;
  references: ApiReference[];
  citations: ApiCitation[];
  highlights: HighlightItem[];
  loading: boolean;
  error: ApiError | Error | null;
};

export function useDocumentHighlights(
  documentId?: string,
  options: { mock?: boolean } = {},
) {
  const document = ref<ApiDocument | null>(null);
  const references = ref<ApiReference[]>([]);
  const citations = ref<ApiCitation[]>([]);
  const highlights = ref<HighlightItem[]>([]);
  const loading = ref(false);
  const error = ref<ApiError | Error | null>(null);
  const cacheKey = options.mock ? "mock" : documentId ?? "";

  async function load() {
    if (!cacheKey) {
      error.value = new Error("ID dokumen tidak tersedia.");
      return;
    }

    const cached = cache.get(cacheKey);
    if (cached) {
      document.value = cached.document;
      references.value = cached.references;
      citations.value = cached.citations;
      highlights.value = cached.highlights;
      return;
    }

    loading.value = true;
    error.value = null;
    try {
      const source = options.mock ? createMockSource() : createApiSource();
      const id = options.mock ? "mock-document" : documentId!;
      const [documentResponse, referenceItems, citationItems, findingItems] = await Promise.all([
        withRateLimitRetry(() => source.get(id)),
        allPages((page) => source.references(id, { page, per_page: 100 })),
        allPages((page) => source.citations(id, { page, per_page: 100 })),
        allPages((page) => source.findings(id, { page, per_page: 100 })),
      ]);

      const locations = locationsByEntity(findingItems);

      // TODO: hapus fallback detail ini jika endpoint list sudah memuat locations.
      const referenceDetails = await mapWithConcurrency(
        referenceItems.filter((reference) => !reference.locations?.length),
        4,
        (reference) => withRateLimitRetry(() => source.reference(reference.id)),
      );
      const citationDetails = await mapWithConcurrency(
        citationItems.filter((citation) => !citation.locations?.length),
        4,
        (citation) => withRateLimitRetry(() => source.citation(citation.id)),
      );
      const detailReferences = new Map(referenceDetails.map(({ data }) => [data.id, data]));
      const detailCitations = new Map(citationDetails.map(({ data }) => [data.id, data]));

      const enrichedReferences = referenceItems.map((reference) => {
        const detail = detailReferences.get(reference.id);
        const findingLocations = locations.references.get(reference.id);
        return {
          ...reference,
          locations: findingLocations?.length
            ? findingLocations
            : reference.locations?.length
              ? reference.locations
              : detail?.locations ?? [],
        };
      });
      const enrichedCitations = citationItems.map((citation) => {
        const detail = detailCitations.get(citation.id);
        const findingLocations = locations.citations.get(citation.id);
        return {
          ...citation,
          context_before: detail?.context_before ?? citation.context_before,
          context_after: detail?.context_after ?? citation.context_after,
          reference: detail?.reference ?? citation.reference,
          status: detail?.status ?? citation.status,
          locations: findingLocations?.length
            ? findingLocations
            : citation.locations?.length
              ? citation.locations
              : detail?.locations ?? [],
        };
      });

      const result: DocumentHighlightsResult = {
        document: frontendFileUrl(documentResponse.data),
        references: enrichedReferences,
        citations: enrichedCitations,
        highlights: [
          ...enrichedReferences.map((reference) => ({
            id: reference.id,
            kind: "reference" as const,
            status: reference.finding?.status ?? "pending",
            locations: reference.locations ?? [],
          })),
          ...enrichedCitations.map((citation) => ({
            id: citation.id,
            kind: "citation" as const,
            status: citation.status,
            locations: citation.locations ?? [],
          })),
        ],
        loading: false,
        error: null,
      };
      cache.set(cacheKey, result);
      document.value = result.document;
      references.value = result.references;
      citations.value = result.citations;
      highlights.value = result.highlights;
    } catch (caught) {
      error.value = caught instanceof ApiError || caught instanceof Error
        ? caught
        : new Error("Gagal memuat data dokumen.");
    } finally {
      loading.value = false;
    }
  }

  return {
    document,
    references,
    citations,
    highlights: computed(() => highlights.value),
    loading,
    error,
    load,
  };
}
