# KKiaPay Integration Guide

## Vue d'ensemble

KKiaPay est un agrégateur de paiement mobile money pour l'Afrique de l'Ouest.

**Méthodes supportées** : Wave, Orange Money, MTN Money, Moov Money

**Documentation officielle** : https://docs.kkiapay.me

---

## Configuration

### Variables d'environnement (.env)

```env
# KKiaPay - https://kkiapay.me
KKIAPAY_PUBLIC_KEY=votre_public_key
KKIAPAY_PRIVATE_KEY=votre_private_key
KKIAPAY_SECRET=votre_secret
KKIAPAY_SANDBOX=true             # false en production
KKIAPAY_CALLBACK_URL=https://votre-domaine.com/api/v1/webhooks/kkiapay
KKIAPAY_WEBHOOK_SECRET=votre_webhook_secret
```

### Activation dans le code

Fichier: `config/payment_providers.yaml`

```yaml
parameters:
    payment.kkiapay.enabled: true
```

Puis vider le cache:
```bash
php bin/console cache:clear
```

---

## Endpoints API

### 1️⃣ Obtenir la configuration KKiaPay

**GET** `/api/kkiapay/config`

Retourne la clé publique et les paramètres pour le frontend (React Native).

**Réponse (200)**:
```json
{
  "enabled": true,
  "publicKey": "127ea3a40f8a7a2d07f7f2fca1baddc43baf7e99",
  "sandbox": true
}
```

**Erreur (503)**:
```json
{
  "enabled": false,
  "message": "KKiaPay est désactivé. Utilisez PayTech.",
  "alternative": "/api/paytech/create-payment"
}
```

---

### 2️⃣ Vérifier une transaction KKiaPay

**POST** `/api/kkiapay/verify`

Vérifier le statut d'une transaction après paiement.

**Body**:
```json
{
  "transactionId": "KKIA-TXN-123456789",
  "months": 1,
  "type": "subscription"
}
```

**Réponse (200)**:
```json
{
  "success": true,
  "message": "Transaction valide",
  "payment": {
    "id": 42,
    "user_id": 1,
    "amount": 5000,
    "currency": "XOF",
    "status": "completed",
    "transaction_id": "KKIA-TXN-123456789",
    "payment_method": "kkiapay",
    "created_at": "2024-01-15T10:30:00Z"
  },
  "subscription": {
    "expires_at": "2024-02-15T10:30:00Z",
    "account_type": "PRO",
    "status": "active"
  }
}
```

**Erreur (400)**:
```json
{
  "error": "Transaction non valide",
  "transaction_id": "KKIA-TXN-123456789"
}
```

---

### 3️⃣ Historique des paiements KKiaPay

**GET** `/api/kkiapay/history`

Récupérer l'historique des paiements KKiaPay de l'utilisateur connecté.

**Query Parameters**:
- `limit` (default: 50)
- `offset` (default: 0)
- `status` (completed, failed, pending)

**Réponse (200)**:
```json
{
  "payments": [
    {
      "id": 42,
      "amount": 5000,
      "currency": "XOF",
      "status": "completed",
      "transaction_id": "KKIA-TXN-123456789",
      "payment_method": "kkiapay",
      "description": "Abonnement PRO 1 mois",
      "created_at": "2024-01-15T10:30:00Z"
    }
  ],
  "total": 1
}
```

---

## Webhook KKiaPay

### Endpoint

**POST** `/api/v1/webhooks/kkiapay`

Reçoit les notifications de KKiaPay quand un paiement est effectué.

**Configuration dans KKiaPay Dashboard**:
1. Aller à Settings → Webhooks
2. URL: `https://votre-domaine.com/api/v1/webhooks/kkiapay`
3. Événements: "Transaction success" + "Transaction failed"
4. Signature: Générer dans le dashboard et ajouter dans `.env` (KKIAPAY_WEBHOOK_SECRET)

### Payload reçu

