-- Formation M9 / M15 : anonymisation d'une COPIE de base avant partage ou usage en développement.
-- Lancé par scripts/formation/db-anonymize.sh, qui définit @dev_password_hash (mot de passe de test).
-- Règle : une base issue de la production n'est jamais partagée telle quelle (RGPD, secrets des passerelles).
-- Ne supprime aucune commande ni aucun produit : seules les données personnelles et les secrets changent.

SET FOREIGN_KEY_CHECKS = 0;

-- Clients : identité, contact, date de naissance
UPDATE sylius_customer
SET email = CONCAT('client', id, '@example.test'),
    email_canonical = CONCAT('client', id, '@example.test'),
    first_name = 'Prénom',
    last_name = CONCAT('Client', id),
    phone_number = NULL,
    birthday = NULL;

-- Comptes boutique : identifiants alignés sur le client, mot de passe de test, jetons effacés
UPDATE sylius_shop_user u
JOIN sylius_customer c ON c.id = u.customer_id
SET u.username = c.email,
    u.username_canonical = c.email_canonical,
    u.email = c.email,
    u.email_canonical = c.email_canonical,
    u.password = @dev_password_hash,
    u.password_reset_token = NULL,
    u.email_verification_token = NULL,
    u.last_login = NULL;

-- Administrateurs
UPDATE sylius_admin_user
SET username = CONCAT('admin', id),
    username_canonical = CONCAT('admin', id),
    email = CONCAT('admin', id, '@example.test'),
    email_canonical = CONCAT('admin', id, '@example.test'),
    first_name = 'Admin',
    last_name = CONCAT('Test', id),
    password = @dev_password_hash,
    password_reset_token = NULL,
    email_verification_token = NULL,
    last_login = NULL;

-- Adresses (le pays est conservé : il pilote la TVA et la livraison)
UPDATE sylius_address
SET first_name = 'Prénom',
    last_name = CONCAT('Nom', id),
    phone_number = NULL,
    company = NULL,
    street = '1 rue de Test',
    city = 'Ville',
    postcode = '00000',
    province_name = NULL;

-- Commandes : notes libres et adresse IP (abandoned_email est un indicateur 0/1, pas une adresse)
UPDATE sylius_order
SET notes = NULL,
    customer_ip = NULL;

-- Paiements : détails retournés par les passerelles ; jetons et requêtes de paiement supprimés
UPDATE sylius_payment SET details = '[]';
DELETE FROM sylius_payment_security_token;
DELETE FROM sylius_payment_request;

-- Secrets des passerelles de paiement (clés API chiffrées avec SYLIUS_PAYMENT_ENCRYPTION_KEY_PATH)
UPDATE sylius_gateway_config SET config = '{}';
-- Jetons des plugins installés (connexions sociales, PayPal) : tables présentes dans ce projet
DELETE FROM sylius_user_oauth;
DELETE FROM sylius_paypal_plugin_pay_pal_credentials;

-- Avis : texte libre
UPDATE sylius_product_review SET comment = 'Avis de test', title = 'Avis';

-- Files de messages : peuvent contenir des données de commandes ou de clients
DELETE FROM messenger_messages;

SET FOREIGN_KEY_CHECKS = 1;
