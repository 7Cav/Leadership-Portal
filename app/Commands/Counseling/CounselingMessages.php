<?php

namespace App\Commands\Counseling;

use App\Models\AppealOutcome;
use App\Models\CounselingRecord;
use App\Models\CounselingType;
use Carbon\CarbonImmutable;

/**
 * The counseling PMs (#565): the wiki's own templates (7CAV-R-014, Developmental Counselling Statement, and the Letter
 * of Reprimand page, "PM Contact Templates") for the meeting invite and the After-Action, filled in from the record,
 * plus the portal's short notices for what the wiki leaves to the leader (the S1 copy, a withdrawal, an appeal and its
 * decision). Plain text with forum BBCode links. S1 should confirm the wording is still current.
 *
 * Every value put in comes from the record or the roster and is printed as given; a `[` in it cannot open a BBCode tag,
 * since {@see plain()} escapes it.
 *
 * @phpstan-type Pm array{title: string, message: string}
 */
final class CounselingMessages
{
    /**
     * The wiki's "Initial Contact" PM, with the three Zulu windows the leader is available in.
     *
     * @param  list<string>  $windows
     * @return Pm
     */
    public static function invite(CounselingRecord $record, string $trooperName, array $windows): array
    {
        $lor = $record->type === CounselingType::Lor;
        $body = $lor
            ? "This is in relation to an infraction which necessitates the use of a Letter of Reprimand. It is very important that we meet to discuss this so that we can understand the cause and come to a shared conclusion on how we can prevent the situation happening again.\n\n"
                ."I recognize this can in some cases come as a shock, and that you might be worried, but it is important that we take a breath and approach it as professionals, together. I'm keen to work with you to find a positive way forward, and here to help you through this process.\n\n"
                .'A Letter of Reprimand is a record of the infraction, associated evidence, and a plan between you and I in order to prevent recurrence.'
            : "It's also important that you know that this message is in no way punitive. I just want to talk to you. This does not go on your permanent record, it is simply a chat where we can jointly understand a specific issue that needs to be addressed.\n\n"
                .'It will be an open conversation between you and I as we come up with a solution to the proposed problem, verbally. It is then recorded on paper and held as a contract for future conduct.';

        $list = implode("\n", array_map(fn (int $i, string $window) => ($i + 1).'. '.self::plain($window), array_keys($windows), $windows));

        return [
            'title' => 'Meeting: '.self::wikiName($record->type),
            'message' => self::plain($trooperName).",\n\n"
                ."I am writing to you to arrange a meeting in order to discuss something that has been brought to my attention. It's important that we meet in the next 72 hours in order to discuss this.\n\n"
                ."{$body}\n\n"
                ."I'm available at these times during the next 72 hours:\n{$list}\n\n"
                .'What time can you meet?',
        ];
    }

    /**
     * The wiki's "After Action" PM, with the meeting's date and a link to the record.
     *
     * @return Pm
     */
    public static function afterAction(CounselingRecord $record, string $trooperName): array
    {
        $lor = $record->type === CounselingType::Lor;
        $link = self::link(route('counseling.show', $record->id), 'here');

        $middle = $lor
            ? "In the meeting I will have clarified the specific infraction observed, and the consequences associated with your Letter of Reprimand. You can find the Letter {$link}.\n\n"
                .'It is required of me by procedure to inform you of your Right to Appeal this Letter of Reprimand no greater than one step higher in the Chain of Command. If you wish to appeal, please let us know within 24 hours so we can escalate it to the appropriate personnel in the next immediate step of the Chain of Command. No subsequent appeals are permitted; the appeal hearing is final. Answer on the Letter in the portal.'
            : "In the meeting I will have clarified the specific infraction observed, and the resolution associated with your Developmental Counseling Statement. You can find the Statement {$link}. Add your part of the plan of action and your comments there.";

        return [
            'title' => 'After-Action: '.self::wikiName($record->type),
            'message' => self::plain($trooperName).",\n\n"
                .'I am writing to you to confirm the minutes of our meeting on '.self::wikiDate($record->counseled_on).".\n\n"
                ."{$middle}\n\n"
                ."We really appreciate you taking the time to understand our concerns and for being prepared to take action to help us achieve high performance in our team.\n\n"
                .'Please let me know if there is anything else we as a team can do to help you.',
        ];
    }

