<?php

namespace EsocialService\Controllers;

use NFePHP\eSocial\Tools;
use NFePHP\eSocial\Common\Soap\SoapCurl;
use NFePHP\Common\Certificate;

class EsocialController
{
    private $config;
    private $tools;

    public function __construct()
    {
        $this->loadConfig();
    }

    /**
     * Carrega configuração do eSocial
     */
    private function loadConfig()
    {
        $configFile = __DIR__ . '/../../config.json';
        if (file_exists($configFile)) {
            $this->config = json_decode(file_get_contents($configFile), true);
        } else {
            $this->config = [
                'tpAmb' => 2, // 1-Produção, 2-Homologação
                'verProc' => 'SISTEMA-RH-1.0',
                'eventoVersion' => 'S.1.3.0', // Versão no formato correto (S.1.3.0 = v_S_01_03_00)
                'serviceVersion' => '1.5.0',
                'empregador' => [
                    'tpInsc' => 1, // 1-CNPJ, 2-CPF
                    'nrInsc' => '',
                    'nmRazao' => '', // Nome/Razão Social
                ],
                'certificate' => [
                    'pfx' => '',
                    'password' => ''
                ]
            ];
        }
    }

    /**
     * Retorna configuração atual
     */
    public function getConfig()
    {
        $response = $this->config;
        // Não retornar senha do certificado
        if (isset($response['certificate']['password'])) {
            $response['certificate']['password'] = '***';
        }
        $this->sendResponse($response);
    }

