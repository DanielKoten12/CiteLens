<?php

namespace App\Http\Requests\Reference;

use App\Enums\ReferenceFindingStatus;
use App\Services\ReferenceFinding\ReferenceFindingReviewService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Body of `PATCH /references/{reference}/finding` (`docs/API_SPEC.md` §5).
 *
 * `status` is required and may not be `pending` (a manual review makes a real
 * verdict). Candidate membership is validated by
 * {@see ReferenceFindingReviewService} because it
 * needs the finding itself.
 */
class UpdateReferenceFindingRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                Rule::enum(ReferenceFindingStatus::class),
                Rule::notIn([ReferenceFindingStatus::Pending->value]),
            ],
            'selected_candidate_id' => ['nullable', 'uuid'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
