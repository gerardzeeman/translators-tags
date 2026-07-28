<?php

namespace App\Service;

use App\Service\Embed\BibleVerseEmbedRenderer;
use App\Service\Embed\EmbedConfigParser;
use App\Service\Embed\EmbedFencedCodeRenderer;
use App\Service\Embed\InstitutioEmbedRenderer;
use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;

/**
 * Renders blog Markdown to HTML. Raw HTML in the source is stripped
 * (html_input: 'strip'), not escaped-and-shown or passed through: a blog can
 * be made publicly visible to anonymous visitors, so its Markdown source is
 * never trusted as safe HTML, even though only ROLE_BLOGGER users can write it.
 *
 * Fenced code blocks with a recognised info string (```bijbelvers, later
 * ```institutie) are intercepted and rendered as embed modules instead of
 * plain code blocks -- see EmbedFencedCodeRenderer.
 */
class BlogMarkdownRenderer
{
    private CommonMarkConverter $converter;

    public function __construct(
        BibleVerseEmbedRenderer $bibleVerseEmbedRenderer,
        InstitutioEmbedRenderer $institutioEmbedRenderer,
    ) {
        $this->converter = new CommonMarkConverter([
            'html_input'         => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $this->converter->getEnvironment()->addRenderer(
            FencedCode::class,
            new EmbedFencedCodeRenderer([$bibleVerseEmbedRenderer, $institutioEmbedRenderer], new EmbedConfigParser()),
            100
        );
    }

    public function render(string $markdown): string
    {
        return (string) $this->converter->convert($markdown);
    }
}
