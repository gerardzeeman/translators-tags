<?php

namespace App\Controller;

use App\Repository\NewsDigestRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Publieke pagina met het laatst verstuurde CGK/Rijnsburg-nieuwsoverzicht --
 * de bestemming van de pushmelding-link, zodat de volledige tekst en
 * eventuele links leesbaar/klikbaar zijn buiten de melding zelf (alleen het
 * laatste overzicht; de volledige historie staat op /admin/nieuwsoverzicht).
 */
class NewsDigestPageController extends AbstractController
{
    #[Route('/cgk-rijnsburg-nieuwsoverzicht', name: 'app_news_digest_latest')]
    public function latest(NewsDigestRepository $newsDigestRepository): Response
    {
        return $this->render('news_digest/latest.html.twig', [
            'digest' => $newsDigestRepository->findOneBy([], ['sentAt' => 'DESC']),
        ]);
    }
}
