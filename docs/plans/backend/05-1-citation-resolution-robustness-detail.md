# Phase 05.1 — Citation Resolution Robustness (Detailed Implementation Plan)

> **Status:** implemented (all workstreams W1/W2; seed evaluation baseline recorded in
> `tests/Feature/CitationResolutionEvaluatorTest.php`)
> **Parent:** [`05-citation-resolution-and-review-detail.md`](05-citation-resolution-and-review-detail.md)
> **Depends on:** Phase 05 (implemented) · **Unblocks:** Phase 06/07
> Canonical references: `docs/API_SPEC.md` §2.6/§4/§5/§6/§7/§12, `docs/DB_SCHEMA.md` (first DBML
> block), `docs/ARCHITECTURE.md` §7/§9/§13, `docs/PRODUCT_REQUIREMENTS.md` §6.4/§6.5,
> `docs/TEST_PLAN.md` §5.5/§5.6, `docs/SECURITY.md` §2, `AGENTS.md` §2/§5/§6/§8/§10/§14/§20.
> Task template: `docs/plans/backend/README.md` §1.4.

Phase 05 made citation resolution *deterministic*, but not *trustworthy*: any unpaired citation
becomes a **high-severity `hallucination`**, so every parser/resolver miss is a false accusation,
and the extractor's own citation→bibliography link (`reference_index`) is discarded. This plan
closes that trust gap.

**In scope:** resolution-signal quality (author initials, soft year, multi-marker parsing, extraction
hints), a two-pass batch resolver, a third `unresolved` outcome with persisted candidates and
provenance, and a seed evaluation harness.

**Out of scope (decided):**
- **Q5 = C** — no semantic/context matching (SPECTER/SBERT context↔title) in 05.1. The resolver
  keeps the seam so it can be added later without moving the decision layer.
- No LLM-based mapping.
- No citation-row splitting for multi-reference markers (Q7 = C: candidates instead).
- No new inference endpoint or SBERT/SPECTER change.

> **Read first:** the ordering decision §4 D-05.1-06 — W1 deliberately keeps the existing
> best-candidate-commits behavior and defers the ambiguity/margin policy to W2, so no interim W1
> release ever *increases* `hallucination` counts.

---

## 1. Baseline (verified against the repository at plan creation)

| Item | Value |
|---|---|
| Pure engine | `CitationMarkerParser` (first pair/first ordinal only), `CitationResolver` (`resolve(ParsedCitationMarker, list<CitationReference>)`), `CitationMatchConfig` (`surname_threshold` 0.85, `yearTolerance()` delegates to `ScoringConfig`) |
| Value objects | `ParsedCitationMarker { surnames: list<string>, year: ?int, ordinals: list<int> }`, `CitationReference { id, authors: ?string, publicationYear: ?int, bibliographyIndex }`, `CitationResolution { referenceId, confidence, matchReason }` |
| Author parsing | `AuthorMatcher::surnames()` strips initials; used by both reference scoring and the citation parser |
| Pipeline | `ResolveCitationsStep` reads persisted citations/references, parses each marker, resolves, then nulls + rewrites `researched_document_reference_id` in one transaction |
| Extraction hint | `ExtractedCitationData::$referenceIndex` is mapped from the payload but **discarded** by `PersistExtractionStep`; nothing consumes it |
| Status | `CitationStatus::{valid,unreliable,pending,hallucination}` + `derive(bool $isPaired, ?ReferenceFindingStatus)`; `CitationStatusResolver::sqlExpression($pairedColumn, $findingStatusColumn)` used by `CitationQueryService`, `DocumentSummaryService`, `FindingsFeedQuery` |
| Derived feed | `FindingType::{reference_*,citation_unreliable,citation_hallucination}`; citation severity hardcoded in `FindingsFeedQuery` (`hallucination` → high, else inherit) |
| Summary | `DocumentAnalysisSummaryData` has `total/valid/unreliable/pending/hallucination_citations`; `DocumentSummaryService::citationCountSelect()` iterates `CitationStatus::cases()` |
| Schema | `researched_document_citations` has only `researched_document_reference_id` for pairing (no state/method/confidence/hint columns); no candidate table; confidence columns elsewhere are `decimal(5,4)` |
| IDs | `PersistExtractionStep` bulk-inserts references/citations with `Str::uuid()` (**random v4**), while Eloquent-created models use `HasUuids` → `Str::uuid7()`. `ResolveCitationsStep` orders references by `text_start_offset IS NULL, text_start_offset, id`, so null offsets fall back to a random v4 order → IEEE `[n]` is effectively arbitrary when offsets are missing |
| Config | `scoring.citation_matching.surname_threshold`; `.env.example` has `SCORING_CITATION_SURNAME_THRESHOLD` |
| Tests | `CitationMarkerParserTest`, `CitationResolverTest`, `CitationMatchConfigTest`, `ResolveCitationsStepTest`, `CitationQueryServiceTest`, `CitationStatusAgreementTest` (F-DERIV-01), endpoint/pairing/review suites |
| Frontend | **Not wired to the API** (mocked store, prototype types) → additive enum/field changes are cheap now |

Facts that constrain the implementation:

- `CitationStatus::derive()` currently takes `bool $isPaired`; the fifth status needs a persisted
  resolution state because the read path cannot recompute the resolver outcome.
- `DocumentSummaryService` and `FindingsFeedQuery` both read `CitationStatus::cases()` / the SQL
  expression, so adding a case automatically widens the summary buckets and the feed.
- GROBID provides **no confidence** for `reference_index` (user-confirmed), so hint trust must be a
  validated prior, never a score.
- The frontend not being wired means `unresolved` + additive fields do not require a migration of
  consumers — only the canonical specs must be updated deliberately.

---

## 2. Execution model

### 2.1 Workstreams and task order

The plan is gated: **W1 changes no API contract and no schema** and can merge on its own; **W2 is the
deliberate contract + schema change** that turns resolution quality into product semantics.

| WS | Task | Deliverable | Size | Depends on | Suggested commit |
|---|---|---|---|---|---|
| — | **T0** | Preflight & baseline | S | — | (no commit) |
| W1 | **T1** | Structured author names + `CitationAuthorParser`/`AuthorMatcher::names()` + multi-marker `ParsedCitationMarker` | L | — | `feat(backend): parse citation authors with initials and multiple markers` |
| W1 | **T2** | Soft-year + initials scoring, new `citation_matching` config, best-candidate decision (no ambiguity policy yet) | M | T1 | `feat(backend): score citation matches with soft year and initials` |
| W1 | **T3** | Extraction-hint artifact (`CitationExtractionHints`), ordered UUIDs for bulk inserts, `ResolveCitationsStep` wiring | M | T2 | `feat(backend): consume extraction reference hints in citation resolution` |
| W1 | **T4** | `CitationBatchResolver` + cross-citation evidence consolidation | M | T3 | `feat(backend): consolidate citation evidence across markers` |
| W1 | **T5** | Evaluation harness (`citations:evaluate`) + seed dataset + calibration notes | L | T4 | `feat(backend): add citation resolution evaluation harness` |
| — | **Gate** | W1 exit criteria green; mergeable without contract change | S | T1–T5 | (verify) |
| W2 | **T6** | Migration: resolution columns + `citation_resolution_candidates`; enums; models; `DB_SCHEMA.md` | M | Gate | `feat(backend): persist citation resolution state and provenance` |
| W2 | **T7** | `CitationResolutionWriter` (automated, bulk), candidates, reset-service update, step writes | L | T6 | `feat(backend): persist citation resolution candidates` |
| W2 | **T8** | `CitationStatus::Unresolved`, `derive`/SQL/summary/feed/severity updates | M | T7 | `feat(backend): derive unresolved citation status` |
| W2 | **T9** | API surface: DTOs, query eager-loading, manual pairing provenance, `API_SPEC.md` | L | T8 | `feat(backend): expose citation resolution detail and candidates` |
| — | **T10** | Docs sync + full-suite exit validation | S | T1–T9 | `docs: sync citation resolution robustness status` |

