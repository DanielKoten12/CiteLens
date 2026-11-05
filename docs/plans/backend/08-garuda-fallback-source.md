# Phase 08 — Garuda Fallback Metadata Source (Detailed Implementation Plan)

> **Status:** detailed plan — **T0–T2 implemented** (2026-11-05); T3+ pending
> **Parent:** none (new phase) · **Depends on:** Phase 04 (implemented), Phase 05 (implemented)
> **Unblocks:** OpenAlex as a second primary source (same seam, no schema change)
> Canonical references: `docs/API_SPEC.md` §2.6/§5/§10/§11, `docs/DB_SCHEMA.md`
> (`reference_finding_candidates`), `docs/ARCHITECTURE.md` §3/§7.1/§13, `docs/SECURITY.md` §1/§6,
> `docs/TEST_PLAN.md` §5, `AGENTS.md` §3/§7/§8/§10/§12/§14/§15.
> Task template: `docs/plans/backend/README.md` §1.4.

This document expands the "Indonesian fallback source" decision into executable tasks. It adds
**one outbound lookup path** to the verification pipeline and **one column** to the candidate table.
It does not touch extraction, scoring weights, citation resolution, reports or the public endpoint
shapes beyond the additive `source` field.

The design keeps four hard rules:

1. **Laravel owns metadata I/O.** The scraper is a queue-worker client next to `CrossrefClient`,
   not an inference endpoint and not a new service (`AGENTS.md` §3, §12).
2. **Garuda is a fallback, never a primary source.** It is queried only when the primary sources
   produced no plausible candidate for a reference.
3. **Failures surface.** Timeouts, 5xx, blocked responses and HTML changes raise
   `GarudaUnavailableException`; the first failure of a run fails the document, later ones mark
   that reference `pending` with an explicit reason. Only a page that says `Found 0 documents` is
   treated as a genuine empty result.
4. **No silent contract change.** The new `source` field is added to `docs/API_SPEC.md`,
   `docs/DB_SCHEMA.md` and the frontend type in the same change.

---

## 1. Objective

Raise recall for Indonesian references that Crossref cannot resolve — local journals, proceedings
and non-DOI publications — by querying the Garuda catalogue as a **last-resort bibliographic
source**, producing ordinary `reference_finding_candidates` rows that the existing scoring engine
ranks and the existing writer persists.

The user-visible outcome:

| Before | After |
|---|---|
| `"Tidak ada kandidat ditemukan di Crossref."` → `not_found` (severity `high`) | Candidate from Garuda, `valid`/`suspicious` with the Garuda record URL |
| Reference that only exists in Garuda is reported as unverifiable | Reference resolved to a real catalogue record with title/authors/journal/year |

---

## 2. Scope

**In:** `MetadataSource` seam for bibliographic search, `WorkMetadata` value object (rename of
`CrossrefWorkData`), Garuda HTTP client + HTML parser + result mapper, fallback gating, config/env,
throttle/cache, candidate `source` column + DTO/type updates, reason wording, fixtures and tests,
docs sync.

**Out:** OpenAlex implementation (uses the same seam, separate phase), RIS/BibTeX enrichment of
Garuda results (documented scaling path, §14.4), bulk Garuda harvesting/local index, DOI discovery
through Garuda (its DOI coverage is too poor — see §3), any frontend feature work beyond the
optional `source` display, and DOCX support (`AGENTS.md` §15).

---

## 3. Baseline (verified against the repository and the live site)

### 3.1 Repository

| Item | Value |
|---|---|
| Pipeline step | `App\Services\Analysis\Steps\ValidateReferencesStep` (`crossref_validation`) — the only place `CrossrefClient` is called |
| Verification artifact | `ReferenceVerification` (reference + `DoiLookup` + `list<CrossrefWorkData>` + `transientFailure`) |
| Writer | `ReferenceFindingWriter` is the single writer of findings/candidates |
| Scoring input | `ReferenceScorer::score(ScoringReference, list<CrossrefWorkData>, EmbeddingIndex)` |
| Candidate columns | `rank`, `confidence`, `doi`, `title`, `authors`, `publication_name`, `publication_year`, `url`, `match_reason` (no source column today) |
| API DTO | `App\Data\ReferenceFinding\ReferenceFindingCandidatePreviewData` |
| Frontend type | `frontend/src/types/api.ts` → `interface Candidate` |
| Config | `config/services.php` (`crossref`, `inference`, `gotenberg`), `config/scoring.php` (`thresholds`, `weights`, `semantic`, `local_venue_keywords`) |
| HTTP in tests | No global `Http::preventStrayRequests()`; every existing test fakes its own calls |
| Progress band | `config/analysis.php` → `crossref_validation` 35–60; do not renumber |
| PHP extensions | `ext-dom` + `libxml` available (PHP 8.5 CLI/image); `symfony/dom-crawler` is **not** a runtime dependency |

### 3.2 Garuda (probed live while writing this plan)

| Probe | Result |
|---|---|
| `GET /documents?q=<title>` | `200 text/html`, 10 result cards/page, `?page=N` pagination |
| `GET /documents/detail/{id}` | journal, vol/issue, title, authors, publisher, year, abstract |
| `GET /citation/site/RIS/{id}`, `/citation/site/bib/{id}` | structured RIS / BibTeX export |
| `GET /robots.txt`, `/oai?verb=Identify`, `/api` | no robots policy, no OAI-PMH, no JSON API |
| Stack | Apache + Fat-Free Framework (`X-Powered-By: Fat-Free Framework`) |
| Search card markup | `div.article-item` → `a.title-article[href=/documents/detail/{id}]`, `a.author-article`, `xmp.subtitle-article` (journal + vol/issue/year, then publisher) |
| Zero-result page | `200` with the marker `Found 0 documents` (a non-empty result says `Found 425 documents`) — the reliable "no results vs broke" signal |
| DOI coverage of Garuda records | ~1/10 sampled records displayed a DOI |
| Crossref overlap for those same records | ~7/10 found in Crossref by title search |

