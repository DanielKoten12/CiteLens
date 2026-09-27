<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Form Request untuk validasi file upload Dafpus Cek.
 *
 * Aturan validasi (sesuai `docs/API_SPEC.md` §4):
 *   - Hanya menerima PDF (DOCX belum didukung di v1)
 *   - Maksimal 20 MB (20480 KB)
 *   - `name` opsional; default nama asli file
 */
class UploadDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // TODO(integrasi): aktifkan otorisasi setelah Sanctum terpasang.
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'mimes:pdf',
                'mimetypes:application/pdf',
                'max:20480', // 20480 KB = 20 MB
            ],
            'name' => [
                'nullable',
                'string',
                'max:255',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'File dokumen wajib diunggah.',
            'file.file' => 'Upload tidak valid, bukan file.',
            'file.mimes' => 'Format file tidak didukung. Hanya PDF yang diterima.',
            'file.mimetypes' => 'Format file tidak didukung. Hanya PDF yang diterima.',
            'file.max' => 'Ukuran file melebihi batas 20 MB.',
            'name.string' => 'Nama dokumen harus berupa teks.',
            'name.max' => 'Nama dokumen terlalu panjang.',
        ];
    }

    /**
     * Selalu render error validasi sebagai JSON dengan error envelope kanonik,
     * termasuk pemetaan 413/415 sesuai `docs/API_SPEC.md` §2.5.
     */
    protected function failedValidation(Validator $validator): void
    {
        $errors = $validator->errors();
        $failedRules = $validator->failed()['file'] ?? [];

        [$status, $code] = match (true) {
            isset($failedRules['Max']) => [413, 'PAYLOAD_TOO_LARGE'],
            isset($failedRules['Mimes']), isset($failedRules['Mimetypes']) => [415, 'UNSUPPORTED_MEDIA_TYPE'],
            default => [422, 'VALIDATION_ERROR'],
        };

        throw new HttpResponseException(
            response()->json([
                'error' => [
                    'code' => $code,
                    'message' => $errors->first() ?: 'Data yang diberikan tidak valid.',
                    'details' => $errors->toArray(),
                ],
            ], $status)
        );
    }
}
