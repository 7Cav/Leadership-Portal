<?php

namespace App\Providers;

use Illuminate\Contracts\Container\Container;

/**
 * Binds every `App\Repositories\{Name}` interface to its `App\Repositories\{Name}\Eloquent`
 * implementation, by convention — a new repository needs no line added here, only the two
 * files `app/Repositories/README.md` already documents. `IdentityLinkException` and any other
 * non-repository file directly under `app/Repositories/` are skipped automatically: they have
 * no sibling `{Name}/Eloquent.php`, so `class_exists()` on the would-be implementation fails
 * and the loop moves on.
 */
class RepositoryServiceBinder
{
    public function bind(Container $app): void
    {
        foreach (glob(app_path('Repositories/*.php')) as $file) {
            $name = basename($file, '.php');
            $interface = "App\\Repositories\\{$name}";
            $implementation = "{$interface}\\Eloquent";

            if (interface_exists($interface) && class_exists($implementation)) {
                $app->bind($interface, $implementation);
            }
        }
    }
}