W1 tasks T1/T2 can start in parallel with nothing else; T3 needs T2; T4 needs T3; T5 needs T4.
W2 is strictly sequential. T6 (schema) must land before T7/T8/T9 so the derived-status tests never
run against half a schema.

### 2.2 Per-task loop

1. Re-read the task and the canonical spec section it cites.
2. Implement the task and its tests in the **same** change.
3. `vendor/bin/pest <affected paths>` — affected tests only.
4. `vendor/bin/pint --dirty --format agent` — format changed PHP files.
5. `php artisan test --compact` — full suite stays green.
6. W1 leaves `docs/API_SPEC.md`/`docs/DB_SCHEMA.md` untouched; only T6/T9/T10 touch them.

### 2.3 Exit criteria

**W1 (no contract/schema change):**
- Multi-marker markers are fully captured (all author/year pairs, all ordinals) and the resolver
  scores every pair; initials are used when both sides expose them.
- Year is a soft signal; a wrong-year candidate is penalized, not hard-rejected, and missing years
  renormalize the weights.
- The extraction hint is consumed when valid: hint-only citations pair, hint contradictions fall
  back to the parser, and an invalid index is ignored.
- Bulk-inserted references/citations use ordered UUIDs so null-offset IEEE order follows payload
  order.
- Evidence consolidation resolves at least the "year present in one marker, missing in a sibling"
  class of citations; it never drops an already-committed pair.
- `citations:evaluate` runs on the seed dataset and prints precision/recall/F1, resolution-state
  distribution and per-method breakdown.
- `php artisan test --compact` green; no API/schema file changed.

**W2 (contract + schema change):**
- `unresolved` is a first-class derived status, severity `medium`, in the feed type filter and the
  document summary; `hallucination` means "no plausible candidate exists".
- Ambiguous citations (winner margin below threshold) become `unresolved` **with persisted
  candidates** instead of a forced pairing.
- `PATCH /citations/{citation}` records `resolution_method = manual` for pair and unpair; automated
  runs are attributed to `extraction_hint`/`apa`/`ieee`.
- `docs/API_SPEC.md` and `docs/DB_SCHEMA.md` updated in the same change; no other contract changes.
- F-DERIV-01 covers all five statuses; ownership isolation unchanged (candidates are nested only).
- `php artisan test --compact` green; `vendor/bin/pint --dirty --format agent` reports nothing.

---

## 3. Conventions this phase locks in

Reused from Phases 01–05:

- One business rule per class; derivation only through `CitationStatus` / `CitationStatusResolver`.
- Controllers are transport only; the pipeline owns automated writes, services own manual writes.
- Ownership via `OwnedResourceFinder`; foreign ids stay `404`.
- Thresholds/limits in `config/scoring.php`; no literals in services.
- `final class`, promoted constructor properties, explicit types, PHPDoc over inline comments.
- Deterministic ordering everywhere (stable sort + tiebreaker).

Phase-05.1-specific conventions:

- **The engine stays pure.** `CitationMarkerParser`, `CitationCandidateScorer`,
  `CitationDecisionPolicy`, `CitationBatchResolver` and the evaluation evaluator take value objects
  and return value objects — no Eloquent, no DB, no container.
- **One author parser.** Structured author parsing extends `AuthorMatcher` (`names()`); the
  citation domain does not fork a second surname parser. `AuthorMatcher::surnames()` keeps its
  current output exactly (regression-tested).
- **The hint is a prior, not a score.** No fabricated confidence for a hint (GROBID provides none).
  A trust switch (`citation_matching.trust_extraction_hint`) can disable it.
- **Never drop a committed pair in W1.** Ambiguity handling that could turn a paired citation into
  an unpaired one only lands with the `unresolved` state (W2).
- **Candidate and provenance writes are bulk.** One transaction per document, chunked inserts
  (mirroring `ReferenceFindingWriter`/`PersistExtractionStep`).
- **All user-facing unresolved/hint strings are centralized** in the decision policy and the feed
  message builder; no string literals in controllers.

---

## 4. Decision log additions

These extend the README `Decision log` and the Phase 04/05 additions. D-05.1-01…05, 09 are
contract/schema decisions and are implemented only in W2; the rest are W1.

