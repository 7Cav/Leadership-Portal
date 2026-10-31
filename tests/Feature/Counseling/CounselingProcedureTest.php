<?php

use App\Models\AuditLogEntry;
use App\Models\CounselingRecord;
use App\Models\CounselingStatus;
use App\Models\Trooper;
use App\Models\TrooperStatus;
use App\Models\UnitActingLead;
use App\Models\UnitOversight;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

require_once __DIR__.'/fixture.php';

// #565: a DCS or LOR is written in the portal, field by field, through the wiki procedure: a draft the leader saves
// at any step, an optional meeting invite, an explicit share that locks the leader's part and sends the After-Action PM
// from their own forum account, a withdrawal back to draft before it locks, and the trooper's one response.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00:00', 'UTC'));
    Storage::fake('counseling');
    $this->tree = counselingTree();
    $this->rifleman = $this->tree['rifleman'];
    $this->self = User::factory()->create(['cav_id' => $this->rifleman->cav_id, 'username' => 'Brandt.L']);
    $this->forum = counselingFakeForum();
    $this->sl = $this->tree['users']['sl'];
    $this->pl = $this->tree['users']['pl'];

    $this->start = function (User $as, string $type = 'dcs'): CounselingRecord {
        $this->actingAs($as)->post(route('counseling.store', $this->rifleman->id), ['type' => $type, 'kind' => 'conduct'])->assertRedirect();

        return CounselingRecord::query()->latest('id')->firstOrFail();
    };
    $this->save = fn (User $as, CounselingRecord $record, array $payload) => $this->actingAs($as)->patch(route('counseling.update', $record->id), $payload);
    $this->share = fn (User $as, CounselingRecord $record) => $this->actingAs($as)->withSession(counselingPmSession($as))->post(route('counseling.share', $record->id));
});

it('starts a draft through the issuer\'s command and opens it, seen only by the issuer, their chain and Admins', function () {
    $this->actingAs($this->sl)->post(route('counseling.store', $this->rifleman->id), ['type' => 'dcs', 'kind' => 'conduct'])
        ->assertRedirect(route('counseling.show', CounselingRecord::query()->value('id')));

    $draft = CounselingRecord::query()->sole();
    expect($draft->status)->toBe(CounselingStatus::Draft)
        ->and($draft->issued_by)->toBe($this->sl->id)
        ->and($draft->issuer_unit_id)->toBe($this->tree['units']['section']->id)
        ->and($draft->counseled_on)->toBeNull()
        ->and(AuditLogEntry::query()->where('action', 'counseling.started')->count())->toBe(1);

    foreach ([$this->sl, $this->pl, $this->tree['users']['co'], $this->tree['users']['admin']] as $viewer) {
        $this->actingAs($viewer)->get(route('counseling.show', $draft->id))->assertOk();
    }

    foreach ([$this->self, $this->tree['users']['otherSl'], $this->tree['users']['grant']] as $viewer) {
        $this->actingAs($viewer)->get(route('counseling.show', $draft->id))->assertNotFound();
    }

    $ids = fn (User $as) => collect($this->flushHeaders()->actingAs($as)->get(route('troopers.show', $this->rifleman->id))->viewData('page')['props']['counseling']['records'])->pluck('id')->all();
    expect($ids($this->pl))->toBe([$draft->id])->and($ids($this->self))->toBe([]);
});

it('keeps who may start each type as it was: a DCS from Section Leader up, an LOR from platoon command up', function () {
    $this->actingAs($this->sl)->post(route('counseling.store', $this->rifleman->id), ['type' => 'lor', 'kind' => 'conduct'])->assertForbidden();
    $this->actingAs($this->self)->post(route('counseling.store', $this->rifleman->id), ['type' => 'dcs', 'kind' => 'conduct'])->assertNotFound();
    $this->actingAs($this->pl)->post(route('counseling.store', $this->rifleman->id), ['type' => 'lor', 'kind' => 'conduct'])->assertRedirect();

    expect(CounselingRecord::query()->count())->toBe(1);
});

