# Phase 04 — Crossref Verification, Candidate Ranking & Scoring

> **Status:** broad plan — **implemented**; see [`04-crossref-verification-detail.md`](04-crossref-verification-detail.md)
> for the executable plan (all recommendations/defaults adopted there). · **Depends on:** Phase 03 · **Unblocks:** Phase 05
> Canonical references: `docs/API_SPEC.md` §2.6/§10/§11, `docs/ARCHITECTURE.md` §7.1,
> `docs/PRODUCT_REQUIREMENTS.md` §6.3/§9, `docs/TEST_PLAN.md` T-REF-07/08 / T-SCORE / T-PIPE-03.

---

## 1. Objective

For every extracted reference, obtain Crossref metadata (by DOI or bibliographic search), rank
the candidates with a combined similarity score (Levenshtein + Jaro-Winkler + SBERT + exact
matching), and persist a `reference_findings` row plus ranked `reference_finding_candidates` that
explain the verdict. This implements product cases 1 and 2 (invalid/hallucinated DOI, valid
reference missing a DOI → suggested DOI).

---

## 2. Scope

**In:** Crossref HTTP client + DOI normalization + query building + result mapping; candidate
retrieval; pure scoring engine (string + author + semantic + metadata agreement); local-venue
heuristic; reason building; finding/candidate persistence; embedding step; configuration.

**Out:** citation resolution (Phase 05), review endpoints (Phase 05), report rendering (Phase 06),
inference service implementation (OQ-13), OpenAlex (explicitly excluded).

---

## 3. Deliverables

### 3.1 Crossref client — `app/Services/Crossref/`

| Artifact | Responsibility |
|---|---|
| `CrossrefClient` | `findByDoi(string $doi): ?CrossrefWorkData`; `searchBibliographic(ReferenceQuery): array<CrossrefWorkData>` |
| `DoiNormalizer` | strip `https://doi.org/`, `doi:` prefixes, URL-decode, lowercase, trim trailing punctuation; validate `10.\d{4,9}/\S+` shape |
| `CrossrefQueryBuilder` | builds `/works` query params: `query.bibliographic`, `query.author`, `rows`, `select`, `mailto` |
| `CrossrefResultMapper` | tolerant JSON → `CrossrefWorkData` mapping (title array, author list, `container-title`, issued date) |
| `CrossrefWorkData` | value object: `doi`, `title`, `authors` (array of names), `containerTitle`, `publicationYear`, `url`, `type` |
| `CrossrefUnavailableException` | connection error/timeout/5xx after retries (feeds the OQ-03 total-outage policy) |

Client behaviour:

- Laravel HTTP client with config base URL, connect/read timeouts, `mailto` (polite pool) and a
  descriptive `User-Agent`; no credentials (OQ-11 resolved: drop `CROSSREF_API_KEY`).
- Retry only transient failures (connection errors/5xx, bounded, exponential backoff); a `404`
  from `/works/{doi}` is a **result** (`null`), not an exception.
- Cache DOI lookups (`services.crossref.cache_ttl`, default 24 h) to avoid repeated calls on
  retry/duplicate references; do not cache bibliographic searches by default (keeps evaluation
  reproducible).
- One client, no business decisions: it returns metadata; Laravel decides verdicts.

### 3.2 Scoring engine — `app/Services/Scoring/`

Pure, unit-testable classes (no Eloquent/HTTP inside):

| Artifact | Responsibility |
|---|---|
| `StringSimilarity` | mb-safe normalized Levenshtein + Jaro-Winkler implementations (author `Koten` ↔ `Koton` must score high) |
| `AuthorMatcher` | parse reference + candidate author strings, order-insensitive best-pair Jaro-Winkler, handle `et al.` |
| `SemanticSimilarity` | cosine similarity over embeddings; handles empty/mismatched vectors |
| `ReferenceScorer` | combines signals into `ScoreBreakdown` (`title`, `authors`, `journal`, `year`, `doi`, `final`) |
| `LocalVenueDetector` | configurable heuristic for likely non-indexed/local venues |
| `MatchReasonBuilder` | builds the per-candidate `match_reason` and the finding `reason` |
| `ScoringConfig` | typed access to `config/scoring.php` |

