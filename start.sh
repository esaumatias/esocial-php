#!/bin/bash

# Script para iniciar o serviço PHP eSocial

echo "🚀 Iniciando serviço eSocial..."

# Verificar se o Composer está instalado
if ! command -v composer &> /dev/null; then
    echo "❌ Composer não encontrado. Instalando..."
    curl -sS https://getcomposer.org/installer | php
    sudo mv composer.phar /usr/local/bin/composer
fi

# Verificar se as dependências estão instaladas
if [ ! -d "vendor" ]; then
    echo "📦 Instalando dependências..."
    composer install
fi

# Verificar se o arquivo .env existe
if [ ! -f ".env" ]; then
    echo "📝 Criando arquivo .env..."
    cp .env.example .env
    echo "⚠️  Configure o arquivo .env antes de continuar"
fi

# Obter porta do .env ou usar padrão
PORT=$(grep ESOCIAL_SERVICE_PORT .env 2>/dev/null | cut -d '=' -f2)
PORT=${PORT:-8080}

echo "✅ Serviço iniciado em http://localhost:${PORT}"
echo "📋 Para parar o serviço, pressione Ctrl+C"
echo ""

# Iniciar servidor PHP
php -S localhost:${PORT} -t . index.php

