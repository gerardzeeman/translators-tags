<?php

namespace App\Controller;

use App\Repository\NewsDigestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Alleen-lezen historie van verstuurde nieuwsoverzicht-pushmeldingen.
 * See docs/push-notifications-plan.md §5.3.
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
}
