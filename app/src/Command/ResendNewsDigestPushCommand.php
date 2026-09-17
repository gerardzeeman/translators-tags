<?php

namespace App\Command;

use App\Message\SendNewsDigestPush;
use App\Repository\NewsDigestRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * CLI-equivalent van de "Opnieuw versturen"-knop op /admin/nieuwsoverzicht
 * (AdminNewsDigestController::resend) -- voor wanneer een resend nodig is
 * zonder een geauthenticeerde admin-sessie in de browser.
 */
#[AsCommand(
    name: 'app:push:resend',
    description: 'Verstuur een NewsDigest-pushmelding opnieuw (standaard: de meest recent verstuurde)',
)]
class ResendNewsDigestPushCommand extends Command
{
    public function __construct(
        private readonly NewsDigestRepository $newsDigestRepository,
        private readonly MessageBusInterface $bus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('id', InputArgument::OPTIONAL, 'NewsDigest-id (standaard: de meest recent verstuurde)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $id = $input->getArgument('id');
        $digest = $id !== null
            ? $this->newsDigestRepository->find((int) $id)
            : $this->newsDigestRepository->findOneBy([], ['sentAt' => 'DESC']);

        if ($digest === null) {
            $io->error($id !== null ? "Geen nieuwsoverzicht gevonden met id {$id}." : 'Geen nieuwsoverzicht gevonden.');
            return Command::FAILURE;
        }

        $this->bus->dispatch(new SendNewsDigestPush($digest->getId()));

        $io->success(sprintf('Opnieuw verstuurd: #%d "%s" (verstuurd op %s)', $digest->getId(), $digest->getTitle(), $digest->getSentAt()->format('d-m-Y H:i')));

        return Command::SUCCESS;
    }
}
