# Strategie White-Label Realiste

## 1. Faisable immediatement dans une seule base de code

- branding par restaurant via `restaurant_branding`
- lien web dedie via `restaurants.access_url`
- sous-domaine par restaurant via `restaurant_branding.web_subdomain`
- domaine personnalise via `restaurant_branding.custom_domain`
- mini portail client web/PWA au nom du restaurant
- menu, categories, prix et reglages propres a chaque restaurant en base
- couleurs, nom public, logo, titre portail et libelle d'installation geres en administration

## 2. Ce qui doit etre gere par parametrage administratif

- creation du tenant restaurant
- activation ou suspension du tenant
- configuration du branding
- configuration du menu et des categories
- configuration de modules actives
- configuration des liens d'acces et options PWA
- choix du plan d'abonnement

## 3. Ce qui necessite une generation specifique par restaurant

- vraie application mobile native signee au nom du restaurant sur stores
- package Android/iOS avec icones, splash screens et metadonnees propres
- publication sur Play Store / App Store

## Recommandation realiste pour Hostinger

La meilleure approche immediate et maintenable est:

- un back-office web multi-tenant
- un portail client responsive
- une PWA installable par restaurant
- un domaine ou sous-domaine brandable
- un fallback robuste par slug URL de type `/portal/{slug}` quand le DNS custom n'est pas encore configure

Pourquoi:

- une seule base de code a maintenir
- deploiement simple sur hebergement mutualise ou VPS Hostinger
- pas de pipeline mobile natif complexe a automatiser
- installation "comme une application" possible sur smartphone via PWA

## Conclusion

Pour la vente multi-restaurant a court terme, le bon compromis est:

- coeur SaaS unique
- branding 100 % base de donnees
- portail client/PWA white-label
- generation native reservee a une offre premium ou a une phase ulterieure
