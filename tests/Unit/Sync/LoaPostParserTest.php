<?php

use App\Models\LoaParseStatus;
use App\Models\LoaType;
use App\Sync\Loa\LoaPostParser;
use App\Sync\Loa\LoaThreadKind;
use App\Sync\Loa\ParsedLoaThread;
use Carbon\CarbonImmutable;

// Pure, app-free unit tests (Pest binds Laravel/DB only to the Feature suite). The fixtures are real
// 7Cav thread bodies with the usernames changed.

function loaFixture(string $name): string
{
    return file_get_contents(__DIR__.'/../../Fixtures/forum/'.$name.'.bbcode');
}

function parseLoa(string $title, string $body, LoaType $section = LoaType::Loa): ParsedLoaThread
{
    return (new LoaPostParser)->parse($title, $body, $section, CarbonImmutable::parse('2026-10-08', 'UTC'));
}

const LOA_TITLE = '[LOA Request] - PVT.Smith.J | Oct 6, 2026 - Oct 20, 2026';

describe('a normal LOA', function () {
    it('reads the subject, the forum id and both dates, with the value on the next line', function () {
        $parsed = parseLoa(LOA_TITLE, loaFixture('loa-on-behalf'));

        expect($parsed->kind)->toBe(LoaThreadKind::LoaRequest)
            ->and($parsed->status())->toBe(LoaParseStatus::Ok)
            ->and($parsed->username)->toBe('Smith.J')
            ->and($parsed->forumUserId)->toBe(11111)
            ->and($parsed->startsAt->toDateString())->toBe('2026-10-06')
            ->and($parsed->endsAt->toDateString())->toBe('2026-10-20')
            ->and($parsed->rawStart)->toBe('Oct 6, 2026')
            ->and($parsed->rawEnd)->toBe('Oct 20, 2026')
            ->and($parsed->reasons)->toBe([]);
    });

    it('does not take the submitter for the subject', function () {
        $parsed = parseLoa(LOA_TITLE, loaFixture('loa-on-behalf'));

        expect($parsed->username)->not->toBe('Jones.B');
    });

    it('reads a self-filed LOA whose labels have trailing spaces and an empty submitter', function () {
        $parsed = parseLoa('[LOA Request] - MAJ.Hart.A | Oct 3, 2026 - Oct 17, 2026', loaFixture('loa-self'));

        expect($parsed->status())->toBe(LoaParseStatus::Ok)
            ->and($parsed->username)->toBe('Hart.A')
            ->and($parsed->forumUserId)->toBe(2222)
            ->and($parsed->startsAt->toDateString())->toBe('2026-10-03')
            ->and($parsed->endsAt->toDateString())->toBe('2026-10-17');
    });

    it('keeps the free-text reason out of the excerpt it retains', function () {
        $parsed = parseLoa(LOA_TITLE, loaFixture('loa-on-behalf'));

        expect($parsed->excerpt)->toContain('Username: Smith.J (forum user 11111)')
            ->and($parsed->excerpt)->toContain('Start Date: Oct 6, 2026')
            ->and($parsed->excerpt)->toContain('End Date: Oct 20, 2026')
            ->and($parsed->excerpt)->not->toContain('A reason the trooper typed in');
    });

    it('is agnostic to formatting: plain text, other colours, and a mention-free username', function () {
        $body = "Rank: PVT\nUsername: Smith.J\nStart Date: Oct 6, 2026\nEnd Date: Oct 20, 2026\n";

        $parsed = parseLoa(LOA_TITLE, $body);

        expect($parsed->status())->toBe(LoaParseStatus::Ok)
            ->and($parsed->username)->toBe('Smith.J')
            ->and($parsed->forumUserId)->toBeNull()
            ->and($parsed->startsAt->toDateString())->toBe('2026-10-06');
    });

    it('reads SIZE, FONT and other formatting wrappers and Windows line endings', function () {
        $body = "[SIZE=4][FONT=Arial][B]Username[/B]:[/FONT][/SIZE] [USER=5]Smith.J[/USER]\r\n[I]Start Date[/I]\r\nOct 6, 2026\r\n[U]End Date[/U]\r\nOct 20, 2026\r\n";

        $parsed = parseLoa(LOA_TITLE, $body);

        expect($parsed->status())->toBe(LoaParseStatus::Ok)->and($parsed->username)->toBe('Smith.J');
    });

    it('accepts every date layout the forum has', function (string $start, string $end) {
        $body = "Username: Smith.J\nStart Date\n{$start}\nEnd Date\n{$end}\n";

        $parsed = parseLoa('[LOA Request] - PVT.Smith.J', $body);

        expect($parsed->status())->toBe(LoaParseStatus::Ok)
            ->and($parsed->startsAt->toDateString())->toBe('2026-10-06')
            ->and($parsed->endsAt->toDateString())->toBe('2026-10-20');
    })->with([
        'abbreviated, comma' => ['Oct 6, 2026', 'Oct 20, 2026'],
        'full month, comma' => ['October 6, 2026', 'October 20, 2026'],
        'abbreviated, no comma' => ['Oct 6 2026', 'Oct 20 2026'],
        'full month, no comma' => ['October 6 2026', 'October 20 2026'],
        'lower case and extra spaces' => ['  oct   6,  2026 ', 'OCT 20, 2026'],
    ]);

    it('puts a same-line value after the colon to use too', function () {
        $parsed = parseLoa('[LOA Request] - PVT.Smith.J', "Username: Smith.J\nStart Date: Oct 6, 2026\nEnd Date: Oct 20, 2026");

        expect($parsed->status())->toBe(LoaParseStatus::Ok);
    });
});

