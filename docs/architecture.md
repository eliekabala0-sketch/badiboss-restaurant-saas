# Architecture Phase 1

## Objectif

Poser un socle SaaS multi-tenant unique, revendable a plusieurs restaurants, sans dupliquer la base de code.

## Principes retenus

- une seule base de code PHP 8
- une seule base MySQL avec isolation logique par `restaurant_id`
- un super administrateur transversal
- du branding et des reglages stockes en base
- aucune suppression sensible par defaut: on utilise des statuts `active`, `disabled`, `suspended`, `banned`, `archived`
- journal d'audit obligatoire pour les actions sensibles

## Arborescence

- `public/`: front controller et rewriting Apache
- `app/Core/`: noyau minimal (router, request, response, db, vue)
- `app/Services/`: authentification, audit, provisionnement tenant
- `app/Http/Controllers/`: web et API REST
- `app/Views/`: interface web simple
- `config/`: configuration applicative et base de donnees
- `routes/`: definitions des routes
- `database/`: schema initial et seed
- `docs/`: decisions d'architecture

## Strategie multi-tenant

- Les donnees transversales de plateforme restent globales: plans, permissions.
- Les donnees d'un restaurant portent `restaurant_id`.
- Le super administrateur a `restaurant_id = NULL`.
- Les roles de base sont globaux en phase 1, avec possibilite d'extensions par tenant via `roles.restaurant_id`.
- Les reglages tenant-specifiques sont stockes dans `settings`.
- Le branding tenant-specifique est stocke dans `restaurant_branding`.
- La resolution de tenant suit cet ordre simple et robuste: domaine custom ou sous-domaine, puis slug dans l'URL, puis parametre `tenant`, puis contexte de session admin.

## Authentification

- interface web: session PHP simple et compatible mutualise
- API REST: emission d'un token stocke hache dans `api_tokens`
- un utilisateur de restaurant ne peut pas se connecter si le tenant est suspendu ou banni

## Interface web minimale

- `/login`: connexion
- `/super-admin`: vue super admin
- `/owner`: vue proprietaire minimale
- `/super-admin/restaurants`: CRUD restaurants / branding / parametres
- `/super-admin/users`: CRUD utilisateurs et affectations role/restaurant
- `/super-admin/audit`: audit avec filtres
- `/super-admin/menu`: socle menu par restaurant
- `/portal/{slug}`: portail tenant brandable

## API REST minimale

- `POST /api/v1/auth/login`
- `GET /api/v1/super-admin/restaurants`

## Extension prevue phases suivantes

- middleware de resolution tenant par domaine ou sous-domaine
- CRUD admin complet pour restaurants, branding, utilisateurs, permissions et menu
- modules stock, ventes, cuisine, livraison et reclamations
- rapports parametrables et exportables
