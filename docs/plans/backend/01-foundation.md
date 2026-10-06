# Phase 01 — Foundation & Shared Primitives

> **Status:** broad plan — detailed implementation plan: [`01-foundation-detail.md`](01-foundation-detail.md)
> **Depends on:** — · **Unblocks:** every later phase
> Canonical references: `docs/API_SPEC.md` §2, `docs/DB_SCHEMA.md` (first block),
> `docs/SECURITY.md` §2/§3/§9, `AGENTS.md` §19/§20.

---

## 1. Objective

Create the cross-cutting primitives every endpoint and pipeline step will reuse, so later phases
only implement domain behaviour:

1. canonical enum types (single source for statuses, steps, severities),
2. a complete, non-leaking error-envelope layer (404/409/503 and framework exceptions),
3. a single response-envelope helper (`data` / `message` / `meta`),
4. an ownership-scoping pattern that returns `404` for foreign resources,
5. all remaining domain models, relationships, factories and states,
6. rate limiters and config placeholders for the external clients,
7. the shared test harness (fakes/fixtures/helpers) later phases build on.

---

## 2. Scope

**In:** enums, exceptions + `bootstrap/app.php` renderers, `ApiResponse`, ownership trait/finder,
domain models/factories, morph map, rate limiters, config keys, route naming conventions, test
harness.

**Out:** endpoint implementations, pipeline steps, clients, scoring, DTOs beyond what this phase
needs.

---

## 3. Deliverables

### 3.1 Enums — `app/Enums/`

| Enum | Values (exact, `lower_snake_case`) | Extra behaviour (suggested) |
|---|---|---|
| `DocumentStatus` | `pending`, `processing`, `completed`, `failed` | `isTerminal()`, `allowsRetry()` |
| `AnalysisStep` | `queued`, `extracting`, `persisting`, `crossref_validation`, `embedding`, `scoring`, `resolving_citations`, `generating_report`, `completed` | ordered list helper for progress |
| `ReferenceFindingStatus` | `pending`, `valid`, `suspicious`, `invalid`, `not_found` | `isProblem()` |
| `CitationStatus` | `valid`, `unreliable`, `pending`, `hallucination` | derived only, never persisted |
| `ReportStatus` | `pending`, `processing`, `completed`, `failed` | `isTerminal()` |
| `FindingType` | `reference_invalid`, `reference_suspicious`, `reference_not_found`, `reference_pending`, `citation_unreliable`, `citation_hallucination` (OQ-09) | `toSeverity()` mapping |
| `FindingSeverity` | `high`, `medium`, `low`, `info` | ordering/rank helper for sorting |

Notes:

- Values come from `docs/API_SPEC.md` §2.6 only. A unit test pins the exact value lists so a
  later edit cannot silently change the contract.
- Use them in `Rule::enum(...)`, Eloquent casts (`'status' => DocumentStatus::class`), DTOs and
  serialization — never compare raw strings outside the enum.

### 3.2 Error envelope — `app/Exceptions/` + `bootstrap/app.php`

Add:

- `ResourceNotFoundException` (404 `NOT_FOUND`) with factories producing the spec messages:
  - `document()` → `"Dokumen tidak ditemukan."`
  - `reference()` → `"Referensi tidak ditemukan."`
  - `citation()` → `"Sitasi tidak ditemukan."`
  - `report()` → `"Laporan tidak ditemukan."`
- `StateConflictException` (409 `CONFLICT`) with factories:
  - `documentRetryNotAllowed()` → `"Analisis hanya dapat diulang untuk dokumen yang gagal."`
  - `reportNotAllowed()` → `"Laporan hanya dapat dibuat untuk dokumen yang selesai dianalisis."`
- `InferenceUnavailableException` (503 `INFERENCE_UNAVAILABLE`) with a safe message
  (`"Layanan analisis tidak tersedia. Coba lagi nanti."`) for the OQ-01/OQ-13 policy.

Extend the `bootstrap/app.php` exception renderers (existing 401/422/429 stay):

- `ModelNotFoundException` / `NotFoundHttpException` (API requests) → 404 `NOT_FOUND`.
- `AuthorizationException` / `AccessDeniedHttpException` → 403 `FORBIDDEN` (reserved; foreign
  resources must never reach this).
- `MethodNotAllowedHttpException` → 400 `BAD_REQUEST` (the canonical table has no 405).
- Generic `HttpExceptionInterface` fallback → map status to the canonical code via one lookup
  table; unknown 4xx → `BAD_REQUEST`, 5xx → `SERVER_ERROR`.
