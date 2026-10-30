<?php

namespace App\Docs;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Markdown written in the portal (the changelog) turned into HTML for a page, with the guides' settings
 * ({@see GuideParser}): raw HTML is stripped and unsafe links (`javascript:`, `data:` and the like) are dropped, so a
 * `<script>` in an entry reaches the page as nothing at all, never as HTML. Tables are allowed, front matter is not.
 */
class SafeMarkdown
{
    private readonly MarkdownConverter $converter;

    public function __construct()
    {
        $environment = new Environment(['html_input' => 'strip', 'allow_unsafe_links' => false]);
        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new TableExtension);

        $this->converter = new MarkdownConverter($environment);
    }

    public function toHtml(string $markdown): string
    {
        return $this->converter->convert($markdown)->getContent();
    }
}
