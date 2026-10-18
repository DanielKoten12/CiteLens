<?php

namespace App\Providers;

use App\Models\GeneratedDocumentReport;
use App\Models\ResearchedDocument;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Stable stored values for morph types (OQ-15). `user` is required because
        // Sanctum's `personal_access_tokens.tokenable` is a morph relation; an enforced
        // map rejects any model it does not cover.
        Relation::enforceMorphMap([
            'user' => User::class,
            'researched_document' => ResearchedDocument::class,
            'generated_document_report' => GeneratedDocumentReport::class,
        ]);

        // `docs/API_SPEC.md` §2.9.
        RateLimiter::for(
            'auth',
            fn (Request $request): Limit => Limit::perMinute(5)->by($request->ip())
        );

        RateLimiter::for(
            'api',
            fn (Request $request): Limit => Limit::perMinute(60)->by($request->user()?->id ?: $request->ip()),
        );

        RateLimiter::for(
            'documents',
            fn (Request $request): Limit => Limit::perMinute(10)->by($request->user()?->id ?: $request->ip()),
        );

        RateLimiter::for(
            'document-status',
            fn (Request $request): Limit => Limit::perMinute(120)->by($request->user()?->id ?: $request->ip()),
        );
    }
}
