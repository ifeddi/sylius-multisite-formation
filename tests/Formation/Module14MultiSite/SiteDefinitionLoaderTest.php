<?php

declare(strict_types=1);

namespace App\Tests\Formation\Module14MultiSite;

use App\Formation\Module14MultiSite\Provisioning\SiteDefinitionLoader;
use App\Formation\Module14MultiSite\Provisioning\SiteProvisioningException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Formation M14 : lecture et validation du fichier YAML d'un site. */
final class SiteDefinitionLoaderTest extends TestCase
{
    private const VALID = <<<'YAML'
        site:
            code: SITE_BE
            name: 'Site Belgique'
            hostname: be.sylius.localhost
            default_locale: fr_FR
            locales: [fr_FR]
            base_currency: EUR
            currencies: [EUR]
            default_tax_zone: EU
            menu_taxon: category
            contact_email: belgique@example.com
            payment_methods: [cash_on_delivery]
            shipping_methods:
                - { code: eu_standard, amount: 890 }
        YAML;

    /** @var list<string> */
    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    public function testLoadsAValidDefinition(): void
    {
        $definition = (new SiteDefinitionLoader())->load($this->write(self::VALID));

        self::assertSame('SITE_BE', $definition->code);
        self::assertSame('be.sylius.localhost', $definition->hostname);
        self::assertSame(['fr_FR'], $definition->locales);
        self::assertSame('EU', $definition->defaultTaxZone);
        self::assertNull($definition->taxCalculationStrategy, 'Absent du fichier : la valeur du channel est conservée.');
        self::assertNull($definition->enabled, 'Absent du fichier : la valeur du channel est conservée.');
        self::assertSame(['cash_on_delivery'], $definition->paymentMethods);
        self::assertSame(['eu_standard' => 890], $definition->shippingMethods);
    }

    public function testTheShippedExampleFileIsValid(): void
    {
        $definition = (new SiteDefinitionLoader())->load(dirname(__DIR__, 3) . '/data/formation/sites/site-be.yaml');

        self::assertSame('SITE_BE', $definition->code);
        self::assertSame('be.sylius.localhost', $definition->hostname);
        self::assertSame(['eu_standard' => 890], $definition->shippingMethods);
    }

    public function testOptionalFieldsDefaultToNull(): void
    {
        $definition = (new SiteDefinitionLoader())->load($this->write(<<<'YAML'
            site:
                code: SITE_X
                name: X
                hostname: x.localhost
                default_locale: en_US
                locales: [en_US]
                base_currency: USD
                currencies: [USD]
            YAML));

        self::assertNull($definition->defaultTaxZone);
        self::assertNull($definition->menuTaxon);
        self::assertNull($definition->contactEmail);
        self::assertSame([], $definition->paymentMethods);
        self::assertSame([], $definition->shippingMethods);
    }

    public function testExplicitEnabledAndStrategyAreKept(): void
    {
        $definition = (new SiteDefinitionLoader())->load($this->write(self::VALID . "\n    enabled: false\n    tax_calculation_strategy: order_item_units_based\n"));

        self::assertFalse($definition->enabled);
        self::assertSame('order_item_units_based', $definition->taxCalculationStrategy);
    }

    public function testHostnameIsLowercased(): void
    {
        $definition = (new SiteDefinitionLoader())->load($this->write(str_replace('be.sylius.localhost', 'BE.Sylius.Localhost', self::VALID)));

        self::assertSame('be.sylius.localhost', $definition->hostname);
    }

    public function testFlatRateAmountMayBeOmitted(): void
    {
        $definition = (new SiteDefinitionLoader())->load($this->write(str_replace('{ code: eu_standard, amount: 890 }', '{ code: eu_standard }', self::VALID)));

        self::assertSame(['eu_standard' => null], $definition->shippingMethods);
    }

    /** @param list<string> $expected fragments attendus dans les erreurs */
    #[DataProvider('invalidDefinitions')]
    public function testRejectsInvalidDefinitions(string $yaml, array $expected): void
    {
        try {
            (new SiteDefinitionLoader())->load($this->write($yaml));
            self::fail('Une SiteProvisioningException était attendue.');
        } catch (SiteProvisioningException $exception) {
            $message = implode("\n", $exception->errors());
            foreach ($expected as $fragment) {
                self::assertStringContainsString($fragment, $message);
            }
        }
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function invalidDefinitions(): iterable
    {
        yield 'racine absente' => ["foo: bar\n", ['clé racine « site »']];
        yield 'champ obligatoire manquant' => [
            "site:\n    code: X\n",
            ['« name » est obligatoire', '« hostname » est obligatoire', '« locales » est obligatoire'],
        ];
        yield 'clé inconnue' => [
            str_replace('name:', 'nom:', self::VALID),
            ['Clé inconnue « nom »', '« name » est obligatoire'],
        ];
        yield 'locale par défaut hors liste' => [
            str_replace('default_locale: fr_FR', 'default_locale: de_DE', self::VALID),
            ['« default_locale » (de_DE) doit faire partie de « locales »'],
        ];
        yield 'devise de base hors liste' => [
            str_replace('base_currency: EUR', 'base_currency: USD', self::VALID),
            ['« base_currency » (USD) doit faire partie de « currencies »'],
        ];
        yield 'hostname avec schéma' => [
            str_replace('be.sylius.localhost', 'https://be.sylius.localhost', self::VALID),
            ['« hostname »'],
        ];
        yield 'code invalide' => [
            str_replace('code: SITE_BE', "code: 'SITE BE'", self::VALID),
            ['« code » (SITE BE)'],
        ];
        yield 'stratégie de taxe inconnue' => [
            str_replace('menu_taxon: category', "menu_taxon: category\n    tax_calculation_strategy: foo", self::VALID),
            ['« tax_calculation_strategy » (foo)'],
        ];
        yield 'montant négatif' => [
            str_replace('amount: 890', 'amount: -5', self::VALID),
            ['« shipping_methods[0].amount »'],
        ];
    }

    public function testRejectsMissingFile(): void
    {
        $this->expectException(SiteProvisioningException::class);
        $this->expectExceptionMessage('Fichier introuvable');

        (new SiteDefinitionLoader())->load('/chemin/qui/nexiste/pas.yaml');
    }

    public function testRejectsMalformedYaml(): void
    {
        $this->expectException(SiteProvisioningException::class);
        $this->expectExceptionMessage('YAML invalide');

        (new SiteDefinitionLoader())->load($this->write("site: [unclosed\n"));
    }

    private function write(string $yaml): string
    {
        $path = tempnam(sys_get_temp_dir(), 'site');
        self::assertNotFalse($path);
        file_put_contents($path, $yaml);
        $this->files[] = $path;

        return $path;
    }
}
