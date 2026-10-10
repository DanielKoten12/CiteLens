# Phase 06 — Report Generation & `/reports` Endpoints (Detailed Implementation Plan)

> **Status:** detailed plan — ready to implement (no blocking open question; §16 lists the
> defaults adopted, two deliberate schema changes and one contract-doc update flagged)
> **Parent:** [`06-reports.md`](06-reports.md)
> **Depends on:** [Phase 02](02-document-lifecycle-detail.md) (file lifecycle, endpoint shell) and
> [Phase 05](05-citation-resolution-and-review-detail.md) / [05.1](05-1-citation-resolution-robustness-detail.md) (content)
> **Unblocks:** Phase 07
> Canonical references: `docs/API_SPEC.md` §2.4/§2.5/§2.6/§2.7/§2.8/§8/§11, `docs/DB_SCHEMA.md`
> (first DBML block), `docs/ARCHITECTURE.md` §5/§8/§9/§13, `docs/SECURITY.md` §3/§7/§8/§10,
> `docs/TEST_PLAN.md` §5.3/§5.7/§5.11, `AGENTS.md` §3/§6/§7/§8/§14/§18/§19/§21.
> Task template: `docs/plans/backend/README.md` §1.4.

This document expands `06-reports.md` into executable tasks. It implements the **on-demand PDF
report slice only**: the `files.disk` column + the `generated_document_reports.file_id` FK, the
`gotenberg/gotenberg-php`-backed renderer seam, the report data builder + Blade template, the async
generation job, the `/reports` endpoints and the multi-disk file lifecycle. It does **not** touch
the analysis pipeline (`generating_report` stays bookkeeping per OQ-08), the FastAPI service, or the
frontend.

The design keeps six hard rules:

1. **Reports are generated for `completed` documents only** (`409` otherwise) and are always
   asynchronous: `store` persists + dispatches, it never renders inline.
2. **`generated_document_reports` has exactly one writer per concern.** `ReportStateService` owns
   `status`/`error`/`file_id`/`generated_at`; the job orchestrates; controllers never write it.
3. **Derived values are never persisted.** The report reuses `CitationStatusResolver`,
   `DocumentSummaryService`, `ReferenceQueryService` and `CitationQueryService`; it does not add
   citation columns or re-implement the derivation.
4. **One file lifecycle, multi-disk aware.** Report PDFs are stored through the existing
   `DocumentFileManager` (extended); every `files` row records the disk it lives on (`files.disk`),
   so documents can stay on the default disk while reports use `reports.disk` and deletion/URLs
   still resolve per file.
5. **No synchronous external calls outside the job.** Gotenberg is an internal service like
   inference; the timeout/queue/lock are configuration.
6. **No contract change silently.** OQ-07 ("multiple reports per document") contradicts the current
   `API_SPEC.md` §8 wording; this phase updates the English spec **and** the Indonesian translation
   in the same change (§12). `DB_SCHEMA.md` gains the OQ-06 `file_id` FK **and** the deliberate
   `files.disk` column (D-06-02).

> **Read first.** §16 lists the decisions worth confirming. Q-1 (`files.disk` + `reports.disk`),
> Q-5 (Gotenberg library version) and Q-11 (PSR-18 client wiring) change implementation shape;
> Q-6 is a required spec-doc edit. Everything else is a wording/config default.

---

## 1. Baseline (verified against the repository at plan creation)

| Item | Value |
|---|---|
| Laravel | `13.x`; PHP CLI `8.5`; `composer.json` requires `^8.3` |
| DTO layer | `spatie/laravel-data` 4.x; `BaseData` (wraps as `data`), `ModelData`; `#[MapName(SnakeCaseMapper::class)]` |
| Pest / DB / queue | Pest 5, in-memory SQLite, `QUEUE_CONNECTION=sync`, `CACHE_STORE=array` (`phpunit.xml`) |
| Envelope | `ApiResponse::{single,created,accepted,collection,noContent}`; `ApiError`/`ApiException`/`ApiExceptionRenderer` |
| Exceptions | `ResourceNotFoundException::report()` (`"Laporan tidak ditemukan."`) and `StateConflictException::reportNotAllowed()` (`"Laporan hanya dapat dibuat untuk dokumen yang selesai dianalisis."`) already exist |
| Ownership | `OwnedResourceFinder::report()` exists and scopes `GeneratedDocumentReport::query()->forUser($user)` |
| Model | `GeneratedDocumentReport` with `status` cast to `ReportStatus`, `generated_at` cast, `file()` and `referenceFinding()` relations, `ScopesThroughDocument`; factory with `pending/processing/completed/failed` states |
| `ReportStatus` | canonical four values + `isTerminal()` |
| File lifecycle | `DocumentFileManager` collects **report** files in `detachForDocument()` (morph `generated_document_report` + `report.file_id`); `DocumentDeletionTest` already covers document-delete cascading report files (F-REP-01). `store()` only accepts `UploadedFile` today; there is no byte-content writer and no per-file disk |
| URL rule | `App\Extensions\Data\Injectors\UrlFromFilePath` resolves `FilePreviewData->url` via `temporaryUrl(...)` when the disk provides it, else `url(...)`; the `local` disk has `serve => true`. The disk is currently read from `config('filesystems.default')`, never from the file row |
| Read model | `DocumentSummaryService::countsFor()`, `ReferenceQueryService::paginate()`, `CitationQueryService::paginate()`, `CitationStatusResolver::{resolve,sqlExpression}`, `FindingsFeedQuery` (citation messages as private constants) |
| Config | `config/services.php` has `crossref` + `inference` blocks; **no** `gotenberg`; `config/analysis.php` has `generating_report` progress 95→99; **no** `config/reports.php`; `.env.example` has no `GOTENBERG_*`/`REPORTS_*` |
| Dependencies | `guzzlehttp/guzzle` `8.2.0` is already a **production** dependency (via `laravel/framework`), `guzzlehttp/psr7` `3.1.0`; `php-http/discovery` is **not** installed. `gotenberg/gotenberg-php` is not in `composer.json` |
| Routes | `routes/api.php` has auth + documents + references/citations/findings; **no** `/reports` routes |
| Views | `resources/views/` contains only `welcome.blade.php`; no `reports/` directory |
| Providers | `bootstrap/providers.php` registers `AppServiceProvider` + `AnalysisServiceProvider`; no report seam binding |
| Schema | `generated_document_reports.file_id` is a plain nullable UUID — **no FK** (OQ-06 pending); `files` is polymorphic with no `fileable_id` FK and **no `disk` column** |
| Test harness | `Tests\Support\{AnalysisHarness,DocumentTree,Fixtures,InferenceFake,CrossrefFake}`; `DocumentTree::report()` marks the document completed first |

Facts that constrain the implementation:

- `files` has **no disk column**: `UrlFromFilePath` and `DocumentFileManager::deleteStoredObjects()`
  both assume `config('filesystems.default')`. D-06-02 adds `files.disk` (backfilled by the
  migration) so `reports.disk` can differ from the document disk while deletion/URLs resolve per
  file; every writer must then persist the disk it used.
- `DocumentAnalysisResetService::reset()` deletes citations/findings before a re-run but report rows
  survive (they hang off the document); a report generated before a retry would be stale. Retry is
  only possible from `failed`, so a document with reports cannot be retried today — no extra guard
  needed (recorded as an assumption).
- `QUEUE_CONNECTION=sync` means `POST /reports` runs the job inline in tests that do not fake the
  bus; endpoint tests must use `Bus::fake()` for the "job dispatched, row pending" assertion and a
  separate sync test for the completed path.
- `DomainSchemaTest` uses `DB::table()` inserts; `Schema::getForeignKeys()` is available for the FK
  assertion.
- `FindingsEndpointTest` pins one citation message
  (`'Sitasi belum dapat ditautkan secara pasti ke referensi.'`) — the message refactor in T3 must
  keep it byte-identical.
- `gotenberg/gotenberg-php` v2.x is the official client for Gotenberg 8.x (MIT): it builds the
  PSR-7 multipart request (`Gotenberg::chromium($url)->pdf()->html(Stream::string('index.html',
  $html))`), sends it through a PSR-18 client, and throws `GotenbergApiErrored` on non-2xx. Guzzle 8
  is already installed, so `GuzzleHttp\Client` (with configured timeouts) can be injected instead of
  relying on `php-http/discovery`.

---

## 2. Execution model

### 2.1 Task order and dependencies

| Task | Deliverable | Size | Depends on | Suggested commit |
|---|---|---|---|---|
| **T0** | Preflight & baseline | S | — | (no commit) |
| **T1** | Migrations: `files.disk` column (backfill) + `file_id → files.id` FK (`nullOnDelete`) + `DB_SCHEMA.md` + model/factory/schema tests | M | — | `feat(backend): record file disks and link report files` |
| **T2** | Report config, `gotenberg/gotenberg-php` dependency, `ReportRenderer` seam, provider, fakes + tests | M | — | `feat(backend): add gotenberg pdf renderer client` |
| **T3** | File lifecycle extension + URL resolver + report read model + data builder + Blade template + labels/messages | L | — | `feat(backend): build report content and template` |
| **T4** | `ReportStateService`, `ReportFailureHandler`, `ReportDeletionService`, `GenerateDocumentReportJob` + tests | L | T1, T2, T3 | `feat(backend): generate document reports asynchronously` |
| **T5** | `/reports` endpoints: generation/query services, DTOs, FormRequest, `ReportController`, routes + tests | L | T1–T4 | `feat(backend): expose report endpoints` |
| **T6** | Full acceptance matrix: T-REP-01..06, T-OWN-08, F-REP-01/02 + e2e | M | T5 | `test(backend): cover report generation and file lifecycle` |
| **T7** | Docs sync (API spec EN/ID, ARCHITECTURE, SECURITY, TEST_PLAN, AGENTS, plans README) + final validation | S | T1–T6 | `docs: sync report generation status` |

T1, T2 and T3 are independent and can land in parallel. T4 needs all three. T5 needs T4. Only T1
touches the schema.

### 2.2 Per-task loop