- Unhandled `Throwable` on API requests → 500 `SERVER_ERROR` with a generic message; full detail
  only in the log (`docs/SECURITY.md` §3/§10).

Design guidance:

- One private helper/table maps HTTP status → canonical code; do not scatter `match` statements.
- Exceptions that carry a user-facing message must never embed internal details; the renderer
  passes the safe message through and the logger receives the exception.
- Keep the existing style: custom exceptions render their own envelope next to their definition
  (follow `DocumentUploadFailedException`), or centralize in `bootstrap/app.php` — pick one and be
  consistent. Recommendation: custom domain exceptions render themselves; framework exceptions are
  mapped centrally.

### 3.3 Response helper — `app/Http/Responses/ApiResponse.php`

- `single(Data|array $data, ?string $message = null, int $status = 200): JsonResponse`
- `collection(LengthAwarePaginator $paginator, class-string<Data> $dataClass): JsonResponse`
  producing `{ data: [...], meta: { current_page, per_page, total, last_page } }`
- `noContent(): Response` for `204`
- Optional `accepted(...)`, `created(...)` convenience wrappers.

Guard against double-wrapping: `BaseData::defaultWrap()` returns `data` for `toArray()`, while
placing a Data object as a JSON value serializes the unwrapped payload. Add a focused test for
both single and collection envelopes.

### 3.4 Ownership scoping — `app/Models/Concerns/` + `app/Services/Ownership/`

- Trait `ScopesThroughDocument` (name TBD in detailed plan) providing
  `scopeForUser(Builder $query, User $user): Builder` for models whose ownership is derived:
  `researched_document_references`, `researched_document_citations`, `reference_findings`,
  `generated_document_reports`. Implementation uses
  `whereHas('researchedDocument', fn ($q) => $q->where('user_id', $user->getKey()))`.
- `ResearchedDocument` uses `$user->researchedDocuments()->findOrFail($id)` directly.
- `App\Services\Ownership\OwnedResourceFinder` (name TBD): `document()`, `reference()`,
  `citation()`, `report()` returning the model or throwing the matching
  `ResourceNotFoundException`.
- All UUID route parameters use `->whereUuid(...)` so malformed IDs 404 without hitting the DB.

Why explicit scoped finders instead of implicit route-model binding: middleware priority puts
`auth` before `SubstituteBindings`, so an auth-aware binding could work, but implicit bindings
would still need a separate 404 message per resource and would scatter the ownership rule. The
explicit `OwnedResourceFinder` keeps one implementation, one message source and a directly
testable ownership check. (If implicit bindings are preferred later, they must still resolve only
owned resources, return `404`, and reuse the same messages.)

### 3.5 Domain models, relationships, factories

Add models (all `HasUuids`, UUID PKs, `$fillable`, casts, relationships):

| Model | Table | Key relations |
|---|---|---|
| `ResearchedDocumentReference` | `researched_document_references` | belongsTo document; hasMany locations; hasOne finding |
| `ResearchedDocumentReferenceLocation` | `..._reference_locations` | belongsTo reference |
| `ResearchedDocumentCitation` | `researched_document_citations` | belongsTo document; belongsTo reference (nullable); hasMany locations |
| `ResearchedDocumentCitationLocation` | `..._citation_locations` | belongsTo citation (schema column is `citation_id`) |
| `ReferenceFinding` | `reference_findings` | belongsTo document/reference/selectedCandidate/reviewedBy; hasMany candidates |
| `ReferenceFindingCandidate` | `reference_finding_candidates` | belongsTo finding |
| `GeneratedDocumentReport` | `generated_document_reports` | belongsTo document; canonical `file_id` + polymorphic row (OQ-06) |

Extend the existing models:

- `ResearchedDocument`: `references()`, `citations()`, `findings()`, `reports()`, status enum cast,
  step enum cast, a document-scoped `forUser()` scope.
- `File`: morph map value cast (OQ-15); keep `morphTo`.

Factories (with states used by all later phases):

- `ResearchedDocumentFactory`: states `pending()`, `processing()`, `completed()`, `failed()`.
- `ResearchedDocumentReferenceFactory`: state `withDoi()`, `withoutDoi()`; sequence for offsets.
- `ResearchedDocumentReferenceLocationFactory`, `ResearchedDocumentCitationFactory`
  (unique `occurrence_index` sequences), `ResearchedDocumentCitationLocationFactory`.
- `ReferenceFindingFactory`: states `pending()`, `valid()`, `suspicious()`, `invalid()`,
  `notFound()`, `manual()`; relationship helpers for candidates.
