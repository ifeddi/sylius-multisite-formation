# Les principes SOLID dans Sylius (module 16)

**Verdict.** Sylius respecte SOLID dans sa conception générale : composants découplés, une interface pour presque
chaque rôle, extension par services taggés, événements et décorateurs, classes `final`. Il s'en écarte à quelques
endroits précis, qui sont des compromis assumés. Les extraits ci-dessous viennent du code réel de Sylius 2.3.1
(`vendor/sylius/sylius/src/Sylius/...`) et de ce projet (`src/Formation/...`).

---

## S : Single Responsibility (une seule raison de changer)

**Principe.** Une classe a une seule responsabilité : un seul motif de modification.

**Dans Sylius.** Le traitement d'une commande est découpé en une classe par préoccupation
(`Component/Core/OrderProcessing/` : `OrderTaxesProcessor`, `OrderPromotionProcessor`,
`OrderShipmentProcessor`, `OrderPaymentProcessor`, `OrderPricesRecalculator`, `ShippingChargesProcessor`…).
Chacune implémente `OrderProcessorInterface` (une seule méthode, `process(OrderInterface $order): void`).

**Erreur** (listener qui fait tout) :

```php
class SupplierListener
{
    public function __invoke($event): void
    {
        $s = $event->getSubject();
        $s->setCode(strtolower(trim($s->getCode())));                // normalisation
        mail($s->getContactEmail(), 'Bienvenue', '...');             // e-mail
        file_put_contents('/tmp/log', $s->getCode());                // journal
        $this->em->getConnection()->executeStatement('UPDATE ...');  // SQL
    }
}
```
Quatre raisons de changer, aucun test unitaire simple, impossible à réutiliser.

**Correction dans le projet.**
- `src/Formation/Module03Supplier/SupplierCodeNormalizer.php` : une seule tâche, testable seul.
- `src/Formation/Module03Supplier/EventListener/SupplierCodeListener.php` : branche l'événement et délègue.
- Test : `tests/Formation/Module03Supplier/SupplierCodeTest.php`.

---

## O : Open/Closed (ouvert à l'extension, fermé à la modification)

**Principe.** On ajoute un comportement en ajoutant du code, pas en modifiant le code existant.

**Dans Sylius.** Un calculateur de livraison est une classe qui implémente `CalculatorInterface`
(`calculate(...)`, `getType()`), enregistrée avec un tag :
`->tag('sylius.shipping_calculator', ['calculator' => 'flat_rate', ...])`. Les règles de promotion
(`sylius.promotion_rule_checker`) et les processeurs de commande (`sylius.order_processor` avec priorités
70, 60, 50, 40, 30, 20, 10, 0, assemblés par `CompositeOrderProcessor`) suivent le même modèle. De
nombreuses classes du cœur sont `final` (`CompositeOrderProcessor`, `OrderTaxesProcessor`, `FlatRateCalculator`) :
on ne les modifie pas, on les étend par les points prévus.

**Erreur** (un `if` par variante dans une seule classe) :

```php
public function compute(string $type, $shipment): int
{
    if ($type === 'flat_rate') { return 590; }
    elseif ($type === 'per_unit') { return 100 * $shipment->getShippingUnitCount(); }
    throw new \Exception('type inconnu');   // chaque nouveau tarif = modifier cette méthode
}
```

**Correction dans le projet.** On ajoute des comportements **sans toucher** au cœur :
- `AdminMenuListener` écoute `sylius.menu.admin.main` : l'entrée « Fournisseurs » est ajoutée sans modifier `MainMenuBuilder`.
- `PaymentCompletedListener` écoute `workflow.sylius_payment.completed.complete`.
- `SupplierFixture` est une nouvelle fixture, enregistrée par autoconfiguration.

---

## L : Liskov Substitution (substituer sans surprise)

**Principe.** Une sous-classe peut remplacer sa classe de base sans casser le code qui l'utilise : mêmes
contrats, pas de précondition plus stricte, pas de résultat plus pauvre.

**Erreur** (une sous-classe qui casse le contrat) :

```php
class Product extends BaseProduct
{
    public function getName(): ?string
    {
        throw new \LogicException('Utiliser getOriginName()');   // Twig, API Platform, panier appellent getName()
    }
}
```

**Correction dans le projet.** `src/Entity/Product/Product.php` **ajoute** un champ (`origin`) sans changer
aucun contrat existant ; `createTranslation()` renvoie bien un `ProductTranslationInterface`. Un `Product` du
projet reste utilisable partout où Sylius attend un produit (test : `ProductOriginTest`).

**Écart dans Sylius.** `OrderTaxesProcessor::process(BaseOrderInterface $order)` accepte l'interface de base puis
exige l'interface du cœur : `Assert::isInstanceOf($order, OrderInterface::class)`. La précondition est renforcée à
l'exécution : c'est un compromis entre le composant générique (`Order`) et le cœur (`Core`).

