<?php

declare(strict_types=1);

namespace App\Formation\Module02Origin\Command;

use App\Entity\Product\Product;
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
 * Formation M2 / M9 : correctif de données « sûr » (modèle à reproduire pour un hotfix de données).
 *
 *   bin/console formation:products:set-origin France --only-empty --dry-run   # simulation : rien n'est écrit
 *   bin/console formation:products:set-origin France --only-empty              # exécution
 *   bin/console formation:products:set-origin Italie --code=OLIVE_OIL
 *
 * Bonnes pratiques illustrées (voir docs/formation/donnees-et-hotfix.md) :
 *  - simulation obligatoire avant exécution (--dry-run) qui liste ce qui changerait ;
 *  - périmètre explicite (--code, --only-empty) ;
 *  - idempotent : une deuxième exécution ne change rien ;
 *  - une transaction et un traçage dans les journaux (qui, quoi, combien).
 */
#[AsCommand(name: 'formation:products:set-origin', description: "Renseigne l'origine des produits (avec simulation)")]
final class SetProductOriginCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('origin', InputArgument::REQUIRED, "Pays ou région d'origine")
            ->addOption('code', null, InputOption::VALUE_REQUIRED, 'Limiter à un code produit')
            ->addOption('only-empty', null, InputOption::VALUE_NONE, 'Ne modifier que les produits sans origine')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simuler : afficher les changements sans rien écrire')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $origin = $input->getArgument('origin');
        Assert::string($origin);

        $code = $input->getOption('code');
        Assert::nullOrString($code);
        $criteria = null !== $code ? ['code' => $code] : [];
        $dryRun = (bool) $input->getOption('dry-run');

        $changes = [];
        $this->entityManager->beginTransaction();

        try {
            foreach ($this->entityManager->getRepository(Product::class)->findBy($criteria) as $product) {
                $current = $product->getOrigin();

                if ($current === $origin || ($input->getOption('only-empty') && null !== $current)) {
                    continue;
                }

                $changes[] = [$product->getCode() ?? '-', $current ?? '(vide)', $origin];
                if (!$dryRun) {
                    $product->setOrigin($origin);
                }
            }

            if (!$dryRun) {
                $this->entityManager->flush();
                $this->entityManager->commit();
            } else {
                $this->entityManager->rollback();
            }
        } catch (\Throwable $exception) {
            $this->entityManager->rollback();

            throw $exception;
        }

        if ([] !== $changes) {
            $io->table(['Produit', 'Origine actuelle', 'Nouvelle origine'], $changes);
        }

        if ($dryRun) {
            $io->note(sprintf('SIMULATION : %d produit(s) seraient modifiés. Aucune écriture.', count($changes)));

            return Command::SUCCESS;
        }

        $this->logger->info('Correctif de données : origine des produits', [
            'command' => 'formation:products:set-origin',
            'origin' => $origin,
            'updated' => count($changes),
            'codes' => array_column($changes, 0),
        ]);
        $io->success(sprintf('%d produit(s) mis à jour (origine : %s).', count($changes), $origin));

        return Command::SUCCESS;
    }
}
