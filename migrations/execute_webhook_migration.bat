@echo off
REM Script batch pour appliquer la migration webhook_logs
REM Usage: execute_webhook_migration.bat

echo.
echo ========================================
echo Application Migration Webhook Logs
echo ========================================
echo.

REM Chercher psql dans les emplacements courants
set PSQL_PATH=
if exist "C:\Program Files\PostgreSQL\16\bin\psql.exe" set PSQL_PATH=C:\Program Files\PostgreSQL\16\bin\psql.exe
if exist "C:\Program Files\PostgreSQL\15\bin\psql.exe" set PSQL_PATH=C:\Program Files\PostgreSQL\15\bin\psql.exe
if exist "C:\Program Files\PostgreSQL\14\bin\psql.exe" set PSQL_PATH=C:\Program Files\PostgreSQL\14\bin\psql.exe
if exist "C:\Program Files\PostgreSQL\13\bin\psql.exe" set PSQL_PATH=C:\Program Files\PostgreSQL\13\bin\psql.exe

if "%PSQL_PATH%"=="" (
    echo [ERREUR] psql.exe non trouve dans les emplacements standards.
    echo.
    echo Veuillez utiliser pgAdmin:
    echo 1. Ouvrir pgAdmin
    echo 2. Se connecter a la base 'planb'
    echo 3. Query Tool (F5)
    echo 4. Copier-coller le contenu de add_webhook_logs.sql
    echo 5. Executer (F5)
    echo.
    pause
    exit /b 1
)

echo [OK] psql trouve: %PSQL_PATH%
echo.

REM Lire les informations de connexion depuis .env
cd /d "%~dp0.."
if not exist .env (
    echo [ERREUR] Fichier .env non trouve dans planb-backend
    pause
    exit /b 1
)

echo [INFO] Lecture de DATABASE_URL depuis .env...
for /f "tokens=*" %%a in ('type .env ^| findstr /i "DATABASE_URL"') do set DATABASE_URL=%%a

if "%DATABASE_URL%"=="" (
    echo [ERREUR] DATABASE_URL non trouve dans .env
    echo.
    echo Veuillez configurer DATABASE_URL dans planb-backend\.env
    pause
    exit /b 1
)

REM Extraire les informations (format: postgresql://user:pass@host:port/db)
REM Note: Cette extraction est basique, peut necessiter ajustement
echo [INFO] Connexion a PostgreSQL...
echo.

REM Essayer d'executer le script SQL
cd /d "%~dp0"
echo [INFO] Execution de add_webhook_logs.sql...
echo.

REM Demander les informations de connexion
echo Veuillez entrer les informations de connexion PostgreSQL:
set /p PGUSER="Utilisateur (postgres): "
if "%PGUSER%"=="" set PGUSER=postgres
set /p PGDATABASE="Base de donnees (planb): "
if "%PGDATABASE%"=="" set PGDATABASE=planb
set /p PGHOST="Host (localhost): "
if "%PGHOST%"=="" set PGHOST=localhost
set /p PGPORT="Port (5432): "
if "%PGPORT%"=="" set PGPORT=5432

echo.
echo [INFO] Connexion: %PGUSER%@%PGHOST%:%PGPORT%/%PGDATABASE%
echo [INFO] Mot de passe sera demande...
echo.

"%PSQL_PATH%" -h %PGHOST% -p %PGPORT% -U %PGUSER% -d %PGDATABASE% -f add_webhook_logs.sql

if %ERRORLEVEL% EQU 0 (
    echo.
    echo ========================================
    echo [SUCCES] Migration appliquee avec succes!
    echo ========================================
    echo.
) else (
    echo.
    echo ========================================
    echo [ERREUR] Echec de la migration
    echo ========================================
    echo.
    echo Alternative: Utiliser pgAdmin
    echo 1. Ouvrir pgAdmin
    echo 2. Se connecter a la base 'planb'
    echo 3. Query Tool (F5)
    echo 4. Copier-coller le contenu de add_webhook_logs.sql
    echo 5. Executer (F5)
    echo.
)

pause


