<?php

use App\Models\AuditLogEntry;
use App\Models\SectionPractice;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

// 3.10 / AD-20, AD-16, AD-8: "Mark rollcall posted" records the leader's manual forum post.

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));
    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'path' => '920/']);
    $this->leader = User::factory()->commanding($this->section)->create(['username' => 'Vasquez.R']);
});

it('records when and by whom the rollcall was posted, and shows it on the page', function () {
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/posted", ['week' => '2026-10-03'])
        ->assertOk()
        ->assertJsonPath('posted', ['at' => '2026-10-08T16:20:00+00:00', 'by' => 'Vasquez.R']);

    expect(AuditLogEntry::query()->where('action', 'rollcall.posted')->count())->toBe(1);

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn ($page) => $page->where('practice.posted', ['at' => '2026-10-08T16:20:00+00:00', 'by' => 'Vasquez.R']));
});

it('shows a week never marked posted as not posted', function () {
    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}")
        ->assertInertia(fn ($page) => $page->where('practice.posted', null));
});

it('lets a past week be marked posted, but never a future one', function () {
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/posted", ['week' => '2026-09-26'])->assertOk();
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/posted", ['week' => '2026-10-10'])->assertStatus(422);

    expect(SectionPractice::query()->sole()->week_start->toDateString())->toBe('2026-09-26');
});

it('refuses a section outside the leader\'s scope (AD-8)', function () {
    $this->actingAs(User::factory()->serving()->create())->postJson("/rollcall/{$this->section->id}/posted", ['week' => '2026-10-03'])
        ->assertForbidden();

    expect(SectionPractice::query()->count())->toBe(0);
});

it('rejects a missing week, and answers an id outside scope with 403', function () {
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/posted", [])->assertStatus(422);
    $this->actingAs($this->leader)->postJson('/rollcall/999999/posted', ['week' => '2026-10-03'])->assertForbidden();
});

it('refuses a unit that is not a section, even for an admin', function () {
    $platoon = Unit::factory()->create(['type' => UnitType::Platoon, 'path' => '921/']);

    $this->actingAs(User::factory()->admin()->create())->postJson("/rollcall/{$platoon->id}/posted", ['week' => '2026-10-03'])
        ->assertStatus(422);
});

it('makes no outbound call — the portal never posts to the forum (AD-20)', function () {
    Http::preventStrayRequests();

    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/posted", ['week' => '2026-10-03'])->assertOk();
});

it('refuses to mark a week posted once its report has been sent, as locked (AD-12)', function () {
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->section->id, 'week_start' => '2026-10-03']);

    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/posted", ['week' => '2026-10-03'])
        ->assertStatus(409)->assertJson(['locked' => true, 'message' => 'The report for this week has been sent, so its roll call is locked.']);

    expect(SectionPractice::query()->whereNotNull('rollcall_posted_at')->count())->toBe(0)
        ->and(AuditLogEntry::query()->where('action', 'rollcall.posted')->count())->toBe(0);
});
