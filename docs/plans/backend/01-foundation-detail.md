# Phase 01 — Foundation & Shared Primitives (Detailed Implementation Plan)

> **Status:** detailed plan — **implemented** (Phase 01 complete; `php artisan test` → 89 passed)
> **Parent:** [`01-foundation.md`](01-foundation.md)
> **Depends on:** — · **Unblocks:** Phases 02–07
> Canonical references: `docs/API_SPEC.md` §2/§12, `docs/DB_SCHEMA.md` (first block),
> `docs/SECURITY.md` §2/§3/§5, `docs/TEST_PLAN.md` §4/§5.3/§8, `AGENTS.md` §5/§7/§8/§13/§19/§20.
> Task template: `docs/plans/backend/README.md` §1.4.

This document expands `01-foundation.md` into executable tasks. Every task below is independently
reviewable and leaves the repository green. Nothing in this phase adds a public endpoint or a
domain table/column; it builds the primitives every later phase reuses. If a decision here turns
out to conflict with the canonical specs, stop and resolve it in `docs/API_SPEC.md` /
`docs/DB_SCHEMA.md` first (README §2) — do not code around it.

---

## 1. Execution model

### 1.1 Task order and dependencies

| Task | Deliverable | Size | Depends on | Suggested commit |
|---|---|---|---|---|
| **T0** | Preflight & baseline | S | — | (no commit) |
| **T1** | Canonical enums + pinned value tests | M | — | `feat(backend): add canonical domain enums` |
| **T2** | Response helper + error envelope layer | L | — | `refactor(backend): centralize API response and error envelopes` |
| **T3** | OQ-14 index, domain models, factories, morph map | L | T1 | `feat(backend): add domain models, factories and morph map` |
| **T4** | `OwnedResourceFinder` + isolation tests | M | T3 | `feat(backend): add document ownership scoping` |
| **T5** | `documents` / `document-status` rate limiters | S | — | `feat(backend): add document rate limiters` |
| **T6** | Crossref/inference/scoring config placeholders | S | — | `chore(backend): add crossref, inference and scoring config` |
| **T7** | Shared test harness (`DocumentTree`, fixture loader) | M | T3 | `test(backend): add shared document tree and fixture harness` |
| **T8** | Docs sync + full-suite exit validation | S | T1–T7 | `docs: sync backend status, schema and plan docs` |

T1/T2/T5/T6 are independent of each other; T3 needs T1 (enum casts), T4 and T7 need T3. Execute
in the listed order to keep the tree green after every commit.

### 1.2 Per-task loop

1. Confirm the relevant open decisions in `01-foundation.md` §6 are still valid (all are resolved;
   record any new ambiguity in the README `Decision log` before coding).
2. Add/adjust files; write tests in the same change as the behavior they pin.
3. `vendor/bin/pest <affected paths>` — affected tests only.
4. `vendor/bin/pint --dirty --format agent` — format changed PHP files.
5. `php artisan test --compact` — full suite stays green.
6. Update the status docs only in T8 (do not churn docs mid-phase).

### 1.3 Exit criteria (from README §9)

- `php artisan test --compact` green; `vendor/bin/pint --dirty --format agent` reports nothing.
- Every F-* acceptance row in §13 below passes.
- `docs/DB_SCHEMA.md` first block and `docs/API_SPEC.md` reflect the two approved spec touches
  (OQ-14 unique index, OQ-09 `reference_pending` filter value).
- `docs/ARCHITECTURE.md` §13, `AGENTS.md` §14 and `docs/plans/backend/README.md` §3 describe the
  new reality.
- No secret, `.env` value or credential added to the repository.

---

## 2. Baseline (verified against the repository at plan creation)

| Item | Value |
|---|---|
| Laravel | `13.33.0` (`laravel/framework ^13.17`) |
| PHP | CLI `8.5.11`; `composer.json` requires `^8.3` |
| `spatie/laravel-data` | `4.23.0` (DTOs live in `app/Data/`, `BaseData::defaultWrap()` = `data`) |
| `laravel/sanctum` | `4.3.3` |
| Pest | `5.2.1` (+ `pest-plugin-laravel 5.0.1`) |
| Test DB / queue | in-memory SQLite, `QUEUE_CONNECTION=sync` (`backend/phpunit.xml`) |
| Baseline suite | `php artisan test --compact` → **26 passed / 178 assertions** |
| Existing domain models | `User`, `ResearchedDocument`, `File` only |
| Existing exceptions | `DocumentUploadFailedException`, `InvalidCredentialsException` |
| Existing envelope renderers | 401/422/429 in `bootstrap/app.php` |
| Existing rate limiters | `auth` (5/min/IP), `api` (60/min/user) |

Facts that constrain the implementation:

- `bootstrap/app.php` registers exception renderers in source order; Laravel's
  `Handler::renderViaCallbacks()` walks them in insertion order, and a custom exception's own
  `render()` method runs **before** all callbacks. These two facts drive the renderer design in T2.
- `UploadDocumentTest` asserts `files.fileable_type === App\Models\ResearchedDocument::class`;
  the morph map (OQ-15) changes the stored value to the alias `researched_document`, so factory and
  test must change in the same commit.
- `reference_findings` currently has only a non-unique index on
  `researched_document_reference_id`; the DBML has no unique marker yet (OQ-14).
- There is no `app/Enums/`, `app/Http/Responses/`, `app/Services/Ownership/`, `tests/Support/` or
  `tests/Fixtures/` directory yet.

---

## 3. Conventions this phase locks in

These conventions are binding for Phases 02–07; they exist so no later phase re-implements them.

### 3.1 Envelope

- Single resource / action: `{ "data": …, "message"?: "…" }` — `ApiResponse::single()/created()/accepted()`.
- Paginated collection: `{ "data": [ … ], "meta": { current_page, per_page, total, last_page } }` —
  `ApiResponse::collection()`.
- `204`: `ApiResponse::noContent()`, empty body.
- Every non-2xx: `{ "error": { code, message, details? } }` — `ApiError::response()` only.
- `message` is emitted only when non-null; `details` only when non-null.
- No ad-hoc `response()->json([...])` envelope construction in controllers or renderers.

