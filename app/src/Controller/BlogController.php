<?php

namespace App\Controller;

use App\Entity\Blog;
use App\Repository\BlogRepository;
use App\Service\BlogSlugGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

class BlogController extends AbstractController
{
    use TargetPathTrait;

    private const MAX_IMAGE_SIZE = 5 * 1024 * 1024; // 5 MB
    private const ALLOWED_IMAGE_MIME_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    public function __construct(
        private readonly BlogRepository    $blogRepository,
        private readonly BlogSlugGenerator $slugGenerator,
        #[Autowire('%kernel.project_dir%')]
        private readonly string            $projectDir,
    ) {}

    #[Route('/blog/', name: 'app_blog_index')]
    public function index(): Response
    {
        $includeLoggedIn = $this->getUser() !== null;

        return $this->render('blog/index.html.twig', [
            'blogs' => $this->blogRepository->findPublishedForAudience($includeLoggedIn),
        ]);
    }

    #[Route('/blog/maken/', name: 'app_blog_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_BLOGGER')]
    public function new(Request $request, EntityManagerInterface $em): Response
    {
        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('blog_new', $request->request->get('_csrf_token'))) {
                $error = 'Ongeldig formulierverzoek.';
            } else {
                $title = trim($request->request->get('title', ''));

                if (mb_strlen($title) < 2) {
                    $error = 'Titel moet minimaal 2 tekens bevatten.';
                }

                if (!$error) {
                    $blog = new Blog();
                    $blog->setTitle($title);
                    $blog->setSlug($this->slugGenerator->generate($title));
                    $blog->setContentMd((string) $request->request->get('content_md', ''));
                    $blog->setVisibility(
                        $request->request->get('visibility') === Blog::VISIBILITY_LOGGED_IN
                            ? Blog::VISIBILITY_LOGGED_IN
                            : Blog::VISIBILITY_PUBLIC
                    );
                    $blog->setAuthor($this->getUser());

                    $em->persist($blog);
                    $em->flush();

                    $this->addFlash('success', 'Concept aangemaakt.');
                    return $this->redirectToRoute('app_blog_edit', ['slug' => $blog->getSlug()]);
                }
            }
        }

        return $this->render('blog/new.html.twig', ['error' => $error]);
    }

    #[Route('/blog/{slug}/bewerken/', name: 'app_blog_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_BLOGGER')]
    public function edit(string $slug, Request $request, EntityManagerInterface $em): Response
    {
        $blog = $this->findBlogOrFail($slug);
        $this->denyAccessUnlessOwnerOrAdmin($blog);

        $error = null;

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('blog_edit_' . $blog->getId(), $request->request->get('_csrf_token'))) {
                $error = 'Ongeldig formulierverzoek.';
            } else {
                $title = trim($request->request->get('title', ''));
                $newSlug = trim($request->request->get('slug', ''));

                if (mb_strlen($title) < 2) {
                    $error = 'Titel moet minimaal 2 tekens bevatten.';
                } elseif ($newSlug !== $blog->getSlug() && $this->blogRepository->slugExists($newSlug)) {
                    $error = 'Deze URL is al in gebruik door een andere blog.';
                } elseif (!preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/', $newSlug)) {
                    $error = 'URL mag alleen kleine letters, cijfers en koppeltekens bevatten.';
                }

                if (!$error) {
                    $blog->setTitle($title);
                    $blog->setSlug($newSlug);
                    $blog->setContentMd((string) $request->request->get('content_md', ''));
                    $blog->setVisibility(
                        $request->request->get('visibility') === Blog::VISIBILITY_LOGGED_IN
                            ? Blog::VISIBILITY_LOGGED_IN
                            : Blog::VISIBILITY_PUBLIC
                    );
                    $blog->touch();
                    $em->flush();

                    $this->addFlash('success', 'Blog opgeslagen.');
                    return $this->redirectToRoute('app_blog_edit', ['slug' => $blog->getSlug()]);
                }
            }
        }

        return $this->render('blog/edit.html.twig', ['blog' => $blog, 'error' => $error]);
    }

    #[Route('/blog/{slug}/publiceren/', name: 'app_blog_publish', methods: ['POST'])]
    #[IsGranted('ROLE_BLOGGER')]
    public function publish(string $slug, Request $request, EntityManagerInterface $em): Response
    {
        $blog = $this->findBlogOrFail($slug);
        $this->denyAccessUnlessOwnerOrAdmin($blog);

        if (!$this->isCsrfTokenValid('blog_publish_' . $blog->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Ongeldig formulierverzoek.');
            return $this->redirectToRoute('app_blog_edit', ['slug' => $blog->getSlug()]);
        }

        if ($request->request->get('action') === 'unpublish') {
            $blog->unpublish();
            $this->addFlash('success', 'Blog is weer een concept.');
        } else {
            $blog->publish();
            $this->addFlash('success', 'Blog gepubliceerd.');
        }
        $em->flush();

        return $this->redirectToRoute('app_blog_edit', ['slug' => $blog->getSlug()]);
    }

    #[Route('/blog/afbeeldingen/upload', name: 'app_blog_image_upload', methods: ['POST'])]
    #[IsGranted('ROLE_BLOGGER')]
    public function uploadImage(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('blog_image_upload', $request->request->get('_csrf_token'))) {
            return $this->json(['error' => 'Ongeldig formulierverzoek.'], 403);
        }

        $file = $request->files->get('afbeelding');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->json(['error' => 'Geen geldig bestand ontvangen.'], 400);
        }

        if ($file->getSize() > self::MAX_IMAGE_SIZE) {
            return $this->json(['error' => 'Bestand is groter dan 5 MB.'], 400);
        }

        // Mime type is guessed from the file's actual content (finfo), not the
        // client-supplied filename/Content-Type -- both are untrusted input.
        $extension = self::ALLOWED_IMAGE_MIME_TYPES[$file->getMimeType()] ?? null;
        if ($extension === null) {
            return $this->json(['error' => 'Alleen JPEG, PNG, WEBP of GIF-afbeeldingen zijn toegestaan.'], 400);
        }

        $subDir    = date('Y/m');
        $filename  = bin2hex(random_bytes(16)) . '.' . $extension;
        $targetDir = $this->projectDir . '/public/uploads/blog/' . $subDir;

        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            return $this->json(['error' => 'Kon de afbeelding niet opslaan.'], 500);
        }

        $file->move($targetDir, $filename);

        return $this->json(['url' => "/uploads/blog/{$subDir}/{$filename}"]);
    }

    #[Route('/blog/{slug}/', name: 'app_blog_show')]
    public function show(string $slug, Request $request): Response
    {
        $blog = $this->findBlogOrFail($slug);
        $isOwnerOrAdmin = $this->isOwnerOrAdmin($blog);

        if ($blog->isDraft() && !$isOwnerOrAdmin) {
            throw $this->createNotFoundException('Blog niet gevonden.');
        }

        if ($blog->isPublished() && !$blog->isPublic() && $this->getUser() === null) {
            // Regenerate the URL server-side (route + params) rather than trusting
            // $request->getUri(), which is partly built from the client-supplied
            // Host header -- without framework.trusted_hosts configured, a spoofed
            // Host could otherwise turn this into a post-login open redirect.
            $this->saveTargetPath($request->getSession(), 'main', $this->generateUrl('app_blog_show', ['slug' => $slug]));
            return $this->redirectToRoute('app_login');
        }

        return $this->render('blog/show.html.twig', [
            'blog'      => $blog,
            'is_draft_preview' => $blog->isDraft(),
        ]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function findBlogOrFail(string $slug): Blog
    {
        $blog = $this->blogRepository->findBySlug($slug);
        if (!$blog) {
            throw $this->createNotFoundException('Blog niet gevonden.');
        }
        return $blog;
    }

    private function isOwnerOrAdmin(Blog $blog): bool
    {
        $user = $this->getUser();
        if (!$user) {
            return false;
        }
        return $this->isGranted('ROLE_ADMIN') || $blog->getAuthor() === $user;
    }

    private function denyAccessUnlessOwnerOrAdmin(Blog $blog): void
    {
        if (!$this->isOwnerOrAdmin($blog)) {
            throw $this->createAccessDeniedException('Je mag alleen je eigen blogs bewerken.');
        }
    }
}
