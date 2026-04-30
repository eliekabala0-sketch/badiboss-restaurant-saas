# Badiboss Restaurant SaaS

Application SaaS multi-restaurant pour la gestion d'un restaurant: stock, cuisine, ventes, incidents, rapports, abonnements et administration plateforme.

## Etat du projet

- Interface principale en francais.
- Isolation multi-restaurant durcie cote backend.
- Rapports journaliers, hebdomadaires et mensuels bases sur de vraies bornes calendaires.
- Abonnements et periode de grace calcules sur des dates reelles.
- Jeu de demonstration principal disponible sur `Badi Saveurs Gombe`.

## Prerequis

- PHP 8.1 ou plus
- MySQL 8 ou equivalent compatible UTF-8
- Extension PDO MySQL activee
- Serveur web pointant vers `public/`

## Configuration minimale

Copier ou adapter les variables suivantes dans `.env` :

```ini
APP_NAME="Badiboss Restaurant SaaS"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://votre-domaine
APP_TIMEZONE=Africa/Kinshasa

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME=badiboss_restaurant_saas
DB_USER=badiboss_user
DB_PASS=mot_de_passe_solide
DB_CHARSET=utf8mb4

SESSION_NAME=badiboss_session
API_TOKEN_TTL_HOURS=24
```

## Demarrage local

1. Creer une base MySQL vide.
2. Importer `database/schema.sql`.
3. Importer `database/seed.sql` si vous voulez les donnees d'initialisation.
4. Adapter `.env`.
5. Lancer le serveur local:

```powershell
php -S 127.0.0.1:8000 -t public
```

6. Ouvrir [http://127.0.0.1:8000](http://127.0.0.1:8000).

## Repertoires a rendre inscriptibles

- `storage/logs`
- `storage/sessions`
- `public/uploads/restaurants`

## Comptes de demonstration

- `superadmin@badiboss.test`
- `owner-gombe@badiboss.test`
- `manager-gombe@badiboss.test`
- `stock-gombe@badiboss.test`
- `kitchen-gombe@badiboss.test`
- `server-gombe@badiboss.test`

Mot de passe de demonstration: `password`

## Perimetre de demonstration recommande

- Restaurant principal: `Badi Saveurs Gombe`
- Cas abonnement expire: `Mboka Grill Lubumbashi`
- Cas onboarding / branding local: `Atelier Demo Badiboss`

## Verification rapide

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\role-http-check.ps1
```

## Notes de deploiement

- Desactiver le mode debug en production.
- Pointer le document root vers `public/`.
- Verifier la timezone du serveur et celle des restaurants.
- Conserver les dossiers `storage/` et `public/uploads/restaurants/` en dehors de toute purge automatique.
- Document de synthese: `docs/deploiement-minimal.md`
