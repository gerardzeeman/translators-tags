<?php

namespace App\Service;

use League\CommonMark\CommonMarkConverter;

/**
 * Renders NewsDigest::body Markdown to HTML for the public overzicht-pagina.
 * Same untrusted-input posture as BlogMarkdownRenderer (html_input: strip,
 * allow_unsafe_links: false) -- this content comes from an external system
 * (the scheduled task via the webhook), not from an authenticated editor.
 */
class NewsDigestMarkdownRenderer
{
    private CommonMarkConverter $converter;

    public function __construct()
    {
        $this->converter = new CommonMarkConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    public function render(string $markdown): string
    {
        return (string) $this->converter->convert($markdown);
    }
}