it('saves a draft as it stands from any step, raising its version, and saves it again later', function () {
    $draft = ($this->start)($this->sl);

    ($this->save)($this->sl, $draft, ['type' => 'dcs', 'kind' => 'missed_practice', 'form' => ['infraction' => 'Missed practice.']])
        ->assertRedirect()->assertSessionHasNoErrors();

    $saved = $draft->fresh();
    expect($saved->version)->toBe(2)
        ->and($saved->form_data['infraction'])->toBe('Missed practice.')
        ->and($saved->form_data['discussion'])->toBe('')
        ->and($saved->kind->value)->toBe('missed_practice')
        ->and($saved->status)->toBe(CounselingStatus::Draft);

    ($this->save)($this->sl, $draft, counselingDraftPayload())->assertSessionHasNoErrors();

    expect($draft->fresh()->version)->toBe(3)
        ->and($draft->fresh()->form_data['discussion'])->toBe('Shifts changed; we went through the LOA process together.')
        ->and(AuditLogEntry::query()->where('action', 'counseling.draft_saved')->count())->toBe(2);
});

it('refuses a future Date of Letter, text over its cap, and turning a DCS into an LOR without the right to file one', function () {
    $draft = ($this->start)($this->sl);

    ($this->save)($this->sl, $draft, counselingDraftPayload(['counseled_on' => now('UTC')->addDay()->toDateString()]))->assertSessionHasErrors('counseled_on');
    ($this->save)($this->sl, $draft, counselingDraftPayload([], ['discussion' => str_repeat('x', 3001)]))->assertSessionHasErrors('form.discussion');
    ($this->save)($this->sl, $draft, counselingDraftPayload(['type' => 'lor']))->assertForbidden();

    expect($draft->fresh()->version)->toBe(1);
});

it('lets the issuer\'s chain work on the draft, and nobody outside it', function () {
    $draft = ($this->start)($this->sl);

    ($this->save)($this->pl, $draft, counselingDraftPayload())->assertSessionHasNoErrors();
    ($this->save)($this->tree['users']['otherSl'], $draft, counselingDraftPayload())->assertNotFound();
    ($this->save)($this->self, $draft, counselingDraftPayload())->assertNotFound();
});

it('deletes a draft, audited, and never a shared record', function () {
    $draft = ($this->start)($this->sl);

    $this->actingAs($this->sl)->delete(route('counseling.destroy', $draft->id))->assertRedirect(route('troopers.show', $this->rifleman->id));
    expect(CounselingRecord::query()->count())->toBe(0)
        ->and(AuditLogEntry::query()->where('action', 'counseling.draft_deleted')->count())->toBe(1);

    $shared = CounselingRecord::factory()->written()->create(['trooper_id' => $this->rifleman->id, 'issued_by' => $this->sl->id, 'issuer_unit_id' => $this->tree['units']['section']->id]);
    $this->actingAs($this->sl)->delete(route('counseling.destroy', $shared->id))->assertForbidden();
    expect($shared->fresh())->not->toBeNull();
});

it('blocks share until the notes of discussion, the leader\'s plan and the meeting confirmation are in, and says what is missing', function () {
    $draft = ($this->start)($this->sl);
    ($this->save)($this->sl, $draft, counselingDraftPayload(['meeting_confirmed' => false, 'counseled_on' => null], ['discussion' => '', 'plan_authority' => '']));

    ($this->share)($this->sl, $draft)->assertSessionHasErrors(['discussion', 'plan_authority', 'meeting_confirmed', 'counseled_on']);

    expect($draft->fresh()->status)->toBe(CounselingStatus::Draft)
        ->and(counselingSentPms($this->forum))->toBe([]);
});

