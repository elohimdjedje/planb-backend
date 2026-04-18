# Script PowerShell pour appliquer la migration webhook_logs
# Usage: .\apply_webhook_migration.ps1

Write-Host "🗄️ Application de la migration webhook_logs..." -ForegroundColor Cyan

# Lire DATABASE_URL depuis .env
$envFile = Join-Path $PSScriptRoot "..\.env"
if (-not (Test-Path $envFile)) {
    Write-Host "❌ Fichier .env non trouvé dans planb-backend" -ForegroundColor Red
    exit 1
}

$envContent = Get-Content $envFile -Raw
$dbUrl = ($envContent | Select-String -Pattern "DATABASE_URL=(.+)" | ForEach-Object { $_.Matches.Groups[1].Value })

if (-not $dbUrl) {
    Write-Host "⚠️ DATABASE_URL non trouvé dans .env" -ForegroundColor Yellow
    Write-Host "Veuillez exécuter manuellement le script SQL:" -ForegroundColor Yellow
    Write-Host "  planb-backend\migrations\add_webhook_logs.sql" -ForegroundColor White
    exit 1
}

# Extraire les informations de connexion
if ($dbUrl -match "postgresql://([^:]+):([^@]+)@([^:]+):(\d+)/(.+)") {
    $username = $matches[1]
    $password = $matches[2]
    $host = $matches[3]
    $port = $matches[4]
    $database = $matches[5]
    
    Write-Host "📊 Connexion à PostgreSQL..." -ForegroundColor Yellow
    Write-Host "  Host: $host" -ForegroundColor Gray
    Write-Host "  Database: $database" -ForegroundColor Gray
    Write-Host "  User: $username" -ForegroundColor Gray
    
    # Vérifier si psql est disponible
    $psqlPath = Get-Command psql -ErrorAction SilentlyContinue
    if (-not $psqlPath) {
        Write-Host "⚠️ psql non trouvé dans le PATH" -ForegroundColor Yellow
        Write-Host "`n📋 Instructions manuelles:" -ForegroundColor Cyan
        Write-Host "1. Ouvrir pgAdmin" -ForegroundColor White
        Write-Host "2. Se connecter à la base: $database" -ForegroundColor White
        Write-Host "3. Ouvrir Query Tool (F5)" -ForegroundColor White
        Write-Host "4. Copier-coller le contenu de: add_webhook_logs.sql" -ForegroundColor White
        Write-Host "5. Exécuter (F5)" -ForegroundColor White
        exit 0
    }
    
    # Lire le script SQL
    $sqlFile = Join-Path $PSScriptRoot "add_webhook_logs.sql"
    $sqlContent = Get-Content $sqlFile -Raw
    
    # Exécuter via psql
    $env:PGPASSWORD = $password
    try {
        $result = $sqlContent | & psql -h $host -p $port -U $username -d $database -c $sqlContent 2>&1
        
        if ($LASTEXITCODE -eq 0) {
            Write-Host "✅ Migration appliquée avec succès !" -ForegroundColor Green
        } else {
            Write-Host "❌ Erreur lors de l'application de la migration" -ForegroundColor Red
            Write-Host $result -ForegroundColor Red
        }
    } catch {
        Write-Host "❌ Erreur: $_" -ForegroundColor Red
    } finally {
        Remove-Item Env:\PGPASSWORD -ErrorAction SilentlyContinue
    }
    
} else {
    Write-Host "⚠️ Format DATABASE_URL non reconnu" -ForegroundColor Yellow
    Write-Host "Veuillez exécuter manuellement le script SQL:" -ForegroundColor Yellow
    Write-Host "  planb-backend\migrations\add_webhook_logs.sql" -ForegroundColor White
}


