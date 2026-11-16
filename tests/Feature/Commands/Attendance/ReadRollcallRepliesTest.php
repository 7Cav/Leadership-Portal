<?php

use App\Commands\Attendance\AttendanceMark;
use App\Commands\Attendance\MarkAttendance;
use App\Commands\Attendance\MarkAttendanceHandler;
use App\Commands\Attendance\ReadRollcallRepliesHandler;
use App\Integration\Forum\ForumApi;
use App\Models\Attendance;
use App\Models\AuditLogEntry;
use App\Models\RollcallReply;
use App\Models\RollcallSignature;
use App\Models\RollcallSource;
use App\Models\SectionPractice;
use App\Models\SyncRun;
use App\Models\SyncRunStatus;
use App\Models\Trooper;
use App\Models\Unit;
use App\Models\UnitType;
use App\Models\User;
use App\Models\WeeklyReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

// Signatures read from the roll call thread's replies: each posted week's thread, matched to the section's roll call by
// forum user, never over a signature a leader set by hand. The forum is faked; nothing reaches the network.

beforeEach(function () {
    // Thursday 08OCT26 16:20Z, inside the Cav week that opened Saturday 03OCT26.
    Carbon::setTestNow(CarbonImmutable::parse('2026-10-08 16:20:00', 'UTC'));
    $this->week = CarbonImmutable::parse('2026-10-03 00:00:00', 'UTC');

    config(['services.sevencav.api_key' => 'forum-key']);
    app()->instance(ForumApi::class, new ForumApi([
        'client_id' => 'id', 'client_secret' => 'secret', 'redirect_uri' => 'https://portal.test/cb',
        'base_url' => 'https://7cav.us', 'authorize_url' => 'https://7cav.us/oauth2/authorize', 'scopes' => ['user:read'],
        'api_key' => 'forum-key',
    ]));

    $this->section = Unit::factory()->create(['type' => UnitType::Section]);
    $this->leader = User::factory()->commanding($this->section)->create();
    $this->alpha = rrTrooper($this->section, 'Alpha.A');
    $this->bravo = rrTrooper($this->section, 'Bravo.B');
    $this->charlie = rrTrooper($this->section, 'Charlie.C');
    $this->outsider = rrTrooper(Unit::factory()->create(['type' => UnitType::Section]), 'Outsider.O');
});

function rrTrooper(Unit $section, string $username, array $attributes = []): Trooper
{
    $trooper = Trooper::factory()->create(['username' => $username, ...$attributes]);
    $trooper->forceFill(['primary_section_id' => $section->id])->save();

    return $trooper;
}

/** The section's practice for the week, marked posted in thread `$threadId`. */
function rrPosted(Unit $section, CarbonImmutable $week, int $threadId = 500, array $attributes = []): SectionPractice
{
    $practice = SectionPractice::factory()->create(['section_id' => $section->id, 'week_start' => $week]);
    $practice->forceFill([
        'rollcall_posted_at' => $week->addDays(1),
        'rollcall_thread_url' => "https://7cav.us/threads/roll-call.{$threadId}/",
        ...$attributes,
    ])->save();

    return $practice->refresh();
}

/** One post as the forum sends it. The opening post is the roll call itself. */
function rrPost(int $postId, ?Trooper $author, string $message, string $postedAt = '2026-10-04 18:00', array $extra = []): array
{
    return [
        'post_id' => $postId,
        'user_id' => $author?->forum_user_id ?? 0,
        'username' => $author?->username ?? 'Guest',
        'message' => $message,
        'post_date' => CarbonImmutable::parse($postedAt, 'UTC')->getTimestamp(),
        'message_state' => 'visible',
        'position' => $postId - 100,
        'is_first_post' => false,
        'view_url' => "https://7cav.us/posts/{$postId}/",
        ...$extra,
    ];
}

/** The roll call post that opens every thread, by the section's leader. */
function rrOpening(): array
{
    return rrPost(100, null, 'Roll call: reply with Will attend, Cannot attend or Make-up', '2026-10-04 12:00', ['user_id' => 999_999, 'username' => 'Leader.L', 'position' => 0, 'is_first_post' => true]);
}

