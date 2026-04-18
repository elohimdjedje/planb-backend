# Script simple pour regénérer les secrets
Write-Host "Generation de nouveaux secrets..." -ForegroundColor Cyan

# Fonction de génération
function New-RandomSecret {
    param([int]$len = 32)
    $b = New-Object byte[] $len
    $r = New-Object System.Security.Cryptography.RNGCryptoServiceProvider
    $r.GetBytes($b)
    $s = ""
    foreach ($byte in $b) { $s += $byte.ToString("x2") }
    return $s
}

# Générer les secrets
$appSecret = New-RandomSecret
$jwtPass = New-RandomSecret

# Afficher les résultats
Write-Host ""
Write-Host "Nouveaux secrets generes :" -ForegroundColor Green
Write-Host ""
Write-Host "APP_SECRET=$appSecret" -ForegroundColor Yellow
Write-Host ""
Write-Host "JWT_PASSPHRASE=$jwtPass" -ForegroundColor Yellow
Write-Host ""
Write-Host "Copiez ces valeurs dans le fichier .env" -ForegroundColor Cyan