it('asks for the meeting invite and a recommended consequence before an LOR is shared', function () {
    $draft = ($this->start)($this->pl, 'lor');
    ($this->save)($this->pl, $draft, counselingDraftPayload(['type' => 'lor']));

    ($this->share)($this->pl, $draft)->assertSessionHasErrors(['invite', 'consequences']);
});

it('shares a DCS: locks it, snapshots Section 1 and the Date of Letter, and sends the After-Action PM from the leader\'s account', function () {
    $draft = ($this->start)($this->sl);
    ($this->save)($this->sl, $draft, counselingDraftPayload());

    ($this->share)($this->sl, $draft)->assertRedirect()->assertSessionHasNoErrors();

    $shared = $draft->fresh();
    expect($shared->status)->toBe(CounselingStatus::Shared)
        ->and($shared->shared_at->toIso8601String())->toBe(now()->toIso8601String())
        ->and($shared->snapshot['trooper']['name'])->toBe('Brandt.L')
        ->and($shared->snapshot['authority']['name'])->toBe('Okafor.B')
        ->and($shared->snapshot['date'])->toBe(now('UTC')->subDay()->toDateString())
        ->and($shared->appeal_unit_id)->toBeNull()
        ->and(AuditLogEntry::query()->where('action', 'counseling.shared')->count())->toBe(1);

    $pms = counselingSentPms($this->forum);
    expect($pms)->toHaveCount(1)
        ->and($pms[0]['title'])->toBe('After-Action: Developmental Counselling Statement')
        ->and($pms[0]['recipients'])->toBe([(int) $this->rifleman->forum_user_id])
        ->and($pms[0]['token'])->toBe('Bearer pm-token')
        ->and($pms[0]['message'])->toContain(route('counseling.show', $draft->id));

    // The leader's part is locked from now on.
    ($this->save)($this->sl, $draft, counselingDraftPayload([], ['discussion' => 'Changed.']))->assertForbidden();
    // A renamed trooper does not change what was shared.
    $this->rifleman->forceFill(['username' => 'Renamed.X'])->save();
    expect($shared->fresh()->snapshot['trooper']['name'])->toBe('Brandt.L');
});

it('sends the meeting invite from the leader\'s account with the three Zulu windows, once, and starts the 72-hour window', function () {
    $draft = ($this->start)($this->sl);

    $this->actingAs($this->sl)->withSession(counselingPmSession($this->sl))
        ->post(route('counseling.invite', $draft->id), ['windows' => ['Thu 1900Z to 2100Z', 'Fri 1800Z to 2000Z', 'Sat 1400Z to 1600Z']])
        ->assertSessionHasNoErrors();

    $invited = $draft->fresh();
    expect($invited->status)->toBe(CounselingStatus::Invited)
        ->and($invited->meetingClosesAt()->toIso8601String())->toBe(now()->addHours(72)->toIso8601String())
        ->and($invited->meeting['windows'])->toHaveCount(3);

    $pms = counselingSentPms($this->forum);
    expect($pms[0]['title'])->toBe('Meeting: Developmental Counselling Statement')
        ->and($pms[0]['message'])->toContain('1. Thu 1900Z to 2100Z')
        ->and($pms[0]['message'])->toContain('What time can you meet?');

    $this->actingAs($this->sl)->withSession(counselingPmSession($this->sl))
        ->post(route('counseling.invite', $draft->id), ['windows' => ['a', 'b', 'c']])->assertForbidden();
    $this->actingAs($this->sl)->withSession(counselingPmSession($this->sl))
        ->post(route('counseling.invite', ($this->start)($this->sl)->id), ['windows' => ['a', 'b']])->assertSessionHasErrors('windows');
});

