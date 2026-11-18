<?php

declare(strict_types=1);

namespace App\Formation\Module14MultiSite\Command;

use App\Formation\Module14MultiSite\Provisioning\ProvisioningReport;
use App\Formation\Module14MultiSite\Provisioning\SiteDefinitionLoader;
use App\Formation\Module14MultiSite\Provisioning\SiteProvisionerInterface;
use App\Formation\Module14MultiSite\Provisioning\SiteProvisioningException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Webmozart\Assert\Assert;

/**
 * Formation M14 : ouvre un site (channel) à partir d'un fichier YAML, de façon idempotente.
 *
 *   bin/console formation:sites:open data/formation/sites/site-be.yaml --dry-run   # simulation : rien n'est écrit
 *   bin/console formation:sites:open data/formation/sites/site-be.yaml             # exécution
 *
 * Modèle de sécurité repris de formation:products:set-origin : simulation, transaction unique, journal.
 * En simulation, c'est l'absence de flush qui garantit qu'aucune écriture n'a lieu ; le rollback referme la transaction.
 * Voir docs/formation/ouverture-site.md.
 */
#[AsCommand(name: 'formation:sites:open', description: 'Ouvre un site (channel) depuis un fichier YAML (avec simulation)')]
final class OpenSiteCommand extends Command
{
    public function __construct(
        private readonly SiteDefinitionLoader $loader,
        private readonly SiteProvisionerInterface $provisioner,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'Fichier YAML décrivant le site (voir data/formation/sites/)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simuler : afficher les changements sans rien écrire')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $file = $input->getArgument('file');
        Assert::string($file);
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $definition = $this->loader->load($file);
        } catch (SiteProvisioningException $exception) {
            $this->renderErrors($io, $exception);

            return Command::FAILURE;
        }

        $this->entityManager->beginTransaction();

        try {
            $report = $this->provisioner->provision($definition);

            if ($dryRun) {
                $this->entityManager->rollback();
            } else {
                $this->entityManager->flush();
                $this->entityManager->commit();
            }
        } catch (SiteProvisioningException $exception) {
            $this->entityManager->rollback();
            $this->entityManager->clear();
            $this->renderErrors($io, $exception);

            return Command::FAILURE;
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }

        $this->renderReport($io, $report);

        if ($dryRun) {
            $this->entityManager->clear();
            $io->note(sprintf('SIMULATION : %d changement(s) seraient appliqués au site %s. Aucune écriture.', count($report->changes()), $definition->code));

            return Command::SUCCESS;
        }

        $this->logger->info('Ouverture de site', [
            'command' => 'formation:sites:open',
            'channel' => $definition->code,
            'hostname' => $definition->hostname,
            'changes' => count($report->changes()),
        ]);

        if ($report->hasChanges()) {
            $io->success(sprintf('Site %s à jour (%d changement(s)). Contrôle : bin/console formation:sites:list', $definition->code, count($report->changes())));
        } else {
            $io->success(sprintf('Site %s déjà conforme : rien à changer.', $definition->code));
        }

        return Command::SUCCESS;
    }

    private function renderReport(SymfonyStyle $io, ProvisioningReport $report): void
    {
        if ($report->hasChanges()) {
            $io->table(['Action', 'Élément', 'Détail'], $report->changes());
        }

        foreach ($report->warnings() as $warning) {
            $io->warning($warning);
        }
    }

    private function renderErrors(SymfonyStyle $io, SiteProvisioningException $exception): void
    {
        $io->error($exception->errors());
    }
}
