<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record which external catalogue a candidate record came from
 * (`App\Enums\MetadataSource`: `crossref`, `garuda`, `semantic_scholar`,
 * `openalex`; `varchar(20)` covers every value).
 *
 * The default backfills existing rows in the same table rebuild (portable on
 * SQLite, MySQL and Postgres). The writer always sets the source explicitly;
 * the default only protects raw inserts and historical rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reference_finding_candidates', function (Blueprint $table): void {
            $table->string('source', 20)->default('crossref')->after('confidence');
        });
    }

    public function down(): void
    {
        Schema::table('reference_finding_candidates', function (Blueprint $table): void {
            $table->dropColumn('source');
        });
    }
};
