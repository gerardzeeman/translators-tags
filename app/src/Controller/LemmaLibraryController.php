<?php

namespace App\Controller;

use App\Repository\LemmaLibraryRepository;
use App\Service\MorphologyParser;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Standalone Latin lemma library: every lemma actually used across all
 * currently-ingested works (the 3 confessions + the Institutio), numbered
 * by combined-corpus frequency -- see db/migrate_add_lemma_number.sql and
 * scripts/build_lemma_library.py. Deliberately its own top-level section
 * (/lemmas), not nested under /institutie/..., since the whole point is
 * that it's no longer Institutio-specific.
 *
 * This does not replace InstitutioController's own /institutie/lemmas
 * pages, which stay as they are (Institutio-only, positional row number
 * rather than a stable library number) -- a deliberate choice not to
 * touch existing, working functionality as a side effect of adding this.
 */
class LemmaLibraryController extends AbstractController
{
    private const PER_PAGE = 100;
    private const OCCURRENCES_PER_PAGE = 50;

    public function __construct(
        private readonly LemmaLibraryRepository $repository,
        private readonly MorphologyParser $morphologyParser,
    ) {}

    #[Route('/lemmas/{page<\d+>}', name: 'app_lemma_library', defaults: ['page' => 1])]
    public function index(int $page, Request $request): Response
    {
        $search = trim((string) $request->query->get('q', '')) ?: null;

        $total = $this->repository->getLemmaCount($search);
        $lastPage = max(1, (int) ceil($total / self::PER_PAGE));
        if ($page < 1 || $page > $lastPage) {
            throw $this->createNotFoundException("Pagina {$page} bestaat niet.");
        }

        $offset = ($page - 1) * self::PER_PAGE;
        $lemmas = $this->repository->getLemmaPage(self::PER_PAGE, $offset, $search);

        return $this->render('lemma_library/index.html.twig', [
            'lemmas'    => $lemmas,
            'offset'    => $offset,
            'total'     => $total,
            'page'      => $page,
            'last_page' => $lastPage,
            'search'    => $search,
        ]);
    }

    #[Route('/lemmas/nummer/{number<\d+>}', name: 'app_lemma_library_by_number')]
    public function byNumber(int $number): Response
    {
        $lemma = $this->repository->getLemmaByNumber($number);
        if ($lemma === null) {
            throw $this->createNotFoundException("Lemma-nummer {$number} bestaat niet.");
        }
        return $this->redirectToRoute('app_lemma_library_detail', ['number' => 'l' . $number]);
    }

    /**
     * One lemma's full detail: library number, gloss, every attested
     * word-form with morphology + frequency, and every occurrence across
     * all works with a KWIC-style snippet -- the general-library
     * counterpart of InstitutioController::lemmaDetail(), spanning every
     * work instead of one.
     *
     * URL is keyed by library number (e.g. /lemmas/woord/l301), not the
     * lemma word itself -- stable even if a lemma's spelling is ever
     * corrected, and citable the way a Strong's number is.
     */
    #[Route('/lemmas/woord/{number<l\d+>}', name: 'app_lemma_library_detail')]
    public function detail(string $number, Request $request): Response
    {
        $lemma = $this->repository->getLemmaByNumber((int) substr($number, 1));
        if ($lemma === null) {
            throw $this->createNotFoundException("Lemma-nummer '{$number}' niet gevonden.");
        }

        $gloss = $this->repository->getLemmaGloss($lemma);
        if ($gloss !== null && $gloss['note'] !== null) {
            $gloss['note_html'] = $this->linkifyStrongsNumbers($gloss['note']);
        }
        $variants = $this->repository->getLemmaVariants([$lemma])[$lemma] ?? [];

        $variants = array_map(
            fn($v) => [
                'norm'              => $v['norm'],
                'freq'              => $v['freq'],
                'morph_description' => $this->morphologyParser->describeLatin($v['morph']),
            ],
            $variants
        );
        $totalFreq = array_sum(array_column($variants, 'freq'));

        $page = max(1, $request->query->getInt('page', 1));
        $lastPage = max(1, (int) ceil($totalFreq / self::OCCURRENCES_PER_PAGE));
        if ($page > $lastPage) {
            throw $this->createNotFoundException("Pagina {$page} bestaat niet.");
        }
        $offset = ($page - 1) * self::OCCURRENCES_PER_PAGE;
        $occurrences = array_map(
            fn($o) => [...$o, 'href' => $this->occurrenceHref($o)],
            $this->repository->getLemmaOccurrences($lemma, self::OCCURRENCES_PER_PAGE, $offset)
        );

        return $this->render('lemma_library/detail.html.twig', [
            'lemma'       => $lemma,
            'number'      => $number,
            'gloss'       => $gloss,
            'variants'    => $variants,
            'total_freq'  => $totalFreq,
            'occurrences' => $occurrences,
            'page'        => $page,
            'last_page'   => $lastPage,
        ]);
    }

    /**
     * A lemma_gloss.note can reference a Strong's number in passing (e.g. a
     * Greek word embedded in a Latin confession text, annotated with its
     * Strong's cross-reference) -- turns any "G1234"/"H1234" mention into a
     * link to that entry's page, escaping everything else first since this
     * is rendered with |raw in the template.
     */
    private function linkifyStrongsNumbers(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        return preg_replace_callback(
            '/\b([GH]\d+)\b/',
            fn($m) => '<a href="' . $this->generateUrl('app_strongs', ['number' => $m[1]]) . '">' . $m[1] . '</a>',
            $escaped
        );
    }

    /**
     * Best-effort deep link back to one occurrence's source page. Each
     * work has its own reading-page routing (book+chapter for the
     * Institutio, werk+chapter for the two chaptered confessions, a flat
     * werk-only listing for the NGB) -- null (no link, just the plain
     * reference text) is a perfectly fine fallback rather than guessing.
     */
    private function occurrenceHref(array $o): ?string
    {
        return match ($o['work_slug']) {
            'institutio-1559' => $o['book'] !== null
                ? $this->generateUrl('app_institutio_chapter', ['book' => $o['book'], 'chapter' => $o['chapter']]) . '#s' . $o['section']
                : $this->generateUrl('app_institutio_front'),
            'canones-dordraceni', 'heidelbergse-catechismus' => $o['chapter'] !== null
                ? $this->generateUrl('app_confession_chapter', ['werk' => $o['work_slug'], 'chapter' => $o['chapter']]) . '#art-' . $o['section']
                : null,
            'ngb' => $this->generateUrl('app_confession_toc', ['werk' => 'ngb']) . '#art-' . $o['section'],
            default => null,
        };
    }
}
