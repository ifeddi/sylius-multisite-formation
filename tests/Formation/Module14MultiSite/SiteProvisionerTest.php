<?php

declare(strict_types=1);

namespace App\Tests\Formation\Module14MultiSite;

use App\Formation\Module14MultiSite\Provisioning\SiteDefinition;
use App\Formation\Module14MultiSite\Provisioning\SiteProvisioner;
use App\Formation\Module14MultiSite\Provisioning\SiteProvisioningException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Sylius\Component\Addressing\Model\Zone;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\Channel;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentMethod;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ShippingMethod;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\Model\Taxon;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Currency\Model\Currency;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Locale\Model\Locale;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Sylius\Resource\Model\ResourceInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/** Formation M14 : logique d'ouverture d'un site (création, idempotence, prérequis, validation). */
final class SiteProvisionerTest extends TestCase
{
    private LocaleInterface $locale;

    private CurrencyInterface $currency;

    private ZoneInterface $zone;

    private TaxonInterface $taxon;

    private PaymentMethodInterface $payment;

    private ShippingMethodInterface $shipping;

    protected function setUp(): void
    {
        $this->locale = new Locale();
        $this->locale->setCode('fr_FR');
        $this->currency = new Currency();
        $this->currency->setCode('EUR');
        $this->zone = new Zone();
        $this->zone->setCode('EU');
        $this->taxon = new Taxon();
        $this->taxon->setCode('category');
        $this->payment = new PaymentMethod();
        $this->payment->setCode('cash_on_delivery');
        $this->shipping = new ShippingMethod();
        $this->shipping->setCode('eu_standard');
        $this->shipping->setCalculator('flat_rate');
        $this->shipping->setConfiguration(['HQ' => ['amount' => 790]]);
    }

    public function testCreatesANewChannelWithAllItsSettings(): void
    {
        $entityManager = $this->createMock(EntityManagerInterface::class);
        $created = null;
        $entityManager->expects(self::once())->method('persist')->willReturnCallback(static function (object $entity) use (&$created): void {
            $created = $entity;
        });

        $report = $this->provisioner($entityManager)->provision($this->definition());

        self::assertInstanceOf(Channel::class, $created);
        self::assertSame('SITE_BE', $created->getCode());
        self::assertSame('Site Belgique', $created->getName());
        self::assertSame('be.sylius.localhost', $created->getHostname());
        self::assertSame('fr_FR', $created->getDefaultLocale()?->getCode());
        self::assertSame('EUR', $created->getBaseCurrency()?->getCode());
        self::assertSame('EU', $created->getDefaultTaxZone()?->getCode());
        self::assertSame('category', $created->getMenuTaxon()?->getCode());
        self::assertSame('belgique@example.com', $created->getContactEmail());
        self::assertTrue($this->payment->hasChannel($created));
        self::assertTrue($this->shipping->hasChannel($created));
        self::assertSame(['amount' => 890], $this->shipping->getConfiguration()['SITE_BE']);
        self::assertSame(['amount' => 790], $this->shipping->getConfiguration()['HQ'], 'Le tarif des autres channels est conservé.');
        self::assertSame('créé', $report->changes()[0][0]);
    }

