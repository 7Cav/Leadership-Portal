<?php

use App\Authorization\ViewAs;
use App\Authorization\ViewAsKind;
use App\Authorization\ViewAsTarget;
use App\Commands\Counseling\CounselingForm;
use App\Commands\Counseling\CounselingFormRows;
use App\Commands\Counseling\FileCounselingRecordHandler;
use App\Integration\GoogleDrive\GoogleDriveApi;
use App\Jobs\UploadCounselingDocumentJob;
use App\Models\AuditLogEntry;
use App\Models\CounselingRecord;
use App\Models\CounselingType;
use App\Models\DriveUploadStatus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/fixture.php';

// A leader fills in the regiment's DCS or LOR form in the portal instead of uploading a signed PDF. The portal renders
// the PDF from it and files it exactly as an upload: the PDF check, the private copy, the Drive copy and the audit entry.

beforeEach(function () {
    Storage::fake('counseling');
    $this->tree = counselingTree();
    $this->rifleman = $this->tree['rifleman'];
    $this->form = fn (array $form = [], array $overrides = []) => [
        'source' => 'form',
        'type' => 'dcs',
        'kind' => 'conduct',
        'counseled_on' => now()->subDays(2)->toDateString(),
        'reason' => 'Disrespect toward an NCO.',
        'form' => [
            'infraction' => 'Swore at the SL during practice on 12Oct26.',
            'regulation' => '7CAV-R-042',
            'discussion' => 'Talked it through after practice.',
            'plan_authority' => 'Check in after next practice.',
            'plan_trooper' => 'Apologise to the SL.',
            ...$form,
        ],
        ...$overrides,
    ];
    $this->lor = fn (array $form = [], array $overrides = []) => ($this->form)([
        'consequences' => ['nfa', 'removal'],
        'removal' => 'section_leader',
        'special_punishments' => 'None.',
        'appeal' => 'do_not',
        ...$form,
    ], ['type' => 'lor', ...$overrides]);
    $this->previewHtml = fn (string $who, array $payload) => $this->actingAs($this->tree['users'][$who])
        ->postJson(route('counseling.preview', $this->rifleman->id), $payload)
        ->assertOk()
        ->json('html');
});

function nothingGenerated(): void
{
    expect(CounselingRecord::query()->count())->toBe(0)
        ->and(Storage::disk('counseling')->allFiles())->toBe([])
        ->and(AuditLogEntry::query()->where('action', 'counseling.filed')->count())->toBe(0);
}

it('files a DCS from the form: a real PDF stored privately, the form kept, and an audit entry that says it was generated', function () {
    Queue::fake();

    $this->actingAs($this->tree['users']['sl'])
        ->post(route('counseling.store', $this->rifleman->id), ($this->form)())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('troopers.show', $this->rifleman->id));

    $record = CounselingRecord::query()->sole();
    $bytes = Storage::disk('counseling')->get($record->document_path);

    expect($record->type)->toBe(CounselingType::Dcs)
        ->and($record->reason)->toBe('Disrespect toward an NCO.')
        ->and($record->document_name)->toBe('DCS '.now()->subDays(2)->toDateString().' Brandt.L.pdf')
        ->and($record->document_path)->toMatch('/^[0-9a-f-]{36}\.pdf$/')
        ->and(str_starts_with($bytes, '%PDF-'))->toBeTrue()
        ->and($record->document_size)->toBe(strlen($bytes))
        ->and($record->document_sha256)->toBe(hash('sha256', $bytes))
        ->and($record->drive_status)->toBe(DriveUploadStatus::Skipped)
        ->and($record->form_data['infraction'])->toBe('Swore at the SL during practice on 12Oct26.')
        ->and($record->form_data['plan_trooper'])->toBe('Apologise to the SL.')
        // A DCS carries the form's fixed text in place of the LOR fields.
        ->and($record->form_data['consequences'])->toBe([])
        ->and($record->form_data['appeal'])->toBeNull();

    $entry = AuditLogEntry::query()->where('action', 'counseling.filed')->sole();
    expect($entry->reason)->toBe(FileCounselingRecordHandler::GENERATED_NOTE)
        ->and($entry->target_id)->toBe((string) $record->id);
});