/**
 * Fake the forum: `$threads` is thread id => pages of posts (each page a list), or a MockResponse to answer with.
 */
function rrForum(array $threads): MockClient
{
    MockClient::destroyGlobal();
    $map = [];

    foreach ($threads as $id => $pages) {
        $map["7cav.us/api/threads/{$id}/posts*"] = $pages instanceof MockResponse
            ? $pages
            : function (PendingRequest $request) use ($pages) {
                $page = (int) ($request->query()->get('page') ?? 1);

                return MockResponse::make([
                    'posts' => $pages[$page - 1] ?? [],
                    'pagination' => ['current_page' => $page, 'last_page' => count($pages)],
                ]);
            };
    }

    return MockClient::global($map);
}

function rrRun(): void
{
    app(ReadRollcallRepliesHandler::class)->handle();
}

function rrSignature(SectionPractice $practice, Trooper $trooper): ?Attendance
{
    return Attendance::query()->where(['section_practice_id' => $practice->id, 'trooper_id' => $trooper->id])->first();
}

function rrLatestRun(): SyncRun
{
    return SyncRun::query()->where('source', SyncRun::SOURCE_ROLLCALL_REPLIES)->orderByDesc('id')->firstOrFail();
}

it('signs each replying trooper from the keywords in their reply', function () {
    $practice = rrPosted($this->section, $this->week);
    rrForum([500 => [[
        rrOpening(),
        rrPost(101, $this->alpha, 'Will attend'),
        rrPost(102, $this->bravo, "Can\u{2019}t attend, work"),
        rrPost(103, $this->charlie, 'Requesting a make-up please'),
    ]]]);

    rrRun();

    $alpha = rrSignature($practice, $this->alpha);
    $reply = RollcallReply::query()->where('trooper_id', $this->alpha->id)->sole();

    expect($alpha->rollcall)->toBe(RollcallSignature::WillAttend)
        ->and($alpha->rollcall_source)->toBe(RollcallSource::Reply)
        ->and($alpha->status)->toBeNull()
        ->and(rrSignature($practice, $this->bravo)->rollcall)->toBe(RollcallSignature::CannotAttend)
        ->and(rrSignature($practice, $this->charlie)->rollcall)->toBe(RollcallSignature::MakeupRequested)
        ->and(RollcallReply::query()->count())->toBe(3)
        ->and($reply->forum_post_id)->toBe(101)
        ->and($reply->post_url)->toBe('https://7cav.us/posts/101/')
        ->and($reply->answer)->toBe(RollcallSignature::WillAttend);

    $run = rrLatestRun();
    expect($run->status)->toBe(SyncRunStatus::Success)
        ->and($run->inserted)->toBe(3)
        ->and($run->triggered_by)->toBe('system')
        // A system run is covered by its sync_runs row; there is no actor to audit.
        ->and(AuditLogEntry::query()->count())->toBe(0);
});

it('reads the thread linked when the roll call was marked posted, and no other', function () {
    $practice = rrPosted($this->section, $this->week, 777);
    $client = rrForum([777 => [[rrOpening(), rrPost(101, $this->alpha, 'present')]]]);

    rrRun();

    expect(rrSignature($practice, $this->alpha)->rollcall)->toBe(RollcallSignature::WillAttend);
    $client->assertSentCount(1);
});

it('does not read the opening post, which is the roll call itself', function () {
    $practice = rrPosted($this->section, $this->week);
    rrForum([500 => [[rrPost(100, $this->alpha, 'Reply with Will attend', '2026-10-04 12:00', ['position' => 0, 'is_first_post' => true])]]]);

    rrRun();

    expect(rrSignature($practice, $this->alpha))->toBeNull()
        ->and(RollcallReply::query()->count())->toBe(0);
});

