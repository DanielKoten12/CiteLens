# Phase 05 — Citation Resolution, Review Endpoints & Findings Feed

> **Status:** implemented ([`05-citation-resolution-and-review-detail.md`](05-citation-resolution-and-review-detail.md))
> · **Depends on:** Phase 04 · **Unblocks:** Phase 06
> Canonical references: `docs/API_SPEC.md` §2.6/§5/§6/§7/§11, `docs/ARCHITECTURE.md` §9,
> `docs/PRODUCT_REQUIREMENTS.md` §6.4/§6.5, `docs/TEST_PLAN.md` T-REF / T-CIT / T-FIND / T-OWN.

---

## 1. Objective

Complete the user-facing verification surface:

1. resolve each in-text citation to a bibliography reference (or leave it unpaired),
2. implement the **single** derived-citation-status rule and reuse it everywhere (DTOs, filters,
   summary, findings feed, manual review),
3. expose `/references`, `/citations` and `/findings` endpoints with the canonical filters,
   pagination and manual-review rules,
4. build the derived findings/highlight feed.

---

## 2. Scope

**In:** citation marker parsing + resolution, derived status resolver + query scope,
`ReferenceController`, `CitationController`, `FindingsController`, their requests/DTOs/services,
manual review audit, findings feed composition.

**Out:** the reference matching/scoring engine (Phase 04), report generation (Phase 06), frontend
rendering.

---

## 3. Deliverables

### 3.1 Citation resolution — `app/Services/Citations/`

| Artifact | Responsibility |
|---|---|
| `CitationMarkerParser` | parse the marker into a structured form: APA (`(Koten, 2023)`, `Koten et al. (2023)`, `(Koten & Tani, 2023)`) and IEEE (`[3]`, `[3], [5]`, `[3-5]`); extract surnames + year for APA and ordinals for IEEE |
| `CitationResolver` | pair each citation with at most one reference of the **same document**; returns a per-citation match result with a score/reason |
| `CitationStatusResolver` | the single derived-status rule (`hallucination` / `pending` / `valid` / `unreliable`) |
| `CitationMatchConfig` | thresholds from `config/scoring.php` (`citation_matching`) |

Resolution rules:

- **IEEE/numeric (proposal scope: APA + IEEE only):** map ordinals to references by their order in
  the bibliography. There is **no ordinal column**; use bibliography order
  (`text_start_offset` ASC, nulls last, `id` tiebreak) as the reference index basis — OQ-18
  resolved. It relies on extraction filling offsets.
- **APA:** compare parsed surnames (+ year) against reference authors (+ `publication_year`) using
  Jaro-Winkler and exact year matching (with the same `year_tolerance` as scoring, default 0).
  Pair only when the best match is above a configured threshold; ties fall back to the higher
  score, then the earliest bibliography position.
