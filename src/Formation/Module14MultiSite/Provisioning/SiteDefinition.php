<?php

declare(strict_types=1);

namespace App\Formation\Module14MultiSite\Provisioning;

use Webmozart\Assert\Assert;

/**
 * Description d'un site (channel) à ouvrir. Les champs optionnels à null (zone, taxon, email, stratégie de taxe,
 * enabled) ne sont pas gérés : la valeur existante du channel est conservée.
 */
final readonly class SiteDefinition
{
    public const TAX_STRATEGIES = ['order_items_based', 'order_item_units_based'];

    /**
     * @param list<string>        $locales
     * @param list<string>        $currencies
     * @param list<string>        $paymentMethods codes des payment methods à rattacher
     * @param array<string, ?int> $shippingMethods code de la shipping method => montant flat_rate en centimes (null : tarif déjà présent)
     */
    public function __construct(
        public string $code,
        public string $name,
        public string $hostname,
        public string $defaultLocale,
        public array $locales,
        public string $baseCurrency,
        public array $currencies,
        public ?string $defaultTaxZone,
        public ?string $taxCalculationStrategy,
        public ?string $menuTaxon,
        public ?string $contactEmail,
        public ?bool $enabled,
        public array $paymentMethods,
        public array $shippingMethods,
    ) {
        Assert::inArray($defaultLocale, $locales, 'La locale par défaut doit faire partie des locales.');
        Assert::inArray($baseCurrency, $currencies, 'La devise de base doit faire partie des devises.');
    }
}
