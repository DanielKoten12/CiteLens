<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Form Request untuk login (`docs/API_SPEC.md` §3).
 *
 * Validasi hanya memastikan bentuk input. Kegagalan kredensial ditangani di
 * controller agar email yang tidak dikenal dan password yang salah menghasilkan
 * respons yang tidak dapat dibedakan (`docs/SECURITY.md` §2).
 */
class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'string',
                'email',
            ],
            'password' => [
                'required',
                'string',
            ],
        ];
    }
}
