<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();          // sama dengan document_id yang dikirim ke FE
            $table->string('filename');              // nama asli file dari user
            $table->string('stored_filename');       // nama file di storage, misal: uuid.pdf
            $table->string('file_path');             // path lengkap ke file di storage
            $table->unsignedBigInteger('file_size'); // ukuran dalam bytes
            $table->string('content_type')->nullable();
            $table->unsignedInteger('page_count')->nullable(); // diisi nanti saat parsing
            $table->string('status')->default('uploaded'); // uploaded | processing | done | failed
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};