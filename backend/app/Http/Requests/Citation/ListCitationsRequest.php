<?php

namespace App\Http\Requests\Citation;

use App\Enums\CitationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters and pagination for `GET /documents/{document}/citations`
 * (`docs/API_SPEC.md` §6).
 *
 * `reference_id` is shape-validated here only; the same-document guarantee is
 * enforced after ownership resolution so foreign ids cannot leak existence
 * (D-05-10).
 */
class ListCitationsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(CitationStatus::class)],
            'reference_id' => ['nullable', 'uuid'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function status(): ?CitationStatus
    {
        $status = $this->validated('status');

        return $status === null ? null : CitationStatus::from($status);
    }

    public function referenceId(): ?string
    {
        return $this->validated('reference_id');
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }
}
