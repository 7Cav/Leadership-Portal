<?php

namespace Database\Seeders;

use App\Commands\Courses\CreateCourse;
use App\Models\AreaOfOperation;
use App\Repositories\CourseRepository;
use App\Repositories\UserRepository;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Seeds the portal-owned course catalog (AD-5, AD-22): no external source publishes it, so the
 * list lives here. Every row goes through the audited `CreateCourse` Command — the seeder never
 * writes the table itself — so the audit trail needs an actor, and the first Admin is used (the
 * Command refuses anyone else).
 * `sort_order` comes from a course's position in this file, so it is only the source of truth for
 * order until a course is added by hand (or the list is edited): rows already seeded keep their
 * number. Re-running is safe: a code already in the catalog is skipped, never updated or duplicated.
 *
 * `php artisan db:seed --class=CourseCatalogSeeder`
 */
class CourseCatalogSeeder extends Seeder
{
    /**
     * Courses grouped by area of operation, in display order: AOs in the order listed, courses in
     * the order listed within each. Each course is `code => name`; codes are portal-assigned short
     * labels (the matrix column headers) and must stay unique across the whole list. An AO with
     * no courses yet is kept as an empty group so there is an obvious place to add them.
     *
     * Names mirror the wording of the Cav roster's graduation records ("Graduated <name>"), so a
     * later story can match a trooper's records to these courses by name — rename with care.
     *
     * @return list<array{0: AreaOfOperation, 1: array<string, string>}>
     */
    public static function groups(): array
    {
        return [
            [AreaOfOperation::General, [
                'BC' => 'Boot Camp',
                'NCOA-1' => 'NCOA Warrior Leadership Course Phase I',
                'NCOA-2' => 'NCOA Warrior Leadership Course Phase II',
                'ODS' => 'Officer Development School',
                'SAC' => 'Server Administration Course',
                'RDPTC' => 'Regimental Disciplinary Process Training Course',
            ]],

            [AreaOfOperation::Battlefield6, [
                'BF6-BAMC' => 'Battlefield 6 Basic Assault and Movement Course',
                'BF6-BCC' => 'Battlefield 6 Basic Communication Course',
            ]],

            [AreaOfOperation::Arma3, [
                'A3-BITC' => 'Arma 3 Basic Infantry Training Course',
                'A3-BRC' => 'Arma 3 Basic Radio Communication Course',
                'A3-BLNC' => 'Arma 3 Basic Land Navigation Course',
            ]],

            [AreaOfOperation::ArmaReforger, [
                'AR-BIT' => 'Arma Reforger Basic Infantry Training Course',
                'AR-AIT' => 'Arma Reforger Advanced Individual Training Course',
                'AR-BLC' => 'Arma Reforger Basic Leader Course',
                'AR-BDC' => 'Arma Reforger Basic Demolitions Course',
                'AR-BLOC' => 'Arma Reforger Basic Logistics Operations Course',
                'AR-BOC' => 'Arma Reforger Basic Orienteering Course',
                'AR-AGS' => 'Arma Reforger Aviation Ground School',
            ]],

            [AreaOfOperation::HllWw2, [
                'HLL-BICT' => 'Hell Let Loose Basic Infantry Combat Training Course',
                'HLL-ES' => 'Hell Let Loose Engineer and Support Course',
                'HLL-AT' => 'Hell Let Loose Anti Tank Course',
                'HLL-BRC' => 'Hell Let Loose Basic Recon Course',
                'HLL-SNP' => 'Hell Let Loose Sniper Course',
                'HLL-BAC' => 'Hell Let Loose Basic Armor Crewman Course',
                'HLL-TDC' => 'Hell Let Loose Tank Driver Course',
                'HLL-ACC' => 'Hell Let Loose Artillery Crewman Course',
            ]],

            [AreaOfOperation::HllWw2Console, []],

            [AreaOfOperation::HllVietnam, []],

            [AreaOfOperation::DcsWorld, [
                'DCS-GS' => 'DCS Ground School Course',
                'DCS-AGS' => 'DCS Aviation Ground School',
                'DCS-BCQ' => 'DCS Basic Carrier Qualification Course',
                // A multi-day course graduates one day at a time on the milpac, so each day is its own course.
                'DCS-F16-1' => 'DCS F-16 Basic Course Day 1',
            ]],

            [AreaOfOperation::Squad, []],

            [AreaOfOperation::Foxhole, []],
        ];
    }

    /**
     * The groups flattened to one ordered list.
     *
     * @return list<array{code: string, name: string, category: AreaOfOperation}>
     */
    public static function catalog(): array
    {
        $catalog = [];

        foreach (self::groups() as [$category, $courses]) {
            foreach ($courses as $code => $name) {
                $catalog[] = ['code' => (string) $code, 'name' => $name, 'category' => $category];
            }
        }

        return $catalog;
    }

    public function run(CreateCourse $createCourse, CourseRepository $courses, UserRepository $users): void
    {
        $actor = $users->firstAdmin()
            ?? throw new RuntimeException('Seeding the course catalog needs an Admin user to audit the writes against; sign in once and make yourself Admin first.');

        // Spaced by ten so a course can later be slotted between two without renumbering. A course
        // already in the catalog is left exactly as it is, so reordering or renaming the list here
        // does not change rows that were seeded earlier — that is a job for an edit Command.
        foreach (self::catalog() as $index => $course) {
            if ($courses->findByCode($course['code']) !== null) {
                continue;
            }

            $createCourse->handle($actor, $course['code'], $course['name'], $course['category'], ($index + 1) * 10);
        }
    }
}
