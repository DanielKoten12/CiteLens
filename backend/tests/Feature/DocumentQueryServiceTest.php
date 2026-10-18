<?php

use App\Enums\DocumentStatus;
use App\Models\ResearchedDocument;
use App\Models\User;
use App\Services\Document\DocumentQueryService;
use Illuminate\Pagination\Paginator;

beforeEach(function () {
    $this->service = app(DocumentQueryService::class);
    $this->user = User::factory()->create();
});

afterEach(function () {
    Paginator::currentPageResolver(fn (): int => 1);
});

it('defaults to newest first with an id tiebreaker', function () {
    $tiedAt = now()->subDays(2);
    $oldest = ResearchedDocument::factory()->for($this->user)->create(['created_at' => $tiedAt]);
    $newest = ResearchedDocument::factory()->for($this->user)->create(['created_at' => now()->subDay()]);
    $middle = ResearchedDocument::factory()->for($this->user)->create(['created_at' => $tiedAt]);

    $ids = collect($this->service->paginate($this->user)->items())->pluck('id');

    $expectedTieOrder = collect([$oldest->getKey(), $middle->getKey()])
        ->sortDesc()
        ->values();

    expect($ids->first())->toBe($newest->getKey())
        ->and($ids->slice(1)->values()->all())->toBe($expectedTieOrder->all());
});

it('honours an ascending created_at sort', function () {
    $oldest = ResearchedDocument::factory()->for($this->user)->create(['created_at' => now()->subDays(2)]);
    ResearchedDocument::factory()->for($this->user)->create(['created_at' => now()->subDay()]);

    $ids = collect($this->service->paginate($this->user, sort: 'created_at')->items())->pluck('id');

    expect($ids->first())->toBe($oldest->getKey());
});

it('filters by status', function () {
    ResearchedDocument::factory()->for($this->user)->completed()->create();
    ResearchedDocument::factory()->for($this->user)->failed()->create();

    $paginator = $this->service->paginate($this->user, status: DocumentStatus::Completed);

    expect($paginator->total())->toBe(1)
        ->and($paginator->items()[0]->status)->toBe(DocumentStatus::Completed);
});

it('searches by name without treating user wildcards as patterns', function () {
    ResearchedDocument::factory()->for($this->user)->create(['name' => 'Laporan 100% Akhir.pdf']);
    ResearchedDocument::factory()->for($this->user)->create(['name' => 'Laporan 100X Akhir.pdf']);
    ResearchedDocument::factory()->for($this->user)->create(['name' => 'Laporan 100_ Akhir.pdf']);
    ResearchedDocument::factory()->for($this->user)->create(['name' => 'Catatan lain.pdf']);

    expect($this->service->paginate($this->user, search: '100%')->total())->toBe(1)
        ->and($this->service->paginate($this->user, search: 'akhir')->total())->toBe(3)
        ->and($this->service->paginate($this->user, search: '100_')->total())->toBe(1);
});

it('paginates deterministically when created_at values tie', function () {
    ResearchedDocument::factory()->for($this->user)->count(5)->create(['created_at' => now()->subDay()]);

    $page = function (int $number) {
        Paginator::currentPageResolver(fn (): int => $number);

        return collect($this->service->paginate($this->user, perPage: 2)->items())->pluck('id');
    };

    $all = $page(1)->merge($page(2))->merge($page(3));
    Paginator::currentPageResolver(fn (): int => 1);

    expect($all)->toHaveCount(5)
        ->and($all->unique())->toHaveCount(5);
});

it('only returns documents owned by the user', function () {
    ResearchedDocument::factory()->for($this->user)->create();
    ResearchedDocument::factory()->create();       // another user's document

    expect($this->service->paginate($this->user)->total())->toBe(1);
});
