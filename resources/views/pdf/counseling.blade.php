{{--
    The regiment's counseling forms on A4 (one page, more when the answers are long): 7CAV-R-042 (Developmental Counselling Statement) and 7CAV-R-043
    (Letter of Reprimand). The titles, labels and fixed text are copied from the official forms, spelling included.
    Rendered to PDF by App\Commands\Counseling\CounselingFormPdf and shown as the preview, so both are the same page.

    Every value a leader typed is printed through {{ }}, the long answers as nl2br(e()) in an HtmlString: markup, scripts and Markdown in it stay
    text. Nothing is loaded from anywhere: no remote images, fonts or styles (dompdf runs with remote access off).

    Expects: $lor (bool), $date (string), $parties (trooper/authority: rank, name, billet), $form (CounselingForm::toArray()).
--}}
@php
    // Each helper returns an HtmlString built from e(), so it prints through {{ }} without being escaped twice.
    $text = fn (?string $value) => new \Illuminate\Support\HtmlString(nl2br(e((string) $value), false));
    $box = fn (bool $on) => new \Illuminate\Support\HtmlString($on ? '&#9746;' : '&#9744;');
    $radio = fn (bool $on) => new \Illuminate\Support\HtmlString($on ? '&#9673;' : '&#9675;');
    $picked = fn (string $c) => in_array($c, $form['consequences'] ?? [], true);
    // Long answers as several rows, so a page can break between them (dompdf cannot split one row).
    $rows = fn (array $columns, int $width) => \App\Commands\Counseling\CounselingFormRows::rows($columns, $width);
    $wide = \App\Commands\Counseling\CounselingFormRows::WIDE;
    $narrow = \App\Commands\Counseling\CounselingFormRows::NARROW;
    $authorityLabel = $lor ? 'Disciplining Authority' : 'Counselling Authority';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>{{ $lor ? '7CAV-R-043 – Letter of Reprimand' : '7CAV-R-042 – Developmental Counselling Statement' }}</title>
