<?php

namespace Database\Seeders\Concerns;

use RuntimeException;

/**
 * For seeders that make up users, staff grants or reports for demos and browser tests. Run
 * against production by mistake (`db:seed --force`, or `migrate --seed` in a deploy script) they
 * would create sign-in-able accounts holding real scope over real units, so they refuse every
 * environment but the three that exist to be thrown away: local, testing and e2e (the Playwright
 * server, Story 5.8).
 */
trait RefusesOutsideDevEnvironments
{
    /** The environments these seeders may run in. */
    private const DEV_ENVIRONMENTS = ['local', 'testing', 'e2e'];

    /**
     * @throws RuntimeException outside local, testing and e2e
     */
    protected function refuseOutsideDevEnvironments(): void
    {
        if (app()->environment(self::DEV_ENVIRONMENTS)) {
            return;
        }

        throw new RuntimeException(sprintf(
            '%s creates demo users and grants, so it only runs when APP_ENV is %s; this is "%s". Nothing was seeded.',
            class_basename(static::class),
            implode(', ', self::DEV_ENVIRONMENTS),
            app()->environment(),
        ));
    }
}