Consequences baked into this plan:

- Garuda must **not** be used to *suggest* a DOI; it is an existence/provenance check.
- A Garuda candidate legitimately has `doi = null` while still producing a `valid` finding. The
  API already expresses this (`selected_candidate_id` → candidate with `doi: null`); the frontend
  must not assume "valid ⇒ DOI present".
- Search relevance is good enough for a single title-only query (top-1 correct in probes), so the
  plan uses **one HTTP request per gated reference**, not one per candidate.

---

## 4. Decisions

| ID | Question | Decision | Rationale |
|---|---|---|---|
| **D-08-01** | Where does the scraper live? | `backend/app/Services/Garuda/`, called from `ValidateReferencesStep` in the queue worker | Matches `AGENTS.md` §3/§12 (Laravel owns external metadata + ranking). No new deployable, no inference contract change, hermetic `Http::fake()` tests. |
| **D-08-02** | Primary vs fallback | Garuda runs **only if** the primary sources produced no candidate with title similarity ≥ `scoring.fallback.title_threshold` | Keeps external load bounded and avoids duplicate/competing candidates for records Crossref already resolves. |
| **D-08-03** | Failure policy | **Failures are surfaced, never silently converted to zero candidates.** `GarudaClient` throws `GarudaUnavailableException`; the step rethrows on the first failure of the run (→ document `failed`), and degrades later per-reference failures to `pending` with an explicit reason | Matches the Crossref OQ-03 shape and the user decision: a broken/blocked source must be visible, not look like "not indexed". A valid `Found 0 documents` page is still a successful exchange (empty result), not an error. |
| **D-08-04** | Source abstraction | Add `App\Contracts\Metadata\BibliographicSource` (`name()`, `search(ReferenceQuery): list<WorkMetadata>`). `CrossrefClient` and `GarudaClient` implement it; the step iterates a config-ordered source list | Three sources are coming (Crossref, OpenAlex, Garuda). A one-method seam makes OpenAlex one class + one config entry. DOI lookup stays Crossref-specific and outside the seam. |
| **D-08-05** | Value-object naming | Rename `CrossrefWorkData` → `App\Services\Metadata\WorkMetadata`, add `MetadataSource $source` | With three producers, `CrossrefWorkData` in `Services/Garuda/` reads wrong and invites per-source copies. Mechanical rename, no behavior change, done as its own commit. |
| **D-08-06** | Candidate provenance | New column `reference_finding_candidates.source` (`crossref` default), enum `App\Enums\MetadataSource` | `url` alone is not provenance (DOIs point to doi.org, Garuda rows to garuda.*). Manual review needs to know which catalogue was matched. |
| **D-08-07** | Step naming | Keep `analysis_step = crossref_validation` | Renaming the enum value is a breaking change for status polling and the progress UI for zero user value. Document the widened meaning. |
| **D-08-08** | Parse source | Search page cards, **not** the detail page; no RIS enrichment in v1 | 1 request per reference. Cards already carry title, all authors, journal string, year, publisher. RIS = 1 extra request per candidate for marginal accuracy. |
| **D-08-09** | Query shape | Single `q` = cleaned title, max 180 chars, no quotes; no author/year in the query | Probes: quoted and unquoted full titles both put the correct record first; author/year reduce recall on Garuda's simple matcher. |
| **D-08-10** | Politeness / reliability | Every knob is config + env (`GARUDA_*`): interval, timeout, retries/backoff, rows, cache TTLs, response cap, redirects, per-document request budget, UA. Defaults: 1 req/s, 30-day cache, 1-day negative cache, 1 retry | Government site with no published API. Operators must tune load without a deploy (dev vs demo vs production), and a single transient blip should not fail a document now that failures are fatal. |
| **D-08-11** | Empty vs broken | Only a page carrying `Found 0 documents` counts as "no results" (cached 1 day). Any `4xx/5xx`, missing marker, or `N > 0` with zero parsed cards is a **failure** | This is the difference between "not indexed" and "the site changed/blocked us" — the exact case that used to be reported as a silent miss. |
| **D-08-12** | `valid` without DOI | Allow it; do not weaken thresholds for Garuda | Thresholds stay source-agnostic. A `valid` finding whose selected candidate has `doi: null` is honest ("exists in the national catalogue, no DOI registered"). |

### 4.1 Open questions resolved with defaults

| # | Question | Default |
|---|---|---|
| Q1 | Should Garuda rows be queryable/filterable by `source` in the API? | No. `source` is returned, not filtered. |
| Q2 | Enrich the top Garuda hit from `/citation/site/RIS/{id}`? | No in v1; only if the card lacks year **and** authors (the client does it at most once per reference). |
| Q3 | Run Garuda when a DOI resolved but metadata conflicts? | Yes — the gate is output-based, not DOI-based: a conflicting DOI produces no strong candidate, so Garuda may supply the correct publication. |
| Q4 | Should `local_venue_keywords` be seeded now? | Yes, but only with **non-indexed venue markers** (`prosiding`, `seminar nasional`, `pengabdian masyarakat`, `skripsi`, `tesis`). Do **not** seed `jurnal`: it matches nearly every Indonesian journal name and would flip genuine `not_found` results to `suspicious` across the board. |
| Q5 | First Garuda failure fails the whole document — isn't that too broad? | **Confirmed** (user): hybrid, mirroring Crossref OQ-03. First failure of a run is fatal; later failures degrade to `pending` with a Garuda-specific reason. One bounded retry mitigates flapping; `/documents/{id}/retry` exists for `failed`. |
| Q6 | Per-document request budget | **Confirmed** (user): `GARUDA_MAX_REQUESTS_PER_DOCUMENT=500` (0 = unlimited). On exhaustion the remaining gated references become `pending` with `"Batas permintaan Garuda tercapai; coba lagi nanti."` — explicit, not silent. See §9.5 for the timeout interaction. |