it('ignores replies from anyone not on the section\'s roll call, a guest, and deleted replies', function () {
    $practice = rrPosted($this->section, $this->week);
    rrForum([500 => [[
        rrOpening(),
        rrPost(101, $this->outsider, 'Will attend'),
        rrPost(102, null, 'Will attend'),
        rrPost(103, $this->alpha, 'Will attend', '2026-10-04 18:00', ['message_state' => 'deleted']),
    ]]]);

    rrRun();

    expect(Attendance::query()->count())->toBe(0)
        ->and(RollcallReply::query()->count())->toBe(0)
        ->and(rrSignature($practice, $this->outsider))->toBeNull();
});

it('counts a trooper\'s latest reply', function () {
    $practice = rrPosted($this->section, $this->week);
    rrForum([500 => [[
        rrOpening(),
        rrPost(101, $this->alpha, 'Will attend', '2026-10-04 18:00'),
        rrPost(102, $this->alpha, 'Sorry, cannot attend after all', '2026-10-06 09:00'),
    ]]]);

    rrRun();

    expect(rrSignature($practice, $this->alpha)->rollcall)->toBe(RollcallSignature::CannotAttend)
        ->and(RollcallReply::query()->sole()->forum_post_id)->toBe(102);
});

it('reads every page of a long thread', function () {
    $practice = rrPosted($this->section, $this->week);
    rrForum([500 => [
        [rrOpening(), rrPost(101, $this->alpha, 'Will attend', '2026-10-04 18:00')],
        [rrPost(102, $this->bravo, 'unable', '2026-10-05 18:00'), rrPost(103, $this->alpha, 'make-up', '2026-10-06 18:00')],
    ]]);

    rrRun();

    expect(rrSignature($practice, $this->alpha)->rollcall)->toBe(RollcallSignature::MakeupRequested)
        ->and(rrSignature($practice, $this->bravo)->rollcall)->toBe(RollcallSignature::CannotAttend);
});

it('matches a trooper with no forum id yet by their whole username, ignoring case', function () {
    $this->bravo->forceFill(['forum_user_id' => null])->save();
    $practice = rrPosted($this->section, $this->week);
    rrForum([500 => [[
        rrOpening(),
        rrPost(101, null, 'Will attend', '2026-10-04 18:00', ['user_id' => 424_242, 'username' => 'BRAVO.B']),
        rrPost(102, null, 'Will attend', '2026-10-04 18:00', ['user_id' => 434_343, 'username' => 'Bravo']),
    ]]]);

    rrRun();

    expect(rrSignature($practice, $this->bravo)->rollcall)->toBe(RollcallSignature::WillAttend)
        ->and(RollcallReply::query()->sole()->forum_post_id)->toBe(101);
});

it('never overwrites a signature a leader set by hand, and keeps the reply\'s answer beside it', function () {
    $practice = rrPosted($this->section, $this->week);
    app(MarkAttendanceHandler::class)->handle($this->leader, new MarkAttendance(
        $this->section->id,
        $this->week,
        new AttendanceMark($this->alpha->id, rollcall: RollcallSignature::CannotAttend),
        new AttendanceMark($this->bravo->id, rollcall: RollcallSignature::Unsigned),
    ));
    rrForum([500 => [[rrOpening(), rrPost(101, $this->alpha, 'Will attend'), rrPost(102, $this->bravo, 'Will attend')]]]);

    rrRun();
    rrRun();

    expect(rrSignature($practice, $this->alpha)->rollcall)->toBe(RollcallSignature::CannotAttend)
        ->and(rrSignature($practice, $this->alpha)->rollcall_source)->toBe(RollcallSource::Manual)
        // Set back to unsigned by hand is the leader's say too.
        ->and(rrSignature($practice, $this->bravo)->rollcall)->toBe(RollcallSignature::Unsigned)
        ->and(rrSignature($practice, $this->bravo)->rollcall_source)->toBe(RollcallSource::Manual)
        ->and(RollcallReply::query()->where('trooper_id', $this->alpha->id)->sole()->answer)->toBe(RollcallSignature::WillAttend)
        ->and(rrLatestRun()->inserted)->toBe(0);
});