it('renders the trooper, the issuer and the infraction on the official DCS form', function () {
    $html = ($this->previewHtml)('sl', ($this->form)());

    expect($html)->toContain('7CAV-R-042 – Developmental Counselling Statement')
        ->toContain('Counselling Authority')
        ->toContain('Brandt.L')
        ->toContain('Okafor.B')
        ->toContain('Swore at the SL during practice on 12Oct26.')
        ->toContain('Not applicable for Counselling Statements.')
        ->toContain('RECORDS must be kept for 90 days only in the 7<sup>th</sup> Cavalry Google Drive and/or Leadership Portal. Send up Chain of Command when complete.')
        ->toContain('PROCEDURAL REFERENCE – For use only in conjunction with 7CAV-R-042.')
        ->not->toContain('Disciplining Authority')
        // Nothing is loaded from anywhere.
        ->not->toMatch('/(src|href)=["\']?https?:/i');
});

it('files an LOR from the form with its consequence and appeal choice, rendered on the LOR form', function () {
    Queue::fake();

    $this->actingAs($this->tree['users']['pl'])
        ->post(route('counseling.store', $this->rifleman->id), ($this->lor)())
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $record = CounselingRecord::query()->sole();
    expect($record->type)->toBe(CounselingType::Lor)
        ->and($record->form_data['consequences'])->toBe(['nfa', 'removal'])
        ->and($record->form_data['removal'])->toBe('section_leader')
        ->and($record->form_data['appeal'])->toBe('do_not')
        ->and(str_starts_with(Storage::disk('counseling')->get($record->document_path), '%PDF-'))->toBeTrue();

    $html = ($this->previewHtml)('pl', ($this->lor)());
    expect($html)->toContain('7CAV-R-043 – Letter of Reprimand')
        ->toContain('Disciplining Authority')
        ->toContain('Mercer.J')
        ->toContain('Any Instance – 30 Days NFA')
        ->toContain('RECORDS must be kept for permanently in MILPACS (Send to S1 once complete)')
        ->toContain('HAVE NOT AMENDED')
        ->not->toContain('Not applicable for Counselling Statements.');
});

it('marks the chosen consequence, removal and appeal, and leaves the appeal outcome blank', function () {
    $html = ($this->previewHtml)('pl', ($this->lor)(['consequences' => ['article_15'], 'removal' => null, 'appeal' => 'do']));

    // ☒ checked, ☐ not; ◉ chosen, ○ not.
    expect($html)->toContain('&#9746;</span> Unit Commander escalation')
        ->toContain('&#9744;</span> Any Instance')
        ->toContain('<span class="b">DO</span> <span class="sym">&#9673;</span>')
        ->toContain('<span class="b">DO NOT</span> <span class="sym">&#9675;</span>')
        ->toContain('<span class="b">HAVE AMENDED</span> <span class="sym">&#9675;</span>');
});

it('asks for the required fields of either type', function (string $field, string $message) {
    $payload = ($this->form)();
    unset($payload['form'][$field]);

    $this->actingAs($this->tree['users']['sl'])
        ->post(route('counseling.store', $this->rifleman->id), $payload)
        ->assertSessionHasErrors(["form.{$field}" => $message]);

    nothingGenerated();
})->with([
    'infraction' => ['infraction', 'Give the infraction and its details.'],
    'regulation' => ['regulation', 'Give the regulation, order or SOP.'],
    'discussion' => ['discussion', 'Give the notes of the discussion.'],
    'authority plan' => ['plan_authority', 'Give the plan of action for the authority.'],
]);

it('takes the other plan and comment columns as optional', function () {
    Queue::fake();
    $payload = ($this->form)();
    unset($payload['form']['plan_trooper']);

    $this->actingAs($this->tree['users']['sl'])
        ->post(route('counseling.store', $this->rifleman->id), $payload)
        ->assertSessionHasNoErrors();

    expect(CounselingRecord::query()->sole()->form_data['plan_trooper'])->toBe('');
});

it('asks an LOR for its consequence and the appeal choice', function (array $form, string $field) {
    $this->actingAs($this->tree['users']['pl'])
        ->post(route('counseling.store', $this->rifleman->id), ($this->lor)($form))
        ->assertSessionHasErrors("form.{$field}");

    nothingGenerated();
})->with([
    'no consequence' => [['consequences' => []], 'consequences'],
    'an unknown consequence' => [['consequences' => ['flogging']], 'consequences.0'],
    'a removal without its position' => [['consequences' => ['removal'], 'removal' => null], 'removal'],
    'an unknown position' => [['removal' => 'platoon_leader'], 'removal'],
    'no appeal choice' => [['appeal' => null], 'appeal'],
    'an unknown appeal choice' => [['appeal' => 'maybe'], 'appeal'],
]);