it('shares an LOR with the trooper, then copies it to S1 1IC, S1 2IC and the battalion\'s oversight officer, and resolves the appeal authority', function () {
    $s1 = Trooper::factory()->create(['billet' => 'S1 1IC', 'status' => TrooperStatus::Combat]);
    $s2 = Trooper::factory()->create(['billet' => 'S1 2IC', 'status' => TrooperStatus::Combat]);
    Trooper::factory()->create(['billet' => 'S1 1IC', 'status' => TrooperStatus::Inactive]);
    $officer = Trooper::factory()->create(['status' => TrooperStatus::Combat]);
    UnitOversight::query()->create(['unit_id' => $this->tree['units']['battalion']->id, 'trooper_id' => $officer->id]);

    $draft = ($this->start)($this->pl, 'lor');
    $this->actingAs($this->pl)->withSession(counselingPmSession($this->pl))->post(route('counseling.invite', $draft->id), ['windows' => ['a', 'b', 'c']]);
    ($this->save)($this->pl, $draft, counselingDraftPayload(['type' => 'lor'], ['consequences' => ['nfa']]));

    ($this->share)($this->pl, $draft)->assertSessionHasNoErrors()->assertSessionHas('status', 'Shared with the trooper.');

    $pms = counselingSentPms($this->forum);
    expect(array_column($pms, 'title'))->toBe(['Meeting: Letter of Reprimand', 'After-Action: Letter of Reprimand', 'Letter of Reprimand: '.trim($this->rifleman->rank_short.' Brandt.L')])
        ->and($pms[1]['recipients'])->toBe([(int) $this->rifleman->forum_user_id])
        ->and($pms[1]['message'])->toContain('within 24 hours')
        ->and($pms[2]['recipients'])->toBe([(int) $s1->forum_user_id, (int) $s2->forum_user_id, (int) $officer->forum_user_id])
        ->and($draft->fresh()->appeal_unit_id)->toBe($this->tree['units']['company']->id);
});

it('never tells S1 of an LOR whose share failed, and keeps a share whose S1 copy failed, telling the leader to send it', function () {
    Trooper::factory()->create(['billet' => 'S1 1IC', 'status' => TrooperStatus::Combat]);
    $draft = ($this->start)($this->pl, 'lor');
    $this->actingAs($this->pl)->withSession(counselingPmSession($this->pl))->post(route('counseling.invite', $draft->id), ['windows' => ['a', 'b', 'c']]);
    ($this->save)($this->pl, $draft, counselingDraftPayload(['type' => 'lor'], ['consequences' => ['nfa']]));

    // The trooper's PM fails: the share rolls back, and S1 was never written to.
    $this->forum = counselingFakeForum(MockResponse::make(['errors' => [['code' => 'server_error']]], 500));
    ($this->share)($this->pl, $draft)->assertSessionHasErrors('pm');
    expect($draft->fresh()->isDraft())->toBeTrue()->and(counselingSentPms($this->forum))->toHaveCount(1);

    // The trooper's PM goes, the S1 copy does not: shared all the same, and the leader is told to send the copy.
    MockClient::destroyGlobal();
    $this->forum = MockClient::global([
        MockResponse::make(['conversation' => ['conversation_id' => 1]]),
        MockResponse::make(['errors' => [['code' => 'no_permission']]], 403),
    ]);
    ($this->share)($this->pl, $draft)->assertSessionHasNoErrors()->assertSessionHas('status', fn (string $status) => str_contains($status, 'Send it to S1 yourself'));
    expect($draft->fresh()->isShared())->toBeTrue();
});

it('tells the leader when nobody can take the LOR\'s copy for record processing', function () {
    $draft = ($this->start)($this->pl, 'lor');
    $this->actingAs($this->pl)->withSession(counselingPmSession($this->pl))->post(route('counseling.invite', $draft->id), ['windows' => ['a', 'b', 'c']]);
    ($this->save)($this->pl, $draft, counselingDraftPayload(['type' => 'lor'], ['consequences' => ['nfa']]));

    ($this->share)($this->pl, $draft)->assertSessionHas('status', fn (string $status) => str_contains($status, 'No S1 or oversight officer'));
    expect($draft->fresh()->isShared())->toBeTrue();
});

