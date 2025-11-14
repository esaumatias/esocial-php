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
            if (($eventoData['tipo'] ?? '') === 'S-1000' && isset($eventoData['dados']['ideEmpregador']['tpInsc']) && $eventoData['dados']['ideEmpregador']['tpInsc'] == 1) {
                $dados = $eventoData['dados'] ?? [];
                if (isset($dados['ideEmpregador']['nrInsc']) && !empty($dados['ideEmpregador']['nrInsc'])) {
                    $cnpj = preg_replace('/\D/', '', (string)$dados['ideEmpregador']['nrInsc']);
                    $classtrib = isset($dados['infocadastro']['classtrib']) ? (string)$dados['infocadastro']['classtrib'] : '';
                    
                    // Códigos de classificação tributária para órgãos públicos
                    $codigosOrgaoPublico = ['21', '22', '23', '24', '25', '26', '27', '28', '29', '30', '31', '32', '33'];
                    $isOrgaoPublico = in_array($classtrib, $codigosOrgaoPublico);
                    
                    $cnpjLength = strlen($cnpj);
                    
                    // Formatar CNPJ para a configuração do Tools também (mesma lógica do evento)
                    // SEMPRE formatar: órgão público precisa ter exatamente 14 dígitos, outros precisam ter exatamente 8
                    if ($isOrgaoPublico) {
                        // Órgão público: garantir que tenha exatamente 14 dígitos
                        if ($cnpjLength == 14) {
                            $cnpjFormatado = $cnpj;
                        } else if ($cnpjLength > 14) {
                            // Se tiver mais de 14, pegar os primeiros 14
                            $cnpjFormatado = substr($cnpj, 0, 14);
                        } else {
                            // Se tiver menos de 14, preencher com zeros à esquerda
                            $cnpjFormatado = str_pad($cnpj, 14, '0', STR_PAD_LEFT);
                        }
                    } else {
                        // Para todos os outros casos (empresas privadas), SEMPRE usar apenas CNPJ base (8 dígitos)
                        // Independente se o CNPJ original tem 14 dígitos ou não
                        $cnpjFormatado = $cnpjLength >= 8 ? substr($cnpj, 0, 8) : str_pad($cnpj, 8, '0', STR_PAD_LEFT);
                    }
                    
                    // Atualizar CNPJ na configuração para usar o mesmo formato do evento
                    $this->config['empregador']['nrInsc'] = $cnpjFormatado;
                    error_log("S-1000: CNPJ da configuração atualizado para: {$cnpjFormatado} (tamanho: " . strlen($cnpjFormatado) . ", é órgão público: " . ($isOrgaoPublico ? 'Sim' : 'Não') . ")");
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
            
            $configArray = [
                'tpAmb' => $this->config['tpAmb'] ?? 2,
                'verProc' => $this->config['verProc'] ?? 'SISTEMA-RH-1.0',
                'eventoVersion' => $eventoVersion,
                'serviceVersion' => $this->config['serviceVersion'] ?? '1.5.0',
                'empregador' => [
                    'tpInsc' => $this->config['empregador']['tpInsc'] ?? 1,
                    'nrInsc' => $this->config['empregador']['nrInsc'] ?? '',
                    'nmRazao' => $this->config['empregador']['nmRazao'] ?? 'Empresa',
                ],
                'transmissor' => [
                    'tpInsc' => $transmissorTpInsc,
                    'nrInsc' => $certificateCNPJ, // Usar CNPJ completo do certificado
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

        // Debug: log dos dados recebidos
        if ($tipo === 'S-1000') {
            error_log("S-1000: Dados recebidos - " . json_encode($dados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            
            // Garantir que infocadastro existe
            if (!isset($dados['infocadastro']) || !is_array($dados['infocadastro'])) {
                $dados['infocadastro'] = [];
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

        // Formatar CNPJ para S-1000: usar apenas CNPJ base (8 dígitos) exceto para órgãos públicos
        if ($tipo === 'S-1000' && isset($dados['ideEmpregador']['tpInsc']) && $dados['ideEmpregador']['tpInsc'] == 1) {
            if (isset($dados['ideEmpregador']['nrInsc']) && !empty($dados['ideEmpregador']['nrInsc'])) {
                $cnpjOriginal = $dados['ideEmpregador']['nrInsc'];
                $cnpj = preg_replace('/\D/', '', (string)$dados['ideEmpregador']['nrInsc']);
                $classtrib = isset($dados['infocadastro']['classtrib']) ? (string)$dados['infocadastro']['classtrib'] : '';
                
                error_log("🔍 S-1000 MONTAR EVENTO: CNPJ recebido - Original: '{$cnpjOriginal}', Limpo: '{$cnpj}' (tamanho: " . strlen($cnpj) . "), Classificação: '{$classtrib}'");
                
                // Códigos de classificação tributária para órgãos públicos (podem usar CNPJ completo)
                $codigosOrgaoPublico = ['21', '22', '23', '24', '25', '26', '27', '28', '29', '30', '31', '32', '33'];
                $isOrgaoPublico = in_array($classtrib, $codigosOrgaoPublico);
                
                $cnpjLength = strlen($cnpj);
                
                // SEMPRE formatar: órgão público precisa ter exatamente 14 dígitos, outros precisam ter exatamente 8
                if ($isOrgaoPublico) {
                    // Órgão público: garantir que tenha exatamente 14 dígitos
                    if ($cnpjLength == 14) {
                        $dados['ideEmpregador']['nrInsc'] = $cnpj;
                        error_log("✅ S-1000: CNPJ mantido completo (14 dígitos) para órgão público. Classificação: {$classtrib}, CNPJ: {$cnpj}");
                    } else if ($cnpjLength > 14) {
                        // Se tiver mais de 14, pegar os primeiros 14
                        $dados['ideEmpregador']['nrInsc'] = substr($cnpj, 0, 14);
                        error_log("✅ S-1000: CNPJ truncado para 14 dígitos (órgão público). Original: {$cnpj} ({$cnpjLength} dígitos), Formatado: " . $dados['ideEmpregador']['nrInsc']);
                    } else {
                        // Se tiver menos de 14, preencher com zeros à esquerda
                        $dados['ideEmpregador']['nrInsc'] = str_pad($cnpj, 14, '0', STR_PAD_LEFT);
                        error_log("✅ S-1000: CNPJ preenchido para 14 dígitos (órgão público). Original: {$cnpj} ({$cnpjLength} dígitos), Formatado: " . $dados['ideEmpregador']['nrInsc']);
                    }
                } else {
                    // Para todos os outros casos (empresas privadas), SEMPRE usar apenas CNPJ base (8 dígitos)
                    // Independente se o CNPJ original tem 14 dígitos ou não
                    if ($cnpjLength >= 8) {
                        $dados['ideEmpregador']['nrInsc'] = substr($cnpj, 0, 8);
                        error_log("✅ S-1000: CNPJ formatado para base (8 dígitos). Original: {$cnpj} ({$cnpjLength} dígitos), Formatado: " . substr($cnpj, 0, 8) . ". Classificação: {$classtrib}, É órgão público: Não");
                    } else {
                        // Se tiver menos de 8 dígitos, preencher com zeros à esquerda
                        $dados['ideEmpregador']['nrInsc'] = str_pad($cnpj, 8, '0', STR_PAD_LEFT);
                        error_log("✅ S-1000: CNPJ preenchido com zeros. Original: {$cnpj} ({$cnpjLength} dígitos), Formatado: " . $dados['ideEmpregador']['nrInsc']);
                    }
                }
                
                // Garantir que seja string (não número) para evitar conversão automática
                $dados['ideEmpregador']['nrInsc'] = (string)$dados['ideEmpregador']['nrInsc'];
                
                // Log final para confirmar
                error_log("🔍 S-1000 MONTAR EVENTO (FINAL): CNPJ formatado = '{$dados['ideEmpregador']['nrInsc']}' (tamanho: " . strlen($dados['ideEmpregador']['nrInsc']) . ", tipo: " . gettype($dados['ideEmpregador']['nrInsc']) . ")");
            } else {
                error_log("⚠️ S-1000: nrInsc não encontrado ou vazio!");
            }
        }

        // Converter dados para stdClass (formato esperado pela biblioteca)
        $std = json_decode(json_encode($dados), false);
        
        // Garantir que o CNPJ seja string após conversão (json_decode pode converter para número)
        if ($tipo === 'S-1000' && isset($std->ideEmpregador->nrInsc)) {
            $cnpjFinal = (string)$std->ideEmpregador->nrInsc;
            $std->ideEmpregador->nrInsc = $cnpjFinal;
            error_log("🔍 S-1000 APÓS CONVERSÃO: CNPJ = '{$cnpjFinal}' (tamanho: " . strlen($cnpjFinal) . ")");
            // Garantir que está correto
            if (strlen($cnpjFinal) !== 8 && strlen($cnpjFinal) !== 14) {
                error_log("⚠️ ERRO: CNPJ tem tamanho incorreto após conversão: " . strlen($cnpjFinal));
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
        
        $config = json_encode([
            'tpAmb' => $this->config['tpAmb'] ?? 2,
            'verProc' => $this->config['verProc'] ?? 'SISTEMA-RH-1.0',
            'eventoVersion' => $eventoVersion,
            'empregador' => [
                'tpInsc' => $this->config['empregador']['tpInsc'] ?? 1,
                'nrInsc' => $this->config['empregador']['nrInsc'] ?? '',
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