    public function testASecondRunChangesNothing(): void
    {
        $created = null;
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$created): void {
            $created = $entity;
        });
        $this->provisioner($entityManager)->provision($this->definition());
        self::assertInstanceOf(ChannelInterface::class, $created);

        $secondEntityManager = $this->createMock(EntityManagerInterface::class);
        $secondEntityManager->expects(self::never())->method('persist');
        $report = $this->provisioner($secondEntityManager, existing: $created)->provision($this->definition());

        self::assertFalse($report->hasChanges(), json_encode($report->changes(), \JSON_THROW_ON_ERROR));
        self::assertSame([], $report->warnings());
    }

    public function testUpdatesAnExistingChannelAndReportsTheDifferences(): void
    {
        $existing = new Channel();
        $existing->setCode('SITE_BE');
        $existing->setName('Ancien nom');

        $report = $this->provisioner($this->createStub(EntityManagerInterface::class), existing: $existing)->provision($this->definition());

        self::assertSame('Site Belgique', $existing->getName());
        $details = array_column($report->changes(), 2);
        self::assertContains('name : Ancien nom → Site Belgique', $details);
        self::assertNotContains('créé', array_column($report->changes(), 0));
    }

    public function testUpdatesTheFlatRateAmountOfAnExistingChannel(): void
    {
        $definition = $this->definition();
        $this->shipping->setConfiguration(['SITE_BE' => ['amount' => 500]]);

        $report = $this->provisioner($this->createStub(EntityManagerInterface::class))->provision($definition);

        self::assertSame(['amount' => 890], $this->shipping->getConfiguration()['SITE_BE']);
        self::assertContains('tarif flat_rate SITE_BE : 500 → 890', array_column($report->changes(), 2));
    }

    public function testReportsAllMissingPrerequisitesAtOnce(): void
    {
        $provisioner = $this->provisioner($this->createStub(EntityManagerInterface::class), locales: [], zones: [], paymentMethods: []);

        try {
            $provisioner->provision($this->definition());
            self::fail('Une SiteProvisioningException était attendue.');
        } catch (SiteProvisioningException $exception) {
            $message = implode("\n", $exception->errors());
            self::assertStringContainsString('Locale « fr_FR » introuvable', $message);
            self::assertStringContainsString('Zone « EU » introuvable', $message);
            self::assertStringContainsString('Payment method « cash_on_delivery » introuvable', $message);
        }
    }

    public function testRejectsAHostnameUsedByAnotherChannel(): void
    {
        $other = new Channel();
        $other->setCode('SITE_FR');

        $this->expectException(SiteProvisioningException::class);
        $this->expectExceptionMessage('déjà utilisé par le channel « SITE_FR »');

        $this->provisioner($this->createStub(EntityManagerInterface::class), sameHostname: [$other])->provision($this->definition());
    }

    public function testRejectsAFlatRateMethodWithoutAmount(): void
    {
        $this->expectException(SiteProvisioningException::class);
        $this->expectExceptionMessage('le montant « amount » (centimes) est obligatoire');

        $this->provisioner($this->createStub(EntityManagerInterface::class))->provision($this->definition(shippingMethods: ['eu_standard' => null]));
    }

    public function testDoesNotAttachAnUnsupportedCalculatorWithoutTariff(): void
    {
        $this->shipping->setCalculator('per_unit_rate');
        $this->shipping->setConfiguration([]);

        $report = $this->provisioner($this->createStub(EntityManagerInterface::class))->provision($this->definition(shippingMethods: ['eu_standard' => null]));

        self::assertSame([], $this->shipping->getConfiguration());
        self::assertCount(0, $this->shipping->getChannels(), 'Sans tarif pour le channel, la method serait vendable puis planterait au calcul.');
        self::assertStringContainsString('calculateur « per_unit_rate » non géré', $report->warnings()[0]);
    }

    public function testAttachesAnUnsupportedCalculatorWhenItsTariffAlreadyExists(): void
    {
        $this->shipping->setCalculator('per_unit_rate');
        $this->shipping->setConfiguration(['SITE_BE' => ['amount' => 100]]);

        $report = $this->provisioner($this->createStub(EntityManagerInterface::class))->provision($this->definition(shippingMethods: ['eu_standard' => null]));

        self::assertCount(1, $this->shipping->getChannels());
        self::assertSame(['SITE_BE' => ['amount' => 100]], $this->shipping->getConfiguration());
        self::assertSame([], $report->warnings());
    }

    public function testFlatRateAmountMayBeOmittedWhenTheTariffAlreadyExists(): void
    {
        $this->shipping->setConfiguration(['SITE_BE' => ['amount' => 500]]);

        $this->provisioner($this->createStub(EntityManagerInterface::class))->provision($this->definition(shippingMethods: ['eu_standard' => null]));

        self::assertSame(['amount' => 500], $this->shipping->getConfiguration()['SITE_BE']);
        self::assertCount(1, $this->shipping->getChannels());
    }

    public function testAbsentOptionalSettingsDoNotOverwriteAnExistingChannel(): void
    {
        $existing = new Channel();
        $existing->setCode('SITE_BE');
        $existing->setEnabled(false);
        $existing->setTaxCalculationStrategy('order_item_units_based');

        $this->provisioner($this->createStub(EntityManagerInterface::class), existing: $existing)->provision($this->definition(enabled: null, strategy: null));

        self::assertFalse($existing->isEnabled());
        self::assertSame('order_item_units_based', $existing->getTaxCalculationStrategy());
    }

    public function testSameHostnameOnTheSameChannelIsNotAConflict(): void
    {
        $existing = new Channel();
        $existing->setCode('SITE_BE');
        $existing->setHostname('be.sylius.localhost');

        $report = $this->provisioner($this->createStub(EntityManagerInterface::class), existing: $existing, sameHostname: [$existing])->provision($this->definition());

        self::assertNotContains('hostname', array_map(static fn (array $change): string => explode(' : ', $change[2])[0], $report->changes()));
    }

    public function testKeepsExtraLocalesAndWarns(): void
    {
        $existing = new Channel();
        $existing->setCode('SITE_BE');
        $en = new Locale();
        $en->setCode('en_US');
        $existing->addLocale($en);

        $report = $this->provisioner($this->createStub(EntityManagerInterface::class), existing: $existing)->provision($this->definition());

        self::assertTrue($existing->hasLocale($en));
        self::assertStringContainsString('Locale « en_US » présente sur SITE_BE mais absente du fichier : conservée', implode("\n", $report->warnings()));
    }

    public function testValidatesTheChannelInTheSyliusGroupAndStopsBeforeAttachingMethods(): void
    {
        $violations = new ConstraintViolationList([new ConstraintViolation('Cette valeur n\'est pas un email valide.', null, [], null, 'contactEmail', null)]);
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects(self::once())->method('validate')->with(self::isInstanceOf(Channel::class), null, ['sylius'])->willReturn($violations);

        try {
            $this->provisioner($this->createStub(EntityManagerInterface::class), validator: $validator)->provision($this->definition());
            self::fail('Une SiteProvisioningException était attendue.');
        } catch (SiteProvisioningException $exception) {
            self::assertSame(['contactEmail : Cette valeur n\'est pas un email valide.'], $exception->errors());
        }

        self::assertCount(0, $this->payment->getChannels());
        self::assertCount(0, $this->shipping->getChannels());
        self::assertArrayNotHasKey('SITE_BE', $this->shipping->getConfiguration());
    }

    public function testDoesNotValidateWhenTheChannelHasNoChange(): void
    {
        $created = null;
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use (&$created): void {
            $created = $entity;
        });
        $this->provisioner($entityManager)->provision($this->definition());
        self::assertInstanceOf(ChannelInterface::class, $created);

        $validator = $this->createMock(ValidatorInterface::class);
        $validator->expects(self::never())->method('validate');

        $this->provisioner($this->createStub(EntityManagerInterface::class), existing: $created, validator: $validator)->provision($this->definition());
    }

    /** @param array<string, ?int> $shippingMethods */
    private function definition(array $shippingMethods = ['eu_standard' => 890], ?bool $enabled = true, ?string $strategy = 'order_items_based'): SiteDefinition
    {
        return new SiteDefinition(
            'SITE_BE',
            'Site Belgique',
            'be.sylius.localhost',
            'fr_FR',
            ['fr_FR'],
            'EUR',
            ['EUR'],
            'EU',
            $strategy,
            'category',
            'belgique@example.com',
            $enabled,
            ['cash_on_delivery'],
            $shippingMethods,
        );
    }

    /**
     * @param array<string, LocaleInterface>|null  $locales
     * @param array<string, ZoneInterface>|null    $zones
     * @param array<string, PaymentMethodInterface>|null $paymentMethods
     * @param list<ChannelInterface>      $sameHostname
     */
    private function provisioner(
        EntityManagerInterface $entityManager,
        ?ChannelInterface $existing = null,
        ?array $locales = null,
        ?array $zones = null,
        ?array $paymentMethods = null,
        array $sameHostname = [],
        ?ValidatorInterface $validator = null,
    ): SiteProvisioner {
        $channelRepository = $this->createStub(ChannelRepositoryInterface::class);
        $channelRepository->method('findOneByCode')->willReturn($existing);
        $channelRepository->method('findBy')->willReturn($sameHostname);

        $factory = $this->createStub(FactoryInterface::class);
        $factory->method('createNew')->willReturnCallback(static fn (): Channel => new Channel());

        if (null === $validator) {
            $validator = $this->createStub(ValidatorInterface::class);
            $validator->method('validate')->willReturn(new ConstraintViolationList());
        }

        return new SiteProvisioner(
            $factory,
            $channelRepository,
            $this->repository($locales ?? ['fr_FR' => $this->locale]),
            $this->repository(['EUR' => $this->currency]),
            $this->repository($zones ?? ['EU' => $this->zone]),
            $this->repository(['category' => $this->taxon]),
            $this->repository($paymentMethods ?? ['cash_on_delivery' => $this->payment]),
            $this->repository(['eu_standard' => $this->shipping]),
            $entityManager,
            $validator,
        );
    }

    /**
     * @template T of ResourceInterface
     *
     * @param array<string, T> $resourcesByCode
     *
     * @return RepositoryInterface<T>
     */
    private function repository(array $resourcesByCode): RepositoryInterface
    {
        $repository = $this->createStub(RepositoryInterface::class);
        $repository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?object => $resourcesByCode[(string) $criteria['code']] ?? null,
        );

        return $repository;
    }
}