it('issues a draft turned from a DCS into an LOR through the command that may issue an LOR', function () {
    // The Platoon Leader is acting Section Leader too: a DCS goes through the section, an LOR through the platoon.
    UnitActingLead::query()->forceCreate(['unit_id' => $this->tree['units']['section']->id, 'trooper_id' => Trooper::query()->where('cav_id', $this->pl->cav_id)->value('id')]);
    $draft = ($this->start)($this->pl, 'dcs');
    expect($draft->issuer_unit_id)->toBe($this->tree['units']['section']->id);

    ($this->save)($this->pl, $draft, counselingDraftPayload(['type' => 'lor']))->assertSessionHasNoErrors();

    expect($draft->fresh()->issuer_unit_id)->toBe($this->tree['units']['platoon']->id);
});

it('sends a PM only from the person whose forum account allowed it', function () {
    $draft = ($this->start)($this->sl);
    ($this->save)($this->sl, $draft, counselingDraftPayload());

    // A grant left in the session by someone else's account serves nobody else.
    $this->actingAs($this->sl)->withSession(counselingPmSession($this->pl))->post(route('counseling.share', $draft->id))->assertSessionHasErrors('pm_consent');
    expect($draft->fresh()->isDraft())->toBeTrue()->and(counselingSentPms($this->forum))->toBe([]);
});

it('says the PM may have gone out when the forum does not answer in time, so it is checked before a retry', function () {
    $draft = ($this->start)($this->sl);
    ($this->save)($this->sl, $draft, counselingDraftPayload());
    $this->forum = counselingFakeForum(MockResponse::make(['conversation' => []]));

    ($this->share)($this->sl, $draft)->assertSessionHasErrors(['pm' => 'The PM may or may not have been sent: the forum did not answer in time, or answered in a way the portal could not read. Nothing was changed in the portal. Check your forum conversations before trying again, so it is not sent twice.']);
    expect($draft->fresh()->isDraft())->toBeTrue();
});

it('drops the trooper\'s answer when a record is withdrawn, so they answer the corrected one again', function () {
    $draft = ($this->start)($this->sl);
    ($this->save)($this->sl, $draft, counselingDraftPayload());
    ($this->share)($this->sl, $draft);
    $this->actingAs($this->self)->post(route('counseling.respond', $draft->id), ['plan_trooper' => 'First answer.'])->assertSessionHasNoErrors();

    $this->actingAs($this->sl)->withSession(counselingPmSession($this->sl))->post(route('counseling.withdraw', $draft->id), ['reason' => 'Wrong date.']);
    ($this->share)($this->sl, $draft)->assertSessionHasNoErrors();

    $again = $draft->fresh();
    expect($again->state())->toBe('shared')
        ->and($again->form_data)->not->toHaveKey('plan_trooper');
    $this->actingAs($this->self)->post(route('counseling.respond', $draft->id), ['plan_trooper' => 'Second answer.'])->assertSessionHasNoErrors();
});

it('refuses a response made while someone acts as the trooper', function () {
    $record = CounselingRecord::factory()->written()->sharedHoursAgo(2)->create([
        'trooper_id' => $this->rifleman->id, 'issued_by' => $this->sl->id, 'issuer_unit_id' => $this->tree['units']['section']->id,
    ]);
    $admin = $this->tree['users']['admin'];
    $this->actingAs($admin)->post('/act-as', ['kind' => 'user', 'id' => $this->self->id])->assertRedirect('/home');

    // Acting as someone never opens their own records: the record is not there for the one acting.
    $this->actingAs($admin)->post(route('counseling.respond', $record->id), ['plan_trooper' => 'Not mine to write.'])->assertNotFound();
    expect($record->fresh()->isResponded())->toBeFalse();
});

