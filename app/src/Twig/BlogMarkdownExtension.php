<?php

namespace App\Twig;

use App\Service\BlogMarkdownRenderer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

class BlogMarkdownExtension extends AbstractExtension
{
    public function __construct(private readonly BlogMarkdownRenderer $renderer) {}

    public function getFilters(): array
    {
        return [
            new TwigFilter('blog_markdown', [$this->renderer, 'render'], ['is_safe' => ['html']]),
        ];
    }
}
