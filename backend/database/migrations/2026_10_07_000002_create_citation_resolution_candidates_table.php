<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ranked alternatives for a citation that could not be committed
     * (Phase 05.1 W2, D-05.1-02). Mirrors `reference_finding_candidates` so the
     * verification UI can show suggestions for both references and citations.
     *
     * Candidates are always valid references of the same document (the writer
     * only inserts references from the citation's own document); the FK cascade
     * keeps them consistent with the document reset.
     */
    public function up(): void
    {
        Schema::create('citation_resolution_candidates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('citation_id')->constrained('researched_document_citations')->cascadeOnDelete();
            $table->foreignUuid('researched_document_reference_id')->constrained('researched_document_references')->cascadeOnDelete();
            $table->integer('rank');
            $table->decimal('confidence', 5, 4);
            $table->string('method');
            $table->text('match_reason')->nullable();
            $table->timestamps();

            $table->unique(['citation_id', 'rank']);
            $table->unique(['citation_id', 'researched_document_reference_id']);
            $table->index('citation_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('citation_resolution_candidates');
    }
};
