<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add the OQ-06 foreign key from a report to its stored PDF.
 *
 * `nullOnDelete` (never cascade): deleting the `files` row only clears the
 * report's canonical pointer, it must not delete the report itself.
 */
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
