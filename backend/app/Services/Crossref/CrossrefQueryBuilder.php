<?php

namespace App\Services\Crossref;

/**
 * Builds Crossref request parameters and paths.
 *
 * Kept separate from {@see CrossrefClient} so query construction is pure and
 * unit-testable, and so the client stays a thin transport adapter.
 */
final class CrossrefQueryBuilder
{
    /**
     * `/works` query params for a bibliographic search.
     *
     * @return array<string, string|int>
     */
    public function searchParams(ReferenceQuery $query): array
    {
        $params = [
            'query.bibliographic' => $query->bibliographic,
            'rows' => max(1, (int) config('services.crossref.rows', 5)),
            'select' => 'DOI,title,author,container-title,issued,URL,type',
        ];

        if ($query->author !== null && trim($query->author) !== '') {
            $params['query.author'] = trim($query->author);
        }

        $mailto = trim((string) config('services.crossref.mailto'));

        if ($mailto !== '') {
            $params['mailto'] = $mailto;
        }

        return $params;
    }

    /**
     * `/works/{doi}` path with the registrar slash kept as a path separator.
     *
     * Each `/`-delimited segment is encoded independently so the documented
     * `works/10.1038/nature14539` shape is preserved while `?`, `#`, spaces and
     * non-ASCII suffix characters still survive the URL.
     */
    public function doiPath(string $normalizedDoi): string
    {
        return 'works/'.implode('/', array_map(
            static fn (string $segment): string => rawurlencode($segment),
            explode('/', $normalizedDoi),
        ));
    }
}
