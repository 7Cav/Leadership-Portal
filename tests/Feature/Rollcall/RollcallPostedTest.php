<?php

use App\Models\AuditLogEntry;
use App\Models\PortalRoleGrant;
use App\Models\SectionPractice;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

// 3.10 / AD-20, AD-16, AD-8: "Mark rollcall posted" records the leader's manual forum post.

beforeEach(function () {
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));
    $this->section = Unit::factory()->create(['type' => UnitType::Section, 'path' => '920/']);
    $this->leader = User::factory()->create(['username' => 'Vasquez.R']);
    PortalRoleGrant::factory()->create(['user_id' => $this->leader->id, 'unit_id' => $this->section->id]);
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
    $this->actingAs(User::factory()->create())->postJson("/rollcall/{$this->section->id}/posted", ['week' => '2026-10-03'])
        ->assertForbidden();

    expect(SectionPractice::query()->count())->toBe(0);
});

it('rejects a missing week and an unknown section', function () {
    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/posted", [])->assertStatus(422);
    $this->actingAs($this->leader)->postJson('/rollcall/999999/posted', ['week' => '2026-10-03'])->assertNotFound();
});

it('makes no outbound call — the portal never posts to the forum (AD-20)', function () {
    Http::preventStrayRequests();

    $this->actingAs($this->leader)->postJson("/rollcall/{$this->section->id}/posted", ['week' => '2026-10-03'])->assertOk();
});
