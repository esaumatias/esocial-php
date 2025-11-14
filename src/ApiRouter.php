<?php

namespace EsocialService;

use EsocialService\Controllers\EsocialController;

class ApiRouter
{
    private $controller;

    public function __construct()
    {
        $this->controller = new EsocialController();
    }

    public function handleRequest()
    {
        $method = $_SERVER['REQUEST_METHOD'];
        $path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $path = str_replace('/index.php', '', $path);
        $path = trim($path, '/');

        // Obter dados do corpo da requisição
        $input = json_decode(file_get_contents('php://input'), true) ?? [];

        // Rotas da API
        switch (true) {
            case $path === 'health' && $method === 'GET':
                $this->sendResponse(['status' => 'ok', 'service' => 'esocial-api']);
                break;

            case $path === 'config' && $method === 'GET':
                $this->controller->getConfig();
                break;

            case $path === 'config' && $method === 'POST':
                $this->controller->saveConfig($input);
                break;

            case $path === 'eventos' && $method === 'POST':
                $this->controller->enviarEvento($input);
                break;

            case $path === 'eventos' && $method === 'GET':
                $this->controller->consultarEvento($_GET);
                break;

            case $path === 'lotes' && $method === 'POST':
                $this->controller->enviarLote($input);
                break;

            case $path === 'lotes' && $method === 'GET':
                $this->controller->consultarLote($_GET);
                break;

            case $path === 'validar' && $method === 'POST':
                $this->controller->validarEvento($input);
                break;

            default:
                http_response_code(404);
                $this->sendResponse([
                    'success' => false,
                    'error' => 'Rota não encontrada'
                ]);
        }
    }

    private function sendResponse($data, $statusCode = 200)
    {
        http_response_code($statusCode);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}

