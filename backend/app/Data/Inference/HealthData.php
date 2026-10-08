<?php

namespace App\Data\Inference;

use App\Data\BaseData;

/**
 * Liveness payload of `GET /health` on the internal inference service
 * (`docs/API_SPEC.md` §9). Used only by the opt-in OQ-01 upload preflight.
 */
final class HealthData extends BaseData
{
    public function __construct(
        public string $status,
        public ?string $grobid = null,
        public ?string $sbert = null,
    ) {}

    public function isHealthy(): bool
    {
        return $this->status === 'ok';
    }
}