    /**
     * The LOR's copy to S1 and the oversight officer for record processing (wiki, Letter of Reprimand delivery, step 6).
     *
     * @return Pm
     */
    public static function s1Copy(CounselingRecord $record, string $trooperName, string $issuerName): array
    {
        return [
            'title' => 'Letter of Reprimand: '.self::plain($trooperName),
            'message' => 'A Letter of Reprimand on '.self::plain($trooperName).' by '.self::plain($issuerName)
                .', dated '.self::wikiDate($record->counseled_on).", was shared with the trooper for record processing.\n\n"
                .'Open the letter and its PDF (7CAV-R-043) '.self::link(route('counseling.show', $record->id), 'here').'.',
        ];
    }

    /** @return Pm */
    public static function withdrawn(CounselingRecord $record, string $trooperName, string $reason): array
    {
        return [
            'title' => 'Withdrawn: '.self::wikiName($record->type),
            'message' => self::plain($trooperName).",\n\n"
                .'The '.self::wikiName($record->type).' I shared with you has been withdrawn while it is corrected. You do not need to answer it.'
                ."\n\nReason: ".self::plain($reason),
        ];
    }

    /**
     * The trooper's appeal, to the appeal authority (wiki, Letter of Reprimand delivery, step 4B).
     *
     * @return Pm
     */
    public static function appealRequested(CounselingRecord $record, string $trooperName, string $issuerName, string $reason): array
    {
        return [
            'title' => 'Appeal: Letter of Reprimand',
            'message' => self::plain($trooperName).' appeals the Letter of Reprimand '.self::plain($issuerName)
                .' issued on '.self::wikiDate($record->counseled_on).". The appeal must be decided within 72 hours, and the decision is final.\n\n"
                .'Reason: '.self::plain($reason)."\n\n"
                .'Decide it '.self::link(route('counseling.appeals'), 'under Appeals in the portal').'.',
        ];
    }

    /** @return Pm */
    public static function appealDecided(CounselingRecord $record, string $trooperName, AppealOutcome $outcome, string $reason): array
    {
        $verdict = $outcome === AppealOutcome::Amended
            ? 'HAVE AMENDED the letter based on the merits of the appeal'
            : 'HAVE NOT AMENDED the letter based on the merits of the appeal';

        return [
            'title' => 'Appeal decided: Letter of Reprimand',
            'message' => 'On the appeal of '.self::plain($trooperName)."'s Letter of Reprimand dated ".self::wikiDate($record->counseled_on)
                .", I {$verdict}. My decision is final.\n\nReason: ".self::plain($reason)."\n\n"
                .'The letter is '.self::link(route('counseling.show', $record->id), 'here').'.',
        ];
    }

    /** As the wiki names the record in its PM subjects. */
    private static function wikiName(CounselingType $type): string
    {
        return $type === CounselingType::Lor ? 'Letter of Reprimand' : 'Developmental Counselling Statement';
    }

    /** `29OCT26`, the wiki's DDMMMYY. */
    private static function wikiDate(?CarbonImmutable $date): string
    {
        return $date === null ? 'the date given' : strtoupper($date->format('dMy'));
    }

    private static function link(string $url, string $text): string
    {
        return "[URL='{$url}']{$text}[/URL]";
    }

    /** Text as text: a `[` cannot start a BBCode tag. */
    private static function plain(string $text): string
    {
        return str_replace('[', '[plain][[/plain]', $text);
    }
}
