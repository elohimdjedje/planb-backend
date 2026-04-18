# 🚀 Plan B - Backend API Symfony

> Backend API pour la plateforme de petites annonces en Afrique de l'Ouest

## 📖 À propos

Plan B est une plateforme de petites annonces conçue pour les pays d'Afrique de l'Ouest (Côte d'Ivoire, Bénin, Sénégal, Mali). Cette API backend est construite avec **Symfony 7** et fournit toutes les fonctionnalités nécessaires pour gérer utilisateurs, annonces, paiements et abonnements PRO.

## ✨ Fonctionnalités

- ✅ **Authentification JWT** - Inscription, connexion sécurisée
- ✅ **Gestion des annonces** - CRUD complet avec pagination
- ✅ **Comptes FREE & PRO** - Limites différenciées
- ✅ **Upload d'images** - Support Cloudinary/AWS S3
- ✅ **Paiements Mobile Money** - Intégration Fedapay
- ✅ **Multi-pays** - CI, BJ, SN, ML
- ✅ **Recherche avancée** - Filtres par catégorie, localisation, prix
- ✅ **Validation complète** - Sécurité des données
- ✅ **API REST documentée** - Format JSON

## 🎯 Stack technique

- **Framework** : Symfony 7.0
- **Base de données** : PostgreSQL 15+ (ou MySQL)
- **Authentification** : JWT (LexikJWTAuthenticationBundle)
- **ORM** : Doctrine
- **Validation** : Symfony Validator
- **API** : RESTful

## 🚀 Installation rapide

### Prérequis
- PHP 8.2+
- Composer
- PostgreSQL 15+ ou MySQL 8+

### Installation

```bash
# 1. Installer les dépendances
composer install

# 2. Configurer l'environnement
cp .env.example .env
# Éditer .env avec vos paramètres

# 3. Générer les clés JWT
php bin/console lexik:jwt:generate-keypair

# 4. Créer la base de données
php bin/console doctrine:database:create
php bin/console doctrine:migrations:migrate

# 5. Lancer le serveur
php -S localhost:8000 -t public
```

📖 **Guide complet pour Windows/XAMPP** : voir [INSTALLATION_WINDOWS.md](INSTALLATION_WINDOWS.md)

## 📱 Endpoints principaux

### Authentification
- `POST /api/v1/auth/register` - Inscription
- `POST /api/v1/auth/login` - Connexion
- `GET /api/v1/users/me` - Profil utilisateur

### Annonces
- `GET /api/v1/listings` - Liste des annonces
- `GET /api/v1/listings/{id}` - Détails
- `POST /api/v1/listings` - Créer (authentifié)
- `PUT /api/v1/listings/{id}` - Modifier (authentifié)
- `DELETE /api/v1/listings/{id}` - Supprimer (authentifié)

## 🧪 Tests

### Exemple d'inscription
```bash
curl -X POST http://localhost:8000/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "email": "user@example.com",
    "password": "Password123!",
    "phone": "+22507123456",
    "firstName": "John",
    "lastName": "Doe",
    "country": "CI",
    "city": "Abidjan"
  }'
```

### Exemple de connexion
```bash
curl -X POST http://localhost:8000/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{
    "username": "user@example.com",
    "password": "Password123!"
  }'
```

## 📊 Structure de la base de données

### Tables principales
- `users` - Utilisateurs (FREE/PRO)
- `listings` - Annonces
- `images` - Images des annonces
- `payments` - Paiements Mobile Money
- `subscriptions` - Abonnements PRO

## 🔒 Sécurité

- ✅ Mots de passe hashés (bcrypt)
- ✅ Tokens JWT avec expiration
- ✅ Validation des entrées
- ✅ Protection CSRF
- ✅ Rate limiting (à implémenter)
- ✅ CORS configuré

## 📈 Limites FREE vs PRO

| Fonctionnalité | FREE | PRO |
|---------------|------|-----|
| Annonces actives | 5 | 50 |
| Images par annonce | 3 | 10 |
| Durée de publication | 30 jours | 90 jours |
| Mise en avant | ❌ | ✅ |

## 🌍 Pays supportés

- 🇨🇮 Côte d'Ivoire (CI)
- 🇧🇯 Bénin (BJ)
- 🇸🇳 Sénégal (SN)
- 🇲🇱 Mali (ML)

## 📝 Commandes utiles

```bash
# Voir toutes les routes
php bin/console debug:router

# Créer une migration
php bin/console make:migration

# Appliquer les migrations
php bin/console doctrine:migrations:migrate

# Créer une entité
php bin/console make:entity

# Vider le cache
php bin/console cache:clear
```

## 🚀 Déploiement

### Recommandations pour production

**Hébergement gratuit pour débuter :**
- Render.com (recommandé)
- Railway.app
- Heroku

**Base de données :**
- Render PostgreSQL (gratuit 0.5 GB)
- Supabase (gratuit 500 MB)

**Stockage images :**
- Cloudinary (gratuit 25 GB)

Voir documentation complète dans `BACKEND_README.md`

## 📚 Documentation

- [Installation Windows/XAMPP](INSTALLATION_WINDOWS.md)
- [Documentation API complète](BACKEND_README.md)
- [Spécifications OpenAPI](backend_symfony_specs.json)

## 👨‍💻 Auteur

**Mickael Elohim DJEDJE**  
Bachelor 3 Concepteur d'Application  
2024/2025

## 📄 Licence

Projet éducatif - Tous droits réservés

---

**🎓 Projet de fin d'études - ESATIC/MyDigitalSchool**
