<?php

declare(strict_types=1);

namespace App\Formation\Module14MultiSite\Provisioning;

use Doctrine\ORM\EntityManagerInterface;
use Sylius\Component\Addressing\Model\ZoneInterface;
use Sylius\Component\Channel\Repository\ChannelRepositoryInterface;
use Sylius\Component\Core\Model\ChannelInterface;
use Sylius\Component\Core\Model\PaymentMethodInterface;
use Sylius\Component\Core\Model\ShippingMethodInterface;
use Sylius\Component\Core\Model\TaxonInterface;
use Sylius\Component\Currency\Model\CurrencyInterface;
use Sylius\Component\Locale\Model\LocaleInterface;
use Sylius\Resource\Doctrine\Persistence\RepositoryInterface;
use Sylius\Resource\Factory\FactoryInterface;
use Sylius\Resource\Model\ResourceInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Webmozart\Assert\Assert;

/**
 * Formation M14 : ouvre un site (channel) de façon idempotente.
 *
 * Clé naturelle : le code du channel. Les prérequis (locales, devises, zone, taxon, methods) doivent déjà exister :
 * ils ne sont jamais créés implicitement. Les locales et devises en trop sur un channel existant sont conservées
 * (avertissement), jamais retirées.
 */
final class SiteProvisioner implements SiteProvisionerInterface
{
    private const FLAT_RATE = 'flat_rate';

    /**
     * @param FactoryInterface<ChannelInterface>             $channelFactory
     * @param ChannelRepositoryInterface<ChannelInterface>   $channelRepository
     * @param RepositoryInterface<LocaleInterface>           $localeRepository
     * @param RepositoryInterface<CurrencyInterface>         $currencyRepository
     * @param RepositoryInterface<ZoneInterface>             $zoneRepository
     * @param RepositoryInterface<TaxonInterface>            $taxonRepository
     * @param RepositoryInterface<PaymentMethodInterface>    $paymentMethodRepository
     * @param RepositoryInterface<ShippingMethodInterface>   $shippingMethodRepository
     */
    public function __construct(
        #[Autowire(service: 'sylius.factory.channel')]
        private readonly FactoryInterface $channelFactory,
        #[Autowire(service: 'sylius.repository.channel')]
        private readonly ChannelRepositoryInterface $channelRepository,
        #[Autowire(service: 'sylius.repository.locale')]
        private readonly RepositoryInterface $localeRepository,
        #[Autowire(service: 'sylius.repository.currency')]
        private readonly RepositoryInterface $currencyRepository,
        #[Autowire(service: 'sylius.repository.zone')]
        private readonly RepositoryInterface $zoneRepository,
        #[Autowire(service: 'sylius.repository.taxon')]
        private readonly RepositoryInterface $taxonRepository,
        #[Autowire(service: 'sylius.repository.payment_method')]
        private readonly RepositoryInterface $paymentMethodRepository,
        #[Autowire(service: 'sylius.repository.shipping_method')]
        private readonly RepositoryInterface $shippingMethodRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly ValidatorInterface $validator,
    ) {
    }

