<?php

use App\Commands\Counseling\CounselingForm;
use App\Commands\Counseling\CounselingFormPdf;
use App\Models\CounselingType;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;

// The generated counseling PDF prints what a leader typed as text, and dompdf loads nothing: no remote fetch, no
// local file outside the view's own directory, no PHP and no JavaScript.

/** A one-pixel PNG on disk, outside the directory dompdf may read from. */
function onePixelPng(): string
{
    $path = tempnam(sys_get_temp_dir(), 'px').'.png';
    file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));

    return $path;
}

it('runs dompdf without remote access, PHP or JavaScript, and reads local files only from the view directory', function () {
    $options = CounselingFormPdf::options();

    expect($options->getIsRemoteEnabled())->toBeFalse()
        ->and($options->getIsPhpEnabled())->toBeFalse()
        ->and($options->getIsJavascriptEnabled())->toBeFalse()
        ->and($options->getChroot())->toBe([resource_path('views/pdf')]);
});

it('prints markup, remote and local image tags typed into every field as text, and embeds no image', function () {
    $png = onePixelPng();
    $payload = "<b>bold</b> <img src=\"http://127.0.0.1:9/x.png\"> <img src=\"file://{$png}\"> <script>alert(1)</script>";

    try {
        $form = CounselingForm::fromInput([
            'infraction' => $payload,
            'regulation' => $payload,
            'discussion' => $payload,
            'plan_authority' => $payload,
            'plan_trooper' => $payload,
            'comments_authority' => $payload,
            'comments_trooper' => $payload,
            'consequences' => ['special_punishment'],
            'special_punishments' => $payload,
            'appeal' => 'do',
        ], CounselingType::Lor);
        $party = ['rank' => '<img src=x>', 'name' => '<b>Brandt.L</b>', 'billet' => 'file:///etc/passwd'];
        $pdf = app(CounselingFormPdf::class);
        $date = CarbonImmutable::parse('2026-10-20');

        $html = $pdf->html(CounselingType::Lor, $date, ['trooper' => $party, 'authority' => $party], $form);
        $bytes = $pdf->pdf(CounselingType::Lor, $date, ['trooper' => $party, 'authority' => $party], $form);
    } finally {
        @unlink($png);
    }

    expect($html)->not->toContain('<img')
        ->not->toContain('<b>')
        ->not->toContain('<script')
        ->toContain('&lt;img src=&quot;http://127.0.0.1:9/x.png&quot;&gt;')
        ->toContain('&lt;b&gt;Brandt.L&lt;/b&gt;')
        ->and(str_starts_with($bytes, '%PDF-'))->toBeTrue()
        ->and($bytes)->not->toContain('/Image');
});

it('would not load an image from outside the view directory or the network even if one reached the page', function () {
    $png = onePixelPng();

    try {
        $dompdf = new Dompdf(CounselingFormPdf::options());
        $dompdf->loadHtml("<p>x</p><img src=\"file://{$png}\"><img src=\"{$png}\"><img src=\"http://127.0.0.1:9/x.png\">");
        $dompdf->render();
        $bytes = (string) $dompdf->output();
    } finally {
        @unlink($png);
    }

    expect($bytes)->not->toContain('/Image');
});
