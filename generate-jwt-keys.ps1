# Script PowerShell pour générer des clés JWT avec .NET
# Alternative pour Windows sans OpenSSL

Write-Host "🔐 Génération des clés JWT avec .NET..." -ForegroundColor Cyan

$keyPath = Join-Path $PSScriptRoot "config\jwt"
$privateKeyPath = Join-Path $keyPath "private.pem"
$publicKeyPath = Join-Path $keyPath "public.pem"

# Créer le dossier si nécessaire
if (-not (Test-Path $keyPath)) {
    New-Item -ItemType Directory -Path $keyPath -Force | Out-Null
}

# Générer une paire de clés RSA 4096 bits
Add-Type -AssemblyName System.Security
$rsa = [System.Security.Cryptography.RSA]::Create(4096)

# Exporter la clé privée au format PEM
$privateKeyBytes = $rsa.ExportRSAPrivateKey()
$privateKeyBase64 = [Convert]::ToBase64String($privateKeyBytes)
$privateKeyPem = "-----BEGIN RSA PRIVATE KEY-----`n"
for ($i = 0; $i -lt $privateKeyBase64.Length; $i += 64) {
    $length = [Math]::Min(64, $privateKeyBase64.Length - $i)
    $privateKeyPem += $privateKeyBase64.Substring($i, $length) + "`n"
}
$privateKeyPem += "-----END RSA PRIVATE KEY-----`n"

# Sauvegarder la clé privée
[System.IO.File]::WriteAllText($privateKeyPath, $privateKeyPem)
Write-Host "✅ Clé privée générée : config\jwt\private.pem" -ForegroundColor Green

# Exporter la clé publique au format PEM
$publicKeyBytes = $rsa.ExportSubjectPublicKeyInfo()
$publicKeyBase64 = [Convert]::ToBase64String($publicKeyBytes)
$publicKeyPem = "-----BEGIN PUBLIC KEY-----`n"
for ($i = 0; $i -lt $publicKeyBase64.Length; $i += 64) {
    $length = [Math]::Min(64, $publicKeyBase64.Length - $i)
    $publicKeyPem += $publicKeyBase64.Substring($i, $length) + "`n"
}
$publicKeyPem += "-----END PUBLIC KEY-----`n"

# Sauvegarder la clé publique
[System.IO.File]::WriteAllText($publicKeyPath, $publicKeyPem)
Write-Host "✅ Clé publique générée : config\jwt\public.pem" -ForegroundColor Green

Write-Host "`n🎉 Clés JWT générées avec succès !" -ForegroundColor Green
Write-Host "📝 Note : Ces clés ne sont PAS chiffrées (pas de passphrase)" -ForegroundColor Yellow
Write-Host "   Pour le développement, c'est suffisant." -ForegroundColor Yellow

# Nettoyer
$rsa.Dispose()
