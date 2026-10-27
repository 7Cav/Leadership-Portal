<?php

namespace App\Docs;

/**
 * One guide, parsed from `resources/docs/{section}/{slug}.md` (#408). The body is already rendered to HTML, with raw
 * HTML in the markdown stripped, so a page shows it as is and never runs it as a template.
 */
final readonly class Guide
{
    /**
     * @param  ?string  $page  the portal path the guide explains (`/rollcall`, `/reports/{unit}/{week}`), for the "?" help
     * @param  bool  $weekly  a task done every week: docs home lists it under "Every week", the guide says "Once a week"
     * @param  ?int  $minutes  about how long the task takes, shown on the guide
     * @param  list<array{id: string, text: string}>  $headings  the level-2 headings, for "On this page"
     */
    public function __construct(
        public string $section,
        public string $slug,
        public string $title,
        public string $summary,
        public int $order,
        public Audience $audience,
        public ?string $page,
        public bool $weekly,
        public ?int $minutes,
        public ?ChecklistItem $checklist,
        public string $html,
        public array $headings,
    ) {}

    public function url(): string
    {
        return "/docs/{$this->section}/{$this->slug}";
    }
}
