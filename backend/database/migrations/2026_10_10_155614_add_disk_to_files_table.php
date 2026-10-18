<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record the private filesystem disk each stored object lives on.
 *
 * Report PDFs may be written to `reports.disk` while document uploads stay on
 * `filesystems.default`; the URL resolver and the file lifecycle need the disk
 * per row instead of assuming the configured default (D-06-02).
 *
 * The column default backfills existing rows in the same
 * `ALTER`/table-rebuild (portable on SQLite, MySQL and Postgres); the app always
 * writes the disk explicitly and the default is only a safety net for raw
 * inserts and factories (D-06-16).
 */
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
