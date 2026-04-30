$projectRoot = Split-Path -Parent $PSScriptRoot

Write-Host "Phase 1B static verification"

$phpFiles = Get-ChildItem $projectRoot -Recurse -Filter *.php
$requiredPatterns = @(
    'restaurant_created',
    'branding_updated',
    'user_created',
    'user_role_changed',
    'user_status_changed',
    'menu_item_created',
    'menu_item_updated',
    'menu_item_status_changed'
)

Write-Host ("PHP files: " + $phpFiles.Count)
Write-Host ("Routes web: " + (Select-String -Path "$projectRoot\routes\web.php" -Pattern '\$router->').Count)
Write-Host ("Routes api: " + (Select-String -Path "$projectRoot\routes\api.php" -Pattern '\$router->').Count)
Write-Host ("SQL tables: " + (Select-String -Path "$projectRoot\database\schema.sql" -Pattern '^CREATE TABLE ').Count)

foreach ($pattern in $requiredPatterns) {
    $hits = Get-ChildItem "$projectRoot\app" -Recurse -Filter *.php | Select-String -Pattern $pattern
    Write-Host ($pattern + ': ' + ($hits.Count -gt 0))
}