1. Re-read the task and the canonical `docs/API_SPEC.md` §8 section it cites.
2. Implement the task and its tests in the **same** change.
3. `vendor/bin/pest <affected paths>` — affected tests only.
4. `vendor/bin/pint --dirty --format agent` — format changed PHP files.
5. `php artisan test --compact` — full suite stays green.
6. Only T7 touches status docs (except T1's `DB_SCHEMA.md` change, which belongs to the migration).

### 2.3 Exit criteria (from `06-reports.md` §5)

- T-REP-01..06, T-OWN-08 and F-REP-01/02 pass (F-REP-01 is already covered by
  `DocumentDeletionTest`; T6 extends it with the report-deletion path).
- `POST /documents/{document}/reports` returns `202` + `"Laporan sedang dibuat."` and dispatches
  `GenerateDocumentReportJob`; non-completed documents return `409` with the exact spec message.
- `download_url` is `null` until the report is `completed`, then a temporary/signed URL on the
  private disk; `error` is a safe message for `failed` reports.
- Job success sets `completed` + `generated_at` + `file_id`; job failure sets `failed` + safe
  `error` with the raw exception only in the log.
- Deleting a report removes its row, its `files` row and the stored object; deleting a document
  still cleans report files (existing test stays green).
- Report files are stored on `reports.disk` (default `filesystems.default`) and every `files` row
  records its `disk`; deleting a report/document removes each object from the disk it lives on.
- Foreign document reports / foreign `GET`+`DELETE /reports/{report}` return `404` with the
  per-resource message and no existence disclosure.
- `php artisan test --compact` green; `vendor/bin/pint --dirty --format agent` reports nothing.
- `docs/DB_SCHEMA.md` carries the OQ-06 FK **and** the `files.disk` column; `docs/API_SPEC.md` §8 no
  longer says "one report per document" (OQ-07) and documents the `download_url`/`error` lifecycle;
  `API_SPEC_ID.md` mirrors it.
- No secret/`.env` value committed; `.env.example` gains placeholders only.

---

## 3. Conventions this phase locks in

Reused from Phases 01–05 (do not re-implement):

- Responses only through `ApiResponse`/`ApiError`; no hand-built envelopes.
- Document-owned lookups through `OwnedResourceFinder` (`report()` for `/reports/{report}`,
  `document()` for `/documents/{document}/reports`).
- Derived citation status only through `CitationStatus::derive()` /
  `CitationStatusResolver::{resolve,sqlExpression}`.
- List endpoints validate filters with `Rule::enum(...)`/integers, paginate deterministically
  (sort + `id` tiebreak) and return the canonical `data`/`meta` collection envelope.
- One business rule per service; controllers are transport only.
- `final class` + constructor property promotion + explicit types; PHPDoc over inline comments;
  `snake_case` enum values at the API boundary.
- Thresholds/limits/timeouts in config, never literals in services.
- Private disk only; download URLs temporary/signed; `{!! !!}` is forbidden in Blade.

Phase-06-specific conventions:

- **One status writer.** `ReportStateService` is the only place that writes
  `generated_document_reports.status|error|file_id|generated_at`. The job and the `failed()` hook
  call it; the endpoints never do.
- **One file lifecycle, disk-aware.** `DocumentFileManager` stays the only writer of `files` rows +
  stored objects; each row records the disk it lives on, report PDFs go through a new byte-content
  method with an explicit disk, and detach/delete resolve per row.
- **One URL rule, disk-aware.** `PrivateFileUrlResolver` is the single implementation; the existing
  `UrlFromFilePath` injector delegates to it with the row's disk.
- **One message source.** Citation-issue messages live on `CitationStatus::message()`; the report
  and the findings feed both read it.
- **Blade renders data; the job orchestrates.** Blade → HTML (`ReportTemplateRenderer`), HTML →
  PDF (`ReportRenderer`), PDF → disk (`DocumentFileManager`). No HTML string is ever built inside
  the job.
- **Failure messages are safe and centralized** in `ReportFailureHandler`; raw exceptions go to the
  log with report/document ids only (`docs/SECURITY.md` §10).

---

## 4. Decision log additions

These extend the README `Decision log` and the Phase 01–05 additions. D-06-01..D-06-09 and
D-06-11..D-06-16 are internal implementation decisions; D-06-10 changes the canonical spec and is
applied in T7.

| ID | Question | Decision |
|---|---|---|
| **D-06-01** | Broad plan names a `ReportFileManager`; Phase 02 D-02-06 made `DocumentFileManager` the owner of every `files` row + object. | **Keep one manager, make it disk-aware.** `DocumentFileManager` gains `storePdf(Model $owner, string $pdf, string $filename, ?string $disk = null): File` and `detachForReport(GeneratedDocumentReport): list<array{disk: string, path: string}>`; `store()` persists the disk it used, `delete()`/`deleteStoredObjects()` resolve `$file->disk`, and the directory becomes morph-aware (`reports/{reportId}` for reports, `documents/{documentId}` otherwise). No second file manager. |
| **D-06-02** | How can `reports.disk` differ from `filesystems.default` when `files` has no disk column? | **Add the `files.disk` column** (deliberate canonical-schema change, alongside OQ-06): `varchar not null`, backfilled by the migration from `config('filesystems.default')`; every writer persists the disk explicitly. `config/reports.php` keeps `reports.disk` (default `filesystems.default`) and only the report path uses it. This is the schema extension the broad plan implied but the current schema lacked (Q-1). |
| **D-06-03** | How do report DTOs resolve `download_url`? | Extract `App\Services\Files\PrivateFileUrlResolver` with `resolve(?string $path, ?string $disk = null)` (temporary URL when supported, else `url()`, `null` on failure) and make `UrlFromFilePath` delegate to it, passing the payload's `disk`. The report controller resolves the URL with `$report->file?->disk`; DTO factories stay pure and take `?string $downloadUrl`. |
| **D-06-04** | `store` 202 body shape in `API_SPEC.md` does not show `download_url`. | Reuse `GeneratedDocumentReportSummaryData` for `store` (adds `download_url: null`) and `GET /documents/{document}/reports`; `GeneratedDocumentReportDetailData` adds `error` for `GET /reports/{report}`. Additive nullable fields, same rationale as D-02-05; no new "action" DTO. |
| **D-06-05** | Where do citation-issue messages come from? | `CitationStatus::message()` becomes the single source (moved out of `FindingsFeedQuery`'s private constants, which now read the enum). The report's issue filter uses the same three statuses as the feed (`unreliable|unresolved|hallucination`) through a new `CitationQueryService::issues()`; no severity/id union is re-implemented. |
| **D-06-06** | Who writes report state and maps failures? | `ReportStateService` (`markProcessing` claims `pending\|processing` atomically, `markCompleted` returns `false` when the row vanished, `markFailed` is a no-op for missing/terminal rows) + `ReportFailureHandler` (log + safe message mapping, mirroring `AnalysisFailureHandler`). The job deletes the just-stored file when `markCompleted` returns `false` (report deleted concurrently). |
| **D-06-07** | Job envelope and retry semantics. | Payload is the report id string; `tries = 1`; `WithoutOverlapping("report-generation:{id}")` with `expireAfter(timeout + buffer)` and `dontRelease()` (mirrors `AnalyzeDocumentJob`); `afterCommit()` dispatch. Regeneration is a new `store` call (OQ-07), never a queue retry. |
| **D-06-08** | Report labels/messages for humans. | Indonesian labels live on the derived enums: `ReferenceFindingStatus::label()` and `CitationStatus::label()` (+ `CitationStatus::message()`). API serialization is unchanged (enum values only); the Blade view renders labels/counts, the API keeps machine values. |
| **D-06-09** | Where does Blade rendering live? | A dedicated `ReportTemplateRenderer` (Blade → HTML string). `ReportRenderer` stays an HTML → PDF-bytes seam, so both are unit-testable independently and a future renderer (e.g. a different Chromium service) does not touch views. |
| **D-06-10** | `API_SPEC.md` §8 says "One report per document", but OQ-07 resolved multiple report rows. | **Update the spec** (English + Indonesian translation) to "multiple reports per document; a failed one can be regenerated by calling `POST` again", plus the `download_url`/`error` lifecycle clarification. Canonical contract change, applied in T7 and called out in the summary. |
| **D-06-11** | Missing `reference_findings` row for a reference in the report table. | Show `pending` (`ReferenceFindingStatus::Pending::label()`), matching the reference-list `status=pending` filter semantics. `confidence`/`reason` stay `null`. Manual-review indicator is not part of OQ-16 and is omitted (Q-4). |
| **D-06-12** | Report content bounds. | `reports.text_preview_length` (default 500) truncates `raw_text`, titles and citation text with `Str::limit`; full row counts are not capped (the report is a whole-document artifact). Gotenberg/job timeouts are config. |
| **D-06-13** | Report file naming/paths. | Directory `reports/{reportId}`, object `reports/{reportId}/{uuid}.pdf`, `filename = laporan-{reportId}.pdf`, mime `application/pdf`. The `files.filename` is not exposed by the report API (only `download_url`). |
| **D-06-14** | How is Gotenberg called? | Use the official `gotenberg/gotenberg-php` client (`^2.25`, MIT, maps to Gotenberg 8.x) instead of a hand-rolled HTTP call: `Gotenberg::chromium($baseUrl)->pdf()->paperSize(...)->margins(...)->html(Stream::string('index.html', $html))` builds the PSR-7 multipart request, and `Gotenberg::send($request, $client)` returns the response, throwing `GotenbergApiErrored` on non-2xx. Dependency justified by the phase plan (correct multipart, maintained client, less code); `docs/ARCHITECTURE.md` §3 and `AGENTS.md` §14 dependency notes are updated in T7. |
| **D-06-15** | Which PSR-18 client does the library use? | Inject one explicitly: `ReportServiceProvider` builds `GuzzleHttp\Client` (already a production dependency) with `timeout`/`connect_timeout` from `services.gotenberg`, and `GotenbergReportRenderer` passes it to `Gotenberg::send()`. Tests use Guzzle's `MockHandler` and assert the captured PSR-7 request; `Psr18ClientDiscovery::find()` is not relied upon (no timeout control, hard to test). |
| **D-06-16** | `files.disk` migration/backfill semantics. | One migration adds `string('disk')->default(config('filesystems.default'))->after('path')`, so existing rows are backfilled with the then-configured default in a single `ALTER` (portable on SQLite/MySQL/Postgres). The app always writes `disk` explicitly; the column default is a safety net for raw inserts/factories only. Rows on another disk than the current default are not detectable and keep the default — documented as an assumption (Q-12). |

---

## 5. T0 — Preflight & baseline

- [ ] `cd backend && composer install` (if `vendor/` is stale).
- [ ] `php artisan test --compact` → all existing tests pass.
- [ ] `git status` clean / expected branch (`feat/report-generation`).
- [ ] Re-read `06-reports.md` §3–§7 and the README `Decision log`; §16 defaults are adopted.
- [ ] Confirm no report routes exist: `php artisan route:list --path=api/v1` shows no
      `/reports` entries.
- [ ] Confirm `config('services.gotenberg')` is absent and `.env.example` has no `GOTENBERG_*` /
      `REPORTS_*` keys.
- [ ] Confirm `Schema::hasColumn('files', 'disk')` is false and
      `Schema::hasColumn('generated_document_reports', 'file_id')` is true with
      `Schema::getForeignKeys('generated_document_reports')` containing **no** `file_id` FK.
- [ ] Confirm `gotenberg/gotenberg-php` is **not** in `composer.json` while `guzzlehttp/guzzle` is
      installed; decide `REPORTS_DISK` (default the `local` private disk with `serve => true`, or an
      equivalent private disk).
- [ ] Confirm `resources/views/reports/` does not exist.
- [ ] Confirm `DocumentDeletionTest` passes (F-REP-01 guard) and
      `OwnedResourceFinder::report()` / `ResourceNotFoundException::report()` exist.
- [ ] Confirm `CitationStatus::message()` does not exist and `FindingsFeedQuery` owns the three
      citation messages as constants (T3 will move them).

No commit for T0.

---

## 6. T1 — Schema: `files.disk` column + `file_id` FK (`nullOnDelete`)

**New files:**
`database/migrations/<timestamp>_add_disk_to_files_table.php`,
`database/migrations/<timestamp>_add_file_foreign_key_to_generated_document_reports_table.php`
**Modified:** `docs/DB_SCHEMA.md` (first DBML block only), `app/Models/File.php` (`$fillable`),
`database/factories/FileFactory.php`
**Tests:** `tests/Feature/DomainSchemaTest.php` (extend), `tests/Unit/DomainModelTest.php` (factory)

### 6.1 `files.disk` migration (D-06-02/D-06-16)

```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('files', function (Blueprint $table): void {
            $table->string('disk')
                ->default((string) config('filesystems.default'))
                ->after('path');
        });
    }

    public function down(): void
    {
        Schema::table('files', function (Blueprint $table): void {
            $table->dropColumn('disk');
        });
    }
};
```

Notes:

- The default backfills existing rows in the same `ALTER` (SQLite rebuilds the table;
  MySQL/Postgres fill existing rows). The column is `not null`; the app always writes it explicitly
  and the default is only a safety net for raw inserts/factories.
- The default value is deployment-dependent (`config('filesystems.default')`), so `DB_SCHEMA.md`
  documents the column as `not null` with a comment, not a literal default.
- No index: `disk` is never filtered on; every lookup goes through `id` or the polymorphic keys.

### 6.2 `File` model + factory

- `File::$fillable` gains `'disk'`.
- `FileFactory::definition()` gains `'disk' => (string) config('filesystems.default')`, so tests
  creating rows directly always carry a real disk.
- No accessor fallback: after the migration the column is non-null. `DocumentFileManager` still
  treats a `null` disk defensively as the default disk (§8.2) so a half-migrated environment cannot
  crash a delete.

### 6.3 report `file_id` FK migration (OQ-06)

```php
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('generated_document_reports', function (Blueprint $table): void {
            $table->foreign('file_id')->references('id')->on('files')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('generated_document_reports', function (Blueprint $table): void {
            $table->dropForeign(['file_id']);
        });
    }
};
```

Notes:

- The column already exists (`create_initial_tables`); this migration only adds the constraint
  (OQ-06). Laravel's SQLite grammar handles the table-alter FK path (the existing
  `selected_candidate_id` FK proves it), so the in-memory test DB and MySQL/Postgres all work.
- `nullOnDelete` (never cascade) is required: deleting the `files` row must **not** delete the
  report; it only clears the canonical pointer. `DocumentFileManager::detachForDocument()` deletes
  `files` rows before the document cascade, so the pointer is nulled, then the report is cascaded.
- No backfill: `file_id` is null for every existing row.

### 6.4 `DB_SCHEMA.md`

In the **first** DBML block only:

- `files` table: add `disk varchar [not null]` with the comment
  `// Private filesystem disk the object lives on; backfilled from the app default (D-06-02).`
- report `file_id`: annotate
  `// Nullable canonical pointer to the stored PDF; nulled when the file row is deleted (OQ-06).`
- add to the `// Relationships` list:
  `Ref: generated_document_reports.file_id > files.id`

The stale second block is left untouched (`AGENTS.md` §15.3).

### 6.5 Tests (`DomainSchemaTest`, `DomainModelTest`)

Imports used by the new cases: `Tests\Support\DocumentTree`, `App\Enums\ReportStatus`,
`App\Models\File`, `Illuminate\Support\Facades\{DB,Schema}`, `Illuminate\Support\Str`.

```php
it('adds a non-null disk column to files', function () {
    $column = collect(Schema::getColumns('files'))->firstWhere('name', 'disk');

    expect($column)->not->toBeNull()
        ->and($column['nullable'])->toBeFalse();
});

it('backfills the configured default disk on raw inserts', function () {
    $document = DocumentTree::create()->document;
    $id = (string) Str::uuid();

    DB::table('files')->insert([
        'id' => $id,
        'fileable_type' => 'researched_document',
        'fileable_id' => $document->getKey(),
        'filename' => 'x.pdf',
        'path' => 'documents/x/y.pdf',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(File::query()->whereKey($id)->value('disk'))->toBe((string) config('filesystems.default'));
});

it('persists the disk on factory rows', function () {
    expect(File::factory()->create()->disk)->toBe((string) config('filesystems.default'));
});

it('declares the report file foreign key', function () {
    $foreignKeys = collect(Schema::getForeignKeys('generated_document_reports'));

    expect($foreignKeys->contains(
        fn (array $fk): bool => $fk['columns'] === ['file_id'] && $fk['foreign_table'] === 'files'
    ))->toBeTrue();
});

it('nulls the report file pointer when the file row is deleted', function () {
    $report = DocumentTree::create()->report();
    $file = File::factory()->create([
        'fileable_type' => 'generated_document_report',
        'fileable_id' => $report->getKey(),
    ]);
    $report->update(['file_id' => $file->getKey()]);

    $file->delete();

    expect($report->fresh()->file_id)->toBeNull()
        ->and($report->fresh()->status)->toBe(ReportStatus::Pending);
});
```

Commit: `feat(backend): record file disks and link report files`.

---

## 7. T2 — Report config, Gotenberg client and the renderer seam

**New files:**
`app/Services/Reports/Contracts/ReportRenderer.php`, `app/Services/Reports/GotenbergReportRenderer.php`,
`app/Exceptions/ReportRenderingException.php`, `app/Providers/ReportServiceProvider.php`,
`config/reports.php`, `tests/Support/FakeReportRenderer.php`,
`tests/Feature/GotenbergReportRendererTest.php`
**Modified:** `config/services.php`, `.env.example`, `bootstrap/providers.php`, `composer.json`,
`composer.lock`

> **Repo note:** the broad plan says `services.gotenberg.*` was "created in Phase 01" — verified
> against the repo, it does not exist (`config/services.php` has only `crossref` + `inference`).
> T2 creates it, together with `config/reports.php`, the `.env.example` placeholders and the
> `composer require gotenberg/gotenberg-php:^2.25` dependency (D-06-14).

### 7.1 Config

`config/reports.php`:

```php
return [
    // Private disk report PDFs are stored on; every `files` row records it (D-06-02).
    // Must name a disk defined in config/filesystems.php.
    'disk' => env('REPORTS_DISK', env('FILESYSTEM_DISK', 'local')),

    // Queue the report job runs on (separate from `analysis` when configured).
    'queue' => env('REPORTS_QUEUE', 'default'),

    // Hard ceiling for one report run (data build + Blade + Gotenberg + store).
    // Must stay above `services.gotenberg.timeout`.
    'timeout' => (int) env('REPORTS_TIMEOUT', 300),

    // Extra seconds the overlap lock outlives the job timeout.
    'lock_expiry_buffer' => (int) env('REPORTS_LOCK_EXPIRY_BUFFER', 60),

    // Blade view rendered to HTML before the renderer.
    'template' => env('REPORTS_TEMPLATE', 'reports.document-report'),

    // Character cap applied to extracted text/titles in the report.
    'text_preview_length' => (int) env('REPORTS_TEXT_PREVIEW_LENGTH', 500),

    // Gotenberg Chromium options (inches, matching the Gotenberg form fields).
    'pdf' => [
        'paper_width' => env('REPORTS_PAPER_WIDTH', '8.27'),
        'paper_height' => env('REPORTS_PAPER_HEIGHT', '11.7'),
        'margin_top' => env('REPORTS_MARGIN_TOP', '0.4'),
        'margin_bottom' => env('REPORTS_MARGIN_BOTTOM', '0.4'),
        'margin_left' => env('REPORTS_MARGIN_LEFT', '0.4'),
        'margin_right' => env('REPORTS_MARGIN_RIGHT', '0.4'),
    ],
];
```

`config/services.php` gains:

```php
/*
| Gotenberg HTML→PDF rendering (`docs/API_SPEC.md` §8). Internal service on the
| private network, exactly like inference: never exposed, never called by the browser.
*/
'gotenberg' => [
    'base_url' => env('GOTENBERG_URL', 'http://gotenberg:3000'),
    'timeout' => (int) env('GOTENBERG_TIMEOUT', 60),
    'connect_timeout' => (int) env('GOTENBERG_CONNECT_TIMEOUT', 5),
],
```

`.env.example` additions (placeholders only):

```dotenv
# Gotenberg HTML→PDF (internal service; private network only).
GOTENBERG_URL=http://gotenberg:3000
GOTENBERG_TIMEOUT=60
GOTENBERG_CONNECT_TIMEOUT=5

# Report generation (see config/reports.php). REPORTS_DISK must name a private
# disk configured in config/filesystems.php; every files row records the disk it used.
REPORTS_DISK=local
REPORTS_QUEUE=default
REPORTS_TIMEOUT=300
REPORTS_LOCK_EXPIRY_BUFFER=60
# REPORTS_TEXT_PREVIEW_LENGTH=500
# REPORTS_PAPER_WIDTH=8.27
# REPORTS_PAPER_HEIGHT=11.7
# REPORTS_MARGIN_TOP=0.4
# REPORTS_MARGIN_BOTTOM=0.4
# REPORTS_MARGIN_LEFT=0.4
# REPORTS_MARGIN_RIGHT=0.4
```

Report PDFs are written to `reports.disk` and the resulting `files` row records that disk
(D-06-02); document files keep `filesystems.default`.

### 7.2 `ReportRenderingException` (internal, never rendered)

```php
final class ReportRenderingException extends RuntimeException
{
    public static function serviceUnavailable(?Throwable $previous = null): self;
    public static function unexpectedResponse(int $status, ?Throwable $previous = null): self;
    public static function malformedResponse(?Throwable $previous = null): self;
}
```

Not an `ApiException`: report generation is asynchronous, so there is no synchronous HTTP response
to render. The job maps it to a safe `error` (T4). Message style mirrors
`ExtractionFailedException`/`CrossrefUnavailableException`.

### 7.3 `ReportRenderer` (contract)

```php
namespace App\Services\Reports\Contracts;

interface ReportRenderer
{
    /**
     * Render a self-contained HTML document to PDF bytes.
     *
     * @throws ReportRenderingException when the renderer is unavailable or returns an invalid PDF
     */
    public function render(string $html): string;
}
```

### 7.4 `GotenbergReportRenderer` (D-06-14/D-06-15)

Composer: `composer require gotenberg/gotenberg-php:^2.25` — the official client for Gotenberg 8.x
(MIT). It builds the PSR-7 multipart request and delegates transport to an injected PSR-18 client.

```php
use Gotenberg\Exceptions\GotenbergApiErrored;
use Gotenberg\Gotenberg;
use Gotenberg\Stream;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Client\NetworkExceptionInterface;

final class GotenbergReportRenderer implements ReportRenderer
{
    public function __construct(
        private readonly ClientInterface $http,
    ) {}

    public function render(string $html): string
    {
        $request = Gotenberg::chromium((string) config('services.gotenberg.base_url'))
            ->pdf()
            ->paperSize(
                (string) config('reports.pdf.paper_width'),
                (string) config('reports.pdf.paper_height'),
            )
            ->margins(
                (string) config('reports.pdf.margin_top'),
                (string) config('reports.pdf.margin_bottom'),
                (string) config('reports.pdf.margin_left'),
                (string) config('reports.pdf.margin_right'),
            )
            ->html(Stream::string('index.html', $html));

        try {
            $response = Gotenberg::send($request, $this->http);
        } catch (GotenbergApiErrored $exception) {
            throw ReportRenderingException::unexpectedResponse($exception->getCode(), $exception);
        } catch (NetworkExceptionInterface|ClientExceptionInterface $exception) {
            throw ReportRenderingException::serviceUnavailable($exception);
        }

        $body = (string) $response->getBody();

        if ($body === '' || ! str_starts_with(ltrim($body), '%PDF')) {
            throw ReportRenderingException::malformedResponse();
        }

        return $body;
    }
}
```

Notes:

- The library builds the exact multipart form Gotenberg expects (`index.html` part;
  `paperWidth`/`paperHeight`/`margin*` fields) — no hand-rolled `Http::attach()` duplication.
- `Gotenberg::send()` throws `GotenbergApiErrored` on non-2xx. Its message is Gotenberg's response
  body, which is logged as part of the exception chain; only the safe message is persisted
  (`docs/SECURITY.md` §10).
- Transport failures/timeouts surface as PSR-18 `NetworkExceptionInterface`/`ClientExceptionInterface`
  from the injected client → `serviceUnavailable`. Guzzle's `sendRequest()` forces
  `http_errors => false`, so non-2xx responses always reach `Gotenberg::send()` and become
  `GotenbergApiErrored`; any other transport exception falls through to the job's catch-all (which
  marks the report `failed` with the default safe message).