| ID | Question | Decision |
|---|---|---|
| **D-05.1-01** | How does a resolver miss stop being a high-severity hallucination? | Add a fifth derived status `unresolved` (`CitationStatus::Unresolved`), feed type `citation_unresolved`, severity `medium` (Q1 = A). `hallucination` is reserved for "no plausible candidate exists at all". Requires `docs/API_SPEC.md` §2.6/§6/§7 and the document summary shape. |
| **D-05.1-02** | Do unresolved citations carry persisted alternatives? | Yes: `citation_resolution_candidates` mirrors `reference_finding_candidates` (rank, confidence, method/source, reason, reference FK) and manual selection stays on `PATCH /citations/{citation}` (Q2 = A). Absorbs multi-reference markers without splitting citation rows. |
| **D-05.1-03** | How much is the GROBID `reference_index` trusted (no confidence available)? | Validated strong prior (Q3 = C): trust the hint when the index is in range and the referenced entry exists; if the parsed marker plausibly contradicts the hinted entry, fall back to the parser result and keep the hint as a candidate; if the marker is unparseable, trust the hint. Config kill switch `citation_matching.trust_extraction_hint`. |
| **D-05.1-04** | Is resolution provenance persisted? | Yes (Q4 = C): `resolution_state`, `resolution_method`, `resolution_confidence`, `extraction_reference_index` on citations. Provenance is legitimate persisted data (not derived status), needed for research reporting and re-resolution/audit. |
| **D-05.1-05** | Semantic/context matching? | No (Q5 = C). `context_before`/`context_after` stay unused for now; the `CitationCandidate`/method seam is documented so a SPECTER/context signal can be added later without touching the decision layer. |
| **D-05.1-06** | Two-pass "global" assignment? | Q6 = A, reframed honestly: citations → references has **unlimited capacity**, so true bipartite assignment does not apply. The two passes are (1) direct decisions (hint, IEEE ordinal, high-score APA) and (2) cross-citation **evidence consolidation** for leftovers (propagate unambiguous years/initials across same-surname markers). Ambiguity/margin detection and the `unresolved` outcome land in **W2 only** so W1 never increases hallucination counts. |
| **D-05.1-07** | Multi-reference markers (`[3], [5]`, `[3–5]`, `(A, 2020; B, 2021)`). | Parse **all** pairs/ordinals; commit the best primary and persist the rest as candidates (Q7 = C). No citation-row splitting (keeps counts/offsets/summary stable). |
| **D-05.1-08** | Evaluation in 05.1? | Yes, a seed harness (Q8 = A): `citations:evaluate` + JSON dataset + metric unit tests. Real labeled data and final calibration stay in Phase 07. |
| **D-05.1-09** | Plan shape. | One plan, two gated workstreams: W1 no contract/schema change, W2 contract/schema (Q9 = A). |
| **D-05.1-10** | Deterministic IEEE order when offsets are missing. | Bulk-insert references/citations with `Str::orderedUuid()` (v7) instead of `Str::uuid()` (v4) so `id` order follows payload order; this fixes the random tiebreak with no schema change. `text_start_offset` stays the primary key of the OQ-18 order. |
| **D-05.1-11** | One author parser or two? | Extend `AuthorMatcher` with `names(): list<AuthorName>` (surname + initials) and make `surnames()` delegate with identical output; the citation engine consumes `names()`. `AuthorMatcher` regression tests must stay green. |
| **D-05.1-12** | Threshold/weight defaults. | New `citation_matching` keys (`weights`, `commit_threshold`, `proposal_threshold`, `winner_margin`, `year_window`, `initial_penalty`, `max_candidates`, `trust_extraction_hint`); defaults are **provisional** and calibrated by T5/Phase 07 (mirrors OQ-12). `surname_threshold` is removed in W1 and its `.env` key retired. |
| **D-05.1-13** | Manual pairing provenance. | `CitationPairingService` sets `resolution_method = manual` for both pair and unpair; unpair sets `resolution_state = unmatched`. Manual picks keep candidates for context. |
| **D-05.1-14** | Status of a citation that was resolved but whose reference is later re-resolved. | Citation state is independent of the finding; the derived status keeps combining `resolution_state` with the reference finding status, so a manual finding change still propagates immediately (no persisted citation status — `AGENTS.md` §10 unchanged). |

---

## 5. T0 — Preflight & baseline

- [ ] `cd backend && composer install` (if `vendor/` is stale).
- [ ] `php artisan test --compact` → all Phase 05 tests pass (404 tests at plan creation).
- [ ] `git status` clean / expected branch (`feat/citation-resolution-robustness`).
- [ ] Confirm `ExtractedCitationData::$referenceIndex` is still discarded by `PersistExtractionStep`.
- [ ] Confirm `PersistExtractionStep` uses `Str::uuid()` for bulk inserts.
- [ ] Confirm no `citation_resolution_*` table/columns exist (`php artisan db:show --counts` or the migration list).
- [ ] Confirm GROBID exposes no hint confidence (user-confirmed; the `API_SPEC.md` §9 contract carries only `reference_index`).
- [ ] Re-read §4 and confirm Q1–Q9 defaults still hold; record any change in §15.

No commit for T0.

---

## 6. W1 — Resolution engine, hints and evaluation (no contract/schema change)

### 6.1 T1 — Structured author names and multi-marker parsing

**New files:** `app/Services/Scoring/AuthorName.php`,
`app/Services/Citations/ParsedAuthorYear.php`
**Modified:** `app/Services/Scoring/AuthorMatcher.php`,
`app/Services/Citations/{ParsedCitationMarker,CitationMarkerParser,CitationReference,CitationResolver}.php`
**Tests:** extend `tests/Unit/AuthorMatcherTest.php`; extend `tests/Unit/CitationMarkerParserTest.php`;
`tests/Feature/CitationResolverTest.php`

#### `AuthorName`

```php
namespace App\Services\Scoring;

final class AuthorName
{
    /** @param string|null $initials uppercase letters, e.g. "DB" */
    public function __construct(
        public readonly string $surname,
        public readonly ?string $initials = null,
    ) {}

    public function firstInitial(): ?string; // first letter of $initials, or null
}
```

#### `AuthorMatcher::names()`

- Add `names(?string $authors): list<AuthorName>` that preserves the current surname logic and
  additionally captures the given-name/initial tokens it currently discards.
- `surnames()` becomes `array_map(fn (AuthorName $n) => $n->surname, $this->names($authors))`; its
  output must be byte-identical for every existing `AuthorMatcherTest` case (regression gate).
- Parsing rules (same separators as today: `&`, `;`, `and`, `dkk.`, commas):
  - multi-token chunk (`Koten, D. B.`): surname = first token, initials = letters of the remaining
    tokens (`DB`);
  - `Given Family` → surname = last word, initials from the preceding words;
  - lowercase particles (`van der`, `de la`) stay attached to the surname;
  - `et al.`/`dkk.` truncated as today.
- `null`/empty → `[]`.

#### Multi-marker `ParsedCitationMarker`

```php
final class ParsedAuthorYear
{
    /** @param list<AuthorName> $authors */
    public function __construct(
        public readonly array $authors,
        public readonly ?int $year = null,
    ) {}
}

final class ParsedCitationMarker
{
    /**
     * @param list<ParsedAuthorYear> $pairs  every APA pair in marker order
     * @param list<int> $ordinals            every IEEE ordinal, ascending, unique
     */
    public function __construct(
        public readonly array $pairs = [],
        public readonly array $ordinals = [],
    ) {}

    public function isIeee(): bool;
    public function isApa(): bool;
    public function isParsed(): bool;
    public function primaryPair(): ?ParsedAuthorYear; // first pair, or null
}
```

`CitationMarkerParser` changes:

- APA parenthetical content is split on `;` into **all** pairs (`(A, 2020; B, 2021)` → two pairs);
  a pair without a year (`(A; B, 2020)`) keeps `year = null`.
- The `firstAuthorYearSegment()` limitation is removed.
- IEEE keeps every ordinal (ranges expand as today).
- Names are parsed with `AuthorMatcher::names()`.
- Still conservative: an unparseable marker yields an empty marker; never invent a pair.

`CitationReference` changes from `authors: ?string` to pre-parsed
`authors: list<AuthorName>`; `fromModel()` (still in the step) parses once per reference so the
batch resolver does not re-parse per citation.

### 6.2 T2 — Soft-year + initials scoring and thresholds

**New files:** `app/Services/Citations/CitationCandidateScorer.php`
**Modified:** `app/Services/Citations/{CitationResolver,CitationMatchConfig}.php`,
`config/scoring.php`, `.env.example`
**Tests:** `tests/Unit/CitationCandidateScorerTest.php`, extend `tests/Feature/CitationResolverTest.php`
and `tests/Feature/CitationMatchConfigTest.php`

`config/scoring.php` `citation_matching` (D-05.1-12):

