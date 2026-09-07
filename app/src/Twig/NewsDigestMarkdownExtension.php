<?php

namespace App\Twig;

use App\Service\NewsDigestMarkdownRenderer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class NewsDigestMarkdownExtension extends AbstractExtension
{
    public function __construct(private readonly NewsDigestMarkdownRenderer $renderer) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('news_digest_markdown', [$this->renderer, 'render'], ['is_safe' => ['html']]),
        ];
    }
}