---

## 5. Execution model

### 5.1 Task order

| Task | Deliverable | Size | Depends on | Suggested commit |
|---|---|---|---|---|
| **T0** | Preflight, `Http::preventStrayRequests()` in the test base | S | — | `test(backend): prevent stray http requests in tests` |
| **T1** | `WorkMetadata` rename + `MetadataSource` enum + `BibliographicSource` contract | M | T0 | `refactor(backend): generalize work metadata across metadata sources` |
| **T2** | Schema: `reference_finding_candidates.source` + model/factory/DTO/frontend type | M | T1 | `feat(backend): record candidate metadata source` |
| **T3** | `GarudaClient` + `GarudaUnavailableException` + parser + mapper + config | L | T2 | `feat(backend): add garuda search client and parser` |
| **T4** | `ValidateReferencesStep` fallback wiring + source loop + reason wording | M | T3 | `feat(backend): fall back to garuda for unresolved references` |
| **T5** | Fixtures, fake, step/e2e tests, acceptance matrix | M | T4 | `test(backend): cover garuda fallback path` |
| **T6** | Docs + contract sync, final validation | S | T5 | `docs: document garuda fallback source` |

### 5.2 Per-task loop

Follow `docs/plans/backend/README.md` §1.4. For every task: implement → `vendor/bin/pest` (targeted)
→ `vendor/bin/pint --dirty` → full `php artisan test` before the commit.

### 5.3 Exit criteria

1. A document with an Indonesian no-DOI reference resolves to a `valid`/`suspicious` finding whose
   selected candidate is a Garuda record — with `source = garuda`.
2. A valid `Found 0 documents` page yields `not_found` and a `completed` document (empty ≠ broken).
3. A Garuda outage (500/timeout/garbage markup) fails the document with
   `"Validasi Garuda tidak tersedia. Coba lagi nanti."`; a failure **after** one successful Garuda
   exchange only marks that reference `pending` with the Garuda-specific reason.
4. Garuda is queried at most `1 + (0|1)` times per reference, never when a primary candidate
   scores ≥ `scoring.fallback.title_threshold`, and never more than
   `GARUDA_MAX_REQUESTS_PER_DOCUMENT` times per document.
5. `GARUDA_ENABLED=false` restores Phase 04 behavior exactly (zero Garuda calls, no new failures).
6. `php artisan test` passes with `Http::preventStrayRequests()` active.

---

## 6. T0 — Preflight

1. `backend/tests/TestCase.php`: add `Http::preventStrayRequests()` to `setUp()` (all existing tests
   already fake their own requests; any that don't will fail loudly here — fix them by faking, not
   by removing the guard). **Done** — full suite passes with the guard active.
2. Confirm `php -m` has `dom` (already true locally and in the image). **Done**.
3. `backend/phpunit.xml`: add `GARUDA_ENABLED=true` — **deferred to T3**, when the config key it
   drives actually exists (no dead env vars).

---

## 7. T1 — `WorkMetadata`, `MetadataSource`, `BibliographicSource`

### 7.1 Rename `CrossrefWorkData` → `WorkMetadata`

Move to `app/Services/Metadata/WorkMetadata.php`, keep the shape, add `source`:

```php
final class WorkMetadata
{
    /** @param list<string> $authors */
    public function __construct(
        public readonly ?string $doi,
        public readonly ?string $title,
        public readonly array $authors = [],
        public readonly ?string $containerTitle = null,
        public readonly ?int $publicationYear = null,
        public readonly ?string $url = null,
        public readonly ?string $type = null,
        public readonly MetadataSource $source = MetadataSource::Crossref,
    ) {}
}
```

Update the 14 referencing files (verified list): `CrossrefClient`, `CrossrefResultMapper`,
`ReferenceVerification`, `ValidateReferencesStep`, `ReferenceScorer`, `ScoredCandidate`, plus the
seven test files. No behavior change; run the full suite before committing.

`CrossrefResultMapper` sets `source: MetadataSource::Crossref` explicitly (do not rely on the
default in the mapper — the default exists only for backwards-compatible test constructors).

### 7.2 `App\Enums\MetadataSource`

```php
enum MetadataSource: string
{
    case Crossref = 'crossref';
    case OpenAlex = 'openalex';
    case Garuda = 'garuda';
}
```

Add a pinned-value test in `tests/Unit/EnumTest.php` (house convention for API-visible enums).

### 7.3 `App\Contracts\Metadata\BibliographicSource`

```php
interface BibliographicSource
{
    public function source(): MetadataSource;

    /** @return list<WorkMetadata> */
    public function search(ReferenceQuery $query): array;
}
```

- `CrossrefClient` implements it: its existing `searchBibliographic()` is renamed to `search()`.
  `findByDoi()` stays Crossref-only and is not part of the interface.
