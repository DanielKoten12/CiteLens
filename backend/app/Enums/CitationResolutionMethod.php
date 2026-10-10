<?php

namespace App\Enums;

/**
 * How a citation was resolved (`researched_document_citations.resolution_method`).
 *
 * Manual pairing/unpairing records `manual`; automated runs record the signal
 * that produced the pairing. GROBID provides no hint confidence, so
 * `extraction_hint` carries whatever confidence the validator could derive.
 */
enum CitationResolutionMethod: string
{
    case ExtractionHint = 'extraction_hint';
    case Apa = 'apa';
    case Ieee = 'ieee';
    case Manual = 'manual';
}
