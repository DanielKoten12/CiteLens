# Phase 05 — Citation Resolution, Review Endpoints & Findings Feed (Detailed Implementation Plan)

> **Status:** detailed plan — ready to implement (4 open questions with recommended defaults; see §16)
> **Parent:** [`05-citation-resolution-and-review.md`](05-citation-resolution-and-review.md)
> **Depends on:** [Phase 04](04-crossref-verification-detail.md) (implemented) · **Unblocks:** Phase 06
> Canonical references: `docs/API_SPEC.md` §2.5/§2.6/§2.7/§5/§6/§7, `docs/DB_SCHEMA.md` (first DBML
> block), `docs/ARCHITECTURE.md` §7/§9/§13, `docs/PRODUCT_REQUIREMENTS.md` §6.3/§6.4/§6.5,
> `docs/TEST_PLAN.md` §5.3–§5.6, `docs/SECURITY.md` §2/§3, `AGENTS.md` §3/§6/§7/§8/§10/§14.
> Task template: `docs/plans/backend/README.md` §1.4.

This document expands `05-citation-resolution-and-review.md` into executable tasks. It keeps the
phase boundary: it implements **citation resolution + the derived-status read model + the
`/references`, `/citations`, `/findings` endpoints + manual review** and **does not** implement the
matching/scoring engine (Phase 04, already done), report generation (Phase 06) or the FastAPI
service (OQ-13). It adds **no migration and no schema change**.

The design keeps five hard rules from the proposal and `AGENTS.md`:

1. **One derived-status rule.** `CitationStatus::derive()` (PHP) and
   `CitationStatusResolver::sqlExpression()` (SQL) stay the only implementations, and a mandatory
   agreement test pins them together (F-DERIV-01).
2. **One attacker surface per concern.** Pairing lives in `CitationPairingService`; manual finding
   review lives in `ReferenceFindingReviewService`. No controller writes a derived row directly.
3. **Ownership first, then validation.** Foreign ids always resolve to `404` through
   `OwnedResourceFinder` **before** same-document/enum checks, so validation can never disclose
   existence (D-05-10).
4. **Derived data is never persisted.** Citation status and the findings feed are computed; no new
   columns.
5. **No schema/contract change silently.** The phase only adds *clarifying* `API_SPEC.md` notes
   (unpair message, 409 rule, list ordering, multi-reference limitation). Any shape change is a
   contract change and must be called out.

> **Read first.** The four questions that materially change implementation shape are §16 Q-1 (use
> the GROBID `reference_index` hint?), Q-2 (list ordering), Q-3 (multi-reference citations) and
> Q-6 (finding-less references in the feed). Each has a recommended default adopted below; if one
> changes, only the named sections change.

---

## 1. Baseline (verified against the repository at plan creation)

