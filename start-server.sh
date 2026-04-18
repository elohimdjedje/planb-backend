#!/bin/bash
# Script Bash pour démarrer le serveur PHP avec support vidéo
# Usage: ./start-server.sh

echo "🚀 Démarrage du serveur PlanB Backend..."
echo ""

# Vérifier que PHP est installé
if ! command -v php &> /dev/null; then
    echo "❌ PHP n'est pas installé ou n'est pas dans le PATH"
    echo "   Installez PHP depuis votre gestionnaire de paquets"
    exit 1
fi

PHP_VERSION=$(php -v | head -n 1)
echo "✅ PHP détecté: $PHP_VERSION"

echo ""
echo "📁 Répertoire: $(pwd)"
echo "🌐 Serveur: http://0.0.0.0:8000"
echo "📱 Accès mobile: http://172.20.10.1:8000"
echo ""
echo "✨ Fonctionnalités activées:"
echo "   • Support vidéo iOS (Range Requests)"
echo "   • Streaming progressif"
echo "   • Fichiers statiques optimisés"
echo ""
echo "⚠️  Appuyez sur Ctrl+C pour arrêter le serveur"
echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# Démarrer le serveur avec le router
php -S 0.0.0.0:8000 -t public router.php
