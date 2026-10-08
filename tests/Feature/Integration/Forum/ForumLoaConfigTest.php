<?php

// 4.1: which forum sections the portal may read for leave is configuration read from the environment
// (AD-23), so it is checked where it is parsed: `config/services.php`. The file is evaluated again
// here with a chosen environment, then the environment is put back.

function loaNodesFor(?string $value): array
{
    $env = $_ENV['SEVENCAV_FORUM_LOA_NODES'] ?? null;
    $server = $_SERVER['SEVENCAV_FORUM_LOA_NODES'] ?? null;

    try {
        if ($value === null) {
            unset($_ENV['SEVENCAV_FORUM_LOA_NODES'], $_SERVER['SEVENCAV_FORUM_LOA_NODES']);
        } else {
            $_ENV['SEVENCAV_FORUM_LOA_NODES'] = $_SERVER['SEVENCAV_FORUM_LOA_NODES'] = $value;
        }

        return (require config_path('services.php'))['sevencav']['loa_nodes'];
    } finally {
        $env === null ? $_ENV = array_diff_key($_ENV, ['SEVENCAV_FORUM_LOA_NODES' => 1]) : $_ENV['SEVENCAV_FORUM_LOA_NODES'] = $env;
        $server === null ? $_SERVER = array_diff_key($_SERVER, ['SEVENCAV_FORUM_LOA_NODES' => 1]) : $_SERVER['SEVENCAV_FORUM_LOA_NODES'] = $server;
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

it('drops a malformed pair: a bad id, an unknown kind, or no kind at all', function () {
    expect(loaNodesFor('abc:loa,12x:loa,0:loa,-3:loa,1.5:loa,180:vacation,400,,:eloa,541:reserve,341:eloa'))
        ->toBe([541 => 'reserve', 341 => 'eloa']);
});

it('puts the environment back the way it found it', function () {
    $_ENV['SEVENCAV_FORUM_LOA_NODES'] = $_SERVER['SEVENCAV_FORUM_LOA_NODES'] = 'before';

    loaNodesFor('180:loa');

    expect($_ENV['SEVENCAV_FORUM_LOA_NODES'])->toBe('before')
        ->and($_SERVER['SEVENCAV_FORUM_LOA_NODES'])->toBe('before');

    unset($_ENV['SEVENCAV_FORUM_LOA_NODES'], $_SERVER['SEVENCAV_FORUM_LOA_NODES']);
    loaNodesFor('180:loa');

    expect($_ENV)->not->toHaveKey('SEVENCAV_FORUM_LOA_NODES')
        ->and($_SERVER)->not->toHaveKey('SEVENCAV_FORUM_LOA_NODES');
});

it('ships the 7Cav leave sections in .env.example, so a copied .env reads them', function () {
    $example = file_get_contents(base_path('.env.example'));

    expect($example)->toContain('SEVENCAV_FORUM_LOA_NODES=180:loa,400:loa,540:loa,178:loa,369:loa,341:eloa')
        ->and($example)->toMatch('/^SEVENCAV_FORUM_API_KEY=$/m');
});