- `GarudaClient` implements it.
- `ReferenceQuery` moves from `Services/Crossref` to `Services/Metadata` (it is no longer
  Crossref-specific) and gains `public readonly ?string $title = null` as the **last** constructor
  argument (built from `$reference->title`), so existing positional calls in tests keep working.
  Each source chooses its own query fields; `CrossrefQueryBuilder` keeps using `bibliographic` —
  no Crossref behavior change.

### 7.4 Provider wiring (lands with T4 — no consumer before then)

`AppServiceProvider` (or a new `MetadataServiceProvider`) exposes the ordered primary-source list:

```php
$this->app->tag([CrossrefClient::class], 'metadata.primary'); // OpenAlex joins this list later
```

The step resolves primary sources via `config('analysis.metadata.primary_sources')`
(default `['crossref']`) mapped to container bindings. Keeping the order in config lets OpenAlex
lands without a code change in the step.

---

## 8. T2 — `source` column and contract

### 8.1 Migration

New migration (never edit `*_create_initial_tables.php`):

```php
Schema::table('reference_finding_candidates', function (Blueprint $table): void {
    $table->string('source', 20)->default('crossref')->after('confidence');
});
```

Portable on SQLite/MySQL/Postgres (string + default). `after()` is ignored on SQLite, harmless.

### 8.2 Model / factory / writer

- `ReferenceFindingCandidate`: add `'source'` to `$fillable`; cast `'source' => MetadataSource::class`.
- `ReferenceFindingCandidateFactory`: `'source' => MetadataSource::Crossref->value` (use the enum
  value, not a bare string).
- `ReferenceFindingWriter`: persist `'source' => $candidate->work->source->value`.

### 8.3 API contract

`docs/API_SPEC.md` §5 reference detail, candidate object:

```json
{ "source": "crossref", "...": "..." }
```

Add `source` to the candidate field table and a note: `source` ∈ `crossref | openalex | garuda`;
`doi` may be `null` for a `valid` finding when the match came from `garuda`. Mirror the change in
`docs/API_SPEC_ID.md` (translation; English stays canonical).

### 8.4 DTO + frontend type

- `ReferenceFindingCandidatePreviewData`: `public MetadataSource $source` + `fromModel()` mapping.
  (spatie/laravel-data serializes a backed enum to its value.)
- `frontend/src/types/api.ts` → `Candidate`: add `source: "crossref" | "openalex" | "garuda";` and
  relax the fields that are already nullable in the API (`title`, `authors`, `publication_name`,
  `publication_year`, `url` → `| null`). This is a type-only fix; no view changes.

### 8.5 Tests

- `DomainSchemaTest`: column exists with default `'crossref'`.
- `DomainModelTest`: enum cast round-trip.
- `ReferenceFindingWriterTest`: persists the source for both a Crossref and a Garuda candidate.

---

## 9. T3 — Garuda client and parser

### 9.1 Input — `ReferenceQuery` (no separate value object)

Garuda consumes the same `ReferenceQuery` as Crossref; only `title` is used (D-08-09). The client
trims it to `services.garuda.query_max_length` (180) before building the query string. The minimum
length check lives in the step gate (`mb_strlen($reference->title) >= 8`), not in a wrapper class —
one less object for one string.

### 9.2 `GarudaSearchParser`

`DOMDocument` + `DOMXPath`; no new dependency.

```php
/** @return list<array{id: string, title: string, authors: list<string>, journal: ?string, publisher: ?string, year: ?int}> */
public function parse(string $html): array
```

Rules:

- `libxml_use_internal_errors(true)`; load with `LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING`;
  **never** `LIBXML_NOENT`. Restore the previous `libxml_use_internal_errors` state in `finally`.
- Encoding: prepend `<?xml encoding="UTF-8">` before `loadHTML()` (the live pages are UTF-8 but
  `DOMDocument` defaults to ISO-8859-1 without a meta hint), then strip the processing instruction.
- Iterate `//div[contains(concat(' ', normalize-space(@class), ' '), ' article-item ')]`.
- Title: `a.title-article` → `textContent`, collapse whitespace; id via
  `preg_match('#/documents/detail/(\d+)#', $href)`.
- Authors: each `a.author-article` → `textContent`, trimmed, empty filtered.
- Journal: first `xmp.subtitle-article` that is not `Publisher :`.
- Publisher: the `xmp.subtitle-article` following an `<i>Publisher :</i>`, or `null`.
- Year: `preg_match('/\((19|20)\d{2}\)/', $journal)`; fallback: last standalone 4-digit year in the
  journal string. Out-of-range years become `null` (`config/analysis.extraction.max_year`).
- Skip cards without an id or a non-empty title.
- Return at most `services.garuda.rows` (default 3) **after** filtering.

Robustness contract: an unknown/changed markup yields `[]`, never an exception. The parser is the
only class that knows Garuda's HTML; everything downstream sees `WorkMetadata`.

### 9.3 `GarudaResultMapper`

Card → `WorkMetadata`:

- `doi: null`, `type: 'journal-article'`, `source: MetadataSource::Garuda`,
  `url: {base}/documents/detail/{id}`, `containerTitle: journal`, `publicationYear: year`.
- **Title de-duplication:** Garuda repeats titles as `"X: X"` (or `"X X"`). Split on the first
  `: ` when both halves have Jaro-Winkler ≥ 0.95; keep the first half. Use the existing
  `App\Services\Scoring\StringSimilarity` (it is pure and already injected elsewhere) — do not
  re-implement similarity here.
- Skip the card when the title is empty after cleanup.

### 9.4 `GarudaClient implements BibliographicSource`