**Rencontré dans ce projet.** `ListSitesCommand` était typée avec l'interface de base d'un channel : PHPStan a refusé
`getLocales()`, qui n'existe que sur l'interface du cœur. Correction : `ChannelRepositoryInterface<Sylius\Component\Core\Model\ChannelInterface>`.

---

## I : Interface Segregation (interfaces petites et ciblées)

**Principe.** Un client ne doit pas dépendre de méthodes qu'il n'utilise pas.

**Dans Sylius** (mesuré sur 2.3.1) : `ChannelContextInterface` 1 méthode, `CartContextInterface` 1 méthode,
`RuleCheckerInterface` 1 méthode, `OrderProcessorInterface` 1 méthode, `CalculatorInterface` 2 méthodes.
`ProductInterface` est **composée** de petites interfaces : `ProductTaxonsAwareInterface`, `ChannelsAwareInterface`,
`ReviewableInterface`, `ImagesAwareInterface`.

**Erreur** (interface « fourre-tout ») :

```php
interface SupplierServiceInterface
{
    public function normalizeCode(string $code): string;
    public function sendWelcomeMail(Supplier $s): void;
    public function exportToCsv(array $suppliers): string;
    public function syncWithErp(Supplier $s): void;
    public function renderPdf(Supplier $s): string;
}
```
Un listener qui ne veut que `normalizeCode()` dépend (et doit doubler en test) des cinq méthodes.

**Correction dans le projet.** Un service par rôle, avec une API étroite : `SupplierCodeNormalizer` (1 méthode
publique). Le formulaire `ProductOriginTypeExtension` n'implémente que ce qu'une extension de formulaire demande.

**Écart dans Sylius.** `Core\Model\OrderInterface` déclare **44 méthodes**, en plus de celles héritées de
`BaseOrderInterface` et de cinq interfaces. C'est une interface large, et la promesse de compatibilité (docs, « Backwards Compatibility Promise »)
autorise l'ajout de méthodes aux interfaces de modèles, à condition que les modèles personnalisés étendent ceux de Sylius.

---

## D : Dependency Inversion (dépendre d'abstractions)

**Principe.** Les modules de haut niveau dépendent d'abstractions (interfaces), pas d'implémentations concrètes,
et reçoivent leurs dépendances de l'extérieur.

**Dans Sylius.** `CompositeOrderProcessor` ne connaît que `OrderProcessorInterface` ; les processeurs sont
injectés par le conteneur.

**Erreur** (dépendances construites ou récupérées en dur) :

```php
class PaymentCompletedListener
{
    public function __invoke($event): void
    {
        $logger = new Logger('app');                                   // implémentation concrète
        $logger->pushHandler(new StreamHandler('/var/log/x'));         // chemin en dur
        $em = $GLOBALS['kernel']->getContainer()->get('doctrine');     // localisateur de services
    }
}
```
Impossible à tester sans fichier ni base de données.

**Correction dans le projet.**
- `PaymentCompletedListener` reçoit un `Psr\Log\LoggerInterface` par le constructeur ; le test injecte un double :
  `tests/Formation/Module05Workflow/PaymentCompletedListenerTest.php`.
- `ListSitesCommand` reçoit `ChannelRepositoryInterface` par `#[Autowire(service: 'sylius.repository.channel')]`.
- `SetProductOriginCommand` reçoit `EntityManagerInterface` et `LoggerInterface`.

**Écart dans Sylius.** Les modèles du cœur utilisent `Doctrine\Common\Collections` (`ArrayCollection`,
`Collection`) : une dépendance à une bibliothèque de collections, pas à l'ORM.

---

## Où Sylius s'écarte de SOLID (à connaître)

| Principe | Écart constaté | Pourquoi c'est acceptable |
|---|---|---|
| I | `Core\Model\OrderInterface` : 44 méthodes | modèle central du domaine ; les rôles sont aussi exposés par de petites interfaces (`CustomerAwareInterface`, `ChannelAwareInterface`…) |
| L | `Assert::isInstanceOf` dans `OrderTaxesProcessor` | un composant générique sert plusieurs profils ; le test est explicite, pas silencieux |
| D | collections Doctrine dans les modèles | bibliothèque de collections, pas l'ORM ; le mapping reste séparé |
| Extension par héritage | surcharge d'entité en étendant la classe concrète (`Product extends BaseProduct`) | c'est le point d'extension officiel de Sylius ; à garder minimal (règle d'extension du projet) |

## Check-list de revue de code

1. **S** : cette classe a-t-elle plus d'une raison de changer ? Le listener contient-il de la logique métier ?
2. **O** : pour ajouter ce comportement, doit-on modifier une classe existante, ou peut-on brancher un événement, un tag, un décorateur ?
3. **L** : la sous-classe change-t-elle un contrat (exception, `null`, type) ?
4. **I** : l'interface est-elle utilisée en entier par chacun de ses clients ?
5. **D** : la classe fait-elle `new` d'un service, utilise-t-elle `$container->get()` ou un état global ?