- The `%PDF` sniff stays as a cheap corruption guard even though the library handles the form.

### 7.5 Provider wiring

`ReportServiceProvider` (registered in `bootstrap/providers.php` after `AnalysisServiceProvider`):

```php
use GuzzleHttp\Client;
use Psr\Http\Client\ClientInterface;

final class ReportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // A dedicated PSR-18 client so Gotenberg gets explicit timeouts instead of
        // relying on php-http/discovery (D-06-15). Guzzle is already installed as a
        // production dependency via laravel/framework.
        $this->app->singleton(ClientInterface::class, fn (): ClientInterface => new Client([
            'timeout' => (int) config('services.gotenberg.timeout'),
            'connect_timeout' => (int) config('services.gotenberg.connect_timeout'),
        ]));

        $this->app->bind(ReportRenderer::class, GotenbergReportRenderer::class);
    }
}
```

`bind` (not `singleton`) for the renderer so tests can override the seam with
`$this->instance(...)` and a fresh renderer is resolved per job. The `ClientInterface` singleton is
only consumed by the renderer in this codebase (documented in the class docblock).

### 7.6 Test double

`tests/Support/FakeReportRenderer`:

```php
final class FakeReportRenderer implements ReportRenderer
{
    public string $lastHtml = '';

    /** @param  callable(): void|null  $onRender  runs before the result is returned */
    public function __construct(
        private readonly string $pdf = '%PDF-1.4 fake report',
        private readonly ?Throwable $failure = null,
        private readonly mixed $onRender = null,
    ) {}

    public function render(string $html): string
    {
        $this->lastHtml = $html;

        if ($this->onRender !== null) {
            ($this->onRender)();
        }

        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->pdf;
    }
}
```

