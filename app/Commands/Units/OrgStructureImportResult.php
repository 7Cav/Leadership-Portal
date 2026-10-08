<?php

namespace App\Commands\Units;

/**
 * What one org-structure import did (story 2.9): how many nodes and billet mappings it created,
 * and which position titles it could not place. Nothing is ever updated or deleted by an import,
 * so created counts are the whole story.
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
        public array $skippedTitles,
    ) {}
}