- Citations that cannot be paired stay unpaired → derived `hallucination`.
- Resolution is idempotent: recompute all pairings from scratch per document run (the retry reset
  from Phase 02 already clears nothing here, so explicitly null/rewrite
  `researched_document_reference_id` for the document's citations before resolving).
- Never pair across documents; manual pairing has the same rule (enforced in the request).

### 3.2 Derived citation status (single source of truth)

`CitationStatusResolver` implements exactly `docs/API_SPEC.md` §2.6:

| Condition | Status |
|---|---|
| `researched_document_reference_id` is null | `hallucination` |
| paired, finding missing or `pending` | `pending` |
| paired, finding `valid` or `suspicious` | `valid` |
| paired, finding `invalid` or `not_found` | `unreliable` |

Usage rules:

- DTOs read the status through the resolver (or a model accessor delegating to it) — never
  reimplement the mapping in a controller/DTO.
- List filters (`status` on citations, summary counts) use a model query scope that mirrors the
  same mapping in SQL. A unit test must assert the PHP resolver and the SQL scope agree for all
  four statuses on the same fixture.
- Manual review of a reference finding immediately changes every dependent citation's derived
  status (no persisted cache).

### 3.3 Endpoints

| Action | Route | Status | Filters / body |
|---|---|---|---|
| `ReferenceController@index` | `GET /documents/{document}/references` | 200 | `status` (finding status), `has_doi` (bool), paginated |
| `ReferenceController@show` | `GET /references/{reference}` | 200 | finding + candidates + locations + resolved citations |
| `ReferenceController@updateFinding` | `PATCH /references/{reference}/finding` | 200 | `status`, `selected_candidate_id`, `reason` |
| `CitationController@index` | `GET /documents/{document}/citations` | 200 | `status` (derived), `reference_id`, paginated |
| `CitationController@show` | `GET /citations/{citation}` | 200 | locations |
| `CitationController@update` | `PATCH /citations/{citation}` | 200 | `researched_document_reference_id` nullable |
| `FindingsController@index` | `GET /documents/{document}/findings` | 200 | `type`, `severity`, paginated |

Route/ownership requirements: same as Phase 02 — scoped finders, `whereUuid`, foreign access
`404`.

Endpoint details:

- **Reference list item** matches `docs/API_SPEC.md` §5: ids, textual fields, offsets, embedded
  `finding` preview (`id`, `status`, `confidence`, `reason`, `selected_candidate_id`).
  `status=pending` filter must include references whose finding is missing **or** `pending`.
  `has_doi=true` means a non-null, non-empty normalized DOI.
- **Reference detail** adds `locations` (page + bbox + `coordinate_system` + `location_index`) and
  `citations` (resolved occurrences: `id`, `citation_text`, `occurrence_index`), with the finding
  detail including `is_manual`/`reviewed_by`/`reviewed_at` and ranked `candidates`.
- **Citation list/detail** matches §6: text/marker/contexts/offsets/`occurrence_index`, derived
  `status`, `reference` preview (nullable), `locations` in detail.
- **PATCH reference finding** (§5): `status` required and one of
  `valid|suspicious|invalid|not_found` (not `pending`); `selected_candidate_id` nullable and must
  belong to the finding (else `422`); `reason` nullable string (bounded). On success set
  `is_manual = true`, `reviewed_by = auth()->id()`, `reviewed_at = now()`; respond with the
  review payload + `"Status referensi diperbarui."`. If the finding does not exist yet, create it
  as a manual finding (confidence `null`) when the document is not actively `processing`; reject
  with `409` while the pipeline is running (OQ-17 resolved).
- **PATCH citation** (§6): nullable
  `researched_document_reference_id`; when present it must reference a reference of the **same
  document** (else `422` with
  `"The selected reference is invalid for this document."`). Return `id`, freshly derived
  `status`, `reference` preview + `"Sitasi berhasil ditautkan."` (pair); decide an equivalent
  message for unpairing in the detailed plan.
- **Findings feed** (§7): derived, read-only union of problem reference findings
  (`invalid`/`suspicious`/`not_found`, plus `pending` — OQ-09 resolved) and citations with derived
  `unreliable`/`hallucination`. Item shape: `id` (`ref-finding:{findingId}` /
  `citation:{citationId}`), `type`, `severity`, `message` (finding `reason` or a canonical
  message), `reference_id`, `citation_id`, `text`, `start_offset`, `end_offset`, `locations`.
  Severity mapping from §2.6; `citation_unreliable` inherits its paired finding's severity.
  `low` is reserved and never emitted by the current mapping. Sorting: document position
  (`text_start_offset` ASC, `id` tiebreak) per OQ-09 resolved.

### 3.4 Requests — `app/Http/Requests/{Reference,Citation,Finding}/`

- `ListReferencesRequest` — `status` enum, `has_doi` boolean (accept `true/false/1/0`), `page`,
  `per_page`.
- `UpdateReferenceFindingRequest` — `status` required enum (excluding `pending`),
  `selected_candidate_id` nullable uuid, `reason` nullable string; candidate-membership rule via
  `withValidator` (or a rule object) → `422`.
- `ListCitationsRequest` — `status` derived enum, `reference_id` uuid + same-document guarantee
  (invalid → `422`), pagination.
- `UpdateCitationRequest` — `researched_document_reference_id` nullable uuid with the same-document
  rule → `422`.
- `ListFindingsRequest` — `type`/`severity` enums, pagination.

Invalid filters must return `422 VALIDATION_ERROR` (not silently ignore).

### 3.5 DTOs

- `App\Data\Reference\`: `ReferenceSummaryData`, `ReferenceDetailData`,
  `ReferenceLocationPreviewData`.
- `App\Data\ReferenceFinding\`: `ReferenceFindingPreviewData`, `ReferenceFindingDetailData`,
  `ReferenceFindingCandidatePreviewData`, `ReferenceFindingReviewData`.
- `App\Data\Citation\`: `CitationSummaryData`, `CitationDetailData`,
  `CitationLocationPreviewData`, `CitationReferencePreviewData`, `CitationPairingData`.
- `App\Data\Finding\`: `FindingHighlightData`.

All with `#[MapName(SnakeCaseMapper)]`, built with `fromModel()` where practical, and eager-loaded
by `ModelData::relations()` to avoid N+1.

### 3.6 Findings feed query — `app/Services/Findings/FindingsFeedQuery`

- Builds a paginated, typed feed over two sources with filters applied on each side.
- Recommendation: a single `unionAll` of two normalized `SELECT`s (common columns:
  `id`, `type`, `severity`, `message`, `reference_id`, `citation_id`, `text`, `start_offset`,
  `end_offset`, `sort_key`) so pagination/`meta` are correct without loading everything into
  memory. Keep SQL portable (SQLite dev, MySQL/Postgres target).
- Sorting: document position (`text_start_offset` ASC, `id` tiebreak, stable pagination) per
  OQ-09 resolved.
- Locations are loaded per item (batched by type to avoid N+1).

---

## 4. Contracts & invariants

- Citation status is **derived, never stored**; one implementation only.
- Manual review sets `is_manual`/`reviewed_by`/`reviewed_at` and never bypasses this path.
- `selected_candidate_id` must belong to the edited finding.
- Citation pairing must stay within the same document.
- Foreign reference/citation/report access → `404`, never `403`.
- Enum values come from the Phase 01 enums; any new filter value requires `docs/API_SPEC.md` update.

---

## 5. Tests / acceptance criteria

| Test ID | Case | Expected |
|---|---|---|
| T-REF-01 | List with `status`/`has_doi` filters | filtered + paginated; invalid filter `422` |
| T-REF-02 | Reference detail | finding + candidates + locations + citations match spec |
| T-REF-03 | PATCH finding audit | `is_manual=true`, `reviewed_by`/`reviewed_at` set |
| T-REF-04 | PATCH invalid status | `422` |
| T-REF-05 | PATCH candidate from another finding | `422` |
| T-REF-06 | Finding change propagates | all paired citations' derived status change immediately |
| T-CIT-01 | List `status`/`reference_id` filters | derived-status filtering correct (incl. `hallucination`) |
| T-CIT-02 | Citation detail | locations match spec |
| T-CIT-03 | Pair unpaired citation (same document) | `200`; derived `valid`/`unreliable` per finding |
| T-CIT-04 | Pair with different-document reference | `422` with spec message |
| T-CIT-05 | Unpair | derived `hallucination` |
| T-CIT-06..09 | Derived statuses (valid/suspicious → valid; invalid/not_found → unreliable; pending → pending; unpaired → hallucination) | exact mapping |
| T-FIND-01 | Feed includes all problem types with correct ids | `ref-finding:` / `citation:` ids, correct `reference_id`/`citation_id` |
| T-FIND-02 | Severity mapping | high/medium/info as specified; `citation_unreliable` inherits |
| T-FIND-03 | `type`/`severity` filters | invalid `422`, valid filtered |
| T-FIND-04 | Locations present | page + bbox fields for viewer |
| T-OWN-05..07 | Foreign references/citations/findings and PATCHes | `404`, no existence disclosure |
| F-DERIV-01 | SQL scope ↔ PHP resolver agreement | all four statuses match on one fixture |

Exit: all rows above pass; manual review and citation pairing audit rules verified.

---

## 6. Decisions (resolved)

- **OQ-09** — the findings feed includes `reference_pending` (severity `info`) and is ordered by
  document position (`text_start_offset` ASC, id tiebreak). `reference_pending` is added to the
  `type` filter and needs an `docs/API_SPEC.md` note.
- **OQ-17** — manual review creates a finding when the reference exists and the document is not
  `processing`; `409` while the pipeline is running.
- **OQ-18** — IEEE ordinals resolve against bibliography order derived from `text_start_offset`
  ASC (nulls last, id tiebreak).

---

## 7. Risks

- APA/IEEE marker variety (multiple brackets, `et al.`, ranges, commas, non-ASCII authors) can
  cause false unpaired citations; keep the parser conservative, test a representative fixture set,
  and prefer leaving a citation unpaired over a wrong pairing.
- Derived-status SQL scope and PHP resolver can drift; the agreement test is mandatory.
- Findings feed union query portability across SQLite/MySQL; keep raw SQL minimal and tested on
  SQLite, with a note for the Postgres target.
- N+1 on locations/candidates in list endpoints; use eager loading/batch queries and assert query
  counts in tests.