```php
final class GarudaClient implements BibliographicSource
{
    public function source(): MetadataSource { return MetadataSource::Garuda; }

    /** @return list<WorkMetadata> */
    public function search(ReferenceQuery $query): array;
}
```

Behavior:

| Concern | Implementation |
|---|---|
| Disabled | `enabled=false` → `[]` without any HTTP |
| Transport | `Http::baseUrl(config('services.garuda.base_url'))->timeout()->connectTimeout()->withUserAgent()->accept('text/html')` |
| Path | `GET /documents` with `['q' => $query->title ?? '']` |
| Redirects | `->withOptions(['allow_redirects' => ['max' => config('services.garuda.redirect_max', 3)]])` (live site 302s `/documents/` → `/documents`) |
| Retries | bounded: `GARUDA_RETRIES` (default 1) on connection errors/5xx only, `GARUDA_RETRY_BACKOFF_MS` (default 200), exponential |
| Response cap | `strlen($body) > services.garuda.max_response_bytes` (2 MB) → throw `unexpectedFormat()` |
| Content type | not `text/html` → throw `unexpectedFormat()` |
| Status | `>=400` → throw `unexpectedResponse()` (no `404`-as-empty: Garuda returns `200` for zero results) |
| Throttle | `Cache::lock('garuda:throttle', 10)->block(10, …)` around the request, `usleep` to honor `min_interval_ms` since `garuda:last_request_at` |
| Empty results | parse `Found (\d[\d.,]*) documents`; `N == 0` → `[]` (cached, D-08-11) |
| Format guard | marker missing, or `N > 0` while zero cards parsed → throw `unexpectedFormat()` — this is exactly the "markup changed, don't report a false miss" guard |
| Cache | `garuda:search:{sha1(query)}`, TTL `cache_ttl` (30 d); **empty** results cached with `negative_cache_ttl` (1 d). Errors are never cached |
| Budget | the **step** counts gated lookups per document (cache hits included, conservative) and stops calling after `max_requests_per_document` |
| Logging | `Log::warning('Garuda lookup failed.', ['document_id' => …, 'reference_id' => …, 'status' => …, 'reason' => …])` — no HTML, no reference text |
| Exceptions | `GarudaUnavailableException::serviceUnavailable()`, `::unexpectedResponse(int)`, `::unexpectedFormat(string)` — the only exceptions the class throws |

Return type is `list<WorkMetadata>` so the step can concatenate with primary results.

Catches are limited to Guzzle/Laravel transport exceptions (`ConnectionException`, `RequestException`)
and are rethrown as `GarudaUnavailableException`; there is **no** blanket `catch (Throwable) → []`.

### 9.5 Config (`config/services.php`, `.env.example`)

```php
'garuda' => [
    'enabled' => (bool) env('GARUDA_ENABLED', true),
    'base_url' => env('GARUDA_BASE_URL', 'https://garuda.kemdiktisaintek.go.id'),
    'timeout' => (int) env('GARUDA_TIMEOUT', 10),
    'connect_timeout' => (int) env('GARUDA_CONNECT_TIMEOUT', 5),
    'retries' => (int) env('GARUDA_RETRIES', 1),
    'retry_backoff_ms' => (int) env('GARUDA_RETRY_BACKOFF_MS', 200),
    'rows' => (int) env('GARUDA_ROWS', 3),
    'query_max_length' => (int) env('GARUDA_QUERY_MAX_LENGTH', 180),
    'cache_ttl' => (int) env('GARUDA_CACHE_TTL', 2592000),
    'negative_cache_ttl' => (int) env('GARUDA_NEGATIVE_CACHE_TTL', 86400),
    'min_interval_ms' => (int) env('GARUDA_MIN_INTERVAL_MS', 1000),
    'redirect_max' => (int) env('GARUDA_REDIRECT_MAX', 3),
    'max_requests_per_document' => (int) env('GARUDA_MAX_REQUESTS_PER_DOCUMENT', 500),
    'max_response_bytes' => (int) env('GARUDA_MAX_RESPONSE_BYTES', 2097152),
    'user_agent' => env('GARUDA_USER_AGENT'),
],
```

Default UA: `DafpusCek/1.0 (+mailto:{CROSSREF_MAILTO})` — reuse the existing contact variable so
there is one place to configure the contact address. If `CROSSREF_MAILTO` is empty, fall back to
`DafpusCek/1.0`.

**Budget vs job timeout.** `ANALYSIS_TIMEOUT` is 900 s (`config/analysis.php`). At the default
`GARUDA_MIN_INTERVAL_MS=1000`, 500 lookups are ~8.3 min of throttled wall time on their own, on top
of Crossref search, extraction and scoring — a pathological document (hundreds of unresolved
references) can approach or exceed the job timeout. 500 is a safety ceiling, not an expected value
(typical documents gate 0–10 references because most resolve in Crossref), but keep
`MAX_REQUESTS_PER_DOCUMENT × MIN_INTERVAL_MS` comfortably below `ANALYSIS_TIMEOUT`; the runbook must
say so. Do not lower the throttle to compensate.

`config/scoring.php`:

```php
'fallback' => [
    'title_threshold' => (float) env('SCORING_FALLBACK_TITLE_THRESHOLD', 0.85),
],
```

`ScoringConfig` gains `fallbackTitleThreshold(): float` through its existing `threshold()` helper
(same clamping/fallback semantics), and its class docblock lists the new key.

`config/analysis.php`:

```php
'metadata' => [
    'primary_sources' => ['crossref'], // openalex joins here
],
```