| Item | Value |
|---|---|
| Laravel | `13.x`; PHP CLI `8.5`; `composer.json` requires `^8.3` |
| DTO layer | `spatie/laravel-data` 4.x, `App\Data\*`, `BaseData` (wraps as `data`), `ModelData` (`relations()`, `prepareQuery()`) |
| Pest / DB / queue | Pest 5, in-memory SQLite, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array` (`phpunit.xml`) |
| Pipeline seam | `RunsDocumentAnalysis` bound to `AnalysisPipeline`; `AnalysisStepRegistry` sorts by `AnalysisStep::ordered()`; `AnalysisServiceProvider` is the only wiring point |
| Pipeline steps today | `ExtractDocumentStep`, `PersistExtractionStep`, `ValidateReferencesStep`, `EmbedReferencesStep`, `ScoreReferencesStep`, `FinalizeAnalysisStep`. `ResolvingCitations` is still a `StubPipelineStep` in `AnalysisHarness::fullSteps()` |
| Extracted citations | `PersistExtractionStep` writes `researched_document_reference_id = null` (pairing deferred to this phase); it also **discards** `ExtractedCitationData::$referenceIndex` (the GROBID bibliography hint) |
| Derived status | `App\Enums\CitationStatus::derive(bool, ?ReferenceFindingStatus)` + `App\Services\Citations\CitationStatusResolver` (`resolve()` PHP, `sqlExpression()` SQL CASE, validated identifiers). `DocumentSummaryService` already uses the SQL expression |
| Ownership | `OwnedResourceFinder::{document,reference,citation,report}` (404 with the per-resource message) and the `ScopesThroughDocument` trait (`forUser`, `forDocument`) |
| Envelope | `ApiResponse::{single,created,accepted,collection,noContent}`; `ApiError` / `ApiException` / `ApiExceptionRenderer` (ValidationException → 422 with `details`) |
| Enums | `CitationStatus`, `FindingType` (incl. `reference_pending`), `FindingSeverity` (incl. `low`), `ReferenceFindingStatus` with `isProblem()`, `toFindingType()`, `toSeverity()` |
| Models | `ResearchedDocumentReference` (`+locations`, `+finding`), `ResearchedDocumentCitation` (`+reference`, `+locations`), `ReferenceFinding` (`+candidates`, `+selectedCandidate`, `+reviewedBy`), `ReferenceFindingCandidate` |
| Config | `config/scoring.php` exists (`thresholds`, `weights`, `semantic`, `year_tolerance` = 1, `local_venue_keywords`); `config/analysis.php` has the `resolving_citations` progress range 85→95 |
| Exceptions | `ResourceNotFoundException`, `StateConflictException::{documentRetryNotAllowed,reportNotAllowed}`, `ApiException` |
| Test harness | `Tests\Support\{AnalysisHarness,DocumentTree,Fixtures,InferenceFake,CrossrefFake,StubPipelineStep}`; `DocumentTree` already builds paired citations, findings and candidates |
| Routes | `routes/api.php` has auth + full `/documents` lifecycle only; no `/references`, `/citations`, `/findings` yet |
| Fixture that constrains e2e | `tests/Fixtures/inference/extract.json` has 3 references + 3 citations (`(LeCun et al., 2015)`, `(Koten, 2023)`, `(Tanpa rujukan, 2022)`) with GROBID `reference_index` 0/1/null |

Facts that constrain the implementation:

- `ReferenceFindingWriter` (Phase 04) writes findings/candidates for automated runs only and leaves
  `is_manual`/`reviewed_by`/`reviewed_at` untouched. Manual review must be a **separate** writer.
- `DocumentAnalysisResetService::reset()` deletes citations and findings before every re-run, so
  pairing always starts from a clean, unpaired citation set; the step must still be idempotent.
- `CitationStatusResolver::sqlExpression()` already validates identifiers and is used by
  `DocumentSummaryService`; reuse it verbatim for list filters and the feed — do not add a second
  CASE.
- `config/analysis.progress.resolving_citations` is defined but no step uses it yet.
- `QUEUE_CONNECTION=sync` runs the pipeline inline in tests; the e2e fixture will produce
  **2 paired + 1 unpaired** citations once resolution lands.

---

## 2. Execution model

### 2.1 Task order and dependencies

| Task | Deliverable | Size | Depends on | Suggested commit |
|---|---|---|---|---|
| **T0** | Preflight & baseline | S | — | (no commit) |
| **T1** | Citation matching engine: `CitationMarkerParser`, `ParsedCitationMarker`, `CitationReference`, `CitationResolution`, `CitationResolver`, `CitationMatchConfig` + `config/scoring.php` / `.env.example` keys | L | — | `feat(backend): add citation marker parser and resolver` |
| **T2** | `ResolveCitationsStep` + provider wiring + `AnalysisHarness` update + pipeline/e2e assertions | M | T1 | `feat(backend): resolve in-text citations during analysis` |
| **T3** | Read model: `ReferenceQueryService`, `CitationQueryService`, `LocationPreviewData`, all reference/citation/finding DTOs | L | — | `feat(backend): add reference and citation read model` |
| **T4** | Endpoints: `ReferenceController`, `CitationController`, FormRequests, `ReferenceFindingReviewService`, `CitationPairingService`, routes | L | T3 | `feat(backend): expose reference and citation review endpoints` |
| **T5** | Findings feed: `FindingsFeedQuery`, `FindingsFeedComposer`, `FindingsController`, route | M | T3 | `feat(backend): add derived findings feed endpoint` |
| **T6** | Full acceptance matrix: T-REF/T-CIT/T-FIND/T-OWN/F-DERIV + pipeline regression updates | L | T2, T4, T5 | `test(backend): cover citation resolution and review endpoints` |
| **T7** | Docs sync + full-suite exit validation | S | T1–T6 | `docs: sync citation resolution status` |

T1 and T3 are independent and can land in parallel. T2 needs only T1. T4 needs only T3. T5 needs
only T3 (and reuses the location DTO). No migration is added anywhere.

### 2.2 Per-task loop

1. Re-read the task and the canonical `docs/API_SPEC.md` §5/§6/§7 section it cites.
2. Implement the task and its tests in the **same** change.
3. `vendor/bin/pest <affected paths>` — affected tests only.
4. `vendor/bin/pint --dirty --format agent` — format changed PHP files.
5. `php artisan test --compact` — full suite stays green.
6. Only T7 touches status docs; do not churn docs mid-phase.

### 2.3 Exit criteria (from `05-citation-resolution-and-review.md` §5)

- T-REF-01..06, T-CIT-01..09, T-FIND-01..04, T-OWN-05..07 and F-DERIV-01 pass.
- After the e2e fixture runs, `(LeCun et al., 2015)` and `(Koten, 2023)` are paired with their
  references and `(Tanpa rujukan, 2022)` is unpaired (`hallucination`); the document still reaches
  `completed`.
- Every endpoint matches `docs/API_SPEC.md` (status, envelope, field names, enum values, messages).
- Foreign reference/citation/document access returns `404` with the per-resource message and a body
  indistinguishable from a missing id.
- The PHP resolver and the SQL scope produce identical statuses for all four derived statuses on one
  fixture (F-DERIV-01).
- `php artisan test --compact` green; `vendor/bin/pint --dirty --format agent` reports nothing.
- No secret/`.env` value committed; `.env.example` gains placeholders only.
- No `docs/DB_SCHEMA.md` change; `docs/API_SPEC.md` gains only clarifying notes (no shape change).

---

## 3. Conventions this phase locks in

Reused from Phases 01–04 (do not re-implement):

- Responses only through `ApiResponse`/`ApiError`; no hand-built envelopes.
- All document-owned lookups go through `OwnedResourceFinder`; child queries use
  `ScopesThroughDocument` (`forUser`, `forDocument`).
- Derived citation status only through `CitationStatus::derive()` /
  `CitationStatusResolver::sqlExpression()`.
- One business rule per service; controllers are transport only.
- `final class` + constructor property promotion + explicit types; PHPDoc over inline comments;
  `snake_case` enum values at the boundary.
- Thresholds/limits in config, never literals in services.
- List endpoints validate filters with `Rule::enum(...)`, paginate deterministically (offset sort +
  id tiebreaker) and return the canonical `data`/`meta` collection envelope.

Phase-05-specific conventions:

- **Pairing is a separate writer from review.** `ResolveCitationsStep` (automated) and
  `CitationPairingService` (manual) each own one column write path; neither re-derives status.
- **Resolution is pure where possible.** `CitationMarkerParser` and `CitationResolver` take value
  objects and return value objects; only `ResolveCitationsStep` touches Eloquent/DB.
- **The parser is conservative.** Prefer an unpaired (`hallucination`) result over a wrong pairing;
  never pair across documents; never invent a reference that does not exist.
- **One location shape.** A single `App\Data\Location\LocationPreviewData` is reused by reference
  detail, citation detail and the findings feed (D-05-08) instead of three identical DTOs.
- **No second status map.** The feed's type/severity mapping reads
  `ReferenceFindingStatus::{toFindingType,toSeverity}()` and `CitationStatus::toFindingType()`; the
  citation-unreliable severity inherits the paired finding's `toSeverity()`.

---

## 4. Decision log additions

These extend the README `Decision log` and the Phase 03/04 additions. They are internal
implementation decisions (no schema change); each is recorded so later phases do not re-litigate it.

| ID | Question | Decision |
|---|---|---|
| **D-05-01** | Does resolution use the GROBID `reference_index` hint (`ExtractedCitationData::$referenceIndex`, not persisted)? | **No, not in v1.** Resolution is derived from persisted reference metadata (authors/year) + marker parsing + the deterministic bibliography order (OQ-18). The hint is a transient inferer artefact with no column; trusting it would couple resolution to an unvalidated payload field and to `PersistExtractionStep` internals. Recorded as Q-1 with the persistence path if it is ever wanted. |
| **D-05-02** | Where do citation-matching thresholds live? | `config/scoring.php` gains a `citation_matching` block (`surname_threshold`), exposed through `App\Services\Citations\CitationMatchConfig`. The `CitationMatchConfig` **delegates year tolerance to `ScoringConfig::yearTolerance()`** so the two matchers cannot drift. Deviates from the broad plan's "separate config" only in sharing the year tolerance (one source). |
| **D-05-03** | Default ordering of the reference/citation lists and the feed. | **Document position**: `text_start_offset` ASC (nulls last) then `id` tiebreak, matching OQ-09 and OQ-18. This is a deliberate exception to the generic `created_at desc` default (`API_SPEC.md` §2.7) and gets a one-line `API_SPEC.md` note. See Q-2. |
| **D-05-04** | Multi-reference citation markers (`[3], [5]`, `[3–5]`, `(A, 2020; B, 2021)`). | The canonical schema stores **one** reference per citation row, so the citation pairs with the **first/lowest ordinal** from an IEEE marker (and the first APA author/year pair), and the remaining references are left unrepresented. Documented as a v1 limitation (Q-3). No schema change. |
| **D-05-05** | Manual review of a reference while the document is non-terminal. | `409 CONFLICT` while `status = processing` (a running pipeline must never be overwritten). Because references only exist after `persisting`, an existing reference implies the document is `processing`, `completed` or `failed`; the guard makes the intent explicit. New factory `StateConflictException::findingReviewNotAllowed()`. Matches OQ-17. |
| **D-05-06** | Does manual review change `confidence`? | **No.** The review adjusts `status`, `selected_candidate_id`, `reason`, `is_manual`, `reviewed_by`, `reviewed_at` only (matches the `API_SPEC.md` §5 example, where `confidence` stays `0.6300`). A newly created manual finding has `confidence = null` (no evidence). |
| **D-05-07** | Success message when unpairing a citation. | `"Tautan sitasi berhasil dilepaskan."` Pairing keeps the spec literal `"Sitasi berhasil ditautkan."`. The spec only documents the pairing message; the unpair message gets a clarifying `API_SPEC.md` note. |
| **D-05-08** | Three identical location DTOs (broad plan) vs one shared shape. | One `App\Data\Location\LocationPreviewData` (`page_number`, `x`, `y`, `width`, `height`, `page_width`, `page_height`, `coordinate_system`, `location_index`) is reused by reference detail, citation detail and the findings feed. Deliberate deviation from the broad plan's `ReferenceLocationPreviewData`/`CitationLocationPreviewData`; a shared shape cannot drift. |
| **D-05-09** | Does the findings feed include references with **no** `reference_findings` row? | **No.** The feed is built from existing finding rows (and citations with a derived `unreliable`/`hallucination`), so the canonical `ref-finding:{findingId}` id always has a real finding id. Finding-less references are still visible through `GET /documents/{document}/references?status=pending` (which includes missing findings). Recorded as Q-6. |
| **D-05-10** | Where is the same-document rule enforced? | After `OwnedResourceFinder` resolves the parent: `CitationPairingService` (pairing) and `CitationQueryService`/`ListCitationsRequest`-driven service check (filter) throw `ValidationException` → `422`. FormRequests validate only shape (enum/uuid/boolean), so foreign ids keep returning `404` and validation never discloses existence. |
| **D-05-11** | `PATCH /citations/{citation}` with an absent `researched_document_reference_id`. | The field must be **present** and nullable: `{ "researched_document_reference_id": null }` unpairs. `['present', 'nullable', 'uuid']`. Absent → `422`. Documented as a request clarification. |
| **D-05-12** | Findings feed query strategy. | A single portable `unionAll` subquery with a computed `sort_offset = COALESCE(offset, 2147483647)` and `sort_id`, wrapped in `fromSub(... 'feed')`, with `type`/`severity` filters and pagination applied on the wrapper. Keeps `meta` correct without loading all rows. `NULL::varchar` is avoided by selecting real columns on each side. See §9.4 for the Postgres note. |
| **D-05-13** | Reason/validation message language. | Spec-mandated literals are copied verbatim (e.g. `"Status referensi diperbarui."`, `"The selected reference is invalid for this document."`). Undocumented new messages are Indonesian (Q-4/Q-5), centralized in the owning service/constant. |

---

## 5. T0 — Preflight & baseline

- [ ] `cd backend && composer install` (if `vendor/` is stale).
- [ ] `php artisan test --compact` → all existing tests pass.
- [ ] `git status` clean / expected branch (`feat/citation-resolution`).
- [ ] Re-read `05-citation-resolution-and-review.md` §2–§7 and the README `Decision log`; no open
      question blocks T1–T6 (the §16 questions have documented defaults).
- [ ] Confirm no `/references`, `/citations`, `/findings` routes exist (`php artisan route:list --path=api/v1`).
- [ ] Confirm `CitationStatusResolver::sqlExpression()` is unchanged and still used by
      `DocumentSummaryService` (no second CASE may be added anywhere).
- [ ] Confirm `analysis.progress.resolving_citations` = `{floor: 85, ceiling: 95}`.
- [ ] Confirm the e2e fixture (`tests/Fixtures/inference/extract.json`) has 3 references and 3
      citations that should resolve to 2 paired + 1 unpaired.

No commit for T0.

---

## 6. T1 — Citation matching engine (pure)

**New files:**
`app/Services/Citations/{CitationMarkerParser,ParsedCitationMarker,CitationReference,CitationResolution,CitationResolver,CitationMatchConfig}.php`
**Modified:** `config/scoring.php`, `.env.example`
**Tests:** `tests/Unit/{CitationMarkerParserTest,CitationResolverTest,CitationMatchConfigTest}.php`

Everything in this task is pure: value objects in, value objects out. No Eloquent, no `DB`, no
`Http`, no container calls outside `config('scoring.*')` (via `CitationMatchConfig` /
`ScoringConfig`).

### 6.1 `ParsedCitationMarker`

```php
namespace App\Services\Citations;