### 3.2 Error code ↔ HTTP mapping and safe messages

Single source: `ApiError`. `codeForStatus()` / `normalizeStatus()` / `messageForStatus()` are the
only status → code/message mapping in the codebase. Unknown 4xx → `400 BAD_REQUEST`; unknown 5xx
→ `500 SERVER_ERROR`.

| HTTP | code | Safe default message |
|---|---|---|
| 400 | `BAD_REQUEST` | `Permintaan tidak valid.` |
| 401 | `UNAUTHENTICATED` | `Unauthenticated.` |
| 403 | `FORBIDDEN` | `Akses ditolak.` |
| 404 | `NOT_FOUND` | `Sumber daya tidak ditemukan.` |
| 409 | `CONFLICT` | `Konflik status permintaan.` |
| 413 | `PAYLOAD_TOO_LARGE` | `Ukuran file melebihi batas 20 MB.` |
| 415 | `UNSUPPORTED_MEDIA_TYPE` | `Format file tidak didukung. Hanya PDF yang diterima.` |
| 422 | `VALIDATION_ERROR` | `The given data was invalid.` |
| 429 | `RATE_LIMITED` | `Too many attempts. Please try again later.` |
| 500 | `SERVER_ERROR` | `Terjadi kesalahan pada server.` |
| 503 | `INFERENCE_UNAVAILABLE` | `Layanan analisis tidak tersedia. Coba lagi nanti.` |

Per-resource 404 messages come from `ResourceNotFoundException` factories (they are the only
place allowed to diverge): document `Dokumen tidak ditemukan.`, reference
`Referensi tidak ditemukan.`, citation `Sitasi tidak ditemukan.`, report
`Laporan tidak ditemukan.`

### 3.3 Routes (apply in Phase 02+; documented here so T2/T5 line up)

- All under `Route::prefix('v1')->name('v1.')`, authenticated group `['auth:sanctum', 'throttle:api']`.
- Action names: `v1.<resource>.<action>` (`v1.documents.index`, `v1.references.updateFinding`, …).
- Every UUID path parameter gets `->whereUuid('param')`; malformed IDs 404 before touching the DB.
- Static routes (`DELETE /documents`) are declared separately and before any wildcard siblings.
- Limiters: `throttle:documents` on `POST /documents`,
  `throttle:document-status` on `GET /documents/{document}/status`; the stricter of `api` and the
  named limiter applies.

### 3.4 Ownership

- Document-owned resources are resolved through `OwnedResourceFinder` (T4) only.
- Foreign/un-owned → the matching `ResourceNotFoundException` → 404 with no existence disclosure.
- Never `403` for foreign resources; `403` is reserved for genuine policy denials.
- Every Phase 02+ endpoint adds at least one two-user isolation test.

### 3.5 Enums

- Canonical values live in `app/Enums/` only; no raw status/step/severity strings outside enums
  (DB defaults and migrations are the only exception, and they must match the enum values).
- Model casts (`'status' => DocumentStatus::class`), `Rule::enum(...)`, and DTO property types use
  the enums. DTO output still serializes as `lower_snake_case` strings via the enum value.
- Adding/changing a value requires an explicit `docs/API_SPEC.md` change first.

---

## 4. T0 — Preflight & baseline

- [x] `cd backend && composer install` (if `vendor/` is stale).
- [x] `php artisan test --compact` → expect 26 passed.
- [x] `git status` clean / expected branch.
- [x] Re-read `01-foundation.md` §6 and the README `Decision log`; no open questions block T1–T8.
- [x] Confirm no `.env`/secret is read into code or docs.

No commit for T0.

---

## 5. T1 — Canonical enums

**Files (all new):** `app/Enums/{DocumentStatus,AnalysisStep,ReferenceFindingStatus,CitationStatus,ReportStatus,FindingType,FindingSeverity}.php`
**Tests:** `tests/Unit/EnumTest.php`, `tests/Unit/CitationStatusTest.php`
**Doc touch:** `docs/API_SPEC.md` §7 — add `reference_pending` to the findings `type` filter list
(OQ-09). No other API text changes.

Design rules: backed `string` enums, TitleCase cases, `lower_snake_case` values exactly as in
`docs/API_SPEC.md` §2.6. Behavior methods are pure and unit-testable; no Laravel/Eloquent
dependency. Do **not** add `values()` helpers (use `Rule::enum()` / `array_column(self::cases(), 'value')`).

### 5.1 `DocumentStatus` (`researched_documents.status`)

```php
enum DocumentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';

    public function isTerminal(): bool;      // Completed | Failed
    public function allowsRetry(): bool;     // Failed only
}
```

### 5.2 `AnalysisStep` (`researched_documents.analysis_step`)

Cases (declaration order is canonical): `Queued`, `Extracting`, `Persisting`, `CrossrefValidation`,
`Embedding`, `Scoring`, `ResolvingCitations`, `GeneratingReport`, `Completed`.
Method: `public static function ordered(): array` returning an explicit `list<self>` in canonical
order (not just `self::cases()`), so a future case reorder cannot silently change the pipeline.

### 5.3 `ReferenceFindingStatus` (`reference_findings.status`)

Cases: `Pending`, `Valid`, `Suspicious`, `Invalid`, `NotFound = 'not_found'`.

```php
public function isProblem(): bool;                  // Invalid | NotFound | Suspicious
public function toFindingType(): ?FindingType;      // Valid => null
public function toSeverity(): ?FindingSeverity;     // Valid => null
```

Severity mapping per `docs/API_SPEC.md` §2.6: `invalid`/`not_found` → `high`, `suspicious` →
`medium`, `pending` → `info`. The mapping lives here, on the source status, because the
`citation_unreliable` severity is context-dependent (paired finding) and cannot be a static
`FindingType` mapping — this is a deliberate refinement of the broad plan's `FindingType::toSeverity()`
suggestion (see §14.2).

### 5.4 `CitationStatus` (derived only, never persisted)

Cases: `Valid`, `Unreliable`, `Pending`, `Hallucination`.

