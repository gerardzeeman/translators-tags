<?php

namespace App\Service\Embed;

/**
 * A blog embed module: recognises a fenced-code-block info string (e.g.
 * "bijbelvers") and renders its key:value body to a self-contained HTML
 * fragment. Errors (unknown book, verse not found, ...) must be rendered as
 * a visible-but-harmless message rather than thrown, so a typo in the embed
 * config can never break the whole blog page.
 */
interface BlogEmbedRendererInterface
{
    public function supports(string $infoString): bool;

    /** @param array<string, string> $config */
    public function render(array $config): string;
}