final class ParsedCitationMarker
{
    /**
     * @param  list<string>  $surnames  parsed APA surnames (author order preserved)
     * @param  list<int>  $ordinals     IEEE reference ordinals, ascending, de-duplicated
     */
    public function __construct(
        public readonly array $surnames = [],
        public readonly ?int $year = null,
        public readonly array $ordinals = [],
    ) {}

    public function isIeee(): bool;   // $ordinals !== []
    public function isApa(): bool;    // ! isIeee() && ($surnames !== [] || $year !== null)
    public function isParsed(): bool; // isIeee() || $surnames !== []
}
```

### 6.2 `CitationMarkerParser`

```php
final class CitationMarkerParser
{
    public function __construct(private readonly AuthorMatcher $authors) {}

    /**
     * Parse a citation marker, falling back to the raw citation text when the
     * marker is null/blank. Never throws; an unparseable input returns an empty
     * `ParsedCitationMarker`.
     */
    public function parse(?string $marker, ?string $citationText = null): ParsedCitationMarker;
}
```

Algorithm (conservative, documented in the class docblock):

```text
1. raw = first non-blank of (marker, citationText); trim; empty → new ParsedCitationMarker()
2. IEEE: regex all bracketed groups `/\[([^\]]*)\]/u`; for each group with a number,
   expand `n`, `n,m`, `n–m` / `n-m` into ordinals.
   - ordinals = sorted unique positive ints. Non-numeric brackets ignored.
   - if ordinals !== [] → return IEEE marker (year/surnames null).
3. APA:
   a. parenthetical: `/([^()]+?),\s*((?:19|20)\d{2}[a-z]?)\s*\)/u` over `( ... )`
      → authorsPart = group 1, year = int(group 2).
   b. narrative: `/([^(),]+?)\s*\(((?:19|20)\d{2}[a-z]?)\)/u`
      → authorsPart = group 1, year = int(group 2).
   c. authorsPart → `AuthorMatcher::surnames()` (reuse; strips `et al.`/`dkk.`,
      splits `&`/`;`/`and`, keeps one surname per author).
   d. if surnames === [] and year === null → empty marker.
4. Multi-reference input (`;`, multiple bracket groups, comma-separated pairs) is
   intentionally reduced to the first pair/first ordinal (D-05-04).
```

Do **not** hand-roll author splitting — `AuthorMatcher::surnames()` already encodes the APA/IEEE
conventions used by `AuthorMatcher` and is covered by its unit tests.

### 6.3 `CitationReference` / `CitationResolution`

```php
final class CitationReference
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $authors,
        public readonly ?int $publicationYear,
        public readonly int $bibliographyIndex, // 1-based, OQ-18 order
    ) {}
}

final class CitationResolution
{
    public function __construct(
        public readonly ?string $referenceId,
        public readonly ?float $confidence = null,
        public readonly ?string $matchReason = null,
    ) {}

    public function isPaired(): bool; // $referenceId !== null
    public static function unpaired(): self;
}
```

`matchReason` is telemetry/logging only (the schema has no citation confidence/reason column); it
is never persisted.

### 6.4 `CitationMatchConfig`

```php
namespace App\Services\Citations;

final class CitationMatchConfig
{
    public function __construct(private readonly ScoringConfig $scoring) {}

    /** `scoring.citation_matching.surname_threshold`, clamped to 0..1. */
    public function surnameThreshold(): float;

    /** Shared with reference scoring so the two matchers cannot drift. */
    public function yearTolerance(): int;
}
```

`config/scoring.php` gains:

```php
'citation_matching' => [
    // Minimum Jaro-Winkler surname similarity for an APA citation to pair.
    'surname_threshold' => (float) env('SCORING_CITATION_SURNAME_THRESHOLD', 0.85),
],
```

`.env.example` adds `# SCORING_CITATION_SURNAME_THRESHOLD=0.85`. No other key is renamed.

### 6.5 `CitationResolver`

```php
final class CitationResolver
{
    public function __construct(
        private readonly ScoringConfig $scoring,
        private readonly CitationMatchConfig $config,
        private readonly StringSimilarity $strings,
        private readonly AuthorMatcher $authors,
    ) {}

    /**
     * @param  list<CitationReference>  $references  bibliography order (bibliographyIndex 1..N)
     */
    public function resolve(ParsedCitationMarker $marker, array $references): CitationResolution;
}
```

Algorithm:

```text
A. IEEE (marker->isIeee()):
   - ordinal = first (lowest) ordinal.
   - reference = the one whose bibliographyIndex === ordinal, if any.
   - paired (confidence 1.0, reason "ordinal") or unpaired when out of range.

B. APA (marker->isApa()):
   - candidates = references where the year gate passes:
       citedYear !== null AND reference.publicationYear !== null
         → abs(diff) <= yearTolerance(), else skip;
       otherwise no year gate (missing year on either side is not a mismatch).
   - for each candidate: surnameScore = best-pair average Jaro-Winkler between
     marker.surnames and AuthorMatcher::surnames(reference.authors);
       - no surnames on the reference side or no cited surnames → score = null → skip.
   - keep the best score; require score >= surnameThreshold.
   - tie-break: higher score, then lower bibliographyIndex (earliest position).
   - paired (confidence = score) or unpaired.
```

- Never pairs across documents because the caller only passes the document's references.
- `null` scores are treated as "no signal", never `0` (avoids fabricating a match).
- Out-of-range IEEE ordinal → unpaired; a low APA score → unpaired.

### 6.6 Tests

`CitationMarkerParserTest`:
- `(Koten, 2023)` → surnames `['Koten']`, year 2023, no ordinals.
- `Koten et al. (2023)` (narrative) → `['Koten']`, 2023.
- `(Koten & Tani, 2023)` → `['Koten', 'Tani']`, 2023.
- `LeCun et al., 2015` (marker without parens) → `['LeCun']`, 2015.
- `[3]` → ordinal `[3]`; `[3], [5]` → `[3, 5]`; `[3-5]` and `[3–5]` → `[3, 4, 5]`.
- `(Tanpa rujukan, 2022)` → `['Tanpa rujukan']`? Document the actual parse; the resolver decides no
  reference matches. (Assert the parser output, not the pairing.)
- blank/null/`"n.d."`/`"[]"` → `isParsed() === false`.
- multi-reference APA `(Koten, 2023; Tani, 2021)` → first pair only (documented, D-05-04).

`CitationResolverTest`:
- exact surname+year → pair with the right reference id; confidence 1.0.
- `Koten` vs `Koton` (≥ threshold) → pair.
- wrong year outside tolerance → unpaired.
- reference year null → year gate skipped, surname decides.
- answer below `surname_threshold` → unpaired.
- two references same surname+year → earliest `bibliographyIndex` wins.
- IEEE `[2]` → second reference; `[99]` → unpaired.
- IEEE `[3-5]` → third reference (lowest ordinal).

`CitationMatchConfigTest`:
- default threshold loads; an override is clamped; `yearTolerance()` equals
  `ScoringConfig::yearTolerance()` for the same config.

---

## 7. T2 — `ResolveCitationsStep` and pipeline wiring

**New file:** `app/Services/Analysis/Steps/ResolveCitationsStep.php`
**Modified:** `app/Providers/AnalysisServiceProvider.php`, `tests/Support/AnalysisHarness.php`
**Tests:** `tests/Feature/ResolveCitationsStepTest.php`; updated `tests/Feature/AnalysisPipelineTest.php`,
`tests/Feature/AnalysisEndToEndTest.php`, `tests/Feature/CrossrefVerificationEndToEndTest.php`

### 7.1 Step

```php
namespace App\Services\Analysis\Steps;

final class ResolveCitationsStep implements PipelineStep
{
    public function __construct(
        private readonly CitationMarkerParser $parser,
        private readonly CitationResolver $resolver,
        private readonly AnalysisProgress $progress,
    ) {}

    public function step(): AnalysisStep { return AnalysisStep::ResolvingCitations; }

    public function handle(ResearchedDocument $document, AnalysisContext $context): void;
}
```

Algorithm:

```text
1. references = $document->references()
       ->orderByRaw('text_start_offset IS NULL')
       ->orderBy('text_start_offset')->orderBy('id')->get()
   map to CitationReference with bibliographyIndex = 1..N (OQ-18 order).
2. citations = $document->citations()->get().
3. DB::transaction:
     a. null every citation's `researched_document_reference_id` for the document
        (idempotency; the reset already deletes rows, but this makes the step safe
        to run twice).
     b. group citation ids by resolved reference id; one `whereIn(id)->update()`
        per reference (bulk; no per-row write for a common case).
4. progress report: `$progress->report($document, $this->step(), $done, $total)`.
   Zero citations → skip.
```