### 7.7 Tests (`GotenbergReportRendererTest`)

Use Guzzle's `MockHandler` (the same client class as production) and inspect the captured PSR-7
request:

```php
$handler = new MockHandler([new Response(200, [], '%PDF-1.4 ok')]);
$renderer = new GotenbergReportRenderer(new Client(['handler' => HandlerStack::create($handler)]));

expect($renderer->render('<html>hello</html>'))->toBe('%PDF-1.4 ok');

$request = $handler->getLastRequest();
expect((string) $request->getUri())->toBe('http://gotenberg:3000/forms/chromium/convert/html')
    ->and((string) $request->getBody())->toContain('index.html', '<html>hello</html>', 'paperWidth');
```

- `it('returns the pdf bytes and targets the chromium html endpoint')` — including the configured
  base URL.
- `it('sends the configured paper size and margins')` — config overrides appear in the multipart
  body.
- `it('maps a non-2xx response to unexpectedResponse')` — `MockHandler` with
  `new Response(500, [], 'boom')`; assert `ReportRenderingException` and the previous exception.
- `it('maps a connection failure to serviceUnavailable')` — `MockHandler` queuing a
  `GuzzleHttp\Exception\ConnectException`.
- `it('rejects an empty or non-pdf body')` — 200 with `''`/`<html>` → `malformedResponse`.
- `it('binds the gotenberg renderer by default')` — `app(ReportRenderer::class)` is
  `GotenbergReportRenderer`.

Commit: `feat(backend): add gotenberg pdf renderer client`.

---

## 8. T3 — File lifecycle, URL rule, report read model, data building and template

**New files:**
`app/Services/Files/PrivateFileUrlResolver.php`,
`app/Services/Reports/{ReportPayload,ReportReferenceRow,ReportCitationRow,ReportDataBuilder,ReportTemplateRenderer}.php`,
`resources/views/reports/document-report.blade.php`,
`tests/Feature/{ReportDataBuilderTest,ReportTemplateRendererTest,PrivateFileUrlResolverTest,DocumentFileManagerTest}.php`
**Modified:** `app/Extensions/Data/Injectors/UrlFromFilePath.php`,
`app/Services/Document/{DocumentFileManager,DocumentDeletionService}.php`,
`app/Services/Reference/ReferenceQueryService.php`,
`app/Services/Citation/CitationQueryService.php`, `app/Services/Findings/FindingsFeedQuery.php`,
`app/Enums/CitationStatus.php`, `app/Enums/ReferenceFindingStatus.php`,
`tests/Unit/CitationStatusTest.php`

### 8.1 `PrivateFileUrlResolver` + `UrlFromFilePath` delegation (D-06-03)

```php
final class PrivateFileUrlResolver
{
    /**
     * A time-limited URL for a private-disk object, or null when it cannot be built.
     */
    public function resolve(?string $path, ?string $disk = null): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        $disk = Storage::disk($disk ?? (string) config('filesystems.default'));

        try {
            return $disk->providesTemporaryUrls()
                ? $disk->temporaryUrl($path, now()->addHour())
                : $disk->url($path);
        } catch (Throwable) {
            return null;
        }
    }
}
```

`UrlFromFilePath::resolve()` becomes:

```php
return app(PrivateFileUrlResolver::class)->resolve(
    $payload[$this->field] ?? null,
    $payload['disk'] ?? null,
);
```

The injector already reads `$payload[$this->field]` (array or Eloquent model), so `$payload['disk']`
uses the same access path. `shouldBeReplacedWhenPresentInPayload()` stays `true`;
`FilePreviewData` behavior is byte-identical for default-disk files (T3 test + existing
`UploadDocumentTest` are the regression guard) and now resolves a report file correctly when its
row lives on `reports.disk`.

### 8.2 `DocumentFileManager` extension (D-06-01)

```php
/**
 * Store an uploaded file on the given disk (default: the app default) and create
 * its `files` row. The disk is persisted on the row.
 *
 * @throws FileStorageException when the disk write fails
 */
public function store(Model $owner, UploadedFile $file, string $filename, ?string $disk = null): File
{
    $disk = $this->resolveDisk($disk);
    $path = $file->storeAs($this->directory($owner), $file->hashName(), $disk);

    if ($path === false) {
        throw FileStorageException::diskWriteFailed();
    }

    return $this->createRow($owner, $filename, $path, $disk, $file->getMimeType(), $file->getSize());
}

/**
 * Store generated bytes for a morph owner on the given disk and create its row.
 *
 * Object first; if the row cannot be created the object is removed again.
 *
 * @throws FileStorageException when the disk write fails
 */
public function storePdf(Model $owner, string $pdf, string $filename, ?string $disk = null): File
{
    $disk = $this->resolveDisk($disk);
    $path = $this->directory($owner).'/'.Str::uuid()->toString().'.pdf';

    if (Storage::disk($disk)->put($path, $pdf) === false) {
        throw FileStorageException::diskWriteFailed();
    }

    return $this->createRow($owner, $filename, $path, $disk, 'application/pdf', strlen($pdf));
}

/**
 * Collect and delete every `files` row belonging to one report (morph rows +
 * the canonical `file_id` pointer), returning the objects to delete after commit.
 *
 * @return list<array{disk: string, path: string}>
 */
public function detachForReport(GeneratedDocumentReport $report): array
{
    $files = File::query()
        ->where(function ($query) use ($report): void {
            $query->where(function ($query) use ($report): void {
                $query->where('fileable_type', $report->getMorphClass())
                    ->where('fileable_id', $report->getKey());
            })->orWhere('id', $report->file_id);
        })
        ->get();

    return $this->detachRows($files);
}

/**
 * @param  iterable<array{disk: string, path: string}>  $objects
 */
public function deleteStoredObjects(iterable $objects): void
{
    foreach ($objects as $object) {
        try {
            Storage::disk($object['disk'])->delete($object['path']);
        } catch (Throwable $exception) {
            Log::warning('Failed to delete a stored file.', [
                'disk' => $object['disk'],
                'path' => $object['path'],
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}

public function delete(File $file): void
{
    $this->deleteStoredObjects([$this->objectFor($file)]);

    $file->delete();
}
```

Private helpers (one place for the disk fallback and row creation):

```php
/** @return list<array{disk: string, path: string}> */
private function detachRows(Collection $files): array
{
    $objects = $files
        ->map(fn (File $file): array => $this->objectFor($file))
        ->values()
        ->all();

    File::query()->whereIn('id', $files->pluck('id'))->delete();

    return $objects;
}

/** @return array{disk: string, path: string} */
private function objectFor(File $file): array
{
    // A null/empty disk is the only legacy case; fall back to the configured default.
    return [
        'disk' => $file->disk ?: (string) config('filesystems.default'),
        'path' => $file->path,
    ];
}

private function createRow(
    Model $owner,
    string $filename,
    string $path,
    string $disk,
    ?string $mimeType,
    int|false|null $size,
): File {
    try {
        return File::query()->create([
            'fileable_type' => $owner->getMorphClass(),
            'fileable_id' => $owner->getKey(),
            'filename' => $filename,
            'path' => $path,
            'disk' => $disk,
            'mime_type' => $mimeType,
            'size' => $size === false ? null : $size,
        ]);
    } catch (Throwable $exception) {
        Storage::disk($disk)->delete($path);

        throw $exception;
    }
}

private function resolveDisk(?string $disk = null): string
{
    return $disk ?? (string) config('filesystems.default');
}
```

`directory()` becomes morph-aware while keeping the existing document layout:

```php
private function directory(Model $owner): string
{
    return ($owner instanceof GeneratedDocumentReport ? 'reports/' : 'documents/').$owner->getKey();
}
```

`detachForDocument()` keeps collecting report rows + `file_id` but returns the new object list:

```php
/** @return list<array{disk: string, path: string}> */
public function detachForDocument(ResearchedDocument $document): array
{
    return $this->detachRows($this->filesForDocument($document));
}
```

