<?php

namespace App\Services\Metadata;

use App\Contracts\Metadata\BibliographicSource;
use App\Contracts\Metadata\DoiResolver;
use App\Enums\MetadataSource;
use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * Resolves and validates the configured metadata source lists.
 *
 * The lists live in `config/analysis.php` and their **order is config-only**
 * (Q7/D-09-08): changing it changes verification provenance and evaluation
 * reproducibility, so it is not an environment toggle.
 *
 * Validation is deliberately strict — an unknown class, a class that does not
 * implement the required contract, a duplicated source (within or across the
 * search lists) or an invalid failure policy fails at the first run instead of
 * silently producing wrong provenance.
 */
final class MetadataSourceRegistry
{
    public function __construct(
        private readonly Container $container,
        private readonly SourceChainPolicy $policy,
    ) {}

    /**
     * Resolve the full source configuration once per analysis run.
     */
    public function resolve(): MetadataSources
    {
        $this->policy->assertValidConfiguration();

        $primary = $this->resolveList('primary_sources', BibliographicSource::class);
        $fallback = $this->resolveList('fallback_sources', BibliographicSource::class);
        $doiResolvers = $this->resolveList('doi_resolvers', DoiResolver::class);

        if ($primary === []) {
            throw new LogicException('analysis.metadata.primary_sources must contain at least one source.');
        }

        if ($doiResolvers === []) {
            throw new LogicException('analysis.metadata.doi_resolvers must contain at least one resolver.');
        }

        $this->assertNoOverlap($primary, $fallback);

        return new MetadataSources(
            primary: $primary,
            fallback: $fallback,
            doiResolvers: $doiResolvers,
        );
    }

    /**
     * @template T of object
     *
     * @param  class-string<T>  $contract
     * @return list<T>
     */
    private function resolveList(string $key, string $contract): array
    {
        $bindings = config('analysis.metadata.'.$key, []);

        if (! is_array($bindings)) {
            throw new LogicException(sprintf('analysis.metadata.%s must be a list of class names.', $key));
        }

        /** @var list<T> $resolved */
        $resolved = [];
        /** @var array<string, true> $seenSources */
        $seenSources = [];

        foreach ($bindings as $binding) {
            if (! is_string($binding) || trim($binding) === '') {
                throw new LogicException(sprintf('analysis.metadata.%s contains a non-class entry.', $key));
            }

            if (! class_exists($binding)) {
                throw new LogicException(sprintf(
                    'Metadata source class [%s] configured in analysis.metadata.%s does not exist.',
                    $binding,
                    $key,
                ));
            }

            $instance = $this->container->make($binding);

            if (! $instance instanceof $contract) {
                throw new LogicException(sprintf(
                    'Metadata source [%s] configured in analysis.metadata.%s must implement %s.',
                    $binding,
                    $key,
                    $contract,
                ));
            }

            /** @var MetadataSource $source */
            $source = $instance->source();

            if (isset($seenSources[$source->value])) {
                throw new LogicException(sprintf(
                    'Metadata source [%s] appears more than once in analysis.metadata.%s.',
                    $source->value,
                    $key,
                ));
            }

            $seenSources[$source->value] = true;
            $resolved[] = $instance;
        }

        return $resolved;
    }

    /**
     * @param  list<BibliographicSource>  $primary
     * @param  list<BibliographicSource>  $fallback
     */
    private function assertNoOverlap(array $primary, array $fallback): void
    {
        $values = [];

        foreach ($primary as $source) {
            $values[$source->source()->value] = true;
        }

        foreach ($fallback as $source) {
            $value = $source->source()->value;

            if (isset($values[$value])) {
                throw new LogicException(sprintf(
                    'Metadata source [%s] is configured as both primary and fallback; it would be queried twice.',
                    $value,
                ));
            }
        }
    }
}