- `ReferenceFindingCandidateFactory`: rank sequence per finding.
- `GeneratedDocumentReportFactory`: states `pending()`, `processing()`, `completed()`, `failed()`.

### 3.6 Rate limiters — `AppServiceProvider`

Keep `auth` and `api`; add:

```php
RateLimiter::for('documents', fn (Request $r) => Limit::perMinute(10)->by($r->user()?->id ?: $r->ip()));
RateLimiter::for('document-status', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));
```

Phases apply them to the exact routes from `docs/API_SPEC.md` §2.9.

### 3.7 Configuration placeholders

- Add `crossref` and `inference` blocks to `config/services.php` (keys in README §10), consumed
  in Phases 03/04.
- Add `config/scoring.php` skeleton (thresholds/weights/local-venue list) — filled in Phase 04.
- Add matching placeholder env vars to `.env.example` (no values, no secrets).

### 3.8 Test harness

- `tests/Fixtures/` for recorded Crossref payloads (used from Phase 04).
- `tests/Support/` for shared helpers: full document-tree builder, inference fake binding,
  Crossref `Http::fake` helper (used from Phase 03/04).
- Keep `tests/Pest.php` `RefreshDatabase` for `Feature`.
- Add a `tests/Feature/ApiErrorEnvelopeTest.php` style suite asserting the canonical envelope for
  401/403/404/409/415/422/429/500/503 using test-registered routes that throw the exceptions.

### 3.9 Documentation status

After the phase lands, update `docs/ARCHITECTURE.md` §13 and `AGENTS.md` §14 status lists.

---

## 4. Contracts & invariants

- Enum values are contract; any change requires `docs/API_SPEC.md` to change first.
- Foreign resources always yield `404` with a non-distinguishing message.
- Error payloads never contain stack traces, SQL, paths or internal messages.
- A `204` response has no body; collections always include `meta`; single resources never do.
- No new domain table/column in this phase. OQ-15 (morph map) is applied here; the OQ-14 unique
  index migration may land here or in Phase 04 — either way, update `docs/DB_SCHEMA.md` in the
  same change.

---

## 5. Tests / acceptance criteria

| ID | Case | Expected |
|---|---|---|
| F-ENUM-01 | Enum value lists | Exactly match `docs/API_SPEC.md` §2.6 |
| F-ERR-01 | `ResourceNotFoundException` variants | 404 + spec message + `error.code = NOT_FOUND` |
| F-ERR-02 | `StateConflictException` variants | 409 + `CONFLICT` + spec message |
| F-ERR-03 | `InferenceUnavailableException` | 503 + `INFERENCE_UNAVAILABLE` + safe message |
| F-ERR-04 | Unknown route / malformed UUID / method not allowed | 404 / 404 / 400 with envelope |
| F-ERR-05 | Unhandled exception in an API route | 500 `SERVER_ERROR`, no internals in body |
| F-RES-01 | `ApiResponse::single` / `collection` | Exact envelope, correct `meta` |
| F-OWN-01 | `forUser` scope on each child model | Only rows of the owning user; foreign id → finder throws 404 |
| F-DB-01 | Models/factories can build the full document tree | `DomainSchemaTest`-style relationships resolve |
| F-RATE-01 | New limiter names/vals | `429 RATE_LIMITED` after the documented counts (route smoke once routes exist) |
| F-SCHEMA-01 | No schema drift | `docs/DB_SCHEMA.md` first block still matches migrations |

Exit: `php artisan test` green, `vendor/bin/pint --dirty` clean.

---

## 6. Decisions (resolved)

All Phase 01 open decisions were resolved on 2026-10-06 (see README `Decision log`):

- **OQ-09** — `reference_pending` is part of `FindingType` (severity `info`) and the findings feed.
- **OQ-14** — unique index on `reference_findings.researched_document_reference_id` approved; add
  the migration here or in Phase 04 and update `docs/DB_SCHEMA.md`.
- **OQ-15** — enforce the morph map (`researched_document`, `generated_document_report`) and update
  the affected factories/tests in the same change.

---

## 7. Risks

- Envelope renderers interacting with framework defaults (e.g. login redirect) — the existing
  `redirectGuestsTo(null)` already prevents redirects; keep the regression test.
- Model factories with sequences can create flaky unique indexes (`occurrence_index`,
  `location_index`) — use explicit sequences and document constraints in the factory.
- Adding a morph map changes the stored `fileable_type` values asserted by
  `UploadDocumentTest`/`FileFactory`; update both in the same change.
