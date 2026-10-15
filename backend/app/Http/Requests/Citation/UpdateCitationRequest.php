<?php

namespace App\Http\Requests\Citation;

use App\Services\Citation\CitationPairingService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Body of `PATCH /citations/{citation}` (`docs/API_SPEC.md` §6).
 *
 * The field must be present and nullable: an explicit `null` clears the
 * pairing, a UUID pairs it. The same-document guarantee is enforced by
 * {@see CitationPairingService} after ownership
 * resolution (D-05-10/D-05-11).
 */
class UpdateCitationRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'researched_document_reference_id' => ['present', 'nullable', 'uuid'],
        ];
    }
}
