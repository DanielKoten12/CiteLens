<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Form Request untuk validasi file upload DafpusCek.
 *
 * Validasi ditangani di sini (bukan di controller) supaya
 * controller tetap bersih dan mudah dibaca.
 *
 * Aturan validasi (sesuai PRD §3.1):
 *   - Hanya menerima .pdf dan .docx
 *   - Maksimal 50 MB (51200 KB)
 */
class UploadDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // TODO(integrasi): ganti true dengan cek auth kalau sudah ada autentikasi
        return true;
    }

    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                // mimes cek ekstensi; mimetypes cek content-type asli file
                'mimes:pdf,docx',
                'mimetypes:application/pdf,application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'max:51200', // satuan: kilobytes → 51200 KB = 50 MB
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required'   => 'File dokumen wajib diunggah.',
            'file.file'       => 'Upload tidak valid, bukan file.',
            'file.mimes'      => 'Format file tidak didukung. DafpusCek hanya menerima file PDF dan DOCX.',
            'file.mimetypes'  => 'Format file tidak didukung. DafpusCek hanya menerima file PDF dan DOCX.',
            'file.max'        => 'Ukuran file melebihi batas maksimal 50 MB.',
        ];
    }

    /**
     * Override handler validasi gagal supaya response-nya
     * selalu JSON (bukan redirect HTML default Laravel).
     */
    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors();

        // Tentukan HTTP status code yang tepat per jenis error
        $firstKey = $errors->keys()[0] ?? 'file';
        $firstMsg = $errors->first($firstKey);

        // Ukuran kebesaran → 413, selain itu → 400
        $status = str_contains($firstMsg, '50 MB') ? 413 : 400;

        throw new HttpResponseException(
            response()->json([
                'message' => $firstMsg,
                'errors'  => $errors->toArray(),
            ], $status)
        );
    }
}
