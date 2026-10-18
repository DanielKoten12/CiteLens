import referencesJson from "@/mocks/references.json";
import citationsJson from "@/mocks/citations.json";
import findingsJson from "@/mocks/findings.json";
import type { ApiCollection, Citation, Finding, Reference } from "@/types/viewer";

const mockMeta = (total: number) => ({
  current_page: 1,
  per_page: total,
  total,
  last_page: 1,
});

export async function getReferences(): Promise<ApiCollection<Reference>> {
  return { data: referencesJson as Reference[], meta: mockMeta(referencesJson.length) };
}

export async function getCitations(): Promise<ApiCollection<Citation>> {
  return { data: citationsJson as Citation[], meta: mockMeta(citationsJson.length) };
}

export async function getFindings(): Promise<ApiCollection<Finding>> {
  return { data: findingsJson as Finding[], meta: mockMeta(findingsJson.length) };
}