    public function provision(SiteDefinition $definition): ProvisioningReport
    {
        $report = new ProvisioningReport();
        $errors = [];

        $locales = [];
        foreach ($definition->locales as $code) {
            $locale = $this->find($this->localeRepository, $code, 'Locale', $errors);
            if (null !== $locale) {
                $locales[$code] = $locale;
            }
        }
        $currencies = [];
        foreach ($definition->currencies as $code) {
            $currency = $this->find($this->currencyRepository, $code, 'Devise', $errors);
            if (null !== $currency) {
                $currencies[$code] = $currency;
            }
        }
        $zone = null !== $definition->defaultTaxZone ? $this->find($this->zoneRepository, $definition->defaultTaxZone, 'Zone', $errors) : null;
        $taxon = null !== $definition->menuTaxon ? $this->find($this->taxonRepository, $definition->menuTaxon, 'Taxon', $errors) : null;

        $paymentMethods = [];
        foreach ($definition->paymentMethods as $code) {
            $method = $this->find($this->paymentMethodRepository, $code, 'Payment method', $errors);
            if (null !== $method) {
                $paymentMethods[] = $method;
            }
        }

        $shippingMethods = [];
        foreach ($definition->shippingMethods as $code => $amount) {
            $method = $this->find($this->shippingMethodRepository, (string) $code, 'Shipping method', $errors);
            if (null === $method) {
                continue;
            }
            $hasTariff = null !== ($method->getConfiguration()[$definition->code] ?? null);
            if (self::FLAT_RATE === $method->getCalculator()) {
                if (null === $amount && !$hasTariff) {
                    $errors[] = sprintf('Shipping method « %s » (flat_rate) : le montant « amount » (centimes) est obligatoire, aucun tarif n\'existe pour %s.', $code, $definition->code);
                }
            } elseif (!$hasTariff) {
                $report->warn(sprintf('Shipping method « %s » : calculateur « %s » non géré, elle n\'est pas rattachée à %s. Rattachez-la depuis l\'admin après l\'ouverture du site.', $code, (string) $method->getCalculator(), $definition->code));

                continue;
            }
            $shippingMethods[] = [$method, $amount];
        }

        foreach ($this->channelRepository->findBy(['hostname' => $definition->hostname]) as $other) {
            if ($other->getCode() !== $definition->code) {
                $errors[] = sprintf('Le hostname « %s » est déjà utilisé par le channel « %s ».', $definition->hostname, (string) $other->getCode());
            }
        }

        if ([] !== $errors) {
            throw new SiteProvisioningException($errors);
        }

        $channel = $this->channelRepository->findOneByCode($definition->code);
        if (null === $channel) {
            $channel = $this->channelFactory->createNew();
            $channel->setCode($definition->code);
            $this->entityManager->persist($channel);
            $report->change('créé', sprintf('channel %s', $definition->code), sprintf('hostname %s', $definition->hostname));
        }
        Assert::isInstanceOf($channel, ChannelInterface::class);

        $this->applyChannel($channel, $definition, $locales, $currencies, $zone, $taxon, $report);

        if ($report->hasChanges()) {
            $violations = $this->validator->validate($channel, null, ['sylius']);
            if (count($violations) > 0) {
                $messages = [];
                foreach ($violations as $violation) {
                    $messages[] = sprintf('%s : %s', $violation->getPropertyPath(), (string) $violation->getMessage());
                }

                throw new SiteProvisioningException($messages);
            }
        }

        foreach ($paymentMethods as $method) {
            if (!$method->hasChannel($channel)) {
                $method->addChannel($channel);
                $report->change('modifié', sprintf('payment method %s', (string) $method->getCode()), sprintf('rattachée à %s', $definition->code));
            }
        }

        foreach ($shippingMethods as [$method, $amount]) {
            $this->applyShippingMethod($method, $amount, $channel, $report);
        }

        return $report;
    }

