# Script pour mettre à jour les secrets dans .env
# Ce script génère de nouveaux secrets sécurisés pour APP_SECRET et JWT_PASSPHRASE

Write-Host "🔒 Mise à jour des secrets de sécurité..." -ForegroundColor Cyan

# Chemin du fichier .env
$envFile = Join-Path $PSScriptRoot ".env"

# Vérifier que le fichier existe
if (-not (Test-Path $envFile)) {
    Write-Host "❌ Fichier .env non trouvé. Copiez .env.example vers .env d'abord." -ForegroundColor Red
    exit 1
}

# Fonction pour générer un secret aléatoire
function Generate-RandomSecret {
    param([int]$Length = 64)
    $bytes = New-Object byte[] ($Length / 2)
    $rng = New-Object System.Security.Cryptography.RNGCryptoServiceProvider
    $rng.GetBytes($bytes)
    $rng.Dispose()
    $secret = ($bytes | ForEach-Object { $_.ToString('x2') }) -join ''
    return $secret
}

# Générer les nouveaux secrets
$newAppSecret = Generate-RandomSecret -Length 64
$newJwtPassphrase = Generate-RandomSecret -Length 64

Write-Host "✅ Nouveaux secrets générés" -ForegroundColor Green

# Créer un backup du fichier .env actuel
$backupFile = "$envFile.backup-$(Get-Date -Format 'yyyyMMdd-HHmmss')"
Copy-Item $envFile $backupFile
Write-Host "📦 Backup créé: $backupFile" -ForegroundColor Yellow

# Lire le contenu du fichier .env
$content = Get-Content $envFile -Raw

# Remplacer APP_SECRET (ligne qui commence par APP_SECRET=)
$content = $content -replace '(?m)^APP_SECRET=.*$', "APP_SECRET=$newAppSecret"

# Remplacer JWT_PASSPHRASE (ligne qui commence par JWT_PASSPHRASE=)
$content = $content -replace '(?m)^JWT_PASSPHRASE=.*$', "JWT_PASSPHRASE=$newJwtPassphrase"

# Écrire le nouveau contenu
Set-Content -Path $envFile -Value $content -NoNewline

Write-Host "" 
Write-Host "✅ Secrets mis à jour avec succès !" -ForegroundColor Green
Write-Host ""
Write-Host "⚠️  IMPORTANT :" -ForegroundColor Yellow
Write-Host "   - Tous les tokens JWT existants sont maintenant invalides" -ForegroundColor Yellow
Write-Host "   - Les utilisateurs devront se reconnecter" -ForegroundColor Yellow
Write-Host "   - Vous devez regénérer les clés JWT:" -ForegroundColor Yellow
Write-Host "     php bin/console lexik:jwt:generate-keypair --overwrite" -ForegroundColor Cyan
Write-Host ""
Write-Host "📝 Anciennes valeurs sauvegardées dans: $backupFile" -ForegroundColor Gray
