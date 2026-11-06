<?php

namespace App\Services\Metadata;

use App\Contracts\Metadata\BibliographicSource;
use App\Contracts\Metadata\DoiResolver;
use App\Enums\MetadataSource;

/**
 * The validated, ordered source configuration for one analysis run.
 *
 * Built once by {@see MetadataSourceRegistry::resolve()} and consumed by both
 * chains. Instances are configuration, not per-document state: they hold the
 * resolved clients and the ordering only.
 */
final class MetadataSources
{
    /**
     * @param  list<BibliographicSource>  $primary
     * @param  list<BibliographicSource>  $fallback
     * @param  list<DoiResolver>  $doiResolvers
     */
    public function __construct(
        public readonly array $primary,
        public readonly array $fallback,
        public readonly array $doiResolvers,
    ) {}

    /**
     * Whether a source belongs to the fallback chain.
     *
     * Optional sources count against the per-source and document-wide budgets;
     * primary sources do not.
     */
    public function isFallback(MetadataSource $source): bool
    {
        foreach ($this->fallback as $fallback) {
            if ($fallback->source() === $source) {
                return true;
            }
        }

        return false;
    }
}
