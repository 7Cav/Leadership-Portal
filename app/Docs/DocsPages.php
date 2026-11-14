<?php

namespace App\Docs;

/**
 * The props of the two docs pages (#409, #410), for one reader. Every list is already filtered to the reader's
 * audience by the {@see GuideLibrary}, so a page never holds a guide it should not show.
 */
final class DocsPages
{
    public function __construct(private readonly GuideLibrary $library) {}

    /** Whether the docs are on (DOCS_ENABLED): every docs page is a 404 while they are off. */
    public static function enabled(): bool
    {
        return (bool) config('portal.docs');
    }

    /**
     * The guide at `/docs/{section}/{slug}` when `$reader` may read it, else null (its page is a 404): the one rule the
     * guide page and the sidebar's bookmarks read.
     */
    public function readable(Audience $reader, string $section, string $slug): ?Guide
    {
        return $this->library->find($reader, $section, $slug);
    }

    /**
     * @param  list<string>  $facts  what the portal knows the reader has done ({@see PortalProgress})
     * @return array<string, mixed>
     */
    public function home(Audience $reader, array $facts = []): array
    {
        $guides = array_filter($this->library->guides(), fn (Guide $guide) => $reader->reads($guide->audience) && $guide->section !== GuideLibrary::TUTORIAL);

        return [
            ...$this->shared($reader, $facts),
            // The role paths (Section Leader, HQ staff...) this reader can follow, each with the guides on it.
            'paths' => array_map(fn (array $path) => [
                'key' => $path['key'],
                'title' => $path['title'],
                'blurb' => $path['blurb'],
                'guides' => array_map(fn (Guide $guide) => $this->link($guide), $path['guides']),
            ], $this->library->paths($reader)),
        ];
    }

    /**
     * The tutorial's first step URL, or null when this reader has no tutorial step to read.
     */
    public function tutorialStart(Audience $reader): ?string
    {
        return ($this->library->tutorial($reader)[0] ?? null)?->url();
    }

    /**
     * @param  list<string>  $facts  what the portal knows the reader has done ({@see PortalProgress})
     * @return ?array<string, mixed> null when the guide does not exist or the reader may not read it
     */
    public function guide(Audience $reader, string $section, string $slug, array $facts = []): ?array
    {
        $guide = $this->readable($reader, $section, $slug);
        if ($guide === null) {
            return null;
        }

        [$previous, $next] = $this->library->neighbors($reader, $guide);
        $steps = $guide->section === GuideLibrary::TUTORIAL ? $this->library->tutorial($reader) : null;
        $sectionTitle = collect($this->library->sections())->firstWhere('key', $guide->section)?->title;

        return [
            ...$this->shared($reader, $facts),
            'guide' => [
                ...$this->link($guide),
                'section' => ['key' => $guide->section, 'title' => $sectionTitle],
                'audience' => $guide->audience->value,
                'page' => $guide->page,
                'weekly' => $guide->weekly,
                'minutes' => $guide->minutes,
                'html' => $guide->html,
                'headings' => $guide->headings,
                'readingMinutes' => self::readingMinutes($guide->html),
                'updated' => $guide->updated,
                'try' => $guide->try,
            ],
            'previous' => $previous ? $this->link($previous) : null,
            'next' => $next ? $this->link($next) : null,
            // The shell's breadcrumb ends with the guide: 7CAV > Docs > {guide}.
            'crumb' => $guide->title,
            // On a tutorial step: every step, for "Step 2 of 8" and the step list. Null on a guide.
            'steps' => $steps === null ? null : array_map(fn (Guide $step) => [
                'id' => $step->slug,
                'title' => $step->title,
                'url' => $step->url(),
            ], $steps),
        ];
    }

    /**
     * @param  list<string>  $facts
     * @return array<string, mixed>
     */
    private function shared(Audience $reader, array $facts): array
    {
        return [
            // The tutorial steps the portal ticks off by itself, by step id.
            'portalDone' => PortalProgress::done($facts),
            'audience' => $reader->value,
            'feedbackUrl' => config('portal.docs_feedback_url') ?: null,
            // The tutorial is not a section of the contents: it has its own entry, `tutorial`.
            'contents' => array_map(fn (array $group) => [
                'key' => $group['section']->key,
                'title' => $group['section']->title,
                'guides' => array_map(fn (Guide $guide) => $this->link($guide), $group['guides']),
            ], array_values(array_filter($this->library->contents($reader), fn (array $group) => $group['section']->key !== GuideLibrary::TUTORIAL))),
            'tutorial' => ($steps = $this->library->tutorial($reader)) === [] ? null : [
                'url' => $steps[0]->url(),
                'steps' => array_map(fn (Guide $step) => ['id' => $step->slug, 'title' => $step->title, 'url' => $step->url()], $steps),
            ],
            // The search (#413) runs in the page, over only the guides this reader may read: no request per keystroke.
            'search' => collect($this->library->contents($reader))->flatMap(fn (array $group) => array_map(fn (Guide $guide) => [
                'title' => $guide->title,
                'summary' => $guide->summary,
                'url' => $guide->url(),
                'section' => $group['section']->title,
                'headings' => array_column($guide->headings, 'text'),
                'text' => self::plainText($guide->html),
            ], $group['guides']))->values()->all(),
        ];
    }

    /** About how long the guide takes to read, at 200 words a minute, never under one minute. */
    private static function readingMinutes(string $html): int
    {
        return max(1, (int) ceil(str_word_count(self::plainText($html)) / 200));
    }

    /** A guide's body as plain words for the search: no tags, entities decoded, whitespace collapsed. */
    private static function plainText(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5)));
    }

    /** @return array{title: string, summary: string, url: string, audience: string, weekly: bool} */
    private function link(Guide $guide): array
    {
        return ['title' => $guide->title, 'summary' => $guide->summary, 'url' => $guide->url(), 'audience' => $guide->audience->value, 'weekly' => $guide->weekly];
    }
}