```json
{
  "transaction_id": "KKIA-TXN-123456789",
  "status": "SUCCESS",
  "reference": "PLANB-BK-42-1705337400",
  "amount": 5000,
  "phone": "+221764123456",
  "payment_method": "wave",
  "timestamp": "2024-01-15T10:30:00Z"
}
```

### Statuts possibles

- `SUCCESS` → Paiement validé ✅
- `FAILED` → Échec du paiement ❌
- `CANCELLED` → Paiement annulé par l'utilisateur ❌
- `PENDING` → En cours de traitement ⏳

### Actions effectuées

Lors d'un webhook `SUCCESS` :
1. Paiement marqué comme `completed`
2. Métadonnées mises à jour (phone, etc.)
3. **Si type = `subscription`** : Abonnement PRO activé

---

## Frontend - React Native Integration

### Installation du SDK

```bash
npm install @kkiapay-org/react-native-sdk
```

### Composant de paiement

```javascript
import React, { useEffect } from 'react';
import { View, Button, Alert } from 'react-native';
import { useKkiapay } from '@kkiapay-org/react-native-sdk';
import api from '../services/api';

const KKiaPaymentModal = ({ amount, orderId, onSuccess, onError }) => {
  const { openKkiapayWidget, addSuccessListener, addFailedListener } = useKkiapay();
  const [config, setConfig] = React.useState(null);

  // Récupérer la config KKiaPay
  React.useEffect(() => {
    const fetchConfig = async () => {
      try {
        const response = await api.get('/kkiapay/config');
        setConfig(response.data);
      } catch (err) {
        console.error('Erreur config KKiaPay:', err);
        onError('KKiaPay non disponible');
      }
    };
    fetchConfig();
  }, []);

  // Écouteur paiement réussi
  useEffect(() => {
    const removeSuccess = addSuccessListener((response) => {
      console.log('✅ Paiement KKiaPay réussi:', response);
      
      // Vérifier la transaction côté backend
      verifyTransaction(response.transactionId);
    });

    return () => removeSuccess();
  }, [addSuccessListener]);

  // Écouteur paiement échoué
  useEffect(() => {
    const removeFailed = addFailedListener((response) => {
      console.error('❌ Paiement KKiaPay échoué:', response);
      Alert.alert('Paiement échoué', 'Veuillez réessayer.');
      onError('Paiement échoué');
    });

    return () => removeFailed();
  }, [addFailedListener]);

  // Vérifier la transaction
  const verifyTransaction = async (transactionId) => {
    try {
      const response = await api.post('/kkiapay/verify', {
        transactionId,
        months: 1,
        type: 'subscription'
      });

      if (response.data.success) {
        Alert.alert('Succès', 'Abonnement activé!');
        onSuccess(response.data.payment);
      }
    } catch (err) {
      console.error('Erreur vérification:', err);
      onError('Erreur de vérification');
    }
  };

  // Ouvrir le widget KKiaPay
  const handlePayment = () => {
    if (!config) {
      Alert.alert('Erreur', 'Configuration non chargée');
      return;
    }

    openKkiapayWidget({
      amount: amount,
      api_key: config.publicKey,
      sandbox: config.sandbox,
      reason: `Abonnement Plan B #${orderId}`,
      data: orderId,
      theme: '#222F5A',
      paymentMethods: ['momo', 'card'],
      countries: ['BJ', 'CI', 'SN', 'TG']
    });
  };

  return (
    <View style={{ padding: 20 }}>
      <Button
        title={`Payer ${amount.toLocaleString()} FCFA avec KKiaPay`}
        onPress={handlePayment}
        color="#222F5A"
      />
    </View>
  );
};

