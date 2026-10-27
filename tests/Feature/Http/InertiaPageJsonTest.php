<?php

use App\Models\PortalRoleGrant;
use App\Models\PracticeCapturePlayer;
use App\Models\SectionPractice;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Dom\HTMLDocument;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * The Inertia page object is printed into <script type="application/json"> on a full load. Whatever a prop holds,
 * the browser must still close that tag where we close it, so the JSON decodes and #app exists to mount on.
 *
 * @return array{script: string, page: mixed, app: bool}
 */
function parseFullLoad(string $html): array
{
    $doc = HTMLDocument::createFromString($html, LIBXML_NOERROR);
    $script = (string) $doc->querySelector('script[data-page]')?->textContent;

    return [
        'script' => $script,
        'page' => json_decode($script, true),
        'app' => $doc->getElementById('app') !== null,
    ];
}

function rollcallWithCapturedPlayer(string $playerName): string
{
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));
    $section = Unit::factory()->create(['type' => UnitType::Section, 'path' => '930/']);
    $leader = User::factory()->serving()->create(['username' => 'Vasquez.R']);
    PortalRoleGrant::factory()->create(['user_id' => $leader->id, 'unit_id' => $section->id]);
    $practice = SectionPractice::factory()->create(['section_id' => $section->id, 'week_start' => '2026-10-03 00:00:00']);
    $practice->forceFill(['captured_at' => '2026-10-08 12:00:00', 'capture_source' => 'crcon', 'server_ref' => 'crcon-1'])->save();
    PracticeCapturePlayer::query()->create([
        'section_practice_id' => $practice->id, 'game_id' => game('hll')->id, 'player_name' => $playerName,
        'platform_id' => '7656119000000001', 'trooper_id' => null,
    ]);

    test()->withoutVite();

    return test()->actingAs($leader)->get("/rollcall/{$section->id}")->assertOk()->getContent();
}

it('keeps the page mountable when a captured player is named <!--<script', function () {
    $parsed = parseFullLoad(rollcallWithCapturedPlayer('<!--<script x'));

    expect($parsed['app'])->toBeTrue()
        ->and($parsed['page'])->toBeArray()
        ->and($parsed['script'])->not->toContain('<')
        ->and(Arr::dot($parsed['page']))->toContain('<!--<script x');
});

it('keeps the page mountable when a forum username holds </script>', function () {
    $user = User::factory()->create(['username' => '</script><div id="x">']);
    PortalRoleGrant::factory()->create(['user_id' => $user->id, 'unit_id' => Unit::factory()->create()->id]);

    $this->withoutVite();
    $parsed = parseFullLoad($this->actingAs(User::factory()->serving()->create())->get('/settings/staff-grants')->assertOk()->getContent());

    expect($parsed['app'])->toBeTrue()
        ->and($parsed['page'])->toBeArray()
        ->and($parsed['script'])->not->toContain('<')
        ->and(Arr::dot($parsed['page']))->toContain('</script><div id="x">');
});

it('round-trips &, quotes and non-ASCII names unchanged', function () {
    $name = "Müller & O'Brien \"Ørn\"";
    $parsed = parseFullLoad(rollcallWithCapturedPlayer($name));

    expect($parsed['page'])->toBeArray()
        ->and($parsed['script'])->not->toContain('&')
        ->and(Arr::dot($parsed['page']))->toContain($name);
});