- No writes outside the transaction; no HTTP; no candidate/finding reads.
- The step never touches `reference_findings` — pairing is independent of the verdict (D-05-03 of
  the broad plan).
- Empty references + non-empty citations → all citations stay unpaired (correct `hallucination`).

### 7.2 Provider wiring

`AnalysisServiceProvider` appends the step in canonical order:

```php
$this->app->singleton(AnalysisStepRegistry::class, fn (Application $app): AnalysisStepRegistry => new AnalysisStepRegistry([
    $app->make(ExtractDocumentStep::class),
    $app->make(PersistExtractionStep::class),
    $app->make(ValidateReferencesStep::class),
    $app->make(EmbedReferencesStep::class),
    $app->make(ScoreReferencesStep::class),
    $app->make(ResolveCitationsStep::class),   // resolving_citations (Phase 05)
    $app->make(FinalizeAnalysisStep::class),
]));
```

### 7.3 `AnalysisHarness::fullSteps()` update

Replace the `StubPipelineStep::for(AnalysisStep::ResolvingCitations)` placeholder with
`app(ResolveCitationsStep::class)` and drop the now-unused `StubPipelineStep` import if nothing else
in the file uses it.

### 7.4 Tests

`ResolveCitationsStepTest`:
- APA fixture: a reference `Koten, D. (2023)` + citation marker `Koten, 2023` → paired FK set.
- year mismatch → unpaired (`null`).
- IEEE marker `[1]` → pairs with bibliography position 1 (order by offset, nulls last).
- **idempotency:** running the step twice leaves the same pairings (no duplicate/incorrect writes).
- **no cross-document pairing:** a same-document reference set never resolves to another document's
  reference (assert the FK belongs to the document).
- citations with `reference_index`-only hints (no parseable marker/text) stay unpaired (Q-1/D-05-01).

Pipeline/e2e updates:
- `AnalysisEndToEndTest` "drives a document to completed": assert `citations` count 3 and
  `whereNotNull('researched_document_reference_id')` count 2.
- `CrossrefVerificationEndToEndTest`: assert `(LeCun et al., 2015)` and `(Koten, 2023)` are paired
  and their derived status is `valid`, and `(Tanpa rujukan, 2022)` is unpaired (`hallucination`)
  via `CitationStatus::derive()`.
- Any test that previously relied on the resolution stub must now install the real step through
  `AnalysisHarness::fullSteps()`; ordering-only tests keep explicit `StubPipelineStep`s.

---

## 8. T3 — Read model (query services + DTOs)

**New files:**
`app/Services/Reference/ReferenceQueryService.php`,
`app/Services/Citation/CitationQueryService.php`,
`app/Data/Location/LocationPreviewData.php`,
`app/Data/Reference/{ReferenceSummaryData,ReferenceDetailData}.php`,
`app/Data/ReferenceFinding/{ReferenceFindingPreviewData,ReferenceFindingDetailData,ReferenceFindingCandidatePreviewData,ReferenceFindingReviewData}.php`,
`app/Data/Citation/{CitationSummaryData,CitationDetailData,CitationReferencePreviewData,CitationPairingData,CitationOccurrenceData}.php`
**Tests:** `tests/Feature/{ReferenceQueryServiceTest,CitationQueryServiceTest}.php`,
`tests/Unit/{ReferenceDataTest,CitationDataTest}.php`

### 8.1 `LocationPreviewData` (D-05-08)

```php
#[MapName(SnakeCaseMapper::class)]
final class LocationPreviewData extends BaseData
{
    public function __construct(
        public int $pageNumber,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public ?float $pageWidth,
        public ?float $pageHeight,
        public string $coordinateSystem,
        public int $locationIndex,
    ) {}

    /** @param ResearchedDocumentReferenceLocation|ResearchedDocumentCitationLocation $location */
    public static function fromModel(Model $location): self;
}
```

Reused by reference detail, citation detail and the findings feed. `page_number` is 1-based and
`coordinate_system` is `pdf_points_top_left` (never re-derived).

### 8.2 `ReferenceQueryService`

```php
namespace App\Services\Reference;

final class ReferenceQueryService
{
    /**
     * Paginate the document's bibliography with the finding eager-loaded.
     * Order: `text_start_offset` ASC nulls last, then `id` (D-05-03).
     */
    public function paginate(
        ResearchedDocument $document,
        ?ReferenceFindingStatus $status = null,
        ?bool $hasDoi = null,
        int $perPage = 15,
    ): LengthAwarePaginator;
}
```

Filters:

- `status = pending`: **includes references whose finding row is missing** (broad plan §3.3). Use
  `where(fn ($q) => $q->whereDoesntHave('finding')->orWhereHas('finding', fn ($f) => $f->where('status', $status)))`.
- any other status: `whereHas('finding', fn ($f) => $f->where('status', $status))`.
- `has_doi = true`: `whereNotNull('doi')->where('doi', '!=', '')`.
- `has_doi = false`: `where(fn ($q) => $q->whereNull('doi')->orWhere('doi', '='))`.
- Always `forDocument($document)` and `->with('finding')` (one query, no N+1).
- `->paginate($perPage)->withQueryString()`.

### 8.3 `CitationQueryService`

```php
namespace App\Services\Citation;

final class CitationQueryService
{
    /**
     * Paginate citations with the derived status filter applied through the single
     * SQL expression. Order: `text_start_offset` ASC nulls last, then `id` (D-05-03).
     */
    public function paginate(
        ResearchedDocument $document,
        ?CitationStatus $status = null,
        ?string $referenceId = null,   // caller has already validated same-document
        int $perPage = 15,
    ): LengthAwarePaginator;
}
```

Query shape (aliases are intentional; the resolver expression references them):

```php
$derived = $this->resolver->sqlExpression('c.researched_document_reference_id', 'f.status');

$query = ResearchedDocumentCitation::query()
    ->from('researched_document_citations as c')
    ->leftJoin('researched_document_references as r', 'r.id', '=', 'c.researched_document_reference_id')
    ->leftJoin('reference_findings as f', 'f.researched_document_reference_id', '=', 'r.id')
    ->where('c.researched_document_id', $document->getKey())
    ->select('c.*')
    ->with('reference.finding');           // eager-load the paired reference + finding

if ($status !== null) {
    $query->whereRaw("({$derived}) = ?", [$status->value]);
}

if ($referenceId !== null) {
    $query->where('c.researched_document_reference_id', $referenceId);
}

return $query
    ->orderByRaw('c.text_start_offset IS NULL')
    ->orderBy('c.text_start_offset')
    ->orderBy('c.id')
    ->paginate($perPage)
    ->withQueryString();
```

- The same-document check for `reference_id` is **not** here; the caller (`ListCitationsRequest`
  consumer / controller) validates it after ownership (D-05-10) and passes a trusted id.
- `with('reference.finding')` issues two extra queries regardless of page size, no N+1.

### 8.4 Reference DTOs — `App\Data\Reference\`

`ReferenceSummaryData` (§5 list item):

```php
#[MapName(SnakeCaseMapper::class)]
final class ReferenceSummaryData extends BaseData
{
    public function __construct(
        public string $id,
        public ?string $rawText,
        public ?string $doi,
        public ?string $title,
        public ?string $authors,
        public ?string $publicationName,
        public ?int $publicationYear,
        public ?int $textStartOffset,
        public ?int $textEndOffset,
        public ?ReferenceFindingPreviewData $finding = null,
    ) {}

    public static function forReference(ResearchedDocumentReference $reference): self;
}
```

`ReferenceDetailData` adds (extends `ModelData`):

- `locations: list<LocationPreviewData>`
- `finding: ?ReferenceFindingDetailData`
- `citations: list<CitationOccurrenceData>` — `{id, citation_text, occurrence_index}`, ordered by
  `occurrence_index` ASC nulls last then `id`.

Build with `ModelData::relations()` so `show` eager-loads `finding.candidates`, `locations`,
`citations` in one round trip.

