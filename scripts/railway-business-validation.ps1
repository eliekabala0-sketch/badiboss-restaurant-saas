# Validation métier Railway (manuelle guidée) — arriérés, caisse, jauges.
# Usage: .\scripts\railway-business-validation.ps1 -BaseUrl https://web-production-99f3.up.railway.app
# Ne modifie pas la base ; lecture + actions UI selon vos comptes tests.

param(
    [string]$BaseUrl = "https://web-production-99f3.up.railway.app"
)

Write-Host "=== 1. Version ===" -ForegroundColor Cyan
try {
    $v = Invoke-RestMethod -Uri "$BaseUrl/health/version" -Method Get
    Write-Host ("commit_short: " + $v.commit_short)
} catch {
    Write-Host $_ -ForegroundColor Red
}

Write-Host "`n=== 2. Scénarios à exécuter dans l’UI (comptes .test / mot de passe seed) ===" -ForegroundColor Cyan
Write-Host @"

A. Blocage commande non clôturée
   1) Se connecter en serveur, créer une commande service, ne pas clôturer.
   2) Modifier created_at en base (veille) UNIQUEMENT si vous savez le faire sans TRUNCATE,
      ou attendre le lendemain (fuseau restaurant).
   3) Reconnecter le même serveur : bandeau « Actions à régulariser » attendu,
      POST nouvelle vente/commande doit refuser avec message explicite.

B. Remise caisse en attente
   1) Serveur : clôturer une vente et enregistrer remise statut « remis à caisse » (non reçu).
   2) Passer la date de la remise avant aujourd’hui 0h (restaurant) ou attendre lendemain.
   3) Caissier : bandeau + file ; réception ou rejet doit lever le blocage sans rafraîchissement forcé.

C. Demande stock ouverte
   1) Cuisine : demande magasin sans clôture côté stock.
   2) created_at < aujourd’hui (restaurant) ou lendemain naturel.
   3) Stock (ou cuisine selon règle) : bandeau + traitement pour lever le blocage.

D. Vérité financière (pas d’argent fantôme)
   1) Vente clôturée avec montant connu.
   2) Remise serveur ≤ total ventes liées.
   3) Caisse « reçu » uniquement après action caissier explicite ; rejet exclu des reçus.
   4) Contrôler écart vendu − reçu sur /caisse et rapport du jour.

E. Jauges
   Sur /cuisine, /stock, /caisse, /ventes : ouvrir le bloc repliable « Discipline · … »,
   changer les onglets période et vérifier score + liste d’activité tracée.

F. Résolution responsable (bloc sous ?focus=, gérant/propriétaire/super admin uniquement)
   1) /ventes?focus=server_request:ID — bloc visible gérant, décisions servie / sans vente / manquant / annuler.
   2) POST /ventes/resolution-responsable — pas de 500 ; agent débloqué si cas réglé ; message pénalité conservée / clémence auditée.
   3) /caisse?focus=cash_transfer:ID — réception totale, partielle, rejet définitif, escalade propriétaire ; manquant serveur si écart.
   4) Imputation SALE_DAY / REMITTANCE_DAY / RESOLUTION_DAY (formulaire bloc + rapport remises tardives).
   5) Serveur simple : pas de bloc décision ; owner : voit clémences dans audit / file caisse escaladée.

"@

Write-Host "`nFin (aucune requête métier automatique lancée)." -ForegroundColor Green
