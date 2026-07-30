<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\InstitutioProposalRepository;
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
 *   - aligned:     one textarea per aligned row.
 *   - not aligned: a single whole-block textarea.
 *
 * Submitting no longer writes the translation directly -- it creates one
 * translation_proposal per changed row (or one for the whole segment, in
 * the not-aligned case), each carrying the submitted reason. The text is
 * only actually applied once a reviewer approves it (see
 * InstitutioProposalController::approve(), which reuses
 * InstitutioRepository::saveSegmentTranslation()/saveSegmentRowTranslations()
 * unchanged for that). A row (or the whole segment) that already has an
 * active proposal renders read-only here -- see
 * InstitutioProposalRepository::getActiveProposalsForSegment() -- since a
 * second concurrent proposal for the same target isn't allowed.
 */
#[Route('/institutie/bewerk')]
#[IsGranted('ROLE_EDIT_INSTITUTIO_TRNL')]
class InstitutioTranslateController extends AbstractController
{
    public function __construct(
        private readonly InstitutioRepository $institutioRepository,
        private readonly InstitutioProposalRepository $proposalRepository,
    ) {}

    #[Route('/{id<\d+>}', name: 'app_institutio_translate', methods: ['GET'])]
    public function show(int $id): Response
    {
        $segment = $this->institutioRepository->getSegmentForEdit($id);
        if ($segment === null) {
            throw $this->createNotFoundException("Segment {$id} niet gevonden.");
        }

        return $this->render('institutio/translate.html.twig', [
            'segment'          => $segment,
            'active_proposals' => $this->proposalRepository->getActiveProposalsForSegment($id),
            'saved'            => false,
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

        $reason = trim((string) $request->request->get('reden', ''));
        if ($reason === '') {
            $this->addFlash('error', 'Geef een reden voor de aanpassing op.');
            return $this->redirectToRoute('app_institutio_translate', ['id' => $id]);
        }

        $activeProposals = $this->proposalRepository->getActiveProposalsForSegment($id);

        /** @var User $user */
        $user = $this->getUser();
        $submittedCount = 0;

        if ($segment['rows']) {
            $submittedRows = $request->request->all('row_nl');
            foreach ($segment['rows'] as $row) {
                $rowId = $row['id'];
                if (!array_key_exists($rowId, $submittedRows) || isset($activeProposals['rows'][$rowId])) {
                    continue; // not submitted, or already has an active proposal -- skip either way
                }
                $newText = (string) $submittedRows[$rowId];
                if ($newText === $row['nl_text']) {
                    continue; // unchanged
                }
                $this->proposalRepository->createTranslationProposal(
                    $id, $rowId, $row['nl_text'], $newText, $reason, $user->getId()
                );
                $submittedCount++;
            }
        } elseif ($activeProposals['whole'] === null) {
            $newText = (string) $request->request->get('text_nl', '');
            if ($newText !== (string) $segment['text_nl']) {
                $this->proposalRepository->createTranslationProposal(
                    $id, null, (string) $segment['text_nl'], $newText, $reason, $user->getId()
                );
                $submittedCount++;
            }
        }

        if ($submittedCount > 0) {
            $this->addFlash(
                'success',
                $submittedCount === 1
                    ? '1 vertaalvoorstel ingediend. Wacht op beoordeling door een andere vertaler.'
                    : "{$submittedCount} vertaalvoorstellen ingediend. Wacht op beoordeling door een andere vertaler."
            );
        } else {
            $this->addFlash('error', 'Geen wijzigingen gevonden om als voorstel in te dienen.');
        }

        return $this->redirectToRoute('app_institutio_translate', ['id' => $id]);
    }
}