```php
'citation_matching' => [
    // Weighted signals (must sum to 1.0); missing signals renormalize.
    'weights' => [
        'surnames' => (float) env('SCORING_CITATION_WEIGHT_SURNAMES', 0.70),
        'year' => (float) env('SCORING_CITATION_WEIGHT_YEAR', 0.30),
    ],
    // Commit an APA pairing at/above this combined score.
    'commit_threshold' => (float) env('SCORING_CITATION_COMMIT_THRESHOLD', 0.90),
    // Offer a candidate (W2 `unresolved`) at/above this score; below = no plausible match.
    'proposal_threshold' => (float) env('SCORING_CITATION_PROPOSAL_THRESHOLD', 0.50),
    // A committed pair must beat the runner-up by this margin (W2 only, D-05.1-06).
    'winner_margin' => (float) env('SCORING_CITATION_WINNER_MARGIN', 0.08),
    // Year distance at which the year signal reaches 0 (1.0 at 0 years).
    'year_window' => (int) env('SCORING_CITATION_YEAR_WINDOW', 5),
    // Subtracted from the surname signal when both sides expose a first initial and they differ.
    'initial_penalty' => (float) env('SCORING_CITATION_INITIAL_PENALTY', 0.15),
    // Max alternatives stored/exposed per citation (W2).
    'max_candidates' => (int) env('SCORING_CITATION_MAX_CANDIDATES', 3),
    // Trust GROBID's reference_index when validated (D-05.1-03).
    'trust_extraction_hint' => (bool) env('SCORING_CITATION_TRUST_EXTRACTION_HINT', true),
],
```

`.env.example` gains the commented equivalents (including the retirement note for
`SCORING_CITATION_SURNAME_THRESHOLD`, which is removed).

`CitationMatchConfig` exposes typed accessors: `weights()` (validated to sum to 1.0 ± 0.001, like
`ScoringConfig::weights()`), `commitThreshold()`, `proposalThreshold()`, `winnerMargin()`,
`yearWindow()`, `initialPenalty()`, `maxCandidates()`, `trustExtractionHint()`, and keeps
`yearTolerance()`? — **removed**: the hard gate is gone; `yearTolerance` is no longer a citation
concept. (Implementation note: `CitationResolver` and its tests stop calling it.)

`CitationCandidateScorer` (pure):

```php
final class CitationCandidateScorer
{
    public function __construct(
        private readonly CitationMatchConfig $config,
        private readonly StringSimilarity $strings,
    ) {}

    /**
     * @param  list<AuthorName>  $citedAuthors
     * @param  list<CitationReference>  $references
     * @return list<CitationCandidate>  scored, ranked, only score >= proposalThreshold
     */
    public function score(ParsedAuthorYear $pair, array $references): array;
}
```

Signals per reference:

| Signal | Computation |
|---|---|
| `surnames` | best-pair average Jaro-Winkler between cited and reference author names (order-insensitive, greedy best-pair, denominator = number of **cited** authors as in Phase 05); when both sides expose a `firstInitial()` and they differ, subtract `initial_penalty` (clamped at 0) |
| `year` | `null` when either side lacks a year; otherwise `max(0, 1 - |diff| / year_window)` |

- `score = Σ wᵢ·sᵢ / Σ wᵢ` over available signals; `surnames === null` (no authors on either side)
  → no score, no candidate (a year alone cannot identify a bibliography entry).
- A missing year renormalizes to surname-only, so an undated reference is still matchable.
- `CitationCandidate` (`app/Services/Citations/CitationCandidate.php`): `referenceId`, `confidence`
  (`round 4`, clamped), `method` (`CitationResolutionMethod`), `matchReason` (component evidence,
  Indonesian, deterministic), `rank` (assigned after sorting by confidence DESC then
  `bibliographyIndex` ASC).

`CitationResolver` (W1 decision, best-candidate — **no margin yet**, D-05.1-06):

```text
resolve(marker, references, hint):
  A. IEEE: ordinals in order → each maps to bibliographyIndex; primary = lowest valid;
     candidates = every valid ordinal (deduped by reference). method = ieee, confidence = 1.0.
  B. Valid hint + unparseable marker → commit hinted reference, method = extraction_hint,
     confidence = null (GROBID provides none).
  C. Valid hint + parsed marker:
        hinted = score the primary pair against the hinted reference only
        if hinted !== null and (hinted is the overall best OR confidence >= proposal_threshold)
            → commit hint (method extraction_hint, confidence = hinted.confidence)
        else if best candidate confidence >= commit_threshold → commit parser best (method apa)
        else → no pairing (the hint stays a W2 candidate, not a pairing)
  D. No hint / invalid hint: best candidate confidence >= commit_threshold → commit (method apa);
     otherwise → no pairing.
  Candidates always carry every scored reference (plus the hint) for W2 persistence.
```

`CitationResolution` (`app/Services/Citations/CitationResolution.php`) is reshaped:

```php
final class CitationResolution
{
    /**
     * @param list<CitationCandidate> $candidates ranked
     */
    public function __construct(
        public readonly CitationResolutionState $state,   // paired|unmatched (W1)
        public readonly ?string $referenceId,
        public readonly ?float $confidence,
        public readonly ?CitationResolutionMethod $method,
        public readonly ?int $hintIndex,
        public readonly array $candidates = [],
    ) {}
}
```

`CitationResolutionState` (`App\Enums`, W1) = `paired | unresolved | unmatched`;
`CitationResolutionMethod` = `extraction_hint | apa | ieee | manual`. Both are **internal** in W1
(not persisted, not serialized), so the enum contract changes only land in W2. `unresolved` is never
produced in W1 (no ambiguity policy).

### 6.3 T3 — Extraction hint artifact and deterministic IEEE order

**New files:** `app/Services/Analysis/CitationExtractionHints.php`
**Modified:** `app/Services/Analysis/AnalysisContext.php`,
`app/Services/Analysis/Steps/PersistExtractionStep.php`,
`app/Services/Analysis/Steps/ResolveCitationsStep.php`
**Tests:** extend `tests/Feature/ExtractionPersistenceTest.php`, `tests/Feature/AnalysisContextTest.php`,
`tests/Feature/ResolveCitationsStepTest.php`

`CitationExtractionHints` (transient, per run):

```php
final class CitationExtractionHints
{
    /**
     * @param array<int, string>      $referenceIdsByIndex 0-based payload index → persisted reference id
     * @param array<string, int|null> $hintByCitationId     persisted citation id → GROBID reference_index
     */
    public function __construct(
        public readonly array $referenceIdsByIndex,
        public readonly array $hintByCitationId,
    ) {}

    public function referenceIdForIndex(?int $index): ?string;
    public function hintForCitation(string $citationId): ?int;
    public static function empty(): self;
}
```

- `PersistExtractionStep` now records, while iterating the payload, the generated reference ids
  (payload order) and the per-citation `referenceIndex`, then calls
  `$context->setExtractionHints($hints)` **before** the transaction ends (the ids are already
  known).
- `AnalysisContext` gains the typed accessor trio (`setExtractionHints`, `hasExtractionHints`,
  `extractionHints()`) following the existing artifact pattern; `takeExtractionHints()` is **not**
  added — the hint is read once by `ResolveCitationsStep` and the artifact is small.