it('asks the leader to allow PMs first, and shares nothing without that', function () {
    $draft = ($this->start)($this->sl);
    ($this->save)($this->sl, $draft, counselingDraftPayload());

    $this->actingAs($this->sl)->post(route('counseling.share', $draft->id))->assertSessionHasErrors('pm_consent');

    $this->actingAs($this->sl)->withSession(['forum_pm_grant' => encrypt(['token' => 'old', 'expires_at' => now()->subMinute()->toIso8601String()])])
        ->post(route('counseling.share', $draft->id))->assertSessionHasErrors('pm_consent');

    expect($draft->fresh()->status)->toBe(CounselingStatus::Draft)->and(counselingSentPms($this->forum))->toBe([]);
});

it('leaves the record unchanged and says why when the forum does not send the PM', function () {
    $draft = ($this->start)($this->sl);
    ($this->save)($this->sl, $draft, counselingDraftPayload());

    $this->forum = counselingFakeForum(MockResponse::make(['errors' => [['code' => 'server_error']]], 500));
    ($this->share)($this->sl, $draft)->assertSessionHasErrors('pm');
    expect($draft->fresh()->status)->toBe(CounselingStatus::Draft)
        ->and(AuditLogEntry::query()->where('action', 'counseling.shared')->count())->toBe(0);

    // A token the forum no longer accepts: asked to allow PMs again, the grant dropped.
    $this->forum = counselingFakeForum(MockResponse::make(['errors' => [['code' => 'invalid_token']]], 401));
    ($this->share)($this->sl, $draft)->assertSessionHasErrors('pm_consent')->assertSessionMissing('forum_pm_grant');
    expect($draft->fresh()->status)->toBe(CounselingStatus::Draft);
});

it('withdraws a shared record back to draft with a required reason before it locks, and tells the trooper', function () {
    $draft = ($this->start)($this->sl);
    ($this->save)($this->sl, $draft, counselingDraftPayload());
    ($this->share)($this->sl, $draft);

    $this->actingAs($this->sl)->withSession(counselingPmSession($this->sl))->post(route('counseling.withdraw', $draft->id), ['reason' => ''])->assertSessionHasErrors('reason');
    $this->actingAs($this->sl)->withSession(counselingPmSession($this->sl))->post(route('counseling.withdraw', $draft->id), ['reason' => 'Wrong date.'])->assertSessionHasNoErrors();

    $withdrawn = $draft->fresh();
    expect($withdrawn->status)->toBe(CounselingStatus::Draft)
        ->and($withdrawn->snapshot)->toBeNull()
        ->and($withdrawn->withdraw_reason)->toBe('Wrong date.')
        ->and(collect(counselingSentPms($this->forum))->last()['title'])->toBe('Withdrawn: Developmental Counselling Statement')
        ->and(AuditLogEntry::query()->where('action', 'counseling.withdrawn')->value('reason'))->toBe('Wrong date.');
});

it('refuses to withdraw a record once it locks, 72 hours after it was shared', function () {
    $record = CounselingRecord::factory()->written()->sharedHoursAgo(72)->create([
        'trooper_id' => $this->rifleman->id, 'issued_by' => $this->sl->id, 'issuer_unit_id' => $this->tree['units']['section']->id,
    ]);

    $this->actingAs($this->sl)->withSession(counselingPmSession($this->sl))->post(route('counseling.withdraw', $record->id), ['reason' => 'x'])->assertForbidden();
    expect($record->fresh()->isShared())->toBeTrue();
});

it('lets the trooper respond to a shared DCS once, with their plan and comments, which acknowledges it', function () {
    $record = CounselingRecord::factory()->written()->sharedHoursAgo(2)->create([
        'trooper_id' => $this->rifleman->id, 'issued_by' => $this->sl->id, 'issuer_unit_id' => $this->tree['units']['section']->id,
    ]);
    expect($record->state())->toBe('shared');

    $this->actingAs($this->sl)->post(route('counseling.respond', $record->id), ['plan_trooper' => 'x'])->assertForbidden();
    $this->actingAs($this->self)->post(route('counseling.respond', $record->id), ['plan_trooper' => 'Post an LOA when I cannot make it.', 'comments_trooper' => 'Understood.'])
        ->assertSessionHasNoErrors();

    $answered = $record->fresh();
    expect($answered->state())->toBe('acknowledged')
        ->and($answered->form_data['plan_trooper'])->toBe('Post an LOA when I cannot make it.')
        ->and($answered->form_data['plan_authority'])->toBe('Check in weekly before practice for four weeks.')
        ->and($answered->responded_by)->toBe($this->self->id)
        ->and(counselingSentPms($this->forum))->toBe([]);

    $this->actingAs($this->self)->post(route('counseling.respond', $record->id), ['plan_trooper' => 'Again.'])->assertForbidden();
});

