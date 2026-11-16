<?php

use App\Models\RollcallSignature;
use App\Rollcall\ReplyAnswer;

// The keywords a roll call reply is read by. Pure: no app or DB is booted.

it('reads the answer a reply gives', function (string $reply, ?RollcallSignature $answer) {
    expect(ReplyAnswer::read($reply))->toBe($answer);
})->with([
    'will attend' => ['Will attend', RollcallSignature::WillAttend],
    'attending' => ['Attending, see you Saturday', RollcallSignature::WillAttend],
    'present' => ['present', RollcallSignature::WillAttend],
    'upper case' => ['I WILL ATTEND', RollcallSignature::WillAttend],
    'inside a sentence' => ['Yes sir, I will attend this week.', RollcallSignature::WillAttend],
    'in bold' => ['[B]Will attend[/B]', RollcallSignature::WillAttend],
    'cannot attend' => ['Cannot attend, work', RollcallSignature::CannotAttend],
    "can't attend" => ["Can't attend", RollcallSignature::CannotAttend],
    'curly apostrophe' => ["Can\u{2019}t attend this week", RollcallSignature::CannotAttend],
    'unable' => ['Unable to make it', RollcallSignature::CannotAttend],
    'makeup' => ['Requesting a makeup', RollcallSignature::MakeupRequested],
    'make-up' => ['Make-up please', RollcallSignature::MakeupRequested],
    'nothing recognised' => ['See you there', null],
    'empty' => ['', null],
]);

it('checks the cannot-attend phrases before the attend ones', function () {
    expect(ReplyAnswer::read('Cannot attend'))->toBe(RollcallSignature::CannotAttend)
        ->and(ReplyAnswer::read("I can't attend, I was attending a wedding"))->toBe(RollcallSignature::CannotAttend)
        ->and(ReplyAnswer::read('Unable to be present'))->toBe(RollcallSignature::CannotAttend);
});

it('reads a make-up request over a cannot attend in the same reply', function () {
    expect(ReplyAnswer::read("Can't attend, I'll do a make-up"))->toBe(RollcallSignature::MakeupRequested);
});

it('does not read a negated keyword as its opposite', function () {
    expect(ReplyAnswer::read('Not attending'))->toBeNull()
        ->and(ReplyAnswer::read("Won't be present"))->toBeNull()
        ->and(ReplyAnswer::read('No make-up needed, attending'))->toBe(RollcallSignature::WillAttend)
        ->and(ReplyAnswer::read('No problem, attending'))->toBe(RollcallSignature::WillAttend);
});

it('leaves quoted text out, so the roll call post a reply quotes is not read', function () {
    $quote = "[QUOTE=\"SGT Vasquez.R, post: 900, member: 12\"]Reply with Will attend, Cannot attend or Make-up[/QUOTE]\n";

    expect(ReplyAnswer::read($quote.'Will attend'))->toBe(RollcallSignature::WillAttend)
        ->and(ReplyAnswer::read($quote.'Thanks'))->toBeNull()
        ->and(ReplyAnswer::read('[QUOTE][QUOTE]Cannot attend[/QUOTE]Make-up[/QUOTE] present'))->toBe(RollcallSignature::WillAttend);
});

it('matches whole words only', function () {
    expect(ReplyAnswer::read('Presently busy'))->toBeNull()
        ->and(ReplyAnswer::read('Representing the section'))->toBeNull();
});
