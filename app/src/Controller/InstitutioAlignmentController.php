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
 * Drag-based sentence-alignment editor for the Institutio corpus -- a
 * separate page from the plain per-row translate editor
 * (InstitutioTranslateController). Lets an editor manually move the word
 * boundary between adjacent rows, and merge/split rows, instead of relying
 * purely on align_sentences.py's LLM grouping. Gated by the same role as
 * the rest of the Institutio editing surface.
 */
#[Route('/institutio/bewerk')]
#[IsGranted('ROLE_EDIT_INSTITUTIO_TRNL')]
class InstitutioAlignmentController extends AbstractController
{
    public function __construct(
        private readonly InstitutioRepository $institutioRepository,
    ) {}

    #[Route('/{id<\d+>}/uitlijning', name: 'app_institutio_alignment', methods: ['GET'])]
    public function show(int $id): Response
    {
        $segment = $this->institutioRepository->getSegmentAlignmentForEdit($id);
        if ($segment === null) {
            throw $this->createNotFoundException(
                "Segment {$id} heeft nog geen vertaling om uit te lijnen."
            );
        }

        return $this->render('institutio/alignment_edit.html.twig', [
            'segment' => $segment,
        ]);
    }

    /**
     * Body (JSON): { rows: [{ la_start: int, words: string[] }, ...] }
     */
    #[Route('/{id<\d+>}/uitlijning', name: 'app_institutio_alignment_save', methods: ['POST'])]
    public function save(int $id, Request $request): JsonResponse
    {
        if (!$this->isCsrfTokenValid('institutio_alignment', $request->headers->get('X-CSRF-Token'))) {
            return $this->json(['success' => false, 'error' => 'Ongeldig formulierverzoek.'], 403);
        }

        $data = json_decode($request->getContent(), true);
        if (!is_array($data) || !isset($data['rows']) || !is_array($data['rows'])) {
            return $this->json(['success' => false, 'error' => 'Ongeldige aanvraag.'], 400);
        }

        $rows = [];
        foreach ($data['rows'] as $r) {
            if (!is_array($r) || !isset($r['la_start'], $r['words']) || !is_array($r['words'])) {
                return $this->json(['success' => false, 'error' => 'Ongeldige rijstructuur.'], 400);
            }
            $rows[] = [
                'la_start' => (int) $r['la_start'],
                'words'    => array_map('strval', $r['words']),
            ];
        }

        try {
            $alignmentDropped = $this->institutioRepository->saveSegmentAlignment($id, $rows);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            return $this->json(['success' => false, 'error' => $e->getMessage()], 400);
        }

        return $this->json([
            'success'           => true,
            'alignment_dropped' => $alignmentDropped,
        ]);
    }
}
