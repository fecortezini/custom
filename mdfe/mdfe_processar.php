<?php
/**
 * Processamento de Emissão de MDF-e
 * Este arquivo recebe os dados do formulário e gera o XML do MDF-e
 */

// Captura QUALQUER output anterior (warnings, BOM, HTML do Dolibarr via include)
// para garantir que o header JSON seja enviado limpo
ob_start();

error_reporting(E_ALL);
ini_set('display_errors', 'Off');

// Verificar se é uma requisição AJAX ANTES de qualquer output
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

// Função para retornar erro como JSON (para AJAX) - definida antes de carregar Dolibarr
function retornarErroJson($mensagem, $detalhes = []) {
    // Descarta qualquer output anterior (warnings, HTML do Dolibarr, etc.)
    if (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'error' => $mensagem,
        'details' => $detalhes
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Função para retornar sucesso como JSON (para AJAX)
function retornarSucessoJson($dados) {
    // Descarta qualquer output anterior (warnings, HTML do Dolibarr, etc.)
    if (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => true], $dados), JSON_UNESCAPED_UNICODE);
    exit;
}

// Função para formatar resposta completa para debug
function formatarRespostaCompleta($response) {
    if (is_object($response) || is_array($response)) {
        return json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
    return (string) $response;
}

/**
 * Mapeamento de tags XML do MDF-e para nomes legíveis pelo usuário.
 * Usado para traduzir mensagens de erro técnicas antes de exibi-las ao cliente.
 */
function mdfe_tags_labels() {
    return [
        // Identificação
        'ide'                  => 'Identificação do MDF-e',
        'cUF'                  => 'Código da UF',
        'tpAmb'                => 'Tipo de Ambiente',
        'tpEmit'               => 'Tipo do Emitente',
        'tpTransp'             => 'Tipo do Transportador',
        'mod'                  => 'Modelo',
        'serie'                => 'Série',
        'nMDF'                 => 'Número do MDF-e',
        'cMDF'                 => 'Código Numérico',
        'cDV'                  => 'Dígito Verificador',
        'modal'                => 'Modal',
        'dhEmi'                => 'Data/Hora de Emissão',
        'dhIniViagem'          => 'Data/Hora de Início da Viagem',
        'UFIni'                => 'UF de Início',
        'UFFim'                => 'UF de Fim',
        'indCarga'             => 'Indicador de Carga',
        'indCarregaPosterior'  => 'Indicador de Carregamento Posterior',
        'infPercurso'          => 'UF de Percurso',
        // Emitente
        'emit'                 => 'Emitente',
        'enderEmit'            => 'Endereço do Emitente',
        'CNPJ'                 => 'CNPJ',
        'CPF'                  => 'CPF',
        'IE'                   => 'Inscrição Estadual',
        'xNome'                => 'Razão Social',
        'xFant'                => 'Nome Fantasia',
        'xLgr'                 => 'Logradouro',
        'nro'                  => 'Número',
        'xCpl'                 => 'Complemento',
        'xBairro'              => 'Bairro',
        'cMun'                 => 'Código do Município',
        'xMun'                 => 'Nome do Município',
        'CEP'                  => 'CEP',
        'UF'                   => 'UF',
        'fone'                 => 'Telefone',
        'email'                => 'E-mail',
        // Modal rodoviário
        'infModal'             => 'Informações do Modal',
        'veicRodo'             => 'Modal Rodoviário',
        'veicTracao'           => 'Veículo de Tração',
        'placa'                => 'Placa',
        'RENAVAM'              => 'RENAVAM',
        'tara'                 => 'Tara',
        'capKG'                => 'Capacidade em KG',
        'capM3'                => 'Capacidade em M³',
        'tpRod'                => 'Tipo de Rodado',
        'tpCar'                => 'Tipo de Carroceria',
        'RNTRC'                => 'RNTRC',
        'xProp'                => 'Nome/Razão Social do Proprietário',
        'CPFCNPJ'              => 'CPF/CNPJ do Proprietário',
        'tpProp'               => 'Tipo de Proprietário',
        'condutor'             => 'Condutor',
        'veicReboque'          => 'Veículo Reboque',
        'nLacre'               => 'Número do Lacre',
        'lota'                 => 'Informações de Lotação',
        'cIOT'                 => 'Código CIOT',
        'valePed'              => 'Vale-Pedágio',
        'CNPJForn'             => 'CNPJ do Fornecedor do Vale-Pedágio',
        'CNPJPg'               => 'CNPJ Pagador do Vale-Pedágio',
        'CPFPg'                => 'CPF Pagador do Vale-Pedágio',
        'nCompra'              => 'Número do Compra do Vale-Pedágio',
        'vValePed'             => 'Valor do Vale-Pedágio',
        'contratante'          => 'Contratante',
        // Produto predominante
        'prodPred'             => 'Produto Predominante',
        'xProd'                => 'Descrição do Produto',
        'cEAN'                 => 'Código de Barras EAN',
        'NCM'                  => 'NCM',
        'cProd'                => 'Código do Produto',
        // Totais
        'tot'                  => 'Totais',
        'qCTe'                 => 'Quantidade de CT-e',
        'qNFe'                 => 'Quantidade de NF-e',
        'qMDFe'                => 'Quantidade de MDF-e',
        'vCarga'               => 'Valor Total da Carga',
        'cUnid'                => 'Unidade de Medida da Carga',
        'qCarga'               => 'Quantidade Total da Carga',
        // Documentos fiscais
        'infDoc'               => 'Documentos Fiscais',
        'infMunDescarga'       => 'Município de Descarga',
        'cMunDescarga'         => 'Código do Município de Descarga',
        'xMunDescarga'         => 'Nome do Município de Descarga',
        'infCTe'               => 'CT-e',
        'infNFe'               => 'NF-e',
        'chCTe'                => 'Chave do CT-e',
        'chNFe'                => 'Chave da NF-e',
        'SegCodBarra'          => 'Segundo Código de Barras',
        'infMunCarrega'        => 'Município de Carregamento',
        'cMunCarrega'          => 'Código do Município de Carregamento',
        'xMunCarrega'          => 'Nome do Município de Carregamento',
        // Seguro
        'seg'                  => 'Seguro',
        'respSeg'              => 'Responsável pelo Seguro',
        'nApol'                => 'Número da Apólice',
        'nAver'                => 'Número da Averbação',
        // Lacres
        'lacres'               => 'Lacres',
        // Autorizados
        'autXML'               => 'Autorizado para Download do XML',
        // Informações adicionais
        'infAdic'              => 'Informações Adicionais',
        'infAdFisco'           => 'Informações Adicionais ao Fisco',
        'infCpl'               => 'Informações Complementares',
        // Pagamento
        'infPag'               => 'Informações de Pagamento',
        'Comp'                 => 'Componente do Pagamento',
        'tpComp'               => 'Tipo do Componente de Pagamento',
        'vComp'                => 'Valor do Componente de Pagamento',
        'codBanco'             => 'Código do Banco',
        'codAgencia'           => 'Código da Agência',
        'CNPJIPEF'             => 'CNPJ da IPEF',
        'indPag'               => 'Indicador do Pagamento',
        'vContrato'            => 'Valor do Contrato',
        'indAntecipaFrete'     => 'Indicador de Antecipação de Frete',
        'Parcela'              => 'Parcela',
        'dVenc'                => 'Data de Vencimento da Parcela',
        'vParcela'             => 'Valor da Parcela',
        'idEstrangeiro'        => 'Identificador do Estrangeiro',
    ];
}

/**
 * Traduz mensagens de erro contendo nomes de tags XML para labels legíveis.
 * Padrão 1 da biblioteca: "Tag {tagName} ..."
 * Padrão 2 da biblioteca: "Preenchimento Obrigatório! [tagName] descrição..."
 */
function mdfe_traduzir_erros(array $erros) {
    $labels = mdfe_tags_labels();
    $traduzidos = [];
    foreach ($erros as $erro) {
        // Padrão 1: "Tag tagName" → "Campo \"Label\""
        $traduzido = preg_replace_callback(
            '/\bTag\s+([A-Za-z][A-Za-z0-9_]*)\b/',
            function($m) use ($labels) {
                $tag   = $m[1];
                $label = $labels[$tag] ?? null;
                return $label ? 'Campo "' . $label . '"' : 'Tag ' . $tag;
            },
            $erro
        );
        // Padrão 2: "[tagName] " → remove o colchete (a descrição que segue já é legível)
        $traduzido = preg_replace('/\[[A-Za-z][A-Za-z0-9_]*\]\s*/', '', $traduzido);
        // Substituições de mensagens específicas por texto mais amigável
        $substituicoes = [
            'Tipo da Carga'                     => 'É necessário informar o tipo de carga.',
            'prodpred'                          => 'É necessário informações sobre o produto predominante.',
            'Descrição do produto predominante' => 'É necessário informações sobre o produto predominante.',
        ];
        foreach ($substituicoes as $contem => $amigavel) {
            if (mb_stripos($traduzido, $contem) !== false) {
                $traduzido = $amigavel;
                break;
            }
        }
        $traduzidos[] = trim($traduzido);
    }
    return $traduzidos;
}

// Carregar Dolibarr (necessário para GETPOST e llxHeader/llxFooter)
require_once '../../main.inc.php';

// Incluir biblioteca do MDF-e
require_once __DIR__ . '/../composerlib/vendor/autoload.php';
require_once DOL_DOCUMENT_ROOT.'/custom/labapp/lib/ibge_utils.php';
require_once DOL_DOCUMENT_ROOT.'/custom/mdfe/lib/certificate_security.lib.php';

use NFePHP\MDFe\Make;
use NFePHP\Common\Certificate;
use NFePHP\MDFe\Tools;
use NFePHP\MDFe\Common\Standardize;

function carregarCertificadoA1Nacional($db) {
    $certPfx = null;
    $certPass = null;

    // Tenta tabela key/value
    $tableKv = (defined('MAIN_DB_PREFIX') ? MAIN_DB_PREFIX : '') . 'nfe_config';
    $res = @$db->query("SELECT name, value FROM `".$tableKv."`");
    if ($res) {
        while ($row = $db->fetch_object($res)) {
            if ($row->name === 'cert_pfx') $certPfx = $row->value;
            if ($row->name === 'cert_pass') $certPass = $row->value;
        }
    }

    // Fallback para tabela com colunas diretas
    if (empty($certPfx)) {
        $tableDirect = MAIN_DB_PREFIX . 'nfe_config';
        $res2 = @$db->query("SELECT cert_pfx, cert_pass FROM `".$tableDirect."` LIMIT 1");
        if ($res2 && $obj = $db->fetch_object($res2)) {
            $certPfx = $obj->cert_pfx;
            $certPass = $obj->cert_pass;
        }
    }

    // Normaliza BLOB/stream
    if (is_resource($certPfx)) {
        $certPfx = stream_get_contents($certPfx);
    }
    
    if ($certPfx === null || $certPfx === '') {
        throw new Exception('Certificado PFX não encontrado no banco de dados.');
    }
    
    $certPass = (string)$certPass;
    //$criptografada = nfseEncryptPassword($certPass, $db);
    $pass = decryptPassword($certPass, $db);
    
    // Retorna certificado usando a biblioteca NFePHP
    try {
        $cert = \NFePHP\Common\Certificate::readPfx($certPfx, $pass);
        return $cert;
    } catch (Exception $e) {
        // Tenta decodificar base64 se falhar
        $certPfxDecoded = base64_decode($certPfx, true);
        if ($certPfxDecoded !== false) {
            $cert = \NFePHP\Common\Certificate::readPfx($certPfxDecoded, $certPass);
            return $cert;
        }
        throw new Exception('Erro ao ler certificado: ' . $e->getMessage());
    }
}

// Função auxiliar para limpar strings
function limparNumero($valor) {
    return preg_replace('/[^0-9]/', '', $valor);
}

// Função auxiliar para formatar valor monetário
function formatarValor($valor) {
    // Remove separadores de milhar (ponto no formato BR), substitui vírgula decimal por ponto
    $valor = preg_replace('/\.(?=\d{3}[,.]|\d{3}$)/', '', (string)$valor);
    $valor = str_replace(',', '.', $valor);
    return number_format(floatval($valor), 2, '.', '');
}

// Função auxiliar para formatar data/hora
function formatarDataHora($datetime) {
    if (empty($datetime)) return null;
    $dt = new DateTime($datetime);
    return $dt->format('Y-m-d\TH:i:sP');
}

/**
 * Obtém o próximo número de MDF-e para o CNPJ/série.
 * Não incrementa o contador - apenas retorna o próximo disponível.
 */
function getProximoNumeroMDFe($db, $cnpj, $ambiente = 2) {
    $p = MAIN_DB_PREFIX;
    $ambiente = intval($ambiente);
    if (!in_array($ambiente, [1, 2])) $ambiente = 2;

    $sql = "SELECT ultimo_numero, serie FROM {$p}mdfe_sequencias WHERE cnpj = '".$db->escape($cnpj)."' AND ambiente = ".$ambiente;
    $res = $db->query($sql);
    if ($res && $db->num_rows($res) > 0) {
        $obj = $db->fetch_object($res);
        return [intval($obj->ultimo_numero) + 1, $obj->serie ?: '1'];
    } else {
        // Primeiro uso: insere registro inicial
        $db->query("INSERT INTO {$p}mdfe_sequencias (cnpj, serie, ambiente, ultimo_numero) VALUES ('".$db->escape($cnpj)."', '1', ".$ambiente.", 0)");
        return [1, '1'];
    }
}

/**
 * Confirma (incrementa) o número do MDF-e após emissão autorizada pela SEFAZ.
 */
function confirmarNumeroMDFe($db, $cnpj, $numero, $ambiente = 2) {
    $p = MAIN_DB_PREFIX;
    $ambiente = intval($ambiente);
    if (!in_array($ambiente, [1, 2])) $ambiente = 2;
    $db->query("UPDATE {$p}mdfe_sequencias SET ultimo_numero = ".intval($numero).", updated_at = NOW() WHERE cnpj = '".$db->escape($cnpj)."' AND ambiente = ".$ambiente);
}

try {
    // Validações básicas
    $errosValidacao = [];
    
    // Campos obrigatórios conforme layout oficial
    // CNPJ ou CPF é obrigatório (não ambos)
    if (empty(GETPOST('cnpj_emit', 'alpha')) && empty(GETPOST('cpf_emit', 'alpha'))) {
        $errosValidacao[] = 'CNPJ ou CPF do Emitente é obrigatório';
    }
    if (empty(GETPOST('ie_emit', 'alpha'))) {
        $errosValidacao[] = 'Inscrição Estadual do Emitente é obrigatória';
    }
    if (empty(GETPOST('razao_social', 'alpha'))) {
        $errosValidacao[] = 'Razão Social do Emitente é obrigatória';
    }
    if (empty(GETPOST('vCarga', 'alpha'))) {
        $errosValidacao[] = 'Valor da Carga é obrigatório';
    }
    if (empty(GETPOST('qCarga', 'alpha'))) {
        $errosValidacao[] = 'Peso da Carga é obrigatório';
    }
    
    // Verificar se há pelo menos um município de descarga com documento
    $munDescargas = GETPOST('mun_descarga', 'array');
    $temDocumento = false;
    $totalDocumentos = 0;
    
    if (!empty($munDescargas)) {
        foreach ($munDescargas as $mun) {
            if (!empty($mun['cte']) || !empty($mun['nfe'])) {
                foreach ((array)($mun['cte'] ?? []) as $doc) {
                    if (!empty($doc['chCTe'])) {
                        $temDocumento = true;
                        $totalDocumentos++;
                    }
                }
                foreach ((array)($mun['nfe'] ?? []) as $doc) {
                    if (!empty($doc['chNFe'])) {
                        $temDocumento = true;
                        $totalDocumentos++;
                    }
                }
            }
        }
    }
    $indCarregaPosterior = GETPOST('indCarregaPosterior', 'alpha');

    // Quando indCarregaPosterior = 1 os documentos serão inseridos após a emissão — dispensa a obrigação de documentos
    // MAS o schema MDF-e exige infDoc com ao menos um município de descarga mesmo assim
    if (!$temDocumento && $indCarregaPosterior !== '1') {
        $errosValidacao[] = 'É necessário informar pelo menos uma NF-e ou CT-e';
    }
    if ($indCarregaPosterior === '1') {
        $temMunDescarga = false;
        if (!empty($munDescargas)) {
            foreach ($munDescargas as $mun) {
                if (!empty($mun['xMunDescarga'])) {
                    $temMunDescarga = true;
                    break;
                }
            }
        }
        if (!$temMunDescarga) {
            $errosValidacao[] = 'Informe pelo menos um Município de Descarga (obrigatório pelo schema MDF-e mesmo com Carga Posterior = Sim)';
        }
    }
    
    // Verificar infLotacao - obrigatório quando só existe 1 documento
    if ($totalDocumentos === 1 && $indCarregaPosterior !== '1') {
        $prodPredData = GETPOST('prodPred', 'array');
        $usaLotacao = !empty($prodPredData['usaLotacao']);
        $infLotacao = $prodPredData['infLotacao'] ?? [];
        
        if (!$usaLotacao) {
            $errosValidacao[] = 'Dados de Lotação são obrigatórios quando só existir um documento informado! Vá na aba "Totais" e marque a opção "Informar dados de Lotação"';
        } else {
            // Verificar se pelo menos o CEP de carregamento foi informado
            $cepCarrega = $infLotacao['infLocalCarrega']['CEP'] ?? '';
            $cepDescarrega = $infLotacao['infLocalDescarrega']['CEP'] ?? '';
            
            if (empty($cepCarrega) && empty($cepDescarrega)) {
                $errosValidacao[] = 'Quando há apenas 1 documento, é obrigatório informar pelo menos o CEP de carregamento ou descarregamento na seção de Lotação';
            }
        }
    }
    
    // Modal Rodoviário - validações específicas
    if (GETPOST('modal', 'alpha') == '1') {
        $veicTracao = GETPOST('veic_tracao', 'array');
        if (empty($veicTracao['placa'])) {
            $errosValidacao[] = 'Placa do veículo de tração é obrigatória';
        }
        if (empty($veicTracao['tara'])) {
            $errosValidacao[] = 'Tara do veículo de tração é obrigatória';
        }
        
        // Verificar condutor
        $condutores = GETPOST('condutores', 'array');
        $temCondutor = false;
        if (!empty($condutores)) {
            foreach ($condutores as $cond) {
                if (!empty($cond['xNome']) && !empty($cond['CPF'])) {
                    $temCondutor = true;
                    break;
                }
            }
        }
        if (!$temCondutor) {
            $errosValidacao[] = 'É necessário informar pelo menos um condutor com nome e CPF';
        }
        
        // Validar infContratante quando tpEmit = 1 (Prestador de serviço de transporte)
        $tpEmit = GETPOST('tpEmit', 'alpha');
        if ($tpEmit == '1') {
            $contratantes = GETPOST('contratantes', 'array');
            $temContratante = false;
            if (!empty($contratantes)) {
                foreach ($contratantes as $contratante) {
                    if (!empty($contratante['CNPJ']) || !empty($contratante['CPF'])) {
                        $temContratante = true;
                        break;
                    }
                }
            }
            if (!$temContratante) {
                $errosValidacao[] = 'Informações dos contratantes é obrigatória quando Tipo Emitente = "Prestador de serviço de transporte". Vá na aba "Modal Rodoviário" > "Contratantes" e adicione pelo menos um contratante.';
            }
        }
    }
    
    // Validar dados bancários/parcelas nos pagamentos
    $pagamentosValidacao = GETPOST('pagamentos', 'array');
    if (!empty($pagamentosValidacao)) {
        foreach ($pagamentosValidacao as $idx => $pag) {
            $indPagVal = (string)($pag['indPag'] ?? '0');
            if ($indPagVal === '0') {
                // À Vista: dados bancários obrigatórios
                $temBanco = !empty($pag['codBanco']) || !empty($pag['codAgencia']) || !empty($pag['CNPJIPEF']);
                if (!$temBanco) {
                    $errosValidacao[] = 'Pagamento ' . ($idx + 1) . ': para pagamento "À Vista", informe os dados bancários (Banco + Agência, ou CNPJ da IPEF).';
                }
            } else {
                // A Prazo: pelo menos uma parcela válida obrigatória
                $temParcela = false;
                if (!empty($pag['infPrazo'])) {
                    foreach ($pag['infPrazo'] as $prazo) {
                        if (!empty($prazo['dVenc']) && !empty($prazo['vParcela'])) {
                            $temParcela = true;
                            break;
                        }
                    }
                }
                if (!$temParcela) {
                    $errosValidacao[] = 'Pagamento ' . ($idx + 1) . ': para pagamento "A Prazo", informe pelo menos uma parcela com data de vencimento e valor.';
                }
            }
        }
    }

    // Se houver erros, exibir e parar
    if (!empty($errosValidacao)) {
        throw new Exception("Erros de validação:\n• " . implode("\n• ", $errosValidacao));
    }

    // === Resolver municípios via IBGE ===
    $xMunEmit = GETPOST('xMun_emit', 'alpha');
    $ufEmit = GETPOST('UF_emit', 'alpha');
    $dadosIbgeMunEmit = null;
    if (!empty($xMunEmit)) {
        $dadosIbgeMunEmit = buscarDadosIbge($db, $xMunEmit, $ufEmit);
        if (!$dadosIbgeMunEmit) {
            throw new Exception("Município do emitente '{$xMunEmit}' não encontrado na base IBGE. Verifique o nome digitado.");
        }
    }

    // === Pegar ambiente ANTES de gerar número ===
    $ambiente = 2; // Padrão: Homologação
    if (isset($db) && is_object($db)) {
        $sql = "SELECT value FROM ".MAIN_DB_PREFIX. "nfe_config WHERE name = 'ambiente';";
        $resSql = $db->query($sql);
        if($resSql && $db->num_rows($resSql) > 0){
            $res = $db->fetch_object($resSql);
            $ambiente = (int)$res->value;
        }
    }

    // === Gerar número MDF-e automaticamente (por ambiente) ===
    $cnpjEmitLimpo = limparNumero(GETPOST('cnpj_emit', 'alpha'));
    [$nMDF, $serie] = getProximoNumeroMDFe($db, $cnpjEmitLimpo, $ambiente);

    // Configuração
    $config = [
        "atualizacao" => date('Y-m-d H:i:s'),
        "tpAmb" => $ambiente, // 1=Produção, 2=Homologação
        "razaosocial" => GETPOST('razao_social', 'alpha'),
        "siglaUF" => GETPOST('UF_emit', 'alpha'),
        "cnpj" => $cnpjEmitLimpo,
        "inscricaomunicipal" => "",
        "codigomunicipio" => $dadosIbgeMunEmit ? $dadosIbgeMunEmit->codigo_ibge : '',
        "schemes" => "PL_MDFe_300a",
        "versao" => "3.00"
    ];

    $configJson = json_encode($config);

    $mdfe = new Make();
    $mdfe->setOnlyAscii(true);

    /*
     * Grupo ide (Identificação)
     */
    $std = new \stdClass();
    $std->cUF = GETPOST('cUF', 'alpha');
    $std->tpAmb = $ambiente; // 1=Produção, 2=Homologação
    $std->tpEmit = GETPOST('tpEmit', 'alpha');
    $tpTransp = GETPOST('tpTransp', 'alpha');
    if (!empty($tpTransp)) {
        $std->tpTransp = $tpTransp;
    }
    $std->mod = '58';
    $std->serie = $serie;
    $std->nMDF = $nMDF; // Número gerado automaticamente
    $std->cMDF = str_pad(rand(1, 99999999), 8, '0', STR_PAD_LEFT); // Código numérico aleatório
    $std->cDV = '0'; // Será calculado automaticamente
    $std->modal = GETPOST('modal', 'alpha');
    $std->dhEmi = date('Y-m-d\TH:i:sP');
    $std->tpEmis = GETPOST('tpEmis', 'alpha');
    $std->procEmi = '0';
    $std->verProc = '1.0';
    $std->UFIni = GETPOST('UFIni', 'alpha');
    $std->UFFim = GETPOST('UFFim', 'alpha');
    
    $dhIniViagem = GETPOST('dhIniViagem', 'alpha');
    if (!empty($dhIniViagem)) {
        $std->dhIniViagem = formatarDataHora($dhIniViagem);
    }
    
    $indCanalVerde = GETPOST('indCanalVerde', 'alpha');
    if (!empty($indCanalVerde)) {
        $std->indCanalVerde = $indCanalVerde;
    }
    
    $indCarregaPosterior = GETPOST('indCarregaPosterior', 'alpha');
    if (!empty($indCarregaPosterior)) {
        $std->indCarregaPosterior = $indCarregaPosterior;
    }
    
    $mdfe->tagide($std);

    // Municípios de Carregamento
    $munCarrega = GETPOST('mun_carrega', 'array');
    $ufsCarregamento = GETPOST('UFCarregamento', 'array'); // agora é array indexado: UFCarregamento[0], UFCarregamento[1]...
    if (!empty($munCarrega)) {
        foreach ($munCarrega as $idx => $mun) {
            if (!empty($mun['xMunCarrega'])) {
                $ufCarrega = !empty($ufsCarregamento[$idx]) ? strtoupper($ufsCarregamento[$idx]) : null;
                $dadosIbgeMunCar = buscarDadosIbge($db, $mun['xMunCarrega'], $ufCarrega);
                if (!$dadosIbgeMunCar) {
                    throw new Exception("Município de carregamento '" . $mun['xMunCarrega'] . "' não encontrado na base IBGE.");
                }
                $infMunCarrega = new \stdClass();
                $infMunCarrega->cMunCarrega = $dadosIbgeMunCar->codigo_ibge;
                $infMunCarrega->xMunCarrega = mb_strtoupper($dadosIbgeMunCar->nome, 'UTF-8');
                $mdfe->taginfMunCarrega($infMunCarrega);
            }
        }
    }

    // UFs de Percurso
    $ufsPercurso = GETPOST('ufs_percurso', 'array');
    if (!empty($ufsPercurso)) {
        foreach ($ufsPercurso as $uf) {
            if (!empty($uf['UFPer'])) {
                $infPercurso = new \stdClass();
                $infPercurso->UFPer = $uf['UFPer'];
                $mdfe->taginfPercurso($infPercurso);
            }
        }
    }

    /*
     * Grupo emit (Emitente)
     */
    $std = new \stdClass();
    $std->CNPJ = limparNumero(GETPOST('cnpj_emit', 'alpha'));
    $std->IE = limparNumero(GETPOST('ie_emit', 'alpha'));
    $std->xNome = mb_strtoupper(GETPOST('razao_social', 'alpha'), 'UTF-8');
    $xFant = GETPOST('nome_fantasia', 'alpha');
    if (!empty($xFant)) {
        $std->xFant = mb_strtoupper($xFant, 'UTF-8');
    }
    $mdfe->tagemit($std);

    // Endereço do Emitente
    $std = new \stdClass();
    $std->xLgr = mb_strtoupper(GETPOST('xLgr', 'alpha'), 'UTF-8');
    $std->nro = GETPOST('nro', 'alpha');
    $xCpl = GETPOST('xCpl', 'alpha');
    if (!empty($xCpl)) {
        $std->xCpl = mb_strtoupper($xCpl, 'UTF-8');
    }
    $std->xBairro = mb_strtoupper(GETPOST('xBairro', 'alpha'), 'UTF-8');
    $std->cMun = $dadosIbgeMunEmit ? $dadosIbgeMunEmit->codigo_ibge : '';
    $std->xMun = $dadosIbgeMunEmit ? mb_strtoupper($dadosIbgeMunEmit->nome, 'UTF-8') : mb_strtoupper(GETPOST('xMun_emit', 'alpha'), 'UTF-8');
    $std->CEP = limparNumero(GETPOST('CEP_emit', 'alpha'));
    $std->UF = GETPOST('UF_emit', 'alpha');
    $fone = limparNumero(GETPOST('fone_emit', 'alpha'));
    if (!empty($fone)) {
        $std->fone = $fone;
    }
    $email = GETPOST('email_emit', 'alpha');
    if (!empty($email)) {
        $std->email = $email;
    }
    $mdfe->tagenderEmit($std);

    /*
     * Grupo rodo (Rodoviário) - apenas para modal rodoviário
     */
    if (GETPOST('modal', 'alpha') == '1') {
        
        // Informações ANTT
        $RNTRC = GETPOST('RNTRC', 'alpha');
        if (!empty($RNTRC)) {
            $infANTT = new \stdClass();
            $infANTT->RNTRC = $RNTRC;
            $mdfe->taginfANTT($infANTT);
        }

        // CIOT
        $ciots = GETPOST('ciot', 'array');
        if (!empty($ciots)) {
            foreach ($ciots as $ciot) {
                if (!empty($ciot['CIOT'])) {
                    $infCIOT = new \stdClass();
                    $infCIOT->CIOT = $ciot['CIOT'];
                    if (!empty($ciot['CPF'])) {
                        $infCIOT->CPF = limparNumero($ciot['CPF']);
                    }
                    if (!empty($ciot['CNPJ'])) {
                        $infCIOT->CNPJ = limparNumero($ciot['CNPJ']);
                    }
                    $mdfe->taginfCIOT($infCIOT);
                }
            }
        }

        // Vale Pedágio (valePed > disp)
        $valesPedagio = GETPOST('vale_pedagio', 'array');
        if (!empty($valesPedagio)) {
            // Agrupar todos os dispositivos
            $dispositivos = [];
            foreach ($valesPedagio as $vale) {
                if (!empty($vale['CNPJForn'])) {
                    $disp = new \stdClass();
                    $disp->CNPJForn = limparNumero($vale['CNPJForn']);
                    if (!empty($vale['CNPJPg'])) {
                        $disp->CNPJPg = limparNumero($vale['CNPJPg']);
                    }
                    if (!empty($vale['CPFPg'])) {
                        $disp->CPFPg = limparNumero($vale['CPFPg']);
                    }
                    if (!empty($vale['nCompra'])) {
                        $disp->nCompra = $vale['nCompra'];
                    }
                    if (!empty($vale['vValePed'])) {
                        $disp->vValePed = formatarValor($vale['vValePed']);
                    }
                    $dispositivos[] = $disp;
                }
            }
            // Se houver dispositivos, agrupar em valePed
            if (!empty($dispositivos)) {
                foreach ($dispositivos as $disp) {
                    $mdfe->tagdisp($disp);
                }
            }
        }

        // Contratantes
        $contratantes = GETPOST('contratantes', 'array');
        if (!empty($contratantes)) {
            foreach ($contratantes as $contratante) {
                if (!empty($contratante['CNPJ']) || !empty($contratante['CPF'])) {
                    $infContratante = new \stdClass();
                    if (!empty($contratante['CNPJ'])) {
                        $infContratante->CNPJ = limparNumero($contratante['CNPJ']);
                    }
                    if (!empty($contratante['CPF'])) {
                        $infContratante->CPF = limparNumero($contratante['CPF']);
                    }
                    $mdfe->taginfContratante($infContratante);
                }
            }
        }

        // Veículo de Tração
        $veicTracao = GETPOST('veic_tracao', 'array');
        if (!empty($veicTracao['placa'])) {
            $stdVeicTracao = new \stdClass();
            if (!empty($veicTracao['cInt'])) {
                $stdVeicTracao->cInt = $veicTracao['cInt'];
            }
            $stdVeicTracao->placa = strtoupper($veicTracao['placa']);
            if (!empty($veicTracao['RENAVAM'])) {
                $stdVeicTracao->RENAVAM = $veicTracao['RENAVAM'];
            }
            $stdVeicTracao->tara = $veicTracao['tara'];
            if (!empty($veicTracao['capKG'])) {
                $stdVeicTracao->capKG = $veicTracao['capKG'];
            }
            $stdVeicTracao->tpRod = $veicTracao['tpRod'];
            $stdVeicTracao->tpCar = $veicTracao['tpCar'];
            $stdVeicTracao->UF = $veicTracao['UF'];

            // Condutores
            $condutores = GETPOST('condutores', 'array');
            $arrCondutores = [];
            if (!empty($condutores)) {
                foreach ($condutores as $cond) {
                    if (!empty($cond['xNome']) && !empty($cond['CPF'])) {
                        $condutor = new \stdClass();
                        $condutor->xNome = mb_strtoupper($cond['xNome'], 'UTF-8');
                        $condutor->CPF = limparNumero($cond['CPF']);
                        $arrCondutores[] = $condutor;
                    }
                }
            }
            if (!empty($arrCondutores)) {
                $stdVeicTracao->condutor = $arrCondutores;
            }

            // Proprietário do veículo de tração
            $propTracao = GETPOST('prop_tracao', 'array');
            if (!empty($propTracao['CPF']) || !empty($propTracao['CNPJ'])) {
                $prop = new \stdClass();
                if (!empty($propTracao['CPF'])) {
                    $prop->CPF = limparNumero($propTracao['CPF']);
                }
                if (!empty($propTracao['CNPJ'])) {
                    $prop->CNPJ = limparNumero($propTracao['CNPJ']);
                }
                if (!empty($propTracao['RNTRC'])) {
                    $prop->RNTRC = $propTracao['RNTRC'];
                }
                if (!empty($propTracao['xNome'])) {
                    $prop->xNome = mb_strtoupper($propTracao['xNome'], 'UTF-8');
                }
                if (!empty($propTracao['IE'])) {
                    $prop->IE = $propTracao['IE'];
                }
                if (!empty($propTracao['UF'])) {
                    $prop->UF = $propTracao['UF'];
                }
                if (!empty($propTracao['tpProp'])) {
                    $prop->tpProp = $propTracao['tpProp'];
                }
                $stdVeicTracao->prop = $prop;
            }

            $mdfe->tagveicTracao($stdVeicTracao);
        }

        // Veículos Reboque
        $reboques = GETPOST('reboques', 'array');
        if (!empty($reboques)) {
            foreach ($reboques as $reboque) {
                if (!empty($reboque['placa'])) {
                    $stdReboque = new \stdClass();
                    if (!empty($reboque['cInt'])) {
                        $stdReboque->cInt = $reboque['cInt'];
                    }
                    $stdReboque->placa = strtoupper($reboque['placa']);
                    if (!empty($reboque['RENAVAM'])) {
                        $stdReboque->RENAVAM = $reboque['RENAVAM'];
                    }
                    $stdReboque->tara = $reboque['tara'];
                    if (!empty($reboque['capKG'])) {
                        $stdReboque->capKG = $reboque['capKG'];
                    }
                    if (!empty($reboque['capM3'])) {
                        $stdReboque->capM3 = $reboque['capM3'];
                    }
                    $stdReboque->tpCar = $reboque['tpCar'];
                    $stdReboque->UF = $reboque['UF'];
                    
                    // Proprietário do reboque
                    if (!empty($reboque['prop'])) {
                        $propReboque = $reboque['prop'];
                        if (!empty($propReboque['CPF']) || !empty($propReboque['CNPJ'])) {
                            $propReb = new \stdClass();
                            if (!empty($propReboque['CPF'])) {
                                $propReb->CPF = limparNumero($propReboque['CPF']);
                            }
                            if (!empty($propReboque['CNPJ'])) {
                                $propReb->CNPJ = limparNumero($propReboque['CNPJ']);
                            }
                            if (!empty($propReboque['RNTRC'])) {
                                $propReb->RNTRC = $propReboque['RNTRC'];
                            }
                            if (!empty($propReboque['xNome'])) {
                                $propReb->xNome = mb_strtoupper($propReboque['xNome'], 'UTF-8');
                            }
                            if (!empty($propReboque['IE'])) {
                                $propReb->IE = $propReboque['IE'];
                            }
                            if (!empty($propReboque['UF'])) {
                                $propReb->UF = $propReboque['UF'];
                            }
                            if (!empty($propReboque['tpProp'])) {
                                $propReb->tpProp = $propReboque['tpProp'];
                            }
                            $stdReboque->prop = $propReb;
                        }
                    }
                    
                    $mdfe->tagveicReboque($stdReboque);
                }
            }
        }
        
        // Código de Agendamento no Porto
        $codAgPorto = GETPOST('codAgPorto', 'alpha');
        if (!empty($codAgPorto)) {
            $mdfe->tagcodAgPorto($codAgPorto);
        }

        // Lacres do modal rodoviário
        $lacresRodo = GETPOST('lacres_rodo', 'array');
        if (!empty($lacresRodo)) {
            foreach ($lacresRodo as $lacre) {
                if (!empty($lacre['nLacre'])) {
                    $lacRodo = new \stdClass();
                    $lacRodo->nLacre = $lacre['nLacre'];
                    $mdfe->taglacRodo($lacRodo);
                }
            }
        }
    }

    /*
     * Grupo infDoc (Documentos Fiscais)
     * O schema MDF-e sempre exige infDoc com ao menos um município.
     * Quando indCarregaPosterior = 1, gera os municípios mas omite os documentos (CT-e/NF-e) dentro deles.
     */
    $munDescargas = GETPOST('mun_descarga', 'array');
    $ufsDescarga = GETPOST('UFDescarga', 'array'); // array indexado: UFDescarga[0], UFDescarga[1]...
    if (!empty($munDescargas)) {
        $nItem = 0;
        foreach ($munDescargas as $index => $munDescarga) {
            if (!empty($munDescarga['xMunDescarga'])) {
                // Buscar código do município via IBGE (agora passa a UF para evitar ambiguidade)
                $ufDescarga = !empty($ufsDescarga[$index]) ? strtoupper($ufsDescarga[$index]) : null;
                $dadosIbgeDesc = buscarDadosIbge($db, $munDescarga['xMunDescarga'], $ufDescarga);
                if (!$dadosIbgeDesc) {
                    throw new Exception("Município de descarga '" . $munDescarga['xMunDescarga'] . "' não encontrado na base IBGE.");
                }
                // Município de Descarga
                $infMunDescarga = new \stdClass();
                $infMunDescarga->cMunDescarga = $dadosIbgeDesc->codigo_ibge;
                $infMunDescarga->xMunDescarga = mb_strtoupper($dadosIbgeDesc->nome, 'UTF-8');
                $infMunDescarga->nItem = $nItem;
                $mdfe->taginfMunDescarga($infMunDescarga);

                // CT-e e NF-e só são incluídos quando NÃO for carga posterior
                if ($indCarregaPosterior !== '1') {
                    // CT-e vinculados a este município
                    if (!empty($munDescarga['cte'])) {
                        foreach ($munDescarga['cte'] as $cte) {
                            if (!empty($cte['chCTe'])) {
                                $std = new \stdClass();
                                $std->chCTe = $cte['chCTe'];
                                if (!empty($cte['SegCodBarra'])) {
                                    $std->SegCodBarra = $cte['SegCodBarra'];
                                }
                                if (!empty($cte['indReentrega'])) {
                                    $std->indReentrega = $cte['indReentrega'];
                                }
                                $std->nItem = $nItem;
                                $mdfe->taginfCTe($std);
                            }
                        }
                    }

                    // NF-e vinculadas a este município
                    if (!empty($munDescarga['nfe'])) {
                        foreach ($munDescarga['nfe'] as $nfe) {
                            if (!empty($nfe['chNFe'])) {
                                $std = new \stdClass();
                                $std->chNFe = $nfe['chNFe'];
                                if (!empty($nfe['SegCodBarra'])) {
                                    $std->SegCodBarra = $nfe['SegCodBarra'];
                                }
                                if (!empty($nfe['indReentrega'])) {
                                    $std->indReentrega = $nfe['indReentrega'];
                                }
                                $std->nItem = $nItem;
                                $mdfe->taginfNFe($std);
                            }
                        }
                    }
                }

                $nItem++;
            }
        }
    }

    /*
     * Grupo seg (Seguro)
     */
    $seguros = GETPOST('seguros', 'array');
    if (!empty($seguros)) {
        foreach ($seguros as $seguro) {
            if (!empty($seguro['respSeg'])) {
                $std = new \stdClass();
                $std->respSeg = $seguro['respSeg'];
                
                // Se respSeg = 2, precisa informar CNPJ ou CPF do responsável
                if ($seguro['respSeg'] == '2') {
                    if (!empty($seguro['respCNPJ'])) {
                        $std->CNPJ = limparNumero($seguro['respCNPJ']);
                    } elseif (!empty($seguro['respCPF'])) {
                        $std->CPF = limparNumero($seguro['respCPF']);
                    }
                }

                // Informações da seguradora (opcional)
                if (!empty($seguro['xSeg']) || !empty($seguro['CNPJSeg'])) {
                    $infSeg = new \stdClass();
                    if (!empty($seguro['xSeg'])) {
                        $infSeg->xSeg = mb_strtoupper($seguro['xSeg'], 'UTF-8');
                    }
                    if (!empty($seguro['CNPJSeg'])) {
                        $infSeg->CNPJ = limparNumero($seguro['CNPJSeg']);
                    }
                    $std->infSeg = $infSeg;
                }

                if (!empty($seguro['nApol'])) {
                    $std->nApol = $seguro['nApol'];
                }

                if (!empty($seguro['nAver'])) {
                    $nAvers = array_map('trim', explode(',', $seguro['nAver']));
                    $std->nAver = $nAvers;
                }

                $mdfe->tagseg($std);
            }
        }
    }

    /*
     * Grupo tot (Totais)
     */
    // Contar documentos (calculado automaticamente)
    $qCTe = 0;
    $qNFe = 0;
    $munDescargas = GETPOST('mun_descarga', 'array');
    if (!empty($munDescargas)) {
        foreach ($munDescargas as $mun) {
            if (!empty($mun['cte'])) {
                foreach ($mun['cte'] as $doc) {
                    if (!empty($doc['chCTe'])) {
                        $qCTe++;
                    }
                }
            }
            if (!empty($mun['nfe'])) {
                foreach ($mun['nfe'] as $doc) {
                    if (!empty($doc['chNFe'])) {
                        $qNFe++;
                    }
                }
            }
        }
    }
    
    $std = new \stdClass();
    if ($qCTe > 0) {
        $std->qCTe = $qCTe;
    }
    if ($qNFe > 0) {
        $std->qNFe = $qNFe;
    }
    $std->vCarga = formatarValor(GETPOST('vCarga', 'alpha'));
    $std->cUnid = GETPOST('cUnid', 'alpha');
    $std->qCarga = formatarValor(GETPOST('qCarga', 'alpha'));
    $mdfe->tagtot($std);

    /*
     * Grupo lacres
     */
    $lacres = GETPOST('lacres', 'array');
    if (!empty($lacres)) {
        foreach ($lacres as $lacre) {
            if (!empty($lacre['nLacre'])) {
                $std = new \stdClass();
                $std->nLacre = $lacre['nLacre'];
                $mdfe->taglacres($std);
            }
        }
    }

    /*
     * Grupo autXML (Autorizados para download)
     */
    $autorizados = GETPOST('autorizados', 'array');
    if (!empty($autorizados)) {
        foreach ($autorizados as $autorizado) {
            if (!empty($autorizado['CNPJ']) || !empty($autorizado['CPF'])) {
                $std = new \stdClass();
                if (!empty($autorizado['CNPJ'])) {
                    $std->CNPJ = limparNumero($autorizado['CNPJ']);
                }
                if (!empty($autorizado['CPF'])) {
                    $std->CPF = limparNumero($autorizado['CPF']);
                }
                $mdfe->tagautXML($std);
            }
        }
    }

    /*
     * Grupo prodPred (Produto Predominante)
     * Nota: quando há apenas 1 documento, o infLotacao é obrigatório
     */
    $prodPredData = GETPOST('prodPred', 'array');
    $deveCriarProdPred = !empty($prodPredData['tpCarga']) || !empty($prodPredData['xProd']) || !empty($prodPredData['usaLotacao']);
    
    if ($deveCriarProdPred) {
        $prodPred = new \stdClass();
        if (!empty($prodPredData['tpCarga'])) {
            $prodPred->tpCarga = $prodPredData['tpCarga'];
        }
        if (!empty($prodPredData['xProd'])) {
            $prodPred->xProd = mb_strtoupper($prodPredData['xProd'], 'UTF-8');
        }
        if (!empty($prodPredData['cEAN'])) {
            $prodPred->cEAN = $prodPredData['cEAN'];
        }
        if (!empty($prodPredData['NCM'])) {
            $prodPred->NCM = preg_replace('/\D/', '', $prodPredData['NCM']);
        }
        
        // Informações de Lotação (obrigatório quando há apenas 1 documento)
        if (!empty($prodPredData['usaLotacao']) && !empty($prodPredData['infLotacao'])) {
            $lotacao = new \stdClass();
            
            // Local de Carregamento
            if (!empty($prodPredData['infLotacao']['infLocalCarrega'])) {
                $localCarrega = new \stdClass();
                $carregaData = $prodPredData['infLotacao']['infLocalCarrega'];
                if (!empty($carregaData['CEP'])) {
                    $localCarrega->CEP = limparNumero($carregaData['CEP']);
                }
                if (!empty($carregaData['latitude'])) {
                    $localCarrega->latitude = $carregaData['latitude'];
                }
                if (!empty($carregaData['longitude'])) {
                    $localCarrega->longitude = $carregaData['longitude'];
                }
                if (!empty($localCarrega->CEP) || !empty($localCarrega->latitude)) {
                    $lotacao->infLocalCarrega = $localCarrega;
                }
            }
            
            // Local de Descarregamento
            if (!empty($prodPredData['infLotacao']['infLocalDescarrega'])) {
                $localDescarrega = new \stdClass();
                $descarregaData = $prodPredData['infLotacao']['infLocalDescarrega'];
                if (!empty($descarregaData['CEP'])) {
                    $localDescarrega->CEP = limparNumero($descarregaData['CEP']);
                }
                if (!empty($descarregaData['latitude'])) {
                    $localDescarrega->latitude = $descarregaData['latitude'];
                }
                if (!empty($descarregaData['longitude'])) {
                    $localDescarrega->longitude = $descarregaData['longitude'];
                }
                if (!empty($localDescarrega->CEP) || !empty($localDescarrega->latitude)) {
                    $lotacao->infLocalDescarrega = $localDescarrega;
                }
            }
            
            // Só adiciona infLotacao se tiver pelo menos um local definido
            if (isset($lotacao->infLocalCarrega) || isset($lotacao->infLocalDescarrega)) {
                $prodPred->infLotacao = $lotacao;
            }
        }
        
        $mdfe->tagprodPred($prodPred);
    }

    /*
     * Grupo infPag (Informações de Pagamento)
     */
    $pagamentos = GETPOST('pagamentos', 'array');
    if (!empty($pagamentos)) {
        foreach ($pagamentos as $pagamento) {
            if (!empty($pagamento['xNome']) || !empty($pagamento['vContrato']) || !empty($pagamento['CPF']) || !empty($pagamento['CNPJ'])) {
                $infPag = new \stdClass();
                if (!empty($pagamento['xNome'])) {
                    $infPag->xNome = $pagamento['xNome'];
                }
                if (!empty($pagamento['CPF'])) {
                    $infPag->CPF = limparNumero($pagamento['CPF']);
                }
                if (!empty($pagamento['CNPJ'])) {
                    $infPag->CNPJ = limparNumero($pagamento['CNPJ']);
                }
                
                // Componentes do pagamento
                if (!empty($pagamento['Comp'])) {
                    $componentes = [];
                    foreach ($pagamento['Comp'] as $comp) {
                        if (!empty($comp['tpComp']) && !empty($comp['vComp'])) {
                            $stdComp = new \stdClass();
                            $stdComp->tpComp = $comp['tpComp'];
                            $stdComp->vComp = formatarValor($comp['vComp']);
                            if (!empty($comp['xComp'])) {
                                $stdComp->xComp = $comp['xComp'];
                            }
                            $componentes[] = $stdComp;
                        }
                    }
                    if (!empty($componentes)) {
                        $infPag->Comp = $componentes;
                    }
                }
                
                if (!empty($pagamento['vContrato'])) {
                    $infPag->vContrato = formatarValor($pagamento['vContrato']);
                }
                if (isset($pagamento['indPag'])) {
                    $infPag->indPag = $pagamento['indPag'];
                }
                
                // Parcelas (infPrazo)
                if (!empty($pagamento['infPrazo'])) {
                    $parcelas = [];
                    foreach ($pagamento['infPrazo'] as $prazo) {
                        if (!empty($prazo['nParcela']) && !empty($prazo['dVenc']) && !empty($prazo['vParcela'])) {
                            $stdPrazo = new \stdClass();
                            $stdPrazo->nParcela = $prazo['nParcela'];
                            $stdPrazo->dVenc = $prazo['dVenc'];
                            $stdPrazo->vParcela = formatarValor($prazo['vParcela']);
                            $parcelas[] = $stdPrazo;
                        }
                    }
                    if (!empty($parcelas)) {
                        $infPag->infPrazo = $parcelas;
                    }
                }

                // Informações bancárias
                if (!empty($pagamento['codBanco']) || !empty($pagamento['codAgencia']) || !empty($pagamento['CNPJIPEF'])) {
                    $infBanc = new \stdClass();
                    if (!empty($pagamento['codBanco'])) {
                        $infBanc->codBanco = $pagamento['codBanco'];
                    }
                    if (!empty($pagamento['codAgencia'])) {
                        $infBanc->codAgencia = $pagamento['codAgencia'];
                    }
                    if (!empty($pagamento['CNPJIPEF'])) {
                        $infBanc->CNPJIPEF = limparNumero($pagamento['CNPJIPEF']);
                    }
                    $infPag->infBanc = $infBanc;
                }

                $mdfe->taginfPag($infPag);
            }
        }
    }

    /*
     * Grupo infAdic (Informações Adicionais)
     */
    $infCpl = GETPOST('infCpl', 'alpha');
    $infAdFisco = GETPOST('infAdFisco', 'alpha');
    if (!empty($infCpl) || !empty($infAdFisco)) {
        $std = new \stdClass();
        if (!empty($infCpl)) {
            $std->infCpl = $infCpl;
        }
        if (!empty($infAdFisco)) {
            $std->infAdFisco = $infAdFisco;
        }
        $mdfe->taginfAdic($std);
    }

    // Gerar o XML - capturando erros específicos
    try {
        $xml = $mdfe->getXML();
    } catch (\Exception $xmlEx) {
        // Captura os erros específicos das tags
        $errosTags = mdfe_traduzir_erros($mdfe->getErrors());
        $mensagemErro = "Erro ao gerar MDF-e:\n" . $xmlEx->getMessage();
        if (!empty($errosTags)) {
            $mensagemErro = "Campos inválidos ou ausentes:\n• " . implode("\n• ", $errosTags);
        }
        throw new Exception($mensagemErro);
    }

    // Verificar erros adicionais
    $errors = mdfe_traduzir_erros($mdfe->getErrors());
    if (!empty($errors)) {
        throw new Exception("Campos inválidos ou ausentes:\n• " . implode("\n• ", $errors));
    }
    
    $filename = 'MDFe_' . $serie . '_' . $nMDF . '_' . date('YmdHis') . '.xml';

    $xmlAssinado = null;
    $respostaEnvio = null;
    $statusEnvio = 'nao_enviado';
    $chaveAcesso = '';
    $statusMDFe = 'gerado';

    // Se existir certificado configurado, tentar assinar e enviar
    
        try {
            //$pfxBin = file_get_contents($certPath);
            //$certificate = Certificate::readPfx($pfxBin, $certPass);
            $cert = carregarCertificadoA1Nacional($db);
            
            $tools = new Tools(json_encode($config), $cert);
            
            // Assinar o XML
            $xmlAssinado = $tools->signMDFe($xml);
            
            // Salvar XML assinado (persistência no banco)
            $filenameAssinado = 'MDFe_' . $serie . '_' . $nMDF . '_' . date('YmdHis') . '_assinado.xml';
            
            // Enviar para SEFAZ
            $resp = $tools->sefazEnviaLote([$xmlAssinado], rand(1, 10000), 1);

            // Normalizar resposta: pode ser string XML ou stdClass (dependendo da versão da lib)
            $st = new Standardize();
            if (is_string($resp)) {
                $respostaXmlBruto = $resp;
                $respostaEnvio = $st->toStd($resp);
            } else {
                // sefazEnviaLote retornou objeto — converter para string para persistência
                $respostaXmlBruto = json_encode($resp, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                // Se já é stdClass, usar diretamente como respostaEnvio
                $respostaEnvio = is_object($resp) ? $resp : $st->toStd((string)$resp);
            }

            // Marcar como enviado
            $statusEnvio = 'enviado';

            // Extrair chave de acesso do XML assinado
            $chaveAcesso = '';
            if (preg_match('/Id="MDFe(\d{44})"/', $xmlAssinado, $matchChave)) {
                $chaveAcesso = $matchChave[1];
            }

            // Determinar status baseado no cStat da SEFAZ (padronizado feminino)
            $statusMDFe = 'pendente';
            $cStatSefaz = null;
            $xMotivoSefaz = null;
            $nProtSefaz = null;
            $dhRecbtoSefaz = null;

            // Extrair dados do protocolo (protMDFe) quando presente
            if (isset($respostaEnvio->protMDFe->infProt)) {
                $infProt = $respostaEnvio->protMDFe->infProt;
                $cStatSefaz = $infProt->cStat ?? null;
                $xMotivoSefaz = $infProt->xMotivo ?? null;
                $nProtSefaz = $infProt->nProt ?? null;
                $dhRecbtoSefaz = $infProt->dhRecbto ?? null;
            } elseif (isset($respostaEnvio->cStat)) {
                $cStatSefaz = $respostaEnvio->cStat;
                $xMotivoSefaz = $respostaEnvio->xMotivo ?? null;
            }

            if ($cStatSefaz !== null) {
                if (in_array((string)$cStatSefaz, ['100'])) {
                    $statusMDFe = 'autorizada';
                } elseif (in_array((string)$cStatSefaz, ['103', '104'])) {
                    $statusMDFe = 'pendente';
                } elseif ((int)$cStatSefaz >= 200) {
                    $statusMDFe = 'rejeitada';
                }
            }

            // Confirmar número MDF-e se autorizado pela SEFAZ
            if ($cStatSefaz !== null && in_array((string)$cStatSefaz, ['100', '103', '104'])) {
                confirmarNumeroMDFe($db, $cnpjEmitLimpo, $nMDF, $ambiente);
            }

            // Coletar dados extras do formulário para persistir
            $munCarregaNome = '';
            $munCarregaArr = GETPOST('mun_carrega', 'array');
            if (!empty($munCarregaArr[0]['xMunCarrega'])) {
                $munCarregaNome = strtoupper($munCarregaArr[0]['xMunCarrega']);
            }
            $munDescargaNome = '';
            $munDescargasArr = GETPOST('mun_descarga', 'array');
            if (!empty($munDescargasArr[0]['xMunDescarga'])) {
                $munDescargaNome = strtoupper($munDescargasArr[0]['xMunDescarga']);
            }
            $placaVeic = '';
            $veicTracaoArr = GETPOST('veic_tracao', 'array');
            if (!empty($veicTracaoArr['placa'])) {
                $placaVeic = strtoupper($veicTracaoArr['placa']);
            }

            // Salvar no banco de dados somente se autorizada
            if (!empty($chaveAcesso) && $statusMDFe === 'autorizada') {
                // Usa dhEmi diretamente (sem conversão de fuso), tratando formato datetime-local
                $dhEmiRaw = GETPOST('dhEmi', 'alpha') ?: date('Y-m-d\TH:i');
                $dataEmissao = str_replace('T', ' ', $dhEmiRaw);
                if (strlen($dataEmissao) === 16) $dataEmissao .= ':00';
                $dataRecebimento = !empty($dhRecbtoSefaz) ? date('Y-m-d H:i:s', strtotime($dhRecbtoSefaz)) : null;
                $p = MAIN_DB_PREFIX;

                // Garantir que respostaXmlBruto é string para o db->escape
                $respostaXmlStr = is_string($respostaXmlBruto) ? $respostaXmlBruto : json_encode($respostaXmlBruto, JSON_UNESCAPED_UNICODE);

                // Tenta INSERT com todas as colunas (inclui muni_ini/muni_fim que são NOT NULL)
                $sqlInsert = "INSERT INTO {$p}mdfe_emitidas
                    (numero, serie, chave_acesso, protocolo, cnpj_emitente, data_emissao, data_recebimento,
                     status, codigo_status, motivo, uf_ini, uf_fim, mun_carrega, mun_descarga,
                     modal, placa, valor_carga, peso_carga, qtd_cte, qtd_nfe,
                     ambiente, xml_enviado, xml_resposta, xml_mdfe)
                    VALUES (
                        ".intval($nMDF).",
                        ".$db->escape(intval($serie)).",
                        '".$db->escape($chaveAcesso)."',
                        ".($nProtSefaz !== null ? "'".$db->escape($nProtSefaz)."'" : "NULL").",
                        '".$db->escape($cnpjEmitLimpo)."',
                        '".$db->escape($dataEmissao)."',
                        ".($dataRecebimento !== null ? "'".$db->escape($dataRecebimento)."'" : "NULL").",
                        '".$db->escape($statusMDFe)."',
                        ".($cStatSefaz !== null ? intval($cStatSefaz) : "NULL").",
                        ".($xMotivoSefaz !== null ? "'".$db->escape($xMotivoSefaz)."'" : "NULL").",
                        '".$db->escape(GETPOST('UFIni', 'alpha'))."',
                        '".$db->escape(GETPOST('UFFim', 'alpha'))."',

                        '".$db->escape($munCarregaNome)."',
                        '".$db->escape($munDescargaNome)."',
                        ".intval(GETPOST('modal', 'alpha')).",
                        '".$db->escape($placaVeic)."',
                        ".floatval(str_replace(',', '.', GETPOST('vCarga', 'alpha'))).",
                        ".floatval(str_replace(',', '.', GETPOST('qCarga', 'alpha'))).",
                        ".intval($qCTe).",
                        ".intval($qNFe).",
                        ".intval($ambiente).",
                        '".$db->escape($xmlAssinado)."',
                        '".$db->escape($respostaXmlStr)."',
                        '".$db->escape($xml)."'
                    )
                    ON DUPLICATE KEY UPDATE
                        protocolo       = ".($nProtSefaz !== null ? "'".$db->escape($nProtSefaz)."'" : "protocolo").",
                        data_recebimento= ".($dataRecebimento !== null ? "'".$db->escape($dataRecebimento)."'" : "data_recebimento").",
                        status          = '".$db->escape($statusMDFe)."',
                        codigo_status   = ".($cStatSefaz !== null ? intval($cStatSefaz) : "codigo_status").",
                        motivo          = ".($xMotivoSefaz !== null ? "'".$db->escape($xMotivoSefaz)."'" : "motivo").",
                        xml_resposta    = '".$db->escape($respostaXmlStr)."',
                        atualizado_em   = CURRENT_TIMESTAMP";

                error_log("[MDF-e] Tentando INSERT para chave: " . $chaveAcesso . " status: " . $statusMDFe);
                $insertOk = $db->query($sqlInsert);

                // Fallback: se INSERT falhou (ex: colunas novas não existem), tenta com colunas básicas
                if (!$insertOk) {
                    $errInsert = $db->lasterror();
                    error_log("[MDF-e] INSERT completo falhou: " . $errInsert . " — tentando fallback com colunas básicas");

                    $sqlFallback = "INSERT INTO {$p}mdfe_emitidas
                        (numero, serie, chave_acesso, data_emissao, status, uf_ini, uf_fim, ambiente, xml_enviado, xml_resposta, xml_mdfe)
                        VALUES (
                            ".intval($nMDF).",
                            ".$db->escape(intval($serie)).",
                            '".$db->escape($chaveAcesso)."',
                            '".$db->escape($dataEmissao)."',
                            '".$db->escape($statusMDFe)."',
                            '".$db->escape(GETPOST('UFIni', 'alpha'))."',
                            '".$db->escape(GETPOST('UFFim', 'alpha'))."',
                            ".intval($ambiente).",
                            '".$db->escape($xmlAssinado)."',
                            '".$db->escape($respostaXmlStr)."',
                            '".$db->escape($xml)."'
                        )
                        ON DUPLICATE KEY UPDATE
                            status        = '".$db->escape($statusMDFe)."',
                            xml_resposta  = '".$db->escape($respostaXmlStr)."',
                            atualizado_em = CURRENT_TIMESTAMP";
                    $insertOk = $db->query($sqlFallback);
                    if (!$insertOk) {
                        error_log("[MDF-e] INSERT fallback também falhou: " . $db->lasterror());
                    } else {
                        error_log("[MDF-e] INSERT fallback OK. Execute migrate_mdfe.php para atualizar a tabela.");
                    }
                }
            }
            
        } catch (\SoapFault $soapEx) {
            $statusEnvio = 'erro_soap';
            $erroAssinatura = 'Erro de comunicação SOAP: ' . $soapEx->getMessage();
            $detalhesErro = [
                'tipo' => 'SOAP Fault',
                'codigo' => $soapEx->getCode(),
                'mensagem' => $soapEx->getMessage(),
                'faultcode' => $soapEx->faultcode ?? null,
                'faultstring' => $soapEx->faultstring ?? null,
                'detail' => isset($soapEx->detail) ? print_r($soapEx->detail, true) : null
            ];
        } catch (Exception $certEx) {
            $statusEnvio = 'erro_assinatura';
            $erroAssinatura = $certEx->getMessage();
            $detalhesErro = [
                'tipo' => 'Exception',
                'mensagem' => $certEx->getMessage(),
                'linha' => $certEx->getLine()
            ];
        }
    

    // Sempre retorna JSON — nunca renderiza HTML
    if (in_array($statusEnvio, ['erro_assinatura', 'erro_soap'])) {
        retornarErroJson('Erro na assinatura/envio do MDF-e: ' . ($erroAssinatura ?? 'Erro desconhecido'), [
            'detalhes' => $detalhesErro ?? [],
            'respostaXml' => isset($respostaXmlBruto) ? $respostaXmlBruto : null
        ]);
    }

    // Monta retorno de sucesso
    $dadosRetorno = [
        'nMDF'  => $nMDF,
        'serie' => $serie,
        'xmlAssinado' => $xmlAssinado ?? $xml,
    ];

    if ($statusEnvio === 'enviado' && $respostaEnvio) {
        $dadosRetorno['cStat']        = $cStatSefaz ?? ($respostaEnvio->cStat ?? null);
        $dadosRetorno['xMotivo']      = $xMotivoSefaz ?? ($respostaEnvio->xMotivo ?? null);
        $dadosRetorno['protocolo']    = $nProtSefaz ?? null;
        $dadosRetorno['nRec']         = $respostaEnvio->infRec->nRec ?? null;
        $dadosRetorno['chaveAcesso']  = $chaveAcesso;
        $dadosRetorno['statusMDFe']   = $statusMDFe;
        $dadosRetorno['respostaXml']  = $respostaXmlBruto ?? null;
        $dadosRetorno['message'] =
            'Chave: '   . ($chaveAcesso ?: 'N/A') . "\n" .
            'Status: '  . ($cStatSefaz ?? 'N/A') . "\n" .
            'Motivo: '  . ($xMotivoSefaz ?? 'N/A') . "\n" .
            (!empty($nProtSefaz) ? 'Protocolo: ' . $nProtSefaz . "\n" : '') .
            (isset($respostaEnvio->infRec->nRec) ? 'Recibo: ' . $respostaEnvio->infRec->nRec : '');
    } else {
        $dadosRetorno['message'] = 'MDF-e gerado. Status: ' . $statusEnvio;
    }

    retornarSucessoJson($dadosRetorno);

} catch (\Throwable $e) {
    // Captura qualquer erro PHP (Exception, TypeError, Error, etc.)
    // para garantir que sempre retornamos JSON — nunca HTML/texto
    retornarErroJson($e->getMessage(), [
        'file' => basename($e->getFile()),
        'line' => $e->getLine()
    ]);
}
