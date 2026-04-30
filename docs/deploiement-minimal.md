# Deploiement minimal

## Objectif

Ce document resume le minimum a verifier avant une demonstration client ou un deploiement controle.

## Configuration

- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL` doit pointer vers l'URL publique reelle
- `APP_TIMEZONE` doit correspondre au fuseau metier de reference
- `DB_CHARSET=utf8mb4`

## Serveur web

- Document root: `public/`
- PHP 8.1+
- PDO MySQL active
- Reecriture d'URL activee si un serveur web frontal est utilise

## Permissions

Les repertoires suivants doivent etre inscriptibles:

- `storage/logs`
- `storage/sessions`
- `public/uploads/restaurants`

## Base de donnees

- Sauvegarde recommandee avant toute migration
- Migrations non destructives uniquement
- Ne jamais purger l'historique metier d'un restaurant sans validation explicite

## Controle avant mise en ligne

1. Verifier l'acces a `/`, `/login`, `/owner`, `/stock`, `/cuisine`, `/ventes`, `/rapport`, `/super-admin`.
2. Verifier les comptes de demonstration et les redirections.
3. Verifier qu'un utilisateur restaurant ne peut pas forcer un autre `restaurant_id`.
4. Verifier l'affichage du fuseau, du rapport du jour et du statut d'abonnement.
5. Verifier les dossiers d'upload et la conservation des logos/photos.

## Donnees de demonstration

- Restaurant principal: `Badi Saveurs Gombe`
- Cas abonnement expire: `Mboka Grill Lubumbashi`
- Cas onboarding / branding local: `Atelier Demo Badiboss`

Les historiques ont ete conserves. Les libelles de test visibles ont ete neutralises sans suppression destructive.
