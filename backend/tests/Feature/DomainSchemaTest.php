<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * Insert a minimal referenced document tree for cascade assertions.
 *
 * @return array{document: string, reference: string, citation: string}
 */
function createDocumentTree(): array
{
    $userId = (string) Str::uuid();
    $documentId = (string) Str::uuid();
    $referenceId = (string) Str::uuid();
    $citationId = (string) Str::uuid();

    DB::table('users')->insert([
        'id' => $userId,
        'name' => 'Reviewer',
        'email' => 'reviewer@example.test',
        'password' => 'hashed-secret',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('researched_documents')->insert([
        'id' => $documentId,
        'user_id' => $userId,
        'name' => 'Thesis',
        'status' => 'pending',
        'analysis_progress' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('researched_document_references')->insert([
        'id' => $referenceId,
        'researched_document_id' => $documentId,
        'raw_text' => 'Smith, J. (2020). A paper.',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('researched_document_citations')->insert([
        'id' => $citationId,
        'researched_document_id' => $documentId,
        'researched_document_reference_id' => $referenceId,
        'citation_text' => '(Smith, 2020)',
        'occurrence_index' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return ['document' => $documentId, 'reference' => $referenceId, 'citation' => $citationId];
}

it('creates every canonical domain table', function () {
    $tables = [
        'files',
        'researched_documents',
        'researched_document_references',
        'researched_document_reference_locations',
        'researched_document_citations',
        'researched_document_citation_locations',
        'reference_findings',
        'reference_finding_candidates',
        'generated_document_reports',
    ];

    foreach ($tables as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }
});

it('generates UUID primary keys for users', function () {
    $user = User::factory()->create();

    expect(Str::isUuid($user->id))->toBeTrue()
        ->and($user->getIncrementing())->toBeFalse()
        ->and($user->getKeyType())->toBe('string');
});

it('hard deletes dependent rows when a researched document is deleted', function () {
    $tree = createDocumentTree();

    expect(DB::table('researched_document_references')->count())->toBe(1)
        ->and(DB::table('researched_document_citations')->count())->toBe(1);

    DB::table('researched_documents')->where('id', $tree['document'])->delete();

    expect(DB::table('researched_document_references')->count())->toBe(0)
        ->and(DB::table('researched_document_citations')->count())->toBe(0);
});

it('unpairs citations when their reference is deleted', function () {
    $tree = createDocumentTree();

    DB::table('researched_document_references')->where('id', $tree['reference'])->delete();

    expect(DB::table('researched_document_citations')->where('id', $tree['citation'])->value('researched_document_reference_id'))
        ->toBeNull();
});
