<?php

namespace App\Commands\Counseling;

use App\Models\AppealOutcome;
use App\Models\CounselingRecord;
use App\Models\CounselingType;
use Carbon\CarbonImmutable;

/**
 * The regiment's official form for a record (#565), as data the viewer's browser fills in: which template
 * (`resources/counseling/7CAV-R-042.pdf` for a DCS, `7CAV-R-043.pdf` for an LOR), and the value of each of its
 * AcroForm fields by their names in that template. The browser only applies this (`resources/js/lib/counselingPdf.js`);
 * every decision about what goes where is made here, once, so the form a leader previews, the trooper downloads and S1
 * files are the same.
 *
 * The LOR's radio groups give both their buttons the same export value, so a choice is named by the button's place on
 * the page: `Group2` removal from Section Leader (top) or Assistant Section Leader (bottom), `Group4` the trooper DO
 * (left) or DO NOT (right) wish to appeal, `Group3` the authority HAVE AMENDED (left) or HAVE NOT AMENDED (right).
 *
 * @phpstan-type Payload array{template: string, fileName: string, version: int, text: array<string, string>, checks: list<string>, radios: list<array{group: string, pick: string}>}
 */
final class CounselingDocument
{
    public const DCS_TEMPLATE = '7CAV-R-042';

    public const LOR_TEMPLATE = '7CAV-R-043';

    /** The leader's and the trooper's text fields, by the name both templates give them. */
    private const TEXT = [
        'infraction' => 'Evidenced infraction and associated details  Previous instances and counsellings',
        'regulation' => 'Associated regulation  order  SOP',
        'discussion' => 'Notes of discussion',
        'plan_authority' => 'Counselling Authority',
        'plan_trooper' => 'Counselling Authority2',
        'plan_other' => 'Counselling Authority3',
        'comments_authority' => 'Counselling AuthorityAny comments',
        'comments_trooper' => 'TrooperAny comments',
        'comments_other' => 'Misc  OthersAny comments',
    ];

    /** The LOR's special punishments field: the template names it after the whole consequence box above it. */
    public const SPECIAL_PUNISHMENTS = 'Any Instance  30 Days NFA 2nd Instance in 90 days  Removal from Section Leader position if applicable Assistant Section Leader position if applicable Unit Commander escalation to Article 15 Commanders Investigation Any special punishment directed by a General Staff Officer to followSpecial punishments ordered';

    /** The LOR's consequence checkboxes, in the form's order. */
    private const CHECKS = [
        'nfa' => 'Check Box1',
        'removal' => 'Check Box2',
        'article_15' => 'Check Box3',
        'special_punishment' => 'Check Box4',
    ];

    /**
     * The form's fields for `$record`, Section 1 included ({@see CounselingFormParties::forRecord()}).
     *
     * @param  array{date: ?string, trooper: array{rank: string, name: string, billet: string}, authority: array{rank: string, name: string, billet: string}}  $section1
     * @return Payload
     */
    public static function for(CounselingRecord $record, array $section1): array
    {
        $lor = $record->type === CounselingType::Lor;
        $authority = $lor ? 'Disciplining Authority' : 'Counselling Authority';
        $form = $record->form_data ?? [];

        $text = [
            'Date of Letter' => $section1['date'] === null ? '' : CarbonImmutable::parse($section1['date'])->format('d M Y'),
            'RankTrooper' => $section1['trooper']['rank'],
            'Name LastFTrooper' => $section1['trooper']['name'],
            'BilletTrooper' => $section1['trooper']['billet'],
            "Rank{$authority}" => $section1['authority']['rank'],
            "Name LastF{$authority}" => $section1['authority']['name'],
            "Billet{$authority}" => $section1['authority']['billet'],
        ];

        foreach (self::TEXT as $key => $field) {
            $text[$field] = self::string($form[$key] ?? '');
        }

        $checks = [];
        $radios = [];

        if ($lor) {
            $text[self::SPECIAL_PUNISHMENTS] = self::string($form['special_punishments'] ?? '');
            $consequences = is_array($form['consequences'] ?? null) ? $form['consequences'] : [];

            foreach (self::CHECKS as $consequence => $field) {
                if (in_array($consequence, $consequences, true)) {
                    $checks[] = $field;
                }
            }

            $radios = array_values(array_filter([
                match ($form['removal'] ?? null) {
                    'section_leader' => ['group' => 'Group2', 'pick' => 'top'],
                    'assistant_section_leader' => ['group' => 'Group2', 'pick' => 'bottom'],
                    default => null,
                },
                match ($form['appeal'] ?? null) {
                    'do' => ['group' => 'Group4', 'pick' => 'left'],
                    'do_not' => ['group' => 'Group4', 'pick' => 'right'],
                    default => null,
                },
                match ($record->appeal_outcome) {
                    AppealOutcome::Amended => ['group' => 'Group3', 'pick' => 'left'],
                    AppealOutcome::NotAmended => ['group' => 'Group3', 'pick' => 'right'],
                    default => null,
                },
            ]));
        }

        return [
            'template' => $lor ? self::LOR_TEMPLATE : self::DCS_TEMPLATE,
            'fileName' => $record->fileName($record->trooper),
            'version' => (int) $record->version,
            'text' => $text,
            'checks' => $checks,
            'radios' => $radios,
        ];
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
