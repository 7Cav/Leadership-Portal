<?php

use App\Commands\Counseling\CounselingDocument;
use App\Models\AppealOutcome;
use App\Models\AuditLogEntry;
use App\Models\CounselingRecord;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/fixture.php';

// #565: `GET /counseling/{id}/document` checks access, then serves the record's document. A record written in the portal
// answers the official form's fields, which the browser fills into the regiment's template (agreed 2026-10-31: no PDF is
// generated or kept on the server). A record kept with its source PDF serves that file as it is. Every download is
// audited with the viewer, the record and the SHA-256 of what was served.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-30 12:00:00', 'UTC'));
    Storage::fake('counseling');
    $this->tree = counselingTree();
    $this->rifleman = $this->tree['rifleman'];
    $this->pl = $this->tree['users']['pl'];
    $this->written = fn (array $attributes = []) => CounselingRecord::factory()->written()->lor()->create([
        'trooper_id' => $this->rifleman->id,
        'issued_by' => $this->pl->id,
        'issuer_unit_id' => $this->tree['units']['platoon']->id,
        'counseled_on' => '2026-10-29',
        'snapshot' => [
            'date' => '2026-10-29',
            'trooper' => ['rank' => 'PFC', 'name' => 'Brandt.L', 'billet' => 'Rifleman'],
            'authority' => ['rank' => '2LT', 'name' => 'Mercer.J', 'billet' => 'Platoon Leader'],
        ],
        ...$attributes,
    ]);
});

it('serves a written LOR as the R-043 form\'s fields, Section 1 from the snapshot, and audits the download', function () {
    $record = ($this->written)([
        'form_data' => [
            ...CounselingRecord::factory()->written()->make()->form_data,
            'consequences' => ['nfa', 'removal'], 'removal' => 'section_leader', 'special_punishments' => 'None.',
            'plan_trooper' => 'Post an LOA.', 'appeal' => 'do',
        ],
        'appeal_outcome' => AppealOutcome::NotAmended,
    ]);

    $response = $this->actingAs($this->pl)->get(route('counseling.document', $record->id))->assertOk();
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');

    $form = $response->json();
    expect($form['template'])->toBe('7CAV-R-043')
        ->and($form['fileName'])->toBe('LOR 2026-10-29 Brandt.L.pdf')
        ->and($form['text']['Date of Letter'])->toBe('29 Oct 2026')
        ->and($form['text']['Name LastFTrooper'])->toBe('Brandt.L')
        ->and($form['text']['RankDisciplining Authority'])->toBe('2LT')
        ->and($form['text']['Notes of discussion'])->toBe('Work shifts changed; went through the LOA process together.')
        ->and($form['text']['Counselling Authority2'])->toBe('Post an LOA.')
        ->and($form['text'][CounselingDocument::SPECIAL_PUNISHMENTS])->toBe('None.')
        ->and($form['checks'])->toBe(['Check Box1', 'Check Box2'])
        ->and($form['radios'])->toBe([
            ['group' => 'Group2', 'pick' => 'top'],
            ['group' => 'Group4', 'pick' => 'left'],
            ['group' => 'Group3', 'pick' => 'right'],
        ]);

    $entry = AuditLogEntry::query()->where('action', 'counseling.downloaded')->sole();
    expect($entry->actor_id)->toBe($this->pl->id)
        ->and((int) $entry->target_id)->toBe($record->id)
        ->and($entry->reason)->toBe('sha256:'.hash('sha256', $response->getContent()));
});

it('fills a DCS into R-042, with the counselling authority and no LOR fields', function () {
    $record = ($this->written)(['type' => 'dcs']);

    $form = $this->actingAs($this->pl)->get(route('counseling.document', $record->id))->assertOk()->json();

    expect($form['template'])->toBe('7CAV-R-042')
        ->and($form['text'])->toHaveKey('Name LastFCounselling Authority')
        ->and($form['text'])->not->toHaveKey('Name LastFDisciplining Authority')
        ->and($form['text'])->not->toHaveKey(CounselingDocument::SPECIAL_PUNISHMENTS)
        ->and($form['checks'])->toBe([])
        ->and($form['radios'])->toBe([]);
});

it('fills a draft\'s Section 1 live from milpac until it is shared', function () {
    $record = ($this->written)(['status' => 'draft', 'snapshot' => null, 'shared_at' => null]);

    $form = $this->actingAs($this->pl)->get(route('counseling.document', $record->id))->assertOk()->json();

    expect($form['text']['Name LastFTrooper'])->toBe('Brandt.L')
        ->and($form['text']['Name LastFDisciplining Authority'])->toBe('Mercer.J');
});

it('serves a record kept with its source PDF as that file, never a regenerated form, audited with the file\'s SHA-256', function () {
    $record = CounselingRecord::factory()->create([
        'trooper_id' => $this->rifleman->id, 'issued_by' => $this->pl->id, 'issuer_unit_id' => $this->tree['units']['platoon']->id,
        'counseled_on' => '2026-10-20',
    ]);
    Storage::disk('counseling')->put($record->document_path, counselingPdfBytes('original'));

    $response = $this->actingAs($this->pl)->get(route('counseling.document', $record->id))
        ->assertOk()
        ->assertDownload('DCS 2026-10-20 Brandt.L.pdf')
        ->assertHeader('Content-Type', 'application/pdf');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');

    expect(AuditLogEntry::query()->where('action', 'counseling.downloaded')->value('reason'))->toBe('sha256:'.hash('sha256', counselingPdfBytes('original')));
});

it('answers 404, and audits nothing, for a record the viewer does not see or a source PDF missing on disk', function () {
    $record = ($this->written)();
    $outsider = User::factory()->serving()->create();

    $this->actingAs($outsider)->get(route('counseling.document', $record->id))->assertNotFound();

    $missing = CounselingRecord::factory()->create(['trooper_id' => $this->rifleman->id, 'issued_by' => $this->pl->id]);
    $this->actingAs($this->pl)->get(route('counseling.document', $missing->id))->assertNotFound();

    expect(AuditLogEntry::query()->where('action', 'counseling.downloaded')->count())->toBe(0);
});