After T1 its `files` delete nulls `report.file_id` via the FK before the document cascade removes
reports. `DocumentDeletionService` and `ReportDeletionService` are typing-only updates (variable/
PHPDoc rename `$paths` → `$objects`); behavior is unchanged for default-disk files.

### 8.3 Read-model extensions

`ReferenceQueryService` gains:

```php
/**
 * Every reference of the document in reading order (report builder; no pagination).
 *
 * @return Collection<int, ResearchedDocumentReference>
 */
public function all(ResearchedDocument $document): Collection
{
    return $this->ordered($document->references()->with('finding'))->get();
}
```

and `paginate()` reuses a private `ordered(Builder $query)` with the existing
`text_start_offset IS NULL` → `text_start_offset` → `id` ordering (D-05-03).

`CitationQueryService` gains:

```php
/**
 * Citations that surface as issues in the report — the same three derived
 * statuses as the findings feed (D-06-05).
 *
 * @return Collection<int, ResearchedDocumentCitation>
 */
public function issues(ResearchedDocument $document): Collection
{
    $derived = $this->resolver->sqlExpression('c.resolution_state', 'f.status');
    $statuses = [CitationStatus::Unreliable, CitationStatus::Unresolved, CitationStatus::Hallucination];
    $placeholders = implode(', ', array_fill(0, count($statuses), '?'));

    return ResearchedDocumentCitation::query()
        ->from('researched_document_citations as c')
        ->leftJoin('researched_document_references as r', 'r.id', '=', 'c.researched_document_reference_id')
        ->leftJoin('reference_findings as f', 'f.researched_document_reference_id', '=', 'r.id')
        ->where('c.researched_document_id', $document->getKey())
        ->whereRaw("({$derived}) IN ({$placeholders})", array_map(fn (CitationStatus $s): string => $s->value, $statuses))
        ->select('c.*')
        ->with('reference.finding')
        ->orderByRaw('c.text_start_offset IS NULL')
        ->orderBy('c.text_start_offset')
        ->orderBy('c.id')
        ->get();
}
```

### 8.4 Labels and messages (D-06-08)

`ReferenceFindingStatus::label()`:

| Value | Label |
|---|---|
| `pending` | `Menunggu penilaian` |
| `valid` | `Valid` |
| `suspicious` | `Perlu ditinjau` |
| `invalid` | `Tidak valid` |
| `not_found` | `Tidak ditemukan` |

`CitationStatus::label()`:

| Value | Label |
|---|---|
| `valid` | `Valid` |
| `unreliable` | `Tidak dapat diandalkan` |
| `pending` | `Menunggu` |
| `unresolved` | `Belum tertaut` |
| `hallucination` | `Tidak ada referensi` |

`CitationStatus::message()` returns `null` for `valid`/`pending` and the exact current feed strings
otherwise (must stay byte-identical to `FindingsEndpointTest`):

```text
unreliable    → Sitasi merujuk pada referensi yang tidak berhasil diverifikasi (invalid/not_found).
unresolved    → Sitasi belum dapat ditautkan secara pasti ke referensi.
hallucination → Sitasi tidak memiliki pasangan referensi (hallucination).
```

`FindingsFeedQuery` replaces its private message constants with `CitationStatus::...->message()`
when building the SQL `CASE` (values are trusted enum constants); severity/type mapping is
untouched.

### 8.5 Report data classes (internal view model — **not** API DTOs)

`app/Services/Reports/ReportPayload.php`, `ReportReferenceRow.php`, `ReportCitationRow.php`:

```php
final readonly class ReportPayload
{
    /**
     * @param  list<ReportReferenceRow>  $references
     * @param  list<ReportCitationRow>  $citationIssues
     */
    public function __construct(
        public string $documentName,
        public CarbonImmutable $documentCreatedAt,
        public ?CarbonImmutable $analysisCompletedAt,
        public DocumentAnalysisSummaryData $summary,
        public array $references,
        public array $citationIssues,
        public CarbonImmutable $generatedAt,
    ) {}
}

final readonly class ReportReferenceRow
{
    public function __construct(
        public ?string $rawText,
        public ?string $doi,
        public ?string $title,
        public ?string $authors,
        public ?string $publicationName,
        public ?int $publicationYear,
        public ReferenceFindingStatus $status,        // missing finding → Pending (D-06-11)
        public ?float $confidence,
        public ?string $reason,
    ) {}
}

final readonly class ReportCitationRow
{
    public function __construct(
        public ?string $citationText,
        public CitationStatus $status,
        public ?string $referenceLabel,               // reference title, else raw text
        public string $message,
    ) {}
}
```

### 8.6 `ReportDataBuilder`

```php
final class ReportDataBuilder
{
    public function __construct(
        private readonly DocumentSummaryService $summaries,
        private readonly ReferenceQueryService $references,
        private readonly CitationQueryService $citations,
        private readonly CitationStatusResolver $resolver,
    ) {}

    public function build(ResearchedDocument $document): ReportPayload
    {
        $limit = (int) config('reports.text_preview_length');

        $references = $this->references->all($document)->map(fn ($reference): ReportReferenceRow => new ReportReferenceRow(
            rawText: $this->preview($reference->raw_text, $limit),
            doi: $reference->doi,
            title: $this->preview($reference->title, $limit),
            authors: $this->preview($reference->authors, $limit),
            publicationName: $this->preview($reference->publication_name, $limit),
            publicationYear: $reference->publication_year,
            status: $reference->finding?->status ?? ReferenceFindingStatus::Pending,
            confidence: $reference->finding?->confidence,
            reason: $reference->finding?->reason,
        ))->values()->all();

        $citationIssues = $this->citations->issues($document)->map(function ($citation) use ($limit): ReportCitationRow {
            $status = $this->resolver->resolve($citation->resolution_state, $citation->reference?->finding?->status);

            return new ReportCitationRow(
                citationText: $this->preview($citation->citation_text, $limit),
                status: $status,
                referenceLabel: $this->preview($citation->reference?->title ?? $citation->reference?->raw_text, $limit),
                message: (string) $status->message(),
            );
        })->values()->all();

        return new ReportPayload(
            documentName: $document->name,
            documentCreatedAt: $document->created_at->toImmutable(),
            analysisCompletedAt: $document->analysis_completed_at?->toImmutable(),
            summary: $this->summaries->countsFor($document),
            references: $references,
            citationIssues: $citationIssues,
            generatedAt: now()->toImmutable(),
        );
    }

    private function preview(?string $text, int $limit): ?string
    {
        return $text === null || $text === '' ? null : Str::limit($text, max(1, $limit));
    }
}
```

### 8.7 `ReportTemplateRenderer` + Blade view

```php
final class ReportTemplateRenderer
{
    public function render(ReportPayload $payload): string
    {
        return View::make((string) config('reports.template'), ['payload' => $payload])->render();
    }
}
```

`resources/views/reports/document-report.blade.php` — self-contained, inline `<style>`, no external
assets, no JS, no `{!! !!}`:

1. header: `Laporan Pemeriksaan Referensi` + document name;
2. document info: name, upload date, analysis-completed date, generated date (`d/m/Y H:i`);
3. `Ringkasan Analisis`: reference counts (total/valid/suspicious/invalid/not_found) and citation
   counts (total/valid/unreliable/pending/unresolved/hallucination) with the enum labels;
4. `Daftar Referensi`: raw text, DOI, title, status label, confidence as `number_format($c * 100, 1) %`
   (or `-` when null), reason;
5. `Masalah Sitasi`: citation text, status label, linked reference, message; empty-state text when
   there are none;
6. footer note that the verdicts are automated and may need manual review.

Printable table styles (`border-collapse`, `page-break-inside: avoid` on rows) and A4 margins come
from the Gotenberg form fields (T2), not from the template.

### 8.8 Tests

- `PrivateFileUrlResolverTest`: returns a URL containing the path for a `Storage::fake('local')`
  object; resolves against an explicitly passed second fake disk (`Storage::fake('reports')`) when
  given `disk: 'reports'`; returns `null` for `null`/empty path; returns `null` when the disk throws
  (mock via `Storage::shouldReceive('disk')->andThrow(...)`).
- `DocumentFileManagerTest`: `storePdf()` writes to and records the passed disk; `delete()` removes
  the object from the row's disk (not the default); `detachForReport()` returns `{disk, path}`
  objects and removes the rows; a document file on `local` and a report file on a second fake disk
  are each cleaned from their own disk on document delete.
- `ReportDataBuilderTest`: orders references/citations by document position; maps a missing finding
  to `pending`; derives `unreliable`/`unresolved`/`hallucination` and only includes issues; truncates
  long text at `reports.text_preview_length`; includes zero-count summaries.
- `ReportTemplateRendererTest`: renders the three section headings with an escaped document name and
  reference text (`&lt;script&gt;` present, raw tag absent); renders empty-state when there are no
  references/issues.
- `CitationStatusTest` (extend): `message()` returns `null` for `valid`/`pending` and the three
  fixed strings; `label()` maps every case.
- Keep `FindingsEndpointTest` green (message refactor regression guard) and `UploadDocumentTest`
  green (`FilePreviewData->url` still resolves for the default disk).

Commit: `feat(backend): build report content and template`.

---

## 9. T4 — Report state, failures, deletion and the generation job

**New files:**
`app/Services/Reports/{ReportStateService,ReportFailureHandler,ReportDeletionService}.php`,
`app/Exceptions/ReportGenerationFailedException.php`,
`app/Jobs/GenerateDocumentReportJob.php`,
`tests/Feature/GenerateDocumentReportJobTest.php`
**Depends on:** T2 (renderer), T3 (builder/template/file manager)

### 9.1 `ReportStateService` (the only status writer, D-06-06)

```php
final class ReportStateService
{
    public const string SAFE_FAILURE_MESSAGE = 'Laporan gagal dibuat. Silakan coba lagi.';

    /**
     * Atomically claim a report for processing.
     *
     * `pending` and a stale `processing` row (a job redelivered after a worker
     * kill) are both claimable; a terminal row is not.
     */
    public function markProcessing(GeneratedDocumentReport $report): bool
    {
        return GeneratedDocumentReport::query()
            ->whereKey($report->getKey())
            ->whereIn('status', [ReportStatus::Pending->value, ReportStatus::Processing->value])
            ->update([
                'status' => ReportStatus::Processing->value,
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * Complete the report and link its stored file.
     *
     * Returns false when the row disappeared or moved to a terminal state while
     * the PDF was being produced (the caller must then delete the orphaned file).
     */
    public function markCompleted(GeneratedDocumentReport $report, File $file): bool
    {
        return GeneratedDocumentReport::query()
            ->whereKey($report->getKey())
            ->whereIn('status', [ReportStatus::Pending->value, ReportStatus::Processing->value])
            ->update([
                'file_id' => $file->getKey(),
                'status' => ReportStatus::Completed->value,
                'error' => null,
                'generated_at' => now(),
                'updated_at' => now(),
            ]) === 1;
    }

    /**
     * Safe failure: missing and already-terminal reports are left untouched.
     */
    public function markFailed(string $reportId, string $safeMessage): void
    {
        GeneratedDocumentReport::query()
            ->whereKey($reportId)
            ->whereIn('status', [ReportStatus::Pending->value, ReportStatus::Processing->value])
            ->update([
                'status' => ReportStatus::Failed->value,
                'error' => $safeMessage,
                'generated_at' => null,
                'updated_at' => now(),
            ]);
    }
}
```

