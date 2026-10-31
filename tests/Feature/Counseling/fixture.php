<?php

use App\Commands\Auth\ForumPmGrant;
use App\Integration\Forum\Requests\StartConversationRequest;
use App\Models\PortalRoleGrant;
use App\Models\Trooper;
use App\Models\UnitType;
use App\Models\User;
use App\Repositories\UnitRepository;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

/**
 * The counseling tests' shared tree (#381): 7CAV ─ 1-7 ─ A/1-7 ─ 1/A/1-7 ─┬─ 1/1/A/1-7 (SL `sl`, rifleman Brandt.L)
 *                                                                        └─ 2/1/A/1-7 (SL `otherSl`)
 * with a Platoon Leader `pl`, a company commander `co`, a staff grant on the company `grant` and an Admin.
 *
 * @return array{units: array<string, mixed>, users: array<string, User>, rifleman: Trooper}
 */
function counselingTree(): array
{
    $units = app(UnitRepository::class);
    $regiment = $units->create(UnitType::Regiment, '7th Cavalry Regiment', '7CAV', null);
    $battalion = $units->create(UnitType::Battalion, '1st Battalion', '1-7', $regiment->id);
    $company = $units->create(UnitType::Company, 'Alpha Company', 'A/1-7', $battalion->id);
    $platoon = $units->create(UnitType::Platoon, '1st Platoon', '1/A/1-7', $company->id);
    $section = $units->create(UnitType::Section, '1st Section', '1/1/A/1-7', $platoon->id);
    $other = $units->create(UnitType::Section, '2nd Section', '2/1/A/1-7', $platoon->id);

    $users = [
        'admin' => User::factory()->admin()->create(['username' => 'Hale.S']),
        'sl' => User::factory()->commanding($section, ['primary_section_id' => $section->id, 'username' => 'Okafor.B'])->create(['username' => 'Okafor.B']),
        'otherSl' => User::factory()->commanding($other, ['primary_section_id' => $other->id])->create(),
        'pl' => User::factory()->commanding($platoon, ['primary_section_id' => $platoon->id, 'username' => 'Mercer.J'])->create(['username' => 'Mercer.J']),
        'co' => User::factory()->commanding($company, ['primary_section_id' => $company->id, 'username' => 'Halvorsen.R'])->create(['username' => 'Halvorsen.R']),
        'grant' => User::factory()->serving()->create(),
    ];
    PortalRoleGrant::factory()->create(['user_id' => $users['grant']->id, 'unit_id' => $company->id]);

    return [
        'units' => compact('regiment', 'battalion', 'company', 'platoon', 'section', 'other'),
        'users' => $users,
        'rifleman' => Trooper::factory()->create(['primary_section_id' => $section->id, 'username' => 'Brandt.L']),
    ];
}

/** A small but real PDF: its bytes start with the PDF signature, so the content sniffer agrees. */
function counselingPdfBytes(string $marker = 'signed-dcs-body'): string
{
    return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n% {$marker}\ntrailer << /Root 1 0 R >>\n%%EOF\n";
}

function counselingPdf(string $name = 'Brandt DCS.pdf', string $marker = 'signed-dcs-body'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, counselingPdfBytes($marker));
}

/**
 * A session holding a live PM grant (#565), as the forum consent leaves it: what a request needs to send a PM.
 *
 * @return array<string, string>
 */
function counselingPmSession(): array
{
    return ['forum_pm_grant' => encrypt((new ForumPmGrant('pm-token', CarbonImmutable::now()->addHour()))->toArray())];
}

/**
 * Fake the forum: every PM the portal starts is answered with `$response` (a new conversation by default).
 */
function counselingFakeForum(?MockResponse $response = null): MockClient
{
    // `global()` keeps a client it already made, so a test that changes the forum's answer starts a fresh one.
    MockClient::destroyGlobal();

    return MockClient::global([
        StartConversationRequest::class => $response ?? MockResponse::make(['conversation' => ['conversation_id' => 77]]),
    ]);
}

/**
 * The PMs the fake forum was asked to start, in order: title, recipients and message.
 *
 * @return list<array{title: string, recipients: list<int>, message: string, token: ?string}>
 */
function counselingSentPms(MockClient $forum): array
{
    return collect($forum->getRecordedResponses())
        ->map(fn ($response) => $response->getPendingRequest())
        ->filter(fn (PendingRequest $pending) => $pending->getRequest() instanceof StartConversationRequest)
        ->map(fn (PendingRequest $pending) => [
            'title' => $pending->body()->all()['title'],
            'recipients' => array_map('intval', $pending->body()->all()['recipient_ids']),
            'message' => $pending->body()->all()['message'],
            'token' => $pending->headers()->get('Authorization'),
        ])
        ->values()
        ->all();
}

/**
 * A draft's full save payload: a DCS ready to share, or with `$overrides`.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function counselingDraftPayload(array $overrides = [], array $form = []): array
{
    return [
        'type' => 'dcs',
        'kind' => 'conduct',
        'counseled_on' => CarbonImmutable::now('UTC')->subDay()->toDateString(),
        'meeting_confirmed' => true,
        'form' => [
            'infraction' => 'Missed section practice on 10, 17 and 24 Oct without an LOA.',
            'regulation' => 'Platoon SOP, attendance.',
            'discussion' => 'Shifts changed; we went through the LOA process together.',
            'plan_authority' => 'Check in weekly before practice for four weeks.',
            'plan_other' => '',
            'comments_authority' => '',
            'comments_other' => '',
            ...$form,
        ],
        ...$overrides,
    ];
}