`.env.example` gets the `GARUDA_*` block with placeholders and a comment that the source is only
consulted as a fallback and needs outbound HTTPS to `garuda.kemdiktisaintek.go.id`.

---

## 10. T4 — Fallback wiring in `ValidateReferencesStep`

Current shape: DOI lookup → Crossref search → `merge()` → push `ReferenceVerification`.
New shape (network I/O only; persistence stays in `ScoreReferencesStep`):

```text
1. DOI lookup (Crossref, unchanged)
2. foreach primary source (Crossref now, OpenAlex later):
       search → merge (same DOI/title de-dupe)
3. gate: best title similarity over $candidates < scoring.fallback.title_threshold
       AND source enabled AND mb_strlen((string) $reference->title) >= 8
   → one Garuda search → append (then merge)
4. push ReferenceVerification (transientFailure stays false — that flag is Crossref-only)
```

Failure handling inside the loop (local counters in `handle()`, never instance state):

```text
garudaSucceeded = false
garudaLookups   = 0

try {
    $results = $garuda->search($query);
    garudaSucceeded = true;                       // any completed exchange, including Found 0
} catch (GarudaUnavailableException $e) {
    if (! $garudaSucceeded) { throw $e; }         // first failure of the run → document failed
    $verifications[] = pending(GARUDA_TRANSIENT); // later failure → explicit unknown for this reference
    continue;
}
```

To carry the reason, `ReferenceVerification` gains `public readonly ?string $pendingReason = null`;
`VerdictDecider` uses `$pendingReason ?? MatchReasonBuilder::TRANSIENT` when `transientFailure` is
true. Garuda paths set `transientFailure: true` **plus** `pendingReason` (`GARUDA_TRANSIENT` or
`GARUDA_BUDGET`). Existing Crossref call sites are unchanged (they pass `null`).

Gate helper (private method on the step; the step is already the tested seam). New constructor
dependencies: `GarudaClient`, `StringSimilarity` and `ScoringConfig` (or read
`config('scoring.fallback.title_threshold')` directly; prefer the injected config for consistency
with the scorer):

```php
private function needsFallback(ScoringReference $reference, array $candidates): bool
{
    $title = trim((string) $reference->title);

    if (mb_strlen($title) < 8) {
        return false; // Garuda needs >= 4 chars; below 8 there is no signal anyway.
    }

    if ($candidates === []) {
        return true;
    }

    $threshold = $this->config->fallbackTitleThreshold();

    foreach ($candidates as $candidate) {
        if (($this->strings->levenshtein($title, $candidate->title) ?? 0.0) >= $threshold) {
            return false;
        }
    }

    return true;
}
```

Rules:

- **No `transientFailure` flag.** That flag means "Crossref did not answer"; Garuda has its own
  `pending` path with `MatchReasonBuilder::GARUDA_TRANSIENT`.
- **First failure is fatal**, later failures degrade to `pending` (D-08-03). The first success is
  any completed exchange, including a valid `Found 0 documents` page.
- **Budget** (Q6): after `GARUDA_MAX_REQUESTS_PER_DOCUMENT` gated lookups, stop calling Garuda and
  mark the remaining gated references `pending` with `GARUDA_BUDGET` — never `not_found`.
- **Query once per reference**, never per candidate.
- **Merge after appending**, so a Garuda record that duplicates a weak Crossref hit is dropped by
  the existing title de-dupe.
- **Progress unchanged**: the loop already calls `progress->report()` after each reference.
- Cache the gate decision? No — it is a pure in-memory comparison.

### 10.1 Reason wording (`MatchReasonBuilder`)

Keep reasons source-agnostic where the source is not a fact about the reference:

| Constant | Change |
|---|---|
| `NOT_FOUND` | `'Tidak ada kandidat ditemukan di Crossref.'` → `'Tidak ada kandidat ditemukan.'` (Garuda may have been consulted) |
| `LOCAL_VENUE` | `'Sumber tidak terindeks Crossref; perlu pemeriksaan manual.'` → `'Sumber tidak terindeks Crossref/Garuda; perlu pemeriksaan manual.'` |
| `GARUDA_TRANSIENT` | **new** — `'Validasi Garuda gagal sementara. Coba lagi nanti.'` |
| `GARUDA_BUDGET` | **new** — `'Batas permintaan Garuda tercapai; coba lagi nanti.'` |
| `DOI_NOT_FOUND`, `MALFORMED_DOI`, `TRANSIENT`, `DOI_MATCH`, `SUSPICIOUS` | unchanged |
| `forCandidate()` | when `$work->source !== MetadataSource::Crossref`, prefix the evidence with the catalogue name, e.g. `'Garuda: kemiripan judul 0.93; …'` |

`forCandidate()` needs the source; pass it in from `ReferenceScorer` (which already has the
`WorkMetadata`). Update the three tests/factories that assert the old strings
(`ScoreReferencesStepTest`, `ReferenceFindingFactory`, `ReferenceScorerTest`).

### 10.2 `local_venue_keywords` seed

The detector is currently a no-op (`'local_venue_keywords' => []`). Seed a conservative Indonesian
list so a low-score Garuda/Crossref miss is classified `suspicious` instead of `not_found`:

```php
'local_venue_keywords' => ['prosiding', 'seminar nasional', 'pengabdian masyarakat', 'skripsi', 'tesis'],
```

Justification: these mark venue types that rarely register DOIs. The detector only softens
`not_found` → `suspicious`; it never invents a match. Deliberately **exclude `jurnal`** — it appears
in almost every Indonesian journal title and would weaken every genuine `not_found` verdict.

