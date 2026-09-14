<?php

namespace App\Controller;

use App\Repository\ConfessionRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public read-only browsing for the confessional documents ingested via the
 * Institutio pipeline's generic work/segment/token schema (Canones
 * Dordraceni, and future works). See ConfessionRepository's docblock for why
 * this is a separate controller/repository from InstitutioController rather
 * than a generalization of it.
 *
 * No Dutch translation exists yet for these works (see PROJECTDOSSIER.md),
 * so every page here shows Latin only, with the same word-hover lemma-gloss
 * popup the Institutio pages use (import institutio/_macros.html.twig).
 */
class ConfessionController extends AbstractController
{
    /**
     * Slug => display metadata for every confession work this controller
     * knows how to browse. A work only appears here once it actually has
     * ingested segments -- listing a not-yet-ingested slug would just 404
     * every link on the index page.
     */
    private const WORKS = [
        'canones-dordraceni' => [
            'title'    => 'Dordtse Leerregels',
            'subtitle' => 'Canones Synodi Dordrechtanae, 1619 — Latijnse tekst (oertaal van de synode)',
            'type'     => 'chapters',
            'voorwoord_prefix' => 'Canones voorwoord',
            'besluit_prefix'   => 'Canones besluit',
        ],
        'ngb' => [
            'title'    => 'Nederlandse Geloofsbelijdenis',
            'subtitle' => 'Confessio Belgica, 1561/1619 — Latijnse vertaling (Hommius, herzien door de Synode van Dordrecht)',
            'type'     => 'flat',
            'voorwoord_prefix' => null,
            'besluit_prefix'   => null,
        ],
        'heidelbergse-catechismus' => [
            'title'    => 'Heidelbergse Catechismus',
            'subtitle' => 'Catechesis Palatina, 1563 — Latijnse vertaling (Lagus & Pithopoeus)',
            'type'     => 'chapters',
            'voorwoord_prefix' => 'HC voorwoord',
            'besluit_prefix'   => null,
        ],
    ];

    public function __construct(
        private readonly ConfessionRepository $repository,
    ) {}

    #[Route('/belijdenisgeschriften', name: 'app_confession_index')]
    public function index(): Response
    {
        $works = [];
        foreach (self::WORKS as $slug => $meta) {
            if ($this->repository->getWork($slug) !== null) {
                $works[] = ['slug' => $slug, ...$meta];
            }
        }

        return $this->render('confession/index.html.twig', ['works' => $works]);
    }

    #[Route('/belijdenisgeschriften/{werk}', name: 'app_confession_toc')]
    public function toc(string $werk): Response
    {
        $meta = self::WORKS[$werk] ?? null;
        $work = $meta !== null ? $this->repository->getWork($werk) : null;
        if ($work === null) {
            throw $this->createNotFoundException('Onbekend werk.');
        }

        if ($meta['type'] === 'flat') {
            return $this->render('confession/flat.html.twig', [
                'werk'     => $werk,
                'title'    => $meta['title'],
                'subtitle' => $meta['subtitle'],
                'articles' => $this->withWordParts($this->repository->getFlatArticles($werk)),
            ]);
        }

        return $this->render('confession/toc.html.twig', [
            'werk'          => $werk,
            'title'         => $meta['title'],
            'subtitle'      => $meta['subtitle'],
            'chapters'      => $this->repository->getChapters($werk),
            'has_voorwoord' => $meta['voorwoord_prefix'] !== null
                && $this->repository->hasUnnumberedSection($werk, $meta['voorwoord_prefix']),
            'has_besluit'   => $meta['besluit_prefix'] !== null
                && $this->repository->hasUnnumberedSection($werk, $meta['besluit_prefix']),
        ]);
    }

    #[Route('/belijdenisgeschriften/{werk}/voorwoord', name: 'app_confession_front')]
    public function front(string $werk): Response
    {
        $this->assertKnownWork($werk);
        $prefix = self::WORKS[$werk]['voorwoord_prefix'] ?? null;
        $segments = $prefix !== null ? $this->repository->getUnnumberedSection($werk, $prefix) : [];
        if (!$segments) {
            throw $this->createNotFoundException('Voorwoord niet gevonden.');
        }
        return $this->render('confession/section.html.twig', [
            'werk' => $werk, 'title' => self::WORKS[$werk]['title'], 'heading' => 'Voorwoord',
            'segments' => $this->withWordParts($segments),
        ]);
    }

