<?php

declare(strict_types=1);

namespace App\Tests\Formation\Module14MultiSite;

use App\Formation\Module14MultiSite\Command\OpenSiteCommand;
use App\Formation\Module14MultiSite\Provisioning\ProvisioningReport;
use App\Formation\Module14MultiSite\Provisioning\SiteDefinitionLoader;
use App\Formation\Module14MultiSite\Provisioning\SiteProvisionerInterface;
use App\Formation\Module14MultiSite\Provisioning\SiteProvisioningException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/** Formation M14 : comportement de la commande (simulation, exécution, erreurs). */
final class OpenSiteCommandTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'site');
        self::assertNotFalse($path);
        file_put_contents($path, <<<'YAML'
            site:
                code: SITE_BE
                name: Belgique
                hostname: be.sylius.localhost
                default_locale: fr_FR
                locales: [fr_FR]
                base_currency: EUR
                currencies: [EUR]
            YAML);
        $this->file = $path;
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
    }

    public function testDryRunRollsBackAndNeverFlushes(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('beginTransaction');
        $entityManager->expects(self::once())->method('rollback');
        $entityManager->expects(self::once())->method('clear');
        $entityManager->expects(self::never())->method('flush');
        $entityManager->expects(self::never())->method('commit');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('info');

        $tester = $this->tester($entityManager, $this->provisionerReturning($this->reportWithOneChange()), $logger);
        $exitCode = $tester->execute(['file' => $this->file, '--dry-run' => true]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('SIMULATION : 1 changement(s)', $tester->getDisplay());
    }

    public function testExecutionFlushesCommitsAndLogs(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('beginTransaction');
        $entityManager->expects(self::once())->method('flush');
        $entityManager->expects(self::once())->method('commit');
        $entityManager->expects(self::never())->method('rollback');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with('Ouverture de site', self::arrayHasKey('channel'));

        $tester = $this->tester($entityManager, $this->provisionerReturning($this->reportWithOneChange()), $logger);
        $exitCode = $tester->execute(['file' => $this->file]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('Site SITE_BE à jour (1 changement(s))', $tester->getDisplay());
    }

    public function testReportsAnAlreadyCompliantSite(): void
    {
        $tester = $this->tester($this->createStub(EntityManagerInterface::class), $this->provisionerReturning(new ProvisioningReport()), $this->createStub(LoggerInterface::class));
        $exitCode = $tester->execute(['file' => $this->file]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertStringContainsString('déjà conforme', $tester->getDisplay());
    }

    public function testProvisioningErrorRollsBackAndFails(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('rollback');
        $entityManager->expects(self::never())->method('flush');
        $entityManager->expects(self::never())->method('commit');

        $provisioner = $this->createStub(SiteProvisionerInterface::class);
        $provisioner->method('provision')->willThrowException(new SiteProvisioningException(['Zone « EU » introuvable.']));

        $tester = $this->tester($entityManager, $provisioner, $this->createStub(LoggerInterface::class));
        $exitCode = $tester->execute(['file' => $this->file]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Zone « EU » introuvable.', $tester->getDisplay());
    }

    public function testUnexpectedErrorRollsBackAndIsRethrown(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::once())->method('rollback');
        $entityManager->expects(self::never())->method('commit');

        $provisioner = $this->createStub(SiteProvisionerInterface::class);
        $provisioner->method('provision')->willThrowException(new \RuntimeException('boom'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $this->tester($entityManager, $provisioner, $this->createStub(LoggerInterface::class))->execute(['file' => $this->file]);
    }

    public function testInvalidFileFailsBeforeAnyTransaction(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $entityManager->expects(self::never())->method('beginTransaction');

        $tester = $this->tester($entityManager, $this->createStub(SiteProvisionerInterface::class), $this->createStub(LoggerInterface::class));
        $exitCode = $tester->execute(['file' => '/chemin/inexistant.yaml']);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('Fichier introuvable', $tester->getDisplay());
    }

    private function tester(EntityManagerInterface $entityManager, SiteProvisionerInterface $provisioner, LoggerInterface $logger): CommandTester
    {
        return new CommandTester(new OpenSiteCommand(new SiteDefinitionLoader(), $provisioner, $entityManager, $logger));
    }

    private function provisionerReturning(ProvisioningReport $report): SiteProvisionerInterface
    {
        $provisioner = $this->createStub(SiteProvisionerInterface::class);
        $provisioner->method('provision')->willReturn($report);

        return $provisioner;
    }

    private function reportWithOneChange(): ProvisioningReport
    {
        $report = new ProvisioningReport();
        $report->change('créé', 'channel SITE_BE', 'hostname be.sylius.localhost');

        return $report;
    }
}
