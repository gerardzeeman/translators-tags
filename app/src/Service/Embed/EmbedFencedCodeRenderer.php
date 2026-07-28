<?php

namespace App\Service\Embed;

use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Renderer\Block\FencedCodeRenderer;
use League\CommonMark\Node\Node;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\HtmlElement;

/**
 * Intercepts fenced code blocks whose info string matches a registered blog
 * embed module (e.g. ```bijbelvers, ```institutie) and renders them via that
 * module instead of as a plain <pre><code> block. Any other fenced block
 * (e.g. a normal ```php snippet) falls through to CommonMark's own renderer.
 */
class EmbedFencedCodeRenderer implements NodeRendererInterface
{
    private FencedCodeRenderer $defaultRenderer;

    /** @param BlogEmbedRendererInterface[] $embedRenderers */
    public function __construct(
        private readonly array             $embedRenderers,
        private readonly EmbedConfigParser $configParser,
    ) {
        $this->defaultRenderer = new FencedCodeRenderer();
    }

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): \Stringable
    {
        FencedCode::assertInstanceOf($node);

        $info = trim($node->getInfoWords()[0] ?? '');

        foreach ($this->embedRenderers as $embedRenderer) {
            if ($embedRenderer->supports($info)) {
                $config = $this->configParser->parse($node->getLiteral());
                return new HtmlElement('div', ['class' => 'blog-embed'], $embedRenderer->render($config));
            }
        }

        return $this->defaultRenderer->render($node, $childRenderer);
    }
}
