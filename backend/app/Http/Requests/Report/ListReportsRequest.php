<?php

namespace App\Http\Requests\Report;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Pagination for `GET /documents/{document}/reports` (`docs/API_SPEC.md` §8).
 *
 * No filters are documented for this endpoint; invalid pagination fails
 * validation and is rendered as the canonical `422 VALIDATION_ERROR`.
 */
final class ListReportsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * The requested page size (default 15, max 100).
     */
    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }
}
