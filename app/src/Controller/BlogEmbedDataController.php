<?php

namespace App\Controller;

use App\Repository\BookRepository;
use App\Repository\InstitutioRepository;
use App\Repository\PassageRepository;
use App\Repository\TranslationRepository;
use App\Service\Embed\InstitutioEmbedRenderer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * JSON data endpoints for the blog editor's embed-picker dialogs (dropdowns
 * for boek/hoofdstuk/vers, word-range chips). Not a general-purpose API --
 * scoped to exactly what the bijbelvers/institutie picker UI needs.
 */
#[Route('/blog/embed-data')]
#[IsGranted('ROLE_BLOGGER')]
class BlogEmbedDataController extends AbstractController
{
    public function __construct(
        private readonly BookRepository        $bookRepository,
        private readonly PassageRepository     $passageRepository,
        private readonly TranslationRepository $translationRepository,
        private readonly InstitutioRepository  $institutioRepository,
    ) {}

    #[Route('/boeken', name: 'app_blog_embed_data_boeken', methods: ['GET'])]
    public function boeken(): JsonResponse
    {
        $books = array_map(fn($b) => [
            'nameNl'       => $b->getNameNl(),
            'testament'    => $b->getTestament(),
            'chapterCount' => $b->getChapterCount(),
        ], $this->bookRepository->findAllOrderedById());

        return $this->json($books);
    }

    #[Route('/verzen', name: 'app_blog_embed_data_verzen', methods: ['GET'])]
    public function verzen(Request $request): JsonResponse
    {
        $boek      = (string) $request->query->get('boek', '');
        $hoofdstuk = (int) $request->query->get('hoofdstuk', 0);

        $book = $this->bookRepository->findByNameNl($boek);
        if (!$book) {
            return $this->json(['error' => 'Boek niet gevonden.'], 404);
        }

        $counts = $this->passageRepository->getChapterVerseCounts($book->getId());
        foreach ($counts as $row) {
            if ((int) $row['chapter'] === $hoofdstuk) {
                return $this->json(['verseCount' => (int) $row['verse_count']]);
            }
        }

        return $this->json(['verseCount' => 0]);
    }

    #[Route('/woorden', name: 'app_blog_embed_data_woorden', methods: ['GET'])]
    public function woorden(Request $request): JsonResponse
    {
        $boek      = (string) $request->query->get('boek', '');
        $hoofdstuk = (int) $request->query->get('hoofdstuk', 0);
        $vers      = (int) $request->query->get('vers', 0);

        $book = $this->bookRepository->findByNameNl($boek);
        if (!$book) {
            return $this->json(['error' => 'Boek niet gevonden.'], 404);
        }

        $svTranslation = $this->translationRepository->findByCode('SV');
        $passage = $this->passageRepository->fetchPassage($book->getId(), $hoofdstuk, $vers, $svTranslation?->getId() ?? 0);

        $words = array_map(fn($w, $i) => [
            'position' => $i + 1,
            'text'     => $w['word_text'],
        ], $passage['source_words'], array_keys($passage['source_words']));

        return $this->json(['words' => $words, 'testament' => $passage['testament']]);
    }

    #[Route('/vertalingen', name: 'app_blog_embed_data_vertalingen', methods: ['GET'])]
    public function vertalingen(): JsonResponse
    {
        $translations = array_map(
            fn($t) => ['code' => $t->getCode(), 'abbreviation' => $t->getAbbreviation()],
            $this->translationRepository->findAllOrderedById()
        );

        return $this->json($translations);
    }

    #[Route('/institutie/structuur', name: 'app_blog_embed_data_institutie_structuur', methods: ['GET'])]
    public function institutieStructuur(): JsonResponse
    {
        $books = [];
        foreach ($this->institutioRepository->getBookChapterCounts() as $book => $chapterCount) {
            $books[] = ['book' => $book, 'chapterCount' => $chapterCount];
        }

        return $this->json([
            'books'          => $books,
            'hasFrontMatter' => $this->institutioRepository->hasFrontMatter(),
        ]);
    }

    #[Route('/institutie/secties', name: 'app_blog_embed_data_institutie_secties', methods: ['GET'])]
    public function institutieSecties(Request $request): JsonResponse
    {
        $boek      = (string) $request->query->get('boek', '');
        $hoofdstuk = (int) $request->query->get('hoofdstuk', 0);

        $data = $boek === 'front'
            ? $this->institutioRepository->getFrontMatter()
            : $this->institutioRepository->getChapter((int) $boek, $hoofdstuk);

        $sections = array_map(fn($s) => [
            'section' => $s['section'],
            'preview' => mb_strlen($s['text']) > 60 ? mb_substr($s['text'], 0, 60) . '…' : $s['text'],
        ], $data['sections']);

        return $this->json(['sections' => $sections]);
    }

    #[Route('/institutie/zinnen', name: 'app_blog_embed_data_institutie_zinnen', methods: ['GET'])]
    public function institutieZinnen(Request $request): JsonResponse
    {
        $referentie = (string) $request->query->get('referentie', '');

        $segment = $this->institutioRepository->findSegmentByRef($referentie);
        if (!$segment) {
            return $this->json(['error' => 'Referentie niet gevonden.'], 404);
        }

        $sentenceRows = InstitutioEmbedRenderer::sentenceRows($segment);
        $rows = array_map(fn($r, $i) => [
            'index'   => $i + 1,
            'la_text' => $r['la_text'],
            'nl_text' => $r['nl_text'],
        ], $sentenceRows, array_keys($sentenceRows));

        return $this->json(['rows' => $rows]);
    }
}
