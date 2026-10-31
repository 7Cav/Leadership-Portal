<?php

use App\Commands\Counseling\CounselingDocument;
use App\Models\AppealOutcome;
use App\Models\CounselingRecord;
use App\Models\CounselingType;
use App\Models\Trooper;

// #565: the server decides what goes in each field of the official form (CounselingDocument), and the browser fills the
// real template with it (resources/js/lib/counselingPdf.js). These fixtures are the contract between the two: this test
// pins the server's answer for a fully filled DCS and LOR to them, and resources/js/lib/__tests__/counselingPdf.spec.js
// fills the same files into the real templates, so a field name changed on one side alone fails a test. To change the
// mapping on purpose, run this file with COUNSELING_FIXTURES=write and commit both.

function counselingDocumentFixture(CounselingType $type): array
{
    $trooper = Trooper::factory()->make(['username' => 'Brandt.L']);
    $record = CounselingRecord::factory()->written()->make([
        'id' => 7,
        'type' => $type,
        'version' => 3,
        'counseled_on' => '2026-10-29',
        'appeal_outcome' => $type === CounselingType::Lor ? AppealOutcome::Amended : null,
        'form_data' => [
            'infraction' => 'Missed section practice on 10, 17 and 24 Oct.',
            'regulation' => 'Platoon SOP, attendance.',
            'discussion' => 'Shifts changed.',
            'plan_authority' => 'Weekly check-in.',
            'plan_trooper' => 'Post an LOA.',
            'plan_other' => 'SL confirms attendance.',
            'comments_authority' => 'Good talk.',
            'comments_trooper' => 'Understood.',
            'comments_other' => 'None.',
            'consequences' => $type === CounselingType::Lor ? ['nfa', 'removal', 'article_15', 'special_punishment'] : [],
            'removal' => $type === CounselingType::Lor ? 'assistant_section_leader' : null,
            'special_punishments' => $type === CounselingType::Lor ? 'To follow.' : '',
            'appeal' => $type === CounselingType::Lor ? 'do_not' : null,
        ],
    ]);
    $record->setRelation('trooper', $trooper);

    return CounselingDocument::for($record, [
        'date' => '2026-10-29',
        'trooper' => ['rank' => 'PFC', 'name' => 'Brandt.L', 'billet' => 'Rifleman'],
        'authority' => ['rank' => '2LT', 'name' => 'Mercer.J', 'billet' => 'Platoon Leader'],
    ]);
}

it('answers exactly the fixture the browser test fills into the real template', function (CounselingType $type) {
    $path = base_path("tests/Fixtures/counseling/document-{$type->value}.json");
    $payload = counselingDocumentFixture($type);

    if (env('COUNSELING_FIXTURES') === 'write') {
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    expect($payload)->toBe(json_decode((string) file_get_contents($path), true));
})->with([CounselingType::Dcs, CounselingType::Lor]);