Suggested combination (provisional, config-driven — OQ-12):

```text
signals   = title (SBERT semantic + normalized string), authors (Jaro-Winkler),
            journal (normalized Levenshtein), year (exact), doi (exact)
weights   = title 0.45, authors 0.25, journal 0.15, year 0.15     (per candidate)
final     = Σ weight_i × signal_i
doi match = short-circuit to a top-confidence candidate when DOI is present and resolves
```

Rules:

- SBERT is one signal only — never sufficient alone (`docs/ARCHITECTURE.md` §7.1).
- Degraded mode (OQ-04 resolved): if embeddings are unavailable, drop the semantic component and
  redistribute its weight across the string signals; record the degradation in `reason`.
- Thresholds configurable: `valid ≥ 0.85` (proposal), `suspicious ≥ 0.50` (provisional); document
  the values used for any evaluation run (Phase 07).

### 3.3 Verification pipeline steps — `app/Services/Analysis/Steps/`

- `ValidateReferencesStep` (`crossref_validation`) — per reference:
  - normalize DOI;
  - **DOI present:** `findByDoi`; 200 → candidate + metadata comparison; 404 → `invalid`
    (OQ-02 resolved, reason `"DOI tidak ditemukan di Crossref."`); a total Crossref outage fails
    the document, while per-reference transient failures degrade to `pending` (OQ-03 resolved);
  - **DOI present but metadata conflicts:** keep the DOI-resolved candidate for transparency and
    also run a bibliographic search to offer the correct publication as a candidate;
  - **no DOI:** bibliographic search → rank candidates → decision matrix below;
  - persist candidates + finding via `ReferenceFindingWriter`;
  - isolation: a single reference's transient failure must not abort the whole document
    (per-reference handling, T-PIPE-03).
- `EmbedReferencesStep` (`embedding`) — collects reference titles + candidate titles, calls
  `/v1/embeddings` in batches, computes cosine similarities for scoring.
  Transient artifacts use an in-memory `AnalysisContext` owned by `AnalysisPipeline`; embeddings
  are **not** persisted (no column exists in the canonical schema). Persisted outputs (candidates,
  findings) still go to the database.
- `ScoreReferencesStep` (`scoring`) — runs `ReferenceScorer` with the context, writes final
  `confidence`, `status`, `reason` and `selected_candidate_id`.

Decision matrix (single place — do not duplicate in the API layer):