### 9.2 `ReportFailureHandler`

```php
final class ReportFailureHandler
{
    public function __construct(private readonly ReportStateService $state) {}

    public function handle(GeneratedDocumentReport $report, Throwable $exception): void
    {
        Log::error('Document report generation failed.', [
            'report_id' => $report->getKey(),
            'document_id' => $report->researched_document_id,
            'exception' => $exception,
        ]);

        $this->state->markFailed($report->getKey(), $this->safeMessage($exception));
    }

    public function safeMessage(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof ReportRenderingException => 'Layanan pembuatan laporan tidak tersedia. Silakan coba lagi.',
            $exception instanceof FileStorageException => 'Laporan gagal disimpan. Silakan coba lagi.',
            default => ReportStateService::SAFE_FAILURE_MESSAGE,
        };
    }
}
```

No signed URLs, no document text, no stack traces in the persisted `error` (`docs/SECURITY.md` §10).

### 9.3 `ReportDeletionService`

```php
final class ReportDeletionService
{
    public function __construct(private readonly DocumentFileManager $fileManager) {}

    public function delete(GeneratedDocumentReport $report): void
    {
        $objects = DB::transaction(function () use ($report): array {
            $objects = $this->fileManager->detachForReport($report);

            $report->delete();

            return $objects;
        });

        $this->fileManager->deleteStoredObjects($objects);
    }
}
```

Mirrors `DocumentDeletionService::delete()`: rows inside the transaction, stored objects
best-effort after commit (failures logged, never fatal).

### 9.4 `GenerateDocumentReportJob`

```php
final class GenerateDocumentReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout;

    public function __construct(public readonly string $reportId)
    {
        $this->timeout = (int) config('reports.timeout');
        $this->onQueue((string) config('reports.queue'));
    }

    public function handle(
        ReportDataBuilder $data,
        ReportTemplateRenderer $templates,
        ReportRenderer $renderer,
        DocumentFileManager $files,
        ReportStateService $state,
        ReportFailureHandler $failures,
    ): void {
        $report = GeneratedDocumentReport::query()->find($this->reportId);

        // Deleted while queued (document cascade) → abort quietly.
        if ($report === null || $report->status->isTerminal()) {
            return;
        }

        $document = $report->researchedDocument()->first();

        // Defensive: reports only exist for completed documents.
        if ($document === null || $document->status !== DocumentStatus::Completed) {
            $failures->handle($report, ReportGenerationFailedException::documentNotCompleted());

            return;
        }

        if (! $state->markProcessing($report)) {
            return;
        }

        $file = null;

        try {
            $payload = $data->build($document);
            $pdf = $renderer->render($templates->render($payload));
            $file = $files->storePdf(
                $report,
                $pdf,
                "laporan-{$report->getKey()}.pdf",
                (string) config('reports.disk'),
            );

            if (! $state->markCompleted($report, $file)) {
                // The report was deleted while the PDF was produced.
                $files->delete($file);
            }
        } catch (Throwable $exception) {
            if ($file !== null) {
                $files->delete($file);
            }

            $failures->handle($report, $exception);
        }
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping("report-generation:{$this->reportId}"))
                ->expireAfter($this->timeout + (int) config('reports.lock_expiry_buffer', 60))
                ->dontRelease(),
        ];
    }

    public function failed(?Throwable $exception): void
    {
        app(ReportStateService::class)->markFailed(
            $this->reportId,
            ReportStateService::SAFE_FAILURE_MESSAGE,
        );
    }
}
```

`ReportGenerationFailedException` (new, internal, extends `RuntimeException`) carries the
`documentNotCompleted()` constructor used only for the defensive branch. It is simple enough to
live in the same T4 commit.

### 9.5 Tests (`GenerateDocumentReportJobTest`)

| Case | Expected |
|---|---|
| success (fake renderer, `Storage::fake((string) config('reports.disk'))`) | report `completed`, `generated_at` set, `error` null; `files` row has `fileable_type = generated_document_report`, `file_id` points at it, `disk = reports.disk`; object exists on `reports.disk`; `lastHtml` contains name + reference text |
| report disk differs from the default | `config(['reports.disk' => 'reports'])` + `Storage::fake('reports')`: the object and row land on `reports`, nothing is written to `local` |
| real renderer + mocked PSR-18 client | same, proving the client is wired (the Guzzle `MockHandler` captured exactly one request) |
| Gotenberg 500 / connection error | `failed` + `'Layanan pembuatan laporan tidak tersedia. Silakan coba lagi.'`; no `files` row; no `reports/{id}` object; `Log::spy` error once with report id |
| disk write failure (`Storage::shouldReceive('disk')->andThrow`) | `failed` + `'Laporan gagal disimpan. Silakan coba lagi.'`; `Log` error |
| missing report | no exception, no renderer call |
| terminal report | no renderer call (mocked client not invoked / fake renderer `lastHtml === ''`) |
| document not completed (defensive) | `failed` + safe message, no renderer call |
| report deleted during render (`FakeReportRenderer` `onRender` deletes the row) | no `files` row/object left behind; no exception |
| `failed()` hook | idempotent `markFailed`; terminal rows untouched |
| middleware | key `report-generation:{id}`, expiry `timeout + buffer`, no release |

Commit: `feat(backend): generate document reports asynchronously`.

---

## 10. T5 — `/reports` endpoints

**New files:**
`app/Data/Report/{GeneratedDocumentReportSummaryData,GeneratedDocumentReportDetailData}.php`,
`app/Http/Requests/Report/ListReportsRequest.php`, `app/Http/Controllers/Api/ReportController.php`,
`app/Services/Reports/{ReportGenerationService,ReportQueryService}.php`,
`tests/Feature/ReportEndpointTest.php`
**Modified:** `routes/api.php`

### 10.1 DTOs (D-06-04)

```php
#[MapName(SnakeCaseMapper::class)]
final class GeneratedDocumentReportSummaryData extends BaseData
{
    public function __construct(
        public string $id,
        public string $documentId,
        public ReportStatus $status,
        public ?string $downloadUrl = null,
        public ?CarbonImmutable $generatedAt = null,
    ) {}

    public static function forReport(GeneratedDocumentReport $report, ?string $downloadUrl): self
    {
        return new self(
            id: $report->getKey(),
            documentId: $report->researched_document_id,
            status: $report->status,
            downloadUrl: $downloadUrl,
            generatedAt: $report->generated_at?->toImmutable(),
        );
    }
}

#[MapName(SnakeCaseMapper::class)]
final class GeneratedDocumentReportDetailData extends BaseData
{
    public function __construct(
        public string $id,
        public string $documentId,
        public ReportStatus $status,
        public ?string $downloadUrl = null,
        public ?string $error = null,
        public ?CarbonImmutable $generatedAt = null,
    ) {}

    public static function forReport(GeneratedDocumentReport $report, ?string $downloadUrl): self
    {
        return new self(
            id: $report->getKey(),
            documentId: $report->researched_document_id,
            status: $report->status,
            downloadUrl: $downloadUrl,
            error: $report->error,
            generatedAt: $report->generated_at?->toImmutable(),
        );
    }
}
```

Named `forReport` (not `fromReport`) to avoid spatie's custom-creation recursion (same rationale as
`ResearchedDocumentSummaryData::forDocument`). `download_url` is `null` until `completed`;
`error` is only populated for `failed`.

### 10.2 `ListReportsRequest`

```php
final class ListReportsRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }
}
```

No filters (nothing in the spec); invalid input → canonical `422` automatically.

### 10.3 `ReportQueryService`

```php
final class ReportQueryService
{
    /** @return LengthAwarePaginator<int, GeneratedDocumentReport> */
    public function paginate(ResearchedDocument $document, int $perPage = 15): LengthAwarePaginator
    {
        return $document->reports()
            ->with('file')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }
}
```

Default sort is `created_at` desc (spec §2.7) with the `id` tiebreaker (deterministic pagination).

### 10.4 `ReportGenerationService`

```php
final class ReportGenerationService
{
    public function generate(ResearchedDocument $document): GeneratedDocumentReport
    {
        if ($document->status !== DocumentStatus::Completed) {
            throw StateConflictException::reportNotAllowed();
        }

        $report = DB::transaction(fn (): GeneratedDocumentReport => GeneratedDocumentReport::query()->create([
            'researched_document_id' => $document->getKey(),
            'status' => ReportStatus::Pending,
        ]));

        GenerateDocumentReportJob::dispatch($report->getKey())->afterCommit();

        return $report;
    }
}
```

The response is built from the as-created (pending) model; the sync test queue must not refresh it
(spec requires `pending` in the 202 body even when the job runs inline).

### 10.5 `ReportController` + routes

```php
final class ReportController extends Controller
{
    public function __construct(
        private readonly ReportQueryService $queryService,
        private readonly ReportGenerationService $generationService,
        private readonly ReportDeletionService $deletionService,
        private readonly PrivateFileUrlResolver $fileUrls,
        private readonly OwnedResourceFinder $finder,
    ) {}

    public function store(Request $request, string $document): JsonResponse
    {
        $model = $this->finder->document($request->user(), $document);
        $report = $this->generationService->generate($model);

        return ApiResponse::accepted(
            GeneratedDocumentReportSummaryData::forReport($report, null),
            'Laporan sedang dibuat.',
        );
    }

    public function index(ListReportsRequest $request, string $document): JsonResponse
    {
        $model = $this->finder->document($request->user(), $document);

        $paginator = $this->queryService->paginate($model, $request->perPage());
        $paginator->through(fn (GeneratedDocumentReport $report): GeneratedDocumentReportSummaryData
            => GeneratedDocumentReportSummaryData::forReport(
                $report,
                $this->fileUrls->resolve($report->file?->path, $report->file?->disk),
            ));

        return ApiResponse::collection($paginator, GeneratedDocumentReportSummaryData::class);
    }

    public function show(Request $request, string $report): JsonResponse
    {
        $model = $this->finder->report($request->user(), $report);
        $model->load('file');

        return ApiResponse::single(GeneratedDocumentReportDetailData::forReport(
            $model,
            $this->fileUrls->resolve($model->file?->path, $model->file?->disk),
        ));
    }

    public function destroy(Request $request, string $report): Response
    {
        $this->deletionService->delete($this->finder->report($request->user(), $report));

        return ApiResponse::noContent();
    }
}
```

Routes added to the existing `auth:sanctum` + `throttle:api` group in `routes/api.php`:

```php
Route::post('/documents/{document}/reports', [ReportController::class, 'store'])
    ->whereUuid('document')->name('documents.reports.store');

Route::get('/documents/{document}/reports', [ReportController::class, 'index'])
    ->whereUuid('document')->name('documents.reports.index');

Route::get('/reports/{report}', [ReportController::class, 'show'])
    ->whereUuid('report')->name('reports.show');

Route::delete('/reports/{report}', [ReportController::class, 'destroy'])
    ->whereUuid('report')->name('reports.destroy');
```

