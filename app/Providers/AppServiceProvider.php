<?php

namespace App\Providers;

use App\Integration\Cav\CavApi;
use App\Integration\Forum\ForumApi;
use App\Kpi\ReportKpiSource;
use App\Kpi\SectionReportKpiSource;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The Forum OAuth2 seam (AD-3). Config carries the client secret from env only
        // (AD-23); binding it here keeps that read in the composition root.
        $this->app->singleton(ForumApi::class, fn () => new ForumApi(config('services.sevencav')));

        // The Cav roster seam (AD-3). Same reasoning: the bearer key is read from config
        // (env only, AD-23) here in the composition root, not inside the seam.
        $this->app->singleton(CavApi::class, fn () => new CavApi(config('services.cav')));

        // Repository contracts (AD-2), each with exactly one implementation registered today,
        // bound by convention — see RepositoryServiceBinder.
        (new RepositoryServiceBinder)->bind($this->app);

        // Where a weekly report's KPIs come from, by echelon (5.6). Only the leaf half exists:
        // a section's live KPIs, with a non-leaf refused.
        // TODO(5.7): bind an implementation that routes a non-leaf unit to the roll-up of its
        // children's sent snapshots and keeps this one for sections.
        $this->app->bind(ReportKpiSource::class, SectionReportKpiSource::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // ORM hygiene (spine: Consistency Conventions; seeds AD-2). A query escaping a
        // Repository via lazy-loading throws loudly in dev/CI — and never ships a silent
        // N+1. Environment-gated: ON everywhere except production.
        Model::preventLazyLoading(! $this->app->isProduction());
    }
}