    /**
     * Salva configuração
     */
    public function saveConfig($data)
    {
        try {
            // Validar dados obrigatórios
            if (empty($data['empregador']['nrInsc'])) {
                throw new \Exception('CNPJ/CPF do empregador é obrigatório');
            }

            $this->config = array_merge($this->config, $data);
            
            // Salvar em arquivo
            $configFile = __DIR__ . '/../../config.json';
            file_put_contents($configFile, json_encode($this->config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            $this->sendResponse([
                'success' => true,
                'message' => 'Configuração salva com sucesso'
            ]);
        } catch (\Exception $e) {
            $this->sendError($e->getMessage());
        }
    }

    /**
     * Envia evento para o eSocial
     */
    public function enviarEvento($data)
    {
        try {
            if (empty($data['evento'])) {
                throw new \Exception('Dados do evento são obrigatórios');
            }

            $eventoData = $data['evento'];
            
            if (empty($eventoData['tipo'])) {
                throw new \Exception('Tipo do evento é obrigatório');
            }

            if (empty($eventoData['dados'])) {
                throw new \Exception('Dados do evento são obrigatórios');
            }

            // Verificar se a configuração existe
            if (empty($this->config['certificate']['pfx']) || empty($this->config['certificate']['password'])) {
                throw new \Exception('Certificado digital não configurado. Configure o certificado antes de enviar eventos.');
            }

            if (empty($this->config['empregador']['nrInsc'])) {
                throw new \Exception('CNPJ/CPF do empregador não configurado. Configure os dados do empregador antes de enviar eventos.');
            }

            // Guardar CNPJ original completo para usar no transmissor
            $cnpjOriginalCompleto = preg_replace('/\D/', '', $this->config['empregador']['nrInsc']);

            // Formatar CNPJ na configuração se for S-1000
            // REGRA OFICIAL: Para tpInsc = 1 (CNPJ), SEMPRE usar apenas a raiz do CNPJ (8 dígitos)
            if (($eventoData['tipo'] ?? '') === 'S-1000' && isset($eventoData['dados']['ideEmpregador']['tpInsc']) && $eventoData['dados']['ideEmpregador']['tpInsc'] == 1) {
                $dados = $eventoData['dados'] ?? [];
                if (isset($dados['ideEmpregador']['nrInsc']) && !empty($dados['ideEmpregador']['nrInsc'])) {
                    $cnpj = preg_replace('/\D/', '', (string)$dados['ideEmpregador']['nrInsc']);
                    $cnpjLength = strlen($cnpj);
                    
                    // Sempre usar apenas a raiz do CNPJ (8 dígitos) para tpInsc = 1
                    $cnpjFormatado = $cnpjLength >= 8 ? substr($cnpj, 0, 8) : str_pad($cnpj, 8, '0', STR_PAD_LEFT);
                    
                    // Atualizar CNPJ na configuração para usar o mesmo formato do evento
                    $this->config['empregador']['nrInsc'] = $cnpjFormatado;
                    error_log("S-1000: CNPJ da configuração atualizado para: {$cnpjFormatado} (8 dígitos - raiz do CNPJ)");
                }
            }
            
            // Guardar CNPJ original para usar no transmissor (deve ser o CNPJ completo do certificado)
            $this->config['_cnpjOriginalTransmissor'] = $cnpjOriginalCompleto;

            $this->initializeTools();

            // Montar evento conforme tipo usando Factory
            $evento = $this->montarEvento($eventoData);

            // Determinar o grupo do evento
            $grupo = $this->getGrupoEvento($eventoData['tipo']);

            // Enviar lote de eventos
            $response = $this->tools->enviarLoteEventos($grupo, [$evento]);

            $this->sendResponse([
                'success' => true,
                'data' => $response
            ]);
        } catch (\Exception $e) {
            error_log('Erro ao enviar evento eSocial: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            $this->sendError($e->getMessage(), 500);
        }
    }

    /**
     * Consulta status de um evento
     */
    public function consultarEvento($params)
    {
        try {
            if (empty($params['protocolo'])) {
                throw new \Exception('Protocolo é obrigatório');
            }

            $this->initializeTools();

            // Usar o método correto da biblioteca para consultar lote de eventos
            $response = $this->tools->consultarLoteEventos($params['protocolo']);

            $this->sendResponse([
                'success' => true,
                'data' => $response
            ]);
        } catch (\Exception $e) {
            error_log('Erro ao consultar evento: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            $this->sendError($e->getMessage());
        }
    }

    /**
     * Envia lote de eventos
     */
    public function enviarLote($data)
    {
        try {
            if (empty($data['eventos']) || !is_array($data['eventos'])) {
                throw new \Exception('Lista de eventos é obrigatória');
            }

            $this->initializeTools();

            // Montar todos os eventos
            $eventos = [];
            $grupo = null;
            foreach ($data['eventos'] as $eventoData) {
                $evento = $this->montarEvento($eventoData);
                $eventos[] = $evento;
                
                // Determinar grupo (todos devem ser do mesmo grupo)
                if ($grupo === null) {
                    $grupo = $this->getGrupoEvento($eventoData['tipo']);
                }
            }

            // Enviar lote de eventos
            $response = $this->tools->enviarLoteEventos($grupo, $eventos);

            $this->sendResponse([
                'success' => true,
                'data' => $response
            ]);
        } catch (\Exception $e) {
            $this->sendError($e->getMessage());
        }
    }

    /**
     * Consulta status de um lote
     */
    public function consultarLote($params)
    {
        try {
            if (empty($params['protocolo'])) {
                throw new \Exception('Protocolo do lote é obrigatório');
            }

            $this->initializeTools();

            // Usar o método correto da biblioteca para consultar lote de eventos
            $response = $this->tools->consultarLoteEventos($params['protocolo']);

            $this->sendResponse([
                'success' => true,
                'data' => $response
            ]);
        } catch (\Exception $e) {
            error_log('Erro ao consultar lote: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            $this->sendError($e->getMessage());
        }
    }

    /**
     * Valida estrutura de um evento sem enviar
     */
    public function validarEvento($data)
    {
        try {
            if (empty($data['evento'])) {
                throw new \Exception('Dados do evento são obrigatórios');
            }

            // Validar estrutura básica
            $evento = $data['evento'];
            $errors = [];

            // Validações básicas
            if (empty($evento['tipo'])) {
                $errors[] = 'Tipo do evento é obrigatório';
            }

            if (empty($evento['dados'])) {
                $errors[] = 'Dados do evento são obrigatórios';
            }

            if (!empty($errors)) {
                throw new \Exception('Erros de validação: ' . implode(', ', $errors));
            }

            $this->sendResponse([
                'success' => true,
                'message' => 'Evento válido',
                'errors' => []
            ]);
        } catch (\Exception $e) {
            $this->sendError($e->getMessage());
        }
    }

    /**
     * Inicializa ferramentas do eSocial
     */
    private function initializeTools()
    {
        if ($this->tools) {
            return;
        }

        // Verificar se a biblioteca está disponível
        if (!class_exists('NFePHP\eSocial\Tools')) {
            throw new \Exception('Biblioteca nfephp-org/sped-esocial não está instalada. Execute: composer install');
        }

        // Carregar certificado
        if (empty($this->config['certificate']['pfx']) || empty($this->config['certificate']['password'])) {
            throw new \Exception('Certificado digital não configurado');
        }

        try {
            $pfxContent = base64_decode($this->config['certificate']['pfx']);
            if ($pfxContent === false) {
                throw new \Exception('Erro ao decodificar certificado. Verifique se o certificado está em formato base64 válido.');
            }

            $certificate = Certificate::readPfx(
                $pfxContent,
                $this->config['certificate']['password']
            );
        } catch (\Exception $e) {
            throw new \Exception('Erro ao carregar certificado: ' . $e->getMessage());
        }

        try {
            // O transmissor DEVE ter o mesmo CNPJ/CPF do certificado
            // Como o certificado deve ter o mesmo CNPJ que está na configuração,
            // vamos usar o CNPJ completo original guardado antes da formatação
            $certificateCNPJ = $this->config['_cnpjOriginalTransmissor'] ?? '';
            
            // Se não tiver o CNPJ original guardado, tentar usar o CNPJ completo do config.json original
            if (empty($certificateCNPJ)) {
                $configFile = __DIR__ . '/../../config.json';
                if (file_exists($configFile)) {
                    $originalConfig = json_decode(file_get_contents($configFile), true);
                    $certificateCNPJ = preg_replace('/\D/', '', $originalConfig['empregador']['nrInsc'] ?? '');
                }
            }
            
            // Se ainda estiver vazio, usar o CNPJ atual da configuração (pode estar formatado)
            if (empty($certificateCNPJ)) {
                $certificateCNPJ = preg_replace('/\D/', '', $this->config['empregador']['nrInsc'] ?? '');
                // Se tiver apenas 8 dígitos, tentar reconstruir o CNPJ completo
                // (isso não é ideal, mas é melhor que nada)
                if (strlen($certificateCNPJ) == 8) {
                    error_log("Aviso: CNPJ do transmissor tem apenas 8 dígitos. Usando como está.");
                }
            }
            
            error_log("CNPJ do certificado/transmissor: {$certificateCNPJ} (tamanho: " . strlen($certificateCNPJ) . ")");

            // Montar configuração no formato esperado pela biblioteca
            // A versão do evento deve estar no formato "S.1.3.0"
            $eventoVersion = $this->config['eventoVersion'] ?? 'S.1.3.0';
            if (preg_match('/^\d+\.\d+\.\d+$/', $eventoVersion)) {
                $eventoVersion = 'S.1.3.0';
            }
            
            // Determinar tipo de inscrição do transmissor (CNPJ = 1, CPF = 2)
            $transmissorTpInsc = strlen($certificateCNPJ) == 14 ? 1 : (strlen($certificateCNPJ) == 11 ? 2 : 1);
            
            // Garantir que o CNPJ do empregador na configuração seja sempre 8 dígitos (raiz do CNPJ)
            $empregadorNrInsc = $this->config['empregador']['nrInsc'] ?? '';
            $empregadorNrInscOriginal = $empregadorNrInsc;
            if (!empty($empregadorNrInsc) && ($this->config['empregador']['tpInsc'] ?? 1) == 1) {
                $empregadorNrInsc = preg_replace('/\D/', '', (string)$empregadorNrInsc);
                if (strlen($empregadorNrInsc) >= 8) {
                    $empregadorNrInsc = substr($empregadorNrInsc, 0, 8);
                } else {
                    $empregadorNrInsc = str_pad($empregadorNrInsc, 8, '0', STR_PAD_LEFT);
                }
                error_log("🔧 INITIALIZE TOOLS: CNPJ do empregador formatado de '{$empregadorNrInscOriginal}' para '{$empregadorNrInsc}' (8 dígitos)");
            }
            
            $configArray = [
                'tpAmb' => $this->config['tpAmb'] ?? 2,
                'verProc' => $this->config['verProc'] ?? 'SISTEMA-RH-1.0',
                'eventoVersion' => $eventoVersion,
                'serviceVersion' => $this->config['serviceVersion'] ?? '1.5.0',
                'empregador' => [
                    'tpInsc' => $this->config['empregador']['tpInsc'] ?? 1,
                    'nrInsc' => $empregadorNrInsc, // Sempre usar apenas a raiz do CNPJ (8 dígitos)
                    'nmRazao' => $this->config['empregador']['nmRazao'] ?? 'Empresa',
                ],
                'transmissor' => [
                    'tpInsc' => $transmissorTpInsc,
                    'nrInsc' => $certificateCNPJ, // Transmissor usa CNPJ completo do certificado
                ]
            ];

            // Inicializar Tools com a configuração correta
            $this->tools = new Tools(
                json_encode($configArray),
                $certificate
            );
        } catch (\Exception $e) {
            throw new \Exception('Erro ao inicializar ferramentas do eSocial: ' . $e->getMessage());
        }
    }

    /**
     * Monta evento conforme tipo usando Factory
     */
    private function montarEvento($eventoData)
    {
        $tipo = $eventoData['tipo'] ?? '';
        $dados = $eventoData['dados'] ?? [];

        // IMPORTANTE: Formatar CNPJ do empregador para TODOS os eventos
        // REGRA OFICIAL: Para tpInsc = 1 (CNPJ), SEMPRE usar apenas a raiz do CNPJ (8 dígitos)
        // O eSocial identifica o estabelecimento completo pelo evento S-1005, não pelo CNPJ completo
        if (isset($dados['ideEmpregador']['tpInsc']) && $dados['ideEmpregador']['tpInsc'] == 1 && isset($dados['ideEmpregador']['nrInsc'])) {
            $cnpj = preg_replace('/\D/', '', (string)$dados['ideEmpregador']['nrInsc']);
            $cnpjLength = strlen($cnpj);
            
            // Sempre usar apenas a raiz do CNPJ (8 dígitos) para tpInsc = 1
            if ($cnpjLength >= 8) {
                $dados['ideEmpregador']['nrInsc'] = substr($cnpj, 0, 8);
            } else {
                $dados['ideEmpregador']['nrInsc'] = str_pad($cnpj, 8, '0', STR_PAD_LEFT);
            }
            
            error_log("✅ {$tipo}: CNPJ formatado para 8 dígitos (raiz do CNPJ). Original: {$cnpj} ({$cnpjLength} dígitos), Formatado: " . $dados['ideEmpregador']['nrInsc']);
            
            // Garantir que seja string
            $dados['ideEmpregador']['nrInsc'] = (string)$dados['ideEmpregador']['nrInsc'];
        }

        // Debug: log dos dados recebidos
        if ($tipo === 'S-1000') {
            error_log("S-1000: Dados recebidos - " . json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            // Garantir que infocadastro existe
            if (!isset($dados['infocadastro']) || !is_array($dados['infocadastro'])) {
                $dados['infocadastro'] = [];
            }
            
            // Garantir que ideperiodo existe
            if (!isset($dados['ideperiodo']) || !is_array($dados['ideperiodo'])) {
                $dados['ideperiodo'] = [];
            }
            
            // Validar campo obrigatório classtrib (classTrib)
            if (empty($dados['infocadastro']['classtrib'])) {
                throw new \Exception('O campo "classtrib" (classificação tributária) é obrigatório no evento S-1000. Informe um código de 2 dígitos (ex: "01" para Empresa enquadrada no regime tributário Normal, "02" para Empresa enquadrada no regime tributário Simples Nacional, etc.)');
            }
            
            // Garantir que classtrib seja string com 2 dígitos
            $classtrib = trim((string)$dados['infocadastro']['classtrib']);
            if (strlen($classtrib) !== 2 || !preg_match('/^\d{2}$/', $classtrib)) {
                throw new \Exception('O campo "classtrib" deve conter exatamente 2 dígitos numéricos. Valor recebido: "' . $classtrib . '"');
            }
            
            // Garantir que o campo esteja no formato correto
            $dados['infocadastro']['classtrib'] = $classtrib;
            
            // Validar campo obrigatório inivalid (início da validade) - formato AAAA-MM
            // O inivalid deve estar em ideperiodo.inivalid (estrutura correta do eSocial)
            // Mas também verificamos infocadastro.inivalid para compatibilidade com versões antigas
            $inivalid = null;
            
            // Prioridade 1: verificar em ideperiodo.inivalid (correto)
            if (!empty($dados['ideperiodo']['inivalid'])) {
                $inivalid = trim((string)$dados['ideperiodo']['inivalid']);
                error_log("S-1000: inivalid encontrado em ideperiodo.inivalid: {$inivalid}");
            }
            // Prioridade 2: verificar em infocadastro.inivalid (compatibilidade)
            else if (!empty($dados['infocadastro']['inivalid'])) {
                $inivalid = trim((string)$dados['infocadastro']['inivalid']);
                error_log("S-1000: inivalid encontrado em infocadastro.inivalid (migrando para ideperiodo): {$inivalid}");
                // Mover para o local correto
                $dados['ideperiodo']['inivalid'] = $inivalid;
                // Remover do local antigo para evitar confusão
                unset($dados['infocadastro']['inivalid']);
            }
            
            // Se ainda não encontrou, lançar erro
            if (empty($inivalid)) {
                throw new \Exception('O campo "inivalid" (início da validade) é obrigatório no evento S-1000. Informe a data no formato AAAA-MM (ex: "2024-01" para janeiro de 2024) no campo "ideperiodo.inivalid". Este campo define o período inicial de validade das informações do empregador.');
            }
            
            // Validar formato do inivalid (AAAA-MM)
            if (!preg_match('/^\d{4}-\d{2}$/', $inivalid)) {
                throw new \Exception('O campo "inivalid" deve estar no formato AAAA-MM (ex: "2024-01"). Valor recebido: "' . $inivalid . '"');
            }
            
            // Validar se o mês está entre 01 e 12
            $partes = explode('-', $inivalid);
            $ano = (int)$partes[0];
            $mes = (int)$partes[1];
            
            if ($ano < 2010 || $ano > 2100) {
                throw new \Exception('O campo "inivalid" deve ter um ano válido entre 2010 e 2100. Valor recebido: "' . $inivalid . '"');
            }
            
            if ($mes < 1 || $mes > 12) {
                throw new \Exception('O campo "inivalid" deve ter um mês válido entre 01 e 12. Valor recebido: "' . $inivalid . '"');
            }
            
            // IMPORTANTE: Validar se o inivalid não está no futuro
            // O eSocial exige que o inivalid seja anterior ou igual ao mês atual
            $dataAtual = new \DateTime();
            $anoAtual = (int)$dataAtual->format('Y');
            $mesAtual = (int)$dataAtual->format('m');
            
            // Criar data do inivalid para comparação
            $dataInivalid = \DateTime::createFromFormat('Y-m', sprintf('%04d-%02d', $ano, $mes));
            $dataAtualFormatada = \DateTime::createFromFormat('Y-m', sprintf('%04d-%02d', $anoAtual, $mesAtual));
            
            // Verificar se o inivalid está no futuro (mais de 1 mês à frente)
            // Permitir até 1 mês no futuro para casos de cadastramento antecipado
            $dataLimite = clone $dataAtualFormatada;
            $dataLimite->modify('+1 month');
            
            if ($dataInivalid > $dataLimite) {
                error_log("⚠️ AVISO S-1000: inivalid está muito no futuro ({$inivalid}). Isso pode causar problemas com eventos de períodos anteriores.");
            }
            
            // Garantir que o campo esteja no formato correto (com zero à esquerda no mês se necessário)
            $inivalidFormatado = sprintf('%04d-%02d', $ano, $mes);
            $dados['ideperiodo']['inivalid'] = $inivalidFormatado;
            
            error_log("✅ S-1000: inivalid validado e formatado: {$inivalidFormatado} (Ano atual: {$anoAtual}, Mês atual: {$mesAtual})");
            
            // Validar e formatar fimvalid se existir
            if (!empty($dados['ideperiodo']['fimvalid'])) {
                $fimvalid = trim((string)$dados['ideperiodo']['fimvalid']);
                if (preg_match('/^\d{4}-\d{2}$/', $fimvalid)) {
                    $partesFim = explode('-', $fimvalid);
                    $anoFim = (int)$partesFim[0];
                    $mesFim = (int)$partesFim[1];
                    if ($anoFim >= 2010 && $anoFim <= 2100 && $mesFim >= 1 && $mesFim <= 12) {
                        $dados['ideperiodo']['fimvalid'] = sprintf('%04d-%02d', $anoFim, $mesFim);
                    } else {
                        // Se formato inválido, remover
                        unset($dados['ideperiodo']['fimvalid']);
                    }
                } else {
                    // Se formato inválido, remover
                    unset($dados['ideperiodo']['fimvalid']);
                }
            }
            
            // Validar campos opcionais mas importantes
            // inddesfolha / indDesFolha (Indicativo de desoneração da folha) - deve ser inteiro (0, 1 ou 2)
            // Aceitar ambos os formatos de nome (minúsculas e camelCase)
            $inddesfolha = null;
            if (isset($dados['infocadastro']['inddesfolha'])) {
                $inddesfolha = $dados['infocadastro']['inddesfolha'];
            } elseif (isset($dados['infocadastro']['indDesFolha'])) {
                $inddesfolha = $dados['infocadastro']['indDesFolha'];
            }
            
            // Converter para inteiro e validar valores permitidos (0, 1 ou 2)
            if ($inddesfolha === null || $inddesfolha === '') {
                $inddesfolha = 0; // Valor padrão: 0 - Não aplicável
            } else {
                $inddesfolha = (int)$inddesfolha;
                if (!in_array($inddesfolha, [0, 1, 2])) {
                    $inddesfolha = 0; // Se valor inválido, usar padrão
                }
            }
            // Normalizar para minúsculas (formato esperado pelo eSocial)
            $dados['infocadastro']['inddesfolha'] = $inddesfolha;
            // Remover versão camelCase se existir
            if (isset($dados['infocadastro']['indDesFolha'])) {
                unset($dados['infocadastro']['indDesFolha']);
            }
            
            // indoptregeletron / indOptRegEletron (Indicativo de opção pelo registro eletrônico) - deve ser inteiro (0 ou 1)
            // Aceitar ambos os formatos de nome (minúsculas e camelCase)
            $indoptregeletron = null;
            if (isset($dados['infocadastro']['indoptregeletron'])) {
                $indoptregeletron = $dados['infocadastro']['indoptregeletron'];
            } elseif (isset($dados['infocadastro']['indOptRegEletron'])) {
                $indoptregeletron = $dados['infocadastro']['indOptRegEletron'];
            }
            
            // Converter para inteiro e validar valores permitidos (0 ou 1)
            if ($indoptregeletron === null || $indoptregeletron === '') {
                $indoptregeletron = 0; // Valor padrão: 0 - Não optou
            } else {
                $indoptregeletron = (int)$indoptregeletron;
                if (!in_array($indoptregeletron, [0, 1])) {
                    $indoptregeletron = 0; // Se valor inválido, usar padrão
                }
            }
            // Normalizar para minúsculas (formato esperado pelo eSocial)
            $dados['infocadastro']['indoptregeletron'] = $indoptregeletron;
            // Remover versão camelCase se existir
            if (isset($dados['infocadastro']['indOptRegEletron'])) {
                unset($dados['infocadastro']['indOptRegEletron']);
            }
            
            error_log("✅ S-1000: Validações concluídas - classtrib: {$classtrib}, ideperiodo.inivalid: {$inivalidFormatado}, inddesfolha: {$inddesfolha} (tipo: " . gettype($inddesfolha) . "), indoptregeletron: {$indoptregeletron} (tipo: " . gettype($indoptregeletron) . ")");
        }

        // Validações e tratamento para S-1200 (Remuneração)
        if ($tipo === 'S-1200') {
            error_log("S-1200: Dados recebidos - " . json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            // Validar campo obrigatório perapur (período de apuração) - formato AAAA-MM
            if (empty($dados['perapur'])) {
                throw new \Exception('O campo "perapur" (período de apuração) é obrigatório no evento S-1200. Informe a data no formato AAAA-MM (ex: "2024-10" para outubro de 2024).');
            }
            
            // Validar formato do perapur (AAAA-MM)
            $perapur = trim((string)$dados['perapur']);
            if (!preg_match('/^\d{4}-\d{2}$/', $perapur)) {
                throw new \Exception('O campo "perapur" deve estar no formato AAAA-MM (ex: "2024-10"). Valor recebido: "' . $perapur . '"');
            }
            
            // Validar se o mês está entre 01 e 12
            $partes = explode('-', $perapur);
            $ano = (int)$partes[0];
            $mes = (int)$partes[1];
            
            if ($ano < 2010 || $ano > 2100) {
                throw new \Exception('O campo "perapur" deve ter um ano válido entre 2010 e 2100. Valor recebido: "' . $perapur . '"');
            }
            
            if ($mes < 1 || $mes > 12) {
                throw new \Exception('O campo "perapur" deve ter um mês válido entre 01 e 12. Valor recebido: "' . $perapur . '"');
            }
            
            // Garantir que o campo esteja no formato correto
            $dados['perapur'] = sprintf('%04d-%02d', $ano, $mes);
            
            // Validar indretif (indicativo de retificação)
            if (!isset($dados['indretif']) || $dados['indretif'] === null) {
                $dados['indretif'] = 1; // 1 = Original, 2 = Retificação
            }
            
            // Se for retificação, nrrecibo é obrigatório
            if ($dados['indretif'] == 2 && empty($dados['nrrecibo'])) {
                throw new \Exception('O campo "nrrecibo" é obrigatório quando "indretif" é igual a 2 (Retificação).');
            }
            
            // Remover nrrecibo se for original (indretif = 1)
            if ($dados['indretif'] == 1 && isset($dados['nrrecibo'])) {
                unset($dados['nrrecibo']);
            }
            
            // Validar indapuracao (indicativo de apuração)
            if (!isset($dados['indapuracao']) || $dados['indapuracao'] === null) {
                $dados['indapuracao'] = 1; // 1 = Mensal, 2 = Anual (13º salário)
            }
            
            // Validar cpftrab (CPF do trabalhador)
            if (empty($dados['cpftrab'])) {
                throw new \Exception('O campo "cpftrab" (CPF do trabalhador) é obrigatório no evento S-1200.');
            }
            
            // Limpar CPF (remover formatação)
            $cpftrab = preg_replace('/\D/', '', (string)$dados['cpftrab']);
            if (strlen($cpftrab) !== 11) {
                throw new \Exception('O campo "cpftrab" deve conter 11 dígitos. Valor recebido: "' . $dados['cpftrab'] . '"');
            }
            $dados['cpftrab'] = $cpftrab;
            
            // Formatar CNPJ do estabelecimento (nrinsc) se existir
            // No S-1200, o nrinsc está dentro de ideestablot
            // REGRA OFICIAL: Para tpInsc = 1 (CNPJ), SEMPRE usar apenas a raiz do CNPJ (8 dígitos)
            // O eSocial identifica o estabelecimento completo pelo evento S-1005, não pelo CNPJ completo
            if (isset($dados['dmdev']) && is_array($dados['dmdev'])) {
                foreach ($dados['dmdev'] as &$dmdev) {
                    if (isset($dmdev['infoperapur']['ideestablot']) && is_array($dmdev['infoperapur']['ideestablot'])) {
                        foreach ($dmdev['infoperapur']['ideestablot'] as &$establot) {
                            if (isset($establot['tpinsc']) && $establot['tpinsc'] == 1 && isset($establot['nrinsc'])) {
                                $nrinsc = preg_replace('/\D/', '', (string)$establot['nrinsc']);
                                $nrinscLength = strlen($nrinsc);
                                
                                // Sempre usar apenas a raiz do CNPJ (8 dígitos) para tpInsc = 1
                                if ($nrinscLength >= 8) {
                                    $establot['nrinsc'] = substr($nrinsc, 0, 8);
                                    error_log("S-1200: CNPJ do estabelecimento formatado de {$nrinsc} ({$nrinscLength} dígitos) para " . substr($nrinsc, 0, 8) . " (8 dígitos - raiz do CNPJ)");
                                } else {
                                    $establot['nrinsc'] = str_pad($nrinsc, 8, '0', STR_PAD_LEFT);
                                    error_log("S-1200: CNPJ do estabelecimento preenchido com zeros. Original: {$nrinsc} ({$nrinscLength} dígitos), Formatado: {$establot['nrinsc']}");
                                }
                            }
                        }
                    }
                }
            }
            
            // Remover campos null do nível raiz
            $camposNull = ['nrrecibo', 'infomv', 'infocomplem', 'procjudtrab', 'infoperant'];
            foreach ($camposNull as $campo) {
                if (isset($dados[$campo]) && $dados[$campo] === null) {
                    unset($dados[$campo]);
                }
            }
            
            // Remover campos null dentro de dmdev
            if (isset($dados['dmdev']) && is_array($dados['dmdev'])) {
                foreach ($dados['dmdev'] as &$dmdev) {
                    if (isset($dmdev['infoperant']) && $dmdev['infoperant'] === null) {
                        unset($dmdev['infoperant']);
                    }
                    if (isset($dmdev['infoperapur']['ideestablot']) && is_array($dmdev['infoperapur']['ideestablot'])) {
                        foreach ($dmdev['infoperapur']['ideestablot'] as &$establot) {
                            if (isset($establot['remunperapur']) && is_array($establot['remunperapur'])) {
                                foreach ($establot['remunperapur'] as &$remun) {
                                    if (isset($remun['indsimples']) && $remun['indsimples'] === null) {
                                        unset($remun['indsimples']);
                                    }
                                    if (isset($remun['itensremun']) && is_array($remun['itensremun'])) {
                                        foreach ($remun['itensremun'] as &$item) {
                                            if (isset($item['fatorrubr']) && $item['fatorrubr'] === null) {
                                                unset($item['fatorrubr']);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
            
            error_log("✅ S-1200: Validações concluídas - perapur: {$dados['perapur']}, indretif: {$dados['indretif']}, indapuracao: {$dados['indapuracao']}, cpftrab: {$dados['cpftrab']}");
        }

        // Limpar campos opcionais vazios para S-1005
        if ($tipo === 'S-1005') {
            // Função recursiva para remover valores vazios
            $limparVazios = function(&$arr) use (&$limparVazios) {
                if (is_array($arr)) {
                    foreach ($arr as $key => $value) {
                        if ($value === '' || $value === null) {
                            unset($arr[$key]);
                        } elseif (is_array($value)) {
                            $limparVazios($value);
                            if (empty($value)) {
                                unset($arr[$key]);
                            }
                        }
                    }
                }
            };
            
            // Remover campos opcionais do nível raiz
            if (isset($dados['fimvalid']) && ($dados['fimvalid'] === '' || $dados['fimvalid'] === null)) {
                unset($dados['fimvalid']);
            }
            
            if (isset($dados['sequencial']) && ($dados['sequencial'] === '' || $dados['sequencial'] === null)) {
                unset($dados['sequencial']);
            }
            
            // Remover dadosestab se modo for EXC
            if (isset($dados['modo']) && $dados['modo'] === 'EXC') {
                unset($dados['dadosestab']);
            }
            
            // Remover novavalidade se estiver vazia ou se modo não for ALT
            if (isset($dados['novavalidade'])) {
                if (!isset($dados['modo']) || $dados['modo'] !== 'ALT') {
                    unset($dados['novavalidade']);
                } else {
                    if (isset($dados['novavalidade']['inivalid']) && 
                        ($dados['novavalidade']['inivalid'] === '' || $dados['novavalidade']['inivalid'] === null)) {
                        unset($dados['novavalidade']['inivalid']);
                    }
                    if (isset($dados['novavalidade']['fimvalid']) && 
                        ($dados['novavalidade']['fimvalid'] === '' || $dados['novavalidade']['fimvalid'] === null)) {
                        unset($dados['novavalidade']['fimvalid']);
                    }
                    if (empty($dados['novavalidade'])) {
                        unset($dados['novavalidade']);
                    }
                }
            }
            
            // Limpeza final recursiva
            $limparVazios($dados);
        }

        // Limpar campos opcionais vazios para S-1020
        if ($tipo === 'S-1020') {
            // Função recursiva para remover valores vazios
            $limparVazios = function(&$arr) use (&$limparVazios) {
                if (is_array($arr)) {
                    foreach ($arr as $key => $value) {
                        if ($value === '' || $value === null) {
                            unset($arr[$key]);
                        } elseif (is_array($value)) {
                            $limparVazios($value);
                            if (empty($value)) {
                                unset($arr[$key]);
                            }
                        }
                    }
                }
            };
            
            // Remover campos opcionais do nível raiz
            if (isset($dados['fimvalid']) && ($dados['fimvalid'] === '' || $dados['fimvalid'] === null)) {
                unset($dados['fimvalid']);
            }
            
            if (isset($dados['sequencial']) && ($dados['sequencial'] === '' || $dados['sequencial'] === null)) {
                unset($dados['sequencial']);
            }
            
            // Remover dadoslotacao se modo for EXC
            if (isset($dados['modo']) && $dados['modo'] === 'EXC') {
                unset($dados['dadoslotacao']);
            }
            
            // Remover campos opcionais de dadoslotacao se vazios
            if (isset($dados['dadoslotacao']) && is_array($dados['dadoslotacao'])) {
                $camposOpcionais = ['tpinsc', 'nrinsc', 'codtercssusp'];
                foreach ($camposOpcionais as $campo) {
                    if (isset($dados['dadoslotacao'][$campo]) && 
                        ($dados['dadoslotacao'][$campo] === '' || $dados['dadoslotacao'][$campo] === null)) {
                        unset($dados['dadoslotacao'][$campo]);
                    }
                }
                
                // Limpar arrays vazios
                if (isset($dados['dadoslotacao']['procjudterceiro']) && 
                    is_array($dados['dadoslotacao']['procjudterceiro']) && 
                    empty($dados['dadoslotacao']['procjudterceiro'])) {
                    unset($dados['dadoslotacao']['procjudterceiro']);
                }
                
                // Remover objetos vazios
                if (isset($dados['dadoslotacao']['infoemprparcial'])) {
                    $camposEmprParcial = ['tpinsccontrat', 'nrinsccontrat', 'tpinscprop', 'nrinscprop'];
                    $temDados = false;
                    foreach ($camposEmprParcial as $campo) {
                        if (isset($dados['dadoslotacao']['infoemprparcial'][$campo]) && 
                            $dados['dadoslotacao']['infoemprparcial'][$campo] !== '' && 
                            $dados['dadoslotacao']['infoemprparcial'][$campo] !== null) {
                            $temDados = true;
                        } else {
                            unset($dados['dadoslotacao']['infoemprparcial'][$campo]);
                        }
                    }
                    if (!$temDados) {
                        unset($dados['dadoslotacao']['infoemprparcial']);
                    }
                }
                
                if (isset($dados['dadoslotacao']['dadosopport'])) {
                    $camposOpport = ['aliqrat', 'fap'];
                    $temDados = false;
                    foreach ($camposOpport as $campo) {
                        if (isset($dados['dadoslotacao']['dadosopport'][$campo]) && 
                            $dados['dadoslotacao']['dadosopport'][$campo] !== '' && 
                            $dados['dadoslotacao']['dadosopport'][$campo] !== null) {
                            $temDados = true;
                        } else {
                            unset($dados['dadoslotacao']['dadosopport'][$campo]);
                        }
                    }
                    if (!$temDados) {
                        unset($dados['dadoslotacao']['dadosopport']);
                    }
                }
            }
            
            // Remover novavalidade se estiver vazia ou se modo não for ALT
            if (isset($dados['novavalidade'])) {
                if (!isset($dados['modo']) || $dados['modo'] !== 'ALT') {
                    unset($dados['novavalidade']);
                } else {
                    if (isset($dados['novavalidade']['inivalid']) && 
                        ($dados['novavalidade']['inivalid'] === '' || $dados['novavalidade']['inivalid'] === null)) {
                        unset($dados['novavalidade']['inivalid']);
                    }
                    if (isset($dados['novavalidade']['fimvalid']) && 
                        ($dados['novavalidade']['fimvalid'] === '' || $dados['novavalidade']['fimvalid'] === null)) {
                        unset($dados['novavalidade']['fimvalid']);
                    }
                    if (empty($dados['novavalidade'])) {
                        unset($dados['novavalidade']);
                    }
                }
            }
            
            // Limpeza final recursiva
            $limparVazios($dados);
        }

        // Limpar campos opcionais vazios para S-2200
        if ($tipo === 'S-2200') {
            // Função recursiva para remover valores vazios
            $limparVazios = function(&$arr) use (&$limparVazios) {
                if (is_array($arr)) {
                    foreach ($arr as $key => $value) {
                        if ($value === '' || $value === null) {
                            unset($arr[$key]);
                        } elseif (is_array($value)) {
                            $limparVazios($value);
                            if (empty($value)) {
                                unset($arr[$key]);
                            }
                        }
                    }
                }
            };
            
            // Remover campos opcionais do nível raiz
            if (isset($dados['sequencial']) && ($dados['sequencial'] === '' || $dados['sequencial'] === null)) {
                unset($dados['sequencial']);
            }
            
            if (isset($dados['nrrecibo']) && ($dados['nrrecibo'] === '' || $dados['nrrecibo'] === null)) {
                unset($dados['nrrecibo']);
            }
            
            if (isset($dados['estciv']) && ($dados['estciv'] === '' || $dados['estciv'] === null)) {
                unset($dados['estciv']);
            }
            
            if (isset($dados['nmsoc']) && ($dados['nmsoc'] === '' || $dados['nmsoc'] === null)) {
                unset($dados['nmsoc']);
            }
            
            // Limpar endereço
            if (isset($dados['endereco']) && is_array($dados['endereco'])) {
                if (isset($dados['endereco']['brasil']) && is_array($dados['endereco']['brasil'])) {
                    $camposOpcionaisBrasil = ['tplograd', 'complemento', 'bairro'];
                    foreach ($camposOpcionaisBrasil as $campo) {
                        if (isset($dados['endereco']['brasil'][$campo]) && 
                            ($dados['endereco']['brasil'][$campo] === '' || $dados['endereco']['brasil'][$campo] === null)) {
                            unset($dados['endereco']['brasil'][$campo]);
                        }
                    }
                }
                if (isset($dados['endereco']['exterior']) && is_array($dados['endereco']['exterior'])) {
                    $camposOpcionaisExterior = ['complemento', 'bairro', 'codpostal'];
                    foreach ($camposOpcionaisExterior as $campo) {
                        if (isset($dados['endereco']['exterior'][$campo]) && 
                            ($dados['endereco']['exterior'][$campo] === '' || $dados['endereco']['exterior'][$campo] === null)) {
                            unset($dados['endereco']['exterior'][$campo]);
                        }
                    }
                }
            }
            
            // Limpar arrays vazios
            if (isset($dados['dependente']) && is_array($dados['dependente']) && empty($dados['dependente'])) {
                unset($dados['dependente']);
            }
            
            // Limpar objetos opcionais vazios
            $objetosOpcionais = ['trabimig', 'deficiencia', 'contato'];
            foreach ($objetosOpcionais as $objeto) {
                if (isset($dados[$objeto]) && is_array($dados[$objeto]) && empty($dados[$objeto])) {
                    unset($dados[$objeto]);
                }
            }
            
            // Limpar campos opcionais do vínculo
            if (isset($dados['vinculo']) && is_array($dados['vinculo'])) {
                $camposOpcionaisVinculo = ['codcargo', 'codfuncao'];
                foreach ($camposOpcionaisVinculo as $campo) {
                    if (isset($dados['vinculo'][$campo]) && 
                        ($dados['vinculo'][$campo] === '' || $dados['vinculo'][$campo] === null)) {
                        unset($dados['vinculo'][$campo]);
                    }
                }
            }
            
            // Limpeza final recursiva
            $limparVazios($dados);
        }

        // Limpar campos opcionais vazios para S-1010
        if ($tipo === 'S-1010') {
            // Função recursiva para remover valores vazios
            $limparVazios = function(&$arr) use (&$limparVazios) {
                if (is_array($arr)) {
                    foreach ($arr as $key => $value) {
                        if ($value === '' || $value === null) {
                            unset($arr[$key]);
                        } elseif (is_array($value)) {
                            $limparVazios($value);
                            if (empty($value)) {
                                unset($arr[$key]);
                            }
                        }
                    }
                }
            };
            
            // Remover campos opcionais vazios de dadosrubrica
            if (isset($dados['dadosrubrica']) && is_array($dados['dadosrubrica'])) {
                $camposOpcionais = ['codinccprp', 'codincpispasep', 'tetoremun', 'observacao'];
                foreach ($camposOpcionais as $campo) {
                    if (isset($dados['dadosrubrica'][$campo]) && 
                        ($dados['dadosrubrica'][$campo] === '' || $dados['dadosrubrica'][$campo] === null)) {
                        unset($dados['dadosrubrica'][$campo]);
                    }
                }
                
                // Limpar arrays vazios de processos
                $arraysProcessos = ['ideprocessocp', 'ideprocessoirrf', 'ideprocessofgts', 'ideprocessopispasep'];
                foreach ($arraysProcessos as $campo) {
                    if (isset($dados['dadosrubrica'][$campo]) && 
                        is_array($dados['dadosrubrica'][$campo]) && 
                        empty($dados['dadosrubrica'][$campo])) {
                        unset($dados['dadosrubrica'][$campo]);
                    }
                }
            }
            
            // Remover campos opcionais do nível raiz
            if (isset($dados['fimvalid']) && ($dados['fimvalid'] === '' || $dados['fimvalid'] === null)) {
                unset($dados['fimvalid']);
            }
            
            if (isset($dados['sequencial']) && ($dados['sequencial'] === '' || $dados['sequencial'] === null)) {
                unset($dados['sequencial']);
            }
            
            // Remover novavalidade se estiver vazia ou se modo não for ALT
            if (isset($dados['novavalidade'])) {
                if (!isset($dados['modo']) || $dados['modo'] !== 'ALT') {
                    unset($dados['novavalidade']);
                } else {
                    if (isset($dados['novavalidade']['inivalid']) && 
                        ($dados['novavalidade']['inivalid'] === '' || $dados['novavalidade']['inivalid'] === null)) {
                        unset($dados['novavalidade']['inivalid']);
                    }
                    if (isset($dados['novavalidade']['fimvalid']) && 
                        ($dados['novavalidade']['fimvalid'] === '' || $dados['novavalidade']['fimvalid'] === null)) {
                        unset($dados['novavalidade']['fimvalid']);
                    }
                    if (empty($dados['novavalidade'])) {
                        unset($dados['novavalidade']);
                    }
                }
            }
            
            // Limpeza final recursiva
            $limparVazios($dados);
        }

        // Limpar campos opcionais vazios para S-2300
        if ($tipo === 'S-2300') {
            // Função recursiva para remover valores vazios
            $limparVazios = function(&$arr) use (&$limparVazios) {
                if (is_array($arr)) {
                    foreach ($arr as $key => $value) {
                        if ($value === '' || $value === null) {
                            unset($arr[$key]);
                        } elseif (is_array($value)) {
                            $limparVazios($value);
                            if (empty($value)) {
                                unset($arr[$key]);
                            }
                        }
                    }
                }
            };
            
            // Remover campos opcionais do nível raiz
            if (isset($dados['sequencial']) && ($dados['sequencial'] === '' || $dados['sequencial'] === null)) {
                unset($dados['sequencial']);
            }
            
            if (isset($dados['nrrecibo']) && ($dados['nrrecibo'] === '' || $dados['nrrecibo'] === null)) {
                unset($dados['nrrecibo']);
            }
            
            if (isset($dados['estciv']) && ($dados['estciv'] === '' || $dados['estciv'] === null)) {
                unset($dados['estciv']);
            }
            
            if (isset($dados['nmsoc']) && ($dados['nmsoc'] === '' || $dados['nmsoc'] === null)) {
                unset($dados['nmsoc']);
            }
            
            if (isset($dados['matricula']) && ($dados['matricula'] === '' || $dados['matricula'] === null)) {
                unset($dados['matricula']);
            }
            
            // Limpar endereço
            if (isset($dados['endereco']) && is_array($dados['endereco'])) {
                if (isset($dados['endereco']['brasil']) && is_array($dados['endereco']['brasil'])) {
                    $camposOpcionaisBrasil = ['tplograd', 'complemento', 'bairro'];
                    foreach ($camposOpcionaisBrasil as $campo) {
                        if (isset($dados['endereco']['brasil'][$campo]) && 
                            ($dados['endereco']['brasil'][$campo] === '' || $dados['endereco']['brasil'][$campo] === null)) {
                            unset($dados['endereco']['brasil'][$campo]);
                        }
                    }
                }
                if (isset($dados['endereco']['exterior']) && is_array($dados['endereco']['exterior'])) {
                    $camposOpcionaisExterior = ['complemento', 'bairro', 'codpostal'];
                    foreach ($camposOpcionaisExterior as $campo) {
                        if (isset($dados['endereco']['exterior'][$campo]) && 
                            ($dados['endereco']['exterior'][$campo] === '' || $dados['endereco']['exterior'][$campo] === null)) {
                            unset($dados['endereco']['exterior'][$campo]);
                        }
                    }
                }
            }
            
            // Limpar arrays vazios
            if (isset($dados['dependente']) && is_array($dados['dependente']) && empty($dados['dependente'])) {
                unset($dados['dependente']);
            }
            
            // Limpar objetos opcionais vazios
            $objetosOpcionais = ['trabimig', 'infodeficiencia', 'contato'];
            foreach ($objetosOpcionais as $objeto) {
                if (isset($dados[$objeto]) && is_array($dados[$objeto]) && empty($dados[$objeto])) {
                    unset($dados[$objeto]);
                }
            }
            
            // Limpeza final recursiva
            $limparVazios($dados);
        }

        // Limpar campos opcionais vazios para S-2299
        if ($tipo === 'S-2299') {
            // Função recursiva para remover valores vazios
            $limparVazios = function(&$arr) use (&$limparVazios) {
                if (is_array($arr)) {
                    foreach ($arr as $key => $value) {
                        if ($value === '' || $value === null) {
                            unset($arr[$key]);
                        } elseif (is_array($value)) {
                            $limparVazios($value);
                            if (empty($value)) {
                                unset($arr[$key]);
                            }
                        }
                    }
                }
            };
            
            // Remover campos opcionais do nível raiz
            if (isset($dados['sequencial']) && ($dados['sequencial'] === '' || $dados['sequencial'] === null)) {
                unset($dados['sequencial']);
            }
            
            if (isset($dados['nrrecibo']) && ($dados['nrrecibo'] === '' || $dados['nrrecibo'] === null)) {
                unset($dados['nrrecibo']);
            }
            
            if (isset($dados['indguia']) && ($dados['indguia'] === '' || $dados['indguia'] === null)) {
                unset($dados['indguia']);
            }
            
            if (isset($dados['dtavprv']) && ($dados['dtavprv'] === '' || $dados['dtavprv'] === null)) {
                unset($dados['dtavprv']);
            }
            
            if (isset($dados['dtprojfimapi']) && ($dados['dtprojfimapi'] === '' || $dados['dtprojfimapi'] === null)) {
                unset($dados['dtprojfimapi']);
            }
            
            if (isset($dados['pensalim']) && ($dados['pensalim'] === '' || $dados['pensalim'] === null)) {
                unset($dados['pensalim']);
            }
            
            if (isset($dados['percaliment']) && ($dados['percaliment'] === '' || $dados['percaliment'] === null)) {
                unset($dados['percaliment']);
            }
            
            if (isset($dados['vralim']) && ($dados['vralim'] === '' || $dados['vralim'] === null)) {
                unset($dados['vralim']);
            }
            
            if (isset($dados['nrproctrab']) && ($dados['nrproctrab'] === '' || $dados['nrproctrab'] === null)) {
                unset($dados['nrproctrab']);
            }
            
            // Limpar arrays vazios
            if (isset($dados['infoInterm']) && is_array($dados['infoInterm']) && empty($dados['infoInterm'])) {
                unset($dados['infoInterm']);
            }
            
            if (isset($dados['observacoes']) && is_array($dados['observacoes']) && empty($dados['observacoes'])) {
                unset($dados['observacoes']);
            }
            
            if (isset($dados['consigfgts']) && is_array($dados['consigfgts']) && empty($dados['consigfgts'])) {
                unset($dados['consigfgts']);
            }
            
            // Limpar objetos opcionais vazios
            $objetosOpcionais = ['sucessaovinc', 'transftit', 'mudancacpf', 'verbasresc', 'remunaposdeslig'];
            foreach ($objetosOpcionais as $objeto) {
                if (isset($dados[$objeto]) && is_array($dados[$objeto]) && empty($dados[$objeto])) {
                    unset($dados[$objeto]);
                }
            }
            
            // Limpeza final recursiva
            $limparVazios($dados);
        }

        // Log final do CNPJ formatado para S-1000 (já foi formatado no início)
        if ($tipo === 'S-1000' && isset($dados['ideEmpregador']['nrInsc'])) {
            error_log("🔍 S-1000 MONTAR EVENTO (FINAL): CNPJ formatado = '{$dados['ideEmpregador']['nrInsc']}' (tamanho: " . strlen($dados['ideEmpregador']['nrInsc']) . ", tipo: " . gettype($dados['ideEmpregador']['nrInsc']) . ")");
        }

        // Converter dados para stdClass (formato esperado pela biblioteca)
        $std = json_decode(json_encode($dados), false);
        
        // Garantir que o CNPJ seja string após conversão (json_decode pode converter para número)
        // REGRA OFICIAL: Para tpInsc = 1 (CNPJ), SEMPRE usar apenas a raiz do CNPJ (8 dígitos)
        if (isset($std->ideEmpregador->tpInsc) && $std->ideEmpregador->tpInsc == 1 && isset($std->ideEmpregador->nrInsc)) {
            $cnpjFinal = preg_replace('/\D/', '', (string)$std->ideEmpregador->nrInsc);
            $cnpjFinalLength = strlen($cnpjFinal);
            
            // Sempre usar apenas a raiz do CNPJ (8 dígitos) para tpInsc = 1
            if ($cnpjFinalLength >= 8) {
                $cnpjFinal = substr($cnpjFinal, 0, 8);
            } else {
                $cnpjFinal = str_pad($cnpjFinal, 8, '0', STR_PAD_LEFT);
            }
            
            $std->ideEmpregador->nrInsc = $cnpjFinal;
            error_log("🔍 {$tipo} APÓS CONVERSÃO: CNPJ formatado para 8 dígitos (raiz do CNPJ). Original tinha {$cnpjFinalLength} dígitos, Formatado: {$cnpjFinal}");
            
            // Garantir que está correto
            if (strlen($cnpjFinal) !== 8) {
                error_log("⚠️ ERRO {$tipo}: CNPJ tem tamanho incorreto após conversão: " . strlen($cnpjFinal));
            }
        }
        
        // Garantir que inddesfolha e indoptregeletron sejam inteiros após conversão (apenas S-1000)
        if ($tipo === 'S-1000') {
            if (isset($std->infocadastro->inddesfolha)) {
                $std->infocadastro->inddesfolha = (int)$std->infocadastro->inddesfolha;
                error_log("🔍 S-1000 APÓS CONVERSÃO: inddesfolha = {$std->infocadastro->inddesfolha} (tipo: " . gettype($std->infocadastro->inddesfolha) . ")");
            }
            if (isset($std->infocadastro->indoptregeletron)) {
                $std->infocadastro->indoptregeletron = (int)$std->infocadastro->indoptregeletron;
                error_log("🔍 S-1000 APÓS CONVERSÃO: indoptregeletron = {$std->infocadastro->indoptregeletron} (tipo: " . gettype($std->infocadastro->indoptregeletron) . ")");
            }
        }

        // Criar Factory baseado no tipo de evento
        $factory = $this->createEventFactory($tipo, $std);

        return $factory;
    }

    /**
     * Cria Factory do evento baseado no tipo
     */
    private function createEventFactory($tipo, $std)
    {
        // Montar configuração completa
        // A versão do evento deve estar no formato "S.1.3.0" (última versão disponível)
        // Versões disponíveis: S.1.0.0, S.1.1.0, S.1.2.0, S.1.3.0
        $eventoVersion = $this->config['eventoVersion'] ?? 'S.1.3.0';
        
        // Se a versão estiver no formato "2.5.0", converter para "S.1.3.0"
        if (preg_match('/^\d+\.\d+\.\d+$/', $eventoVersion)) {
            $eventoVersion = 'S.1.3.0'; // Usar a versão mais recente disponível
        }
        
        // Garantir que o CNPJ do empregador na configuração seja sempre 8 dígitos (raiz do CNPJ)
        $empregadorNrInsc = $this->config['empregador']['nrInsc'] ?? '';
        $empregadorNrInscOriginal = $empregadorNrInsc;
        if (!empty($empregadorNrInsc) && ($this->config['empregador']['tpInsc'] ?? 1) == 1) {
            $empregadorNrInsc = preg_replace('/\D/', '', (string)$empregadorNrInsc);
            if (strlen($empregadorNrInsc) >= 8) {
                $empregadorNrInsc = substr($empregadorNrInsc, 0, 8);
            } else {
                $empregadorNrInsc = str_pad($empregadorNrInsc, 8, '0', STR_PAD_LEFT);
            }
            error_log("🔧 CREATE FACTORY ({$tipo}): CNPJ do empregador formatado de '{$empregadorNrInscOriginal}' para '{$empregadorNrInsc}' (8 dígitos)");
        }
        
        $config = json_encode([
            'tpAmb' => $this->config['tpAmb'] ?? 2,
            'verProc' => $this->config['verProc'] ?? 'SISTEMA-RH-1.0',
            'eventoVersion' => $eventoVersion,
            'empregador' => [
                'tpInsc' => $this->config['empregador']['tpInsc'] ?? 1,
                'nrInsc' => $empregadorNrInsc, // Sempre usar apenas a raiz do CNPJ (8 dígitos)
                'nmRazao' => $this->config['empregador']['nmRazao'] ?? 'Empresa',
            ]
        ]);

        // Carregar certificado
        $pfxContent = base64_decode($this->config['certificate']['pfx']);
        $certificate = \NFePHP\Common\Certificate::readPfx(
            $pfxContent,
            $this->config['certificate']['password']
        );

        // Criar Factory baseado no tipo de evento
        // O construtor espera: $config, $std (dados), $certificate
        switch ($tipo) {
            case 'S-1200':
                $factory = new \NFePHP\eSocial\Factories\EvtRemun($config, $std, $certificate);
                break;
            case 'S-2200':
                $factory = new \NFePHP\eSocial\Factories\EvtAdmissao($config, $std, $certificate);
                break;
            case 'S-1000':
                $factory = new \NFePHP\eSocial\Factories\EvtInfoEmpregador($config, $std, $certificate);
                break;
            case 'S-1005':
                $factory = new \NFePHP\eSocial\Factories\EvtTabEstab($config, $std, $certificate);
                break;
            case 'S-1010':
                $factory = new \NFePHP\eSocial\Factories\EvtTabRubrica($config, $std, $certificate);
                break;
            case 'S-1020':
                $factory = new \NFePHP\eSocial\Factories\EvtTabLotacao($config, $std, $certificate);
                break;
            case 'S-2299':
                $factory = new \NFePHP\eSocial\Factories\EvtDeslig($config, $std, $certificate);
                break;
            case 'S-2300':
                $factory = new \NFePHP\eSocial\Factories\EvtTSVInicio($config, $std, $certificate);
                break;
            default:
                throw new \Exception("Tipo de evento não suportado: {$tipo}");
        }

        return $factory;
    }

    /**
     * Determina o grupo do evento
     */
    private function getGrupoEvento($tipo)
    {
        // Mapeamento de tipos de evento para grupos
        // Grupo 1: EVENTOS INICIAIS (Tabelas)
        // Grupo 2: EVENTOS NÃO PERIÓDICOS
        // Grupo 3: EVENTOS PERIÓDICOS
        $grupos = [
            'S-1000' => 1, // Grupo 1: Eventos Iniciais (Tabelas)
            'S-1005' => 1,
            'S-1010' => 1,
            'S-1020' => 1,
            'S-1200' => 3, // Grupo 3: Eventos Periódicos (Remuneração mensal)
            'S-2200' => 2, // Grupo 2: Eventos Não Periódicos (Admissão)
            'S-2299' => 2, // Grupo 2: Eventos Não Periódicos (Desligamento)
            'S-2300' => 2, // Grupo 2: Eventos Não Periódicos (TSV Início)
        ];

        if (!isset($grupos[$tipo])) {
            throw new \Exception("Grupo não definido para o evento: {$tipo}");
        }

        return $grupos[$tipo];
    }

    /**
     * Envia resposta de sucesso
     */
    private function sendResponse($data, $statusCode = 200)
    {
        http_response_code($statusCode);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * Envia resposta de erro
     */
    private function sendError($message, $statusCode = 400)
    {
        http_response_code($statusCode);
        echo json_encode([
            'success' => false,
            'error' => $message
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}

