<?php

use App\Rollcall\ResultImage;

beforeEach(function () {
    if (! extension_loaded('gd') || ! function_exists('imagettftext')) {
        $this->markTestSkipped('GD with FreeType is not installed.');
    }
});

function rows(int $count): array
{
    return array_map(fn (int $i) => ['name' => "SGT Trooper.{$i}", 'rc' => 'Yes', 'sp' => $i % 2 ? 'No' : 'Yes'], range(1, $count));
}

it('draws a PNG of the chosen mark column', function () {
    $png = ResultImage::render('rc', 'WD/1 | 03Oct26', rows(5));
    $info = getimagesizefromstring($png);

    expect(str_starts_with($png, "\x89PNG"))->toBeTrue()
        ->and($info[2])->toBe(IMAGETYPE_PNG)
        ->and($info[0])->toBeGreaterThan(200);
});

it('makes the image taller for a longer section', function () {
    $short = getimagesizefromstring(ResultImage::render('sp', 'WD/1', rows(3)));
    $long = getimagesizefromstring(ResultImage::render('sp', 'WD/1', rows(30)));

    expect($long[1])->toBeGreaterThan($short[1] + 26 * 20);
});

it('draws different pixels for the two columns', function () {
    expect(ResultImage::render('rc', 'WD/1', rows(4)))->not->toBe(ResultImage::render('sp', 'WD/1', rows(4)));
});

it('draws an image for a section with no troopers', function () {
    $info = getimagesizefromstring(ResultImage::render('rc', 'WD/1', []));

    expect($info[1])->toBeGreaterThan(0);
});

it('refuses a column it does not know', function () {
    ResultImage::render('xx', 'WD/1', rows(1));
})->throws(InvalidArgumentException::class);
