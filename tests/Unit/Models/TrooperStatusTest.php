<?php

use App\Models\TrooperStatus;

// `TrooperStatus::serving()` is the one definition of "still in the Cav". These pin it and keep
// call sites from drifting back to a deny-list (`!= Inactive`), which would quietly count any
// standing added later as serving in strength and KPIs while sign-in refuses it.

it('counts the three Cav rosters as serving and Inactive as not', function () {
    expect(TrooperStatus::servingValues())->toBe(['combat', 'reserve', 'eloa'])
        ->and(TrooperStatus::Combat->isServing())->toBeTrue()
        ->and(TrooperStatus::Reserve->isServing())->toBeTrue()
        ->and(TrooperStatus::Eloa->isServing())->toBeTrue()
        ->and(TrooperStatus::Inactive->isServing())->toBeFalse();
});

/**
 * Code-shaped comparisons against `Inactive`: `'!=' / '<>'` in a where, `===`/`!==`/`==`/`!=` on
 * either side, and `whereNot`/`whereNotIn` naming it. A best-effort tripwire, not a proof.
 */
const INACTIVE_COMPARISON_IDIOM = '/[\'"](?:!=|<>)[\'"]\s*,\s*TrooperStatus::Inactive|[!=]==?\s*TrooperStatus::Inactive|TrooperStatus::Inactive(?:->value)?\s*[!=]==?|whereNot(?:In)?\([^;]*TrooperStatus::Inactive/';

it('recognizes the deny-list idioms it guards against', function (string $snippet) {
    expect(preg_match(INACTIVE_COMPARISON_IDIOM, $snippet))->toBe(1);
})->with([
    "->where('status', '!=', TrooperStatus::Inactive->value)",
    "->where('troopers.status', '<>', TrooperStatus::Inactive->value)",
    '$trooper->status === TrooperStatus::Inactive',
    '$trooper->status !== TrooperStatus::Inactive',
    'TrooperStatus::Inactive === $trooper->status',
    "->whereNot('status', TrooperStatus::Inactive->value)",
    "->whereNotIn('status', [TrooperStatus::Inactive->value])",
]);

it('does not flag assigning Inactive or a match arm that yields it', function (string $snippet) {
    expect(preg_match(INACTIVE_COMPARISON_IDIOM, $snippet))->toBe(0);
})->with([
    "->update(['status' => TrooperStatus::Inactive->value])",
    'default => TrooperStatus::Inactive,',
    '$status = TrooperStatus::Inactive;',
]);

it('finds no deny-list serving check in app/ outside TrooperStatus', function () {
    $root = dirname(__DIR__, 3);
    $enum = realpath($root.'/app/Models/TrooperStatus.php');

    // Allowed on purpose, by file and count. The sync's own departure sweep asks "not already
    // departed", not "serving": a trooper holding any other standing who has left every roster
    // still gets marked `Inactive`.
    $allowed = [
        'app/Repositories/TrooperRepository/Eloquent.php' => 1,
    ];

    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app')) as $file) {
        if ($file->getExtension() !== 'php' || $file->getRealPath() === $enum) {
            continue;
        }

        $relative = str_replace('\\', '/', substr($file->getRealPath(), strlen(realpath($root)) + 1));
        $found = preg_match_all(INACTIVE_COMPARISON_IDIOM, file_get_contents($file->getPathname()));

        if ($found > ($allowed[$relative] ?? 0)) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([], 'Ask TrooperStatus::servingValues() / isServing() whether a trooper is serving, not "!= Inactive".');
});
