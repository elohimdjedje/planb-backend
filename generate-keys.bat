@echo off
echo Génération des clés JWT avec Docker...

docker run --rm -v "%CD%\config\jwt:/jwt" alpine/openssl genrsa -out /jwt/private.pem 4096
docker run --rm -v "%CD%\config\jwt:/jwt" alpine/openssl rsa -in /jwt/private.pem -pubout -out /jwt/public.pem

echo.
echo Clés JWT générées avec succès !
echo - Clé privée : config\jwt\private.pem
echo - Clé publique : config\jwt\public.pem
pause
