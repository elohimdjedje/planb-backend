# Script PowerShell pour démarrer le serveur PHP avec support vidéo
# Usage: .\start-server.ps1

Write-Host "🚀 Démarrage du serveur PlanB Backend..." -ForegroundColor Cyan
Write-Host ""

# Vérifier que PHP est installé
try {
    $phpVersion = php -v 2>&1 | Select-String "PHP" | Select-Object -First 1
    Write-Host "✅ PHP détecté: $phpVersion" -ForegroundColor Green
} catch {
    Write-Host "❌ PHP n'est pas installé ou n'est pas dans le PATH" -ForegroundColor Red
    Write-Host "   Installez PHP depuis https://windows.php.net/download/" -ForegroundColor Yellow
    exit 1
}

Write-Host ""
Write-Host "📁 Répertoire: $PWD" -ForegroundColor Cyan
Write-Host "🌐 Serveur: http://0.0.0.0:8000" -ForegroundColor Cyan
Write-Host "📱 Accès mobile: http://172.20.10.1:8000" -ForegroundColor Cyan
Write-Host ""
Write-Host "✨ Fonctionnalités activées:" -ForegroundColor Green
Write-Host "   • Support vidéo iOS (Range Requests)" -ForegroundColor White
Write-Host "   • Streaming progressif" -ForegroundColor White
Write-Host "   • Fichiers statiques optimisés" -ForegroundColor White
Write-Host ""
Write-Host "⚠️  Appuyez sur Ctrl+C pour arrêter le serveur" -ForegroundColor Yellow
Write-Host ""
Write-Host "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━" -ForegroundColor DarkGray
Write-Host ""

# Démarrer le serveur avec le router
php -S 0.0.0.0:8000 -t public router.php