export default KKiaPaymentModal;
```

---

## Cas d'usage

### 1️⃣ Abonnement PRO via KKiaPay

```bash
POST /api/v1/payments/create-subscription
```

**Body**:
```json
{
  "months": 1,
  "paymentMethod": "mtn_money",
  "phoneNumber": "+221764123456"
}
```

**Réponse** (retourne config pour widget):
```json
{
  "success": true,
  "payment": {
    "id": 42,
    "amount": 5000,
    "currency": "XOF",
    "status": "pending",
    "paymentMethod": "mtn_money"
  },
  "kkiapay_config": {
    "publicKey": "...",
    "sandbox": true,
    "amount": 5000,
    "name": "John Doe",
    "phone": "+221764123456",
    "email": "john@example.com"
  },
  "payment_id": 42
}
```

### 2️⃣ Boost d'annonce via KKiaPay

```bash
POST /api/v1/payments/boost-listing
```

**Body**:
```json
{
  "listing_id": 123,
  "paymentMethod": "moov_money"
}
```

---

## Tests

### Tester le webhook (dev seulement)

```bash
POST /api/v1/webhooks/test
```

**Body**:
```json
{
  "provider": "kkiapay",
  "transaction_id": "KKIA-TXN-123456789",
  "status": "SUCCESS",
  "amount": 5000,
  "reference": "PLANB-BK-42-1705337400"
}
```

### Tester une transaction KKiaPay

```bash
curl -X POST http://localhost:8000/api/kkiapay/verify \
  -H "Content-Type: application/json" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -d '{
    "transactionId": "KKIA-TXN-123456789",
    "months": 1,
    "type": "subscription"
  }'
```

---

## Troubleshooting

### ❌ "KKiaPay est désactivé"

**Solution** : Activer dans `config/payment_providers.yaml` et `php bin/console cache:clear`

### ❌ Webhook non reçu

**Vérifications** :
1. URL webhook correcte dans KKiaPay Dashboard
2. Signature webhook validée
3. Domaine en HTTPS (requis par KKiaPay)
4. Logs: `php bin/console tail`

### ❌ Transaction non trouvée

**Solution** : Le webhook stocke les métadonnées avec la clé `kkiapay_ref` - vérifier que la référence correspond

---

## Flux complet d'un paiement

```
┌─────────────────────────────────────────────────────────────┐
│ 1. Frontend appelle POST /api/v1/payments/create-subscription
└──────────────┬──────────────────────────────────────────────┘
               │
┌──────────────▼──────────────────────────────────────────────┐
│ 2. Backend crée Payment entity (status: pending)
│    Retourne kkiapay_config + payment_id
└──────────────┬──────────────────────────────────────────────┘
               │
┌──────────────▼──────────────────────────────────────────────┐
│ 3. Frontend ouvre widget KKiaPay avec la config
└──────────────┬──────────────────────────────────────────────┘
               │
┌──────────────▼──────────────────────────────────────────────┐
│ 4. Utilisateur effectue le paiement dans le widget
└──────────────┬──────────────────────────────────────────────┘
               │
        ┌──────┴──────┐
        │             │
    ✅ SUCCESS    ❌ FAILED
        │             │
        │   ┌─────────┴─────────────┐
        │   │ Widget AppList événement│
        │   │ (onSuccess/onFailed)   │
        │   └─────────┬─────────────┘
        │             │
        │   ┌─────────▼──────────────┐
        │   │ Frontend appelle       │
        │   │ /api/kkiapay/verify    │
        │   └─────────┬──────────────┘
        │             │
        │   ┌─────────▼──────────────────────────┐
        │   │ Backend enregistre la transaction  │
        │   │ et active l'abonnement             │
        │   └─────────┬──────────────────────────┘
        │             │
        │   ┌─────────▼──────────────────────────┐
        │   │ Webhook KKiaPay confirme paiement  │
        │   │ POST /api/v1/webhooks/kkiapay      │
        │   └─────────┬──────────────────────────┘
        │             │
        └─────────────┴──────────────────────────────────────┘
                      │
              ✅ Abonnement ACTIVÉ
```

---

## Support

- **Documentation KKiaPay** : https://docs.kkiapay.me
- **Dashboard KKiaPay** : https://app.kkiapay.me
- **Support** : https://support.kkiapay.me