it('caps every field at its length', function (string $field, int $max) {
    $payload = ($this->lor)([$field => str_repeat('a', $max + 1)]);

    $this->actingAs($this->tree['users']['pl'])
        ->post(route('counseling.store', $this->rifleman->id), $payload)
        ->assertSessionHasErrors("form.{$field}");

    nothingGenerated();
})->with([
    'infraction' => ['infraction', CounselingForm::LONG_MAX],
    'regulation' => ['regulation', CounselingForm::SHORT_MAX],
    'discussion' => ['discussion', CounselingForm::LONG_MAX],
    'authority plan' => ['plan_authority', CounselingForm::PLAN_MAX],
    'trooper plan' => ['plan_trooper', CounselingForm::PLAN_MAX],
    'other plan' => ['plan_other', CounselingForm::PLAN_MAX],
    'authority comments' => ['comments_authority', CounselingForm::COMMENT_MAX],
    'trooper comments' => ['comments_trooper', CounselingForm::COMMENT_MAX],
    'other comments' => ['comments_other', CounselingForm::COMMENT_MAX],
    'special punishments' => ['special_punishments', CounselingForm::LONG_MAX],
]);

it('prints markup typed into a field as text, never as markup', function () {
    $html = ($this->previewHtml)('sl', ($this->form)([
        'infraction' => '<script>alert(1)</script> **bold** <img src=x onerror=alert(1)>',
        'plan_authority' => "Line one\nLine two",
    ]));

    expect($html)->toContain('&lt;script&gt;alert(1)&lt;/script&gt; **bold** &lt;img src=x onerror=alert(1)&gt;')
        ->not->toContain('<script>')
        ->not->toContain('<img')
        ->not->toContain('<strong>')
        // Line breaks are kept, as breaks.
        ->toContain('Line one<br>');

    Queue::fake();
    $this->actingAs($this->tree['users']['sl'])
        ->post(route('counseling.store', $this->rifleman->id), ($this->form)(['infraction' => '<script>alert(1)</script>']))
        ->assertSessionHasNoErrors();
    expect(str_starts_with(Storage::disk('counseling')->get(CounselingRecord::query()->sole()->document_path), '%PDF-'))->toBeTrue();
});

it('allows generating only what uploading allows: same refusals, nothing stored', function (string $who, string $type, int $status) {
    $payload = $type === 'lor' ? ($this->lor)() : ($this->form)();

    $this->actingAs($this->tree['users'][$who])->post(route('counseling.store', $this->rifleman->id), $payload)->assertStatus($status);
    $this->actingAs($this->tree['users'][$who])->postJson(route('counseling.preview', $this->rifleman->id), $payload)->assertStatus($status);

    nothingGenerated();
})->with([
    'an LOR from a Section Leader' => ['sl', 'lor', 403],
    'a staff grant holder' => ['grant', 'dcs', 403],
    'a Section Leader outside the trooper\'s section' => ['otherSl', 'dcs', 404],
]);

it('refuses generating and previewing during a view-as preview', function () {
    $admin = $this->tree['users']['admin'];
    app(ViewAs::class)->enter($admin, new ViewAsTarget(ViewAsKind::Unit, $this->tree['units']['section']->id));

    $this->actingAs($admin)->post(route('counseling.store', $this->rifleman->id), ($this->form)())->assertForbidden();
    $this->actingAs($admin)->postJson(route('counseling.preview', $this->rifleman->id), ($this->form)())->assertForbidden();

    nothingGenerated();
});

it('previews without storing or auditing anything', function () {
    ($this->previewHtml)('sl', ($this->form)());

    nothingGenerated();
});