---

## 11. T5 — Fixtures, fake, tests

### 11.1 Fixtures (`backend/tests/Fixtures/garuda/`)

| File | Purpose |
|---|---|
| `search.html` | Trimmed real response for one Indonesian query (10 cards). Keep the outer page shell so encoding/whitespace edge cases stay realistic. |
| `search-empty.html` | Valid page with `Found 0 documents` and zero `.article-item` — must parse to `[]`, not to an error. |
| `search-changed.html` | Valid HTML with the result marker and card classes renamed — must raise `unexpectedFormat()`. |
| `search-ris.html` | Only if Q2 enrichment is implemented; otherwise omit. |

Sanitize fixtures: no local paths, no personal data beyond the published article metadata.

### 11.2 Test fakes

`Tests\Support\GarudaFake`:

```php
GarudaFake::empty();                      // GET /documents → valid "Found 0 documents" page
GarudaFake::malformed();                  // 200 with an unrecognizable page
GarudaFake::fromFixture('search.html');   // 200 text/html
GarudaFake::unavailable();                // 500
GarudaFake::timeout();                    // ConnectionException
```

`AnalysisHarness::document()` (or `fullSteps()`) installs `GarudaFake::empty()` by default so no
pipeline test can ever reach the network once T0 lands.

`Http::fake()` **appends** stub callbacks (verified in `Illuminate\Http\Client\Factory::stubUrl`),
so a later test-specific core fake does not remove the harness stub. Install the Garuda stub first
and avoid `Http::fake(['*' => …])` wildcards in tests that need Garuda to return a fixture.

### 11.3 Test matrix

| Test | Asserts |
|---|---|
| `GarudaSearchParserTest` (unit) | 10 cards parsed; authors/journal/year/publisher extracted; UTF-8 preserved; `Found 425 documents` marker read; `search-empty.html` → `[]`; `search-changed.html` → `GarudaResponseFormatException`; no libxml warnings leak |
| `GarudaClientTest` (feature) | second identical search hits cache (one HTTP request); disabled → zero requests; 500/timeout/non-HTML/oversized/missing marker → throws `GarudaUnavailableException`; one retry on 5xx then throw; UA and `q` sent |
| `GarudaResultMapperTest` (unit) | duplicate-subtitle cleanup; `source = garuda`; `doi = null`; URL built from base |
| `ValidateReferencesStepTest` | Garuda not called when a Crossref candidate ≥ threshold; called when none; not called for a short/empty title; first Garuda failure fails the run; failure after one success → that reference `pending`; budget exhaustion → `pending`/`GARUDA_BUDGET`, not `not_found` |
| `AnalysisPipelineTest` / `AnalysisFailureHandlerTest` | `GarudaUnavailableException` → document `failed`, safe message, no stack/URL leak |
| `ScoreReferencesStepTest` | Garuda candidate persisted with `source = garuda`; verdict `valid` at ≥ threshold; `not_found` reason no longer says "Crossref" |
| `ReferenceEndpointTest` | candidate payload includes `source`; null DOI candidate serializes as `null` |
| `AnalysisEndToEndTest` | (a) Indonesian no-DOI reference → completed, `valid`, `source = garuda`; (b) Garuda 500 on first gated reference → `failed`; (c) valid empty page → completed + `not_found` |
| `EnumTest` | `MetadataSource` values pinned |

Acceptance rows: T-REF-10 — "reference not indexed in Crossref resolves via Garuda fallback";
T-REF-11 — "Garuda outage fails the document with a safe message"; T-REF-12 — "`Found 0 documents`
is a miss, not a failure". Add all three to `docs/TEST_PLAN.md`.

---

## 12. T6 — Documentation and final validation

1. `docs/API_SPEC.md` §5/§10 — `source` field, footnote that `valid` does not imply a DOI.
2. `AnalysisFailureHandler` — new branch: `GarudaUnavailableException` →
   `'Validasi Garuda tidak tersedia. Coba lagi nanti.'` (`docs/SECURITY.md` §3 non-disclosure).
3. `docs/API_SPEC_ID.md` — mirror the translation.
4. `docs/DB_SCHEMA.md` — first DBML block, `reference_finding_candidates.source`; regenerate
   `DB_SCHEMA.png` if the repo workflow does (otherwise note it as stale).
5. `docs/ARCHITECTURE.md` §3/§7.1/§13 — source list, fallback order, failure policy.
6. `docs/SECURITY.md` — outbound egress table gains `garuda.kemdiktisaintek.go.id`; note the 2 MB
   cap, no credentials, no frontend access, metadata-only (no PDF downloads).
7. `docs/TEST_PLAN.md §5` — T-REF-10/11/12, including the outage and `Found 0` cases.
8. `AGENTS.md` §11 (external services) and §14 (status) — Garuda as optional fallback; keep the
   existing "do not add Google Scholar" rule explicit.
9. `docs/plans/backend/README.md` — phase map row (this file), status line.

Validation: `vendor/bin/pint --dirty`, `php artisan test`, plus one manual run against live Garuda
with a real document (log the query count and elapsed time for a 30-reference PDF).

---

## 13. Files touched

**New**