- `ResolveCitationsStep` reads `$context->hasExtractionHints() ? $context->extractionHints() :
  CitationExtractionHints::empty()` so the step still works when invoked directly in tests.
- **Ordered UUIDs (D-05.1-10):** `PersistExtractionStep::insert()` uses
  `(string) Str::orderedUuid()` for the generated `id`, and citation/reference ids use the same.
  `Str::orderedUuid()` is still a RFC-9562 UUID string; no column/type change. This makes the
  `id` tiebreak in `ResolveCitationsStep::references()` follow insertion (payload) order when
  `text_start_offset` is null.
- Hint validation happens in `CitationResolver`: `referenceIdForIndex()` must return a non-null id
  and that id must exist in the `references` list passed in; otherwise the hint is ignored.

### 6.4 T4 — Batch resolver and evidence consolidation

**New files:** `app/Services/Citations/{CitationResolutionInput,CitationBatchResolver}.php`
**Modified:** `app/Services/Analysis/Steps/ResolveCitationsStep.php`
**Tests:** `tests/Unit/CitationBatchResolverTest.php`, extend `tests/Feature/ResolveCitationsStepTest.php`

```php
final class CitationResolutionInput
{
    public function __construct(
        public readonly string $citationId,
        public readonly ParsedCitationMarker $marker,
        public readonly ?int $hintIndex = null,
    ) {}
}

final class CitationBatchResolver
{
    public function __construct(private readonly CitationResolver $resolver) {}

    /**
     * Pass 1: direct resolutions (hint, IEEE, high-score APA).
     * Pass 2: evidence consolidation for leftovers.
     *
     * @param  list<CitationReference>  $references
     * @param  list<CitationResolutionInput>  $inputs
     * @return array<string, CitationResolution> keyed by citation id
     */
    public function resolve(array $references, array $inputs): array;
}
```

Pass 2 — evidence consolidation (D-05.1-06), only for inputs still `unmatched`:

```text
1. group leftovers by normalized surname key: sorted, StringSimilarity::normalize()d
   primaryPair()->authors surnames; groups with no surnames are skipped.
2. group evidence = union of:
     - the strongest year present in any member (all members must agree; if two members
       carry different years for the same surname, the group evidence is ambiguous → no merge)
     - the most common non-null first initial per surname (only when unambiguous)
3. for each member missing year/initials, merge the group evidence into a copy of its pair
   and re-score; commit the best candidate when its confidence >= commit_threshold.
4. keep every original resolution untouched — consolidation only upgrades unmatched → paired.
   It never changes a committed pair (no dropping).
```

This deterministically resolves the class `(Koten, 2023)` + `(Koten)` against a single
`Koten, D. (2023)` reference, and is a pure function covered by unit tests.

`ResolveCitationsStep` becomes thin:

```text
1. references = ordered references (unchanged) mapped to CitationReference (authors pre-parsed).
2. citations = document citations.
3. hints = context hints (or empty).
4. inputs = parse every citation (marker ?? text) + hint.
5. resolutions = batchResolver.resolve(references, inputs).
6. transaction:
     reset every citation in the document (FK null; W2 also resets state/method/confidence/hint,
     deletes candidates)
     group citation ids by resolved reference id (same bulk-update approach as Phase 05).
7. progress reporting per citation (unchanged).
```

In W1 the step persists only the FK (behavior-compatible); `resolution.state/method/confidence`
are carried in the value object and logged at `debug` (document id + method counters) so the
evaluation harness and observability can see them. W2 switches that logging to persistence.

### 6.5 T5 — Evaluation harness and seed dataset

**New files:** `app/Console/Commands/EvaluateCitationResolutionCommand.php`,
`app/Services/Evaluation/{CitationResolutionEvaluator,CitationEvaluationResult}.php`,
`tests/Fixtures/citations/evaluation/seed.json`
**Tests:** `tests/Unit/CitationResolutionEvaluatorTest.php`,
`tests/Feature/EvaluateCitationResolutionCommandTest.php`

Dataset shape (JSON, one file can contain several documents):

```json
{
  "documents": [
    {
      "name": "apa-mixed",
      "references": [
        { "id": "r1", "authors": "Koten, D. B.", "publication_year": 2023, "text_start_offset": 100 },
        { "id": "r2", "authors": "LeCun, Y., Bengio, Y., & Hinton, G.", "publication_year": 2015, "text_start_offset": 250 }
      ],
      "citations": [
        { "id": "c1", "marker": "(Koten, 2023)", "text": "(Koten, 2023)", "expected_reference_id": "r1", "hint_index": 0 },
        { "id": "c2", "marker": "(Tanpa rujukan, 2022)", "text": "(Tanpa rujukan, 2022)", "expected_reference_id": null }
      ]
    }
  ]
}
```

`CitationResolutionEvaluator` (pure) computes, per document and aggregate:

| Metric | Definition |
|---|---|
| `pair_precision` | committed pairs that match `expected_reference_id` / committed pairs |
| `pair_recall` | committed pairs that match / citations with a non-null expectation |
| `pair_f1` | harmonic mean |
| `unresolved_rate` | `unresolved` / all citations (always 0 in W1, ≥ 0 in W2) |
| `hallucination_precision` | predicted `unmatched` whose expectation is null / predicted `unmatched` |
| `hallucination_recall` | predicted `unmatched` whose expectation is null / all null-expectation citations |
| `by_method` | the same counts split by `extraction_hint` / `apa` / `ieee` |
| threshold sweep | `pair_f1` recomputed for a grid of `commit_threshold` × `winner_margin` (reuses the pure resolver, so no DB) |

Command:

```bash
php artisan citations:evaluate tests/Fixtures/citations/evaluation/seed.json
php artisan citations:evaluate path/to/dataset.json --json=storage/app/evaluation/report.json
```

- Table output: aggregate metrics, per-document metrics, per-method counts, and the winning
  threshold pair for the sweep.
- Exit code `0` when the dataset loads and evaluates (metrics are informational), `1` on a
  malformed dataset.
- The seed dataset includes the three product cases plus adversarial cases: same surname in two
  years, same surname+year twice, initials mismatch, `et al.`, IEEE range, multi-marker, hint
  disagreement, hint out of range, null offsets, and an undated reference.
- Baseline numbers from the seed run are recorded in the command's test expectations (tight
  enough to catch regressions, loose enough for calibration changes) and in the T10 docs sync.

> Real labeled data (Indonesian theses) remains a Phase 07 task; the harness is dataset-driven so
> it can be pointed at a larger corpus without code changes.

---

## 7. W1 gate

Before starting W2, verify:

- [ ] `php artisan test --compact` green; no `docs/API_SPEC.md`/`docs/DB_SCHEMA.md` diff.
- [ ] `citations:evaluate` on the seed dataset reports `pair_recall` ≥ the Phase 05 baseline
      (hints + multi-marker + consolidation must not regress recall) and no new hallucination
      regressions.
- [ ] `ResolveCitationsStep` still works when invoked directly without the context hints
      (`CitationExtractionHints::empty()` fallback) and with offsets missing (ordered UUIDs).
