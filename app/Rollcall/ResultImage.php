<?php

namespace App\Rollcall;

use InvalidArgumentException;

/**
 * Draws one of a roll call's result images (#641): a PNG listing every trooper with their Signed RC mark (`rc`) or their
 * Attended SP mark (`sp`), which the roll call BBCode embeds by URL so the forum post shows the latest marks without
 * being pasted again. Rank text only, no insignia: drawing them would mean fetching images server-side, and the only
 * path to an outside system is `Integration/` (AD-3). Uses GD with the bundled DejaVu Sans (`resources/fonts`), whose
 * glyphs cover every character a forum username can hold.
 *
 * Pure over the rows from {@see ResultRows}; never reads the database or the network.
 */
final class ResultImage
{
    public const KINDS = ['rc' => 'Signed RC', 'sp' => 'Attended SP'];

    private const WIDTH = 460;

    private const ROW = 26;

    private const HEAD = 62;

    private const PAD = 14;

    private const SIZE = 10.5;

    /** Mark word => [red, green, blue]. */
    private const TONES = [
        'Yes' => [46, 125, 50],
        'No' => [198, 40, 40],
        'MU' => [21, 101, 192],
        'EX' => [191, 120, 0],
        'LOA' => [191, 120, 0],
        '-' => [120, 120, 120],
    ];

    /**
     * @param  'rc'|'sp'  $kind
     * @param  string  $title  the line under the heading: the unit and the week
     * @param  list<array{name: string, rc: string, sp: string}>  $rows
     * @return string the PNG
     */
    public static function render(string $kind, string $title, array $rows): string
    {
        $heading = self::KINDS[$kind] ?? throw new InvalidArgumentException("Unknown result image: {$kind}");
        $height = self::HEAD + max(1, count($rows)) * self::ROW + self::PAD;

        $image = imagecreatetruecolor(self::WIDTH, $height);
        $ink = imagecolorallocate($image, 33, 37, 41);
        $muted = imagecolorallocate($image, 108, 117, 125);
        $rule = imagecolorallocate($image, 222, 226, 230);
        $band = imagecolorallocate($image, 246, 247, 249);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));

        $fonts = dirname(__DIR__, 2).'/resources/fonts';
        $regular = "{$fonts}/DejaVuSans.ttf";
        $bold = "{$fonts}/DejaVuSans-Bold.ttf";

        imagettftext($image, 13, 0, self::PAD, 26, $ink, $bold, $heading);
        imagettftext($image, self::SIZE - 1, 0, self::PAD, 47, $muted, $regular, $title);
        imageline($image, 0, self::HEAD - 6, self::WIDTH, self::HEAD - 6, $rule);

        if ($rows === []) {
            imagettftext($image, self::SIZE, 0, self::PAD, self::HEAD + 18, $muted, $regular, 'No troopers on this roll call.');
        }

        foreach ($rows as $i => $row) {
            $top = self::HEAD + $i * self::ROW;
            $base = $top + 18;

            if ($i % 2 === 1) {
                imagefilledrectangle($image, 0, $top - 3, self::WIDTH, $top + self::ROW - 4, $band);
            }

            imagettftext($image, self::SIZE, 0, self::PAD, $base, $ink, $regular, self::fit($row['name'], 330, $regular));

            $mark = $row[$kind];
            [$r, $g, $b] = self::TONES[$mark] ?? self::TONES['-'];
            $tone = imagecolorallocate($image, $r, $g, $b);
            $box = imagettfbbox(self::SIZE, 0, $bold, $mark);
            imagettftext($image, self::SIZE, 0, self::WIDTH - self::PAD - ($box[2] - $box[0]), $base, $tone, $bold, $mark);
        }

        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** A name too wide for its column is cut with an ellipsis rather than running under the mark. */
    private static function fit(string $text, int $width, string $font): string
    {
        $wide = fn (string $s) => ($box = imagettfbbox(self::SIZE, 0, $font, $s)) ? $box[2] - $box[0] : 0;

        while ($text !== '' && $wide($text) > $width) {
            $text = mb_substr(rtrim($text, '…'), 0, -1).'…';
        }

        return $text;
    }
}