```php
public static function derive(bool $isPaired, ?ReferenceFindingStatus $findingStatus): self;
public function toFindingType(): ?FindingType;      // Unreliable | Hallucination, else null
```

`derive()` is **the** single implementation of the derived-citation rule (`AGENTS.md` §10);
Phase 05 must call it, never re-implement the table:

| Input | Result |
|---|---|
| `isPaired === false` | `Hallucination` |
| paired, finding `null` or `Pending` | `Pending` |
| paired, finding `Valid`/`Suspicious` | `Valid` |
| paired, finding `Invalid`/`NotFound` | `Unreliable` |

Do not add a `ResearchedDocumentCitation` accessor that lazy-loads `reference.finding`; Phase 05
owns the resolver with eager-loaded relations (N+1 guard).

### 5.5 `ReportStatus` (`generated_document_reports.status`)

Cases: `Pending`, `Processing`, `Completed`, `Failed`; `isTerminal()` = `Completed | Failed`.

### 5.6 `FindingType` (findings-feed `type`)

Cases: `ReferenceInvalid = 'reference_invalid'`, `ReferenceSuspicious`, `ReferenceNotFound`,
`ReferencePending`, `CitationUnreliable`, `CitationHallucination`.
Helpers: `isReference(): bool` (prefix `reference_`), `isCitation(): bool` (prefix `citation_`).

### 5.7 `FindingSeverity`

Cases: `High`, `Medium`, `Low`, `Info`; `rank(): int` → `high=3, medium=2, low=1, info=0` for
deterministic feed sorting. No severity mapping on this enum itself.

### 5.8 Tests

`tests/Unit/EnumTest.php` (F-ENUM-01):

- pin exact value lists for all seven enums (one `expect(array_column(X::cases(), 'value'))->toBe([...])` each);
- pin `AnalysisStep::ordered()` order;
- pin `DocumentStatus::isTerminal()` / `allowsRetry()`, `ReportStatus::isTerminal()`;
- pin `ReferenceFindingStatus::toFindingType()`/`toSeverity()`/`isProblem()` for all five cases;
- pin `FindingType::isReference()`/`isCitation()`;
- pin `FindingSeverity::rank()`.

`tests/Unit/CitationStatusTest.php` (early F-CIT-06..09):

- all four `derive()` buckets, including `findingStatus === null` → `Pending`;
- `toFindingType()` returns null for `valid`/`pending`.

---

## 6. T2 — Response helper and error-envelope layer

**New files:** `app/Http/Responses/ApiResponse.php`, `app/Http/Responses/ApiError.php`,
`app/Exceptions/ApiException.php`, `app/Exceptions/ResourceNotFoundException.php`,
`app/Exceptions/StateConflictException.php`, `app/Exceptions/InferenceUnavailableException.php`,
`app/Exceptions/ApiExceptionRenderer.php`
**Modified:** `bootstrap/app.php`, `app/Exceptions/DocumentUploadFailedException.php`,
`app/Exceptions/InvalidCredentialsException.php`, `app/Http/Controllers/Api/AuthController.php`,
`app/Http/Controllers/Api/UploadController.php`
**Tests:** `tests/Feature/ApiResponseTest.php`, `tests/Feature/ApiErrorEnvelopeTest.php`,
`tests/Unit/ApiErrorTest.php`

### 6.1 `ApiError` — one error shape, one status table

```php
final class ApiError
{
    /** @var array<int, string> */
    private const STATUS_TO_CODE = [ /* §3.2 table */ ];

    /** @var array<string, string> */
    private const CODE_TO_MESSAGE = [ /* §3.2 table */ ];

    public static function response(
        string $code,
        string $message,
        int $status,
        ?array $details = null,
        array $headers = [],
    ): JsonResponse;                                   // builds ['error' => ErrorResponseData]

    public static function statusResponse(int $status, ?string $message = null, array $headers = []): JsonResponse;

    public static function codeForStatus(int $status): string;    // unknown: 5xx => SERVER_ERROR, else BAD_REQUEST
    public static function normalizeStatus(int $status): int;     // known => itself; unknown 4xx => 400, unknown 5xx => 500
    public static function messageForStatus(int $status): string; // safe default per code
}
```

Implementation notes:

- `response()` uses `ErrorResponseData` with `details: $details ?? new Optional` so the key is
  omitted when empty, and passes `$headers` to `response()->json($payload, $status, $headers)`
  (needed for `Retry-After` on 429).
- `statusResponse()` normalizes the status **before** building code/message/status so an `abort(419)`
  becomes a `400 BAD_REQUEST` body, never a 419 payload.

### 6.2 `ApiResponse` — success envelopes

```php
final class ApiResponse
{
    public static function single(Data|array|null $data, ?string $message = null, int $status = 200): JsonResponse;
    public static function created(Data|array|null $data, ?string $message = null): JsonResponse;  // 201
    public static function accepted(Data|array|null $data, ?string $message = null): JsonResponse; // 202
    /** @param class-string<Data> $dataClass */
    public static function collection(LengthAwarePaginator $paginator, string $dataClass): JsonResponse;
    public static function noContent(): Response; // 204, no body
}
```

Implementation notes:

- `single()` builds `['data' => $data]` and appends `message` only when non-null. Pass the
  `Data` object directly (as the existing controllers do); it serializes **unwrapped** under a JSON
  value (`TransformationContext` default is `WrapExecutionType::Disabled`), so `data.data` must
  never appear. Do not call `toArray()` — one transformation path only; pin this with the test.
- `collection()` maps each paginator item through `$dataClass::from($item)` (pass through items
  that are already `Data`), and emits the exact `meta` keys from §3.1. It never adds `message`.
- Adoption: refactor `AuthController` (`register` → `created`, `login`/`me` → `single`, `logout` →
  `single(null, 'Logout berhasil.')`) and `UploadController` (`accepted(..., 'Dokumen berhasil diunggah.')`).
  Existing `AuthTest`/`UploadDocumentTest` assertions are the regression guard; do not change any
  message or status code.

### 6.3 Custom domain exceptions