- [ ] `AuthorMatcherTest` is byte-for-byte green (surname regression).
- [ ] W1 is mergeable on its own; if the team wants to ship it before the contract change, that is
      safe (it only adds recall, never drops pairs).

---

## 8. W2 — Persisted resolution state, candidates and API

### 8.1 T6 — Migration, enums, models, schema doc

**New files:**
`database/migrations/2026_xx_xx_add_citation_resolution_columns.php`,
`database/migrations/2026_xx_xx_create_citation_resolution_candidates_table.php`,
`app/Enums/CitationResolutionState.php`, `app/Enums/CitationResolutionMethod.php`,
`app/Models/CitationResolutionCandidate.php`,
`database/factories/CitationResolutionCandidateFactory.php`
**Modified:** `app/Models/ResearchedDocumentCitation.php` (casts + relations),
`docs/DB_SCHEMA.md`

Citation columns (matching `decimal(5,4)` used by `reference_findings.confidence`):

```php
$table->string('resolution_state')->default('unmatched');            // paired|unresolved|unmatched
$table->string('resolution_method')->nullable();                     // extraction_hint|apa|ieee|manual
$table->decimal('resolution_confidence', 5, 4)->nullable();
$table->integer('extraction_reference_index')->nullable();           // raw GROBID hint, audit
$table->index(['researched_document_id', 'resolution_state']);
```

`citation_resolution_candidates`:

```php
$table->uuid('id')->primary();
$table->foreignUuid('citation_id')->constrained('researched_document_citations')->cascadeOnDelete();
$table->foreignUuid('researched_document_reference_id')->constrained('researched_document_references')->cascadeOnDelete();
$table->integer('rank');
$table->decimal('confidence', 5, 4);
$table->string('method');                                            // extraction_hint|apa|ieee
$table->text('match_reason')->nullable();
$table->timestamps();
$table->unique(['citation_id', 'rank']);
$table->unique(['citation_id', 'researched_document_reference_id']);
$table->index('citation_id');
```

- Model casts: `resolution_state` → `CitationResolutionState`, `resolution_method` →
  `CitationResolutionMethod`, `resolution_confidence` → `float`.
- `ResearchedDocumentCitation::candidates(): HasMany<CitationResolutionCandidate>` ordered by
  `rank`; `CitationResolutionCandidate::{citation(), reference()}`.
- `docs/DB_SCHEMA.md` first DBML block: add the four columns to `researched_document_citations`,
  add the `citation_resolution_candidates` table, and the `Ref:` lines
  (`citation_resolution_candidates.citation_id > researched_document_citations.id`,
  `.researched_document_reference_id > researched_document_references.id`).
- Migration note: `after()` is MySQL-only and ignored by SQLite/Postgres — keep it for readability
  only; do not rely on column order.

### 8.2 T7 — W2 persistence: writer, candidates, reset, step

**New files:** `app/Services/Citation/CitationResolutionWriter.php`
**Modified:** `app/Services/Analysis/Steps/ResolveCitationsStep.php`,
`app/Services/Document/DocumentAnalysisResetService.php`
**Tests:** `tests/Feature/CitationResolutionWriterTest.php`, extend
`tests/Feature/ResolveCitationsStepTest.php`, `tests/Feature/DocumentAnalysisResetServiceTest.php`

`CitationResolutionWriter` (automated, single writer, mirrors `ReferenceFindingWriter`):

```text
persistBatch(document, resolutions: array<string, CitationResolution>):
  DB::transaction:
    1. reset all citations of the document: researched_document_reference_id = null,
       resolution_state = unmatched, resolution_method = null, resolution_confidence = null,
       extraction_reference_index = null; delete citation_resolution_candidates for the document
    2. per resolution:
         paired     → FK, state paired, method, confidence, hint index
         unresolved → FK null, state unresolved, method (best candidate's), confidence
                      (best candidate's), hint index
         unmatched  → FK null, state unmatched, method null (or the method that failed a
                      contradiction), confidence null, hint index
    3. candidates: reassign rank 1..N per citation, one chunked bulk insert
       (citation_id, reference_id, rank, confidence, method, match_reason)
```

- Candidate ranks come from `CitationResolution::candidates` (`rank` 1..N), so the unique
  `(citation_id, rank)` index cannot be violated.
- `DocumentAnalysisResetService::reset()` deletes `citation_resolution_candidates` before
  `researched_document_citations` (child-first, consistent with the existing explicit order).
- `ResolveCitationsStep` now calls the writer instead of hand-writing the FK; it passes the
  resolutions plus the raw hint index per citation. The existing `AnalysisProgress` reporting is
  unchanged.
- Re-running is idempotent: step 1 clears state/candidates, step 2/3 rewrite.

### 8.3 T8 — Derived status, summary and findings feed

**Modified:** `app/Enums/{CitationStatus,FindingType}.php`,
`app/Services/Citations/CitationStatusResolver.php`,
`app/Services/Document/DocumentSummaryService.php`,
`app/Data/ResearchedDocument/DocumentAnalysisSummaryData.php`,
`app/Services/Findings/FindingsFeedQuery.php`

`CitationStatus`:

```php
enum CitationStatus: string
{
    case Valid = 'valid';
    case Unreliable = 'unreliable';
    case Pending = 'pending';
    case Unresolved = 'unresolved';
    case Hallucination = 'hallucination';

    public static function derive(
        CitationResolutionState $state,
        ?ReferenceFindingStatus $findingStatus,
    ): self {
        return match (true) {
            $state === CitationResolutionState::Unresolved => self::Unresolved,
            $state === CitationResolutionState::Unmatched => self::Hallucination,
            $findingStatus === null, $findingStatus === ReferenceFindingStatus::Pending => self::Pending,
            $findingStatus === ReferenceFindingStatus::Valid,
            $findingStatus === ReferenceFindingStatus::Suspicious => self::Valid,
            default => self::Unreliable,
        };
    }

    public function toFindingType(): ?FindingType;               // + Unresolved → CitationUnresolved
    public function toSeverity(?ReferenceFindingStatus $finding = null): ?FindingSeverity;
}
```

- `toSeverity`: `Unresolved` → `medium`; `Hallucination` → `high`; `Unreliable` → the paired
  finding's `ReferenceFindingStatus::toSeverity()` (high for invalid/not_found); `Valid`/`Pending`
  → null. This replaces the citation-severity CASE hardcoded in `FindingsFeedQuery` (D-05.1-01).
- `FindingType::CitationUnresolved = 'citation_unresolved'`.
- `CitationStatusResolver::resolve(CitationResolutionState $state, ?ReferenceFindingStatus)` and
  `sqlExpression(string $stateColumn, string $findingStatusColumn)`:

