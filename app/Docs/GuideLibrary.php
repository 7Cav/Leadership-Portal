<?php

namespace App\Docs;

use Illuminate\Support\Facades\Cache;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Every guide in `resources/docs`, and the views of them one reader may have (#408). Guides are files in the repo,
 * edited by pull request, so this reads the disk and never the DB.
 *
 * Layout: `sections.yaml` lists the sections in contents order (`key: Title`); each section is a folder of
 * `{slug}.md` guides. Files directly in `resources/docs` (its README) are not guides.
 *
 * A guide outside the reader's audience is answered exactly like one that does not exist, so no caller can tell the
 * two apart. A guide with a problem (bad front matter, a broken link, a missing image) is left out, and
 * {@see problems()} names it: a test fails the build on any, so none reaches production.
 *
 * Parsed once per change of the files: the cache key is a fingerprint of every file's path, size and mtime, so a
 * deploy that changes a guide reads it again and nothing else does.
 */
final class GuideLibrary
{
    /** Bump when the parser's output changes, so a deploy does not serve guides parsed the old way. */
    private const FORMAT = 1;

    /** @var ?array{sections: list<Section>, guides: list<Guide>, problems: list<string>} */
    private ?array $loaded = null;

    public function __construct(
        private readonly GuideParser $parser,
        private readonly ?string $root = null,
        private readonly ?string $images = null,
    ) {}

    /** @return list<Section> */
    public function sections(): array
    {
        return $this->load()['sections'];
    }

    /**
     * Every valid guide, in contents order, whoever may read it.
     *
     * @return list<Guide>
     */
    public function guides(): array
    {
        return $this->load()['guides'];
    }

    /**
     * What is wrong with the guides on disk, one line each, naming the file. Empty when every guide is valid.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->load()['problems'];
    }

    /**
     * The contents tree for one reader: the sections they have at least one guide in, in order, each with its guides
     * in order.
     *
     * @return list<array{section: Section, guides: list<Guide>}>
     */
    public function contents(Audience $reader): array
    {
        $readable = collect($this->readable($reader))->groupBy(fn (Guide $guide) => $guide->section);

        return collect($this->sections())
            ->filter(fn (Section $section) => $readable->has($section->key))
            ->map(fn (Section $section) => ['section' => $section, 'guides' => $readable[$section->key]->all()])
            ->values()
            ->all();
    }

    /** One guide, or null when it does not exist or the reader may not read it: the two look the same. */
    public function find(Audience $reader, string $section, string $slug): ?Guide
    {
        foreach ($this->readable($reader) as $guide) {
            if ($guide->section === $section && $guide->slug === $slug) {
                return $guide;
            }
        }

        return null;
    }

    /**
     * The reader's "Start here" list: guides with a `checklist` entry listed for their audience, in the list's order.
     *
     * @return list<Guide>
     */
    public function checklist(Audience $reader): array
    {
        return collect($this->readable($reader))
            ->filter(fn (Guide $guide) => $guide->checklist !== null && $reader->reads($guide->checklist->audience))
            ->sortBy([fn (Guide $a, Guide $b) => $a->checklist->order <=> $b->checklist->order, fn (Guide $a, Guide $b) => $a->url() <=> $b->url()])
            ->values()
            ->all();
    }

    /**
     * For the "?" help (#411): each portal path a readable guide explains, mapped to that guide's URL. Where two guides
     * explain the same page, the one written for the narrowest audience the reader holds wins (an Admin on a Settings
     * page gets the Admin guide), then the first in contents order.
     *
     * @return array<string, string>
     */
    public function pages(Audience $reader): array
    {
        $best = [];
        foreach ($this->readable($reader) as $guide) {
            if ($guide->page === null) {
                continue;
            }
            $current = $best[$guide->page] ?? null;
            if ($current === null || ($guide->audience !== $current->audience && $guide->audience->reads($current->audience))) {
                $best[$guide->page] = $guide;
            }
        }

        return array_map(fn (Guide $guide) => $guide->url(), $best);
    }

    /**
     * The guides either side of `$guide` in the reader's contents order, for the previous and next links. Crosses
     * sections, so the last guide of one leads to the first of the next.
     *
     * @return array{0: ?Guide, 1: ?Guide}
     */
    public function neighbors(Audience $reader, Guide $guide): array
    {
        $readable = $this->readable($reader);
        $at = array_search($guide->url(), array_map(fn (Guide $g) => $g->url(), $readable), true);
        if ($at === false) {
            return [null, null];
        }

        return [$readable[$at - 1] ?? null, $readable[$at + 1] ?? null];
    }

    /** @return list<Guide> */
    private function readable(Audience $reader): array
    {
        return array_values(array_filter($this->guides(), fn (Guide $guide) => $reader->reads($guide->audience)));
    }

    /** @return array{sections: list<Section>, guides: list<Guide>, problems: list<string>} */
    private function load(): array
    {
        // A week, not forever: every edit leaves a key behind, and an old one should not sit in the store for good.
        return $this->loaded ??= Cache::remember('docs.guides.'.$this->fingerprint(), now()->addWeek(), fn () => $this->parseAll());
    }

    private function fingerprint(): string
    {
        $stamp = [self::FORMAT, $this->root()];
        foreach ([...$this->files($this->root()), ...$this->files($this->images())] as $file) {
            $stamp[] = $file.'|'.filesize($file).'|'.filemtime($file);
        }

        return md5(implode("\n", $stamp));
    }

    /** @return array{sections: list<Section>, guides: list<Guide>, problems: list<string>} */
    private function parseAll(): array
    {
        [$sections, $problems] = $this->readSections();
        $known = [];
        foreach ($sections as $section) {
            $known[$section->key] = $section->order;
        }

        foreach (glob($this->root().'/*', GLOB_ONLYDIR) ?: [] as $dir) {
            if (! array_key_exists(basename($dir), $known)) {
                $problems[] = basename($dir).'/: folder is not listed in sections.yaml';
            }
        }

        // In contents order, so where two guides share a slug the one a reader meets first keeps it.
        $parsed = [];
        foreach (array_keys($known) as $section) {
            $dir = $this->root().'/'.$section;
            foreach ($this->files($dir) as $file) {
                $name = substr($file, strlen($dir) + 1);
                if (! str_ends_with($name, '.md') || str_contains($name, '/')) {
                    $problems[] = "{$section}/{$name}: only .md guides belong in a section folder, with no subfolders";

                    continue;
                }
                $slug = substr($name, 0, -3);
                $parsed["{$section}/{$slug}"] = $this->parser->parse($section, $slug, (string) file_get_contents($file));
            }
        }

        $valid = [];
        $slugs = [];
        foreach ($parsed as $path => $result) {
            foreach ($result['problems'] as $problem) {
                $problems[] = "{$path}.md: {$problem}";
            }
            $guide = $result['guide'];
            if ($guide === null) {
                continue;
            }
            if (isset($slugs[$guide->slug])) {
                // One slug, one guide: a link or a search result must never be ambiguous about which guide it means.
                $problems[] = "{$path}.md: slug `{$guide->slug}` is already used by {$slugs[$guide->slug]}.md";

                continue;
            }
            $slugs[$guide->slug] = $path;
            $valid[$path] = $guide;
        }

        foreach ($parsed as $path => $result) {
            foreach ($result['links'] as $url) {
                if ($this->isDocsLink($url) && ! $this->linksToGuide($url, $parsed)) {
                    $problems[] = "{$path}.md: links to {$url}, which is not a guide";
                }
            }
            foreach ($result['images'] as $url) {
                if (! $this->imageExists($url)) {
                    $problems[] = "{$path}.md: image {$url} is not in public/guide-img";
                }
            }
        }

        $guides = array_values($valid);
        usort($guides, fn (Guide $a, Guide $b) => [$known[$a->section], $a->order, $a->slug] <=> [$known[$b->section], $b->order, $b->slug]);

        return ['sections' => $sections, 'guides' => $guides, 'problems' => array_values(array_unique($problems))];
    }

    /** @return array{0: list<Section>, 1: list<string>} */
    private function readSections(): array
    {
        $file = $this->root().'/sections.yaml';
        if (! is_file($file)) {
            return [[], ['sections.yaml: missing']];
        }

        try {
            $listed = Yaml::parseFile($file);
        } catch (ParseException $e) {
            return [[], ["sections.yaml: not valid YAML: {$e->getMessage()}"]];
        }

        $sections = [];
        $problems = [];
        foreach (is_array($listed) ? $listed : [] as $key => $title) {
            if (! is_string($key) || preg_match(GuideParser::SLUG, $key) !== 1 || ! is_string($title) || trim($title) === '') {
                $problems[] = 'sections.yaml: each line must be `folder-name: Title`';

                continue;
            }
            $sections[] = new Section($key, $title, count($sections));
        }

        return [$sections, $problems];
    }

    private function isDocsLink(string $url): bool
    {
        return $url === '/docs' || str_starts_with($url, '/docs/') || str_starts_with($url, '/docs#');
    }

    /** @param  array<string, mixed>  $parsed */
    private function linksToGuide(string $url, array $parsed): bool
    {
        $path = rtrim(strtok($url, '#?'), '/');

        return $path === '/docs' || isset($parsed[substr($path, strlen('/docs/'))]);
    }

    private function imageExists(string $url): bool
    {
        if (! str_starts_with($url, '/guide-img/') || str_contains($url, '..')) {
            return false;
        }

        return is_file($this->images().'/'.substr($url, strlen('/guide-img/')));
    }

    /** @return list<string> every file under `$dir`, sorted, with forward slashes */
    private function files(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }

        $files = [];
        $walk = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
        foreach ($walk as $file) {
            if ($file->isFile()) {
                $files[] = str_replace('\\', '/', $file->getPathname());
            }
        }
        sort($files);

        return $files;
    }

    private function root(): string
    {
        return str_replace('\\', '/', $this->root ?? resource_path('docs'));
    }

    private function images(): string
    {
        return str_replace('\\', '/', $this->images ?? public_path('guide-img'));
    }
}
