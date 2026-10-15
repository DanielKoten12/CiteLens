<?php

namespace App\Http\Requests\Finding;

use App\Enums\FindingSeverity;
use App\Enums\FindingType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters and pagination for `GET /documents/{document}/findings`
 * (`docs/API_SPEC.md` §7).
 */
class ListFindingsRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['nullable', Rule::enum(FindingType::class)],
            'severity' => ['nullable', Rule::enum(FindingSeverity::class)],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function type(): ?FindingType
    {
        $type = $this->validated('type');

        return $type === null ? null : FindingType::from($type);
    }

    public function severity(): ?FindingSeverity
    {
        $severity = $this->validated('severity');

        return $severity === null ? null : FindingSeverity::from($severity);
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }
}
