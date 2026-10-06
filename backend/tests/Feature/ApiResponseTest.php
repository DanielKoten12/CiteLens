<?php

use App\Data\ResearchedDocument\ResearchedDocumentDetailData;
use App\Http\Responses\ApiResponse;
use App\Models\ResearchedDocument;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Testing\TestResponse;

/**
 * `ApiResponse` returns raw framework responses; wrap them so the fluent
 * test assertions apply.
 */
function testResponseFrom(mixed $response): TestResponse
{
    return TestResponse::fromBaseResponse($response);
}

it('wraps a single resource without double wrapping', function () {
    $user = User::factory()->create();
    $document = ResearchedDocument::factory()->for($user)->create(['name' => 'Skripsi.pdf']);

    $response = testResponseFrom(ApiResponse::single(
        data: ResearchedDocumentDetailData::from($document),
        message: 'Dokumen berhasil diambil.',
    ));

    $response
        ->assertOk()
        ->assertJsonPath('data.name', 'Skripsi.pdf')
        ->assertJsonPath('message', 'Dokumen berhasil diambil.')
        ->assertJsonMissingPath('data.data');
});

it('omits the message key when no message is given', function () {
    $user = User::factory()->create();
    $document = ResearchedDocument::factory()->for($user)->create();

    testResponseFrom(ApiResponse::single(ResearchedDocumentDetailData::from($document)))
        ->assertOk()
        ->assertJsonMissingPath('message');
});

it('supports a null data payload with a message', function () {
    testResponseFrom(ApiResponse::single(null, 'Logout berhasil.'))
        ->assertOk()
        ->assertJsonPath('data', null)
        ->assertJsonPath('message', 'Logout berhasil.');
});

it('wraps a paginated collection with the canonical meta block', function () {
    $user = User::factory()->create();
    ResearchedDocument::factory()->count(3)->for($user)->create();

    $paginator = ResearchedDocument::query()->orderBy('created_at')->paginate(2);

    $response = testResponseFrom(ApiResponse::collection($paginator, ResearchedDocumentDetailData::class));

    $response
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.current_page', 1)
        ->assertJsonPath('meta.per_page', 2)
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.last_page', 2)
        ->assertJsonMissingPath('message');

    expect($response->json('data.0.id'))->toBeString();
});

it('passes through items that are already data objects', function () {
    $user = User::factory()->create();
    $documents = ResearchedDocument::factory()->count(2)->for($user)->create();

    $paginator = new LengthAwarePaginator(
        items: $documents->map(fn (ResearchedDocument $document): ResearchedDocumentDetailData => ResearchedDocumentDetailData::from($document))->all(),
        total: 2,
        perPage: 15,
        currentPage: 1,
    );

    testResponseFrom(ApiResponse::collection($paginator, ResearchedDocumentDetailData::class))
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2);
});

it('returns a bodiless 204 response', function () {
    $response = ApiResponse::noContent();

    testResponseFrom($response)->assertNoContent();

    expect($response->getContent())->toBe('');
});