    #[Route('/belijdenisgeschriften/{werk}/besluit', name: 'app_confession_back')]
    public function back(string $werk): Response
    {
        $this->assertKnownWork($werk);
        $prefix = self::WORKS[$werk]['besluit_prefix'] ?? null;
        $segments = $prefix !== null ? $this->repository->getUnnumberedSection($werk, $prefix) : [];
        if (!$segments) {
            throw $this->createNotFoundException('Besluit niet gevonden.');
        }
        return $this->render('confession/section.html.twig', [
            'werk' => $werk, 'title' => self::WORKS[$werk]['title'], 'heading' => 'Besluit',
            'segments' => $this->withWordParts($segments),
        ]);
    }

    #[Route('/belijdenisgeschriften/{werk}/{chapter<\d+>}', name: 'app_confession_chapter')]
    public function chapter(string $werk, int $chapter): Response
    {
        $this->assertKnownWork($werk);
        $data = $this->repository->getChapter($werk, $chapter);
        if (!$data['articles'] && !$data['rejections']) {
            throw $this->createNotFoundException('Hoofdstuk niet gevonden.');
        }

        return $this->render('confession/chapter.html.twig', [
            'werk'       => $werk,
            'title'      => self::WORKS[$werk]['title'],
            'chapter'    => $chapter,
            'heading'    => $data['heading'],
            'articles'   => $this->withWordParts($data['articles']),
            'rejections' => $this->withWordParts($data['rejections']),
            'nav'        => $this->repository->getAdjacentChapters($werk, $chapter),
        ]);
    }

    // priority: 10 -- otherwise this and saveText() below are shadowed by the
    // {werk}/{chapter<\d+>} route above, which happily matches "bewerk/5" as
    // werk="bewerk", chapter=5 (confirmed by browser-testing: without this,
    // GET /belijdenisgeschriften/bewerk/5 404s from assertKnownWork('bewerk')
    // instead of reaching this method).
    #[Route('/belijdenisgeschriften/bewerk/{id<\d+>}', name: 'app_confession_edit_text', methods: ['GET'], priority: 10)]
    public function editText(int $id): Response
    {
        $segment = $this->repository->getSegmentText($id);
        if ($segment === null) {
            throw $this->createNotFoundException('Segment niet gevonden.');
        }
        return $this->render('confession/edit_text.html.twig', [
            'segment' => $segment,
            'history' => $this->repository->getTextCorrectionHistory($id),
        ]);
    }

    #[Route('/belijdenisgeschriften/bewerk/{id<\d+>}', name: 'app_confession_save_text', methods: ['POST'], priority: 10)]
    public function saveText(int $id, Request $request): Response
    {
        $segment = $this->repository->getSegmentText($id);
        if ($segment === null) {
            throw $this->createNotFoundException('Segment niet gevonden.');
        }

        if (!$this->isCsrfTokenValid('confession_edit_text', (string) $request->request->get('_csrf_token'))) {
            throw $this->createAccessDeniedException('Ongeldig CSRF-token.');
        }

        $newText = trim((string) $request->request->get('text_la'));
        $note = trim((string) $request->request->get('note')) ?: null;
        if ($newText === '') {
            $this->addFlash('error', 'De tekst mag niet leeg zijn.');
            return $this->redirectToRoute('app_confession_edit_text', ['id' => $id]);
        }

        $user = $this->getUser();
        $this->repository->saveTextCorrection($id, $newText, $user?->getId(), $note);

        $this->addFlash('success', 'Correctie opgeslagen. Dit segment moet opnieuw getokeniseerd worden (tokenize_latin.py) voordat woord-hover weer klopt.');
        return $this->redirectToRoute('app_confession_edit_text', ['id' => $id]);
    }

    private function assertKnownWork(string $werk): void
    {
        if (!isset(self::WORKS[$werk]) || $this->repository->getWork($werk) === null) {
            throw $this->createNotFoundException('Onbekend werk.');
        }
    }

    /**
     * @param array<int, array{text_la: string, tokens: array}> $segments
     * @return array<int, array{id: int, section: int, kind: ?string, heading: ?string, parts: array}>
     */
    private function withWordParts(array $segments): array
    {
        return array_map(
            fn($s) => [
                'id'      => $s['id'],
                'section' => $s['section'],
                'kind'    => $s['kind'] ?? null,
                'heading' => $s['heading'] ?? null,
                'parts'   => $this->repository->splitTextIntoWordParts($s['text_la'], $s['tokens']),
            ],
            $segments
        );
    }
}
