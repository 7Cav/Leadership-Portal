<?php

// 4.1: which forum sections the portal may read for leave is configuration read from the environment
// (AD-23), so it is checked where it is parsed: `config/services.php`. The file is evaluated again
// here with a chosen environment, then the environment is put back.
//
// Laravel's `env()` reads `$_ENV`, `$_SERVER` and the process environment (`getenv()`), and a copied
// `.env` (as CI does from `.env.example`) fills all three. So the helper sets and clears all three,
// otherwise a value the example ships leaks back in when a test asks for "nothing configured".

const LOA_NODES_ENV = 'SEVENCAV_FORUM_LOA_NODES';

function loaNodesFor(?string $value): array
{
    $env = $_ENV[LOA_NODES_ENV] ?? null;
    $server = $_SERVER[LOA_NODES_ENV] ?? null;
    $process = getenv(LOA_NODES_ENV);

    try {
        if ($value === null) {
            unset($_ENV[LOA_NODES_ENV], $_SERVER[LOA_NODES_ENV]);
            putenv(LOA_NODES_ENV);
        } else {
            $_ENV[LOA_NODES_ENV] = $_SERVER[LOA_NODES_ENV] = $value;
            putenv(LOA_NODES_ENV.'='.$value);
        }

        return (require config_path('services.php'))['sevencav']['loa_nodes'];
    } finally {
        $env === null ? $_ENV = array_diff_key($_ENV, [LOA_NODES_ENV => 1]) : $_ENV[LOA_NODES_ENV] = $env;
        $server === null ? $_SERVER = array_diff_key($_SERVER, [LOA_NODES_ENV => 1]) : $_SERVER[LOA_NODES_ENV] = $server;
        putenv($process === false ? LOA_NODES_ENV : LOA_NODES_ENV.'='.$process);
    }
}

it('reads the leave sections as section id and kind pairs', function () {
    expect(loaNodesFor('180:loa,400:loa,540:loa,178:loa,369:loa,341:eloa'))
        ->toBe([180 => 'loa', 400 => 'loa', 540 => 'loa', 178 => 'loa', 369 => 'loa', 341 => 'eloa']);
});

it('tolerates spaces around the pairs', function () {
    expect(loaNodesFor(' 180 : loa , 341:eloa '))->toBe([180 => 'loa', 341 => 'eloa']);
});

it('reads nothing when none is configured', function (?string $value) {
    expect(loaNodesFor($value))->toBe([]);
})->with([null, '', '   ']);

it('reads nothing when unset even if the environment already carries the example value', function () {
    // What CI has: a `.env` copied from `.env.example`, so the process already holds the nodes.
    $_ENV[LOA_NODES_ENV] = $_SERVER[LOA_NODES_ENV] = '180:loa,341:eloa';
    putenv(LOA_NODES_ENV.'=180:loa,341:eloa');

    try {
        expect(loaNodesFor(null))->toBe([]);
    } finally {
        unset($_ENV[LOA_NODES_ENV], $_SERVER[LOA_NODES_ENV]);
        putenv(LOA_NODES_ENV);
    }
});

it('fails loudly on a malformed pair: a bad id, an unknown kind (reserve is not a leave type), or no kind at all', function (string $value) {
    expect(fn () => loaNodesFor($value))->toThrow(InvalidArgumentException::class, 'SEVENCAV_FORUM_LOA_NODES');
})->with(['abc:loa', '12x:loa', '0:loa', '-3:loa', '1.5:loa', '180:vacation', '400', ':eloa', '541:reserve', '180:loa,400:LOA', '180;loa']);

it('still skips empty entries', function () {
    expect(loaNodesFor('180:loa,,341:eloa,'))->toBe([180 => 'loa', 341 => 'eloa']);
});

it('puts the environment back the way it found it', function () {
    $_ENV[LOA_NODES_ENV] = $_SERVER[LOA_NODES_ENV] = 'before';
    putenv(LOA_NODES_ENV.'=before');

    loaNodesFor('180:loa');

    expect($_ENV[LOA_NODES_ENV])->toBe('before')
        ->and($_SERVER[LOA_NODES_ENV])->toBe('before')
        ->and(getenv(LOA_NODES_ENV))->toBe('before');

    unset($_ENV[LOA_NODES_ENV], $_SERVER[LOA_NODES_ENV]);
    putenv(LOA_NODES_ENV);
    loaNodesFor('180:loa');

    expect($_ENV)->not->toHaveKey(LOA_NODES_ENV)
        ->and($_SERVER)->not->toHaveKey(LOA_NODES_ENV)
        ->and(getenv(LOA_NODES_ENV))->toBeFalse();
});

it('ships the 7Cav leave sections in .env.example, so a copied .env reads them', function () {
    $example = file_get_contents(base_path('.env.example'));

    expect($example)->toContain('SEVENCAV_FORUM_LOA_NODES=180:loa,400:loa,540:loa,178:loa,369:loa,341:eloa')
        ->and($example)->toMatch('/^SEVENCAV_FORUM_API_KEY=$/m');
});
