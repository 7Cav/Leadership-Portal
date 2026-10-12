<?php

namespace App\Providers;

use App\Commands\Capture\CaptureSources;
use App\Integration\BattleMetrics\BattleMetricsApi;
use App\Integration\Cav\CavApi;
use App\Integration\Crcon\CrconApi;
use App\Integration\Forum\ForumApi;
use App\Kpi\EchelonReportKpiSource;
use App\Kpi\ReportKpiSource;
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

        // The CRCON presence seam (AD-3, Story 7.3). Key and URL come from config (env only, AD-23).
        $this->app->singleton(CrconApi::class, fn () => new CrconApi(config('services.crcon')));

        // The BattleMetrics presence seam (AD-3, Story 7.4). Key and server id come from config (env only, AD-23).
        $this->app->singleton(BattleMetricsApi::class, fn () => new BattleMetricsApi(config('services.battlemetrics')));

        // Which sources a practice capture reads, by platform (Story 7.5): HLL from CRCON with
        // BattleMetrics as fallback, Arma from BattleMetrics. 7.10 / 7.11 add entries here.
        $this->app->singleton(CaptureSources::class, fn ($app) => CaptureSources::default(
            $app->make(CrconApi::class),
            $app->make(BattleMetricsApi::class),
            config('services.crcon'),
            config('services.battlemetrics'),
        ));

        // Repository contracts (AD-2), each with exactly one implementation registered today,
        // bound by convention — see RepositoryServiceBinder.
        (new RepositoryServiceBinder)->bind($this->app);

        // Where a weekly report's KPIs come from, by echelon (5.6/5.7): a section's live KPIs, or
        // a non-leaf unit's roll-up of its children's sent snapshots.
        $this->app->bind(ReportKpiSource::class, EchelonReportKpiSource::class);
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
