<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\UploadDocumentRequest;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Controller untuk endpoint upload dokumen DafpusCek.
 *
 * Tanggung jawab endpoint ini (sengaja dibatasi dulu):
 *   1. Terima file dari FE (multipart/form-data)
 *   2. Validasi format & ukuran (ditangani UploadDocumentRequest)
 *   3. Simpan ke storage/app/private/temp/ dengan nama UUID unik
 *   4. Return metadata file ke FE
 *
 * BELUM termasuk (menyusul setelah koordinasi tim):
 *   - Dispatch job ke queue untuk pipeline analisis
 *     TODO(integrasi): dispatch AnalyzeDocumentJob setelah
 *     user klik "Mulai Analisis", bukan di sini
 */
class UploadController extends Controller
{
    public function upload(UploadDocumentRequest $request): JsonResponse
    {
        $file = $request->file('file');

        // Buat identifier unik untuk dokumen ini
        $documentId = (string) Str::uuid();
        $originalName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();
        $storedName = $documentId.'.'.$extension;
        $fileSize = $file->getSize();       // bytes
        $contentType = $file->getMimeType();

        // Simpan ke storage/app/private/temp/{uuid}.pdf (atau .docx)
        // File ini SEMENTARA — sesuai catatan privasi PRD:
        // belum permanen sampai user memicu "Mulai Analisis"
        $stored = $file->storeAs('temp', $storedName, 'local');

        if ($stored === false) {
            return response()->json([
                'message' => 'Dokumen gagal disimpan. Silakan coba lagi.',
            ], 503);
        }

        try {
            $document = Document::create([
                'id' => $documentId,
                'filename' => $originalName,
                'stored_filename' => $storedName,
                'file_path' => storage_path('app/private/'.$stored),
                'file_size' => $fileSize,
                'content_type' => $contentType,
                'status' => 'uploaded',
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($stored);
            report($exception);

            return response()->json([
                'message' => 'Dokumen berhasil diunggah tetapi gagal dicatat. Silakan coba lagi.',
            ], 500);
        }

        return response()->json([
            'document_id' => $document->id,
            'original_filename' => $originalName,
            'stored_filename' => $storedName,
            'file_size' => $fileSize,
            'content_type' => $contentType,
            'status' => 'uploaded',
        ], 201);
    }
}
