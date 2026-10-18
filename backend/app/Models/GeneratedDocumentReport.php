<?php

namespace App\Models;

use App\Enums\ReportStatus;
use App\Models\Concerns\ScopesThroughDocument;
use Database\Factories\GeneratedDocumentReportFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An on-demand generated PDF report for a document.
 *
 * Maps the canonical `generated_document_reports` table. `file_id` is the
 * canonical pointer to the stored PDF (OQ-06); the polymorphic `files` row is
 * managed by the report file lifecycle in Phase 06.
 */
class GeneratedDocumentReport extends Model
{
    /** @use HasFactory<GeneratedDocumentReportFactory> */
    use HasFactory, HasUuids, ScopesThroughDocument;

    protected $fillable = [
        'researched_document_id',
        'reference_finding_id',
        'file_id',
        'status',
        'error',
        'generated_at',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReportStatus::class,
            'generated_at' => 'datetime',
        ];
    }

    /**
     * The optional finding this report was scoped to.
     *
     * @return BelongsTo<ReferenceFinding, $this>
     */
    public function referenceFinding(): BelongsTo
    {
        return $this->belongsTo(ReferenceFinding::class);
    }

    /**
     * The stored PDF file (canonical pointer).
     *
     * @return BelongsTo<File, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class, 'file_id');
    }
}
