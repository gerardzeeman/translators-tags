<?php

namespace App\Controller;

use App\Repository\SpellingRuleRepository;
use App\Repository\TextFlagRepository;
use App\Service\SpellingContext;
use App\Service\SpellingModernizer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Flags on problems in a commentary's text (TextFlagRepository): placed,
 * edited and removed from the chapter page (flags_controller.js, JSON), and
 * listed here -- open or resolved, per category and chapter, with whether
 * the flagged text is still there -- as the reference for fixing them; plus
 * export/import between dev and prod.
 *
 * Routes before CommentaryController's /commentaren/{boek} (priority).
 */
#[Route('/commentaren/flags')]
#[IsGranted('ROLE_EDIT_SPELLING')]
class TextFlagController extends AbstractController
{
    public function __construct(
        private readonly TextFlagRepository $flags,
        private readonly SpellingRuleRepository $spellingRules,
        private readonly SpellingModernizer $modernizer,
    ) {}

    #[Route('', name: 'app_text_flags', methods: ['GET'], priority: 20)]
    public function index(Request $request): Response
    {
        $status = in_array($request->query->get('status'), ['open', 'resolved'], true) ? $request->query->get('status') : null;
        $category = array_key_exists((string) $request->query->get('categorie'), TextFlagRepository::CATEGORIES)
            ? $request->query->get('categorie') : null;
        $chapter = ctype_digit((string) $request->query->get('hoofdstuk')) ? (int) $request->query->get('hoofdstuk') : null;

        $flags = $this->flags->search($status, $category, $chapter);
        foreach ($flags as &$flag) {
            $flag['found'] = $this->stillThere($flag);
        }
        return $this->render('flags/index.html.twig', [
            'flags'      => $flags,
            'counts'     => $this->flags->counts(),
            'categories' => TextFlagRepository::CATEGORIES,
            'fields'     => TextFlagRepository::FIELDS,
            'filter'     => ['status' => $status, 'categorie' => $category, 'hoofdstuk' => $chapter],
        ]);
    }

    #[Route('', name: 'app_text_flag_create', methods: ['POST'], priority: 20)]
    public function create(Request $request): JsonResponse
    {
        $data = $this->json_body($request);
        if ($data === null) {
            return $this->json(['error' => 'Ongeldig verzoek.'], 400);
        }
        $segment = $this->spellingRules->segmentWithText(SpellingRuleRepository::DEFAULT_LAYER, (int) ($data['segment'] ?? 0));
        $flag = [
            'field'          => (string) ($data['field'] ?? ''),
            'snippet'        => trim((string) ($data['snippet'] ?? '')),
            'context_before' => mb_substr((string) ($data['context_before'] ?? ''), -60),
            'context_after'  => mb_substr((string) ($data['context_after'] ?? ''), 0, 60),
            'category'       => (string) ($data['category'] ?? ''),
            'note'           => (string) ($data['note'] ?? ''),
        ];
        if ($segment === null || !isset(TextFlagRepository::FIELDS[$flag['field']])
            || !isset(TextFlagRepository::CATEGORIES[$flag['category']]) || $flag['snippet'] === ''
            || mb_strlen($flag['snippet']) > 500) {
            return $this->json(['error' => 'Kies een stuk tekst (hoogstens 500 tekens) en een categorie.'], 400);
        }
        $id = $this->flags->create($segment['id'], $flag, $this->getUser()?->getUserIdentifier());
        return $this->json($this->flags->find($id));
    }

    #[Route('/{id<\d+>}', name: 'app_text_flag_update', methods: ['POST'], priority: 20)]
    public function update(int $id, Request $request): JsonResponse
    {
        $data = $this->json_body($request);
        $flag = $this->flags->find($id);
        if ($data === null || $flag === null) {
            return $this->json(['error' => 'Onbekende flag.'], 400);
        }
        $category = (string) ($data['category'] ?? $flag['category']);
        if (!isset(TextFlagRepository::CATEGORIES[$category])) {
            return $this->json(['error' => 'Onbekende categorie.'], 400);
        }
        $status = ($data['status'] ?? $flag['status']) === 'resolved' ? 'resolved' : 'open';
        $this->flags->update($id, $category, array_key_exists('note', $data) ? (string) $data['note'] : $flag['note'],
            $status, $this->getUser()?->getUserIdentifier());
        return $this->json($this->flags->find($id));
    }

