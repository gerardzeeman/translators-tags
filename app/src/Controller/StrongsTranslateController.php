<?php

namespace App\Controller;

use App\Repository\LinkingRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/strongs/translate')]
#[IsGranted('ROLE_EDIT_STRONG_TRNL')]
class StrongsTranslateController extends AbstractController
{
    public function __construct(
        private readonly LinkingRepository $linkingRepo,
    ) {}

    #[Route('', name: 'app_strongs_translate_home')]
    public function home(): Response
    {
        return $this->render('strongs/translate.html.twig', [
            'strongs_id'    => null,
            'strongs_entry' => null,
            'saved'         => false,
        ]);
    }

    #[Route('/{strongs}', name: 'app_strongs_translate', methods: ['GET'], requirements: ['strongs' => '[HGhg]\d+[A-Za-z]?'])]
    public function show(string $strongs): Response
    {
        $strongs = strtoupper($strongs);
        $entry   = $this->linkingRepo->fetchStrongsEntry($strongs);

        if (!$entry) {
            $this->addFlash('error', "Strong's nummer '{$strongs}' niet gevonden.");
            return $this->redirectToRoute('app_strongs_translate_home');
        }

        return $this->render('strongs/translate.html.twig', [
            'strongs_id'    => $strongs,
            'strongs_entry' => $entry,
            'saved'         => false,
        ]);
    }

    #[Route('/{strongs}', name: 'app_strongs_translate_save', methods: ['POST'], requirements: ['strongs' => '[HGhg]\d+[A-Za-z]?'])]
    public function save(string $strongs, Request $request): Response
    {
        $strongs = strtoupper($strongs);

        $this->linkingRepo->saveStrongsTranslation(
            $strongs,
            $request->request->get('short_def_nl'),
            $request->request->get('definition_nl'),
            $request->request->get('etymology_nl'),
        );

        // Re-fetch after save so the editor shows the stored values
        $entry = $this->linkingRepo->fetchStrongsEntry($strongs);

        return $this->render('strongs/translate.html.twig', [
            'strongs_id'    => $strongs,
            'strongs_entry' => $entry,
            'saved'         => true,
        ]);
    }
}
