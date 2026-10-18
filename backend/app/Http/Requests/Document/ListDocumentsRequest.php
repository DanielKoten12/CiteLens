<?php

namespace App\Http\Requests\Document;

use App\Enums\DocumentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters and pagination for `GET /documents` (`docs/API_SPEC.md` §4).
 *
 * Invalid values fail validation and are rendered as the canonical
 * `422 VALIDATION_ERROR` by the Phase 01 exception layer.
 */
class ListDocumentsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(DocumentStatus::class)],
            'q' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', Rule::in(['created_at', '-created_at'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * The requested sort, defaulting to newest first.
     */
    public function sort(): string
    {
        return $this->validated('sort') ?? '-created_at';
    }

    /**
     * The requested page size (default 15, max 100).
     */
    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }

    /**
     * The requested status filter, if any.
     */
    public function status(): ?DocumentStatus
    {
        $status = $this->validated('status');

        return $status === null ? null : DocumentStatus::from($status);
    }

    /**
     * The requested name search, if any.
     */
    public function search(): ?string
    {
        $term = $this->validated('q');

        return $term === null || $term === '' ? null : $term;
    }
}
