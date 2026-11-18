<?php

declare(strict_types=1);

namespace App\Formation\Module14MultiSite\Command;

use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Formation M14 : liste les sites (channels) et leur configuration.
 * Utile pour contrôler un rollout multi-sites : hostname, devise de base, langues, état.
 */
#[AsCommand(name: 'formation:sites:list', description: 'Liste les sites (channels) : hostname, devise, langues, état')]
final class ListSitesCommand extends Command
{
    /** @param ChannelRepositoryInterface<ChannelInterface> $channelRepository */
    public function __construct(
        #[Autowire(service: 'sylius.repository.channel')]
        private readonly ChannelRepositoryInterface $channelRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $rows = [];

        foreach ($this->channelRepository->findAll() as $channel) {
            $locales = [];
            foreach ($channel->getLocales() as $locale) {
                $locales[] = $locale->getCode();
            }

            $rows[] = [
                $channel->getCode(),
                $channel->getHostname() ?? '-',
                $channel->getBaseCurrency()?->getCode() ?? '-',
                implode(', ', $locales),
                $channel->isEnabled() ? 'oui' : 'non',
            ];
        }

        if ([] === $rows) {
            $io->warning('Aucun channel. Chargez les fixtures : bin/console sylius:fixtures:load formation_multisite');

            return Command::SUCCESS;
        }

        usort($rows, static fn (array $a, array $b): int => strcmp((string) $a[0], (string) $b[0]));
        $io->table(['Code', 'Hostname', 'Devise', 'Langues', 'Actif'], $rows);

        return Command::SUCCESS;
    }
}
