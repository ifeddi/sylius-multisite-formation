<?php

declare(strict_types=1);

namespace App\Formation\Module14MultiSite\Provisioning;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/** Lit et valide un fichier YAML décrivant un site (voir data/formation/sites/site-be.yaml). */
final class SiteDefinitionLoader
{
    private const KEYS = [
        'code', 'name', 'hostname', 'default_locale', 'locales', 'base_currency', 'currencies', 'default_tax_zone',
        'tax_calculation_strategy', 'menu_taxon', 'contact_email', 'enabled', 'payment_methods', 'shipping_methods',
    ];

    /** @throws SiteProvisioningException */
    public function load(string $path): SiteDefinition
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new SiteProvisioningException([sprintf('Fichier introuvable ou illisible : %s', $path)]);
        }

        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $exception) {
            throw new SiteProvisioningException([sprintf('YAML invalide : %s', $exception->getMessage())], $exception);
        }

        if (!is_array($data) || !isset($data['site']) || !is_array($data['site'])) {
            throw new SiteProvisioningException(['La clé racine « site » est absente ou n\'est pas un tableau.']);
        }

        /** @var array<array-key, mixed> $site */
        $site = $data['site'];
        $errors = [];

        foreach (array_diff(array_map('strval', array_keys($site)), self::KEYS) as $unknown) {
            $errors[] = sprintf('Clé inconnue « %s » (clés admises : %s).', $unknown, implode(', ', self::KEYS));
        }

        $code = $this->string($site, 'code', true, $errors) ?? '';
        $name = $this->string($site, 'name', true, $errors) ?? '';
        $hostname = strtolower($this->string($site, 'hostname', true, $errors) ?? '');
        $defaultLocale = $this->string($site, 'default_locale', true, $errors) ?? '';
        $baseCurrency = $this->string($site, 'base_currency', true, $errors) ?? '';
        $locales = $this->stringList($site, 'locales', true, $errors);
        $currencies = $this->stringList($site, 'currencies', true, $errors);
        $strategy = $this->string($site, 'tax_calculation_strategy', false, $errors);
        $enabled = $site['enabled'] ?? null;

        if ('' !== $code && 1 !== preg_match('/^[\w-]+$/', $code)) {
            $errors[] = sprintf('« code » (%s) ne peut contenir que lettres, chiffres, _ et -.', $code);
        }
        if ('' !== $hostname && 1 !== preg_match('/^[a-z0-9]([a-z0-9.-]*[a-z0-9])?$/i', $hostname)) {
            $errors[] = sprintf('« hostname » (%s) doit être un nom d\'hôte sans schéma ni chemin.', $hostname);
        }
        if (null !== $strategy && !in_array($strategy, SiteDefinition::TAX_STRATEGIES, true)) {
            $errors[] = sprintf('« tax_calculation_strategy » (%s) doit valoir : %s.', $strategy, implode(', ', SiteDefinition::TAX_STRATEGIES));
        }
        if (null !== $enabled && !is_bool($enabled)) {
            $errors[] = '« enabled » doit être un booléen (true ou false).';
        }
        if ('' !== $defaultLocale && [] !== $locales && !in_array($defaultLocale, $locales, true)) {
            $errors[] = sprintf('« default_locale » (%s) doit faire partie de « locales ».', $defaultLocale);
        }
        if ('' !== $baseCurrency && [] !== $currencies && !in_array($baseCurrency, $currencies, true)) {
            $errors[] = sprintf('« base_currency » (%s) doit faire partie de « currencies ».', $baseCurrency);
        }

        $shippingMethods = $this->shippingMethods($site, $errors);

        if ([] !== $errors) {
            throw new SiteProvisioningException($errors);
        }

        return new SiteDefinition(
            $code,
            $name,
            $hostname,
            $defaultLocale,
            $locales,
            $baseCurrency,
            $currencies,
            $this->string($site, 'default_tax_zone', false, $errors),
            $strategy,
            $this->string($site, 'menu_taxon', false, $errors),
            $this->string($site, 'contact_email', false, $errors),
            is_bool($enabled) ? $enabled : null,
            $this->stringList($site, 'payment_methods', false, $errors),
            $shippingMethods,
        );
    }

    /**
     * @param array<array-key, mixed> $site
     * @param list<string>            $errors
     */
    private function string(array $site, string $key, bool $required, array &$errors): ?string
    {
        $value = $site[$key] ?? null;

        if (null === $value) {
            if ($required) {
                $errors[] = sprintf('« %s » est obligatoire.', $key);
            }

            return null;
        }

        if (!is_string($value) || '' === trim($value)) {
            $errors[] = sprintf('« %s » doit être un texte non vide.', $key);

            return null;
        }

        return trim($value);
    }

    /**
     * @param array<array-key, mixed> $site
     * @param list<string>            $errors
     *
     * @return list<string>
     */
    private function stringList(array $site, string $key, bool $required, array &$errors): array
    {
        $value = $site[$key] ?? null;

        if (null === $value) {
            if ($required) {
                $errors[] = sprintf('« %s » est obligatoire (liste non vide).', $key);
            }

            return [];
        }

        if (!is_array($value) || !array_is_list($value) || ($required && [] === $value)) {
            $errors[] = sprintf('« %s » doit être une liste de textes%s.', $key, $required ? ' non vide' : '');

            return [];
        }

        $list = [];
        foreach ($value as $item) {
            if (!is_string($item) || '' === trim($item)) {
                $errors[] = sprintf('« %s » ne peut contenir que des textes non vides.', $key);

                return [];
            }
            $list[] = trim($item);
        }

        return $list;
    }

    /**
     * @param array<array-key, mixed> $site
     * @param list<string>            $errors
     *
     * @return array<string, ?int>
     */
    private function shippingMethods(array $site, array &$errors): array
    {
        $value = $site['shipping_methods'] ?? [];

        if (!is_array($value) || !array_is_list($value)) {
            $errors[] = '« shipping_methods » doit être une liste d\'éléments { code, amount }.';

            return [];
        }

        $methods = [];
        foreach ($value as $index => $item) {
            if (!is_array($item) || !isset($item['code']) || !is_string($item['code']) || '' === trim($item['code'])) {
                $errors[] = sprintf('« shipping_methods[%d] » doit contenir un « code » (texte non vide).', $index);

                continue;
            }

            $amount = $item['amount'] ?? null;
            if (null !== $amount && (!is_int($amount) || $amount < 0)) {
                $errors[] = sprintf('« shipping_methods[%d].amount » (%s) doit être un entier positif en centimes.', $index, get_debug_type($amount));

                continue;
            }

            $methods[trim($item['code'])] = $amount;
        }

        return $methods;
    }
}
