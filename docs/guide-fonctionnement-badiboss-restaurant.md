# Guide de fonctionnement Badiboss Restaurant

## Objectif

Ce guide fixe les règles métier et techniques à préserver dans Badiboss Restaurant, pour éviter les régressions en production.

## Rôles

- `owner` : pilote le restaurant, tranche les litiges, prépare la paie, garde la vue globale.
- `manager` : gère l’opérationnel avec les mêmes arbitrages métier autorisés selon permissions.
- `cashier_server` : prend les demandes, reçoit les produits de la cuisine, remet l’argent à la caisse.
- `kitchen` : prépare, fournit, valide les retours cuisine.
- `stock_manager` : gère le magasin, alimente la cuisine, suit les écarts.
- `cashier` : reçoit les remises, suit les écarts de caisse.

Le propriétaire n’est pas coté en discipline. Les vues d’équipe sont réservées au propriétaire / gérant selon permissions. Un serveur ne voit que sa propre jauge.

## Flux principal

### Stock -> cuisine -> serveur -> caisse

1. Le magasin enregistre les entrées et sorties.
2. La cuisine reçoit du stock validé et produit.
3. Le serveur envoie une demande.
4. La cuisine prépare puis remet.
5. Le serveur confirme la réception.
6. La vente appartient au jour d’activité.
7. La remise caisse est un flux séparé.

### Vente vs remise caisse

- `vente` : lecture métier liée au jour d’activité.
- `remise caisse` : lecture financière liée au jour de remise / réception caisse.
- Une vente du 10 reste une vente du 10, même si l’argent est remis le 11.
- Le gérant peut arbitrer l’imputation caisse si la remise est tardive.

## Règle clé : servi au serveur = vente lue

Tout produit reçu par le serveur depuis la cuisine est une vente du jour d’activité, sauf :

- retour cuisine validé ;
- rejet / annulation validé ;
- décision responsable contraire.

Conséquences :

- une demande `REMIS_SERVEUR` sans `sale` liée doit remonter dans les rapports de vente ;
- cette lecture ne doit pas créer automatiquement une `sale` ;
- aucune double vente si une `sale` liée existe déjà ;
- aucun double stock ;
- les retours cuisine validés doivent être exclus de la partie vendue.

## Décision gérant souveraine

Le gérant / owner décide, le système applique immédiatement.

Après décision :

- le dossier sort des arriérés actifs ;
- le compte n’est plus bloqué ;
- l’historique reste visible ;
- les pénalités peuvent rester ;
- les manquants peuvent rester ;
- aucun nouveau mouvement stock ne doit être créé si le stock a déjà été impacté.

## Discipline et jauges

### Point de départ

La jauge mensuelle commence au début réel du fonctionnement du restaurant, ou à la date réelle de début de service de l’agent si elle est renseignée.

### Présence

- présence automatique par activité réelle ;
- pas de saisie manuelle “présent” ;
- le gérant peut justifier absence, repos, maladie, exonération.

### Dégradation de la jauge

La jauge part de 100 puis baisse selon :

- manquants ;
- remises tardives ;
- retards ;
- absences ;
- absences répétées ;
- inactivité ;
- faible activité par rapport au groupe de rôle ;
- opérations abandonnées.

### Affichage attendu

- Excellent
- Bon
- Moyen
- Problématique
- Très problématique

Éviter les faux `100` et l’abus de `Non évalué`.

## Paie

La préparation paie est un audit / calcul proposé, jamais un paiement automatique.

Les éléments à considérer :

- salaire de base ;
- primes ;
- repos ;
- absences justifiées / non justifiées ;
- manquants ;
- sanctions / retenues ;
- net proposé.

## Contrôle stock

Par article, garder une lecture simple :

- stock départ ;
- entrées ;
- sorties cuisine ;
- ventes ;
- retours ;
- pertes ;
- disponible magasin ;
- disponible cuisine ;
- total théorique.

Principes à ne pas casser :

- jamais de double déduction ;
- une résolution gérant ne doit pas recréer une consommation stock ;
- une boisson déjà sortie et non vendue reste physiquement disponible ;
- un retour cuisine validé doit corriger la lecture vendue.

## Rapports

Rapports à préserver :

- journalier ;
- hebdomadaire ;
- mensuel ;
- annuel si activé plus tard.

Chaque rapport doit distinguer :

- vendu activité ;
- remis caisse ;
- reçu caisse ;
- manquants ;
- écarts ;
- activité agents ;
- cohérence stock.

Ne jamais confondre vente activité et remise caisse.

## Navigation et performance

Bonnes pratiques :

- libérer la session PHP tôt sur les grosses pages en lecture ;
- éviter les doublons visuels ;
- garder un bloc essentiel ouvert, le reste repliable ;
- préserver les vues téléphone et ordinateur ;
- éviter les requêtes dépendantes de colonnes optionnelles sans garde de schéma.

## Badiboss Pay : préparation seulement

Points d’entrée à préparer sans activer de paiement réel :

- abonnement restaurant ;
- déclaration / validation de paiement abonnement ;
- encaissement restaurant futur ;
- callbacks fournisseur de paiement ;
- statut de paiement ;
- journal d’audit de paiement.

À préserver :

- la caisse actuelle ;
- la séparation vente / remise caisse ;
- l’audit par restaurant.

## Guide développeur

### Règles de sécurité

- toujours filtrer par `restaurant_id` ;
- ne jamais faire de régularisation globale silencieuse ;
- ne jamais créer de vente automatique hors flux explicitement validé ;
- protéger les lectures si certaines colonnes / tables optionnelles ne sont pas encore déployées ;
- tester en sandbox avant production pour toute mutation.

### Zones sensibles

- `ReportService`
- `SalesService`
- `RegularizationGateService`
- `ManagerResolutionService`
- `CashService`
- `StockService`
- `StaffDisciplineService`

### Points à ne pas casser

- retour cuisine ;
- rejet commande ;
- souveraineté gérant ;
- anti-doublon ;
- vente au jour d’activité ;
- séparation vente / caisse ;
- non-redéduction stock ;
- isolation multi-tenant stricte.