it('closes the LOR\'s answer 24 hours after it was shared: No response, the letter stands as written', function () {
    $record = CounselingRecord::factory()->written()->lor()->sharedHoursAgo(24)->create([
        'trooper_id' => $this->rifleman->id, 'issued_by' => $this->pl->id, 'issuer_unit_id' => $this->tree['units']['platoon']->id,
        'appeal_unit_id' => $this->tree['units']['company']->id,
    ]);

    expect($record->state())->toBe('no_response');
    $this->actingAs($this->self)->post(route('counseling.respond', $record->id), ['appeal' => 'do_not'])->assertForbidden();
});

it('takes the trooper\'s answer within the 24 hours, DO NOT appeal needing no PM', function () {
    $record = CounselingRecord::factory()->written()->lor()->sharedHoursAgo(23)->create([
        'trooper_id' => $this->rifleman->id, 'issued_by' => $this->pl->id, 'issuer_unit_id' => $this->tree['units']['platoon']->id,
        'appeal_unit_id' => $this->tree['units']['company']->id,
    ]);

    $this->actingAs($this->self)->post(route('counseling.respond', $record->id), ['plan_trooper' => '', 'appeal' => ''])->assertSessionHasErrors('appeal');
    $this->actingAs($this->self)->post(route('counseling.respond', $record->id), ['appeal' => 'do'])->assertSessionHasErrors('appeal_reason');
    $this->actingAs($this->self)->post(route('counseling.respond', $record->id), ['appeal' => 'do_not'])->assertSessionHasNoErrors();

    expect($record->fresh()->state())->toBe('acknowledged')
        ->and($record->fresh()->form_data['appeal'])->toBe('do_not')
        ->and($record->fresh()->isAppealed())->toBeFalse();
});

it('puts every record waiting for the trooper\'s answer on their Home, soonest due first, with its deadline', function () {
    $dcs = CounselingRecord::factory()->written()->sharedHoursAgo(10)->create([
        'trooper_id' => $this->rifleman->id, 'issued_by' => $this->sl->id, 'issuer_unit_id' => $this->tree['units']['section']->id,
    ]);
    $lor = CounselingRecord::factory()->written()->lor()->sharedHoursAgo(2)->create([
        'trooper_id' => $this->rifleman->id, 'issued_by' => $this->pl->id, 'issuer_unit_id' => $this->tree['units']['platoon']->id,
    ]);
    // Answered, or a draft: not waiting.
    CounselingRecord::factory()->written()->responded()->create(['trooper_id' => $this->rifleman->id, 'issued_by' => $this->sl->id]);
    CounselingRecord::factory()->draft()->create(['trooper_id' => $this->rifleman->id, 'issued_by' => $this->sl->id]);

    $home = $this->actingAs($this->self)->get(route('home'))->assertOk()->viewData('page')['props']['counseling'];

    expect(collect($home)->pluck('id')->all())->toBe([$lor->id, $dcs->id])
        ->and($home[0]['respondBy'])->toBe(now()->addHours(22)->utc()->format('Y-m-d\TH:i:s\Z'))
        ->and($home[0]['href'])->toBe(route('counseling.show', $lor->id));

    // Nobody else sees the trooper's countdown.
    expect($this->actingAs($this->pl)->get(route('home'))->viewData('page')['props']['counseling'])->toBe([]);
});
