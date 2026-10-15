<?php

use App\Enums\ReferenceFindingStatus;
use App\Models\ReferenceFinding;
use App\Services\Reference\ReferenceQueryService;
use Tests\Support\DocumentTree;

it('filters references by finding status including missing findings', function () {
    $tree = DocumentTree::create();

    $valid = $tree->reference(['text_start_offset' => 100, 'text_end_offset' => 200]);
    ReferenceFinding::factory()->forReference($valid)->valid()->create();

    $pending = $tree->reference(['text_start_offset' => 200, 'text_end_offset' => 300]);
    ReferenceFinding::factory()->forReference($pending)->pending()->create();

    $missing = $tree->reference(['text_start_offset' => 300, 'text_end_offset' => 400]);

    $service = app(ReferenceQueryService::class);

    $pendingIds = collect($service->paginate($tree->document, ReferenceFindingStatus::Pending)->items())
        ->pluck('id')->all();

    expect($pendingIds)->toEqualCanonicalizing([$pending->getKey(), $missing->getKey()]);

    $validIds = collect($service->paginate($tree->document, ReferenceFindingStatus::Valid)->items())
        ->pluck('id')->all();

    expect($validIds)->toBe([$valid->getKey()]);
});

it('filters references by doi presence', function () {
    $tree = DocumentTree::create();

    $withDoi = $tree->reference([
        'doi' => '10.1000/example',
        'text_start_offset' => 100,
        'text_end_offset' => 200,
    ]);
    $withoutDoi = $tree->reference([
        'doi' => null,
        'text_start_offset' => 200,
        'text_end_offset' => 300,
    ]);

    $service = app(ReferenceQueryService::class);

    $hasDoi = collect($service->paginate($tree->document, hasDoi: true)->items())->pluck('id')->all();
    $noDoi = collect($service->paginate($tree->document, hasDoi: false)->items())->pluck('id')->all();

    expect($hasDoi)->toBe([$withDoi->getKey()])
        ->and($noDoi)->toBe([$withoutDoi->getKey()]);
});

it('orders references by document position with nulls last', function () {
    $tree = DocumentTree::create();

    $nullOffset = $tree->reference(['text_start_offset' => null, 'text_end_offset' => null]);
    $third = $tree->reference(['text_start_offset' => 300, 'text_end_offset' => 400]);
    $first = $tree->reference(['text_start_offset' => 100, 'text_end_offset' => 200]);

    $ids = collect(app(ReferenceQueryService::class)->paginate($tree->document)->items())
        ->pluck('id')->all();

    expect($ids)->toBe([$first->getKey(), $third->getKey(), $nullOffset->getKey()]);
});

it('paginates references with the requested page size', function () {
    $tree = DocumentTree::create();

    foreach (range(1, 5) as $index) {
        $tree->reference([
            'text_start_offset' => $index * 100,
            'text_end_offset' => ($index * 100) + 50,
        ]);
    }

    $paginator = app(ReferenceQueryService::class)->paginate($tree->document, perPage: 2);

    expect($paginator->perPage())->toBe(2)
        ->and($paginator->total())->toBe(5)
        ->and($paginator->items())->toHaveCount(2);
});