Rate limit: the general `throttle:api` (60/min/user) only — `docs/API_SPEC.md` §2.9 defines no
report-specific limiter; adding one would be a contract change.

### 10.6 Endpoint tests (`ReportEndpointTest`)

| Case | Expected |
|---|---|
| store for completed document (`Bus::fake`) | `202`, `data.{id,document_id,status,generated_at,download_url}` with `pending`/`null`; message exact; `Bus::assertDispatched(GenerateDocumentReportJob::class)` with the report id; row pending |
| store for `pending`/`processing`/`failed` document | `409 CONFLICT` + exact message; no row; no dispatch |
| store twice | two rows, two dispatches (OQ-07) |
| index | `200`, `meta` block, newest first (`created_at` desc), `download_url` null for non-completed; completed + fake file yields a non-null string containing the path |
| index invalid pagination (`per_page=0/101`, `page=0`) | `422 VALIDATION_ERROR` |
| show pending | `error: null`, `download_url: null` |
| show failed | safe `error`, `download_url: null`, `generated_at: null` |
| show completed + file | `download_url` string |
| show/delete unknown report | `404` + `"Laporan tidak ditemukan."` |
| delete completed | `204` empty body; row gone; `files` row gone; object gone from the report's disk (`Storage::fake((string) config('reports.disk'))`) |
| delete with `reports.disk` ≠ default | object removed from the report disk; a document file on `local` is untouched |
| delete failed/pending (no file) | `204`; row gone |
| envelope | single/collection/error shapes asserted |

Commit: `feat(backend): expose report endpoints`.

---

## 11. T6 — Acceptance matrix, ownership and end-to-end

**New:** `tests/Feature/ReportGenerationEndToEndTest.php`
**Modified:** `tests/Feature/OwnershipIsolationTest.php` (`DocumentDeletionTest` stays as the
baseline guard for F-REP-01)

### 11.1 Ownership (`T-OWN-08`, `T-OWN-09`)

Add to `OwnershipIsolationTest`:

- `GET /documents/{foreign}/reports` and `POST /documents/{foreign}/reports` → `404` +
  `"Dokumen tidak ditemukan."`;
- `GET /reports/{foreign}` and `DELETE /reports/{foreign}` → `404` +
  `"Laporan tidak ditemukan."`;
- the foreign-vs-missing bodies are identical per endpoint;
- the foreign report row is still present and unchanged after the foreign `DELETE`.

### 11.2 Acceptance matrix (this phase's rows)

| Test ID | Case | Expected | Covered by |
|---|---|---|---|
| T-REP-01 | Generate for a completed document | `202 pending`, job dispatched | `ReportEndpointTest` |
| T-REP-02 | Generate for a non-completed document | `409 CONFLICT` + exact message | `ReportEndpointTest` |
| T-REP-03 | List / detail / delete | spec shapes; `204` on delete | `ReportEndpointTest` |
| T-REP-04 | Failed generation records `error` | `failed` + safe error | `GenerateDocumentReportJobTest` |
| T-REP-05 | Delete removes the stored file | no orphan row/object | `ReportEndpointTest` |
| T-REP-06 | Job success | `completed`, `generated_at`, valid `download_url` | `GenerateDocumentReportJobTest` + e2e |
| T-OWN-08 | Foreign document reports / `GET`+`DELETE /reports/{other}` | `404`, no disclosure | `OwnershipIsolationTest` |
| F-REP-01 | Document delete cascades report files | storage + rows cleaned | `DocumentDeletionTest` (existing) |
| F-REP-02 | Gotenberg unavailable/timeout | `failed` + safe error; `store` can regenerate | `GenerateDocumentReportJobTest` + e2e |
| F-REP-03 | Job idempotency | terminal report not re-rendered | `GenerateDocumentReportJobTest` |
| F-REP-04 | Report deleted mid-job | orphan file cleaned up | `GenerateDocumentReportJobTest` |
| F-REP-05 | HTML escaping | extracted text escaped in the PDF HTML | `ReportTemplateRendererTest` |
| F-REP-06 | URL/build shapes | `download_url` null→string; ordering/truncation | `PrivateFileUrlResolverTest`, `ReportDataBuilderTest` |
| F-REP-07 | Multi-disk file lifecycle | document file on `local`, report file on `reports.disk`; each deletion removes the object from its own disk | `DocumentFileManagerTest`, `DocumentDeletionTest` |

### 11.3 End-to-end (`ReportGenerationEndToEndTest`)

With the **sync** test queue (no `Bus::fake`), `config(['reports.disk' => 'reports'])`,
`Storage::fake('reports')`, and the real `GotenbergReportRenderer` bound to a Guzzle client whose
`MockHandler` returns `'%PDF-1.4 ok'` (override the `ClientInterface` singleton):

1. seed `DocumentTree` with a reference/finding/citation + `complete()`;
2. `POST /api/v1/documents/{id}/reports` → `202`;
3. the inline job runs the real builder/template/client; assert the DB row is `completed` with a
   `file_id`, `files.disk = 'reports'` and the object exists (`Storage::disk('reports')->assertExists`);
4. `GET /api/v1/reports/{id}` → `completed` + non-null `download_url`;
5. `DELETE /api/v1/reports/{id}` → `204`, row + `files` row + object gone.
6. MockHandler 500 variant → row `failed` with the safe error; `POST` again → a new row is created
   and (with a healthy mock) completes.

Commit: `test(backend): cover report generation and file lifecycle`.

---

## 12. T7 — Documentation sync and final validation

1. `docs/API_SPEC.md` §8:
   - intro: replace "One report per document" with "Reports are generated on demand; a document may
     have multiple reports (OQ-07), and a failed report can be regenerated by calling `POST`
     again.";
   - `POST`: note the 202 body is the summary shape with `download_url: null` and that the row is
     `pending` (additive field, D-06-04);
   - `GET /documents/{document}/reports`: `download_url` is `null` until `completed`; default order
     `created_at` desc;
   - `GET /reports/{report}`: `error` carries a safe message when `failed`;
   - `DELETE`: deletes the row **and** the stored file.
2. `docs/API_SPEC_ID.md` §8: mirror the same changes (translation lags behind the English spec; this
   phase fixes the reports section it touches — `AGENTS.md` §2 keeps English canonical).
3. `docs/DB_SCHEMA.md`: T1 already added the `files.disk` column + comment and the FK Ref/comment.
4. `docs/ARCHITECTURE.md`:
   - §3 (technology stack): note the `gotenberg/gotenberg-php` client and Guzzle PSR-18 transport;
   - §8: "one report per document" → multiple on-demand reports;
   - §9 data model: `files` now records its disk;
   - §13 status: report generation (endpoints, job, Gotenberg renderer, multi-disk file lifecycle)
     implemented; only the FastAPI inference implementation remains missing.
5. `docs/SECURITY.md`:
   - §7: name Gotenberg alongside inference as an internal, private-network service; note report HTML
     is generated server-side and never rendered in the browser;
   - §8: files may live on configured private disks; access stays via signed/temporary URLs, and a
     file's disk is stored with its row.
6. `docs/TEST_PLAN.md` §5.7: add T-REP-06 and F-REP-01/02 (plus F-REP-07 multi-disk) rows (keep
   §5.3 T-OWN-08 unchanged).
7. `AGENTS.md` §14: move reports from "missing" to implemented (Phase 06), update the phase list and
   note the new production dependency (`gotenberg/gotenberg-php`, `php-http/discovery`).
8. `docs/plans/backend/README.md`:
   - §3.1/§3.2 status (`/reports` implemented, Gotenberg renderer + config exist);
   - §8.1 traceability table rows for the four report endpoints → implemented;
   - §10 config table: keep `reports.disk` (now real) and `services.gotenberg.*`, add
     `REPORTS_DISK`/`REPORTS_*`; note that `files.disk` was added (D-06-02) and that
     `gotenberg/gotenberg-php` is a new dependency.
9. Final validation: `php artisan test --compact`, `vendor/bin/pint --dirty --format agent`,
   `php artisan route:list --path=api/v1/reports`.

Commit: `docs: sync report generation status`.

---

## 13. Files touched (summary)

```text
backend/
├── app/
│   ├── Data/Report/                          + GeneratedDocumentReportSummaryData,
│   │                                           GeneratedDocumentReportDetailData
│   ├── Enums/CitationStatus.php              ~ + message(), label()
│   ├── Enums/ReferenceFindingStatus.php      ~ + label()
│   ├── Exceptions/                           + ReportRenderingException,
│   │                                           ReportGenerationFailedException
│   ├── Extensions/Data/Injectors/
│   │   └── UrlFromFilePath.php               ~ delegates to PrivateFileUrlResolver (path + disk)
│   ├── Http/
│   │   ├── Controllers/Api/ReportController.php        +
│   │   └── Requests/Report/ListReportsRequest.php      +
│   ├── Jobs/GenerateDocumentReportJob.php              +
│   ├── Models/File.php                       ~ + disk in $fillable
│   ├── Providers/ReportServiceProvider.php             +
│   └── Services/
│       ├── Citation/CitationQueryService.php           ~ + issues()
│       ├── Document/DocumentFileManager.php            ~ disk-aware store/storePdf/delete/detach
│       ├── Document/DocumentDeletionService.php        ~ object-list typing only
│       ├── Files/PrivateFileUrlResolver.php            +
│       ├── Findings/FindingsFeedQuery.php              ~ messages from CitationStatus
│       ├── Reference/ReferenceQueryService.php         ~ + all()
│       └── Reports/
│           ├── Contracts/ReportRenderer.php            +
│           ├── GotenbergReportRenderer.php             + (uses gotenberg/gotenberg-php)
│           ├── ReportCitationRow.php                   +
│           ├── ReportDataBuilder.php                   +
│           ├── ReportDeletionService.php               +
│           ├── ReportFailureHandler.php                +
│           ├── ReportGenerationService.php             +
│           ├── ReportPayload.php                       +
│           ├── ReportQueryService.php                  +
│           ├── ReportReferenceRow.php                  +
│           ├── ReportStateService.php                  +
│           └── ReportTemplateRenderer.php              +
├── bootstrap/providers.php                   ~ + ReportServiceProvider
├── composer.json / composer.lock             ~ + gotenberg/gotenberg-php ^2.25
├── config/reports.php                        + (disk, queue, timeout, template, text, pdf)
├── config/services.php                       ~ + gotenberg block
├── .env.example                              ~ + GOTENBERG_* / REPORTS_* placeholders
├── database/migrations/..._add_disk_to_files_table.php                                      +
├── database/migrations/..._add_file_foreign_key_to_generated_document_reports_table.php     +
├── database/factories/FileFactory.php        ~ + disk
├── resources/views/reports/document-report.blade.php       +
├── routes/api.php                            ~ + 4 report routes
└── tests/
    ├── Feature/DomainSchemaTest.php          ~ + files.disk + FK cases
    ├── Feature/DocumentFileManagerTest.php   + multi-disk lifecycle
    ├── Feature/GotenbergReportRendererTest.php +
    ├── Feature/GenerateDocumentReportJobTest.php  +
    ├── Feature/OwnershipIsolationTest.php    ~ T-OWN-08 report cases
    ├── Feature/PrivateFileUrlResolverTest.php +
    ├── Feature/ReportDataBuilderTest.php     +
    ├── Feature/ReportEndpointTest.php        +
    ├── Feature/ReportGenerationEndToEndTest.php +
    ├── Feature/ReportTemplateRendererTest.php +
    ├── Support/FakeReportRenderer.php        +
    ├── Unit/CitationStatusTest.php           ~ message()/label()
    └── Unit/EnumTest.php                     ~ label() pins
docs/API_SPEC.md · docs/API_SPEC_ID.md · docs/DB_SCHEMA.md · docs/ARCHITECTURE.md ·
docs/SECURITY.md · docs/TEST_PLAN.md · AGENTS.md · docs/plans/backend/README.md   ~ status/contract
docs/plans/backend/06-reports-detail.md       (this file)
```