```text
backend/app/Contracts/Metadata/BibliographicSource.php
backend/app/Enums/MetadataSource.php
backend/app/Exceptions/GarudaUnavailableException.php
backend/app/Services/Metadata/WorkMetadata.php            (moved from Services/Crossref)
backend/app/Services/Garuda/GarudaClient.php
backend/app/Services/Garuda/GarudaSearchParser.php
backend/app/Services/Garuda/GarudaResultMapper.php
backend/database/migrations/*_add_source_to_reference_finding_candidates.php
backend/tests/Feature/GarudaClientTest.php
backend/tests/Unit/GarudaSearchParserTest.php
backend/tests/Unit/GarudaResultMapperTest.php
backend/tests/Support/GarudaFake.php
backend/tests/Fixtures/garuda/{search,search-empty,search-changed}.html
docs/plans/backend/08-garuda-fallback-source.md
```

**Modified**

```text
backend/app/Services/Crossref/CrossrefClient.php          (+ implements BibliographicSource)
backend/app/Services/Crossref/ReferenceQuery.php          (+ title)
backend/app/Services/Analysis/Steps/ValidateReferencesStep.php
backend/app/Services/Analysis/ReferenceVerification.php   (type rename)
backend/app/Services/Scoring/{ReferenceScorer,ScoredCandidate,MatchReasonBuilder}.php
backend/app/Services/ReferenceFinding/ReferenceFindingWriter.php
backend/app/Models/ReferenceFindingCandidate.php
backend/database/factories/ReferenceFindingCandidateFactory.php
backend/app/Data/ReferenceFinding/ReferenceFindingCandidatePreviewData.php
backend/app/Providers/AppServiceProvider.php
backend/config/{services,scoring,analysis}.php
backend/.env.example
backend/phpunit.xml
backend/tests/TestCase.php
backend/tests/Support/AnalysisHarness.php
backend/tests/{Feature,Unit}/* (rename fallout + new assertions)
frontend/src/types/api.ts
docs/{API_SPEC,API_SPEC_ID,DB_SCHEMA,ARCHITECTURE,SECURITY,TEST_PLAN}.md
AGENTS.md
docs/plans/backend/README.md
```

No new Composer/npm dependency.

---

## 14. Risks and pitfalls

### 14.1 Pitfalls

- **Do not merge Garuda results unconditionally.** Without the gate you double outbound traffic and
  create near-duplicate candidates for records Crossref already resolves.
- **Do not swallow Garuda failures into `[]`.** That is the bug this policy exists to prevent:
  a blocked/changed site would silently look like "not indexed". Throw, and let the step decide
  between fatal and `pending`.
- **Do not treat a valid empty page as a failure.** Parse the `Found N documents` marker first;
  `N = 0` is a cacheable miss. Only marker absence or `N > 0` with zero parsed cards is a format
  error.
- **Do not catch `Throwable` at the client boundary.** Catch transport exceptions only and rethrow
  them as `GarudaUnavailableException`; programming errors must stay loud.
- **Do not treat Garuda as DOI evidence.** `doi = null` candidates must not populate a "suggested
  DOI" UI path, and must not flip `DoiLookup`.
- **Do not parse the detail page** in v1 — it multiplies requests and duplicates data the cards
  already carry.
- **Encoding.** Without the `<?xml encoding="UTF-8">` hint, `DOMDocument` mangles Indonesian text
  (`é`, `ü`, Arabic names), which silently destroys title similarity.
- **Throttle must be global**, not per job: three queue workers must not each send 1 req/s. Use the
  cache lock + timestamp, not `sleep()`.
- **Cache the parsed array, not the HTML.** Raw pages are ~65 KB each; arrays are a few hundred
  bytes and are what the step consumes.
- **`after('confidence')`** is a MySQL/Postgres nicety; do not rely on it for correctness.
- **Budget × throttle vs `ANALYSIS_TIMEOUT`.** 500 lookups at 1 req/s is ~8 min of wall time; the
  job timeout is 900 s. Keep the product below the timeout and tune the throttle, never the other
  way round.

### 14.2 Legal / operational (must be stated in the PR)

- Garuda publishes no robots policy, no API terms and no rate limits. Low-volume, cached,
  metadata-only access with an identifiable UA is the defensible baseline. **Owner action (not
  code): obtain written permission from Garuda/Kemdiktisaintek before a public production launch.**
- Never fetch the "Download Original" / "Original Source" links — those point at journal
  publishers and would turn the client into a bulk crawler.
- Monitor for silent breakage: a weekly smoke test against live Garuda asserting ≥1 parsed card for
  a known title, logged as a warning (not a failing deploy) when it returns zero.

### 14.3 Rollback

`GARUDA_ENABLED=false` disables the source with no deploy (the emergency switch if Garuda starts
blocking or rate-limiting harder than configured). The `source` column stays (`crossref` default)
and is harmless. Reverting the step wiring restores Phase 04 behavior exactly.

### 14.4 Scaling path (documented, not implemented)

1. **RIS enrichment** for top hits (`/citation/site/RIS/{id}`) when card metadata is incomplete.
2. **Per-journal OAI-PMH** (`/oai?verb=GetRecord`) for journals that do not register DOIs — more
   authoritative than scraping the aggregator, but N-journal configuration.
3. **Local index**: if per-reference scraping becomes the bottleneck or Garuda asks you to stop,
   harvest Garuda metadata into a `garuda_records` table on a separate queue and query locally.
   That is when a separate worker/service is justified (see the design discussion), not before.

---

## 15. Hand-off

After this phase, adding OpenAlex is:

1. `OpenAlexClient implements BibliographicSource` (JSON, `mailto` param, cursor-free single page),
2. `MetadataSource::OpenAlex` already exists,
3. add `'openalex'` to `config('analysis.metadata.primary_sources')`,
4. no schema, DTO, step or reason changes.

That is the test of whether the seam in T1 was the right size: if OpenAlex needs step edits, the
seam is wrong and should be revisited before writing its client.
