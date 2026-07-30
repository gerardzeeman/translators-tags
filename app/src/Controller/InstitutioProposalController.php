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
 * The review side panel for a single translation proposal (see
 * InstitutioProposalRepository / db/migrate_add_translation_proposals.sql).
 * All four actions render/redirect back to the same
 * institutio/proposal_panel.html.twig inside the "institutio-proposal-panel"
 * Turbo Frame -- mirrors the existing Bible-verse-citation panel pattern
 * (InstitutioController::verse() + institutio-verse-panel).
 *
 * Class-level gate (ROLE_EDIT_INSTITUTIO_TRNL) covers viewing the panel and
 * commenting -- available to the proposer and any other translator.
 * Approve/reject additionally require ROLE_REVIEW_INSTITUTIO_TRNL (a
 * distinct role, see security.yaml) plus an explicit self-review guard,
 * since Symfony's declarative access control can't express "not the same
 * user who created this specific row."
 */
#[Route('/institutie/voorstel')]
#[IsGranted('ROLE_EDIT_INSTITUTIO_TRNL')]
class InstitutioProposalController extends AbstractController
{
    public function __construct(
        private readonly InstitutioProposalRepository $proposalRepository,
        private readonly InstitutioRepository $institutioRepository,
    ) {}

    #[Route('/{proposalId<\d+>}', name: 'app_institutio_proposal_panel', methods: ['GET'])]
    public function panel(int $proposalId): Response
    {
        $proposal = $this->proposalRepository->getProposalTimeline($proposalId);
        if ($proposal === null) {
            throw $this->createNotFoundException("Vertaalvoorstel {$proposalId} niet gevonden.");
        }

        /** @var User $user */
        $user = $this->getUser();

        return $this->render('institutio/proposal_panel.html.twig', [
            'proposal'    => $proposal,
            'can_comment' => $proposal['status'] !== 'approved',
            'can_review'  => $proposal['status'] !== 'approved'
                && $this->isGranted('ROLE_REVIEW_INSTITUTIO_TRNL')
                && $proposal['created_by']['id'] !== $user->getId(),
        ]);
    }

    #[Route('/{proposalId<\d+>}/reageren', name: 'app_institutio_proposal_comment', methods: ['POST'])]
    public function comment(int $proposalId, Request $request): Response
    {
        $proposal = $this->proposalRepository->findProposal($proposalId);
        if ($proposal === null) {
            throw $this->createNotFoundException("Vertaalvoorstel {$proposalId} niet gevonden.");
        }

        if (!$this->isCsrfTokenValid('institutio_proposal', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Ongeldig formulierverzoek.');
            return $this->redirectToRoute('app_institutio_proposal_panel', ['proposalId' => $proposalId]);
        }

        $body = trim((string) $request->request->get('body', ''));
        if ($body === '') {
            $this->addFlash('error', 'Vul een reactie in.');
            return $this->redirectToRoute('app_institutio_proposal_panel', ['proposalId' => $proposalId]);
        }
        if ($proposal['status'] === 'approved') {
            $this->addFlash('error', 'Dit voorstel is al definitief goedgekeurd; reageren kan niet meer.');
            return $this->redirectToRoute('app_institutio_proposal_panel', ['proposalId' => $proposalId]);
        }

        /** @var User $user */
        $user = $this->getUser();
        $this->proposalRepository->addProposalEvent($proposalId, $user->getId(), 'comment', $body);

        return $this->redirectToRoute('app_institutio_proposal_panel', ['proposalId' => $proposalId]);
    }

    #[Route('/{proposalId<\d+>}/goedkeuren', name: 'app_institutio_proposal_approve', methods: ['POST'])]
    #[IsGranted('ROLE_REVIEW_INSTITUTIO_TRNL')]
    public function approve(int $proposalId, Request $request): Response
    {
        $proposal = $this->proposalRepository->findProposal($proposalId);
        if ($proposal === null) {
            throw $this->createNotFoundException("Vertaalvoorstel {$proposalId} niet gevonden.");
        }

        /** @var User $user */
        $user = $this->getUser();
        if ($proposal['created_by_user_id'] === $user->getId()) {
            throw $this->createAccessDeniedException('Je kunt je eigen vertaalvoorstel niet beoordelen.');
        }
        if ($proposal['status'] === 'approved') {
            $this->addFlash('error', 'Dit voorstel is al goedgekeurd.');
            return $this->redirectToRoute('app_institutio_proposal_panel', ['proposalId' => $proposalId]);
        }

        if (!$this->isCsrfTokenValid('institutio_proposal', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Ongeldig formulierverzoek.');
            return $this->redirectToRoute('app_institutio_proposal_panel', ['proposalId' => $proposalId]);
        }

        $body = trim((string) $request->request->get('body', ''));
        $newText = $proposal['new_text'];

        $alignmentDropped = $this->proposalRepository->transactional(
            function () use ($proposal, $newText, $body, $proposalId, $user): bool {
                $this->proposalRepository->addProposalEvent($proposalId, $user->getId(), 'approve', $body !== '' ? $body : null);

                return $proposal['sentence_alignment_id'] !== null
                    ? $this->institutioRepository->saveSegmentRowTranslations(
                        $proposal['segment_id'],
                        [$proposal['sentence_alignment_id'] => $newText]
                    )
                    : $this->institutioRepository->saveSegmentTranslation($proposal['segment_id'], $newText);
            }
        );

        if ($alignmentDropped) {
            $this->addFlash(
                'warning',
                'De woord-uitlijning (fase 4) voor dit segment is verwijderd omdat de vertaling '
                . 'is aangepast en de oude uitlijning niet meer bij de nieuwe tekst past. '
                . 'Voer align_segments.py opnieuw uit voor dit segment om de uitlijning te herstellen.'
            );
        }

        return $this->redirectToRoute('app_institutio_proposal_panel', ['proposalId' => $proposalId]);
    }

    #[Route('/{proposalId<\d+>}/afkeuren', name: 'app_institutio_proposal_reject', methods: ['POST'])]
    #[IsGranted('ROLE_REVIEW_INSTITUTIO_TRNL')]
    public function reject(int $proposalId, Request $request): Response
    {
        $proposal = $this->proposalRepository->findProposal($proposalId);
        if ($proposal === null) {
            throw $this->createNotFoundException("Vertaalvoorstel {$proposalId} niet gevonden.");
        }

        /** @var User $user */
        $user = $this->getUser();
        if ($proposal['created_by_user_id'] === $user->getId()) {
            throw $this->createAccessDeniedException('Je kunt je eigen vertaalvoorstel niet beoordelen.');
        }
        if ($proposal['status'] === 'approved') {
            $this->addFlash('error', 'Dit voorstel is al goedgekeurd.');
            return $this->redirectToRoute('app_institutio_proposal_panel', ['proposalId' => $proposalId]);
        }

        if (!$this->isCsrfTokenValid('institutio_proposal', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Ongeldig formulierverzoek.');
            return $this->redirectToRoute('app_institutio_proposal_panel', ['proposalId' => $proposalId]);
        }

        $body = trim((string) $request->request->get('body', ''));
        if ($body === '') {
            $this->addFlash('error', 'Geef een reden voor de afkeuring op.');
            return $this->redirectToRoute('app_institutio_proposal_panel', ['proposalId' => $proposalId]);
        }

        $this->proposalRepository->addProposalEvent($proposalId, $user->getId(), 'reject', $body);

        return $this->redirectToRoute('app_institutio_proposal_panel', ['proposalId' => $proposalId]);
    }
}