New Composer dependency: `gotenberg/gotenberg-php:^2.25` (MIT; pulls `php-http/discovery`, already
allowed in `composer.json`). No npm dependency. No change to `OwnedResourceFinder`, rate limiters,
the analysis pipeline, or any existing enum value.

---

## 14. Risks, pitfalls and deliberate deviations

### 14.1 Pitfalls to avoid

1. **Rendering inside the request.** `store` must only insert + dispatch; Gotenberg is called in the
   job only.
2. **A second state writer.** Never update `generated_document_reports` status columns outside
   `ReportStateService`; the job's success/failure paths both go through it.
3. **A second URL rule.** Do not call `Storage::disk()->url()` directly in the controller/DTOs;
   resolve through `PrivateFileUrlResolver`.
4. **A second citation-status map.** The report reads `CitationStatusResolver` and
   `CitationStatus::message()/label()`; do not re-map statuses in the builder or Blade.
5. **Unescaped extracted text.** `{!! !!}` is forbidden; the template test asserts escaping.
6. **Orphan files.** Order is always: write object → row → link; on any later failure delete the
   file (row + object). `markCompleted()` returning `false` must delete the just-stored file.
7. **Blocking deletion on storage.** `ReportDeletionService` must delete rows in the transaction and
   objects after commit (best-effort, logged) — same rule as `DocumentDeletionService`.
8. **Per-file disks.** Every read/delete of a stored object must use the row's `disk` (fallback
   the default for legacy rows); `reports.disk` must never be assumed by `UrlFromFilePath` or the
   file manager. The `files.disk` writer is `DocumentFileManager` only.
9. **Library request shape.** Do not hand-roll the Gotenberg multipart request: keep
   `GotenbergReportRenderer` on `Gotenberg::chromium()->pdf()->html(Stream::string('index.html',
   …))` and `Gotenberg::send($request, $client)`, so the client library owns the wire format and the
   injected PSR-18 client owns timeouts.
10. **Sync-queue test traps.** `POST /reports` tests must `Bus::fake()` for the pending-jobs
    assertion; the e2e test uses the sync queue deliberately and binds a `MockHandler` client.
11. **Timeouts.** `reports.timeout` must exceed `services.gotenberg.timeout` (defaults 300 > 60);
    the overlap lock uses `timeout + lock_expiry_buffer`.
12. **Unbounded HTML.** Truncate repeated text via `reports.text_preview_length`; keep the template
    free of base64/external assets.
13. **Foreign ids.** Always `OwnedResourceFinder` first; state/validation checks run after ownership
    so a foreign id never discloses existence.

### 14.2 Deliberate deviations from the broad plan (accepted, documented)

1. **D-06-01** — no `ReportFileManager`; `DocumentFileManager` is extended (single, disk-aware file
   lifecycle).
2. **D-06-02** — `files.disk` was added (the broad plan only assumed the `reports.disk` config);
   `reports.disk` is implemented as specified and defaults to `filesystems.default`.
3. **D-06-03** — `PrivateFileUrlResolver` extracted; `UrlFromFilePath` delegates (path + disk).
4. **D-06-04** — `store` reuses `GeneratedDocumentReportSummaryData` (adds `download_url: null`).
5. **D-06-09** — explicit `ReportTemplateRenderer` between builder and renderer.
6. **D-06-10** — `API_SPEC.md` §8 wording updated (OQ-07 contradiction), a required canonical-doc fix.
7. **D-06-14/D-06-15** — Gotenberg is called through the official `gotenberg/gotenberg-php` client
   with an injected Guzzle PSR-18 client instead of the framework HTTP client (user decision).

### 14.3 Rollback

- T1: `php artisan migrate:rollback` drops the FK and the `files.disk` column; no data is lost
  beyond the recorded disks (rows fall back to the configured default). Report files keep working.
- T2–T5: each commit is additive; reverting removes the routes/job/config/dependency. The analysis
  pipeline, document lifecycle and verification endpoints are untouched.
- T7 only edits docs. No backfill is needed beyond the `files.disk` column default.

### 14.4 Working assumptions to confirm during implementation

- `gotenberg/gotenberg-php` `v2.x` targets Gotenberg `8.x`; the form fields are
  `paperWidth`/`paperHeight`/`margin*` (inches) and the multipart file is `index.html` — Q-5.
- The `files.disk` migration's default (`config('filesystems.default')`) is the correct disk for all
  pre-existing rows; installations that historically mixed disks cannot be detected (Q-12).
- A document with report rows can never be retried today (retry is `failed`-only), so reports cannot
  go stale through a re-run; if retry ever opens up, reports should be deleted or marked stale in
  the same change.
- `Storage::fake('local')`/`Storage::fake('reports')` temporary URLs (`?expiration=...`) are enough
  to assert `download_url` shape; the real signed-URL shape (`signature=`) is exercised in
  production via the `serve => true` local disk and covered by `PrivateFileUrlResolverTest` at the
  disk-API level.

### 14.5 Scaling path (documented, not implemented)

For very large documents the job builds the full reference/citation arrays in memory and Gotenberg
returns the full PDF in the response body. If that becomes a problem, stream the HTML part and/or
cap report rows behind `reports.*` config without changing the endpoint contract. Not needed for v1.

---

## 15. Hand-off to Phase 07

| Primitive | Location | Phase 07 usage |
|---|---|---|
| Renderer seam | `ReportRenderer` + `ReportServiceProvider` | Fake the binding for tests; no real Gotenberg needed in CI |
| Report job | `GenerateDocumentReportJob` | Runbook step 8; `REPORTS_QUEUE` tuning |
| Report state | `ReportStateService` | Observability/metrics if added |
| Report data | `ReportDataBuilder`, `ReportPayload` | Template iteration without touching the job |
| Config | `config/reports.php`, `services.gotenberg` | Real values in `.env`, timeouts in the runbook, `REPORTS_DISK` as a configured private disk |
| File lifecycle | `DocumentFileManager::storePdf/detachForReport/deleteStoredObjects` | Per-file `disk` cleanup closure; `files.disk` in T-SCHEMA |
| Gotenberg client | `gotenberg/gotenberg-php` + `GotenbergReportRenderer` | Verify the library major matches the deployed Gotenberg major (v2.x ↔ 8.x) |

Phase 07 should verify the Gotenberg runbook (`docker run --rm -p 3000:3000 gotenberg/gotenberg:8`),
mark T-REP/F-REP rows covered, and keep the report HTML self-contained.

---

## 16. Open questions / ambiguities (with recommended defaults)

The plan proceeds with the recommended answer for each. Q-1, Q-5, Q-6 and Q-11 change
implementation/the canonical spec; the rest are wording/config defaults.

| # | Question | Recommendation (adopted above) |
|---|---|---|
| **Q-1** | Broad plan/README define `reports.disk` (default `filesystems.default`), but `files` has no disk column and URL/deletion paths assume a single disk. | **Add the `files.disk` column** (user decision): non-null, backfilled from `config('filesystems.default')` by the migration; every writer persists the disk it used. Keep `reports.disk` as the report disk (default `filesystems.default`). This is a deliberate canonical-schema change alongside OQ-06 (D-06-02/D-06-16). |
| **Q-2** | 202 `store` body: include `download_url: null` (D-06-04) or a dedicated action DTO? | Include it by reusing `GeneratedDocumentReportSummaryData` (additive field, D-02-05 precedent). Alternative: a third DTO that omits it. |
| **Q-3** | Indonesian report labels/messages are unspecified beyond OQ-16. | Adopt the labels in D-06-08 and the three issue messages already used by the findings feed (`CitationStatus::message()`); failed-report errors: `'Laporan gagal dibuat. Silakan coba lagi.'`, `'Layanan pembuatan laporan tidak tersedia. Silakan coba lagi.'`, `'Laporan gagal disimpan. Silakan coba lagi.'`. |
| **Q-4** | Should the report mark manually reviewed findings (`is_manual`)? | **No indicator in v1** (OQ-16 lists no such field). The status/reason already reflect the manual change. Adding a `Ditinjau manual` badge later is a template-only change. |
| **Q-5** | Which Gotenberg version/fields does the deployment target? | Use `gotenberg/gotenberg-php` `^2.25`, which maps to Gotenberg **8.x** (`/forms/chromium/convert/html`, `files` = `index.html`, inch dimensions); Phase 07's runbook uses `gotenberg/gotenberg:8`. Pin the library major to the deployed Gotenberg major (`v1.x` for 7.x). |
| **Q-6** | `API_SPEC.md` §8 says "One report per document" while OQ-07 allows multiple. | **Update the canonical spec** (English + ID translation) to multiple on-demand reports, with failed-regeneration and `download_url`/`error` clarifications (D-06-10). Required to avoid a silent contract contradiction. |
| **Q-7** | Report list ordering. | Spec default `created_at` desc + `id` desc tiebreak; no `sort` parameter (the spec documents none). |
| **Q-8** | Can `store` be called while another report is `pending`/`processing`? | **Yes** — OQ-07 allows multiple rows; a failed report is regenerated the same way as a fresh one. No dedupe/idempotency key. |
| **Q-9** | Should references with no `reference_findings` row appear as `pending` in the report? | Yes (D-06-11), matching the reference-list `status=pending` filter; `confidence`/`reason` stay null. |
| **Q-10** | Report file naming/paths. | `reports/{reportId}/{uuid}.pdf`, `laporan-{reportId}.pdf`; not exposed by the API (D-06-13). |
| **Q-11** | PSR-18 client for the Gotenberg library: `php-http/discovery` or an explicit client? | **Explicit client** (D-06-15): `ReportServiceProvider` builds `GuzzleHttp\Client` with `services.gotenberg` timeouts and binds it as `Psr\Http\Client\ClientInterface`. Discovery would work but gives no timeout control and is hard to fake; tests bind a `MockHandler` client. |
| **Q-12** | `files.disk` backfill for installations that historically wrote to several disks. | The migration backfills every row with the **current** default disk; a row that lived on a different disk cannot be detected automatically and would need a manual data fix. Documented as an assumption (D-06-16); no mixed-disk data exists in the repo's dev/test environment. |