```sql
CASE
  WHEN c.resolution_state = 'unresolved' THEN 'unresolved'
  WHEN c.resolution_state = 'unmatched'  THEN 'hallucination'
  WHEN f.status IS NULL OR f.status = 'pending' THEN 'pending'
  WHEN f.status IN ('valid','suspicious') THEN 'valid'
  ELSE 'unreliable'
END
```

  The identifier guard stays; both call sites (`CitationQueryService`, `DocumentSummaryService`)
  switch the first argument to the state column. `FindingsFeedQuery`'s citation side joins on state
  and builds type/severity/message from the enums (`CitationStatus::toFindingType/toSeverity`),
  with the unresolved message
  `"Sitasi belum dapat ditautkan secara pasti ke referensi."`.
- `DocumentAnalysisSummaryData` gains `unresolvedCitations`; `DocumentSummaryService` adds the
  `unresolved_citations` bucket (the existing `citationCountSelect()` already iterates
  `CitationStatus::cases()`, so only the mapping/`forCounts` signature changes).
- `DocumentController`/summary DTO tests and `CitationStatusAgreementTest` (F-DERIV-01) extend to
  all five statuses.

### 8.4 T9 — API surface: detail, candidates, manual provenance

**New files:**
`app/Data/Citation/{CitationResolutionData,CitationResolutionCandidatePreviewData}.php`
**Modified:** `app/Data/Citation/{CitationSummaryData,CitationDetailData,CitationPairingData}.php`,
`app/Services/Citation/{CitationQueryService,CitationPairingService}.php`,
`app/Http/Controllers/Api/CitationController.php`, `docs/API_SPEC.md`
**Tests:** extend `tests/Feature/{CitationDataTest,CitationEndpointTest,CitationPairingTest}.php`

Response additions (all **additive**):

- `CitationSummaryData` gains `resolution_method` (`?CitationResolutionMethod`).
- `CitationDetailData` gains:
  - `resolution: { state, method, confidence, hint_index }`
  - `candidates: list<{ id, rank, confidence, method, match_reason, reference: { id, title } }>`
- `CitationPairingData` gains `resolution_method` (`manual` for both pair and unpair).
- `CitationQueryService::paginate()` eager-loads `candidates.reference` for the detail path only
  (list stays lean); the detail controller loads `['reference.finding', 'locations',
  'candidates.reference']`.
- `CitationController::statusFor()` passes `$citation->resolution_state` instead of the FK check.
- `CitationPairingService` sets `resolution_state = paired|unmatched`, `resolution_method = manual`,
  `resolution_confidence = null` (keeps candidates; a manual pick does not delete alternatives).
- All `unresolved`/hint strings stay centralized (`CitationStatus`/feed builder/service), not in
  controllers.

`docs/API_SPEC.md` updates (W2, deliberate contract change):

| Section | Change |
|---|---|
| §2.6 citation status | add `unresolved` row/condition; note `hallucination` = no candidate exists; severity table gains `citation_unresolved → medium` |
| §4 document summary | add `unresolved_citations` count |
| §5 reference detail `citations[]` | unchanged (occurrence previews only) |
| §6 citations list | `status` filter gains `unresolved`; item gains `resolution_method` |
| §6 citation detail | add `resolution` and `candidates` objects |
| §6 PATCH citations | note `resolution_method = manual` is recorded for pair/unpair |
| §7 findings feed | `type` filter gains `citation_unresolved`; add the unresolved message and severity rule |

No `docs/DB_SCHEMA.md` change beyond T6; no new endpoints; ownership stays citation-scoped
(candidates have no standalone endpoint).

---

## 9. Acceptance matrix

| ID | Case | Expected | Covered by |
|---|---|---|---|
| T-CIT-10 | Ambiguous APA match (margin below `winner_margin`) | `unresolved` (not paired, not hallucination), candidates persisted, severity `medium` | `CitationBatchResolverTest`, `CitationResolutionWriterTest`, `CitationEndpointTest`, `FindingsEndpointTest` |
| T-CIT-11 | No plausible candidate | `hallucination` (high), no candidates | resolver unit + endpoint |
| T-CIT-12 | Valid extraction hint (unparseable marker) | paired with `resolution_method=extraction_hint`, hint index persisted | `ResolveCitationsStepTest` |
| T-CIT-13 | Hint contradicts the parsed marker | parser result wins; hint retained as candidate; no hallucination | resolver unit + step |
| T-CIT-14 | Hint index out of range | ignored; parser result used | resolver unit |
| T-CIT-15 | Initials disambiguation (`Koten, D.` vs `Koten, A.`) | correct reference chosen, penalty applied to the mismatch | scorer unit |
| T-CIT-16 | Soft year (preprint gap within `year_window`) | paired; outside window → unresolved, not forced | scorer unit |
| T-CIT-17 | Multi-marker `(A, 2020; B, 2021)` | primary committed, second entry persisted as candidate | parser/batch unit + step |
| T-CIT-18 | IEEE `[3-5]` | primary = ordinal 3, 4/5 candidates | parser unit + step |
| T-CIT-19 | Null offsets | IEEE ordinal order follows payload order (ordered UUIDs) | step test |
| T-CIT-20 | Evidence consolidation (`(Koten, 2023)` + `(Koten)`) | both paired, second via consolidation | `CitationBatchResolverTest` |
| T-CIT-21 | Manual pair/unpair | `resolution_method=manual`, state `paired`/`unmatched`, candidates kept | `CitationPairingTest` |
| T-CIT-22 | All five derived statuses agree (PHP/SQL/summary) | exact agreement | `CitationStatusAgreementTest` (F-DERIV-01) |
| T-EVAL-01 | Seed evaluation | metrics computed, thresholds sweep works, no dataset crash | evaluator unit + command test |
| T-OWN-05..07 | Ownership unchanged | `404`; candidates never leak | `OwnershipIsolationTest` |
| — | `AuthorMatcher` surname regression | output unchanged | `AuthorMatcherTest` |

---

## 10. T10 — Documentation sync and final validation

- [ ] `docs/API_SPEC.md` — the W2 table in §8.4 (no other shape changes).
- [ ] `docs/DB_SCHEMA.md` — the T6 columns/table (first DBML block only).
- [ ] `docs/TEST_PLAN.md` §5.5/§5.6 — add T-CIT-10..22 and the evaluation rows.
- [ ] `docs/ARCHITECTURE.md` §13 — add the robustness layer (`CitationBatchResolver`,
  `CitationCandidateScorer`, hints, candidates, `unresolved`); keep the "missing" list accurate.
- [ ] `AGENTS.md` §14 — same status update.
- [ ] `docs/plans/backend/README.md` §3.1/§3.2, phase map, traceability — add Phase 05.1.
- [ ] `docs/plans/backend/05-citation-resolution-and-review-detail.md` — link this plan as the
  follow-up.
- [ ] Record the seed evaluation baseline in the plan/summary.
- [ ] `php artisan test --compact` + `vendor/bin/pint --dirty --format agent`.

---

## 11. Files touched (summary)

