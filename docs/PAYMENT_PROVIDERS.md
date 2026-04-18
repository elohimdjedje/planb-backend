# Documentation - Providers de Paiement

## Vue d'ensemble

Plan B utilise deux agrégateurs de paiement :

| Provider | Méthodes supportées | Statut |
|----------|---------------------|--------|
| **PayTech** | Wave, Orange Money, Free Money, Carte bancaire | ✅ Activé |
| **KKiaPay** | Wave, Orange Money, MTN, Moov | ⏳ Désactivé |

---

## Comment activer/désactiver un provider

### Fichier de configuration

Modifiez le fichier `config/payment_providers.yaml` :

```yaml
parameters:
    # PayTech - Agrégateur principal
    payment.paytech.enabled: true    # true = activé, false = désactivé
    
    # KKiaPay - Agrégateur secondaire
    payment.kkiapay.enabled: false   # true = activé, false = désactivé
```

### Exemple : Activer KKiaPay

1. Ouvrez `config/payment_providers.yaml`
2. Changez `payment.kkiapay.enabled: false` en `payment.kkiapay.enabled: true`
3. Videz le cache Symfony :
   ```bash
   php bin/console cache:clear
   ```

### Exemple : Désactiver PayTech

1. Ouvrez `config/payment_providers.yaml`
2. Changez `payment.paytech.enabled: true` en `payment.paytech.enabled: false`
3. Videz le cache Symfony :
   ```bash
   php bin/console cache:clear
   ```

---

## Configuration des clés API

### PayTech

Dans le fichier `.env` :

```env
# PayTech - https://paytech.sn
PAYTECH_API_KEY=votre_api_key
PAYTECH_SECRET_KEY=votre_secret_key
PAYTECH_ENV=prod
PAYTECH_IPN_URL=https://votre-domaine.com/api/webhook/paytech
PAYTECH_SUCCESS_URL=https://votre-domaine.com/payment/success
PAYTECH_CANCEL_URL=https://votre-domaine.com/payment/cancel
```

### KKiaPay

Dans le fichier `.env` :

```env
# KKiaPay - https://kkiapay.me
KKIAPAY_PUBLIC_KEY=votre_public_key
KKIAPAY_PRIVATE_KEY=votre_private_key
KKIAPAY_SECRET=votre_secret
KKIAPAY_SANDBOX=false
KKIAPAY_CALLBACK_URL=https://votre-domaine.com/api/webhook/kkiapay
KKIAPAY_WEBHOOK_SECRET=votre_webhook_secret
```

---

## Endpoints API

### PayTech (quand activé)

| Endpoint | Méthode | Description |
|----------|---------|-------------|
| `/api/paytech/create-payment` | POST | Créer un paiement |
| `/api/paytech/subscription` | POST | Abonnement PRO |
| `/api/paytech/booking-payment` | POST | Paiement réservation |
| `/api/webhook/paytech` | POST | Webhook IPN |

### KKiaPay (quand activé)

| Endpoint | Méthode | Description |
|----------|---------|-------------|
| `/api/kkiapay/config` | GET | Config pour frontend |
| `/api/kkiapay/verify` | POST | Vérifier transaction |
| `/api/kkiapay/history` | GET | Historique paiements |
| `/api/webhook/kkiapay` | POST | Webhook |

---

## Vérifier si un provider est activé (dans le code)

```php
// Dans un controller ou service
public function __construct(
    private PayTechService $payTechService,
    private KKiaPayService $kkiaPayService
) {}

public function someMethod(): void
{
    if ($this->payTechService->isEnabled()) {
        // PayTech est activé, utiliser ce provider
    }
    
    if ($this->kkiaPayService->isEnabled()) {
        // KKiaPay est activé, utiliser ce provider
    }
}
```

---

## Checklist pour activer KKiaPay

Quand vous aurez les documents requis :

1. [ ] Activer le compte sur https://app.kkiapay.me/dashboard/activation
2. [ ] Soumettre les documents d'identité
3. [ ] Attendre la validation de KKiaPay
4. [ ] Configurer le webhook :
   - URL : `https://votre-domaine.com/api/webhook/kkiapay`
   - Événements : Transactions succès + Transactions échecs
   - Secret hash : copier et ajouter dans `.env`
5. [ ] Modifier `config/payment_providers.yaml` :
   ```yaml
   payment.kkiapay.enabled: true
   ```
6. [ ] Vider le cache : `php bin/console cache:clear`
7. [ ] Tester un paiement

---

## Support

- **PayTech** : https://paytech.sn/documentation
- **KKiaPay** : https://docs.kkiapay.me
