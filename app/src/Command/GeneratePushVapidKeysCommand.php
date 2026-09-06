<?php

namespace App\Command;

use Minishlink\WebPush\VAPID;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:push:generate-vapid-keys',
    description: 'Generate a VAPID key pair for Web Push (docs/push-notifications-plan.md §3)',
)]
class GeneratePushVapidKeysCommand extends Command
{
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $keys = VAPID::createVapidKeys();

        $io->title('VAPID-sleutelpaar gegenereerd');
        $io->text('Zet deze twee waarden in .env.local (projectroot, nooit committen):');
        $io->newLine();
        $output->writeln("VAPID_PUBLIC_KEY={$keys['publicKey']}");
        $output->writeln("VAPID_PRIVATE_KEY={$keys['privateKey']}");
        $io->newLine();
        $io->warning('Roteren maakt alle bestaande browserabonnementen ongeldig — zie docs/push-notifications-plan.md §3.2.');

        return Command::SUCCESS;
    }
}