describe('never guessing (needs_review)', function () {
    it('flags dates it cannot read, keeps the raw text, and asserts no dates', function (string $start) {
        $parsed = parseLoa('[LOA Request] - PVT.Smith.J', "Username: Smith.J\nStart Date\n{$start}\nEnd Date\nOct 20, 2026\n");

        expect($parsed->status())->toBe(LoaParseStatus::NeedsReview)
            ->and($parsed->startsAt)->toBeNull()
            ->and($parsed->endsAt)->toBeNull()
            ->and($parsed->rawStart)->toBe($start)
            ->and($parsed->reason())->toContain('Start Date');
    })->with(['next week', 'probably Oct 6', '6/10/2026', '2026-10-06', 'TBD', 'Oct 6']);

    it('does not roll an impossible day over', function () {
        $parsed = parseLoa('[LOA Request] - PVT.Smith.J', "Username: Smith.J\nStart Date\nFeb 30, 2026\nEnd Date\nMar 10, 2026\n");

        expect($parsed->status())->toBe(LoaParseStatus::NeedsReview)->and($parsed->startsAt)->toBeNull();
    });

    it('flags an end before the start', function () {
        $parsed = parseLoa('[LOA Request] - PVT.Smith.J', "Username: Smith.J\nStart Date\nOct 20, 2026\nEnd Date\nOct 6, 2026\n");

        expect($parsed->status())->toBe(LoaParseStatus::NeedsReview)->and($parsed->reason())->toContain('before the Start');
    });

    // 2026-10-03 is a Saturday. An LOA is at least one Cav week (Saturday to Friday) and at most 14 days.
    it('flags an LOA shorter than one Cav week, and asserts no dates', function (string $start, string $end) {
        $parsed = parseLoa('[LOA Request] - PVT.Smith.J', "Username: Smith.J\nStart Date\n{$start}\nEnd Date\n{$end}\n");

        expect($parsed->status())->toBe(LoaParseStatus::NeedsReview)
            ->and($parsed->reason())->toBe('The leave covers fewer than 7 days, shorter than one Cav week (Saturday to Friday).')
            ->and($parsed->startsAt)->toBeNull()
            ->and($parsed->endsAt)->toBeNull();
    })->with([
        'one day' => ['Oct 3, 2026', 'Oct 3, 2026'],
        'six days' => ['Oct 3, 2026', 'Oct 8, 2026'],
    ]);

    it('reads an LOA from one Cav week up to 14 days cleanly, whatever day it starts on', function (string $start, string $end) {
        $parsed = parseLoa('[LOA Request] - PVT.Smith.J', "Username: Smith.J\nStart Date\n{$start}\nEnd Date\n{$end}\n");

        expect($parsed->status())->toBe(LoaParseStatus::Ok)
            ->and($parsed->startsAt)->not->toBeNull()
            ->and($parsed->endsAt)->not->toBeNull();
    })->with([
        'one Cav week, Saturday to Friday' => ['Oct 3, 2026', 'Oct 9, 2026'],
        'seven days from a Tuesday' => ['Oct 6, 2026', 'Oct 12, 2026'],
        'fourteen days' => ['Oct 3, 2026', 'Oct 16, 2026'],
        'two weeks to the same weekday, as the forum posts them' => ['Oct 3, 2026', 'Oct 17, 2026'],
    ]);

    it('flags an LOA longer than 14 days, and asserts no dates', function (string $end) {
        $parsed = parseLoa('[LOA Request] - PVT.Smith.J', "Username: Smith.J\nStart Date\nOct 3, 2026\nEnd Date\n{$end}\n");

        expect($parsed->status())->toBe(LoaParseStatus::NeedsReview)
            ->and($parsed->reason())->toBe('The End Date is more than 14 days after the Start Date, longer than an LOA can be.')
            ->and($parsed->startsAt)->toBeNull()
            ->and($parsed->endsAt)->toBeNull();
    })->with(['Oct 18, 2026', 'Dec 3, 2026']);

    it('flags a date more than three years out, in either direction', function (string $date) {
        $parsed = parseLoa('[LOA Request] - PVT.Smith.J', "Username: Smith.J\nStart Date\n{$date}\nEnd Date\n{$date}\n");

        expect($parsed->status())->toBe(LoaParseStatus::NeedsReview)->and($parsed->reason())->toContain('three years');
    })->with(['Oct 6, 2036', 'Oct 6, 2016']);

    it('flags a missing date field, a missing username and a thread with neither', function () {
        expect(parseLoa('[LOA Request] - PVT.Smith.J', "Username: Smith.J\nStart Date\nOct 6, 2026\n")->reason())->toContain('End Date is missing')
            ->and(parseLoa('[LOA Request] - PVT.Smith.J', "Start Date\nOct 6, 2026\nEnd Date\nOct 7, 2026\n")->reason())->toContain('no Username')
            ->and(parseLoa('[LOA Request] - PVT.Smith.J', 'just some words')->status())->toBe(LoaParseStatus::NeedsReview);
    });

    it('flags an empty Username, and more than two of them', function () {
        $empty = parseLoa('[LOA Request] - PVT.Smith.J', "Username:\nStart Date\nOct 6, 2026\nEnd Date\nOct 7, 2026\n");
        $many = parseLoa('[LOA Request] - PVT.Smith.J', "Username: A.A\nUsername: B.B\nUsername: C.C\nStart Date\nOct 6, 2026\nEnd Date\nOct 7, 2026\n");

        expect($empty->status())->toBe(LoaParseStatus::NeedsReview)
            ->and($many->status())->toBe(LoaParseStatus::NeedsReview)
            ->and($many->username)->toBeNull()
            ->and($many->reason())->toContain('3 Username fields');
    });

    it('does not read the next label as the value of a blank field', function () {
        $blankStart = parseLoa('[LOA Request] - PVT.Smith.J', 'Username: Smith.J
Start Date

End Date
Oct 20, 2026
');
        $blankName = parseLoa('[LOA Request] - PVT.Smith.J', 'Username:
Start Date
Oct 6, 2026
End Date
Oct 20, 2026
');

        expect($blankStart->status())->toBe(LoaParseStatus::NeedsReview)
            ->and($blankStart->reason())->toContain('Start Date is missing')
            ->and($blankStart->rawStart)->toBeNull()
            ->and($blankName->username)->toBeNull()
            ->and($blankName->reason())->toContain('no Username');
    });

    it('flags dates in the title that differ from the dates in the post', function () {
        $parsed = parseLoa('[LOA Request] - PVT.Smith.J | Oct 6, 2026 - Oct 27, 2026', loaFixture('loa-on-behalf'));

        expect($parsed->status())->toBe(LoaParseStatus::NeedsReview)
            ->and($parsed->reason())->toContain('title differ')
            ->and($parsed->startsAt)->toBeNull();
    });

    it('does not mind a title with no dates at all', function () {
        expect(parseLoa('[LOA Request] - PVT.Smith.J', loaFixture('loa-on-behalf'))->status())->toBe(LoaParseStatus::Ok);
    });

    it('flags a title that names someone other than the post\'s Username', function () {
        $parsed = parseLoa('[LOA Request] - PVT.Evil.X | Oct 3, 2026 - Oct 17, 2026', loaFixture('loa-self'));

        expect($parsed->status())->toBe(LoaParseStatus::NeedsReview)
            ->and($parsed->reason())->toBe('The title names PVT.Evil.X but the post names Hart.A.')
            ->and($parsed->username)->toBe('Hart.A')
            ->and($parsed->startsAt)->toBeNull();
    });

    it('reads the title\'s name as the same person when only the rank, case or spacing differs', function (string $title) {
        expect(parseLoa($title, loaFixture('loa-on-behalf'))->status())->toBe(LoaParseStatus::Ok);
    })->with([
        'rank and name' => [LOA_TITLE],
        'another rank' => ['[LOA Request] - PFC.Smith.J | Oct 6, 2026 - Oct 20, 2026'],
        'no rank' => ['[LOA Request] - Smith.J | Oct 6, 2026 - Oct 20, 2026'],
        'other case' => ['[LOA Request] - pvt.SMITH.j | Oct 6, 2026 - Oct 20, 2026'],
        'spaces around the dots' => ['[LOA Request] -  PVT. Smith . J  | Oct 6, 2026 - Oct 20, 2026'],
        'a space after the rank' => ['[LOA Request] - PVT Smith.J | Oct 6, 2026 - Oct 20, 2026'],
    ]);

    it('reads an on-behalf post clean when the title names its subject, not its submitter', function () {
        $parsed = parseLoa(LOA_TITLE, loaFixture('loa-on-behalf'));
        $submitter = parseLoa('[LOA Request] - SGT.Jones.B | Oct 6, 2026 - Oct 20, 2026', loaFixture('loa-on-behalf'));

        expect($parsed->status())->toBe(LoaParseStatus::Ok)
            ->and($submitter->status())->toBe(LoaParseStatus::NeedsReview)
            ->and($submitter->reason())->toContain('The title names SGT.Jones.B but the post names Smith.J.');
    });

    it('does not compare names when the title has none it can read', function (string $title) {
        expect(parseLoa($title, loaFixture('loa-on-behalf'))->status())->toBe(LoaParseStatus::Ok);
    })->with([
        'tag only' => ['[LOA Request]'],
        'tag and dash' => ['[LOA Request] - '],
        'dates only' => ['[LOA Request] - | Oct 6, 2026 - Oct 20, 2026'],
        'free text' => ['[LOA Request] - going on holiday'],
    ]);

    it('compares the name in an ELOA report or return title as well', function () {
        $report = parseLoa('[ELOA Report] - PFC.Lane.M', loaFixture('eloa-report'), LoaType::Eloa);
        $wrongReport = parseLoa('[ELOA Report] - PFC.Reed.D', loaFixture('eloa-report'), LoaType::Eloa);
        $return = parseLoa('[ELOA Return] - 1SG.Fox.R', loaFixture('eloa-return'), LoaType::Eloa);
        $wrongReturn = parseLoa('[ELOA Return] - 1SG.Cole.CJ', loaFixture('eloa-return'), LoaType::Eloa);

        expect($report->status())->toBe(LoaParseStatus::Ok)
            ->and($wrongReport->status())->toBe(LoaParseStatus::NeedsReview)
            ->and($wrongReport->reason())->toBe('The title names PFC.Reed.D but the post names Lane.M.')
            ->and($return->status())->toBe(LoaParseStatus::Ok)
            ->and($wrongReturn->status())->toBe(LoaParseStatus::NeedsReview)
            ->and($wrongReturn->reason())->toBe('The title names 1SG.Cole.CJ but the post names Fox.R.');
    });

    it('flags a title tag it does not know, and one in the wrong section', function () {
        $unknown = parseLoa('[LOA Cancel] - PVT.Smith.J', loaFixture('loa-on-behalf'));
        $wrongSection = parseLoa(LOA_TITLE, loaFixture('loa-on-behalf'), LoaType::Eloa);

        expect($unknown->kind)->toBe(LoaThreadKind::Unknown)
            ->and($unknown->status())->toBe(LoaParseStatus::NeedsReview)
            ->and($unknown->reason())->toContain('does not start with')
            ->and($wrongSection->status())->toBe(LoaParseStatus::NeedsReview)
            ->and($wrongSection->reason())->toContain('in the ELOA section');
    });
});

describe('an ELOA report', function () {
    it('takes the LAST Username as the subject, since the submitter comes first', function () {
        $parsed = parseLoa('[ELOA Report] - PFC.Lane.M', loaFixture('eloa-report'), LoaType::Eloa);

        expect($parsed->kind)->toBe(LoaThreadKind::EloaReport)
            ->and($parsed->status())->toBe(LoaParseStatus::Ok)
            ->and($parsed->username)->toBe('Lane.M')
            ->and($parsed->forumUserId)->toBeNull();
    });

    it('has no start or end date of its own, and reads the expected return as an expectation only', function () {
        $parsed = parseLoa('[ELOA Report] - PFC.Lane.M', loaFixture('eloa-report'), LoaType::Eloa);

        expect($parsed->startsAt)->toBeNull()
            ->and($parsed->endsAt)->toBeNull()
            ->and($parsed->rawEnd)->toBe('Nov 13, 2026')
            ->and($parsed->expectedReturnAt?->toDateString())->toBe('2026-11-13');
    });

    it('does not send a thread to review because its expected return cannot be read', function () {
        $body = preg_replace('/Nov 13, 2026/', 'when the unit deploys', loaFixture('eloa-report'));
        $parsed = parseLoa('[ELOA Report] - PFC.Lane.M', $body, LoaType::Eloa);

        expect($parsed->status())->toBe(LoaParseStatus::Ok)
            ->and($parsed->expectedReturnAt)->toBeNull()
            ->and($parsed->rawEnd)->toBe('when the unit deploys');
    });

    it('drops an expected return that is years away, without sending the thread to review', function () {
        $body = preg_replace('/Nov 13, 2026/', 'Nov 13, 2062', loaFixture('eloa-report'));
        $parsed = parseLoa('[ELOA Report] - PFC.Lane.M', $body, LoaType::Eloa);

        expect($parsed->status())->toBe(LoaParseStatus::Ok)
            ->and($parsed->expectedReturnAt)->toBeNull();
    });

    it('is not held to the LOA length limits, however near or far its expected return', function (string $return) {
        $body = preg_replace('/Nov 13, 2026/', $return, loaFixture('eloa-report'));
        $parsed = parseLoa('[ELOA Report] - PFC.Lane.M', $body, LoaType::Eloa);

        expect($parsed->status())->toBe(LoaParseStatus::Ok)
            ->and($parsed->expectedReturnAt?->toDateString())->not->toBeNull();
    })->with(['Oct 9, 2026', 'Aug 4, 2027']);

    it('has no expected return for an LOA', function () {
        expect(parseLoa('[LOA Request] - SGT.Smith.J', loaFixture('loa-self'), LoaType::Loa)->expectedReturnAt)->toBeNull();
    });

    it('finds the PAF thread it links to, from a bare link', function () {
        expect(parseLoa('[ELOA Report] - PFC.Lane.M', loaFixture('eloa-report'), LoaType::Eloa)->pafThreadId)->toBe(106503);
    });

    it('is not an LOA: its title and section must agree', function () {
        $parsed = parseLoa('[ELOA Report] - PFC.Lane.M', loaFixture('eloa-report'), LoaType::Loa);

        expect($parsed->status())->toBe(LoaParseStatus::NeedsReview)->and($parsed->reason())->toContain('in the LOA section');
    });

    it('flags a body whose Type contradicts the title, or has none', function () {
        $contradicts = parseLoa('[ELOA Report] - PFC.Lane.M', loaFixture('eloa-return'), LoaType::Eloa);
        $none = parseLoa('[ELOA Report] - PFC.Lane.M', "Username: A.A\nUsername: Lane.M\nPAF LINK https://7cav.us/threads/1/\n", LoaType::Eloa);

        expect($contradicts->status())->toBe(LoaParseStatus::NeedsReview)->and($contradicts->reason())->toContain('Type is "Return"')
            ->and($none->status())->toBe(LoaParseStatus::NeedsReview)->and($none->reason())->toContain('no Type');
    });
});

describe('an ELOA return', function () {
    it('reads the subject and the PAF thread from a link wrapped in [URL]', function () {
        $parsed = parseLoa('[ELOA Return] - 1SG.Fox.R', loaFixture('eloa-return'), LoaType::Eloa);

        expect($parsed->kind)->toBe(LoaThreadKind::EloaReturn)
            ->and($parsed->status())->toBe(LoaParseStatus::Ok)
            ->and($parsed->username)->toBe('Fox.R')
            ->and($parsed->pafThreadId)->toBe(106812)
            ->and($parsed->startsAt)->toBeNull()
            ->and($parsed->endsAt)->toBeNull();
    });

    it('reads a PAF link with a title slug in it', function () {
        $body = "Username: A.A\nUsername: Fox.R\nType: Return\nPAF LINK https://7cav.us/threads/eloa-return-fox-r.106812/\n";

        expect(parseLoa('[ELOA Return] - 1SG.Fox.R', $body, LoaType::Eloa)->pafThreadId)->toBe(106812);
    });

    it('leaves the PAF thread unset when there is no link, or the link is not a thread', function () {
        expect(parseLoa('[ELOA Return] - 1SG.Fox.R', "Username: A.A\nUsername: Fox.R\nType: Return\n", LoaType::Eloa)->pafThreadId)->toBeNull()
            ->and(parseLoa('[ELOA Return] - 1SG.Fox.R', "Username: A.A\nUsername: Fox.R\nType: Return\nPAF LINK https://7cav.us/forums/365/\n", LoaType::Eloa)->pafThreadId)->toBeNull();
    });
});

describe('the title tag', function () {
    it('is read case-insensitively and with spacing', function (string $title, LoaThreadKind $kind) {
        expect(LoaThreadKind::fromTitle($title))->toBe($kind);
    })->with([
        ['[LOA Request] - PVT.A.B', LoaThreadKind::LoaRequest],
        ['[loa request]- PVT.A.B', LoaThreadKind::LoaRequest],
        ['  [ELOA Report] - PFC.A.B', LoaThreadKind::EloaReport],
        ['[ELOA  Return] - 1SG.A.B', LoaThreadKind::EloaReturn],
        ['[ELOA Request] - PFC.A.B', LoaThreadKind::Unknown],
        ['[LOA Report] - PFC.A.B', LoaThreadKind::Unknown],
        ['LOA Request - PVT.A.B', LoaThreadKind::Unknown],
        ['', LoaThreadKind::Unknown],
    ]);
});
