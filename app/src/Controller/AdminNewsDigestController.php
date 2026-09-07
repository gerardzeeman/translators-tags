<?php

namespace App\Controller;

use App\Entity\NewsDigest;
use App\Message\SendNewsDigestPush;
use App\Repository\NewsDigestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Historie van verstuurde nieuwsoverzicht-pushmeldingen, met de optie een
 * eerder overzicht opnieuw te versturen. See docs/push-notifications-plan.md
 * §5.3.
 */
#[Route('/admin/nieuwsoverzicht')]
#[IsGranted('ROLE_ADMIN')]
class AdminNewsDigestController extends AbstractController
{
    #[Route('', name: 'admin_news_digest_index')]
    public function index(NewsDigestRepository $newsDigestRepository): Response
    {
        return $this->render('admin/news_digest/index.html.twig', [
            'digests' => $newsDigestRepository->findBy([], ['sentAt' => 'DESC']),
        ]);
    }

    #[Route('/{id}/opnieuw-versturen', name: 'admin_news_digest_resend', methods: ['POST'])]
    public function resend(NewsDigest $digest, Request $request, MessageBusInterface $bus): Response
    {
        if (!$this->isCsrfTokenValid('news_digest_resend_' . $digest->getId(), $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Ongeldig formulierverzoek.');
            return $this->redirectToRoute('admin_news_digest_index');
        }

        // Rechtstreeks dispatchen (niet via de webhook): dit is een bewuste,
        // geauthenticeerde admin-actie, niet een binnenkomend verzoek van de
        // scheduled task -- de idempotentie-/rate-limit-bescherming van de
        // webhook (plan §5.2) is hier niet van toepassing. success/failure-
        // tellers op deze rij worden cumulatief opgehoogd, niet gereset.
        $bus->dispatch(new SendNewsDigestPush($digest->getId()));

        $this->addFlash('success', sprintf('"%s" opnieuw verstuurd.', $digest->getTitle()));
        return $this->redirectToRoute('admin_news_digest_index');
    }
}
