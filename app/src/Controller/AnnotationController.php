<?php

namespace App\Controller;

use App\Entity\HebrewWord;
use App\Entity\GreekWord;
use App\Entity\LinkConfidence;
use App\Entity\TranslationWord;
use App\Entity\WordLink;
use App\Repository\HebrewWordRepository;
use App\Repository\GreekWordRepository;
use App\Repository\TranslationWordRepository;
use App\Repository\WordLinkRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/annotation')]
#[IsGranted('ROLE_LINKER')]
class AnnotationController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface  $em,
        private readonly HebrewWordRepository    $hebrewRepo,
        private readonly GreekWordRepository     $greekRepo,
        private readonly TranslationWordRepository $twRepo,
        private readonly WordLinkRepository      $linkRepo,
    ) {}

    /**
     * Create or confirm a word link.
     * POST /api/annotation/link
     * Body (JSON): { source_lang, source_word_id, translation_word_id, notes? }
     */
    #[Route('/link', name: 'api_annotation_link', methods: ['POST'])]
    public function createLink(Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('linking_api', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Invalid request.'], 403);
        }

        $data = json_decode($request->getContent(), true);

        $lang   = $data['source_lang']          ?? null;
        $srcId  = (int) ($data['source_word_id']         ?? 0);
        $twId   = (int) ($data['translation_word_id']    ?? 0);
        $notes  = $data['notes']                 ?? null;

        if (!in_array($lang, ['HE', 'GR'], true) || !$srcId || !$twId) {
            return $this->json(['error' => 'Missing or invalid parameters.'], 400);
        }

        // Load entities
        $sourceWord = $lang === 'HE'
            ? $this->hebrewRepo->find($srcId)
            : $this->greekRepo->find($srcId);

        $translationWord = $this->twRepo->find($twId);

        if (!$sourceWord || !$translationWord) {
            return $this->json(['error' => 'Source or translation word not found.'], 404);
        }

        // Check if an existing link already connects these two words
        $existing = $this->findExistingLink($lang, $srcId, $twId);

        if ($existing) {
            // Upsert a manual confidence record on the existing link
            $this->upsertManualConfidence($existing, $notes);
            $linkId = $existing->getId();
        } else {
            // Create a new link
            $link = new WordLink();
            $link->setSourceLanguage($lang);
            $link->setTranslationWord($translationWord);

            if ($lang === 'HE') {
                $link->setHebrewWord($sourceWord);
            } else {
                $link->setGreekWord($sourceWord);
            }

            $this->em->persist($link);
            $this->em->flush(); // get the ID

            $this->upsertManualConfidence($link, $notes);
            $linkId = $link->getId();
        }

        $this->em->flush();

        return $this->json([
            'success' => true,
            'link_id' => $linkId,
        ]);
    }

    /**
     * Delete a word link (manual correction: unlink).
     * DELETE /api/annotation/link/{id}
     */
    #[Route('/link/{id}', name: 'api_annotation_unlink', methods: ['DELETE'])]
    public function deleteLink(Request $request, int $id): JsonResponse
    {
        if (!$this->isCsrfTokenValid('linking_api', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['error' => 'Invalid request.'], 403);
        }

        $link = $this->linkRepo->find($id);
        if (!$link) {
            return $this->json(['error' => 'Link not found.'], 404);
        }

        $this->em->remove($link);
        $this->em->flush();

        return $this->json(['success' => true]);
    }

    /**
     * Get all links for a given source word.
     * GET /api/annotation/word/{lang}/{id}
     */
    #[Route('/word/{lang}/{id}', name: 'api_annotation_word_links', methods: ['GET'])]
    public function wordLinks(string $lang, int $id): JsonResponse
    {
        if (!in_array($lang, ['HE', 'GR'], true)) {
            return $this->json(['error' => 'Invalid language code.'], 400);
        }

        $links = $lang === 'HE'
            ? $this->linkRepo->findBy(['hebrewWord' => $id])
            : $this->linkRepo->findBy(['greekWord'  => $id]);

        $result = array_map(function (WordLink $link) {
            $tw = $link->getTranslationWord();
            return [
                'link_id'           => $link->getId(),
                'translation_word'  => [
                    'id'        => $tw->getId(),
                    'word_text' => $tw->getWordText(),
                    'position'  => $tw->getWordPosition(),
                ],
                'best_score' => $link->getBestScore(),
                'is_manual'  => $link->isManual(),
                'confidences' => array_map(fn($c) => [
                    'method'     => $c->getMethod(),
                    'score'      => $c->getScore(),
                    'created_at' => $c->getCreatedAt()->format('Y-m-d H:i'),
                    'notes'      => $c->getNotes(),
                ], $link->getConfidences()->toArray()),
            ];
        }, $links);

        return $this->json($result);
    }

    // ── private helpers ───────────────────────────────────────────────────────

    private function findExistingLink(string $lang, int $srcId, int $twId): ?WordLink
    {
        $criteria = ['translationWord' => $twId];
        if ($lang === 'HE') {
            $criteria['hebrewWord'] = $srcId;
        } else {
            $criteria['greekWord'] = $srcId;
        }
        return $this->linkRepo->findOneBy($criteria);
    }

    private function upsertManualConfidence(WordLink $link, ?string $notes): void
    {
        // Check if manual confidence already exists
        foreach ($link->getConfidences() as $conf) {
            if ($conf->getMethod() === 'manual') {
                $conf->setScore(1.0);
                $conf->setNotes($notes);
                return;
            }
        }

        // Create new manual confidence
        $conf = new LinkConfidence($link, 'manual');
        $conf->setScore(1.0);
        $conf->setNotes($notes);
        $this->em->persist($conf);
    }
}