it('queues the Drive copy of a generated PDF when Google Drive is configured', function () {
    Queue::fake();
    config(['services.google_drive' => ['service_account_email' => 'sa@example.iam.gserviceaccount.com', 'private_key' => 'not-used-here', 'folder_id' => 'folder-1']]);
    app()->forgetInstance(GoogleDriveApi::class);

    $this->actingAs($this->tree['users']['pl'])
        ->post(route('counseling.store', $this->rifleman->id), ($this->lor)())
        ->assertRedirect();

    $record = CounselingRecord::query()->sole();
    expect($record->drive_status)->toBe(DriveUploadStatus::Pending);
    Queue::assertPushed(UploadCounselingDocumentJob::class, fn (UploadCounselingDocumentJob $job) => $job->command->recordId === $record->id);
});

it('refuses the same form filed twice within 10 minutes, though the two PDFs differ', function () {
    Queue::fake();

    $this->actingAs($this->tree['users']['sl'])->post(route('counseling.store', $this->rifleman->id), ($this->form)())->assertSessionHasNoErrors();
    $this->actingAs($this->tree['users']['sl'])->post(route('counseling.store', $this->rifleman->id), ($this->form)())->assertSessionHasErrors('document');

    expect(CounselingRecord::query()->count())->toBe(1)
        ->and(Storage::disk('counseling')->allFiles())->toHaveCount(1);

    $this->actingAs($this->tree['users']['sl'])
        ->post(route('counseling.store', $this->rifleman->id), ($this->form)(['discussion' => 'A second talk.']))
        ->assertSessionHasNoErrors();
    expect(CounselingRecord::query()->count())->toBe(2);
});

it('still takes an upload when no source is given, as before', function () {
    Queue::fake();

    $this->actingAs($this->tree['users']['sl'])
        ->post(route('counseling.store', $this->rifleman->id), [
            'type' => 'dcs',
            'kind' => 'conduct',
            'counseled_on' => now()->subDays(1)->toDateString(),
            'reason' => 'Late to practice.',
            'document' => counselingPdf(),
        ])
        ->assertSessionHasNoErrors();

    $record = CounselingRecord::query()->sole();
    expect($record->form_data)->toBeNull()
        ->and(AuditLogEntry::query()->where('action', 'counseling.filed')->sole()->reason)->toBeNull();
});

it('gives a viewer who may file section 1 for the form, read from the roster', function () {
    $props = $this->actingAs($this->tree['users']['sl'])->get(route('troopers.show', $this->rifleman->id))->assertOk()->viewData('page')['props']['counseling'];

    expect($props['formParties']['trooper']['name'])->toBe('Brandt.L')
        ->and($props['formParties']['authority']['name'])->toBe('Okafor.B');

    $grant = $this->actingAs($this->tree['users']['grant'])->get(route('troopers.show', $this->rifleman->id))->assertOk()->viewData('page')['props']['counseling'];
    expect($grant['formParties'])->toBeNull();
});

it('prints a long plan as several rows under a repeating header, losing no text', function () {
    $plan = trim(str_repeat('Attend every section practice for the next four weeks and report to the SL afterwards. ', 15))
        ."\nAlso read the SOP. ".str_repeat('x', 60);
    $html = ($this->previewHtml)('sl', ($this->form)(['plan_authority' => $plan]));

    // The plan table carries its own header row, which dompdf repeats on every page it runs over.
    expect($html)->toMatch('/<thead>\s*<tr><td class="i">Agreed plan of action<\/td><td>Counselling Authority<\/td>/');

    $chunks = CounselingFormRows::rows([$plan, 'Apologise to the SL.', ''], CounselingFormRows::NARROW);
    expect(count($chunks))->toBeGreaterThan(3);
    foreach ($chunks as $i => $row) {
        $lines = substr_count($row[0], "\n") + 1;
        expect($lines)->toBeGreaterThanOrEqual(CounselingFormRows::MIN_LINES);
        expect($html)->toContain(nl2br(e($row[0]), false));
    }

    $squash = fn (string $text) => preg_replace('/\s+/', '', $text);
    expect($squash(implode('', array_column($chunks, 0))))->toBe($squash($plan))
        ->and($squash(implode('', array_column($chunks, 1))))->toBe('ApologisetotheSL.');
});

it('never leaves a last row of fewer than three lines', function () {
    $lines = implode("\n", array_map(fn ($n) => "line {$n}", range(1, CounselingFormRows::LINES + 1)));
    $rows = CounselingFormRows::rows([$lines], CounselingFormRows::WIDE);

    expect($rows)->toHaveCount(1)
        ->and(substr_count($rows[0][0], "\n") + 1)->toBe(CounselingFormRows::LINES + 1);
});