```php
abstract class ApiException extends RuntimeException
{
    abstract public function code(): string;
    abstract public function status(): int;
    /** @return array<string, mixed>|null */
    public function details(): ?array { return null; }

    public function render(Request $request): JsonResponse
    {
        return ApiError::response($this->code(), $this->getMessage(), $this->status(), $this->details());
    }
}
```

New `final` exceptions:

| Class | Factories | code / status |
|---|---|---|
| `ResourceNotFoundException` | `document()`, `reference()`, `citation()`, `report()` (exact §3.2 messages) | `NOT_FOUND` / 404 |
| `StateConflictException` | `documentRetryNotAllowed()` → `Analisis hanya dapat diulang untuk dokumen yang gagal.`; `reportNotAllowed()` → `Laporan hanya dapat dibuat untuk dokumen yang selesai dianalisis.` | `CONFLICT` / 409 |
| `InferenceUnavailableException` | `serviceUnavailable(?Throwable $previous = null)` → `Layanan analisis tidak tersedia. Coba lagi nanti.` | `INFERENCE_UNAVAILABLE` / 503 |

Refactor the two existing exceptions to extend `ApiException` with identical output
(`DocumentUploadFailedException` → code `SERVER_ERROR`, status 500; `InvalidCredentialsException`
→ code `VALIDATION_ERROR`, status 422, `details()` returning the email detail). This is the only
"churn" to working code in this phase and it is justified: one envelope implementation.

### 6.4 `ApiExceptionRenderer` — framework exceptions (the critical piece)

Replace the inline closures in `bootstrap/app.php` with a registrar. Bootstrap becomes:

```php
->withExceptions(function (Exceptions $exceptions): void {
    ApiExceptionRenderer::register($exceptions);
})
```

`ApiExceptionRenderer::register()` calls `$exceptions->shouldRenderJsonWhen($shouldRenderAsApiError)`
(guard: `$request->is('api/*') || $request->expectsJson()`) and registers renderers **in this exact
order** (insertion order matters — see the note below):

| # | Exception | Response |
|---|---|---|
| 1 | `Illuminate\Auth\AuthenticationException` | 401 `UNAUTHENTICATED`, `Unauthenticated.` |
| 2 | `Illuminate\Validation\ValidationException` | 422 `VALIDATION_ERROR`, `The given data was invalid.` + `$exception->errors()` as details |
| 3 | `Illuminate\Http\Exceptions\ThrottleRequestsException` | 429 `RATE_LIMITED`, existing message + `$exception->getHeaders()` |
| 4 | `Symfony\...\NotFoundHttpException` | `ApiError::statusResponse(404)` (covers prepared `ModelNotFoundException` and unmatched routes) |
| 5 | `AuthorizationException` \| `AccessDeniedHttpException` | `ApiError::statusResponse(403)` (reserved; never used for ownership) |
| 6 | `MethodNotAllowedHttpException` | `ApiError::response('BAD_REQUEST', 'Metode permintaan tidak diizinkan.', 400)` |
| 7 | `HttpExceptionInterface` fallback | `ApiError::statusResponse($exception->getStatusCode())` — safe message only, never `getMessage()` |
| 8 | `Throwable` fallback | 500 `SERVER_ERROR`, `Terjadi kesalahan pada server.` |

Rules for every renderer:

- Return `null` when `shouldRenderAsApiError()` is false, so non-API/web behavior is untouched.
- The `Throwable` fallback must return `null` for `Illuminate\Http\Exceptions\HttpResponseException`
  (thrown by `UploadDocumentRequest::failedValidation()`), otherwise the 413/415/422 envelopes
  would be swallowed into a 500. Do not log manually — Laravel's reporter already logs; the only
  requirement is that the body never contains the exception message, SQL, paths or traces
  (`docs/SECURITY.md` §3/§10). This must hold even with `APP_DEBUG=true`.
- Registration order is the contract: `renderViaCallbacks()` iterates callbacks in insertion order.

### 6.5 Tests

`tests/Unit/ApiErrorTest.php` (F-ERR-04 mapping):

- `codeForStatus()` for all known statuses; unknown 404-ish (`418`) → `BAD_REQUEST`; `599` → `SERVER_ERROR`;
- `normalizeStatus(418) === 400`, `normalizeStatus(599) === 500`.

`tests/Feature/ApiResponseTest.php` (F-RES-01):

- `single(ResearchedDocumentDetailData, message)` → exact `data` + `message`, `assertJsonMissingPath('data.data')`;
- `single(null)` has no `message` key and `data === null`;
- `collection(paginator, DTO::class)` → `data.0.id`, exact `meta` (`current_page`, `per_page`,
  `total`, `last_page`) and no `message`;
- `noContent()` → 204, empty body.

`tests/Feature/ApiErrorEnvelopeTest.php` (F-ERR-01..05). Register test-only routes under
`api/v1/__errors` in a `beforeEach` (after the app boots) covering: throw each new domain
exception; `RuntimeException('internal-secret')`; `abort(418)`; a route with `->whereUuid('id')`;
and a `ModelNotFoundException`. Assertions:

- 404 `NOT_FOUND` + per-resource message for each `ResourceNotFoundException` factory;
- 409 `CONFLICT` + exact messages for both `StateConflictException` factories;
- 503 `INFERENCE_UNAVAILABLE` + safe message;
- unknown route `/api/v1/__missing` → 404 `NOT_FOUND`;
- malformed UUID on the `whereUuid` route → 404; valid UUID → 200 (pins the Phase 02 convention);
- `DELETE /api/v1/auth/login` → 400 `BAD_REQUEST` (method not allowed, canonical table has no 405);
- `abort(418)` → 400 `BAD_REQUEST` with the safe `BAD_REQUEST` message;
- `RuntimeException` → 500 `SERVER_ERROR` with the generic message and `assertDontSee('internal-secret')`;
- `ModelNotFoundException` → 404 `NOT_FOUND`;
- all error bodies have `error.code`/`error.message` and no `error.details` unless documented.

---

## 7. T3 — OQ-14 index, domain models, factories, morph map

### 7.1 OQ-14 migration (approved schema touch)

