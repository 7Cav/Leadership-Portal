<?php

namespace App\Docs;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Extension\FrontMatter\Exception\InvalidFrontMatterException;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\StringContainerHelper;
use League\CommonMark\Normalizer\SlugNormalizer;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Renderer\HtmlRenderer;

/**
 * Turns one guide file into a {@see Guide}, or into the reasons it is not one (#408). Markdown is CommonMark with YAML
 * front matter. Raw HTML is stripped and unsafe link schemes are dropped, so a guide can never put script on a page.
 *
 * Two call-outs are written as a block quote whose first line is a marker:
 *
 *     > [!why] The weekly report is built from your confirmed rollcall.
 *     > [!good-to-know] Troopers on leave are already marked.
 *
 * They render as `<blockquote class="callout callout-why">` (or `callout-good-to-know`) led by "Why this matters." or
 * "Good to know.", in text, not CSS, so a screen reader reads the label too.
 */
final class GuideParser
{
    private const KEYS = ['title', 'summary', 'section', 'order', 'audience', 'page', 'weekly', 'minutes', 'checklist'];

    private const REQUIRED = ['title', 'summary', 'section', 'order', 'audience'];

    private const CHECKLIST_KEYS = ['label', 'audience', 'order'];

    private const CALLOUTS = ['why' => 'Why this matters.', 'good-to-know' => 'Good to know.'];

    /** Lowercase words joined by hyphens: what a section folder and a guide file may be called. */
    public const SLUG = '/^[a-z0-9]+(?:-[a-z0-9]+)*$/';

    private readonly MarkdownParser $parser;

    private readonly HtmlRenderer $renderer;

    private readonly SlugNormalizer $slugs;