    #[Route('/{id<\d+>}/verwijderen', name: 'app_text_flag_delete', methods: ['POST'], priority: 20)]
    public function delete(int $id, Request $request): Response
    {
        $viaForm = $request->request->has('_csrf_token');
        $valid = $viaForm
            ? $this->isCsrfTokenValid('text_flag', (string) $request->request->get('_csrf_token'))
            : $this->json_body($request) !== null;
        if ($valid) {
            $this->flags->delete($id);
        }
        if ($viaForm) {
            $this->addFlash($valid ? 'success' : 'error', $valid ? 'Flag verwijderd.' : 'Ongeldig formulierverzoek.');
            return $this->redirect($request->headers->get('referer') ?: $this->generateUrl('app_text_flags'));
        }
        return $this->json(['deleted' => $valid], $valid ? 200 : 400);
    }

    /** Resolve / reopen from the overview (a form). */
    #[Route('/{id<\d+>}/status', name: 'app_text_flag_status', methods: ['POST'], priority: 20)]
    public function status(int $id, Request $request): Response
    {
        $flag = $this->flags->find($id);
        if ($flag !== null && $this->isCsrfTokenValid('text_flag', (string) $request->request->get('_csrf_token'))) {
            $this->flags->update($id, $flag['category'], $flag['note'],
                $flag['status'] === 'resolved' ? 'open' : 'resolved', $this->getUser()?->getUserIdentifier());
        }
        return $this->redirect(($request->headers->get('referer') ?: $this->generateUrl('app_text_flags')) . '#flag-' . $id);
    }

    #[Route('/export', name: 'app_text_flags_export', methods: ['GET'], priority: 20)]
    public function export(): Response
    {
        $response = new JsonResponse($this->flags->export());
        $response->setEncodingOptions(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $response->headers->set('Content-Disposition', 'attachment; filename="flags-' . date('Y-m-d-His') . '.json"');
        return $response;
    }

    #[Route('/import', name: 'app_text_flags_import', methods: ['POST'], priority: 20)]
    public function import(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('text_flag', (string) $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Ongeldig formulierverzoek.');
            return $this->redirectToRoute('app_text_flags');
        }
        $file = $request->files->get('file');
        $data = $file !== null && $file->isValid() ? json_decode((string) file_get_contents($file->getPathname()), true) : null;
        if (!is_array($data) || ($data['format'] ?? '') !== 'alefomega-text-flags/1' || !is_array($data['flags'] ?? null)) {
            $this->addFlash('error', 'Dit is geen exportbestand van de flags.');
            return $this->redirectToRoute('app_text_flags');
        }
        $result = $this->flags->import(array_values(array_filter($data['flags'], 'is_array')), $this->getUser()?->getUserIdentifier());
        $this->addFlash('success', sprintf('Geïmporteerd: %d toegevoegd, %d bijgewerkt%s.', $result['added'], $result['updated'],
            $result['skipped'] ? ", {$result['skipped']} overgeslagen (tekst ontbreekt hier)" : ''));
        return $this->redirectToRoute('app_text_flags');
    }

    /**
     * Whether a flag's text is still in its column (else it has most likely
     * been fixed by a re-parse): white space and case aside.
     *
     * @param array<string, mixed> $flag with text_la, text_nl
     */
    private function stillThere(array $flag): bool
    {
        $norm = fn(?string $s) => mb_strtolower(preg_replace('/\s+/u', ' ', trim((string) $s)));
        $text = match ($flag['field']) {
            'la'     => (string) $flag['text_la'],
            'los'    => (string) preg_replace(['/⟦C:([^‖⟧]*)‖[^⟧]*⟧/u', '/⟦[^⟧]*⟧/u'], ['$1', ' '], (string) $flag['text_nl']),
            'modern' => $this->modernText($flag),
            default  => '',
        };
        return str_contains($norm($text), $norm($flag['snippet']));
    }

    /** @param array<string, mixed> $flag */
    private function modernText(array $flag): string
    {
        if ($flag['text_nl'] === null) {
            return '';
        }
        $choices = $this->spellingRules->choicesFor(SpellingRuleRepository::DEFAULT_LAYER, [$flag['segment_id']]);
        $paras = $this->modernizer->paragraphs(CommentaryController::dutchParagraphs($flag['text_nl']),
            SpellingRuleRepository::DEFAULT_LAYER, new SpellingContext($choices[$flag['segment_id']] ?? []));
        return implode("\n", array_map(fn($parts) => implode('', array_map(fn($p) => $p['content'], $parts)), $paras));
    }

    /**
     * A JSON request body with a valid token, or null.
     *
     * @return array<string, mixed>|null
     */
    private function json_body(Request $request): ?array
    {
        $data = json_decode($request->getContent(), true);
        return is_array($data) && $this->isCsrfTokenValid('text_flag', (string) ($data['_token'] ?? '')) ? $data : null;
    }
}
