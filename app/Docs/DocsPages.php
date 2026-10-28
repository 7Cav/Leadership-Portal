<?php

namespace App\Docs;

/**
 * The props of the two docs pages (#409, #410), for one reader. Every list is already filtered to the reader's
 * audience by the {@see GuideLibrary}, so a page never holds a guide it should not show.
 */
final class DocsPages
{
    public function __construct(private readonly GuideLibrary $library) {}

    /** @return array<string, mixed> */
    public function home(Audience $reader): array
    {
        return $this->shared($reader);
    }

    /** @return ?array<string, mixed> null when the guide does not exist or the reader may not read it */
    public function guide(Audience $reader, string $section, string $slug): ?array
    {
        $guide = $this->library->find($reader, $section, $slug);
        if ($guide === null) {
            return null;
        }

        [$previous, $next] = $this->library->neighbors($reader, $guide);
        $sectionTitle = collect($this->library->sections())->firstWhere('key', $guide->section)?->title;

        return [
            ...$this->shared($reader),
            'guide' => [
                ...$this->link($guide),
                'section' => ['key' => $guide->section, 'title' => $sectionTitle],
                'audience' => $guide->audience->value,
                'page' => $guide->page,
                'weekly' => $guide->weekly,
                'minutes' => $guide->minutes,
                'html' => $guide->html,
                'headings' => $guide->headings,
                // The guide's Start here item id when it is on this reader's list, for "Tick it off" (#412).
                'checklist' => $guide->checklist !== null && $reader->reads($guide->checklist->audience) ? "{$guide->section}/{$guide->slug}" : null,
            ],
            'previous' => $previous ? $this->link($previous) : null,
            'next' => $next ? $this->link($next) : null,
            // The shell's breadcrumb ends with the guide: 7CAV > Docs > {guide}.
            'crumb' => $guide->title,
        ];
    }

    /** @return array<string, mixed> */
    private function shared(Audience $reader): array
    {
        return [
            'audience' => $reader->value,
            'feedbackUrl' => config('portal.docs_feedback_url') ?: null,
            'contents' => array_map(fn (array $group) => [
                'key' => $group['section']->key,
                'title' => $group['section']->title,
                'guides' => array_map(fn (Guide $guide) => $this->link($guide), $group['guides']),
            ], $this->library->contents($reader)),
            // The search (#413) runs in the page, over only the guides this reader may read: no request per keystroke.
            'search' => collect($this->library->contents($reader))->flatMap(fn (array $group) => array_map(fn (Guide $guide) => [
                'title' => $guide->title,
                'summary' => $guide->summary,
                'url' => $guide->url(),
                'section' => $group['section']->title,
                'headings' => array_column($guide->headings, 'text'),
                'text' => self::plainText($guide->html),
            ], $group['guides']))->values()->all(),
            'checklist' => array_map(fn (Guide $guide) => [
                'id' => "{$guide->section}/{$guide->slug}",
                'label' => $guide->checklist->label,
                'url' => $guide->url(),
            ], $this->library->checklist($reader)),
        ];
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
