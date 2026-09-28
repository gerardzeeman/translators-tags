<?php

namespace App\Command;

use App\Controller\SpellingRuleController;
use App\Repository\SpellingRuleRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Reads spelling rules from an export (app:spelling:export, or the export
 * button on /commentaren/spelling): a rule with the same kind and source is
 * updated, others added; --replace also removes the rules not in the file.
 * All or nothing.
 *
 *   php bin/console app:spelling:import sync/spellingregels.json [--replace]
 */
#[AsCommand(name: 'app:spelling:import', description: 'Import spelling rules (modern spelling) from a JSON export')]
class SpellingRulesImportCommand extends Command
{
    public function __construct(private readonly SpellingRuleRepository $repository)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('file', InputArgument::REQUIRED, 'Export file (.json)')
             ->addOption('replace', null, InputOption::VALUE_NONE, 'Remove the rules that are not in the file')
             ->addOption('layer', null, InputOption::VALUE_REQUIRED, 'Translation layer', SpellingRuleRepository::DEFAULT_LAYER);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = (string) $input->getArgument('file');
        if (!is_readable($file)) {
            $output->writeln("<error>Cannot read {$file}</error>");
            return Command::FAILURE;
        }
        try {
            $rules = SpellingRuleController::rulesFromExport((string) file_get_contents($file));
        } catch (\RuntimeException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
        $result = $this->repository->import((string) $input->getOption('layer'), $rules,
            (bool) $input->getOption('replace'), 'console');
        $output->writeln(sprintf('<info>%d added, %d updated, %d removed</info>',
            $result['added'], $result['updated'], $result['removed']));
        return Command::SUCCESS;
    }
}
