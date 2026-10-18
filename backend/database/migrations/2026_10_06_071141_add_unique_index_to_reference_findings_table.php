<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One finding per reference is the domain invariant (OQ-14 resolved);
     * the unique index makes the Phase 04 upsert concurrency-safe.
     */
    public function up(): void
    {
        Schema::table('reference_findings', function (Blueprint $table) {
            $table->unique('researched_document_reference_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reference_findings', function (Blueprint $table) {
            $table->dropUnique(['researched_document_reference_id']);
        });
    }
};