```text
backend/app/
├── Console/Commands/                         + EvaluateCitationResolutionCommand
├── Data/
│   ├── Citation/                             + CitationResolutionData,
│   │                                           CitationResolutionCandidatePreviewData
│   │                                         ~ CitationSummaryData, CitationDetailData,
│   │                                           CitationPairingData
│   └── ResearchedDocument/                   ~ DocumentAnalysisSummaryData (+unresolved)
├── Enums/                                    + CitationResolutionState, CitationResolutionMethod
│                                             ~ CitationStatus (+Unresolved), FindingType
├── Models/                                   + CitationResolutionCandidate
│                                             ~ ResearchedDocumentCitation (casts/relations)
├── Services/
│   ├── Analysis/                             + CitationExtractionHints
│   │   ├── AnalysisContext                   ~ hints accessors
│   │   └── Steps/                            ~ PersistExtractionStep (hints + ordered uuid),
│   │                                           ResolveCitationsStep (batch + writer)
│   ├── Citation/                             + CitationResolutionWriter
│   │                                         ~ CitationQueryService, CitationPairingService
│   ├── Citations/                            + CitationCandidate, CitationResolutionInput,
│   │                                           CitationBatchResolver, CitationCandidateScorer
│   │                                         ~ CitationMarkerParser, CitationResolver,
│   │                                           CitationMatchConfig, ParsedCitationMarker,
│   │                                           ParsedAuthorYear, CitationReference,
│   │                                           CitationResolution, CitationStatusResolver
│   ├── Document/                             ~ DocumentAnalysisResetService, DocumentSummaryService
│   ├── Evaluation/                           + CitationResolutionEvaluator, CitationEvaluationResult
│   ├── Findings/FindingsFeedQuery            ~ state-based type/severity/message
│   └── Scoring/AuthorMatcher                 ~ +names(), surnames() delegates
database/migrations/                          + resolution columns, + candidates table
database/factories/                           + CitationResolutionCandidateFactory
config/scoring.php                            ~ citation_matching block
.env.example                                  ~ new SCORING_CITATION_* keys
tests/                                        unit/feature/fixtures (per task)
docs/API_SPEC.md · docs/DB_SCHEMA.md · docs/TEST_PLAN.md · docs/ARCHITECTURE.md ·
AGENTS.md · docs/plans/backend/README.md    ~ status + contract updates
```

No new Composer/npm dependency. No new endpoints (additive fields on existing ones). No change to
`OwnedResourceFinder`, rate limits, or the report surface.

---

## 12. Risks, pitfalls and deliberate deviations

### 12.1 Pitfalls

1. **Hint as gospel.** GROBID gives no confidence; never fabricate one. Validate the index and
   keep the parser as the contradiction check (D-05.1-03); keep the trust switch.
2. **Dropping pairs in W1.** The ambiguity/margin policy must not run before `unresolved` exists,
   or W1 ships more high-severity hallucinations. Gate it to W2 (D-05.1-06).
3. **Forking the surname parser.** `AuthorMatcher::names()` must preserve `surnames()` output
   exactly; regression tests are mandatory.
4. **Breaking the existing status contract in W1.** W1 persists only the FK and keeps four
   statuses; `unresolved` becomes visible only in W2.
5. **Migration atomicity.** Columns + table in one deliberate change; `down()` drops both. No
   backfill: existing rows default to `unmatched` → `hallucination`, identical to today.
6. **N+1 on candidates.** Detail loads `candidates.reference`; the list stays lean; the writer
   bulk-inserts candidates.
7. **Derived-status drift.** `CitationStatusResolver::sqlExpression()` must remain the only SQL
   mapping; `FindingsFeedQuery` and `DocumentSummaryService` reuse it, and F-DERIV-01 pins all
   five statuses.
8. **Config rename.** Removing `surname_threshold` must update `CitationMatchConfig`, its test,
   `.env.example` and any docs; no runtime `env()` reads outside config.
9. **Candidate rank collisions.** Rank assigned 1..N after sorting; unique `(citation_id, rank)`
   enforces it; the writer resets before writing.
10. **Ordered UUID assumptions.** Only the *generation* changes; never parse/sort UUIDs as
    timestamps in app code.
11. **Real-world dataset absence.** The seed harness is a regression/calibration tool, not a
    publishable evaluation; label it as such until Phase 07.

### 12.2 Deliberate deviations

1. **D-05.1-06** — "global assignment" is evidence consolidation + ambiguity detection, not
   Hungarian/bipartite (unlimited capacity).
2. **D-05.1-10** — ordering fixed via UUID v7 + offset, not by adding a reference `extraction_index`
   column (keeps the schema delta limited to provenance).
3. **D-05.1-11** — `AuthorMatcher` is extended, not replaced by a citation-specific parser.
4. **D-05.1-05** — context/semantic signal deferred; the seam is the `method` enum + scorer
   interface.

### 12.3 Working assumptions to confirm during implementation

- `Str::orderedUuid()` availability and DB portability (SQLite/MySQL/Postgres UUID columns accept
  the v7 string).
- `CitationResolutionState`/`CitationResolutionMethod` values are the ones serialized in W2
  (`lower_snake_case`, matching the API conventions).
- The evaluation seed metrics are recorded as expected ranges, not exact floats (ranking ties).

---

## 13. Rollback

- **W1** is revert-safe: the pure engine and hint artifact have no persisted footprint. Reverting
  T1–T5 restores the Phase 05 behavior (hint discarded, surname/year matcher, first-pair parsing).
- **W2** migration `down()` drops the four columns and the candidates table. Existing data
  (reference FKs) is untouched, and the default `unmatched` maps every unpaired row back to
  `hallucination`, which is exactly the Phase 05 semantics — no data loss.
- The `PATCH /citations/{citation}` contract stays backward compatible (manual pairing still
  accepts `researched_document_reference_id`).

---

## 14. Hand-off to Phase 06/07

| Primitive | Location | Usage rule |
|---|---|---|
| Resolution state/method | `App\Enums\CitationResolutionState`/`Method` | reports read these; never re-derive |
| Candidates | `CitationResolutionCandidate` | read model only; manual selection goes through `CitationPairingService` |
| Batch resolver | `CitationBatchResolver` | the only automated matching path; do not duplicate scoring |
| Thresholds | `config/scoring.php` → `CitationMatchConfig` | Phase 07 tunes values, never the classes |
| Evaluation | `citations:evaluate` | Phase 07 points it at the real labeled corpus and fixes the defaults |

Phase 06 (reports) may surface `unresolved` citations and their candidates in the report; it must
not add citation-status columns or re-implement derivation. Phase 07 owns the real-data
calibration, including the question of whether `winner_margin`/thresholds need per-style defaults.

---

## 15. Open questions

None blocking. The following are intentionally deferred with the stated defaults:

| # | Question | Default adopted |
|---|---|---|
| Q-1 | Should the `unresolved` message mention candidate count? | Fixed message for v1; candidate detail carries the alternatives. |
| Q-2 | Should suggestion candidates be frozen at pipeline time or recomputed? | Frozen at pipeline time (persisted) so the reviewer sees the run's evidence; a manual re-resolution is a future feature. |
| Q-3 | Per-style (APA vs IEEE) threshold overrides? | Not in 05.1; revisit after Phase 07 calibration. |
| Q-4 | Should `unresolved` be visible in the document list summary only, or also in a separate count endpoint? | Only the existing summary bucket; no new endpoint. |