- `php artisan make:migration add_unique_index_to_reference_findings_table`.
- `up()`: `Schema::table('reference_findings', fn (Blueprint $table) => $table->unique('researched_document_reference_id'));`
- `down()`: `$table->dropUnique(['researched_document_reference_id']);`
- Keep the existing non-unique index; both can coexist on SQLite/MySQL/Postgres.
- Update `docs/DB_SCHEMA.md` **first block only**: mark the `researched_document_reference_id`
  index `[unique]` with a `// one finding per reference (OQ-14)` comment. Do not touch the stale
  second DBML block.
- Land this in Phase 01 (allowed by OQ-14) because the factory/model/tests built here assume the
  invariant and Phase 04's upsert needs it.

### 7.2 Models (new in `app/Models/`)

All new models use `HasFactory, HasUuids`, the `$fillable` property (matching the existing domain
models), `casts()` (matching the existing models) and explicit relation return types.

| Model | Table | Casts | Relations |
|---|---|---|---|
| `ResearchedDocumentReference` | `researched_document_references` | `publication_year`, `text_start_offset`, `text_end_offset` int | `researchedDocument()` BelongsTo; `locations()` HasMany ordered by `location_index`; `finding()` HasOne |
| `ResearchedDocumentReferenceLocation` | `..._reference_locations` | `page_number`, `location_index` int; `x`, `y`, `width`, `height`, `page_width`, `page_height` float | `reference()` BelongsTo (`researched_document_reference_id`) |
| `ResearchedDocumentCitation` | `researched_document_citations` | `occurrence_index`, offsets int | `researchedDocument()` BelongsTo; `reference()` BelongsTo (`researched_document_reference_id`); `locations()` HasMany ordered |
| `ResearchedDocumentCitationLocation` | `..._citation_locations` | same numeric casts as reference location | `citation()` BelongsTo (`citation_id` — schema column name) |
| `ReferenceFinding` | `reference_findings` | `status` => `ReferenceFindingStatus`; `confidence` float; `is_manual` bool; `reviewed_at` datetime | `researchedDocument()`, `reference()`, `selectedCandidate()` (BelongsTo candidate), `reviewedBy()` BelongsTo `User`, `candidates()` HasMany ordered by `rank`; uses `ScopesThroughDocument` |
| `ReferenceFindingCandidate` | `reference_finding_candidates` | `rank`, `publication_year` int; `confidence` float | `referenceFinding()` BelongsTo (`reference_finding_id`) |
| `GeneratedDocumentReport` | `generated_document_reports` | `status` => `ReportStatus`; `generated_at` datetime | `researchedDocument()`, `referenceFinding()` (nullable), `file()` BelongsTo `File` via canonical `file_id`; uses `ScopesThroughDocument` |

Notes:

- The polymorphic `files` row for reports is managed by Phase 06; Phase 01 exposes only the
  canonical `file_id` relation. Name the morph relation in Phase 06 if needed (no `file()` collision).
- `ReferenceFindingCandidate` is not document-scoped itself; it is reached through
  `finding → reference → document`, so it does not use the trait.
- `confidence`/coordinates cast to `float` so DTO/JSON output stays numeric per
  `docs/API_SPEC.md` §5 (`"x": 72.0`, `"confidence": 0.95`). Do not use `decimal:4` (string output).

**Modify `ResearchedDocument`**: add `'status' => DocumentStatus::class` and
`'analysis_step' => AnalysisStep::class` casts; add `references()`, `citations()`, `findings()`,
`reports()` HasMany relations; add `scopeForUser(Builder $query, User $user): Builder`
(`where('user_id', $user->getKey())`).

**Modify `User`**: add `researchedDocuments(): HasMany`.

**Modify `DocumentUploadService`**: assign `DocumentStatus::Pending` / `AnalysisStep::Queued`
instead of raw strings.

**Modify `ResearchedDocumentDetailData`**: type `$status` as `DocumentStatus` and `$currentStep` as
`?AnalysisStep` (enum cast values are enum instances; `string`-typed properties would fail).
Serialization still emits `pending`/`queued` via `EnumTransformer`. `UploadDocumentTest` remains
the regression guard.

### 7.3 Ownership trait

