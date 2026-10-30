<?php

namespace App\Commands\Counseling;

use App\Models\CounselingType;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\View\Factory as ViewFactory;

/**
 * Renders a DCS or LOR from the portal's form as the regiment's A4 form (more pages when the answers are long, header rows repeated), through the Blade view
 * `pdf.counseling`. {@see html()} is also the preview, so what the leader sees is what is filed.
 *
 * dompdf (pure PHP, no system binaries) runs with remote access, PHP and JavaScript off and its file access kept to
 * the view's own directory: the page loads nothing from anywhere, and the text it prints is escaped by the view.
 */
class CounselingFormPdf
{
    public function __construct(private readonly ViewFactory $views) {}

    /**
     * @param  array{trooper: array{rank: string, name: string, billet: string}, authority: array{rank: string, name: string, billet: string}}  $parties
     */
    public function html(CounselingType $type, CarbonImmutable $date, array $parties, CounselingForm $form): string
    {
        return $this->views->make('pdf.counseling', [
            'lor' => $type === CounselingType::Lor,
            'date' => $date->format('d M Y'),
            'parties' => $parties,
            'form' => $form->toArray(),
        ])->render();
    }

    /**
     * The PDF's bytes.
     *
     * @param  array{trooper: array{rank: string, name: string, billet: string}, authority: array{rank: string, name: string, billet: string}}  $parties
     */
    public function pdf(CounselingType $type, CarbonImmutable $date, array $parties, CounselingForm $form): string
    {
        $dompdf = new Dompdf(self::options());
        $dompdf->loadHtml($this->html($type, $date, $parties, $form), 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * dompdf's settings: nothing fetched over the network, no PHP or JavaScript in the page, and local files only from
     * the view's own directory.
     */
    public static function options(): Options
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setChroot([resource_path('views/pdf')]);
        $options->setDefaultFont('DejaVu Sans');
        $options->setDefaultPaperSize('a4');

        return $options;
    }

    /**
     * The PDF written to a temporary file, for the filing path that an upload takes. The caller removes it.
     *
     * @param  array{trooper: array{rank: string, name: string, billet: string}, authority: array{rank: string, name: string, billet: string}}  $parties
     */
    public function toTemporaryFile(CounselingType $type, CarbonImmutable $date, array $parties, CounselingForm $form): string
    {
        $path = tempnam(sys_get_temp_dir(), 'counseling-');
        if ($path === false || file_put_contents($path, $this->pdf($type, $date, $parties, $form)) === false) {
            throw new \RuntimeException('Could not write the generated counseling PDF to a temporary file.');
        }

        return $path;
    }
}
