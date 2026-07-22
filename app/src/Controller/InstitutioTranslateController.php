<?php

namespace App\Controller;

use App\Repository\InstitutioRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Manual translation editor for the Institutio corpus -- gated by
 * ROLE_EDIT_INSTITUTIO_TRNL (same pattern as StrongsTranslateController for
 * the Bible corpus). Edits the whole segment's Dutch translation at once
 * (not an individual sentence): the sentence-by-sentence view on /institutio
 * is derived from this text by the LLM alignment step, not stored
 * separately, so there's nothing narrower to edit in place.
 */
#[Route('/institutio/bewerk')]
#[IsGranted('ROLE_EDIT_INSTITUTIO_TRNL')]
class InstitutioTranslateController extends AbstractController
{
    public function __construct(
        private readonly InstitutioRepository $institutioRepository,
    ) {}

    #[Route('/{id<\d+>}', name: 'app_institutio_translate', methods: ['GET'])]
    public function show(int $id): Response
    {
        $segment = $this->institutioRepository->getSegmentForEdit($id);
        if ($segment === null) {
            throw $this->createNotFoundException("Segment {$id} niet gevonden.");
        }

        return $this->render('institutio/translate.html.twig', [
            'segment' => $segment,
            'saved'   => false,
        ]);
    }

    #[Route('/{id<\d+>}', name: 'app_institutio_translate_save', methods: ['POST'])]
    public function save(int $id, Request $request): Response
    {
        $segment = $this->institutioRepository->getSegmentForEdit($id);
        if ($segment === null) {
            throw $this->createNotFoundException("Segment {$id} niet gevonden.");
        }

        if (!$this->isCsrfTokenValid('institutio_translate', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Ongeldig formulierverzoek.');
            return $this->redirectToRoute('app_institutio_translate', ['id' => $id]);
        }

        $this->institutioRepository->saveSegmentTranslation(
            $id,
            (string) $request->request->get('text_nl', '')
        );

        // Re-fetch after save so the editor shows the stored value
        $segment = $this->institutioRepository->getSegmentForEdit($id);

        return $this->render('institutio/translate.html.twig', [
            'segment' => $segment,
            'saved'   => true,
        ]);
    }
}
