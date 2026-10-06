<?php

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The limiter names and values are the contract in `docs/API_SPEC.md` §2.9.
 * Route-level 429 smoke tests arrive with the routes in Phase 02.
 */
it('configures the documented document rate limits', function () {
    $user = User::factory()->create();

    $documents = resolveLimit('documents', $user);
    $status = resolveLimit('document-status', $user);

    expect($documents->maxAttempts)->toBe(10)
        ->and($documents->key)->toBe($user->getKey())
        ->and($status->maxAttempts)->toBe(120)
        ->and($status->key)->toBe($user->getKey());
});

it('keeps the existing auth and api limiters', function () {
    $user = User::factory()->create();

    $auth = resolveLimit('auth', null, ip: '198.51.100.7');
    $api = resolveLimit('api', $user);

    expect($auth->maxAttempts)->toBe(5)
        ->and($auth->key)->toBe('198.51.100.7')
        ->and($api->maxAttempts)->toBe(60)
        ->and($api->key)->toBe($user->getKey());
});

it('keys document limiters by client ip for anonymous requests', function () {
    expect(resolveLimit('documents', null, ip: '203.0.113.9')->key)->toBe('203.0.113.9')
        ->and(resolveLimit('document-status', null, ip: '203.0.113.9')->key)->toBe('203.0.113.9');
});

function resolveLimit(string $name, ?User $user, string $ip = '127.0.0.1'): Limit
{
    $request = Request::create('/api/v1/documents', 'POST', server: ['REMOTE_ADDR' => $ip]);

    if ($user !== null) {
        $request->setUserResolver(fn (): User => $user);
    }

    $limit = (RateLimiter::limiter($name))($request);

    expect($limit)->toBeInstanceOf(Limit::class);

    return $limit;
}