    /**
     * @param array<string, LocaleInterface>   $locales
     * @param array<string, CurrencyInterface> $currencies
     */
    private function applyChannel(
        ChannelInterface $channel,
        SiteDefinition $definition,
        array $locales,
        array $currencies,
        ?ZoneInterface $zone,
        ?TaxonInterface $taxon,
        ProvisioningReport $report,
    ): void {
        $subject = sprintf('channel %s', $definition->code);

        if ($channel->getName() !== $definition->name) {
            $this->record($report, $subject, 'name', $channel->getName(), $definition->name);
            $channel->setName($definition->name);
        }
        if ($channel->getHostname() !== $definition->hostname) {
            $this->record($report, $subject, 'hostname', $channel->getHostname(), $definition->hostname);
            $channel->setHostname($definition->hostname);
        }
        if (null !== $definition->enabled && $channel->isEnabled() !== $definition->enabled) {
            $this->record($report, $subject, 'enabled', $channel->isEnabled() ? 'oui' : 'non', $definition->enabled ? 'oui' : 'non');
            $channel->setEnabled($definition->enabled);
        }
        if (null !== $definition->contactEmail && $channel->getContactEmail() !== $definition->contactEmail) {
            $this->record($report, $subject, 'contact_email', $channel->getContactEmail(), $definition->contactEmail);
            $channel->setContactEmail($definition->contactEmail);
        }
        if (null !== $definition->taxCalculationStrategy && $channel->getTaxCalculationStrategy() !== $definition->taxCalculationStrategy) {
            $this->record($report, $subject, 'tax_calculation_strategy', $channel->getTaxCalculationStrategy(), $definition->taxCalculationStrategy);
            $channel->setTaxCalculationStrategy($definition->taxCalculationStrategy);
        }
        if (null !== $zone && $channel->getDefaultTaxZone()?->getCode() !== $zone->getCode()) {
            $this->record($report, $subject, 'default_tax_zone', $channel->getDefaultTaxZone()?->getCode(), $zone->getCode());
            $channel->setDefaultTaxZone($zone);
        }
        if (null !== $taxon && $channel->getMenuTaxon()?->getCode() !== $taxon->getCode()) {
            $this->record($report, $subject, 'menu_taxon', $channel->getMenuTaxon()?->getCode(), $taxon->getCode());
            $channel->setMenuTaxon($taxon);
        }

        foreach ($locales as $code => $locale) {
            if (!$channel->hasLocale($locale)) {
                $channel->addLocale($locale);
                $report->change('modifié', $subject, sprintf('locale ajoutée : %s', $code));
            }
        }
        $defaultLocale = $locales[$definition->defaultLocale];
        if ($channel->getDefaultLocale()?->getCode() !== $definition->defaultLocale) {
            $this->record($report, $subject, 'default_locale', $channel->getDefaultLocale()?->getCode(), $definition->defaultLocale);
            $channel->setDefaultLocale($defaultLocale);
        }
        foreach ($channel->getLocales() as $locale) {
            if (!isset($locales[(string) $locale->getCode()])) {
                $report->warn(sprintf('Locale « %s » présente sur %s mais absente du fichier : conservée.', (string) $locale->getCode(), $definition->code));
            }
        }

        foreach ($currencies as $code => $currency) {
            if (!$channel->hasCurrency($currency)) {
                $channel->addCurrency($currency);
                $report->change('modifié', $subject, sprintf('devise ajoutée : %s', $code));
            }
        }
        $baseCurrency = $currencies[$definition->baseCurrency];
        if ($channel->getBaseCurrency()?->getCode() !== $definition->baseCurrency) {
            $this->record($report, $subject, 'base_currency', $channel->getBaseCurrency()?->getCode(), $definition->baseCurrency);
            $channel->setBaseCurrency($baseCurrency);
        }
        foreach ($channel->getCurrencies() as $currency) {
            if (!isset($currencies[(string) $currency->getCode()])) {
                $report->warn(sprintf('Devise « %s » présente sur %s mais absente du fichier : conservée.', (string) $currency->getCode(), $definition->code));
            }
        }
    }

    private function applyShippingMethod(ShippingMethodInterface $method, ?int $amount, ChannelInterface $channel, ProvisioningReport $report): void
    {
        $channelCode = (string) $channel->getCode();
        $subject = sprintf('shipping method %s', (string) $method->getCode());

        if (!$method->hasChannel($channel)) {
            $method->addChannel($channel);
            $report->change('modifié', $subject, sprintf('rattachée à %s', $channelCode));
        }

        if (self::FLAT_RATE !== $method->getCalculator() || null === $amount) {
            return;
        }

        $configuration = $method->getConfiguration();
        $channelConfiguration = $configuration[$channelCode] ?? null;
        $current = is_array($channelConfiguration) && is_int($channelConfiguration['amount'] ?? null) ? $channelConfiguration['amount'] : null;
        if ($current !== $amount) {
            $configuration[$channelCode] = ['amount' => $amount];
            $method->setConfiguration($configuration);
            $report->change('modifié', $subject, sprintf('tarif flat_rate %s : %s → %d', $channelCode, null === $current ? '(aucun)' : (string) $current, $amount));
        }
    }

    private function record(ProvisioningReport $report, string $subject, string $field, ?string $old, ?string $new): void
    {
        $report->change('modifié', $subject, sprintf('%s : %s → %s', $field, $old ?? '(vide)', $new ?? '(vide)'));
    }

    /**
     * @template T of ResourceInterface
     *
     * @param RepositoryInterface<T> $repository
     * @param list<string>           $errors
     *
     * @return T|null
     */
    private function find(RepositoryInterface $repository, string $code, string $label, array &$errors): ?object
    {
        $resource = $repository->findOneBy(['code' => $code]);

        if (null === $resource) {
            $errors[] = sprintf('%s « %s » introuvable : elle doit exister avant l\'ouverture du site.', $label, $code);

            return null;
        }

        return $resource;
    }
}
