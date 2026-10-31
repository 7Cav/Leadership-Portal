<?php

use App\Models\AppealOutcome;
use App\Models\AuditLogEntry;
use App\Models\CounselingRecord;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/fixture.php';

// #565: "DO appeal" on an LOR queues it for the commander of the unit one level above the issuing unit, who decides
// within 72 hours: HAVE AMENDED (the consequence edited, with a reason) or HAVE NOT AMENDED (with a reason). The decision
// is final, freezes the LOR and goes by PM to the trooper, the issuer and S1. The LOR is not frozen while it is pending.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00:00', 'UTC'));
    Storage::fake('counseling');
    $this->tree = counselingTree();
    $this->rifleman = $this->tree['rifleman'];
    $this->self = User::factory()->create(['cav_id' => $this->rifleman->cav_id, 'username' => 'Brandt.L']);
    $this->co = $this->tree['users']['co'];
    $this->pl = $this->tree['users']['pl'];
    $this->s1 = Trooper::factory()->create(['billet' => 'S1 1IC', 'status' => TrooperStatus::Combat]);
    $this->forum = counselingFakeForum();

    $this->lor = CounselingRecord::factory()->written()->lor()->sharedHoursAgo(2)->create([
        'trooper_id' => $this->rifleman->id,
        'issued_by' => $this->pl->id,
        'issuer_unit_id' => $this->tree['units']['platoon']->id,
        'appeal_unit_id' => $this->tree['units']['company']->id,
        'counseled_on' => now('UTC')->subDay()->toDateString(),
        'form_data' => [...CounselingRecord::factory()->written()->make()->form_data, 'consequences' => ['nfa', 'removal'], 'removal' => 'assistant_section_leader'],
    ]);

    $this->appeal = fn () => $this->actingAs($this->self)->withSession(counselingPmSession())
        ->post(route('counseling.respond', $this->lor->id), ['appeal' => 'do', 'appeal_reason' => 'My shifts changed; an LOR is too much.']);
    $this->decide = fn (User $as, array $data) => $this->actingAs($as)->withSession(counselingPmSession())
        ->post(route('counseling.decide', $this->lor->id), $data);
});

it('sends a DO appeal from the trooper\'s account to the commander one level above, and queues it for them', function () {
    ($this->appeal)()->assertSessionHasNoErrors();

    $appealed = $this->lor->fresh();
    expect($appealed->state())->toBe('appealed')
        ->and($appealed->appeal_statement)->toBe('My shifts changed; an LOR is too much.')
        ->and($appealed->appealDueAt()->toIso8601String())->toBe(now()->addHours(72)->toIso8601String());

    $pms = counselingSentPms($this->forum);
    $commander = Trooper::query()->where('cav_id', $this->co->cav_id)->sole();
    expect($pms)->toHaveCount(1)
        ->and($pms[0]['title'])->toBe('Appeal: Letter of Reprimand')
        ->and($pms[0]['recipients'])->toBe([(int) $commander->forum_user_id])
        ->and($pms[0]['message'])->toContain(route('counseling.appeals'));

    $page = $this->actingAs($this->co)->get(route('counseling.appeals'))->assertOk()->viewData('page')['props'];
    expect(collect($page['appeals'])->pluck('id')->all())->toBe([$this->lor->id])
        ->and($page['appeals'][0]['canDecide'])->toBeTrue()
        ->and($page['counselingAppeals'])->toBeTrue()
        ->and($page['counselingAppealsPending'])->toBe(1);

    // Nobody else hears it: the issuer, their company's other leaders, the trooper.
    foreach ([$this->pl, $this->self] as $viewer) {
        $props = $this->actingAs($viewer)->get(route('counseling.appeals'))->viewData('page')['props'];
        expect($props['appeals'])->toBe([])->and($props['counselingAppeals'])->toBeFalse();
    }
});

it('needs the trooper to allow PMs before a DO appeal goes, and refuses an appeal with nobody to hear it', function () {
    $this->actingAs($this->self)->post(route('counseling.respond', $this->lor->id), ['appeal' => 'do', 'appeal_reason' => 'x'])->assertSessionHasErrors('pm_consent');

    $this->lor->forceFill(['appeal_unit_id' => null])->save();
    ($this->appeal)()->assertSessionHasErrors('appeal');

    expect($this->lor->fresh()->isResponded())->toBeFalse();
});

