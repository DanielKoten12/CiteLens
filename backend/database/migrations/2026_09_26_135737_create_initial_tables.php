<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('files', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Polymorphic owner (researched_documents, generated_document_reports, ...).
            $table->uuidMorphs('fileable');

            $table->string('filename');
            $table->text('path');
            $table->string('mime_type')->nullable();
            $table->bigInteger('size')->nullable();
            $table->timestamps();
        });

        Schema::create('researched_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->text('name');
            $table->string('status')->default('pending');

            // Analysis progress tracking: 0-100, current step, failure message.
            $table->integer('analysis_progress')->default(0);
            $table->string('analysis_step')->nullable();
            $table->text('analysis_error')->nullable();
            $table->timestamp('analysis_started_at')->nullable();
            $table->timestamp('analysis_completed_at')->nullable();

            $table->timestamps();
        });

        Schema::create('researched_document_references', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('researched_document_id')
                ->constrained(null, null, 'rdr_researched_document_id_fk')
                ->cascadeOnDelete();

            $table->text('raw_text')->nullable();
            $table->string('doi')->nullable();
            $table->text('title')->nullable();
            $table->text('authors')->nullable();
            $table->text('publication_name')->nullable();
            $table->integer('publication_year')->nullable();

            // Character offsets of this entry within the extracted page text.
            $table->integer('text_start_offset')->nullable();
            $table->integer('text_end_offset')->nullable();

            $table->timestamps();

            $table->index('researched_document_id');
            $table->index('doi');
        });

        Schema::create('researched_document_reference_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('researched_document_reference_id')
                ->constrained(null, null, 'rdrl_researched_document_reference_id_fk')
                ->cascadeOnDelete();

            // 1-based page number.
            $table->integer('page_number');

            // Bounding box in PDF page coordinates.
            $table->decimal('x', 10, 4);
            $table->decimal('y', 10, 4);
            $table->decimal('width', 10, 4);
            $table->decimal('height', 10, 4);

            $table->decimal('page_width', 10, 4)->nullable();
            $table->decimal('page_height', 10, 4)->nullable();

            $table->string('coordinate_system')->default('pdf_points_top_left');

            // Order when a reference spans multiple regions.
            $table->integer('location_index')->default(0);

            $table->timestamps();

            $table->unique(
                ['researched_document_reference_id', 'location_index'],
                'rdrl_reference_location_unique'
            );
            $table->index('page_number');
        });

        Schema::create('researched_document_citations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('researched_document_id')->constrained()->cascadeOnDelete();

            // null = unresolved citations.
            $table->foreignUuid('researched_document_reference_id')
                ->nullable()
                ->constrained(null, null, 'rdc_researched_document_reference_id_fk')
                ->nullOnDelete();

            // Citation as it appears in the PDF, e.g. "[3]", "(Smith, 2020)".
            $table->text('citation_text');

            // Optional normalized marker, e.g. "[3]", "Smith, 2020".
            $table->string('citation_marker')->nullable();

            // Optional surrounding text.
            $table->text('context_before')->nullable();
            $table->text('context_after')->nullable();

            // Optional character offsets within the extracted page text.
            $table->integer('text_start_offset')->nullable();
            $table->integer('text_end_offset')->nullable();

            $table->integer('occurrence_index')->nullable();

            $table->timestamps();

            $table->index('researched_document_id');
            $table->index(
                'researched_document_reference_id',
                'rdc_researched_document_reference_id_idx'
            );
            $table->unique(
                ['researched_document_id', 'occurrence_index'],
                'rdc_researched_document_occurrence_unique'
            );
        });

        Schema::create('researched_document_citation_locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('citation_id')->constrained('researched_document_citations')->cascadeOnDelete();

            // 1-based page number.
            $table->integer('page_number');

            // Bounding box in PDF page coordinates.
            $table->decimal('x', 10, 4);
            $table->decimal('y', 10, 4);
            $table->decimal('width', 10, 4);
            $table->decimal('height', 10, 4);

            $table->decimal('page_width', 10, 4)->nullable();
            $table->decimal('page_height', 10, 4)->nullable();

            $table->string('coordinate_system')->default('pdf_points_top_left');

            // Order when a citation spans multiple regions.
            $table->integer('location_index')->default(0);

            $table->timestamps();

            $table->unique(['citation_id', 'location_index']);
            $table->index('page_number');
        });

        Schema::create('reference_findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('researched_document_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('researched_document_reference_id')->constrained()->cascadeOnDelete();

            // null = none found.
            $table->uuid('selected_candidate_id')->nullable();

            // pending | valid | suspicious | invalid | not_found
            $table->string('status');

            $table->decimal('confidence', 5, 4)->nullable();
            $table->text('reason')->nullable();

            // Manual review audit.
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->boolean('is_manual')->default(false);

            $table->timestamps();

            $table->index('researched_document_id');
            $table->index('researched_document_reference_id');
        });

        Schema::create('reference_finding_candidates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('reference_finding_id')->constrained()->cascadeOnDelete();

            // Ranking returned by the matching algorithm.
            $table->integer('rank');
            $table->decimal('confidence', 5, 4);

            // Candidate publication.
            $table->string('doi')->nullable();
            $table->text('title')->nullable();
            $table->text('authors')->nullable();
            $table->text('publication_name')->nullable();
            $table->integer('publication_year')->nullable();
            $table->text('url')->nullable();

            // Why this candidate was considered a match.
            $table->text('match_reason')->nullable();

            $table->timestamps();

            $table->unique(
                ['reference_finding_id', 'rank'],
                'rfc_reference_finding_rank_unique'
            );
        });

        // Circular reference: reference_findings.selected_candidate_id -> candidates.id.
        // Added after both tables exist (findings are created first with a null selected candidate).
        Schema::table('reference_findings', function (Blueprint $table) {
            $table->foreign('selected_candidate_id')
                ->references('id')
                ->on('reference_finding_candidates')
                ->nullOnDelete();
        });

        Schema::create('generated_document_reports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('researched_document_id')->constrained()->cascadeOnDelete();

            // Nullable because reports are generated at document level.
            $table->foreignUuid('reference_finding_id')->nullable()->constrained()->nullOnDelete();

            $table->uuid('file_id')->nullable();

            // pending | processing | completed | failed
            $table->string('status')->default('pending');
            $table->text('error')->nullable();

            // Set when generation completes.
            $table->timestamp('generated_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('reference_findings', function (Blueprint $table) {
            $table->dropForeign(['selected_candidate_id']);
        });

        Schema::dropIfExists('generated_document_reports');
        Schema::dropIfExists('reference_finding_candidates');
        Schema::dropIfExists('reference_findings');
        Schema::dropIfExists('researched_document_citation_locations');
        Schema::dropIfExists('researched_document_citations');
        Schema::dropIfExists('researched_document_reference_locations');
        Schema::dropIfExists('researched_document_references');
        Schema::dropIfExists('researched_documents');
        Schema::dropIfExists('files');
    }
};