it('keeps a signature recorded before sources were, as a leader\'s', function () {
    $practice = rrPosted($this->section, $this->week);
    Attendance::factory()->create(['section_practice_id' => $practice->id, 'trooper_id' => $this->alpha->id, 'rollcall' => RollcallSignature::Excused, 'status' => null, 'source' => null]);
    rrForum([500 => [[rrOpening(), rrPost(101, $this->alpha, 'Will attend')]]]);

    rrRun();

    expect(rrSignature($practice, $this->alpha)->rollcall)->toBe(RollcallSignature::Excused);
});

it('lets a leader overrule a signature a reply gave, and keeps it theirs from then on', function () {
    $practice = rrPosted($this->section, $this->week);
    rrForum([500 => [[rrOpening(), rrPost(101, $this->alpha, 'Will attend')]]]);
    rrRun();

    app(MarkAttendanceHandler::class)->handle($this->leader, new MarkAttendance(
        $this->section->id,
        $this->week,
        new AttendanceMark($this->alpha->id, rollcall: RollcallSignature::Excused),
    ));
    rrRun();

    expect(rrSignature($practice, $this->alpha)->rollcall)->toBe(RollcallSignature::Excused)
        ->and(rrSignature($practice, $this->alpha)->rollcall_source)->toBe(RollcallSource::Manual);
});

it('leaves a reply with no answer recognised unsigned, for the leader to read', function () {
    $practice = rrPosted($this->section, $this->week);
    rrForum([500 => [[rrOpening(), rrPost(101, $this->alpha, 'See you there o7')]]]);

    rrRun();

    expect(rrSignature($practice, $this->alpha))->toBeNull()
        ->and(RollcallReply::query()->sole()->answer)->toBeNull()
        ->and(RollcallReply::query()->sole()->post_url)->toBe('https://7cav.us/posts/101/')
        ->and(rrLatestRun()->needs_review)->toBe(1)
        ->and(rrLatestRun()->status)->toBe(SyncRunStatus::Success);
});

it('takes back a signature a reply gave once the latest reply says nothing it recognises, or the reply is gone', function () {
    $practice = rrPosted($this->section, $this->week);
    rrForum([500 => [[rrOpening(), rrPost(101, $this->alpha, 'Will attend'), rrPost(102, $this->bravo, 'Will attend')]]]);
    rrRun();

    rrForum([500 => [[rrOpening(), rrPost(101, $this->alpha, 'Will attend', '2026-10-04 18:00'), rrPost(103, $this->alpha, 'Actually not sure yet', '2026-10-05 18:00')]]]);
    rrRun();

    expect(rrSignature($practice, $this->alpha)->rollcall)->toBe(RollcallSignature::Unsigned)
        ->and(rrSignature($practice, $this->alpha)->rollcall_source)->toBeNull()
        ->and(rrSignature($practice, $this->bravo)->rollcall)->toBe(RollcallSignature::Unsigned)
        ->and(rrSignature($practice, $this->bravo)->rollcall_source)->toBeNull()
        ->and(RollcallReply::query()->pluck('trooper_id')->all())->toBe([$this->alpha->id])
        ->and(rrLatestRun()->updated)->toBe(2);
});

it('does not read a week whose report has been sent', function () {
    $practice = rrPosted($this->section, $this->week);
    WeeklyReport::factory()->sent()->create(['unit_id' => $this->section->id, 'week_start' => $this->week]);
    $client = rrForum([500 => [[rrOpening(), rrPost(101, $this->alpha, 'Will attend')]]]);

    rrRun();

    expect(rrSignature($practice, $this->alpha))->toBeNull();
    $client->assertNothingSent();
});

