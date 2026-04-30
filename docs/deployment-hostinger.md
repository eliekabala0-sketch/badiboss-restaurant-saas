# Procedure de Deploiement Hostinger

## Cible

Hebergement Apache + PHP 8 + MySQL, avec document root pointant vers `public/`.

## Etapes

1. Creer la base MySQL sur Hostinger.
2. Importer `database/schema.sql`.
3. Importer `database/seed.sql`.
4. Copier les fichiers du projet sur l'hebergement.
5. Pointer le domaine ou sous-domaine principal vers `public/`.
6. Creer le fichier `.env` a partir de `.env.example`.
7. Renseigner les identifiants MySQL de production.
8. Verifier que `mod_rewrite` est actif.
9. Verifier que PHP PDO MySQL est disponible.

## Recommandations

- stocker logos et assets brandes dans un dossier `storage/` ou sur un stockage objet/CDN
- activer HTTPS pour tous les domaines de restaurants
- configurer les sous-domaines wildcard si l'offre Hostinger le permet
- si les domaines custom sont proposes, ajouter une procedure de verification DNS cote super admin

## Limites realistes sur mutualise

- generation d'applications mobiles natives par restaurant non recommandee sur Hostinger
- pour une vraie app mobile white-label par client, prevoir un pipeline externe de build
- la PWA brandee reste la solution immediate la plus fiable
