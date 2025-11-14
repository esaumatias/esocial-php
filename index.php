<?php
/**
 * API REST para integração com eSocial
 * Este serviço atua como ponte entre o sistema Node.js e a biblioteca PHP do eSocial
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Tratar requisições OPTIONS (preflight)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once __DIR__ . '/vendor/autoload.php';

use EsocialService\ApiRouter;

// Carregar variáveis de ambiente
// No Heroku, as variáveis vêm de getenv(), então priorizamos isso
// Para desenvolvimento local, carrega do arquivo .env
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        // Só definir se não estiver já definido (Heroku tem prioridade)
        if (!isset($_ENV[$name]) && getenv($name) === false) {
            $_ENV[$name] = $value;
        }
    }
}

// Carregar variáveis do Heroku (getenv)
foreach ($_SERVER as $key => $value) {
    if (strpos($key, 'HEROKU_') === 0 || strpos($key, 'ESOCIAL_') === 0) {
        $_ENV[$key] = $value;
    }
}

try {
    // Verificar se o autoload existe
    $autoloadPath = __DIR__ . '/vendor/autoload.php';
    if (!file_exists($autoloadPath)) {
        throw new Exception('Composer autoload não encontrado. Execute: composer install');
    }
    
    require_once $autoloadPath;
    
    $router = new EsocialService\ApiRouter();
    $router->handleRequest();
} catch (Exception $e) {
    http_response_code(500);
    
    // Log do erro
    error_log('Erro no index.php: ' . $e->getMessage());
    error_log('Stack trace: ' . $e->getTraceAsString());
    
    $debug = isset($_ENV['APP_DEBUG']) && $_ENV['APP_DEBUG'] === 'true';
    
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'file' => $debug ? $e->getFile() : null,
        'line' => $debug ? $e->getLine() : null,
        'trace' => $debug ? $e->getTraceAsString() : null
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

