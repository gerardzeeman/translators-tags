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
 * the Bible corpus). Two edit modes, chosen by whether the segment already
 * has a sentence-level alignment (see InstitutioRepository::getSegmentForEdit):
 *   - aligned:     one textarea per aligned row, updating only that row's
 *                  Dutch text (saveSegmentRowTranslations) -- la_start isn't
 *                  touched, so the alignment survives the edit.
 *   - not aligned: a single whole-block textarea (saveSegmentTranslation),
 *                  which is the only option before any alignment exists.
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

        if ($segment['rows']) {
            // Already sentence-aligned: edit each row's Dutch text in place
            // so the existing alignment (keyed off la_start, untouched here)
            // survives the edit -- see saveSegmentRowTranslations.
            $alignmentDropped = $this->institutioRepository->saveSegmentRowTranslations(
                $id,
                $request->request->all('row_nl')
            );
        } else {
            $alignmentDropped = $this->institutioRepository->saveSegmentTranslation(
                $id,
                (string) $request->request->get('text_nl', '')
            );
        }

        if ($alignmentDropped) {
            $this->addFlash(
                'warning',
                'De woord-uitlijning (fase 4) voor dit segment is verwijderd omdat de vertaling '
                . 'is aangepast en de oude uitlijning niet meer bij de nieuwe tekst past. '
                . 'Voer align_segments.py opnieuw uit voor dit segment om de uitlijning te herstellen.'
            );
        }

        // Re-fetch after save so the editor shows the stored value
        $segment = $this->institutioRepository->getSegmentForEdit($id);

        return $this->render('institutio/translate.html.twig', [
            'segment' => $segment,
            'saved'   => true,
        ]);
    }
}
