<?php

namespace App\Commands\Units;

/**
 * What one org-structure import did (story 2.9): how many nodes and billet mappings it created, how many of
 * its own mappings it repointed to where Cav now lists the billet, how many existing mappings disagree with Cav
 * and were kept (corrected by hand, or never confirmed by the import), and which position titles it could not
 * place. An import never updates or deletes a unit, and never deletes a billet mapping. The
 * leadership billet → commanded unit mappings (story 1.16) are reconciled to Cav instead, so their
 * counts are created / repointed / removed.
 */
final readonly class OrgStructureImportResult
{
    /**
     * @param  list<string>  $skippedTitles  position titles in line-company groups whose designation
     *                                       could not be parsed, or named another company
     */
    public function __construct(
        public int $unitsCreated,
        public int $mappingsCreated,
        public int $mappingsDiffering,
        public array $skippedTitles,
        public int $commandMappingsCreated,
        public int $commandMappingsRepointed,
        public int $commandMappingsRemoved,
        public int $mappingsRepointed = 0,
    ) {}
}
