<?php

namespace App\Twig;

use App\Service\TranslationAccessService;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class TranslationAccessExtension extends AbstractExtension
{
    public function __construct(private readonly TranslationAccessService $translationAccess) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('translation_visible', [$this->translationAccess, 'isVisible']),
        ];
    }
}
