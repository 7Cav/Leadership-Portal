<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
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
        // ORM hygiene (spine: Consistency Conventions; seeds AD-2). A query escaping a
        // Repository via lazy-loading throws loudly in dev/CI — and never ships a silent
        // N+1. Environment-gated: ON everywhere except production.
        Model::preventLazyLoading(! $this->app->isProduction());
    }
}
