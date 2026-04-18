# Script PowerShell pour appliquer la migration de modération
# À exécuter après avoir configuré la connexion PostgreSQL

Write-Host "`n🛡️  APPLICATION DE LA MIGRATION DE MODÉRATION`n" -ForegroundColor Cyan

$sqlFile = Join-Path $PSScriptRoot "add_moderation.sql"

if (-not (Test-Path $sqlFile)) {
    Write-Host "❌ Fichier SQL non trouvé: $sqlFile" -ForegroundColor Red
    exit 1
}

Write-Host "📄 Fichier SQL trouvé: $sqlFile" -ForegroundColor Green
Write-Host "`n⚠️  INSTRUCTIONS:" -ForegroundColor Yellow
Write-Host "1. Ouvrir pgAdmin" -ForegroundColor White
Write-Host "2. Se connecter à PostgreSQL" -ForegroundColor White
Write-Host "3. Sélectionner la base de données 'planb'" -ForegroundColor White
Write-Host "4. Ouvrir Query Tool (F5)" -ForegroundColor White
Write-Host "5. Ouvrir le fichier: $sqlFile" -ForegroundColor White
Write-Host "6. Copier tout le contenu (Ctrl+A, Ctrl+C)" -ForegroundColor White
Write-Host "7. Coller dans Query Tool (Ctrl+V)" -ForegroundColor White
Write-Host "8. Exécuter (F5)`n" -ForegroundColor White

Write-Host "📋 Contenu du fichier SQL:" -ForegroundColor Cyan
Write-Host "─────────────────────────────────────────────────────────" -ForegroundColor Gray
Get-Content $sqlFile | Write-Host
Write-Host "─────────────────────────────────────────────────────────`n" -ForegroundColor Gray

Write-Host "✅ Après exécution, vérifiez avec:" -ForegroundColor Green
Write-Host "   SELECT column_name FROM information_schema.columns" -ForegroundColor White
Write-Host "   WHERE table_name = 'users'" -ForegroundColor White
Write-Host "   AND column_name IN ('is_banned', 'is_suspended', 'warnings_count');`n" -ForegroundColor White

Write-Host "   SELECT table_name FROM information_schema.tables" -ForegroundColor White
Write-Host "   WHERE table_name = 'moderation_actions';`n" -ForegroundColor White


