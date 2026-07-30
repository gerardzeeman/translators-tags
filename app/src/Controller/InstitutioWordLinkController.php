<?php

namespace App\Controller;

use App\Repository\InstitutioRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Word-level linking screen for the Institutio corpus -- styled and behaved
 * the same as the Bible corpus's Hebrew/Greek<->Dutch word-linking screen
 * (LinkingController + word_linker_controller.js): click a Latin word, then
 * click Dutch words to toggle-link them. Reuses that same Stimulus
 * controller unchanged (data-tw-id there is just an opaque string
 * identifier, so a Dutch word's character offset works as well as the
 * Bible corpus's real translation_word id) and the same .src-word,
 * .nl-word, .method-border- and .method-underline- CSS.
 *
 * Reviews/corrects the `alignment` table SimAlign populates in phase 4
 * (align_segments.py) -- this is fase 5 (Annotatie-UI) from the project
 * dossier.
 */
#[Route('/institutie/bewerk')]
#[IsGranted('ROLE_EDIT_INSTITUTIO_TRNL')]
class InstitutioWordLinkController extends AbstractController
{
    public function __construct(
        private readonly InstitutioRepository $institutioRepository,
    ) {}

    #[Route('/{id<\d+>}/woordkoppeling', name: 'app_institutio_wordlink', methods: ['GET'])]
    public function show(int $id): Response
    {
        $segment = $this->institutioRepository->getSegmentWordLinksForEdit($id);
        if ($segment === null) {
            throw $this->createNotFoundException(
                "Segment {$id} heeft nog geen vertaling om te koppelen."
            );
        }

        return $this->render('institutio/wordlink_edit.html.twig', [
            'segment' => $segment,
        ]);
    }

    /**
     * Body (JSON): { source_word_id: int, tw_ids: int[] } -- word_linker_controller.js
     * also sends `lang` and `translation_id`, unused here: there's only one
     * source language, and the segment id in the URL already scopes it.
     */
    #[Route('/{id<\d+>}/woordkoppeling/opslaan', name: 'app_institutio_wordlink_save', methods: ['POST'])]
    public function save(int $id, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('institutio_wordlink', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['success' => false, 'error' => 'Ongeldig formulierverzoek.'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !isset($data['source_word_id'], $data['tw_ids']) || !is_array($data['tw_ids'])) {
            return $this->json(['success' => false, 'error' => 'Ongeldige aanvraag.'], 400);
        }

        $tokenId = (int) $data['source_word_id'];
        $targetStarts = array_map('intval', $data['tw_ids']);

        try {
            $this->institutioRepository->saveManualWordLink($id, $tokenId, $targetStarts);
        } catch (\InvalidArgumentException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return $this->json([
            'success' => true,
            'linked'  => count($targetStarts),
            'empty'   => count($targetStarts) === 0,
        ]);
    }
}
