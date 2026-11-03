<?php

use App\Models\AttendanceSource;
use App\Models\AttendanceStatus;
use App\Models\RollcallSignature;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\AttendanceRepository;
use App\Repositories\SectionPracticeRepository;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

// The live roll call result images (#641): the forum fetches them with no portal login, by a token in the URL.
// The running week is 03OCT26 (Thursday 08OCT26 now).

function imageUrl(Unit $section, string $kind = 'rc', string $week = '2026-10-03', ?string $token = null): string
{
    return '/rollcall-images/'.($token ?? $section->fresh()->rollcall_image_token)."/{$week}/{$kind}.png";
}

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));
    $units = app(UnitRepository::class);
    $regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', null, null);
    $battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $company = $units->create(UnitType::Company, 'Alpha', 'A/1-7', $battalion->id);
    $platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $this->section = $units->create(UnitType::Section, 'Section A', '1/1/A/1-7', $platoon->id);
    $this->platoon = $platoon;

    $trooper = Trooper::factory()->create(['tis' => '2015-03-01']);
    $trooper->forceFill(['primary_section_id' => $this->section->id])->save();
    $this->trooper = $trooper;
});

function markTrooper(Unit $section, Trooper $trooper, AttendanceStatus $status, RollcallSignature $signature): void
{
    $practice = app(SectionPracticeRepository::class)->findOrCreate($section->id, CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC'));
    app(AttendanceRepository::class)->markPractice($practice, $trooper->id, $status, AttendanceSource::Manual);
    app(AttendanceRepository::class)->signRollcall($practice, $trooper->id, $signature);
}

it('serves each result as a PNG with no login', function () {
    foreach (['rc', 'sp'] as $kind) {
        $response = $this->get(imageUrl($this->section, $kind));

        $response->assertOk()->assertHeader('Content-Type', 'image/png');
        expect(str_starts_with($response->getContent(), "\x89PNG"))->toBeTrue();
    }
});

it('may be embedded by another site and is neither session-bound nor indexed', function () {
    $response = $this->get(imageUrl($this->section));

    $response->assertHeader('Cross-Origin-Resource-Policy', 'cross-origin')
        ->assertHeader('X-Robots-Tag', 'noindex')
        ->assertCookieMissing(config('session.cookie'));
    expect($response->headers->get('Cache-Control'))->toContain('public')->and($response->headers->get('ETag'))->not->toBeEmpty();
});

it('changes the image when a mark changes, and answers 304 while it has not', function () {
    $before = $this->get(imageUrl($this->section));
    $etag = $before->headers->get('ETag');

    $this->get(imageUrl($this->section), ['If-None-Match' => $etag])->assertStatus(304);

    markTrooper($this->section, $this->trooper, AttendanceStatus::Present, RollcallSignature::WillAttend);

    $after = $this->get(imageUrl($this->section), ['If-None-Match' => $etag]);
    $after->assertOk();
    expect($after->headers->get('ETag'))->not->toBe($etag)->and($after->getContent())->not->toBe($before->getContent());
});

it('draws the same marks the roll call holds, per column', function () {
    markTrooper($this->section, $this->trooper, AttendanceStatus::Missed, RollcallSignature::WillAttend);

    $rc = $this->get(imageUrl($this->section, 'rc'))->getContent();
    $sp = $this->get(imageUrl($this->section, 'sp'))->getContent();

    expect($rc)->not->toBe($sp);
});

it('answers 404 for a wrong token, an unknown column, a bad week or a future week', function () {
    $this->get(imageUrl($this->section, token: str_repeat('a', 40)))->assertNotFound();
    $this->get(imageUrl($this->section, 'xx'))->assertNotFound();
    $this->get(imageUrl($this->section, week: '2026-13-45'))->assertNotFound();
    $this->get(imageUrl($this->section, week: '2026-10-10'))->assertNotFound();
});

it('answers 404 for a token that belongs to a unit that is not a section', function () {
    $this->get(imageUrl($this->platoon))->assertNotFound();
});

it('does not leak the unit through the response', function () {
    $response = $this->get(imageUrl($this->section));

    expect($response->headers->get('Content-Disposition'))->not->toContain($this->section->callsign);
});

it('sends the roll call page the addresses of its two images for the week', function () {
    $leader = User::factory()->commanding($this->section)->create();
    $token = $this->section->fresh()->rollcall_image_token;

    $this->actingAs($leader)->get("/rollcall/{$this->section->id}?week=2026-10-03")
        ->assertInertia(fn ($page) => $page
            ->where('resultImages.rc', url("/rollcall-images/{$token}/2026-10-03/rc.png"))
            ->where('resultImages.sp', url("/rollcall-images/{$token}/2026-10-03/sp.png")));
});

it('is rate limited', function () {
    foreach (range(1, 120) as $i) {
        $this->get(imageUrl($this->section));
    }

    $this->get(imageUrl($this->section))->assertStatus(429);
});
