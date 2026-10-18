<?php

namespace App\Http\Requests\Reference;

use App\Enums\ReferenceFindingStatus;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters and pagination for `GET /documents/{document}/references`
 * (`docs/API_SPEC.md` §5).
 *
 * Invalid values fail validation and render the canonical
 * `422 VALIDATION_ERROR` envelope.
 */
class ListReferencesRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => ['nullable', Rule::enum(ReferenceFindingStatus::class)],
            'has_doi' => ['nullable', function (string $attribute, mixed $value, Closure $fail): void {
                if (! $this->isBoolean($value)) {
                    $fail('The :attribute must be true or false.');
                }
            }],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function status(): ?ReferenceFindingStatus
    {
        $status = $this->validated('status');

        return $status === null ? null : ReferenceFindingStatus::from($status);
    }

    /**
     * Accepts `true/false/1/0` (query strings and JSON booleans).
     */
    public function hasDoi(): ?bool
    {
        $value = $this->validated('has_doi');

        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['true', '1'], true);
    }

    private function isBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return true;
        }

        return in_array(strtolower(trim((string) $value)), ['true', 'false', '1', '0'], true);
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }
}