<style>
    @page { size: A4 portrait; margin: 22mm 18mm 18mm 18mm; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5pt; color: #000; margin: 0; background: #fff; }
    table { width: 100%; border-collapse: collapse; table-layout: fixed; }
    td, th { border: 0.6pt solid #000; padding: 1.5pt 3pt; vertical-align: top; text-align: left; font-weight: normal; word-wrap: break-word; overflow-wrap: break-word; }
    /* Over more than one page: the header rows repeat, a long answer breaks between its lines, short rows stay whole. */
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    tr.cont td { border-top: 0; }
    td { orphans: 3; widows: 3; }
    .keep { page-break-after: avoid; }
    table.section { margin-top: -0.6pt; }
    .title { font-size: 15pt; font-weight: bold; text-align: center; padding: 3pt; }
    .b { font-weight: bold; }
    .i { font-style: italic; }
    .tall { height: 52pt; }
    .plan { height: 120pt; }
    .comments { height: 40pt; }
    .fixed { vertical-align: middle; }
    .fixed p { margin: 0 0 8pt 0; }
    .foot { text-align: center; }
    .sym { font-size: 10pt; }
    .indent { padding-left: 14pt; }
    .running { display: none; }
    @media print {
        .running { display: block; position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 7pt; color: #444; }
        .running .page:after { content: "Page " counter(page) " of " counter(pages); }
        .running .page { float: right; }
    }
</style>
</head>
<body>
<div class="running">{{ $lor ? '7CAV-R-043' : '7CAV-R-042' }} · {{ $parties['trooper']['name'] }} · {{ $date }}<span class="page"></span></div>
<table>
    <colgroup><col style="width: 25%"><col style="width: 25%"><col style="width: 25%"><col style="width: 25%"></colgroup>
    <thead><tr><th colspan="4" class="title">{{ $lor ? '7CAV-R-043 – Letter of Reprimand' : '7CAV-R-042 – Developmental Counselling Statement' }}</th></tr></thead>
    <tbody>

    <tr><td class="b">Section 1</td><td></td><td>Date (<span class="i">of Letter</span>)</td><td data-field="date">{{ $date }}</td></tr>
    <tr><td>Title</td><td>Rank</td><td>Name (<span class="i">Last.F</span>)</td><td>Billet</td></tr>
    <tr>
        <td class="i">Trooper</td>
        <td>{{ $parties['trooper']['rank'] }}</td>
        <td data-field="trooper-name">{{ $parties['trooper']['name'] }}</td>
        <td>{{ $parties['trooper']['billet'] }}</td>
    </tr>
    <tr>
        <td class="i">{{ $authorityLabel }}</td>
        <td>{{ $parties['authority']['rank'] }}</td>
        <td data-field="authority-name">{{ $parties['authority']['name'] }}</td>
        <td>{{ $parties['authority']['billet'] }}</td>
    </tr>

    <tr class="keep"><td class="b">Section 2</td><td colspan="3"></td></tr>
    @foreach ($rows([$form['infraction'] ?? ''], $wide) as $i => $row)
        <tr @class(['cont' => $i > 0])>
            <td @class(['i', 'tall' => $loop->count === 1])>{{ $i === 0 ? 'Evidenced infraction and associated details / Previous instances and counsellings' : '' }}</td>
            <td colspan="3" @if ($i === 0) data-field="infraction" @endif>{{ $text($row[0]) }}</td>
        </tr>
    @endforeach
    @foreach ($rows([$form['regulation'] ?? ''], $wide) as $i => $row)
        <tr @class(['cont' => $i > 0])>
            <td @class(['i', 'tall' => $loop->count === 1])>{{ $i === 0 ? 'Associated regulation / order / SOP' : '' }}</td>
            <td colspan="3" @if ($i === 0) data-field="regulation" @endif>{{ $text($row[0]) }}</td>
        </tr>
    @endforeach
    @foreach ($rows([$form['discussion'] ?? ''], $wide) as $i => $row)
        <tr @class(['cont' => $i > 0])>
            <td @class(['i', 'tall' => $loop->count === 1])>{{ $i === 0 ? 'Notes of discussion' : '' }}</td>
            <td colspan="3" @if ($i === 0) data-field="discussion" @endif>{{ $text($row[0]) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
{{-- Its own table, so its column headings repeat on every page the plan and comments run over. --}}
<table class="section">
    <colgroup><col style="width: 25%"><col style="width: 25%"><col style="width: 25%"><col style="width: 25%"></colgroup>
    <thead>
        <tr><td class="i">Agreed plan of action</td><td>Counselling Authority</td><td>Trooper</td><td>Misc / Others</td></tr>
    </thead>
    <tbody>
    @foreach ($rows([$form['plan_authority'] ?? '', $form['plan_trooper'] ?? '', $form['plan_other'] ?? ''], $narrow) as $i => $row)
        <tr @class(['cont' => $i > 0])>
            <td @class(['plan' => $loop->count === 1])></td>
            <td @class(['plan' => $loop->count === 1]) @if ($i === 0) data-field="plan-authority" @endif>{{ $text($row[0]) }}</td>
            <td @class(['plan' => $loop->count === 1])>{{ $text($row[1]) }}</td>
            <td @class(['plan' => $loop->count === 1])>{{ $text($row[2]) }}</td>
        </tr>
    @endforeach
    @foreach ($rows([$form['comments_authority'] ?? '', $form['comments_trooper'] ?? '', $form['comments_other'] ?? ''], $narrow) as $i => $row)
        <tr @class(['cont' => $i > 0])>
            <td @class(['i', 'comments' => $loop->count === 1])>{{ $i === 0 ? 'Any comments' : '' }}</td>
            <td>{{ $text($row[0]) }}</td>
            <td>{{ $text($row[1]) }}</td>
            <td>{{ $text($row[2]) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>
<table class="section">
    <colgroup><col style="width: 25%"><col style="width: 25%"><col style="width: 25%"><col style="width: 25%"></colgroup>
    <tbody>

    @if ($lor)
        <tr>
            <td class="i">Recommended Consequence</td>
            <td colspan="3" data-field="consequences">
                <span class="sym">{{ $box($picked('nfa')) }}</span> Any Instance – 30 Days NFA<br>
                <span class="sym">{{ $box($picked('removal')) }}</span> 2<sup>nd</sup> Instance (in 90 days) – Removal from<br>
                <span class="indent"><span class="sym">{{ $radio(($form['removal'] ?? null) === 'section_leader') }}</span> Section Leader position (if applicable)</span><br>
                <span class="indent"><span class="sym">{{ $radio(($form['removal'] ?? null) === 'assistant_section_leader') }}</span> Assistant Section Leader position (if applicable)</span><br>
                <span class="sym">{{ $box($picked('article_15')) }}</span> Unit Commander escalation to Article 15 Commander’s Investigation<br>
                <span class="sym">{{ $box($picked('special_punishment')) }}</span> Any special punishment directed by a General Staff Officer (<span class="i">to follow</span>)
            </td>
        </tr>
        @foreach ($rows([$form['special_punishments'] ?? ''], $wide) as $i => $row)
            <tr @class(['cont' => $i > 0])>
                <td @class(['i', 'comments' => $loop->count === 1])>{{ $i === 0 ? 'Special punishments ordered' : '' }}</td>
                <td colspan="3" @if ($i === 0) data-field="special-punishments" @endif>{{ $text($row[0]) }}</td>
            </tr>
        @endforeach
        <tr>
            <td class="i">Right to Appeal</td>
            <td colspan="3" data-field="appeal">
                I, the disciplining authority, have informed the trooper of their right to appeal in accordance with 7CAV-R-043 and have been informed that
                they <span class="b">DO</span> <span class="sym">{{ $radio(($form['appeal'] ?? null) === 'do') }}</span>
                or <span class="b">DO NOT</span> <span class="sym">{{ $radio(($form['appeal'] ?? null) === 'do_not') }}</span>
                wish to appeal to one level higher in the chain of command.
            </td>
        </tr>
        <tr>
            <td class="i">Appeal outcome<br>(if applicable)</td>
            <td colspan="3">
                I, the appeal authority, being no more than one level higher in the chain of command,
                <span class="b">HAVE AMENDED</span> <span class="sym">&#9675;</span> or <span class="b">HAVE NOT AMENDED</span> <span class="sym">&#9675;</span>
                the above document based on the merits of the appeal. My decision is final.
            </td>
        </tr>
    @else
        <tr>
            <td class="i">Recommended Consequence</td>
            <td colspan="3" rowspan="4" class="fixed">
                <p>Not applicable for Counselling Statements. They are developmental coaching sessions intended to ensure troopers know the process and expectations.</p>
                <p>Counselling statements are not subject to appeals, as they are simply a non-punitive reminder of the process and expectations.</p>
            </td>
        </tr>
        <tr><td class="i comments">Special punishments ordered</td></tr>
        <tr><td class="i comments">Right to Appeal</td></tr>
        <tr><td class="i comments">Appeal outcome<br>(if applicable)</td></tr>
    @endif

    <tr><td colspan="4" class="foot">PROCEDURAL REFERENCE – For use only in conjunction with {{ $lor ? '7CAV-R-043' : '7CAV-R-042' }}.</td></tr>
    @if ($lor)
        <tr><td colspan="4" class="foot">RECORDS must be kept for permanently in MILPACS (Send to S1 once complete)</td></tr>
    @else
        <tr><td colspan="4" class="foot">RECORDS must be kept for 90 days only in the 7<sup>th</sup> Cavalry Google Drive and/or Leadership Portal. Send up Chain of Command when complete.</td></tr>
    @endif
    <tr><td colspan="4" class="foot">THIS DOCUMENT must include all relevant information and content of the verbal discussion had with the trooper unless no effort was made to contact the Disciplining Authority within 72 hours.</td></tr>
    </tbody>
</table>
</body>
</html>
