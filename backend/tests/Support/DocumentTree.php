<?php

namespace Tests\Support;

use App\Enums\AnalysisStep;
use App\Enums\DocumentStatus;
use App\Models\GeneratedDocumentReport;
use App\Models\ReferenceFinding;
use App\Models\ReferenceFindingCandidate;
use App\Models\ResearchedDocument;
use App\Models\ResearchedDocumentCitation;
use App\Models\ResearchedDocumentCitationLocation;
use App\Models\ResearchedDocumentReference;
use App\Models\ResearchedDocumentReferenceLocation;
use App\Models\User;

/**
 * Builds a complete document tree through the real factories for tests.
 *
 * Indexes with uniqueness constraints (`occurrence_index`, `location_index`) are
 * computed from the current rows instead of relying on factory sequences, which
 * reset per factory invocation — repeated calls on the same document therefore
 * never produce unique-index violations.
 */
final class DocumentTree
{
    public function __construct(
        public readonly User $user,
        public readonly ResearchedDocument $document,
    ) {}

    /**
     * Create a user (unless given) and a fresh pending document.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function create(?User $user = null, array $attributes = []): self
    {
        $user ??= User::factory()->create();

        $document = ResearchedDocument::factory()->for($user)->create($attributes);

        return new self($user, $document);
    }

    /**
     * Add a bibliography reference, optionally with highlight locations.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function reference(array $attributes = [], int $locations = 0): ResearchedDocumentReference
    {
        if (! array_key_exists('text_start_offset', $attributes)) {
            $attributes['text_start_offset'] = ($this->document->references()->max('text_end_offset') ?? 0);
        }

        if (! array_key_exists('text_end_offset', $attributes)) {
            $attributes['text_end_offset'] = $attributes['text_start_offset'] === null
                ? null
                : $attributes['text_start_offset'] + 120;
        }

        $reference = ResearchedDocumentReference::factory()->for($this->document)->create($attributes);

        for ($index = 0; $index < $locations; $index++) {
            ResearchedDocumentReferenceLocation::factory()
                ->for($reference, 'reference')
                ->create(['location_index' => $index]);
        }

        return $reference->load('locations');
    }

    /**
     * Add an in-text citation. Pass a reference to pair it (same document);
     * omit it to create an unresolved (hallucination) citation.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function citation(
        ?ResearchedDocumentReference $reference = null,
        array $attributes = [],
        int $locations = 0,
    ): ResearchedDocumentCitation {
        $attributes['occurrence_index'] ??= ($this->document->citations()->max('occurrence_index') ?? -1) + 1;

        $factory = ResearchedDocumentCitation::factory()->for($this->document);

        if ($reference !== null) {
            $factory = $factory->pairedTo($reference);
        }

        $citation = $factory->create($attributes);

        for ($index = 0; $index < $locations; $index++) {
            ResearchedDocumentCitationLocation::factory()
                ->for($citation, 'citation')
                ->create(['location_index' => $index]);
        }

        return $citation->load('locations');
    }

    /**
     * Add a finding for a reference, optionally with ranked candidates and the
     * first candidate selected.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function finding(
        ResearchedDocumentReference $reference,
        array $attributes = [],
        int $candidates = 0,
    ): ReferenceFinding {
        $finding = ReferenceFinding::factory()->forReference($reference)->create($attributes);

        if ($candidates > 0) {
            $created = ReferenceFindingCandidate::factory()
                ->for($finding)
                ->count($candidates)
                ->create();

            $finding->update(['selected_candidate_id' => $created->first()->getKey()]);
        }

        return $finding->refresh();
    }

    /**
     * Add a report. Reports are only meaningful for completed documents, so the
     * document is marked completed first when it is not already.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function report(array $attributes = []): GeneratedDocumentReport
    {
        if ($this->document->status !== DocumentStatus::Completed) {
            $this->complete();
        }

        return GeneratedDocumentReport::factory()->for($this->document)->create($attributes);
    }

    /**
     * Mark the document as a successfully completed analysis.
     */
    public function complete(): self
    {
        $this->document->update([
            'status' => DocumentStatus::Completed,
            'analysis_progress' => 100,
            'analysis_step' => AnalysisStep::Completed,
            'analysis_error' => null,
            'analysis_started_at' => now()->subMinutes(5),
            'analysis_completed_at' => now()->subMinute(),
        ]);

        return $this;
    }
}
