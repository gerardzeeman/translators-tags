<?php

namespace App\Controller;

use App\Repository\SpellingRuleRepository;
use App\Service\SpellingModernizer;
use App\Service\SpellingRules;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The rules for the modern-spelling version of Los's translation (shown
 * as a third column next to his commentary): list, add/edit/delete, a live
 * preview of a rule while it is written, suggestions, and export/import to
 * move the rules between dev and prod (either way).
 *
 * Routes before CommentaryController's /commentaren/{boek} (priority).
 */
#[Route('/commentaren/spelling')]
#[IsGranted('ROLE_EDIT_SPELLING')]
class SpellingRuleController extends AbstractController
{
    private const LAYER = SpellingRuleRepository::DEFAULT_LAYER;

    public function __construct(
        private readonly SpellingRuleRepository $repository,
        private readonly SpellingModernizer $modernizer,
    ) {}

    #[Route('', name: 'app_spelling_rules', methods: ['GET'], priority: 20)]
    public function index(Request $request): Response
    {
        $rules = $this->repository->all(self::LAYER);
        $edit = ctype_digit((string) $request->query->get('bewerk')) ? $this->repository->find((int) $request->query->get('bewerk')) : null;
        return $this->render('spelling/index.html.twig', [
            'rules'    => $rules,
            'hits'     => $this->modernizer->hitsPerRule($rules, self::LAYER),
            'coverage' => $this->modernizer->coverage(80, self::LAYER),
            'form'     => $edit ?? [
                'id'   => null,
                'kind' => $request->query->get('soort', 'word'),
                'source' => $request->query->get('van', ''),
                'target' => '', 'exceptions' => '', 'position' => 0, 'active' => true, 'note' => '',
            ],
            'filter'   => $request->query->get('zoek', ''),
        ]);
    }

    #[Route('/opslaan', name: 'app_spelling_rule_save', methods: ['POST'], priority: 20)]
    public function save(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('spelling_rule', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Ongeldig formulierverzoek.');
            return $this->redirectToRoute('app_spelling_rules');
        }
        // (the fields may be empty: getInt() refuses those)
        $id = (int) ($request->request->get('id') ?: 0) ?: null;
        $data = [
            'kind'       => $request->request->get('kind') === 'pattern' ? 'pattern' : 'word',
            'source'     => trim((string) $request->request->get('source')),
            'target'     => trim((string) $request->request->get('target')),
            'exceptions' => (string) $request->request->get('exceptions', ''),
            'position'   => (int) ($request->request->get('position') ?: 0),
            'active'     => $request->request->has('active'),
            'note'       => (string) $request->request->get('note', ''),
        ];
        $error = SpellingRules::validate($data['kind'], $data['source'], $data['target']);
        if ($error === null && $this->repository->exists(self::LAYER, $data['kind'], $data['source'], $id)) {
            $error = "Er is al een regel voor „{$data['source']}”.";
        }
        if ($error !== null) {
            $this->addFlash('error', $error);
            return $this->redirectToRoute('app_spelling_rules', array_filter([
                'bewerk' => $id, 'soort' => $data['kind'], 'van' => $data['source'],
            ]));
        }
        if ($id) {
            $this->repository->update($id, $data);
            $this->addFlash('success', "Regel „{$data['source']} → {$data['target']}” bijgewerkt.");
        } else {
            $this->repository->create(self::LAYER, $data, $this->getUser()?->getUserIdentifier());
            $this->addFlash('success', "Regel „{$data['source']} → {$data['target']}” toegevoegd.");
        }
        return $this->redirectToRoute('app_spelling_rules');
    }

    #[Route('/{id<\d+>}/verwijderen', name: 'app_spelling_rule_delete', methods: ['POST'], priority: 20)]
    public function delete(int $id, Request $request): Response
    {
        if ($this->isCsrfTokenValid('spelling_rule', $request->request->get('_csrf_token')) && ($rule = $this->repository->find($id))) {
            $this->repository->delete($id);
            $this->addFlash('success', "Regel „{$rule['source']} → {$rule['target']}” verwijderd.");
        }
        return $this->redirectToRoute('app_spelling_rules');
    }

    #[Route('/{id<\d+>}/actief', name: 'app_spelling_rule_toggle', methods: ['POST'], priority: 20)]
    public function toggle(int $id, Request $request): Response
    {
        if ($this->isCsrfTokenValid('spelling_rule', $request->request->get('_csrf_token'))) {
            $this->repository->toggle($id);
        }
        return $this->redirectToRoute('app_spelling_rules', ['_fragment' => 'regel-' . $id]);
    }

    #[Route('/voorbeeld', name: 'app_spelling_rule_preview', methods: ['GET'], priority: 20)]
    public function preview(Request $request): JsonResponse
    {
        return $this->json($this->modernizer->preview(
            $request->query->get('kind') === 'pattern' ? 'pattern' : 'word',
            (string) $request->query->get('source', ''),
            (string) $request->query->get('target', ''),
            (string) $request->query->get('exceptions', ''),
            self::LAYER,
        ));
    }

    #[Route('/export', name: 'app_spelling_rules_export', methods: ['GET'], priority: 20)]
    public function export(): Response
    {
        $response = new JsonResponse($this->repository->export(self::LAYER));
        $response->setEncodingOptions(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $name = sprintf('spellingregels-%s-%s.json', self::LAYER, date('Y-m-d-His'));
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $name . '"');
        return $response;
    }

    #[Route('/import', name: 'app_spelling_rules_import', methods: ['POST'], priority: 20)]
    public function import(Request $request): Response
    {
        if (!$this->isCsrfTokenValid('spelling_rule', $request->request->get('_csrf_token'))) {
            $this->addFlash('error', 'Ongeldig formulierverzoek.');
            return $this->redirectToRoute('app_spelling_rules');
        }
        $file = $request->files->get('file');
        try {
            if ($file === null || !$file->isValid()) {
                throw new \RuntimeException('Kies een exportbestand (.json).');
            }
            $rules = self::rulesFromExport((string) file_get_contents($file->getPathname()));
            $result = $this->repository->import(self::LAYER, $rules, $request->request->get('mode') === 'replace',
                $this->getUser()?->getUserIdentifier());
            $this->addFlash('success', sprintf('Geïmporteerd: %d toegevoegd, %d bijgewerkt, %d verwijderd.',
                $result['added'], $result['updated'], $result['removed']));
        } catch (\RuntimeException $e) {
            $this->addFlash('error', $e->getMessage());
        }
        return $this->redirectToRoute('app_spelling_rules');
    }

    /**
     * The rules in an export file, checked -- nothing is imported if one of
     * them is not a usable rule.
     *
     * @return list<array<string, mixed>>
     */
    public static function rulesFromExport(string $json): array
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !isset($data['rules']) || !is_array($data['rules'])
            || !str_starts_with((string) ($data['format'] ?? ''), 'alefomega-spelling-rules/')) {
            throw new \RuntimeException('Dit is geen exportbestand van de spellingregels.');
        }
        foreach ($data['rules'] as $i => $rule) {
            $error = is_array($rule)
                ? SpellingRules::validate((string) ($rule['kind'] ?? ''), (string) ($rule['source'] ?? ''), (string) ($rule['target'] ?? ''))
                : 'geen regel';
            if ($error !== null) {
                throw new \RuntimeException(sprintf('Regel %d in het bestand is ongeldig: %s', $i + 1, $error));
            }
        }
        return array_values($data['rules']);
    }
}