`app/Models/Concerns/ScopesThroughDocument.php` (name settled from the broad plan's "TBD"):

```php
trait ScopesThroughDocument
{
    /** @return BelongsTo<ResearchedDocument, $this> */
    public function researchedDocument(): BelongsTo
    {
        return $this->belongsTo(ResearchedDocument::class, 'researched_document_id');
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->whereHas('researchedDocument', fn (Builder $query) => $query->where('user_id', $user->getKey()));
    }

    public function scopeForDocument(Builder $query, ResearchedDocument $document): Builder
    {
        return $query->where('researched_document_id', $document->getKey());
    }
}
```

Scope-relevant FK columns (`researched_document_id`, `user_id`) already have indexes; no extra
index needed.

### 7.4 Morph map (OQ-15)

In `AppServiceProvider::boot()`:

```php
Relation::enforceMorphMap([
    'user' => User::class,
    'researched_document' => ResearchedDocument::class,
    'generated_document_report' => GeneratedDocumentReport::class,
]);
```

Implementation discovery: an enforced morph map applies to **every** morph relation, and
Sanctum's `personal_access_tokens.tokenable` is a morph relation on `User`. Without the
`user` alias, token issuance throws `ClassMorphViolationException`. The `user` alias only affects
newly stored `tokenable_type` values (reads remain compatible with legacy FQCN values).

Same commit: update `FileFactory` (`fileable_type => 'researched_document'`) and `UploadDocumentTest`
(`assertDatabaseHas('files', ['fileable_type' => 'researched_document', ...])`). Add a DB_SCHEMA.md
comment on `files.fileable_type` listing the two file aliases.

### 7.5 Factories (`database/factories/`)

Extend `ResearchedDocumentFactory`:

- default: `status => DocumentStatus::Pending`, `analysis_step => AnalysisStep::Queued`;
- states `pending()`, `processing()` (progress ~40, step `Extracting`, `analysis_started_at`),
  `completed()` (progress 100, step `Completed`, started+completed timestamps), `failed()`
  (progress < 100, safe `analysis_error`).

New factories and required states:

| Factory | Default | States / helpers |
|---|---|---|
| `ResearchedDocumentReferenceFactory` | `researched_document_id => ResearchedDocument::factory()`, DOI `null`, offsets sequence | `withDoi(?string $doi = null)`, `withoutDoi()` |
| `ResearchedDocumentReferenceLocationFactory` | page 1, A4 page size, bbox floats | `location_index` sequence starting at 0 for `createMany` |
| `ResearchedDocumentCitationFactory` | `researched_document_id => ResearchedDocument::factory()`, unpaired (`researched_document_reference_id => null`), `occurrence_index => null` | `pairedTo(ResearchedDocumentReference $reference)` sets **both** document id and reference id from the reference |
| `ResearchedDocumentCitationLocationFactory` | as reference location, FK `citation_id` | `location_index` sequence |
| `ReferenceFindingFactory` | pending, no candidate | `forReference(ResearchedDocumentReference $reference)` (sets both ids consistently); states `pending()`, `valid()`, `suspicious()`, `invalid()`, `notFound()`, `manual()` (`is_manual`, `reviewed_by => User::factory()`, `reviewed_at`) |
| `ReferenceFindingCandidateFactory` | `reference_finding_id => ReferenceFinding::factory()`, `rank` sequence 1..N | used via `->for($finding)` (guesses `referenceFinding`) + `->createMany(n)` |
| `GeneratedDocumentReportFactory` | `researched_document_id => ResearchedDocument::factory()->completed()`, pending | `pending()`, `processing()`, `completed()` (`generated_at`), `failed()` (safe `error`) |

Factory rules (they prevent the flaky unique-index failures flagged in `01-foundation.md` §7):

- `ReferenceFindingFactory` must set `researched_document_id` from the chosen
  `researched_document_reference_id` (attribute closure or `forReference()`), because the unique
  index is per reference and a mismatched document id breaks the `forUser` scope.
- A `Sequence` resets per factory invocation, so never rely on `occurrence_index`/`location_index`
  sequences across separate `DocumentTree` calls: `DocumentTree` computes explicit indexes (T7).
- `occurrence_index` stays nullable and null by default (safe for single citations; the DB unique
  index allows multiple `NULL`s on SQLite/MySQL/Postgres).

### 7.6 Tests

Extend `tests/Feature/DomainSchemaTest.php`:

- F-SCHEMA-01: creating a second `ReferenceFinding` for the same reference throws
  `Illuminate\Database\QueryException` (unique index holds);
- cascade: deleting a `ReferenceFinding` removes its candidates; deleting a selected candidate
  nulls `selected_candidate_id` (the `nullOnDelete` FK);
- keep the existing table/cascade tests unchanged (they must stay green).

New `tests/Feature/DomainModelTest.php` (F-DB-01):

- build a full tree through factories: document → reference → location → citation → citation
  location → finding → candidates → report; assert every relation resolves with the expected
  counts and FK values;
- enum casts return enum instances (`$document->status === DocumentStatus::Pending`,
  `$finding->status === ReferenceFindingStatus::Valid`);
- morph map: `fileable_type === 'researched_document'` and `$file->fileable instanceof ResearchedDocument`.

---

## 8. T4 — Ownership scoping

**New file:** `app/Services/Ownership/OwnedResourceFinder.php`
**Tests:** `tests/Feature/OwnershipIsolationTest.php`

```php
final class OwnedResourceFinder
{
    public function document(User $user, string $id): ResearchedDocument;              // $user->researchedDocuments()->find($id)
    public function reference(User $user, string $id): ResearchedDocumentReference;    // ::query()->forUser($user)->find($id)
    public function citation(User $user, string $id): ResearchedDocumentCitation;
    public function report(User $user, string $id): GeneratedDocumentReport;
}
```

Each method returns the model or throws its `ResourceNotFoundException` factory
(`->find($id) ?? throw ResourceNotFoundException::document()`). Use `find()` + explicit throw (not
`findOrFail()`) so the per-resource message is preserved — the framework 404 renderer would
otherwise replace it with the generic message.

Tests (F-OWN-01):

- Two users, a full `DocumentTree` for user A.
- For every finder method: owner resolves the model; the other user gets
  `ResourceNotFoundException` with the exact per-resource message.
- `forUser()` scope on reference/citation/finding/report returns only user A's rows and zero for
  user B; the scope is chainable after other query builder calls.
- Document scope: `$userB->researchedDocuments()` is empty.
- Assert the 404 response body cannot distinguish "missing" from "foreign" once HTTP endpoints
  exist (Phase 02 extends this file; the finder tests here pin the service-level invariant).

This finder is the only allowed resolution path for document-owned resources in later phases;
direct `findOrFail` on a child model outside the finder is a review blocker.

---

## 9. T5 — Rate limiters

**Modified:** `app/Providers/AppServiceProvider.php`
**Tests:** `tests/Feature/RateLimitersTest.php`

Add next to the existing `auth`/`api` limiters:

```php
RateLimiter::for('documents', fn (Request $request): Limit => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()));
RateLimiter::for('document-status', fn (Request $request): Limit => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()));
```

Tests (F-RATE-01):

- `RateLimiter::limiter('documents')` and `'document-status'` resolve to a `Limit`;
- with a request that has a user resolver, `maxAttempts` is 10 / 120 and `key` is the user id
  (anonymous → IP);
- route-level 429 smoke tests belong to Phase 02 once the routes exist.

Do not remove or weaken the existing `auth`/`api` limiters.

---

## 10. T6 — Configuration placeholders

**Modified:** `config/services.php`, new `config/scoring.php`, `.env.example`

`config/services.php` — append (readme §10 table, OQ-11: no `CROSSREF_API_KEY`):

```php
'crossref' => [
    'base_url'        => env('CROSSREF_BASE_URL', 'https://api.crossref.org'),
    'mailto'          => env('CROSSREF_MAILTO'),
    'timeout'         => (int) env('CROSSREF_TIMEOUT', 10),
    'connect_timeout' => (int) env('CROSSREF_CONNECT_TIMEOUT', 5),
    'rows'            => (int) env('CROSSREF_ROWS', 5),
    'cache_ttl'       => (int) env('CROSSREF_CACHE_TTL', 86400),
],
'inference' => [
    'base_url'             => env('INFERENCE_BASE_URL', 'http://inference:8000'),
    'timeout'              => (int) env('INFERENCE_TIMEOUT', 120),
    'connect_timeout'      => (int) env('INFERENCE_CONNECT_TIMEOUT', 5),
    'embedding_batch_size' => (int) env('INFERENCE_EMBEDDING_BATCH_SIZE', 32),
],
```

`config/scoring.php` — skeleton with provisional defaults (OQ-12: tunable in Phase 07, consumed in
Phase 04). Keys: `thresholds.valid` (0.85), `thresholds.suspicious` (0.50),
`weights.{title,authors,journal,year}` (0.45/0.25/0.15/0.15), `semantic.enabled` (true),
`year_tolerance` (1), `local_venue_keywords` (empty list with a "tuned in Phase 07" comment).
Every value gets an optional `SCORING_*` env override; the file must sum-document the weight
invariant in a comment.

`.env.example` — placeholders only, no secrets: `CROSSREF_MAILTO=` (comment: required contact
email), `CROSSREF_BASE_URL=https://api.crossref.org`, `CROSSREF_TIMEOUT=10`,
`CROSSREF_CONNECT_TIMEOUT=5`, `CROSSREF_ROWS=5`, `CROSSREF_CACHE_TTL=86400`,
`INFERENCE_BASE_URL=http://inference:8000`, `INFERENCE_TIMEOUT=120`,
`INFERENCE_CONNECT_TIMEOUT=5`, `INFERENCE_EMBEDDING_BATCH_SIZE=32`, and commented
`SCORING_*` overrides. `GOTENBERG_*` is Phase 06 — do not add it here.

No `analysis.*` / `reports.*` config in this phase (Phases 03/06 own them).

---

## 11. T7 — Shared test harness

**New files:** `tests/Support/DocumentTree.php`, `tests/Support/Fixtures.php`,
`tests/Fixtures/crossref/doi-found.json`, `tests/Unit/FixturesTest.php`

`Tests\Support\DocumentTree` — a small builder over the real factories used by every later phase:

```php
final class DocumentTree
{
    public function __construct(
        public readonly User $user,
        public readonly ResearchedDocument $document,
    ) {}

    public static function create(?User $user = null, array $attributes = []): self;
    public function reference(array $attributes = [], int $locations = 0): ResearchedDocumentReference;
    public function citation(?ResearchedDocumentReference $reference = null, array $attributes = [], int $locations = 0): ResearchedDocumentCitation;
    public function finding(ResearchedDocumentReference $reference, array $attributes = [], int $candidates = 0): ReferenceFinding;
    public function report(array $attributes = []): GeneratedDocumentReport;
}
```

Behavior:

- `create()` resolves/creates the user and a `pending` document.
- `reference()`/`citation()` fill `text_start_offset`/`occurrence_index` deterministically from the
  current max, so repeated calls on the same document never violate the unique indexes (a plain
  factory sequence resets per call).
- `citation(reference: $ref)` uses `pairedTo($ref)` so the citation and reference share the document.
- `finding()` creates `$candidates` ranked candidates (`rank` 1..N) and sets
  `selected_candidate_id` to rank 1.
- `report()` defaults to a `completed` document state for realistic fixtures.

`Tests\Support\Fixtures` — read-only JSON loader with a path constant relative to the file
(`__DIR__.'/../Fixtures'`), `json(string $path): array` using `JSON_THROW_ON_ERROR`. It must not
depend on the app container (usable from Unit tests).

Fixture now: `tests/Fixtures/crossref/doi-found.json` — one realistic Crossref `/works/{doi}`
payload (`status`, `message-type`, `message.DOI/title/container-title/author[]/issued/URL`).
Phase 03/04 add their own fixtures; the loader and directory exist from this phase.

Deliberate scoping: do **not** build the inference fake binding or the Crossref `Http::fake()`
helper yet — those clients are defined in Phases 03/04 and building fakes for non-existent
interfaces would be speculative rework. This is the harness's growth path:
`DocumentTree` + `Fixtures` (T7) → inference fakes (Phase 03) → Crossref fakes (Phase 04).

`tests/Unit/FixturesTest.php`: loading the DOI fixture returns the expected DOI/title and throws on
a missing file.

---

## 12. T8 — Documentation sync and final validation

- [x] `docs/DB_SCHEMA.md` — first block: unique index on `reference_findings.researched_document_reference_id`
      (T3) and `files.fileable_type` alias comment (T3). Verify `docs/DB_SCHEMA.png` is not
      contradicted in prose (image regeneration is out of scope).
- [x] `docs/API_SPEC.md` §7 — `type` filter list includes `reference_pending` (T1, OQ-09). No other
      spec change in this phase.
- [x] `docs/ARCHITECTURE.md` §13 — backend bullet: enums, envelope layer, domain models/factories,
      ownership finder, rate limiters, config blocks.
- [x] `AGENTS.md` §14 — same status update (models list, enums, error layer). Also check §5/§4
      model mentions if they say "only User, ResearchedDocument, File exist".
- [x] `docs/plans/backend/README.md` §3.1/§3.2 — move the new primitives from "missing" to "exists";
      update the phase-map row for Phase 01 if a status column exists there.
- [x] `docs/plans/backend/01-foundation.md` — status header points to this detailed plan.
- [x] Re-run `php artisan test --compact` (full suite) and `vendor/bin/pint --dirty --format agent`.

---

## 13. Acceptance matrix

| ID | Case | Covered by |
|---|---|---|
| F-ENUM-01 | Enum value lists exactly match `docs/API_SPEC.md` §2.6 | `tests/Unit/EnumTest.php` |
| F-ERR-01 | `ResourceNotFoundException` variants → 404 + spec message + `NOT_FOUND` | `tests/Feature/ApiErrorEnvelopeTest.php` |
| F-ERR-02 | `StateConflictException` variants → 409 + `CONFLICT` + spec message | same |
| F-ERR-03 | `InferenceUnavailableException` → 503 + `INFERENCE_UNAVAILABLE` + safe message | same |
| F-ERR-04 | Unknown route / malformed UUID / method not allowed / unknown 4xx | same + `tests/Unit/ApiErrorTest.php` |
| F-ERR-05 | Unhandled exception → 500 `SERVER_ERROR`, no internals in body | same (`assertDontSee('internal-secret')`) |
| F-RES-01 | `ApiResponse` single/collection/204 exact envelopes | `tests/Feature/ApiResponseTest.php` |
| F-OWN-01 | `forUser` scopes only the owner; foreign id → per-resource 404 | `tests/Feature/OwnershipIsolationTest.php` |
| F-DB-01 | Factories build the full tree; relations + casts + morph map resolve | `tests/Feature/DomainModelTest.php` |
| F-RATE-01 | Limiter names/values per `docs/API_SPEC.md` §2.9 | `tests/Feature/RateLimitersTest.php` (route smoke in Phase 02) |
| F-SCHEMA-01 | No schema drift; OQ-14 unique index enforced | `tests/Feature/DomainSchemaTest.php` + `docs/DB_SCHEMA.md` |
| F-CIT-06..09 (early) | All four derived citation buckets | `tests/Unit/CitationStatusTest.php` (endpoints in Phase 05) |

---

## 14. Risks, pitfalls and deliberate deviations

### 14.1 Pitfalls to avoid

1. **Catch-all renderer swallowing prepared responses** — `Throwable` must return `null` for
   `HttpResponseException` and for non-API requests, and must be registered **last**. Add the
   upload tests (413/415/422) to the T2 verification run; they are the canary.
2. **Enum casts vs DTO property types** — cast enum values reach DTOs as enum instances; a
   `string`-typed property will TypeError. Type enum fields as enums in DTOs (`ResearchedDocumentDetailData`
   in T3) and verify with the existing upload test.
3. **Morph map breaks existing assertions** — update `FileFactory` + `UploadDocumentTest` in the
   same commit as `enforceMorphMap`; a partial change leaves the suite red.
4. **Factory sequences and unique indexes** — sequences reset per factory invocation; never rely on
   them across separate calls for `(researched_document_id, occurrence_index)` or
   `(parent_id, location_index)`. `DocumentTree` computes explicit indexes; factories keep null for
   `occurrence_index` by default.
5. **`findOrFail` loses per-resource messages** — `prepareException()` converts
   `ModelNotFoundException` to a generic `NotFoundHttpException`. Use `find()` + explicit throw in
   `OwnedResourceFinder`.
6. **Decimal vs float output** — use `float` casts for coordinates/confidence so the JSON matches
   `docs/API_SPEC.md` examples; `decimal:4` would emit strings.
7. **Debug leak** — the generic 500 renderer must override `APP_DEBUG` behavior for API requests;
   the envelope test must set no special env and still not contain the internal message.
8. **Test-only routes** — register them inside `beforeEach` under `api/v1/__errors` (after the app
   boots); never add them to `routes/api.php`.
9. **`ApiResponse::collection` double wrapping** — assert `data.0.id` (not `data.data.0.id`).
10. **Rate limiter keys** — `.by($user->id ?: $request->ip())` keeps anonymous and authenticated
    buckets separate; do not key solely on IP for authenticated routes.

### 14.2 Deliberate deviations from the broad plan (accepted, documented)

1. **`ApiException` base + `ApiError` + `ApiExceptionRenderer`** instead of inline closures and
   per-exception envelope duplication: one status→code table, one message table, one test surface,
   thin `bootstrap/app.php`. Custom exceptions keep their `render()` neighbor style.
2. **`FindingType::toSeverity()` is not implemented** — severity for `citation_unreliable` depends
   on the paired reference finding, which an enum-static map cannot express. Severity maps from
   `ReferenceFindingStatus` (static) and is composed in Phase 05 for citations.
3. **OQ-14 lands in Phase 01** (the broad plan allowed Phase 01 or 04) because the models,
   factories and schema tests built here rely on the invariant.
4. **Harness scope** — only `DocumentTree` + `Fixtures` now; inference/Crossref fakes arrive with
   their clients in Phases 03/04.
5. **Enforced morph map includes `user`** — Sanctum's `tokenable` morph requires the `User` alias;
   without it, token issuance fails under `enforceMorphMap`. See §7.4.
6. **Generic 404 message** for unmatched routes/malformed UUIDs (`Sumber daya tidak ditemukan.`)
   because no resource type can be inferred before routing; valid-UUID foreign resources get the
   per-resource message from the finder. The status is still exactly `404 NOT_FOUND`.

### 14.3 Rollback

Every task is a self-contained commit. If a task must be reverted: revert its commit only; T3's
migration down drops the unique index; the morph map revert requires the `FileFactory`/test change
from the same commit. No data migration or backfill is involved.

---

## 15. Hand-off contract for Phases 02+

Phase 02 (and later) may rely on, and must not duplicate:

| Primitive | Location | Usage rule |
|---|---|---|
| Success envelopes | `App\Http\Responses\ApiResponse` | All controllers return via it |
| Error envelope + mapping | `App\Http\Responses\ApiError`, `ApiExceptionRenderer` | No ad-hoc `error` arrays |
| Domain exceptions | `App\Exceptions\{ResourceNotFoundException,StateConflictException,InferenceUnavailableException}` | Throw factories; never rebuild messages |
| Enums | `App\Enums\*` | Casts, validation (`Rule::enum`), DTO types, filters |
| Ownership | `App\Services\Ownership\OwnedResourceFinder` | Only resolution path for document-owned resources |
| Rate limiters | `documents`, `document-status` | Apply per §3.3 |
| Test harness | `Tests\Support\{DocumentTree,Fixtures}` | Build fixtures; extend rather than duplicate |
| Derived citation rule | `CitationStatus::derive()` | Phase 05 resolver calls it; never re-implement |

Also carried forward as explicit Phase 02 requirements: the upload message becomes
`Dokumen berhasil diunggah. Analisis sedang diproses.` and `AnalyzeDocumentJob` is dispatched
after commit (both are Phase 02 scope, not this phase).
