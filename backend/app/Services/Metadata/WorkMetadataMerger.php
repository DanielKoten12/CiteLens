<?php

namespace App\Services\Metadata;

use App\Services\Scoring\StringSimilarity;

/**
 * Merge and de-duplicate candidate records collected from several sources
 * (D-09-09).
 *
 * Rules, in order:
 *
 * 1. **DOI identity wins.** A normalized DOI is unique; the first occurrence
 *    keeps its source/provenance, later copies only fill missing fields.
 * 2. **Normalized title for DOI-less works.** The title key is compared with
 *    {@see StringSimilarity::normalize()} so punctuation/case never defeat the
 *    duplicate check.
 * 3. **DOI promotion.** A later DOI-bearing record whose normalized title
 *    matches an earlier DOI-less record replaces the earlier entry **in place**
 *    and inherits its missing fields. This is what makes "Garuda found the local
 *    article, OpenAlex knows its DOI" surface as rank 1 with a DOI instead of
 *    letting the DOI-less record shadow it.
 * 4. **Stable source order.** No sorting happens here; the scorer ranks and
 *    tie-breaks afterwards.
 */
final class WorkMetadataMerger
{
    public function __construct(
        private readonly StringSimilarity $strings,
    ) {}

    /**
     * @param  list<WorkMetadata>  $existing
     * @param  list<WorkMetadata>  $incoming
     * @return list<WorkMetadata>
     */
    public function merge(array $existing, array $incoming): array
    {
        /** @var list<WorkMetadata> $merged */
        $merged = [];

        /** @var array<string, int> $doiPositions */
        $doiPositions = [];

        /** @var array<string, int> $titlePositions first entry per normalized title */
        $titlePositions = [];

        foreach ([...$existing, ...$incoming] as $work) {
            $doi = $work->doi;
            $title = $this->titleKey($work->title);

            if ($doi !== null) {
                if (isset($doiPositions[$doi])) {
                    // Same publication already collected: keep the first record's
                    // position/provenance, fill anything it is missing.
                    $position = $doiPositions[$doi];
                    $merged[$position] = $merged[$position]->mergeMissing($work);

                    continue;
                }

                $titlePosition = $title === null ? null : ($titlePositions[$title] ?? null);

                if ($titlePosition !== null && $merged[$titlePosition]->doi === null) {
                    // Promotion: keep the earlier position, but the DOI-bearing
                    // record becomes the identity (DOI + provenance) and inherits
                    // the earlier record's missing bibliographic fields.
                    $merged[$titlePosition] = $work->mergeMissing($merged[$titlePosition]);
                    $doiPositions[$doi] = $titlePosition;

                    continue;
                }

                $doiPositions[$doi] = count($merged);

                if ($title !== null && ! isset($titlePositions[$title])) {
                    $titlePositions[$title] = count($merged);
                }

                $merged[] = $work;

                continue;
            }

            if ($title !== null) {
                if (isset($titlePositions[$title])) {
                    continue;
                }

                $titlePositions[$title] = count($merged);
            }

            $merged[] = $work;
        }

        return array_values($merged);
    }

    private function titleKey(?string $title): ?string
    {
        $key = $this->strings->normalize($title);

        return $key === '' ? null : $key;
    }
}
