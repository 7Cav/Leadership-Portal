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
                'checklist' => $guide->checklist !== null && $reader->reads($guide->checklist->audience),
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
            'checklist' => array_map(fn (Guide $guide) => [
                'id' => "{$guide->section}/{$guide->slug}",
                'label' => $guide->checklist->label,
                'url' => $guide->url(),
            ], $this->library->checklist($reader)),
        ];
    }

    /** @return array{title: string, summary: string, url: string, audience: string, weekly: bool} */
    private function link(Guide $guide): array
    {
        return ['title' => $guide->title, 'summary' => $guide->summary, 'url' => $guide->url(), 'audience' => $guide->audience->value, 'weekly' => $guide->weekly];
    }
}
