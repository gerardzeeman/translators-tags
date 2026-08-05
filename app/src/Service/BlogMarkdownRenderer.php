<?php

namespace App\Service;

use App\Entity\Blog;
use App\Service\Embed\BibleVerseEmbedRenderer;
use App\Service\Embed\EmbedConfigParser;
use App\Service\Embed\EmbedFencedCodeRenderer;
use App\Service\Embed\InstitutioEmbedRenderer;
use League\CommonMark\CommonMarkConverter;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\Table\TableExtension;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;

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
        private readonly BibleVerseEmbedRenderer $bibleVerseEmbedRenderer,
        InstitutioEmbedRenderer                  $institutioEmbedRenderer,
        private readonly RoleHierarchyInterface  $roleHierarchy,
    ) {
        $this->converter = new CommonMarkConverter([
            'html_input'         => 'strip',
            'allow_unsafe_links' => false,
        ]);

        // CommonMarkConverter only wires up the core spec extension -- pipe
        // tables are a GFM extension, not core CommonMark, so `| a | b |`
        // rendered as a literal paragraph without this.
        $this->converter->getEnvironment()->addExtension(new TableExtension());

        $this->converter->getEnvironment()->addRenderer(
            FencedCode::class,
            new EmbedFencedCodeRenderer([$this->bibleVerseEmbedRenderer, $institutioEmbedRenderer], new EmbedConfigParser()),
            100
        );
    }

    public function render(Blog $blog): string
    {
        // Resolved against the blog's AUTHOR, not whoever is currently viewing
        // the page -- see BibleVerseEmbedRenderer::$authorHasHsvAccess for why.
        // getReachableRoleNames() applies role_hierarchy inheritance (e.g. an
        // author with ROLE_ADMIN counts, since that implies ROLE_VIEWER_HSV).
        $authorRoles = $this->roleHierarchy->getReachableRoleNames($blog->getAuthor()->getRoles());
        $this->bibleVerseEmbedRenderer->setAuthorHasHsvAccess(in_array('ROLE_VIEWER_HSV', $authorRoles, true));

        return (string) $this->converter->convert($blog->getContentMd());
    }
}
