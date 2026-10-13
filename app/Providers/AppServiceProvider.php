<?php

namespace App\Providers;

use App\Commands\Capture\CaptureSources;
use App\Integration\BattleMetrics\BattleMetricsApi;
use App\Integration\Cav\CavApi;
use App\Integration\Crcon\CrconApi;
use App\Integration\Forum\ForumApi;
use App\Integration\Nightwatch\NightwatchApi;
use App\Kpi\EchelonReportKpiSource;
use App\Kpi\ReportKpiSource;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
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
        // BattleMetrics as fallback, Arma from BattleMetrics, WARDOGS from Nightwatch. 7.11 adds an entry here.
        $this->app->singleton(CaptureSources::class, fn ($app) => CaptureSources::default(
            $app->make(CrconApi::class),
            $app->make(BattleMetricsApi::class),
            config('services.crcon'),
            config('services.battlemetrics'),
            $app->make(NightwatchApi::class),
            config('services.nightwatch'),
        ));

        // The Nightwatch WARDOGS presence seam (AD-3, Story 7.10). Key and server ids come from config (env only, AD-23).
        $this->app->singleton(NightwatchApi::class, fn () => new NightwatchApi(config('services.nightwatch')));

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

        // Sign-in throttle. Both halves of the OAuth handshake are reachable without a session:
        // `auth.redirect` mints and stores a state nonce per hit, and the callback spends a
        // forum token exchange per hit, so unthrottled either one lets a single client hammer
        // the session store and 7cav.us. Ten a minute per IP, counted separately for each
        // route so one real sign-in (one hit on each) costs one slot of each budget, which
        // leaves room for a handful of troopers signing in from behind one NAT.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)
            ->by($request->ip().'|'.$request->route()?->getName())
            // Both routes are browser navigations: a throttled user goes back to the login screen
            // with the same flash as every other sign-in failure, not the framework's bare 429.
            ->response(fn () => redirect()->route('login')
                ->with('error', 'Too many sign-in attempts. Try again in a minute.')));

        // Manual roster sync (Story 2.4). SyncRosterJob is unique, so extra clicks never queue
        // a second sync; this caps how often an Admin can even ask, per Admin.
        RateLimiter::for('roster-sync', fn (Request $request) => Limit::perMinute(5)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
