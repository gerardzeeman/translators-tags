<?php

namespace App\Command;

use App\Repository\SpellingRuleRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Writes the spelling rules (modern spelling of Los) as JSON -- the same
 * file as the export button on /commentaren/spelling, for
 * app:spelling:import in the other environment.
 *
 *   php bin/console app:spelling:export --output=sync/spellingregels.json
 */
#[AsCommand(name: 'app:spelling:export', description: 'Export the spelling rules (modern spelling) as JSON')]
class SpellingRulesExportCommand extends Command
{
    public function __construct(private readonly SpellingRuleRepository $repository)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'File to write (default: stdout)')
             ->addOption('layer', null, InputOption::VALUE_REQUIRED, 'Translation layer', SpellingRuleRepository::DEFAULT_LAYER);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $data = $this->repository->export((string) $input->getOption('layer'));
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        if ($file = $input->getOption('output')) {
            file_put_contents($file, $json);
            $output->writeln(sprintf('<info>%d rules written to %s</info>', count($data['rules']), $file));
        } else {
            $output->write($json, false, OutputInterface::OUTPUT_RAW);
        }
        return Command::SUCCESS;
    }
}
