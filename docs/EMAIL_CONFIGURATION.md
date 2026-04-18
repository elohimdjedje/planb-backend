# Configuration de l'envoi d'emails - Plan B

Ce document explique comment configurer l'envoi d'emails pour Plan B.

## Variables d'environnement requises

Ajoutez ces variables dans votre fichier `.env` :

```env
# Configuration Mailer (Symfony Mailer)
# Format: MAILER_DSN=transport://user:password@smtp.example.com:port
# Exemples ci-dessous

# === OPTION 1: Gmail (pour développement/test) ===
MAILER_DSN=gmail://USERNAME:PASSWORD@default
# Remplacez USERNAME et PASSWORD par vos identifiants Gmail
# Note: Vous devrez créer un "Mot de passe d'application" dans votre compte Google

# === OPTION 2: SMTP générique ===
MAILER_DSN=smtp://username:password@smtp.example.com:587
# Exemple avec Mailtrap (pour tests):
# MAILER_DSN=smtp://username:password@smtp.mailtrap.io:2525

# === OPTION 3: SendGrid ===
MAILER_DSN=sendgrid://KEY@default
# Remplacez KEY par votre clé API SendGrid

# === OPTION 4: Mailgun ===
MAILER_DSN=mailgun://KEY:DOMAIN@default
# Remplacez KEY et DOMAIN par vos identifiants Mailgun

# === OPTION 5: Amazon SES ===
MAILER_DSN=ses://ACCESS_KEY:SECRET_KEY@default?region=eu-west-1

# === OPTION 6: Pour développement local (sans envoi réel) ===
MAILER_DSN=null://null

# Configuration de l'expéditeur
MAILER_FROM_EMAIL=noreply@planb.ci
MAILER_FROM_NAME=Plan B

# URL de l'application (pour les liens dans les emails)
APP_URL=http://localhost:5173
# En production: APP_URL=https://app.planb.ci
```

## Services d'email recommandés

### Pour le développement
- **Mailtrap** (gratuit) : https://mailtrap.io
  - Parfait pour tester les emails sans encombrer votre boîte
  - Configuration simple

### Pour la production
- **SendGrid** (gratuit jusqu'à 100 emails/jour) : https://sendgrid.com
- **Mailgun** (gratuit jusqu'à 5000 emails/mois) : https://www.mailgun.com
- **Amazon SES** (très économique) : https://aws.amazon.com/ses/
- **Postmark** (payant mais excellent) : https://postmarkapp.com

## Configuration Gmail (pour développement)

1. Activez la validation en deux étapes sur votre compte Google
2. Allez dans "Sécurité" > "Mots de passe des applications"
3. Créez un nouveau mot de passe d'application
4. Utilisez ce mot de passe dans `MAILER_DSN` :
   ```env
   MAILER_DSN=gmail://votre-email@gmail.com:mot-de-passe-application@default
   ```

## Configuration SendGrid (recommandé pour production)

1. Créez un compte sur https://sendgrid.com
2. Générez une clé API dans Settings > API Keys
3. Configurez dans `.env` :
   ```env
   MAILER_DSN=sendgrid://KEY@default
   MAILER_FROM_EMAIL=noreply@planb.ci
   MAILER_FROM_NAME=Plan B
   ```
4. Vérifiez votre domaine d'envoi dans SendGrid

## Emails envoyés par Plan B

1. **Email de bienvenue** : Envoyé après l'inscription avec lien de vérification
2. **Email de vérification** : Pour vérifier l'adresse email
3. **Email de réinitialisation de mot de passe** : Avec code de réinitialisation
4. **Email de confirmation de changement de mot de passe**
5. **Email de confirmation de vérification d'email**

## Test de l'envoi d'emails

### En mode développement

Les emails sont envoyés normalement. Vous pouvez aussi utiliser Mailtrap pour capturer tous les emails.

### Développement local SANS Docker (recommandé) : Mailpit

Si vous ne voulez pas utiliser Docker, le plus simple est d'utiliser **Mailpit** en local.

1. Installer Mailpit sur Windows (au choix) :
   - Avec Chocolatey : `choco install mailpit`
   - Ou télécharger l'exécutable depuis la page Releases de Mailpit, puis ajouter le dossier au `PATH`.

2. Démarrer Mailpit :
   - Dans un terminal : `mailpit`

3. Configurer Symfony Mailer dans `planb-backend/.env.local` :

```env
MAILER_DSN=smtp://127.0.0.1:1025
MAILER_FROM_EMAIL=noreply@planb.local
MAILER_FROM_NAME="Plan B (Local)"
FRONTEND_URL=http://localhost:5173
```

4. Ouvrir l'interface Mailpit :
   - `http://localhost:8025`

Vous verrez l'email de vérification + l'email OTP (2FA) apparaître ici.

### Vérifier la configuration

```bash
# Vérifier que Symfony Mailer est bien configuré
php bin/console debug:container mailer

# Tester l'envoi d'un email (si vous avez créé une commande de test)
php bin/console app:test-email test@example.com
```

## Dépannage

### Les emails ne sont pas envoyés

1. Vérifiez que `MAILER_DSN` est bien défini dans `.env`
2. Vérifiez les logs : `var/log/dev.log` ou `var/log/prod.log`
3. En mode dev, les erreurs sont affichées dans la console

### Erreur "Connection refused"

- Vérifiez que le serveur SMTP est accessible
- Vérifiez le port (587 pour TLS, 465 pour SSL, 25 pour non sécurisé)
- Vérifiez vos identifiants

### Erreur "Authentication failed"

- Vérifiez vos identifiants dans `MAILER_DSN`
- Pour Gmail, utilisez un "Mot de passe d'application"
- Pour SendGrid, vérifiez votre clé API

## Sécurité

⚠️ **Important** : Ne commitez jamais votre fichier `.env` avec vos vraies clés API !

- Utilisez `.env.local` pour vos configurations locales
- Utilisez les variables d'environnement sur votre serveur de production
- Utilisez des secrets managés pour les clés API en production

## Support

Pour toute question sur la configuration email, contactez l'équipe de développement.
