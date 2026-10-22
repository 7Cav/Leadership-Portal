<?php

// The browser tab shows the root view's `<title>` until the Inertia page mounts and sets its own.
// That title is the app name from config, so with APP_NAME unset it must not fall back to Laravel's
// stock "Laravel". `config/app.php` is evaluated again with APP_NAME cleared from all three places
// `env()` reads (`$_ENV`, `$_SERVER`, `getenv()`), then the environment is put back.

const ROOT_TITLE_ENV = 'APP_NAME';

function appNameWithoutEnv(): string
{
    $env = $_ENV[ROOT_TITLE_ENV] ?? null;
    $server = $_SERVER[ROOT_TITLE_ENV] ?? null;
    $process = getenv(ROOT_TITLE_ENV);

    try {
        unset($_ENV[ROOT_TITLE_ENV], $_SERVER[ROOT_TITLE_ENV]);
        putenv(ROOT_TITLE_ENV);

        return (require config_path('app.php'))['name'];
    } finally {
        $env === null ? $_ENV = array_diff_key($_ENV, [ROOT_TITLE_ENV => 1]) : $_ENV[ROOT_TITLE_ENV] = $env;
        $server === null ? $_SERVER = array_diff_key($_SERVER, [ROOT_TITLE_ENV => 1]) : $_SERVER[ROOT_TITLE_ENV] = $server;
        putenv($process === false ? ROOT_TITLE_ENV : ROOT_TITLE_ENV.'='.$process);
    }
}

it('names the app 7Cav Leadership Portal when APP_NAME is not set', function () {
    expect(appNameWithoutEnv())->toBe('7Cav Leadership Portal');
});

it('puts the configured app name in the root view title', function () {
    config(['app.name' => '7Cav Leadership Portal']);

    $this->get('/login')
        ->assertOk()
        ->assertSee('<title inertia>7Cav Leadership Portal</title>', false);
});