`CitationOccurrenceData` (lives in `App\Data\Citation\`, used by `ReferenceDetailData`):

```php
#[MapName(SnakeCaseMapper::class)]
final class CitationOccurrenceData extends BaseData
{
    public function __construct(
        public string $id,
        public string $citationText,
        public ?int $occurrenceIndex,
    ) {}
}
```

### 8.5 Reference-finding DTOs — `App\Data\ReferenceFinding\`

| DTO | Fields |
|---|---|
| `ReferenceFindingPreviewData` | `id`, `status` (`ReferenceFindingStatus`), `confidence` (`?float`), `reason` (`?string`), `selected_candidate_id` (`?string`) |
| `ReferenceFindingDetailData` | preview fields + `is_manual` (bool), `reviewed_by` (`?string`), `reviewed_at` (`?CarbonImmutable`), `candidates` (`list<ReferenceFindingCandidatePreviewData>`) |
| `ReferenceFindingCandidatePreviewData` | `id`, `rank` (int), `confidence` (float), `doi`, `title`, `authors`, `publication_name`, `publication_year` (`?int`), `url`, `match_reason` |
| `ReferenceFindingReviewData` | `id`, `status`, `confidence`, `reason`, `selected_candidate_id`, `is_manual`, `reviewed_by`, `reviewed_at`, `updated_at` |

All `#[MapName(SnakeCaseMapper::class)]`, `fromModel()` factories. `ReferenceFindingReviewData` is
used only by `PATCH /references/{reference}/finding`.

### 8.6 Citation DTOs — `App\Data\Citation\`

`CitationSummaryData` (§6 list item):

```php
#[MapName(SnakeCaseMapper::class)]
final class CitationSummaryData extends BaseData
{
    public function __construct(
        public string $id,
        public string $citationText,
        public ?string $citationMarker,
        public ?string $contextBefore,
        public ?string $contextAfter,
        public ?int $textStartOffset,
        public ?int $textEndOffset,
        public ?int $occurrenceIndex,
        public CitationStatus $status,                       // derived, never stored
        public ?CitationReferencePreviewData $reference = null,
    ) {}

    /** The caller passes the status from the resolver so the DTO never re-derives. */
    public static function forCitation(ResearchedDocumentCitation $citation, CitationStatus $status): self;
}
```

- `CitationDetailData` adds `locations: list<LocationPreviewData>`.
- `CitationReferencePreviewData`: `id`, `title`.
- `CitationPairingData` (PATCH response): `id`, `status` (`CitationStatus`), `reference`
  (`?CitationReferencePreviewData`).

Derivation of the status in the controllers (single resolver call):

```php
$status = $this->resolver->resolve(
    $citation->researched_document_reference_id !== null,
    $citation->reference?->finding?->status,
);
```

### 8.7 Query-service tests

`ReferenceQueryServiceTest`:
- `status=pending` returns both a `pending`-finding reference and a finding-less reference, and
  excludes `valid`.
- `status=valid` returns only `valid` (missing findings excluded).
- `has_doi=true/false` filtering, including empty-string tolerance.
- deterministic order by offset ASC with nulls last and id tiebreak.
- page size respected (`meta`).

`CitationQueryServiceTest`:
- **F-DERIV-01 (service half):** one fixture with all four pairings (unpaired, paired+pending,
  paired+valid, paired+invalid); filtering by each `CitationStatus` returns exactly the ids the
  PHP resolver derives.
- `reference_id` filter returns only that reference's citations.
- deterministic order and `meta`.

`ReferenceDataTest` / `CitationDataTest`:
- `fromModel` maps every field; `selected_candidate_id` is null when unset; snake_case output keys
  match `API_SPEC.md` (`selected_candidate_id`, `page_width`, `text_start_offset`, …).

---

## 9. T4 — `/references` and `/citations` endpoints

**New files:**
`app/Http/Controllers/Api/{ReferenceController,CitationController}.php`,
`app/Http/Requests/Reference/{ListReferencesRequest,UpdateReferenceFindingRequest}.php`,
`app/Http/Requests/Citation/{ListCitationsRequest,UpdateCitationRequest}.php`,
`app/Services/ReferenceFinding/ReferenceFindingReviewService.php`,
`app/Services/Citation/CitationPairingService.php`
**Modified:** `app/Exceptions/StateConflictException.php`, `routes/api.php`
**Tests:** `tests/Feature/{ReferenceEndpointTest,CitationEndpointTest,CitationPairingTest,ReferenceFindingReviewTest}.php`

### 9.1 Routes (`routes/api.php`, inside the existing `auth:sanctum` + `throttle:api` group)

```php
Route::get('/documents/{document}/references', [ReferenceController::class, 'index'])
    ->whereUuid('document')->name('documents.references.index');
Route::get('/references/{reference}', [ReferenceController::class, 'show'])
    ->whereUuid('reference')->name('references.show');
Route::patch('/references/{reference}/finding', [ReferenceController::class, 'updateFinding'])
    ->whereUuid('reference')->name('references.finding.update');

Route::get('/documents/{document}/citations', [CitationController::class, 'index'])
    ->whereUuid('document')->name('documents.citations.index');
Route::get('/citations/{citation}', [CitationController::class, 'show'])
    ->whereUuid('citation')->name('citations.show');
Route::patch('/citations/{citation}', [CitationController::class, 'update'])
    ->whereUuid('citation')->name('citations.update');
```

No new rate limiter: these fall under the general `api` limiter (60/min/user). Malformed UUIDs fall
through `whereUuid` and render the canonical 404.

### 9.2 FormRequests

`ListReferencesRequest` (`App\Http\Requests\Reference`):

| Field | Rules |
|---|---|
| `status` | `nullable`, `Rule::enum(ReferenceFindingStatus::class)` |
| `has_doi` | `nullable`, `boolean` (accepts `true/false/1/0`) |
| `page` | `nullable`, `integer`, `min:1` |
| `per_page` | `nullable`, `integer`, `min:1`, `max:100` |

Accessors: `status(): ?ReferenceFindingStatus`, `hasDoi(): ?bool` (`filter_var(..., FILTER_VALIDATE_BOOLEAN)`),
`perPage(): int` (default 15).

`ListCitationsRequest`:

| Field | Rules |
|---|---|
| `status` | `nullable`, `Rule::enum(CitationStatus::class)` |
| `reference_id` | `nullable`, `uuid` |
| `page` / `per_page` | as above |

Same-document validation for `reference_id` happens after the document is resolved (D-05-10), not in
the request.

`ListFindingsRequest` (§10): `type` (`Rule::enum(FindingType::class)`), `severity`
(`Rule::enum(FindingSeverity::class)`), pagination.

`UpdateReferenceFindingRequest`:

| Field | Rules |
|---|---|
| `status` | `required`, `Rule::enum(ReferenceFindingStatus::class)`, `Rule::notIn([ReferenceFindingStatus::Pending->value])` |
| `selected_candidate_id` | `nullable`, `uuid` |
| `reason` | `nullable`, `string`, `max:2000` |

Candidate membership is validated in the review service (needs the finding), not here.

`UpdateCitationRequest`:

| Field | Rules |
|---|---|
| `researched_document_reference_id` | `present`, `nullable`, `uuid` (D-05-11) |

### 9.3 `ReferenceController`

```php
final class ReferenceController extends Controller
{
    public function __construct(
        private readonly ReferenceQueryService $queryService,
        private readonly ReferenceFindingReviewService $reviewService,
        private readonly OwnedResourceFinder $finder,
    ) {}

    public function index(ListReferencesRequest $request, string $document): JsonResponse
    {
        $model = $this->finder->document($request->user(), $document);

        $paginator = $this->queryService->paginate(
            $model, $request->status(), $request->hasDoi(), $request->perPage(),
        );

        $paginator->through(
            fn (ResearchedDocumentReference $reference): ReferenceSummaryData
                => ReferenceSummaryData::forReference($reference),
        );

        return ApiResponse::collection($paginator, ReferenceSummaryData::class);
    }

    public function show(Request $request, string $reference): JsonResponse
    {
        $model = $this->finder->reference($request->user(), $reference);
        $model->load(['finding.candidates', 'locations', 'citations']);

        return ApiResponse::single(ReferenceDetailData::forReference($model));
    }

    public function updateFinding(UpdateReferenceFindingRequest $request, string $reference): JsonResponse
    {
        $model = $this->finder->reference($request->user(), $reference);

        $finding = $this->reviewService->review(
            $model,
            ReferenceFindingStatus::from($request->validated('status')),
            $request->validated('selected_candidate_id'),
            $request->validated('reason'),
            $request->user(),
        );

        return ApiResponse::single(
            ReferenceFindingReviewData::fromModel($finding),
            'Status referensi diperbarui.',
        );
    }
}
```

### 9.4 `ReferenceFindingReviewService`

```php
namespace App\Services\ReferenceFinding;

final class ReferenceFindingReviewService
{
    public function review(
        ResearchedDocumentReference $reference,
        ReferenceFindingStatus $status,
        ?string $candidateId,
        ?string $reason,
        User $reviewer,
    ): ReferenceFinding;
}
```

Algorithm:

```text
1. document = $reference->researchedDocument;
   if document->status === DocumentStatus::Processing
       → throw StateConflictException::findingReviewNotAllowed();
2. finding = ReferenceFinding::firstOrNew(['researched_document_reference_id' => $reference->id]);
   finding->researched_document_id = $reference->researched_document_id;
   (a new manual finding keeps confidence null, D-05-06)
3. finding->save() so candidate membership can be checked against a persisted id.
4. if candidateId !== null:
       exists = finding->candidates()->whereKey($candidateId)->exists()
       if (! exists) → throw ValidationException::withMessages([
           'selected_candidate_id' => ['Kandidat yang dipilih tidak valid untuk temuan ini.'],
       ]);
5. finding->status = $status;
   finding->selected_candidate_id = $candidateId;   // null clears
   finding->reason = $reason;
   finding->is_manual = true;
   finding->reviewed_by = $reviewer->getKey();
   finding->reviewed_at = now();
   finding->save();
6. return $finding->refresh()->load('candidates');
```

`StateConflictException` gains:

```php
public static function findingReviewNotAllowed(): self
{
    return new self('Status referensi tidak dapat diubah saat analisis sedang berjalan.');
}
```

### 9.5 `CitationController`

```php
final class CitationController extends Controller
{
    public function __construct(
        private readonly CitationQueryService $queryService,
        private readonly CitationPairingService $pairingService,
        private readonly CitationStatusResolver $resolver,
        private readonly OwnedResourceFinder $finder,
    ) {}

    public function index(ListCitationsRequest $request, string $document): JsonResponse
    {
        $model = $this->finder->document($request->user(), $document);

        $referenceId = $request->validated('reference_id');
        $this->pairingService->assertReferenceBelongsToDocument($model, $referenceId); // 422, D-05-10

        $paginator = $this->queryService->paginate(
            $model, $request->status(), $referenceId, $request->perPage(),
        );

        $paginator->through(function (ResearchedDocumentCitation $citation): CitationSummaryData {
            $status = $this->resolver->resolve(
                $citation->researched_document_reference_id !== null,
                $citation->reference?->finding?->status,
            );

            return CitationSummaryData::forCitation($citation, $status);
        });

        return ApiResponse::collection($paginator, CitationSummaryData::class);
    }

    public function show(Request $request, string $citation): JsonResponse
    {
        $model = $this->finder->citation($request->user(), $citation);
        $model->load(['reference.finding', 'locations']);

        return ApiResponse::single(CitationDetailData::forCitation($model, $this->statusFor($model)));
    }

    public function update(UpdateCitationRequest $request, string $citation): JsonResponse
    {
        $model = $this->finder->citation($request->user(), $citation);

        $paired = $this->pairingService->pair($model, $request->validated('researched_document_reference_id'));
        $paired->load('reference.finding');

        return ApiResponse::single(
            CitationPairingData::forCitation($paired, $this->statusFor($paired)),
            $paired->researched_document_reference_id !== null
                ? 'Sitasi berhasil ditautkan.'
                : 'Tautan sitasi berhasil dilepaskan.',
        );
    }

    private function statusFor(ResearchedDocumentCitation $citation): CitationStatus
    {
        return $this->resolver->resolve(
            $citation->researched_document_reference_id !== null,
            $citation->reference?->finding?->status,
        );
    }
}
```

### 9.6 `CitationPairingService`

```php
namespace App\Services\Citation;

final class CitationPairingService
{
    /** Pair/unpair and return the fresh model. Throws 422 for a foreign reference. */
    public function pair(ResearchedDocumentCitation $citation, ?string $referenceId): ResearchedDocumentCitation;

    /** 422 when a filter reference id is not of this document; null is a no-op. */
    public function assertReferenceBelongsToDocument(ResearchedDocument $document, ?string $referenceId): void;
}
```

Rules:

- `pair(..., null)` → sets the FK null, saves (unpair).
- `pair(..., $id)` → `ResearchedDocumentReference` must exist **and**
  `researched_document_id === $citation->researched_document_id`; otherwise
  `ValidationException::withMessages(['researched_document_reference_id' => ['The selected reference is invalid for this document.']])`
  (exact spec literal).
- `assertReferenceBelongsToDocument` uses the same check with the field key `reference_id` and the
  same spec literal, so a foreign/other-document reference and a non-existent one are
  indistinguishable (no existence disclosure).

### 9.7 Endpoint tests

`ReferenceEndpointTest`:
- **T-REF-01:** list with `status`/`has_doi` → filtered + paginated; invalid filter → `422`
  (`error.code = VALIDATION_ERROR`).
- **T-REF-02:** detail → finding (+ candidates, `is_manual`, audit fields) + locations +
  resolved citations matching `API_SPEC.md` §5.
- **T-REF-03:** PATCH sets `is_manual=true`, `reviewed_by`, `reviewed_at`, returns
  `"Status referensi diperbarui."`.
- **T-REF-04:** PATCH `status=pending`/bogus → `422`.
- **T-REF-05:** PATCH `selected_candidate_id` from another finding → `422`.
- **T-REF-06:** change the finding status then list citations → derived status changes for every
  paired citation (no cache).
- 409 while the document is `processing` (D-05-05).
- manually reviewing a reference with no finding creates a manual finding with `confidence = null`.

`CitationEndpointTest`:
- **T-CIT-01:** list `status`/`reference_id` filters, including `hallucination`; invalid filter → `422`.
- **T-CIT-02:** detail → locations match spec.
- **T-CIT-03:** pair an unpaired citation with a same-document reference → `200`, derived
  `valid`/`unreliable` per the reference finding.
- **T-CIT-04:** pair with a different-document reference → `422` with the exact spec message.
- **T-CIT-05:** unpair (`null`) → derived `hallucination`, message
  `"Tautan sitasi berhasil dilepaskan."`.
- **T-CIT-06..09:** derived statuses (paired valid/suspicious → `valid`; paired invalid/not_found →
  `unreliable`; paired pending → `pending`; unpaired → `hallucination`) in both list and detail.
- `reference_id` filter from another document → `422` (no 404 leak of that reference).

---

## 10. T5 — Findings / highlights feed

**New files:**
`app/Services/Findings/{FindingsFeedQuery,FindingsFeedComposer}.php`,
`app/Http/Controllers/Api/FindingsController.php`,
`app/Http/Requests/Finding/ListFindingsRequest.php`,
`app/Data/Finding/FindingHighlightData.php`
**Modified:** `routes/api.php`
**Tests:** `tests/Feature/FindingsEndpointTest.php`

### 10.1 Route

```php
Route::get('/documents/{document}/findings', [FindingsController::class, 'index'])
    ->whereUuid('document')->name('documents.findings.index');
```

### 10.2 `FindingHighlightData`

```php
#[MapName(SnakeCaseMapper::class)]
final class FindingHighlightData extends BaseData
{
    /**
     * @param  list<LocationPreviewData>  $locations
     */
    public function __construct(
        public string $id,                 // "ref-finding:{findingId}" | "citation:{citationId}"
        public FindingType $type,
        public FindingSeverity $severity,
        public ?string $message,
        public ?string $referenceId,
        public ?string $citationId,
        public ?string $text,
        public ?int $startOffset,
        public ?int $endOffset,
        public array $locations = [],
    ) {}

    /** @param list<LocationPreviewData> $locations */
    public static function fromFeedRow(object $row, array $locations): self;
}
```

`id` prefix is built in PHP (`source === 'reference' ? 'ref-finding:' : 'citation:'.entity_id`) so
the SQL stays portable (no `CONCAT`/`||`).

### 10.3 `FindingsFeedQuery`

Returns a `LengthAwarePaginator` of normalized rows (not DTOs). Reference side (LEFT-free, only real
findings per D-05-09):

```php
$references = DB::table('reference_findings as f')
    ->join('researched_document_references as r', 'r.id', '=', 'f.researched_document_reference_id')
    ->where('f.researched_document_id', $document->getKey())
    ->whereIn('f.status', [
        ReferenceFindingStatus::Invalid->value,
        ReferenceFindingStatus::Suspicious->value,
        ReferenceFindingStatus::NotFound->value,
        ReferenceFindingStatus::Pending->value,
    ])
    ->select([
        DB::raw("'reference' as source"),
        'f.id as entity_id',
        "CASE f.status WHEN 'suspicious' THEN 'reference_suspicious'"
            ." WHEN 'invalid' THEN 'reference_invalid'"
            ." WHEN 'not_found' THEN 'reference_not_found'"
            ." WHEN 'pending' THEN 'reference_pending' END as type",
        "CASE f.status WHEN 'suspicious' THEN 'medium'"
            ." WHEN 'invalid' THEN 'high'"
            ." WHEN 'not_found' THEN 'high'"
            ." WHEN 'pending' THEN 'info' END as severity",
        'f.reason as message',
        'f.researched_document_reference_id as reference_id',
        DB::raw('NULL as citation_id'),
        'r.raw_text as text',
        'r.text_start_offset as start_offset',
        'r.text_end_offset as end_offset',
        DB::raw('COALESCE(r.text_start_offset, 2147483647) as sort_offset'),
        'f.id as sort_id',
    ]);
```

Citation side (derived; the alias/expression is the shared resolver):

```php
$derived = $this->resolver->sqlExpression('c.researched_document_reference_id', 'f.status');

$citations = DB::table('researched_document_citations as c')
    ->leftJoin('researched_document_references as r', 'r.id', '=', 'c.researched_document_reference_id')
    ->leftJoin('reference_findings as f', 'f.researched_document_reference_id', '=', 'r.id')
    ->where('c.researched_document_id', $document->getKey())
    ->whereRaw("({$derived}) IN ('unreliable', 'hallucination')")
    ->select([
        DB::raw("'citation' as source"),
        'c.id as entity_id',
        "CASE WHEN ({$derived}) = 'hallucination' THEN 'citation_hallucination'"
            ." ELSE 'citation_unreliable' END as type",
        "CASE WHEN ({$derived}) = 'hallucination' THEN 'high'"
            ." WHEN f.status IN ('invalid','not_found') THEN 'high' ELSE 'info' END as severity",
        "CASE WHEN ({$derived}) = 'hallucination'"
            ." THEN 'Sitasi tidak memiliki pasangan referensi (hallucination).'"
            ." ELSE 'Sitasi merujuk pada referensi yang tidak berhasil diverifikasi (invalid/not_found).' END as message",
        'c.researched_document_reference_id as reference_id',
        'c.id as citation_id',
        'c.citation_text as text',
        'c.text_start_offset as start_offset',
        'c.text_end_offset as end_offset',
        DB::raw('COALESCE(c.text_start_offset, 2147483647) as sort_offset'),
        'c.id as sort_id',
    ]);
```

Wrapper + filters + pagination:

```php
return DB::query()
    ->fromSub($references->unionAll($citations), 'feed')
    ->when($type !== null, fn ($q) => $q->where('feed.type', $type->value))
    ->when($severity !== null, fn ($q) => $q->where('feed.severity', $severity->value))
    ->orderBy('feed.sort_offset')
    ->orderBy('feed.sort_id')
    ->paginate($perPage);
```

Notes:

- Both sides select the **same columns in the same order**; `unionAll` keeps column positions.
- `sort_offset = COALESCE(offset, 2147483647)` gives portable nulls-last ordering on all three
  engines; `sort_id` breaks ties (OQ-09). `id` is the lowercase UUID string, so lexicographic order
  is deterministic.
- `type`/`severity` filters are applied on the wrapper; invalid enum values never reach here (the
  request rejects them).
- `reference_pending` is included (OQ-09); `reference_valid` is excluded; `low` is never emitted
  by the mapping.
- Postgres note: `NULL as citation_id`/`NULL as reference_id` are resolved against the other union
  branch (both `varchar`); this is the portable form. See §14.5.

### 10.4 `FindingsFeedComposer`

```php
final class FindingsFeedComposer
{
    public function compose(LengthAwarePaginator $paginator): LengthAwarePaginator;
}
```

- Collect reference ids and citation ids from the page.
- Two batched location queries (`researched_document_reference_locations`,
  `researched_document_citation_locations`) with `whereIn(parent_id, ...)`, ordered by
  `location_index`, grouped by parent id.
- `$paginator->through(fn ($row) => FindingHighlightData::fromFeedRow($row, $locations[...]))`.
- Returns the same paginator instance so `ApiResponse::collection` emits the canonical `meta`.

### 10.5 `FindingsController`

```php
public function index(ListFindingsRequest $request, string $document): JsonResponse
{
    $model = $this->finder->document($request->user(), $document);

    $paginator = $this->feedQuery->paginate(
        $model, $request->type(), $request->severity(), $request->perPage(),
    );

    return ApiResponse::collection(
        $this->composer->compose($paginator),
        FindingHighlightData::class,
    );
}
```

### 10.6 Tests

`FindingsEndpointTest`:
- **T-FIND-01:** feed includes reference (`invalid`/`suspicious`/`not_found`/`pending`) and citation
  (`unreliable`/`hallucination`) types with correct `ref-finding:{findingId}` / `citation:{id}` ids
  and `reference_id`/`citation_id`.
- **T-FIND-02:** severity mapping — `not_found`/`invalid`/`hallucination` → `high`; `suspicious` →
  `medium`; `pending` → `info`; `citation_unreliable` inherits (high for invalid/not_found); `low`
  is never emitted.
- **T-FIND-03:** `type`/`severity` filters (valid → filtered; invalid → `422`).
- **T-FIND-04:** every item carries `locations` with page + bbox fields.
- ordering by `text_start_offset` ASC with an offset-less item last and `id` tiebreak.
- pagination `meta` correct across the union (`total` = sum of both sides after filters).
- `reference_valid` never appears.
- a document with no findings → empty `data`, `meta.total = 0`.

---

## 11. T6 — Full acceptance matrix, ownership and agreement

**Modified/new tests:** `tests/Feature/OwnershipIsolationTest.php` (HTTP additions),
`tests/Feature/CitationStatusAgreementTest.php`, plus any gaps from T4/T5.

### 11.1 Ownership (`T-OWN-05..07`, `T-OWN-09`)

Add HTTP assertions with two users to `OwnershipIsolationTest`:

- `GET /documents/{other}/references`, `/citations`, `/findings` → `404`
  `"Dokumen tidak ditemukan."`
- `GET /references/{other}` → `404` `"Referensi tidak ditemukan."`
- `PATCH /references/{other}/finding` → `404` (and the foreign finding is unchanged)
- `GET /citations/{other}`, `PATCH /citations/{other}` → `404` `"Sitasi tidak ditemukan."`
- For every case, compare the body to the same request against `fake()->uuid()` and assert it is
  byte-identical (no existence disclosure).

### 11.2 Agreement (`F-DERIV-01`)

`CitationStatusAgreementTest`:

- Build one document with four citations that produce all four derived statuses (unpaired;
  paired + `pending` finding; paired + `valid`; paired + `invalid`).
- For each `CitationStatus`, call `CitationQueryService::paginate(document, status)` and assert the
  returned ids equal the ids for which `CitationStatusResolver::resolve()` yields that status.
- Assert `DocumentSummaryService::forDocument()` buckets equal the per-status counts from the PHP
  resolver (the SQL summary path and the PHP path agree).

### 11.3 Acceptance matrix (this phase's rows)

| ID | Case | Covered by |
|---|---|---|
| T-REF-01 | List `status`/`has_doi`, invalid `422` | `ReferenceEndpointTest` |
| T-REF-02 | Reference detail (finding/candidates/locations/citations) | `ReferenceEndpointTest` |
| T-REF-03 | PATCH audit fields | `ReferenceFindingReviewTest` + `ReferenceEndpointTest` |
| T-REF-04 | PATCH invalid status `422` | `ReferenceEndpointTest` |
| T-REF-05 | PATCH candidate from another finding `422` | `ReferenceFindingReviewTest` |
| T-REF-06 | Finding change propagates to citation status | `CitationEndpointTest` |
| T-CIT-01 | List `status`/`reference_id` filters | `CitationEndpointTest` |
| T-CIT-02 | Citation detail locations | `CitationEndpointTest` |
| T-CIT-03 | Pair same-document | `CitationPairingTest` + `CitationEndpointTest` |
| T-CIT-04 | Pair different-document `422` + message | `CitationPairingTest` |
| T-CIT-05 | Unpair → `hallucination` | `CitationPairingTest` |
| T-CIT-06..09 | Derived status mapping | `CitationStatusTest` + `CitationEndpointTest` |
| T-FIND-01 | Feed types/ids | `FindingsEndpointTest` |
| T-FIND-02 | Severity mapping | `FindingsEndpointTest` |
| T-FIND-03 | `type`/`severity` filters | `FindingsEndpointTest` |
| T-FIND-04 | Locations present | `FindingsEndpointTest` |
| T-OWN-05..07 | Foreign references/citations/findings and PATCHes → `404` | `OwnershipIsolationTest` |
| F-DERIV-01 | SQL scope ↔ PHP resolver agreement | `CitationStatusAgreementTest` |

---

## 12. T7 — Documentation sync and final validation

- [ ] `docs/ARCHITECTURE.md` §13 — backend bullet: add citation resolution
  (`CitationMarkerParser`, `CitationResolver`, `ResolveCitationsStep`), the `/references`/
  `/citations`/`/findings` endpoints, manual review services and the findings feed; remove them from
  the **Missing** list (keep Phase 06 reports and the inference service).
- [ ] `AGENTS.md` §14 — same status update (citation resolution + review endpoints exist; missing
  list stays accurate).
- [ ] `docs/plans/backend/README.md` §3.1/§3.2 and §7/§8 — move citation resolution/endpoints from
  "missing" to "exists"; mark Phase 05 implemented in the phase map and traceability table.
- [ ] `docs/plans/backend/05-citation-resolution-and-review.md` — status header points at this
  detailed plan.
- [ ] `docs/API_SPEC.md` — **clarifying notes only, no shape change:**
  - unpair success message (`"Tautan sitasi berhasil dilepaskan."`),
  - manual review `409` while the document is `processing`,
  - `selected_candidate_id` → `422` when it does not belong to the finding,
  - reference/citation list and findings ordering = document position (offset ASC, nulls last, id
    tiebreak),
  - multi-reference markers resolve to the first/lowest ordinal (v1 limitation),
  - `reference_pending` already listed in the `type` filter (OQ-09) — confirm it stays.
- [ ] Confirm **no** `docs/DB_SCHEMA.md` change.
- [ ] `php artisan test --compact` (full suite) + `vendor/bin/pint --dirty --format agent`.

---

## 13. Files touched (summary)

```text
backend/app/
├── Data/
│   ├── Citation/                        +   CitationSummaryData, CitationDetailData,
│   │                                        CitationReferencePreviewData, CitationPairingData,
│   │                                        CitationOccurrenceData
│   ├── Finding/                         +   FindingHighlightData
│   ├── Location/                        +   LocationPreviewData (shared, D-05-08)
│   ├── Reference/                       +   ReferenceSummaryData, ReferenceDetailData
│   └── ReferenceFinding/                +   ReferenceFindingPreviewData, ReferenceFindingDetailData,
│                                            ReferenceFindingCandidatePreviewData,
│                                            ReferenceFindingReviewData
├── Exceptions/StateConflictException    ~   + findingReviewNotAllowed()
├── Http/
│   ├── Controllers/Api/                 +   ReferenceController, CitationController, FindingsController
│   └── Requests/
│       ├── Reference/                   +   ListReferencesRequest, UpdateReferenceFindingRequest
│       ├── Citation/                    +   ListCitationsRequest, UpdateCitationRequest
│       └── Finding/                     +   ListFindingsRequest
├── Providers/AnalysisServiceProvider    ~   append ResolveCitationsStep
├── Services/
│   ├── Analysis/Steps/                  +   ResolveCitationsStep
│   ├── Citation/                        +   CitationQueryService, CitationPairingService
│   ├── Citations/                       +   CitationMarkerParser, ParsedCitationMarker,
│   │                                        CitationReference, CitationResolution,
│   │                                        CitationResolver, CitationMatchConfig
│   │                                        (reuses existing CitationStatusResolver)
│   ├── Findings/                        +   FindingsFeedQuery, FindingsFeedComposer
│   ├── Reference/                       +   ReferenceQueryService
│   └── ReferenceFinding/                +   ReferenceFindingReviewService
│                                            (ReferenceFindingWriter untouched, D-05-12)

backend/config/scoring.php               ~   + citation_matching.surname_threshold
backend/.env.example                     ~   + SCORING_CITATION_SURNAME_THRESHOLD
backend/routes/api.php                   ~   + 7 routes (references/citations/findings)
backend/tests/                            NEW unit/feature suites + harness updates (T1–T6)
```

No migration, no `Http/Resources`, no schema/DTO-envelope change, no new Composer/npm dependency.
`ReferenceFindingWriter` and `CitationStatusResolver` are untouched.

---

## 14. Risks, pitfalls and deliberate deviations

### 14.1 Pitfalls to avoid

1. **A second status mapping.** Never add another `CASE`/`match` for citation status. DTO, filter,
   summary and feed all go through `CitationStatus` / `CitationStatusResolver`.
2. **Persisting derived data.** No `status`/`severity` column on citations; the feed is computed.
3. **Owning validation before ownership.** Resolve through `OwnedResourceFinder` first; only then
   run same-document/enum checks, so foreign ids stay `404` (D-05-10).
4. **A second writer for findings.** Automated verdicts go through `ReferenceFindingWriter`; manual
   review goes through `ReferenceFindingReviewService` and must not call the writer.
5. **Cross-document pairing.** `CitationResolver` only receives the document's references; the
   manual path re-checks `researched_document_id`. Never trust a client-supplied reference id.
6. **Byte-based comparison / naive author splitting.** Reuse `StringSimilarity` and
   `AuthorMatcher::surnames()`; do not hand-roll.
7. **Treating a missing signal as `0`.** Year/surname `null` means "no signal", not a mismatch
   (except an explicit year outside tolerance, which is a real mismatch).
8. **Over-eager parsing.** An unparseable marker stays unpaired (prefer `hallucination` over a wrong
   pairing). Never guess.
9. **N+1.** Eager-load `finding`/`reference.finding` in the query services; batch locations in the
   feed composer; assert query counts in tests where practical.
10. **Non-portable union SQL.** Keep both sides' column lists identical, build the `id` prefix in
    PHP, order via `COALESCE(offset, 2147483647)`, and avoid `CONCAT`/`||`.
11. **`selected_candidate_id` FK.** Manual review sets it to a candidate of the same finding (or
    `null`); the automated writer's null-before-replace rule must not be reintroduced here.
12. **`env()` outside config.** Read citation thresholds through `CitationMatchConfig` /
    `ScoringConfig`, never directly.

### 14.2 Deliberate deviations from the broad plan (accepted, documented)

1. **D-05-01** — the GROBID `reference_index` hint is not used; resolution is marker/order-based.
2. **D-05-02** — `CitationMatchConfig` shares `ScoringConfig::yearTolerance()` instead of owning a
   second year tolerance.
3. **D-05-03** — child lists and the feed use document-position order, a documented exception to the
   generic `created_at desc` default.
4. **D-05-08** — one `LocationPreviewData` instead of the broad plan's per-entity location DTOs.
5. **D-05-09** — the feed contains only real findings (no synthesized id for finding-less
   references).

### 14.3 Rollback

Each task is a self-contained commit with no migration and no backfill. Reverting T2 restores the
`ResolvingCitations` stub and the pipeline completes with unpaired citations (the Phase 04 end
state). Reverting T4/T5 removes only the new endpoints and read model; the pipeline still resolves
citations. No data migration is ever required.

### 14.4 Scaling path (documented, not implemented)

Resolution is O(citations × references) with pure string comparisons and one transaction; for a
document with thousands of references/citations this is bounded and fast. If it ever becomes hot,
index references by parsed surname before the loop and/or cap candidate references per citation
without changing the resolver contract. The findings feed is a paginated union with two batched
location queries; no materialisation is needed in v1.

### 14.5 Engine portability note

The union wrapper and `COALESCE` are portable across SQLite/MySQL/Postgres. The `NULL as citation_id`
/ `NULL as reference_id` placeholders resolve against the opposite branch's `varchar` type; if a
future Postgres run complains, replace them with `CAST(NULL AS VARCHAR)`. This is recorded here
rather than pre-emptively complicating the SQL.

---

## 15. Hand-off contract for Phase 06+

Phase 06 (reports) may rely on, and must not duplicate:

| Primitive | Location | Usage rule |
|---|---|---|
| Derived status | `CitationStatus::derive()` / `CitationStatusResolver` | reuse in the report; do not re-map |
| Reference/citation read model | `ReferenceQueryService`, `CitationQueryService` | reuse for report sections; do not re-query ad-hoc |
| Manual review audit | `ReferenceFindingReviewService` | the only manual writer; report reads the fields |
| Findings feed | `FindingsFeedQuery`/`Composer` | the report may reuse the query for "citation issues"; do not re-implement severity |
| Location shape | `App\Data\Location\LocationPreviewData` | reuse; do not fork |
| Pipeline step registry | `AnalysisServiceProvider` | Phase 06 only adds report generation; it does not touch `resolving_citations` |

Phase 06 must generate reports only for `completed` documents (FR-F5) and must not add citation
columns or persist derived status.

---

## 16. Open questions / ambiguities (with recommended defaults)

The plan proceeds with the recommended answer for each. Confirm or redirect; Q-1/Q-2/Q-3/Q-6 change
implementation shape, the rest change messages/ordering notes.

| # | Question | Recommendation (adopted above) |
|---|---|---|
| **Q-1** | The extraction contract carries a per-citation `reference_index` (GROBID's bibliography hint) that `PersistExtractionStep` currently discards. Should resolution use it? | **No for v1** (D-05-01): resolve from persisted reference metadata + marker parsing + deterministic bibliography order. The hint has no column and is an unvalidated payload field; using it would couple resolution to `PersistExtractionStep` internals. If wanted later, the robust path is a transient `AnalysisContext` artefact built at persistence time (payload order → reference id map), **not** a schema change, and only as a fallback when no marker parses. |
| **Q-2** | Default ordering of `GET /documents/{document}/references` and `/citations`. | **Document position** (`text_start_offset` ASC nulls last, `id` tiebreak), matching OQ-09/OQ-18 and the viewer's reading order (D-05-03). Alternative: the generic `created_at desc` default; this would need less of an `API_SPEC.md` note but makes the bibliography list harder to consume. |
| **Q-3** | Multi-reference citations (`[3], [5]`, `[3–5]`, `(A, 2020; B, 2021)`) vs the one-reference schema. | **Pair with the first/lowest ordinal** (or first APA pair); leave the rest unrepresented; document as a v1 limitation (D-05-04). Alternative: split such a citation into multiple rows during resolution (would need a schema/contract decision and per-occurrence deduplication). |
| **Q-4** | Success message when unpairing `PATCH /citations/{citation}`. | `"Tautan sitasi berhasil dilepaskan."` (D-05-07); spec only documents the pairing message, so this gets an `API_SPEC.md` note. |
| **Q-5** | `409` and `422` messages not given by the spec (review while processing, candidate not in finding). | `"Status referensi tidak dapat diubah saat analisis sedang berjalan."` and `"Kandidat yang dipilih tidak valid untuk temuan ini."` (D-05-05/D-05-13); centralized in `StateConflictException` and the review service. |
| **Q-6** | Should the findings feed include references with no `reference_findings` row (shown as `reference_pending` in the reference list)? | **No** (D-05-09): the canonical id is `ref-finding:{findingId}`, which has no value without a finding. Finding-less references remain visible via the reference list `status=pending` filter. Alternative: synthesize `ref-finding:{referenceId}` (breaks the documented id contract) or change the reference-list filter to exclude missing findings (breaks the broad plan §3.3). |
| **Q-7** | `citation_unreliable` severity is effectively always `high` (only `invalid`/`not_found` produce `unreliable`). | Implement the documented inheritance generically via the paired finding's `toSeverity()` (so a future mapping change is safe), but do not invent a `medium`/`low` unreliable case. No spec change. |
