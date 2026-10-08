<?php

namespace App\Services\Crossref;

/**
 * Canonicalizes DOI strings extracted from documents and Crossref metadata.
 *
 * Pure and dependency-free so it is usable from persistence (Phase 03) and the
 * Crossref client (Phase 04). `normalize()` is best-effort: it strips URL/prefix
 * noise and lowercases, but deliberately does **not** discard a malformed value,
 * because "a DOI is present but broken" is a distinct verification case that
 * Phase 04 must classify as `invalid`. `isValid()` performs the strict shape
 * check separately.
 */
final class DoiNormalizer
{
    /**
     * `https://doi.org/…`, `http://dx.doi.org/…`, `doi: …` prefixes.
     */
    private const string PREFIX_PATTERN = '#^(?:https?://(?:dx\.)?doi\.org/|doi:\s*)#i';

    /**
     * Canonical DOI shape: registrar prefix + suffix (`10.\d{4,9}/\S+`).
     */
    private const string SHAPE_PATTERN = '#^10\.\d{4,9}/\S+$#';

    /**
     * Normalize a raw DOI reference. Returns `null` only when no value is present.
     */
    public function normalize(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $value = trim($raw);

        if ($value === '') {
            return null;
        }

        $value = preg_replace(self::PREFIX_PATTERN, '', $value) ?? $value;
        $value = rawurldecode($value);
        $value = mb_strtolower(trim($value));
        $value = rtrim($value, ".,;:)]}>\"'\\");
        $value = rtrim($value);

        return $value === '' ? null : $value;
    }

    /**
     * Whether the normalized value has the canonical DOI shape.
     */
    public function isValid(string $doi): bool
    {
        return preg_match(self::SHAPE_PATTERN, $doi) === 1;
    }
}