it('keeps an LOR unfrozen while its appeal is pending, past 72 hours after it was shared', function () {
    // Shared 2 hours ago and appealed now: 71 hours on, it is 73 hours after the share and still within the appeal's 72.
    ($this->appeal)();
    $this->travel(71)->hours();

    expect($this->lor->fresh()->isFrozen())->toBeFalse();
});

it('records HAVE NOT AMENDED with its reason and the authority as they stand, freezes the LOR and tells the trooper, the issuer and S1', function () {
    ($this->appeal)();

    ($this->decide)($this->co, ['outcome' => 'not_amended', 'reason' => ''])->assertSessionHasErrors('reason');
    ($this->decide)($this->co, ['outcome' => 'not_amended', 'reason' => 'The letter is fair.'])->assertSessionHasNoErrors();

    $decided = $this->lor->fresh();
    expect($decided->appeal_outcome)->toBe(AppealOutcome::NotAmended)
        ->and($decided->appeal_decision_reason)->toBe('The letter is fair.')
        ->and($decided->appeal_authority['name'])->toBe('Halvorsen.R')
        ->and($decided->appeal_original)->toBeNull()
        ->and($decided->isFrozen())->toBeTrue()
        ->and($decided->state())->toBe('decided')
        ->and(AuditLogEntry::query()->where('action', 'counseling.appeal_decided')->value('reason'))->toBe('The letter is fair.');

    $pm = collect(counselingSentPms($this->forum))->last();
    expect($pm['title'])->toBe('Appeal decided: Letter of Reprimand')
        ->and($pm['recipients'])->toBe([(int) $this->rifleman->forum_user_id, (int) $this->pl->forum_user_id, (int) $this->s1->forum_user_id])
        ->and($pm['message'])->toContain('HAVE NOT AMENDED');

    // Final: no second decision.
    ($this->decide)($this->co, ['outcome' => 'amended', 'reason' => 'Changed my mind.', 'form' => ['consequences' => ['nfa']]])->assertForbidden();
});

it('records HAVE AMENDED with the consequence as amended, keeping the one first written in the record\'s history', function () {
    ($this->appeal)();

    ($this->decide)($this->co, ['outcome' => 'amended', 'reason' => 'Removal is too much here.', 'form' => ['consequences' => []]])
        ->assertSessionHasErrors('form.consequences');
    ($this->decide)($this->co, ['outcome' => 'amended', 'reason' => 'Removal is too much here.', 'form' => ['consequences' => ['nfa']]])
        ->assertSessionHasNoErrors();

    $decided = $this->lor->fresh();
    expect($decided->appeal_outcome)->toBe(AppealOutcome::Amended)
        ->and($decided->form_data['consequences'])->toBe(['nfa'])
        ->and($decided->form_data['removal'])->toBeNull()
        ->and($decided->form_data['infraction'])->toBe('Missed section practice on 10, 17 and 24 Oct without an LOA.')
        ->and($decided->appeal_original['consequences'])->toBe(['nfa', 'removal'])
        ->and($decided->appeal_original['removal'])->toBe('assistant_section_leader');
});

it('lets nobody but the appeal authority decide: not the issuer, not an Admin, not the trooper', function () {
    ($this->appeal)();

    foreach ([$this->pl, $this->tree['users']['admin'], $this->self] as $who) {
        ($this->decide)($who, ['outcome' => 'not_amended', 'reason' => 'x'])->assertForbidden();
    }

    expect($this->lor->fresh()->isAppealDecided())->toBeFalse();
});

it('lets an undecided appeal lapse after 72 hours: the letter stands as written and freezes', function () {
    ($this->appeal)();
    $this->travel(72)->hours();

    $lapsed = $this->lor->fresh();
    expect($lapsed->isAppealPending())->toBeFalse()
        ->and($lapsed->isFrozen())->toBeTrue()
        ->and($lapsed->state())->toBe('acknowledged');

    ($this->decide)($this->co, ['outcome' => 'not_amended', 'reason' => 'Late.'])->assertForbidden();
    expect($this->actingAs($this->co)->get(route('counseling.appeals'))->viewData('page')['props']['counselingAppealsPending'])->toBe(0);
});

it('refuses a withdrawal once the trooper has asked to appeal', function () {
    ($this->appeal)();

    $this->actingAs($this->pl)->withSession(counselingPmSession())->post(route('counseling.withdraw', $this->lor->id), ['reason' => 'x'])->assertForbidden();
});
