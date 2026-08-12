<?php

namespace App\Providers;

use App\Integration\Cav\CavApi;
use App\Integration\Forum\ForumApi;
use App\Repositories\AuditLogRepository;
use App\Repositories\PortalRoleGrantRepository;
use App\Repositories\SyncRunRepository;
use App\Repositories\TrooperRepository;
use App\Repositories\UnitRepository;
use App\Repositories\UserRepository;
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

        // Repository contracts (AD-2), each with exactly one implementation registered today.
        $this->app->bind(AuditLogRepository::class, AuditLogRepository\Eloquent::class);
        $this->app->bind(PortalRoleGrantRepository::class, PortalRoleGrantRepository\Eloquent::class);
        $this->app->bind(SyncRunRepository::class, SyncRunRepository\Eloquent::class);
        $this->app->bind(TrooperRepository::class, TrooperRepository\Eloquent::class);
        $this->app->bind(UnitRepository::class, UnitRepository\Eloquent::class);
        $this->app->bind(UserRepository::class, UserRepository\Eloquent::class);
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