    public function __construct()
    {
        $environment = new Environment(['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new FrontMatterExtension);

        $this->parser = new MarkdownParser($environment);
        $this->renderer = new HtmlRenderer($environment);
        $this->slugs = new SlugNormalizer;
    }

    /**
     * The guide when it is valid, and every link and image URL it uses, for the library to check against the other
     * guides and the disk.
     *
     * @return array{guide: ?Guide, problems: list<string>, links: list<string>, images: list<string>}
     */
    public function parse(string $section, string $slug, string $markdown): array
    {
        $problems = [];
        if (preg_match(self::SLUG, $slug) !== 1) {
            $problems[] = 'the file name must be lowercase words joined by hyphens, ending in .md';
        }

        try {
            $document = $this->parser->parse($markdown);
        } catch (InvalidFrontMatterException $e) {
            return ['guide' => null, 'problems' => ["front matter is not valid YAML: {$e->getMessage()}"], 'links' => [], 'images' => []];
        }

        $meta = $document->data->get('front_matter', null);
        if (! is_array($meta) || array_is_list($meta)) {
            return ['guide' => null, 'problems' => ['front matter is missing: start the file with a --- block'], 'links' => [], 'images' => []];
        }

        $problems = [...$problems, ...$this->checkMeta($meta, $section)];

        $headings = $this->anchorHeadings($document);
        $this->renderCallouts($document);
        [$links, $images] = $this->urls($document);

        if ($problems !== []) {
            return ['guide' => null, 'problems' => $problems, 'links' => $links, 'images' => $images];
        }

        $audience = Audience::from($meta['audience']);
        $checklist = isset($meta['checklist'])
            ? new ChecklistItem($meta['checklist']['label'], Audience::from($meta['checklist']['audience']), $meta['checklist']['order'])
            : null;

        $guide = new Guide(
            section: $section,
            slug: $slug,
            title: $meta['title'],
            summary: $meta['summary'],
            order: $meta['order'],
            audience: $audience,
            page: $meta['page'] ?? null,
            weekly: $meta['weekly'] ?? false,
            minutes: $meta['minutes'] ?? null,
            checklist: $checklist,
            html: $this->renderer->renderDocument($document)->getContent(),
            headings: $headings,
        );

        return ['guide' => $guide, 'problems' => [], 'links' => $links, 'images' => $images];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return list<string>
     */
    private function checkMeta(array $meta, string $section): array
    {
        $problems = [];

        foreach (array_diff(array_keys($meta), self::KEYS) as $unknown) {
            $problems[] = "unknown front-matter key `{$unknown}`";
        }
        foreach (array_diff(self::REQUIRED, array_keys($meta)) as $missing) {
            $problems[] = "missing front-matter key `{$missing}`";
        }

        foreach (['title', 'summary'] as $key) {
            if (array_key_exists($key, $meta) && (! is_string($meta[$key]) || trim($meta[$key]) === '')) {
                $problems[] = "`{$key}` must be text";
            }
        }
        if (array_key_exists('section', $meta) && $meta['section'] !== $section) {
            $problems[] = "`section` must be `{$section}`, the folder the guide is in";
        }
        if (array_key_exists('order', $meta) && ! is_int($meta['order'])) {
            $problems[] = '`order` must be a whole number';
        }
        if (array_key_exists('audience', $meta) && $this->audience($meta['audience']) === null) {
            $problems[] = '`audience` must be everyone, leader or admin';
        }
        if (array_key_exists('page', $meta) && (! is_string($meta['page']) || ! str_starts_with($meta['page'], '/'))) {
            $problems[] = '`page` must be a portal path starting with /';
        }
        if (array_key_exists('weekly', $meta) && ! is_bool($meta['weekly'])) {
            $problems[] = '`weekly` must be true or false';
        }
        if (array_key_exists('minutes', $meta) && (! is_int($meta['minutes']) || $meta['minutes'] < 1)) {
            $problems[] = '`minutes` must be a whole number of minutes';
        }

        if (array_key_exists('checklist', $meta)) {
            $problems = [...$problems, ...$this->checkChecklist($meta['checklist'], $this->audience($meta['audience'] ?? null))];
        }

        return $problems;
    }

    /** @return list<string> */
    private function checkChecklist(mixed $item, ?Audience $guide): array
    {
        if (! is_array($item) || array_is_list($item)) {
            return ['`checklist` must have a label, an audience and an order'];
        }

        $problems = [];
        foreach (array_diff(array_keys($item), self::CHECKLIST_KEYS) as $unknown) {
            $problems[] = "unknown `checklist` key `{$unknown}`";
        }
        foreach (array_diff(self::CHECKLIST_KEYS, array_keys($item)) as $missing) {
            $problems[] = "missing `checklist` key `{$missing}`";
        }
        if (array_key_exists('label', $item) && (! is_string($item['label']) || trim($item['label']) === '')) {
            $problems[] = '`checklist.label` must be text';
        }
        if (array_key_exists('order', $item) && ! is_int($item['order'])) {
            $problems[] = '`checklist.order` must be a whole number';
        }
        if (array_key_exists('audience', $item)) {
            $listed = $this->audience($item['audience']);
            if ($listed === null) {
                $problems[] = '`checklist.audience` must be everyone, leader or admin';
            } elseif ($guide !== null && ! $listed->reads($guide)) {
                // Listing a leader guide for everyone would put an item on a trooper's list they could not open.
                $problems[] = "`checklist.audience` cannot be wider than the guide's own audience, `{$guide->value}`";
            }
        }

        return $problems;
    }

    private function audience(mixed $value): ?Audience
    {
        return is_string($value) ? Audience::tryFrom($value) : null;
    }

    /**
     * Gives every heading a stable id from its text (a repeat gets `-2`, `-3`) so "On this page" and a shared link can
     * point at it, and returns the level-2 ones: a guide's steps.
     *
     * @return list<array{id: string, text: string}>
     */
    private function anchorHeadings(Document $document): array
    {
        $used = [];
        $steps = [];

        foreach ($this->nodes($document, Heading::class) as $heading) {
            $text = trim(StringContainerHelper::getChildText($heading));
            $base = $this->slugs->normalize($text) ?: 'section';
            $id = $base;
            for ($n = 2; isset($used[$id]); $n++) {
                $id = "{$base}-{$n}";
            }
            $used[$id] = true;

            $heading->data->set('attributes/id', $id);
            if ($heading->getLevel() === 2) {
                $steps[] = ['id' => $id, 'text' => $text];
            }
        }

        return $steps;
    }

    private function renderCallouts(Document $document): void
    {
        foreach ($this->nodes($document, BlockQuote::class) as $quote) {
            $paragraph = $quote->firstChild();
            if (! $paragraph instanceof Paragraph) {
                continue;
            }

            // The parser keeps unmatched brackets as text nodes of their own, so `[!why]` arrives as "[", "!why", "]".
            $lead = '';
            $consumed = [];
            for ($node = $paragraph->firstChild(); $node instanceof Text && strlen($lead) < 16; $node = $node->next()) {
                $lead .= $node->getLiteral();
                $consumed[] = $node;
                if (str_contains($lead, ']')) {
                    break;
                }
            }
            if (preg_match('/^\[!(why|good-to-know)\]/i', $lead, $marker) !== 1) {
                continue;
            }

            $kind = strtolower($marker[1]);
            foreach ($consumed as $node) {
                $node->detach();
            }
            $rest = ltrim(substr($lead, strlen($marker[0])));
            if ($rest !== '') {
                $paragraph->prependChild(new Text($rest));
            } elseif ($paragraph->firstChild() instanceof Newline) {
                // The marker stood alone on its line: drop the line break after it too.
                $paragraph->firstChild()->detach();
            } elseif ($paragraph->firstChild() instanceof Text) {
                $first = $paragraph->firstChild();
                $first->setLiteral(ltrim($first->getLiteral()));
            }

            $label = new Strong;
            $label->appendChild(new Text(self::CALLOUTS[$kind]));
            $paragraph->prependChild(new Text(' '));
            $paragraph->prependChild($label);

            $quote->data->set('attributes/class', "callout callout-{$kind}");
        }
    }

    /** @return array{0: list<string>, 1: list<string>} link URLs and image URLs */
    private function urls(Document $document): array
    {
        return [
            array_map(fn (Link $link) => $link->getUrl(), $this->nodes($document, Link::class)),
            array_map(fn (Image $image) => $image->getUrl(), $this->nodes($document, Image::class)),
        ];
    }

    /**
     * Every node of one type, collected before any is changed: changing the tree while walking it skips nodes.
     *
     * @template T of \League\CommonMark\Node\Node
     *
     * @param  class-string<T>  $type
     * @return list<T>
     */
    private function nodes(Document $document, string $type): array
    {
        $found = [];
        foreach ($document->iterator() as $node) {
            if ($node instanceof $type) {
                $found[] = $node;
            }
        }

        return $found;
    }
}
