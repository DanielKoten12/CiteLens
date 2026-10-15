<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persisted citation-resolution provenance (Phase 05.1 W2, D-05.1-04).
     *
     * `resolution_state` drives the derived citation status (`paired`,
     * `unresolved`, `unmatched`); `resolution_method` records which signal
     * produced the pairing; `resolution_confidence` holds the combined score;
     * `extraction_reference_index` keeps the raw GROBID hint for audit.
     *
     * Existing rows default to `unmatched`, which maps them back to the exact
     * Phase 05 derived status (`hallucination`) — no backfill required.
     *
     * `after()` is MySQL-only and intentionally ignored on SQLite/Postgres;
     * column order is not part of the contract.
     */
    public function up(): void
    {
        Schema::table('researched_document_citations', function (Blueprint $table) {
            $table->string('resolution_state')->default('unmatched')->after('researched_document_reference_id');
            $table->string('resolution_method')->nullable()->after('resolution_state');
            $table->decimal('resolution_confidence', 5, 4)->nullable()->after('resolution_method');
            $table->integer('extraction_reference_index')->nullable()->after('resolution_confidence');

            $table->index(['researched_document_id', 'resolution_state']);
        });
    }

    public function down(): void
    {
        Schema::table('researched_document_citations', function (Blueprint $table) {
            $table->dropIndex(['researched_document_id', 'resolution_state']);

            $table->dropColumn([
                'resolution_state',
                'resolution_method',
                'resolution_confidence',
                'extraction_reference_index',
            ]);
        });
    }
};
