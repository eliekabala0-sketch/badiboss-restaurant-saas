# Déploiement Railway

Ce document prépare le déploiement Railway sans modifier les flux métier validés.

## 1. Pré-requis

- Projet local : `C:\Users\user\Documents\Badiboss Restaurant SaaS`
- Point d'entrée web : `public/`
- Commande locale actuelle : `php -S 127.0.0.1:8000 -t public`
- Commande Railway : `php -S 0.0.0.0:$PORT -t public`

## 2. Variables Railway à configurer

Configurer ces variables dans Railway :

```env
APP_NAME=Badiboss Restaurant SaaS
APP_ENV=production
APP_DEBUG=false
APP_URL=https://votre-service.up.railway.app
APP_TIMEZONE=Africa/Kinshasa
SESSION_NAME=badiboss_session
API_TOKEN_TTL_HOURS=24

DB_HOST=
DB_PORT=
DB_DATABASE=
DB_USERNAME=
DB_PASSWORD=
DB_CHARSET=utf8mb4
```

Notes :

- L'application accepte aussi les anciens noms locaux `DB_NAME`, `DB_USER`, `DB_PASS`.
- Pour Railway, privilégier `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`.

## 3. Base de données Railway

L'application utilise MySQL via `config/database.php`.

Deux approches possibles :

### Option A — schéma seul

Importer uniquement le schéma :

```bash
mysql -h <DB_HOST> -P <DB_PORT> -u <DB_USERNAME> -p <DB_DATABASE> < database/schema.sql
```

Cette option est la plus sûre si vous voulez initialiser une base propre sans données de démonstration.

### Option B — schéma + seed

Attention : `database/seed.sql` commence par des `TRUNCATE TABLE`.
Il doit être utilisé uniquement sur une base vide ou de démonstration.

```bash
mysql -h <DB_HOST> -P <DB_PORT> -u <DB_USERNAME> -p <DB_DATABASE> < database/seed.sql
```

## 4. Démarrage Railway

Railway peut utiliser soit :

- `Procfile`
- `railway.json`

La commande configurée est :

```bash
php -S 0.0.0.0:$PORT -t public
```

Le healthcheck cible :

```text
/health
```

## 5. Uploads

Les uploads d'images restaurant sont actuellement stockés localement sous :

```text
public/uploads/restaurants/
```

Limite importante Railway :

- le stockage disque local du conteneur est éphémère ;
- les fichiers téléversés peuvent disparaître lors d'un redéploiement ou d'un redémarrage.

Conclusion :

- le fonctionnement local actuel reste intact ;
- pour la production Railway, prévoir à terme un stockage externe type S3, Cloudinary ou équivalent.

## 6. Commandes Git

Initialiser Git si nécessaire :

```bash
git init
```

Créer une branche de travail :

```bash
git checkout -b chore/railway-prepare
```

Ajouter les fichiers :

```bash
git add .
```

Créer le commit :

```bash
git commit -m "Prepare Railway deployment"
```

## 7. Push vers GitHub

Si le dépôt distant n'existe pas encore :

```bash
git remote add origin <URL_DU_DEPOT_GITHUB>
git push -u origin chore/railway-prepare
```

Si l'authentification GitHub est demandée, elle doit être réalisée manuellement depuis votre poste.

## 8. Déploiement Railway

Après push sur GitHub :

1. Créer un nouveau projet Railway.
2. Connecter le dépôt GitHub.
3. Ajouter un service MySQL Railway.
4. Reporter les variables DB du service MySQL dans les variables de l'application.
5. Définir `APP_URL` avec l'URL publique Railway.
6. Lancer le déploiement.
7. Importer la base selon l'option choisie.

## 9. Vérifications après déploiement

Tester au minimum :

- `/health`
- `/login`
- `/super-admin`
- `/owner`
- `/stock`
- `/cuisine`
- `/ventes`
- `/rapport`

## 10. Risques connus

- `seed.sql` est destructif sur une base non vide.
- Les uploads locaux ne sont pas persistants sur Railway.
- Les sessions sont stockées localement dans `storage/sessions`, donc elles ne sont pas partagées entre plusieurs instances.
