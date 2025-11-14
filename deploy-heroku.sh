#!/bin/bash
# Script para fazer deploy do esocial-service no Heroku
# Este script prepara o repositório e faz o deploy

set -e

echo "🚀 Preparando deploy do eSocial Service para Heroku..."

# Verificar se estamos na pasta correta
if [ ! -f "composer.json" ] || [ ! -f "index.php" ]; then
    echo "❌ Erro: Execute este script dentro da pasta esocial-service"
    exit 1
fi

# Verificar se o Heroku CLI está instalado
if ! command -v heroku &> /dev/null; then
    echo "❌ Heroku CLI não encontrado. Instale em: https://devcenter.heroku.com/articles/heroku-cli"
    exit 1
fi

# Verificar se já existe um app Heroku configurado
if [ -z "$HEROKU_APP_NAME" ]; then
    echo "📝 Informe o nome do app Heroku:"
    read -r HEROKU_APP_NAME
fi

# Inicializar git se não existir
if [ ! -d ".git" ]; then
    echo "📦 Inicializando repositório Git..."
    git init
    git add .
    git commit -m "Initial commit - eSocial Service"
fi

# Configurar remote do Heroku
echo "🔗 Configurando Heroku remote..."
heroku git:remote -a "$HEROKU_APP_NAME" 2>/dev/null || heroku create "$HEROKU_APP_NAME"

# Configurar buildpack PHP
echo "⚙️  Configurando buildpack PHP..."
heroku buildpacks:set heroku/php -a "$HEROKU_APP_NAME"

# Renomear branch para main se necessário
CURRENT_BRANCH=$(git branch --show-current)
if [ "$CURRENT_BRANCH" = "master" ]; then
    echo "🔄 Renomeando branch master para main..."
    git branch -M main 2>/dev/null || true
fi

# Fazer deploy
echo "📤 Fazendo deploy..."
git push heroku main 2>/dev/null || git push heroku master

echo "✅ Deploy concluído!"
echo "🌐 URL: https://$HEROKU_APP_NAME.herokuapp.com"
echo ""
echo "📋 Próximos passos:"
echo "1. Teste: curl https://$HEROKU_APP_NAME.herokuapp.com/health"
echo "2. Configure ESOCIAL_SERVICE_URL no seu app Node.js:"
echo "   heroku config:set ESOCIAL_SERVICE_URL=https://$HEROKU_APP_NAME.herokuapp.com -a seu-app-nodejs"