| Situation | Finding status |
|---|---|
| DOI present, resolves, metadata agrees (score ≥ valid threshold) | `valid` |
| DOI present, resolves, metadata conflicts (score below valid / conflicting year) | `invalid` |
| DOI present, does not resolve (Crossref 404) | `invalid` (OQ-02 resolved) |
| No DOI, best candidate ≥ valid threshold | `valid` (+ suggested DOI = selected candidate's DOI) |
| No DOI, score between thresholds | `suspicious` |
| No DOI, no candidates at all | `not_found` |
| Low score but venue looks local/non-indexed | `suspicious` (not `not_found`; avoids false positives) |
| Crossref transient failure for this reference | `pending` with an explicit reason (OQ-03 resolved) |

### 3.4 Persistence — `app/Services/ReferenceFinding/ReferenceFindingWriter`

- Upsert one finding per reference (`researched_document_reference_id`; unique index per OQ-14
  resolved):
  set `researched_document_id`, `status`, `confidence`, `reason`, caretaker audit fields left
  untouched for automated runs (`is_manual=false`).
- Replace candidates transactionally: delete old rows, insert ranked candidates with
  `rank = 1..N` (unique per finding), then set `selected_candidate_id` (FK ordering:
  finding → candidates → selected candidate).
- Guarantee: rank ordering matches score ordering; every candidate stores the DOI that can be
  suggested to the user (FR-R7).

### 3.5 Configuration

- `config/scoring.php`: thresholds, weights, semantic toggle, year tolerance, local-venue keyword
  list.
- `config/services.php` `crossref` block (Phase 01) consumed here.
- `.env.example` placeholders for the optional overrides.

### 3.6 Test fixtures — `tests/Fixtures/crossref/`

Recorded/synthetic payloads for: DOI hit (metadata agrees), DOI hit (metadata conflict), DOI 404,
bibliographic search (multiple candidates), empty result, malformed/missing fields. These drive
both feature tests and the evaluation harness.

---

## 4. Contracts & invariants

- All Crossref traffic is outbound from Laravel queue workers; never from the frontend; no
  credentials in the browser (`docs/SECURITY.md` §1/§6).
- `reference_findings.status` uses only the canonical five values; `confidence` is `0.0000`–`1.0000`.
- Candidate `rank` is unique per finding and ordered by score; `selected_candidate_id` belongs to
  the finding (or is `null`).
- A suggested DOI exists on the selected candidate for the "valid without DOI" case (FR-R7).
- No OpenAlex; no Google Scholar.
- SBERT is a supporting signal only.

---

## 5. Tests / acceptance criteria

| Test ID | Case | Expected |
|---|---|---|
| T-SCORE-01 | Normalized Levenshtein on known pairs | expected similarity values |
| T-SCORE-02 | Jaro-Winkler `Koten` vs `Koton` | high similarity |
| T-SCORE-03 | SBERT unavailable | string signals used; degradation recorded; document still completes |
| T-SCORE-04 | Combined score ordering | higher-confidence candidate ranks first |
| T-SCORE-05 | Threshold boundary (≥ 0.85) | correct status transitions |
| T-SCORE-06 | Local-journal low score | `suspicious`, not `not_found` |
| T-SCORE-07 | Candidate ranks unique per finding | DB constraint holds under upsert |
| T-REF-07 | DOI resolves to a different publication | `invalid` + reason; conflicting fields named |
| T-REF-08 | Valid reference without DOI | top candidate carries the suggested DOI; status `valid` |
| F-XREF-01 | Crossref 404 for DOI | `invalid` + `"DOI tidak ditemukan di Crossref."` (OQ-02) |
| F-XREF-02 | Crossref outage (OQ-03 policy) | document fails safely **or** references `pending`, per decision |
| F-XREF-03 | Per-reference transient failure | other references still get verdicts (T-PIPE-03) |
| F-XREF-04 | DOI cache | second lookup does not hit HTTP (assert via `Http::fake` call count) |
| F-SCORE-01 | Reason strings | deterministic, human-readable, include component evidence |

Exit: `Http::fake()`-driven feature test walks a fixture document end-to-end to correct findings;
scoring unit tests cover boundary cases.

---

## 6. Decisions (resolved)

- **OQ-02** — a DOI present but not found in Crossref is `invalid` with
  `"DOI tidak ditemukan di Crossref."`; `not_found` only for no-DOI/no-candidate.
- **OQ-03** — total Crossref outage fails the document; per-reference transient failures become
  `pending` with an explicit reason.
- **OQ-04** — SBERT unavailability degrades to string signals and is recorded in `reason`.
- **OQ-11** — no `CROSSREF_API_KEY`; use `CROSSREF_MAILTO` + contact User-Agent.
- **OQ-12** — ship the provisional weights/thresholds in `config/scoring.php`; tune them in
  Phase 07 and record the config per evaluation run.
- **OQ-14** — add the unique index on `reference_findings.researched_document_reference_id` and
  update `docs/DB_SCHEMA.md`.

---

## 7. Risks

- Unicode names: PHP's native `levenshtein()` is byte-based; use mb-safe implementations and test
  with diacritics.
- Crossref metadata shapes vary (missing titles, `issued` absent); the mapper must be tolerant and
  the scorer must treat missing signals explicitly (not as zero silently).
- Too many Crossref calls for large documents; sequential processing + caching keeps v1 polite —
  document the scaling path (rate limiter/concurrency) in the detailed plan.
- Local-venue heuristic is fuzzy; keep it conservative and configurable to avoid false negatives
  (marking real local references as `not_found`).
- Weight/threshold tuning can invalidate existing findings; the evaluation harness records the
  config for reproducibility, and findings are recomputed per analysis run.
