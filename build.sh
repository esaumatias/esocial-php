#!/bin/bash
# Script de build para Heroku
# Este script copia os arquivos necessários para a raiz durante o build

echo "Building eSocial Service for Heroku..."

# Se já estamos na raiz do esocial-service, não precisa fazer nada
if [ -f "composer.json" ] && [ -f "index.php" ]; then
    echo "✓ Arquivos já estão na raiz"
    exit 0
fi

# Se estamos no diretório raiz do projeto e esocial-service existe
if [ -d "esocial-service" ]; then
    echo "✓ Copiando arquivos de esocial-service para raiz..."
    cp -r esocial-service/* .
    cp -r esocial-service/.* . 2>/dev/null || true
    echo "✓ Build concluído"
fi

