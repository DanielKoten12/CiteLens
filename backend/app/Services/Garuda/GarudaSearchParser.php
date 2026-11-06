<?php

namespace App\Services\Garuda;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * Parses Garuda's server-rendered search result page.
 *
 * This is the only class that knows the page markup. A page whose structure
 * changed yields no cards; the client decides whether that is an empty result
 * (explicit `Found 0 documents`) or a format failure (anything else).
 *
 * Cards: `div.article-item` with `a.title-article`, `a.author-article` and
 * `xmp.subtitle-article` (journal first, publisher second).
 */
final class GarudaSearchParser
{
    private const CARD_XPATH = "//div[contains(concat(' ', normalize-space(@class), ' '), ' article-item ')]";

    private const MIN_YEAR = 1900;

    private const MAX_YEAR = 2100;

    /**
     * Total hits reported by the page ("Found 425 documents"), or `null` when
     * the marker is absent (which means the page is not the expected one).
     */
    public function total(string $html): ?int
    {
        if (! preg_match('/Found\s+([\d.,]+)\s+documents/iu', $html, $matches)) {
            return null;
        }

        return (int) str_replace(['.', ','], '', $matches[1]);
    }

    /**
     * @return list<array{id: string, title: string, authors: list<string>, journal: ?string, publisher: ?string, year: ?int}>
     */
    public function parse(string $html): array
    {
        $document = $this->load($html);

        if ($document === null) {
            return [];
        }

        $xpath = new DOMXPath($document);
        $nodes = $xpath->query(self::CARD_XPATH);

        if ($nodes === false) {
            return [];
        }

        $cards = [];
        $seen = [];

        foreach ($nodes as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            $card = $this->card($xpath, $node);

            if ($card === null || isset($seen[$card['id']])) {
                continue;
            }

            $seen[$card['id']] = true;
            $cards[] = $card;
        }

        return $cards;
    }

    /**
     * @return array{id: string, title: string, authors: list<string>, journal: ?string, publisher: ?string, year: ?int}|null
     */
    private function card(DOMXPath $xpath, DOMElement $node): ?array
    {
        $anchor = $this->firstNode($xpath, './/a[contains(@class, "title-article")]', $node);

        if (! $anchor instanceof DOMElement) {
            return null;
        }

        if (preg_match('#/documents/detail/(\d+)#', $anchor->getAttribute('href'), $matches) !== 1) {
            return null;
        }

        $title = $this->text($anchor);

        if ($title === '') {
            return null;
        }

        $subtitles = $this->texts($xpath, './/xmp[contains(@class, "subtitle-article")]', $node);

        return [
            'id' => $matches[1],
            'title' => $title,
            'authors' => $this->texts($xpath, './/a[contains(@class, "author-article")]', $node),
            'journal' => $subtitles[0] ?? null,
            'publisher' => $subtitles[1] ?? null,
            'year' => $this->year($subtitles[0] ?? null),
        ];
    }

    private function year(?string $journal): ?int
    {
        if ($journal === null) {
            return null;
        }

        if (preg_match('/\((19|20)\d{2}\)/', $journal, $matches) === 1) {
            $year = (int) substr($matches[0], 1, 4);
        } elseif (preg_match_all('/\b(19|20)\d{2}\b/', $journal, $matches) > 0) {
            $year = (int) end($matches[0]);
        } else {
            return null;
        }

        return $year >= self::MIN_YEAR && $year <= self::MAX_YEAR ? $year : null;
    }

    private function firstNode(DOMXPath $xpath, string $expression, DOMElement $context): ?DOMElement
    {
        $nodes = $xpath->query($expression, $context);

        if ($nodes === false) {
            return null;
        }

        foreach ($nodes as $node) {
            if ($node instanceof DOMElement) {
                return $node;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function texts(DOMXPath $xpath, string $expression, DOMElement $context): array
    {
        $nodes = $xpath->query($expression, $context);

        if ($nodes === false) {
            return [];
        }

        $values = [];

        foreach ($nodes as $node) {
            $value = $this->normalize($node->textContent);

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    private function text(DOMElement $element): string
    {
        return $this->normalize($element->textContent);
    }

    private function normalize(string $value): string
    {
        $value = preg_replace('/[\s\x{00A0}]+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Load HTML without network access or entity substitution. The XML encoding
     * hint is required: without it DOMDocument assumes ISO-8859-1 and mangles
     * Indonesian names.
     */
    private function load(string $html): ?DOMDocument
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument;

            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8">'.$html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );

            return $loaded ? $document : null;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }
}