it('reads only posted weeks with a thread link, not demo ones, and none older than the weeks it keeps open', function () {
    SectionPractice::factory()->create(['section_id' => $this->section->id, 'week_start' => $this->week]);
    rrPosted(Unit::factory()->create(['type' => UnitType::Section]), $this->week, 501, ['rollcall_thread_url' => null]);
    rrPosted(Unit::factory()->create(['type' => UnitType::Section]), $this->week, 502, ['is_demo' => true]);
    rrPosted($this->section, $this->week->subWeeks(ReadRollcallRepliesHandler::WEEKS_BACK + 1), 503);
    $lastWeek = rrPosted($this->section, $this->week->subWeek(), 504);
    $client = rrForum([
        501 => [[rrOpening()]], 502 => [[rrOpening()]], 503 => [[rrOpening()]],
        504 => [[rrOpening(), rrPost(101, $this->alpha, 'Will attend')]],
    ]);

    rrRun();

    expect(rrSignature($lastWeek, $this->alpha)->rollcall)->toBe(RollcallSignature::WillAttend);
    $client->assertSentCount(1);
});

it('writes nothing for a week the forum cannot send, records the run partial, and still reads the others', function () {
    $failing = rrPosted($this->section, $this->week->subWeek(), 600);
    $working = rrPosted($this->section, $this->week, 500);
    rrForum([
        600 => MockResponse::make(['errors' => [['code' => 'do_not_have_permission']]], 403),
        500 => [[rrOpening(), rrPost(101, $this->alpha, 'Will attend')]],
    ]);

    rrRun();

    $run = rrLatestRun();
    expect(rrSignature($working, $this->alpha)->rollcall)->toBe(RollcallSignature::WillAttend)
        ->and(Attendance::query()->where('section_practice_id', $failing->id)->count())->toBe(0)
        ->and($run->status)->toBe(SyncRunStatus::Partial)
        ->and($run->errored)->toBe(1)
        ->and($run->error)->toContain("Section practice {$failing->id} (week of 26SEP26)");
});

it('records a link that is not a thread as a problem for that week', function () {
    rrPosted($this->section, $this->week, 500, ['rollcall_thread_url' => 'https://7cav.us/posts/901/']);
    $client = rrForum([]);

    rrRun();

    expect(rrLatestRun()->status)->toBe(SyncRunStatus::Partial)
        ->and(rrLatestRun()->error)->toContain('not a thread on the forum');
    $client->assertNothingSent();
});

it('is scheduled hourly at a quarter to the hour, only with a forum key', function () {
    $this->artisan('list');
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event) => str_contains((string) $event->command, 'portal:read-rollcall-replies'));

    expect($event)->not->toBeNull()
        ->and($event->getExpression())->toBe('45 * * * *')
        ->and($event->filtersPass($this->app))->toBeTrue();

    config(['services.sevencav.api_key' => null]);
    expect($event->filtersPass($this->app))->toBeFalse();
});

it('reads in the foreground with --now, and refuses without a forum key', function () {
    rrPosted($this->section, $this->week);
    rrForum([500 => [[rrOpening(), rrPost(101, $this->alpha, 'Will attend')]]]);

    $this->artisan('portal:read-rollcall-replies', ['--now' => true])
        ->expectsOutput('Read 1 roll call thread: 1 signature set, 0 taken back, 0 replies with no answer recognised.')
        ->assertSuccessful();

    config(['services.sevencav.api_key' => '']);
    $this->artisan('portal:read-rollcall-replies')->assertFailed();
});

it('shows the roll call page who set each signature and what the reply said', function () {
    rrPosted($this->section, $this->week);
    Trooper::query()->whereIn('id', [$this->bravo->id, $this->charlie->id])->update(['primary_section_id' => null]);
    app(MarkAttendanceHandler::class)->handle($this->leader, new MarkAttendance(
        $this->section->id,
        $this->week,
        new AttendanceMark($this->alpha->id, rollcall: RollcallSignature::CannotAttend),
    ));
    rrForum([500 => [[rrOpening(), rrPost(101, $this->alpha, 'Will attend')]]]);
    rrRun();

    $this->actingAs($this->leader)->get("/rollcall/{$this->section->id}?week=2026-10-03")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('troopers.0.id', $this->alpha->id)
            ->where('troopers.0.rollcall', 'cannot_attend')
            ->where('troopers.0.rollcallSource', 'manual')
            ->where('troopers.0.reply', ['answer' => 'will_attend', 'url' => 'https://7cav.us/posts/101/', 'postedAt' => '2026-10-04T18:00:00+00:00']));
});
