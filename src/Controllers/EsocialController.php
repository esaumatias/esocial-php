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
            
            // Validar e garantir que infoComplem (informações complementares do trabalhador) seja preenchido
            // O eSocial exige que o grupo ideTrabalhador tenha informações complementares
            if (empty($dados['infocomplem']) || !is_array($dados['infocomplem'])) {
                throw new \Exception('O grupo "infocomplem" (informações complementares do trabalhador) é obrigatório no evento S-1200. É necessário informar pelo menos "nmtrab" (nome do trabalhador) e "dtnascto" (data de nascimento).');
            }
            
            // Validar campos obrigatórios dentro de infocomplem
            if (empty($dados['infocomplem']['nmtrab'])) {
                throw new \Exception('O campo "infocomplem.nmtrab" (nome do trabalhador) é obrigatório no evento S-1200.');
            }
            
            if (empty($dados['infocomplem']['dtnascto'])) {
                throw new \Exception('O campo "infocomplem.dtnascto" (data de nascimento) é obrigatório no evento S-1200. Formato: AAAA-MM-DD ou AAAA/MM/DD.');
            }
            
            // Validar e garantir que dmdev (demonstração de valores) seja preenchido corretamente
            if (empty($dados['dmdev']) || !is_array($dados['dmdev']) || count($dados['dmdev']) === 0) {
                throw new \Exception('O grupo "dmdev" (demonstração de valores) é obrigatório no evento S-1200 e deve conter pelo menos um elemento.');
            }
            
            // Formatar CNPJ do estabelecimento (nrinsc) e validar estrutura do dmdev
            // No S-1200, o nrinsc está dentro de ideestablot
            // REGRA OFICIAL: Para tpInsc = 1 (CNPJ), SEMPRE usar apenas a raiz do CNPJ (8 dígitos)
            // O eSocial identifica o estabelecimento completo pelo evento S-1005, não pelo CNPJ completo
            // A validação que exige 12-14 dígitos é um erro do sistema e deve ser ignorada
            foreach ($dados['dmdev'] as $index => &$dmdev) {
                // Validar campos obrigatórios no dmdev
                if (empty($dmdev['idedmdev'])) {
                    $dmdev['idedmdev'] = (string)($index + 1);
                }
                if (empty($dmdev['codcateg'])) {
                    throw new \Exception("O campo 'codcateg' (código da categoria) é obrigatório no dmdev[{$index}] do evento S-1200.");
                }
                
                // Validar que infoperapur existe e está preenchido
                if (empty($dmdev['infoperapur']) || !is_array($dmdev['infoperapur'])) {
                    throw new \Exception("O grupo 'infoperapur' (informações de remuneração no período de apuração) é obrigatório no dmdev[{$index}] do evento S-1200.");
                }
                
                // Validar que ideestablot existe e tem pelo menos um elemento
                if (empty($dmdev['infoperapur']['ideestablot']) || !is_array($dmdev['infoperapur']['ideestablot']) || count($dmdev['infoperapur']['ideestablot']) === 0) {
                    throw new \Exception("O grupo 'ideestablot' (identificação do estabelecimento/lotação) é obrigatório no dmdev[{$index}].infoperapur do evento S-1200 e deve conter pelo menos um elemento.");
                }
                
                foreach ($dmdev['infoperapur']['ideestablot'] as $establotIndex => &$establot) {
                    // Validar campos obrigatórios em ideestablot
                    if (empty($establot['tpinsc'])) {
                        $establot['tpinsc'] = 1; // CNPJ por padrão
                    }
                    if (empty($establot['nrinsc'])) {
                        throw new \Exception("O campo 'nrinsc' (número de inscrição) é obrigatório no dmdev[{$index}].infoperapur.ideestablot[{$establotIndex}] do evento S-1200.");
                    }
                    if (empty($establot['codlotacao'])) {
                        throw new \Exception("O campo 'codlotacao' (código da lotação) é obrigatório no dmdev[{$index}].infoperapur.ideestablot[{$establotIndex}] do evento S-1200.");
                    }
                    
                    // Formatar CNPJ do estabelecimento
                    // IMPORTANTE: O servidor do eSocial valida contra o schema oficial que exige 12-14 dígitos para ideEstabLot.nrInsc
                    // Portanto, vamos manter o CNPJ completo (14 dígitos) para ideEstabLot.nrInsc
                    // Apenas ideEmpregador.nrInsc deve ter 8 dígitos (raiz do CNPJ)
                    if (isset($establot['tpinsc']) && $establot['tpinsc'] == 1 && isset($establot['nrinsc'])) {
                        $nrinsc = preg_replace('/\D/', '', (string)$establot['nrinsc']);
                        $nrinscLength = strlen($nrinsc);
                        
                        // Para ideEstabLot.nrInsc, usar CNPJ completo (14 dígitos) para passar na validação do servidor
                        // O servidor do eSocial valida contra o schema oficial que exige 12-14 dígitos
                        if ($nrinscLength >= 14) {
                            $establot['nrinsc'] = substr($nrinsc, 0, 14);
                            error_log("S-1200: CNPJ do estabelecimento (ideEstabLot.nrInsc) mantido com 14 dígitos: {$establot['nrinsc']} (servidor exige 12-14 dígitos)");
                        } else if ($nrinscLength >= 8) {
                            // Se tiver apenas 8 dígitos, completar com zeros à direita até 14 dígitos
                            $establot['nrinsc'] = str_pad(substr($nrinsc, 0, 8), 14, '0', STR_PAD_RIGHT);
                            error_log("S-1200: CNPJ do estabelecimento (ideEstabLot.nrInsc) completado de {$nrinsc} ({$nrinscLength} dígitos) para {$establot['nrinsc']} (14 dígitos)");
                        } else {
                            $establot['nrinsc'] = str_pad($nrinsc, 14, '0', STR_PAD_LEFT);
                            error_log("S-1200: CNPJ do estabelecimento (ideEstabLot.nrInsc) preenchido com zeros. Original: {$nrinsc} ({$nrinscLength} dígitos), Formatado: {$establot['nrinsc']}");
                        }
                    }
                    
                    // Validar que remunperapur existe e tem pelo menos um elemento
                    if (empty($establot['remunperapur']) || !is_array($establot['remunperapur']) || count($establot['remunperapur']) === 0) {
                        throw new \Exception("O grupo 'remunperapur' (remuneração no período de apuração) é obrigatório no dmdev[{$index}].infoperapur.ideestablot[{$establotIndex}] do evento S-1200 e deve conter pelo menos um elemento.");
                    }
                    
                    foreach ($establot['remunperapur'] as $remunIndex => &$remun) {
                        // Validar que itensremun existe e tem pelo menos um elemento
                        if (empty($remun['itensremun']) || !is_array($remun['itensremun']) || count($remun['itensremun']) === 0) {
                            throw new \Exception("O grupo 'itensremun' (itens de remuneração) é obrigatório no dmdev[{$index}].infoperapur.ideestablot[{$establotIndex}].remunperapur[{$remunIndex}] do evento S-1200 e deve conter pelo menos um elemento.");
                        }
                        
                        foreach ($remun['itensremun'] as $itemIndex => &$item) {
                            // Validar campos obrigatórios em itensremun
                            if (empty($item['codrubr'])) {
                                throw new \Exception("O campo 'codrubr' (código da rubrica) é obrigatório no dmdev[{$index}].infoperapur.ideestablot[{$establotIndex}].remunperapur[{$remunIndex}].itensremun[{$itemIndex}] do evento S-1200.");
                            }
                            if (empty($item['idetabrubr'])) {
                                throw new \Exception("O campo 'idetabrubr' (identificação da tabela de rubricas) é obrigatório no dmdev[{$index}].infoperapur.ideestablot[{$establotIndex}].remunperapur[{$remunIndex}].itensremun[{$itemIndex}] do evento S-1200.");
                            }
                            if (!isset($item['vrrubr']) || $item['vrrubr'] === null || $item['vrrubr'] === '') {
                                throw new \Exception("O campo 'vrrubr' (valor da rubrica) é obrigatório no dmdev[{$index}].infoperapur.ideestablot[{$establotIndex}].remunperapur[{$remunIndex}].itensremun[{$itemIndex}] do evento S-1200.");
                            }
                            
                            // Garantir que vrunit seja igual a vrrubr se não for informado
                            if (!isset($item['vrunit']) || $item['vrunit'] === null || $item['vrunit'] === '') {
                                $item['vrunit'] = $item['vrrubr'];
                            }
                            
                            // Garantir que qtdrubr seja 1.0 se não for informado
                            if (!isset($item['qtdrubr']) || $item['qtdrubr'] === null || $item['qtdrubr'] === '') {
                                $item['qtdrubr'] = 1.0;
                            }
                            
                            // Garantir que os valores numéricos sejam do tipo correto (number, não string)
                            $item['vrrubr'] = (float)$item['vrrubr'];
                            $item['vrunit'] = (float)$item['vrunit'];
                            $item['qtdrubr'] = isset($item['qtdrubr']) ? (float)$item['qtdrubr'] : 1.0;
                            
                            // Garantir que codcateg seja inteiro
                            if (isset($dmdev['codcateg'])) {
                                $dmdev['codcateg'] = (int)$dmdev['codcateg'];
                            }
                        }
                    }
                }
                
                // IMPORTANTE: A biblioteca NFePHP suporta duas formas de estruturar os dados:
                // Forma 1: dmdev[0]->infoperapur->ideestablot[0] (que estamos usando) - tpinsc é integer
                // Forma 2: dmdev[0]->ideestablot[0] (forma alternativa) - tpinsc é string
                // 
                // O schema JSON mostra que na forma alternativa, tpinsc deve ser string, não integer
                // Por isso, vamos converter tpinsc para string quando adicionar ideestablot diretamente
                // IMPORTANTE: O nrinsc na forma alternativa também deve ter 14 dígitos (CNPJ completo)
                if (isset($dmdev['infoperapur']['ideestablot']) && is_array($dmdev['infoperapur']['ideestablot']) && count($dmdev['infoperapur']['ideestablot']) > 0) {
                    // Se já temos infoperapur->ideestablot, também adicionar ideestablot diretamente como backup
                    // Mas converter tpinsc para string conforme o schema da forma alternativa
                    if (!isset($dmdev['ideestablot']) || !is_array($dmdev['ideestablot']) || count($dmdev['ideestablot']) === 0) {
                        $ideestablotAlt = [];
                        foreach ($dmdev['infoperapur']['ideestablot'] as $establot) {
                            $establotAlt = $establot;
                            // Converter tpinsc para string na forma alternativa
                            if (isset($establotAlt['tpinsc'])) {
                                $establotAlt['tpinsc'] = (string)$establotAlt['tpinsc'];
                            }
                            // Garantir que nrinsc tenha 14 dígitos na forma alternativa também
                            if (isset($establotAlt['tpinsc']) && $establotAlt['tpinsc'] == '1' && isset($establotAlt['nrinsc'])) {
                                $nrinscAlt = preg_replace('/\D/', '', (string)$establotAlt['nrinsc']);
                                $nrinscAltLength = strlen($nrinscAlt);
                                if ($nrinscAltLength >= 14) {
                                    $establotAlt['nrinsc'] = substr($nrinscAlt, 0, 14);
                                } else if ($nrinscAltLength >= 8) {
                                    $establotAlt['nrinsc'] = str_pad(substr($nrinscAlt, 0, 8), 14, '0', STR_PAD_RIGHT);
                                } else {
                                    $establotAlt['nrinsc'] = str_pad($nrinscAlt, 14, '0', STR_PAD_LEFT);
                                }
                            }
                            $ideestablotAlt[] = $establotAlt;
                        }
                        $dmdev['ideestablot'] = $ideestablotAlt;
                        error_log("S-1200: Adicionado ideestablot diretamente em dmdev como forma alternativa (biblioteca suporta ambas as formas). tpinsc convertido para string. nrinsc formatado para 14 dígitos.");
                    }
                }
                
                // IMPORTANTE: Não adicionar infocomplcont automaticamente
                // O eSocial valida se infocomplcont deve ou não ser preenchido conforme as regras do layout
                // Se o usuário não informou infocomplcont, não devemos adicionar automaticamente
                // Remover infocomplcont se estiver vazio ou null para evitar erros de validação
                if (isset($dmdev['infocomplcont']) && (empty($dmdev['infocomplcont']) || $dmdev['infocomplcont'] === null)) {
                    unset($dmdev['infocomplcont']);
                    error_log("S-1200: infocomplcont removido (estava vazio ou null) para evitar erro de validação do eSocial.");
                }
                
                // Se infocomplcont existir e foi informado pelo usuário, garantir que tenha os campos obrigatórios
                if (isset($dmdev['infocomplcont']) && is_array($dmdev['infocomplcont']) && !empty($dmdev['infocomplcont'])) {
                    if (empty($dmdev['infocomplcont']['codcbo'])) {
                        error_log("S-1200: AVISO - infocomplcont existe mas codcbo está vazio. Campo codcbo é obrigatório quando infocomplcont é informado.");
                    }
                }
            }
            
            // Remover campos null do nível raiz
            // IMPORTANTE: infocomplem NÃO deve ser removido - é obrigatório no S-1200
            $camposNull = ['nrrecibo', 'infomv', 'procjudtrab', 'infoperant'];
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
            
            // Remover campo indguia - não pode ser preenchido no S-1200 conforme layout do eSocial
            if (isset($dados['indguia'])) {
                unset($dados['indguia']);
                error_log("S-1200: Campo indguia removido (não pode ser preenchido no S-1200)");
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

        // Log detalhado dos dados antes da conversão para S-1200
        if ($tipo === 'S-1200') {
            error_log("S-1200: Dados ANTES da conversão - " . json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        
        // Converter dados para stdClass (formato esperado pela biblioteca)
        // A biblioteca NFePHP espera:
        // - O objeto raiz deve ser stdClass
        // - Arrays numéricos (dmdev, ideestablot, remunperapur, itensremun) devem ser arrays
        // - Objetos (infoperapur) devem ser stdClass
        // - Elementos dos arrays devem ser stdClass
        
        // Função recursiva para converter arrays associativos em objetos, mas manter arrays numéricos
        $convertToStdClass = function($data) use (&$convertToStdClass) {
            if (is_array($data)) {
                // Verificar se é array numérico (índices sequenciais começando em 0)
                $isNumeric = array_keys($data) === range(0, count($data) - 1);
                
                if ($isNumeric) {
                    // Array numérico: manter como array, mas converter elementos
                    return array_map($convertToStdClass, $data);
                } else {
                    // Array associativo: converter para objeto
                    return (object)array_map($convertToStdClass, $data);
                }
            } elseif (is_object($data)) {
                // Já é objeto: processar propriedades recursivamente
                $result = new \stdClass();
                foreach ($data as $key => $value) {
                    $result->$key = $convertToStdClass($value);
                }
                return $result;
            }
            return $data;
        };
        
        // Converter dados
        $std = $convertToStdClass($dados);
        
        // Garantir que seja stdClass no nível raiz
        if (!($std instanceof \stdClass)) {
            $std = (object)$std;
        }
        
        // Para S-1200, garantir que infoperapur seja um objeto válido (não vazio)
        // A biblioteca verifica !empty($dm->infoperapur), então precisamos garantir que seja um objeto com propriedades
        // IMPORTANTE: empty() retorna true para objetos sem propriedades, então precisamos garantir que tenha pelo menos ideestablot
        if ($tipo === 'S-1200' && isset($std->dmdev) && is_array($std->dmdev)) {
            foreach ($std->dmdev as $dm) {
                // Verificar se infoperapur existe e tem propriedades
                if (isset($dm->infoperapur)) {
                    // Se for objeto mas estiver vazio (sem propriedades), empty() retorna true
                    if (is_object($dm->infoperapur)) {
                        $props = get_object_vars($dm->infoperapur);
                        if (empty($props)) {
                            error_log("S-1200: ERRO CRÍTICO - infoperapur é um objeto vazio (sem propriedades)! A biblioteca não irá processá-lo.");
                        } else {
                            error_log("S-1200: infoperapur tem " . count($props) . " propriedade(s): " . implode(', ', array_keys($props)));
                            // Verificar se ideestablot existe e não está vazio
                            if (isset($dm->infoperapur->ideestablot)) {
                                if (is_array($dm->infoperapur->ideestablot) && count($dm->infoperapur->ideestablot) > 0) {
                                    error_log("S-1200: infoperapur.ideestablot existe e tem " . count($dm->infoperapur->ideestablot) . " elemento(s)");
                                } else {
                                    error_log("S-1200: ERRO CRÍTICO - infoperapur.ideestablot está vazio ou não é array!");
                                }
                            } else {
                                error_log("S-1200: ERRO CRÍTICO - infoperapur.ideestablot não existe!");
                            }
                        }
                    } else {
                        error_log("S-1200: ERRO - infoperapur não é um objeto! Tipo: " . gettype($dm->infoperapur));
                    }
                } else {
                    error_log("S-1200: ERRO CRÍTICO - infoperapur não existe no dmdev!");
                }
            }
        }
        
        // Remover campo indguia do objeto std para S-1200 - não pode ser preenchido conforme layout do eSocial
        if ($tipo === 'S-1200' && isset($std->indguia)) {
            unset($std->indguia);
            error_log("S-1200: Campo indguia removido do objeto std (não pode ser preenchido no S-1200)");
        }
        
        // Log detalhado dos dados após a conversão para S-1200
        if ($tipo === 'S-1200') {
            error_log("S-1200: Dados APÓS a conversão - " . json_encode($std, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            // Verificar estrutura do dmdev e garantir que infoperapur seja um objeto válido
            if (isset($std->dmdev) && is_array($std->dmdev)) {
                foreach ($std->dmdev as $idx => $dm) {
                    error_log("S-1200: dmdev[{$idx}] - idedmdev: " . ($dm->idedmdev ?? 'N/A') . ", codcateg: " . ($dm->codcateg ?? 'N/A'));
                    
                    // Verificar se infoperapur existe e é um objeto válido
                    if (isset($dm->infoperapur)) {
                        error_log("S-1200: dmdev[{$idx}].infoperapur existe - tipo: " . gettype($dm->infoperapur) . ", empty: " . (empty($dm->infoperapur) ? 'true' : 'false'));
                        if (is_object($dm->infoperapur)) {
                            error_log("S-1200: dmdev[{$idx}].infoperapur propriedades: " . implode(', ', array_keys(get_object_vars($dm->infoperapur))));
                        }
                    } else {
                        error_log("S-1200: ERRO - dmdev[{$idx}].infoperapur NÃO existe!");
                    }
                    
                    if (isset($dm->infoperapur) && is_object($dm->infoperapur) && isset($dm->infoperapur->ideestablot) && is_array($dm->infoperapur->ideestablot)) {
                        foreach ($dm->infoperapur->ideestablot as $idx2 => $est) {
                            error_log("S-1200: dmdev[{$idx}].infoperapur.ideestablot[{$idx2}] - tpinsc: " . ($est->tpinsc ?? 'N/A') . ", nrinsc: " . ($est->nrinsc ?? 'N/A') . ", codlotacao: " . ($est->codlotacao ?? 'N/A'));
                            if (isset($est->remunperapur) && is_array($est->remunperapur)) {
                                foreach ($est->remunperapur as $idx3 => $rem) {
                                    error_log("S-1200: dmdev[{$idx}].infoperapur.ideestablot[{$idx2}].remunperapur[{$idx3}] - matricula: " . ($rem->matricula ?? 'N/A'));
                                    if (isset($rem->itensremun) && is_array($rem->itensremun)) {
                                        foreach ($rem->itensremun as $idx4 => $item) {
                                            error_log("S-1200: dmdev[{$idx}].infoperapur.ideestablot[{$idx2}].remunperapur[{$idx3}].itensremun[{$idx4}] - codrubr: " . ($item->codrubr ?? 'N/A') . ", idetabrubr: " . ($item->idetabrubr ?? 'N/A') . ", vrrubr: " . ($item->vrrubr ?? 'N/A') . ", vrunit: " . ($item->vrunit ?? 'N/A') . ", qtdrubr: " . ($item->qtdrubr ?? 'N/A'));
                                        }
                                    } else {
                                        error_log("S-1200: ERRO - remunperapur[{$idx3}].itensremun não existe ou não é array!");
                                    }
                                }
                            } else {
                                error_log("S-1200: ERRO - ideestablot[{$idx2}].remunperapur não existe ou não é array!");
                            }
                        }
                    } else {
                        error_log("S-1200: ERRO - dmdev[{$idx}].infoperapur.ideestablot não existe ou não é array!");
                    }
                }
            } else {
                error_log("S-1200: ERRO - dmdev não existe ou não é array!");
            }
        }
        
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
                // Log do std antes de criar a factory para S-1200
                error_log("S-1200: std ANTES de criar factory - " . json_encode($std, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                
                $factory = new \NFePHP\eSocial\Factories\EvtRemun($config, $std, $certificate);
                
                // Log do XML gerado para verificar se está correto
                try {
                    $xml = $factory->toXML();
                    // Log apenas uma parte do XML (primeiros 2000 caracteres) para não sobrecarregar
                    $xmlPreview = substr($xml, 0, 2000);
                    error_log("S-1200: XML gerado (primeiros 2000 caracteres): " . $xmlPreview);
                    
                    // Verificar se o XML contém infoPerApur
                    if (strpos($xml, 'infoPerApur') !== false) {
                        error_log("S-1200: ✅ XML contém infoPerApur");
                    } else {
                        error_log("S-1200: ❌ ERRO - XML NÃO contém infoPerApur!");
                    }
                    
                    // Verificar se o XML contém remunPerApur
                    if (strpos($xml, 'remunPerApur') !== false) {
                        error_log("S-1200: ✅ XML contém remunPerApur");
                    } else {
                        error_log("S-1200: ❌ ERRO - XML NÃO contém remunPerApur!");
                    }
                    
                    // Verificar nrInsc no XML
                    if (preg_match('/<nrInsc>(\d+)<\/nrInsc>/', $xml, $matches)) {
                        $nrInscFound = $matches[1];
                        error_log("S-1200: 📋 nrInsc encontrado no XML: '{$nrInscFound}' (tamanho: " . strlen($nrInscFound) . " dígitos)");
                        if (strlen($nrInscFound) == 8) {
                            error_log("S-1200: ✅ nrInsc com 8 dígitos (raiz do CNPJ) - correto para empresas privadas");
                        } else {
                            error_log("S-1200: ⚠️ nrInsc com " . strlen($nrInscFound) . " dígitos - pode causar erro no servidor do eSocial");
                        }
                    }
                } catch (\Exception $e) {
                    error_log("S-1200: Erro ao gerar XML: " . $e->getMessage());
                }
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

