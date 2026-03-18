<?php
declare(strict_types=1);

// Temporariamente habilitar exibição e log de erros para depuração (remover após diagnóstico)
// ini_set('display_errors', '1');
// ini_set('display_startup_errors', '1');
// error_reporting(E_ALL);
// ini_set('log_errors', '1');
// if (defined('DOL_DOCUMENT_ROOT')) {
//     ini_set('error_log', DOL_DOCUMENT_ROOT . '/custom/nfe/php_errors.log');
// }
require_once DOL_DOCUMENT_ROOT . '/custom/composerlib/vendor/autoload.php';
require_once DOL_DOCUMENT_ROOT . '/custom/nfe/lib/cfop_utils.php';    // Mantido para validações e helpers
require_once DOL_DOCUMENT_ROOT . '/custom/nfe/lib/cfop_builder.php';  // NOVA BIBLIOTECA PRINCIPAL
require_once DOL_DOCUMENT_ROOT . '/custom/nfe/csosn.php'; 
require_once DOL_DOCUMENT_ROOT.'/custom/nfe/lib/nfe_security.lib.php';

if(!function_exists('buscarDadosIbge')){
    dol_include_once('/custom/nfe/lib/ibge_utils.php');
}
use NFePHP\NFe\MakeDev;
use NFePHP\NFe\Tools;
use NFePHP\Common\Certificate;
use NFePHP\NFe\Common\Standardize;
use NFePHP\DA\NFe\Danfe;

if (!function_exists('nfeLog')) {
    function nfeLog(string $level, string $msg, array $ctx = []): void {
        if ($ctx) $msg .= ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        error_log('[NFe][' . strtoupper($level) . '] ' . $msg);
    }
}

function nfeSafeQuery($db, string $sql, string $ctx) {
    $res = $db->query($sql);
    if (!$res) {
        nfeLog('error', 'Falha SQL', ['ctx'=>$ctx,'erro'=>$db->lasterror(),'sql'=>$sql]);
        throw new Exception("Erro de banco em {$ctx}");
    }
    return $res;
}

/* Gera cNF determinístico (8 dígitos) baseado em CNPJ, série e número */
function gerarCodigoNF(string $cnpj, int $serie, int $numero): string {
    $hash = hash('crc32', $cnpj.'|'.$serie.'|'.$numero);
    $num  = str_pad((string)hexdec($hash), 8, '0', STR_PAD_LEFT);
    return substr($num, -8);
}

/**
 * Busca a regra fiscal aplicável para um determinado item da NFe.
 * A função prioriza regras mais específicas (por NCM) sobre as mais genéricas (por CFOP).
 *
 * @param DoliDBMysqli $db Conexão com o banco de dados do Dolibarr.
 * @param array $product Dados do produto da fatura.
 * @param array $emitter Dados do emitente.
 * @param array $recipient Dados do destinatário.
 * @param array $invoice Dados da fatura/operação.
 * @return stdClass|null Objeto com as regras fiscais ou null se não encontrar.
 */
/**
 * Busca a regra fiscal aplicável, usando o CFOP específico do item.
 */
function getTaxRule($db, $product, $emitter, $recipient, $cfopParaBusca) {
    $uf_origin = strtoupper(trim($emitter['uf'] ?? ''));
    $uf_dest = strtoupper(trim($recipient['uf'] ?? ''));
    $ncm_raw = $product['extrafields']['prd_ncm'] ?? '';
    $ncm = preg_replace('/\D/', '', (string)$ncm_raw);
    $ncm = ($ncm === '') ? null : $ncm; // tratar vazio como NULL

    // Vigência: apenas regras ativas e dentro do período
    $vigenciaSql = " AND (date_start IS NULL OR date_start <= CURDATE()) AND (date_end IS NULL OR date_end >= CURDATE()) ";

    // 1) Regra específica por NCM (SOMENTE COM MATCH EM UF ORIGIN + UF DEST)
    if ($ncm !== null) {
        $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "custom_tax_rules2
                WHERE active = 1
                  AND uf_origin = '" . $db->escape($uf_origin) . "'
                  AND uf_dest = '" . $db->escape($uf_dest) . "'
                  AND cfop = '" . $db->escape($cfopParaBusca) . "'
                  AND ncm = '" . $db->escape($ncm) . "'"
                  . $vigenciaSql . "
                LIMIT 1";
        $result = $db->query($sql);
        if ($result && $db->num_rows($result) > 0) {
            $obj = $db->fetch_object($result);
            nfeLog('info', 'Regra fiscal aplicada (específica por NCM e UFs)', [
                'by' => 'ncm_exact',
                'rowid' => $obj->rowid ?? null,
                'cfop' => $obj->cfop ?? $cfopParaBusca,
                'ncm' => $ncm,
                'uf_origin' => $uf_origin,
                'uf_dest' => $uf_dest
            ]);
            return $obj;
        } else {
            nfeLog('debug', 'Nenhuma regra específica por NCM encontrada para as UFs informadas', [
                'ncm' => $ncm,
                'cfop' => $cfopParaBusca,
                'uf_origin' => $uf_origin,
                'uf_dest' => $uf_dest
            ]);
        }
    } else {
        nfeLog('debug', 'Produto sem NCM válido, pulando busca por NCM', [
            'produto_ncm_raw' => $ncm_raw,
            'cfop' => $cfopParaBusca,
            'uf_origin' => $uf_origin,
            'uf_dest' => $uf_dest
        ]);
    }

    // 2) Regra genérica por CFOP (mesmas UFs, sem NCM)
    $sql_generic = "SELECT * FROM " . MAIN_DB_PREFIX . "custom_tax_rules2
                    WHERE active = 1
                      AND uf_origin = '" . $db->escape($uf_origin) . "'
                      AND uf_dest = '" . $db->escape($uf_dest) . "'
                      AND cfop = '" . $db->escape($cfopParaBusca) . "'
                      AND (ncm IS NULL OR ncm = '')"
                      . $vigenciaSql . "
                    LIMIT 1";
    $result_generic = $db->query($sql_generic);
    if ($result_generic && $db->num_rows($result_generic) > 0) {
        $obj = $db->fetch_object($result_generic);
        nfeLog('info', 'Regra fiscal aplicada (genérica por CFOP e UFs)', [
            'by' => 'cfop_generic',
            'rowid' => $obj->rowid ?? null,
            'cfop' => $obj->cfop ?? $cfopParaBusca,
            'uf_origin' => $uf_origin,
            'uf_dest' => $uf_dest
        ]);
        return $obj;
    }

    // 3) Nenhuma regra encontrada
    nfeLog('warning', 'Nenhuma regra fiscal encontrada para CFOP/NCM nas UFs informadas', [
        'cfop' => $cfopParaBusca,
        'ncm' => $ncm,
        'uf_origin' => $uf_origin,
        'uf_dest' => $uf_dest
    ]);
    return null;
}
/**
 * Obtém o próximo número da NF-e e incrementa a sequência.
 *
 * @param DoliDB $db
 * @param string $cnpj
 * @param int $serie
 * @param int|null $numeroCustom
 * @param int $ambiente
 * @return int
 */
function obterNumeroParaNFe($db, $cnpj, $serie, $numeroCustom = null, $ambiente = 2) {
    $amb = (int)$ambiente;

    $db->begin();
    try {
        $sql = "SELECT ultimo_numero FROM ".MAIN_DB_PREFIX."nfe_sequencia
                WHERE cnpj='".$db->escape($cnpj)."' AND serie=".(int)$serie." AND ambiente=".$amb." FOR UPDATE";
        $res = nfeSafeQuery($db, $sql, 'lock_seq');
        if ($db->num_rows($res)) {
            $o = $db->fetch_object($res);
            if ($numeroCustom !== null && $numeroCustom > 0) {
                // Número customizado: usa sem atualizar DB (atualização ocorre pós-autorização)
                $numero = (int)$numeroCustom;
                nfeLog('info','Usando número customizado',['numero'=>$numero,'ambiente'=>$amb]);
            } else {
                $numero = (int)$o->ultimo_numero + 1;
                nfeSafeQuery(
                    $db,
                    "UPDATE ".MAIN_DB_PREFIX."nfe_sequencia SET ultimo_numero=".$numero."
                     WHERE cnpj='".$db->escape($cnpj)."' AND serie=".(int)$serie." AND ambiente=".$amb,
                    'update_seq'
                );
                nfeLog('info','Incrementado número NFe',['numero'=>$numero,'ambiente'=>$amb]);
            }
        } else {
            $numero = ($numeroCustom !== null && $numeroCustom > 0) ? (int)$numeroCustom : 12350;
            nfeSafeQuery(
                $db,
                "INSERT INTO ".MAIN_DB_PREFIX."nfe_sequencia (cnpj, serie, ambiente, ultimo_numero)
                 VALUES ('".$db->escape($cnpj)."', ".(int)$serie.", ".$amb.", ".$numero.")",
                'insert_seq'
            );
            nfeLog('info','Sequência inicializada',['numero'=>$numero,'ambiente'=>$amb]);
        }
        $db->commit();
        return $numero;
    } catch (Exception $e) {
        $db->rollback();
        throw $e;
    }
}



/**
 * Monta o XML da NF-e processada.
 *
 * @param string $xmlAssinado
 * @param string $retorno
 * @return string
 */

/**
 * FUNÇÃO ANTIGA - MANTIDA PARA REFERÊNCIA (NÃO É MAIS UTILIZADA)
 * @deprecated Use determinarCFOP() da biblioteca cfop_utils.php
 * 
 * Determina o CFOP do item com base nas novas regras fiscais do produto.
 */

function montaProcNFe(string $xmlAssinado, string $retorno): string
{
    $domRet = new DOMDocument();
    $domRet->loadXML($retorno);
    $protNFe = $domRet->getElementsByTagName("protNFe")->item(0);

    if (!$protNFe) {
        throw new Exception("Protocolo de autorização não encontrado na resposta da SEFAZ.");
    }

    $domNFe = new DOMDocument();
    $domNFe->loadXML($xmlAssinado);
    $nfe = $domNFe->getElementsByTagName("NFe")->item(0);

    $domProc = new DOMDocument("1.0", "UTF-8");
    $nfeProc = $domProc->createElement("nfeProc");
    $nfeProc->setAttribute("xmlns", "http://www.portalfiscal.inf.br/nfe");
    $nfeProc->setAttribute("versao", "4.00");

    $nfeProc->appendChild($domProc->importNode($nfe, true));
    $nfeProc->appendChild($domProc->importNode($protNFe, true));
    $domProc->appendChild($nfeProc);

    return $domProc->saveXML();
}
/**
 * Converte um valor para float, tratando formatos de moeda.
 *
 * @param mixed $val O valor a ser convertido.
 * @return float O valor convertido para float.
 */
function toFloat($val) {
    $v = trim((string)$val);
    if ($v === '') return 0.0;
    if (strpos($v, ',') !== false && strpos($v, '.') !== false) {
        $v = str_replace('.', '', $v);
        $v = str_replace(',', '.', $v);
    } else {
        $v = str_replace(',', '.', $v);
    }
    return (float)$v;
}

/**
 * Função principal para gerar a NF-e.
 *
 * @param DoliDB $db
 * @param array $mysoc Dados da sua empresa.
 * @param array $dest Dados do destinatário.
 * @param array $products Array de produtos.
 * @param array $fatura Dados da fatura.
 */
function gerarNfe($db, $mysoc, $dest, $products, $fatura, $numeroCustom = null) {
    // Log dedicado para debug em produção
    $nfeDebugLog = sys_get_temp_dir() . '/nfe_debug_' . date('Y-m-d') . '.log';
    $logNfe = function(string $msg) use ($nfeDebugLog) {
        @file_put_contents($nfeDebugLog, '[' . date('H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
    };
    $logNfe('========== INICIO gerarNfe ==========');
    $logNfe('PHP: ' . phpversion() . ' | Memory: ' . memory_get_usage(true) . ' | PID: ' . getmypid());

    // Shutdown handler para capturar erros fatais que escapam de try-catch
    register_shutdown_function(function() use ($nfeDebugLog) {
        $err = error_get_last();
        if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            @file_put_contents($nfeDebugLog,
                '[' . date('H:i:s') . '] *** FATAL ERROR ***'
                . ' Type=' . $err['type']
                . ' Msg=' . $err['message']
                . ' File=' . $err['file'] . ':' . $err['line'] . "\n",
                FILE_APPEND
            );
        }
    });

    // Normalização e ambiente
    if (!isset($products[0]) || !is_array($products[0])) $products = [$products];
    date_default_timezone_set('America/Sao_Paulo');    
    ini_set('display_errors','0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_WARNING & ~E_DEPRECATED & ~E_USER_DEPRECATED);
    //nfeLog('debug','Produtos carregados',['qtd'=>count($products)]);
    
    $schema = 'PL_010_V1';
    $stdIde = null;
    $nfe = null;

    try {
        $logNfe('CHECKPOINT 1: Criando MakeDev');
        $nfe = new MakeDev($schema);
        $nfe->setOnlyAscii(false);
        $nfe->setCheckGtin(false);
        $logNfe('CHECKPOINT 2: MakeDev criado OK');
        
        // Carrega configurações do banco de dados
        $sql_config = "SELECT name, value FROM ".MAIN_DB_PREFIX."nfe_config";
        $res_config = $db->query($sql_config);
        $nfe_configs = array();
        if ($res_config) {
            while ($obj_config = $db->fetch_object($res_config)) {
                $nfe_configs[$obj_config->name] = $obj_config->value;
            }
        }

        $arr = [
        "atualizacao" => "2025-11-13 09:01:21",
        "tpAmb"       => (int)($nfe_configs['ambiente'] ?? 2), // 1=Produção, 2=Homologação
        "razaosocial" => $mysoc['nome'],
        "cnpj"        => $mysoc['cnpj'],
        "siglaUF"     => $mysoc['uf'],
        "schemes"     => "PL_010_V1",
        "versao"      => '4.00',
        "tokenIBPT"   => "AAAAAAA",
        "CSC"         => "GPB0JBWLUR6HWFTVEAS6RJ69GPCROFPBBB8G",
        "CSCid"       => "000001",
        "proxyConf"   => [
            "proxyIp"   => "",
            "proxyPort" => "",
            "proxyUser" => "",
            "proxyPass" => ""
            ]
        ];

        $cfg_json = json_encode($arr);
        $pfxContent = $nfe_configs['cert_pfx'];
        // Removida a decodificação Base64. Lendo a senha em texto plano.
        $password = $nfe_configs['cert_pass'];
        $senhaOriginal = nfeDecryptPassword($password, $db);
        //$configJson = $nfe_configs['config_json'] ?? '';
        $ambiente = $nfe_configs['ambiente'] ?? '2'; // Pega o ambiente do banco
        
        if (empty($pfxContent) || empty($cfg_json)) {
            throw new \Exception('Erro: Certificado ou configuração JSON não encontrados no banco de dados. Configure o módulo na área de administração.');
        }

        $logNfe('CHECKPOINT 3: Lendo certificado PFX (tamanho=' . strlen($pfxContent) . ')');
        $certificate = Certificate::readPfx($pfxContent, $senhaOriginal);
        $logNfe('CHECKPOINT 4: Certificado OK, criando Tools');
        $tools = new Tools($cfg_json, $certificate);
        $tools->model(55);
        
        $std = new stdClass();
        $std->versao = '4.00';
        $std->Id = '';
        //$std->pk_nItem = null; para evitar possível interferência
        $nfe->taginfNFe($std);

        $logNfe('CHECKPOINT 5: Tags iniciais OK, buscando IBGE');
        $isDevolucao = !empty($fatura['extrafields']['is_devolucao']);
        $logNfe('CHECKPOINT 5a: cidade=' . ($mysoc['cidade'] ?? 'NULL') . ' uf=' . ($mysoc['uf'] ?? 'NULL'));
        
        $dadosIbgeMysoc = buscarDadosIbge($db, (string)($mysoc['cidade'] ?? ''), (string)($mysoc['uf'] ?? ''));        
        $stdIde = new stdClass();
        $stdIde->cUF = $dadosIbgeMysoc->codigo_uf;
        $stdIde->mod = 55;
        $stdIde->serie = 1;
        $stdIde->nNF = obterNumeroParaNFe($db, $mysoc['cnpj'], $stdIde->serie, $numeroCustom, (int)$ambiente);
        $stdIde->cNF = gerarCodigoNF($mysoc['cnpj'], (int)$stdIde->serie, (int)$stdIde->nNF); // substitui rand
        $stdIde->natOp = $fatura['extrafields']['nat_op'] ?? 'Venda de Mercadoria'; // Ajuste para permitir devolução
        $stdIde->dhEmi = date('Y-m-d\TH:i:sP');
        $stdIde->dhSaiEnt = date('Y-m-d\TH:i:sP');
        $stdIde->tpNF = $isDevolucao ? '0' : '1';
        $paisDest = strtoupper($dest['pais'] ?? '');
        $ufDest = strtoupper($dest['uf'] ?? '');
        $ufEmit = strtoupper($mysoc['uf'] ?? '');
        if ($paisDest && $paisDest !== 'BRASIL') {
            $idDest = '3';
        } elseif ($ufDest && $ufEmit && $ufDest !== $ufEmit) {
            $idDest = '2';
        } else {
            $idDest = '1';
        }
        
        $stdIde->idDest = $idDest;
        $stdIde->cMunFG = $dadosIbgeMysoc->codigo_ibge;
        $stdIde->tpImp = 1;
        $stdIde->tpEmis = 1;
        $stdIde->cDV = 0;
        $stdIde->tpAmb = $ambiente;
        $stdIde->finNFe = $isDevolucao ? 4 : 1; // 4 = Devolução
        $stdIde->indFinal = 1;
        $stdIde->indPres = $fatura['extrafields']['indpres'];
        $stdIde->procEmi = 0;
        $stdIde->verProc = '3.10.13';
        $nfe->tagide($stdIde);
        
        $stdEmit = new stdClass();
        $stdEmit->CNPJ = $mysoc['cnpj'];
        $stdEmit->xNome = $mysoc['nome'];
        $stdEmit->xFant = $mysoc['nfant'];
        $stdEmit->IM = $mysoc['im']; 
        $stdEmit->CRT = $mysoc['crt'];
        $stdEmit->IE = $mysoc['ie'];
        $nfe->tagemit($stdEmit);

        $enderEmit = new stdClass();
        $enderEmit->xLgr = $mysoc['rua'];
        $enderEmit->nro = $mysoc['numero'];
        $enderEmit->xBairro = $mysoc['bairro'];
        $enderEmit->cMun = $dadosIbgeMysoc->codigo_ibge;
        $enderEmit->xMun = $mysoc['cidade'];
        $enderEmit->UF = $mysoc['uf'];
        $enderEmit->CEP = $mysoc['cep'];
        $enderEmit->cPais = 1058;
        $enderEmit->xPais = $mysoc['pais'];
        $enderEmit->fone = $mysoc['telefone']; 
        $nfe->tagenderEmit($enderEmit);
        
        // Normaliza e valida endereço do destinatário
        $destEndereco = array(
            'rua' => $dest['extrafields']['rua'] ?? $dest['rua'] ?? null,
            'numero' => $dest['extrafields']['numero_de_endereco'] ?? $dest['numero_de_endereco'] ?? $dest['numero'] ?? null,
            'bairro' => $dest['extrafields']['bairro'] ?? $dest['bairro'] ?? null,
            'cidade' => $dest['cidade'] ?? null,
            'cod_municipio' => $dest['extrafields']['codigo_do_municipio'] ?? $dest['codigo_do_municipio'] ?? null,
            'uf' => $dest['uf'] ?? null,
            'cep' => $dest['cep'] ?? null,
            'pais' => $dest['pais'] ?? 'Brasil',
            'fone' => $dest['fone'] ?? null,
            'indiedest' => $dest['extrafields']['indiedest'] ?? '9',
            'ie' => isset($dest['ie']) ? preg_replace('/\D/','',$dest['ie']) : null
        );

        // Validação mínima para evitar rejeição de tags obrigatórias
        foreach (['rua'=>'xLgr','numero'=>'nro','bairro'=>'xBairro','cidade'=>'xMun','uf'=>'UF','cep'=>'CEP'] as $k=>$tag) {
            if (empty($destEndereco[$k])) {
                error_log("ERRO DEVOLUCAO - Campo obrigatório ausente no endereço do destinatário: {$k} (tag {$tag})");
                throw new Exception("Endereço do destinatário incompleto (faltando {$k}).");
            }
        }

        $stdDest = new stdClass();
        $stdDest->CNPJ = $dest['cnpj'];
        $stdDest->xNome = $dest['nome'];
        $stdDest->indIEDest = $destEndereco['indiedest'];
        if (!empty($destEndereco['ie'])) $stdDest->IE = $destEndereco['ie'];
        $nfe->tagdest($stdDest);
        $logNfe('CHECKPOINT 6: Emit+Dest OK, buscando IBGE dest: cidade=' . ($destEndereco['cidade'] ?? 'NULL') . ' uf=' . ($destEndereco['uf'] ?? 'NULL'));
        $dadosIbgeDest = buscarDadosIbge($db, (string)($destEndereco['cidade'] ?? ''), (string)($destEndereco['uf'] ?? ''));
        $stdenderDest = new stdClass();
        $stdenderDest->xLgr = $destEndereco['rua'];
        $stdenderDest->nro = $destEndereco['numero'];
        $stdenderDest->xBairro = $destEndereco['bairro'];

        $stdenderDest->cMun = $dadosIbgeDest->codigo_ibge;
        $stdenderDest->xMun = $destEndereco['cidade'];
        $stdenderDest->UF = $destEndereco['uf'];
        $stdenderDest->CEP = $destEndereco['cep'];
        $stdenderDest->cPais = '1058';
        $stdenderDest->xPais = $destEndereco['pais'];
        if (!empty($destEndereco['fone'])) $stdenderDest->fone = $destEndereco['fone'];
        $nfe->tagenderDest($stdenderDest);

        $totalProdutos = 0.00;
        $totalPis = 0.00;
        $totalCofins = 0.00;
        $itemCounter = 1;
        $totalICMSCred = 0.00;
        $totalBCST = 0.00;
        $totalICMSST = 0.00;
        
        // NOVO: Separar ICMS de desoneração (vICMS) do crédito de ICMS (vCredICMSSN)
        $totalICMSDeson = 0.00;  // Para casos de desoneração (não é o caso do Simples)
        $totalVCredICMSSN = 0.00; // Total de crédito de ICMS (CSOSN 101/201)

        // Mapeamento CFOP saída -> devolução entrada
        $cfopMapDevolucao = [
            '5102' => '1202',
            '6102' => '2202',
            '5403' => '1410',
            '6403' => '2410',
            '5405' => '1411',
            '6405' => '2411'
        ];

        // Mapeamento adicional: CFOP de entrada (devolução) -> CFOP de saída (para fallback de regra fiscal)
        $cfopEntradaParaSaida = [
            '1202' => '5102',
            '2202' => '6102',
            '1410' => '5403',
            '2410' => '6403',
            '1411' => '5405',
            '2411' => '6405'
        ];
        
        // NOVO: Busca alíquota de permissão de crédito do mês atual
        $mesReferencia = date('Y-m');
        $sqlAliqCred = "SELECT aliq_cred_perm FROM " . MAIN_DB_PREFIX . "nfe_perm_credito 
                        WHERE mes_referencia = '" . $db->escape($mesReferencia) . "' LIMIT 1";
        $resAliqCred = $db->query($sqlAliqCred);
        
        $aliqCredPermissao = 0.00; // Fallback padrão
        if ($resAliqCred && $db->num_rows($resAliqCred) > 0) {
            $objAliqCred = $db->fetch_object($resAliqCred);
            $aliqCredPermissao = (float)$objAliqCred->aliq_cred_perm;
            nfeLog('info', 'Alíquota de permissão de crédito carregada do banco', [
                'mes_referencia' => $mesReferencia,
                'aliquota' => $aliqCredPermissao
            ]);
        } else {
            nfeLog('warning', 'Alíquota de permissão de crédito não encontrada para o mês', [
                'mes_referencia' => $mesReferencia,
                'fallback' => $aliqCredPermissao
            ]);
        }

        $logNfe('CHECKPOINT 7: Iniciando loop de ' . count($products) . ' produto(s)');

        foreach ($products as $product) {

            // ========== CARREGAMENTO EXPLÍCITO DOS EXTRAFIELDS DO PRODUTO ==========
            // CRÍTICO: Garante que todos os extrafields estejam disponíveis
            if (!isset($product['extrafields']) || !is_array($product['extrafields']) || empty($product['extrafields'])) {
                // Se extrafields não existe ou está incompleto, carrega do banco
                require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
                $productObj = new Product($db);
                if ($productObj->fetch($product['rowid'] ?? $product['id'] ?? 0) > 0) {
                    $productObj->fetch_optionals(); // Força reload dos extrafields
                    
                    // CORREÇÃO CRÍTICA: Mescla array_options (que contém options_xxx) com extrafields
                    $product['extrafields'] = array_merge(
                        $product['extrafields'] ?? [],
                        $productObj->array_options ?? []
                    );
                    
                    // ADICIONAL: Remove prefixo 'options_' para acesso direto
                    // Cria cópias sem prefixo para compatibilidade
                    if (isset($productObj->array_options) && is_array($productObj->array_options)) {
                        foreach ($productObj->array_options as $key => $value) {
                            // Se chave começa com 'options_', adiciona versão sem prefixo
                            if (strpos($key, 'options_') === 0) {
                                $cleanKey = substr($key, 8); // remove 'options_'
                                if (!isset($product['extrafields'][$cleanKey])) {
                                    $product['extrafields'][$cleanKey] = $value;
                                }
                            }
                        }
                    }

                } else {
                    nfeLog('error', 'Falha ao carregar extrafields do produto', [
                        'produto' => $product['ref'] ?? 'N/A',
                        'rowid' => $product['rowid'] ?? $product['id'] ?? 'N/A'
                    ]);
                }
            }
            
            // ========== LOG DE DEBUG (REMOVER APÓS CORRIGIR) ==========
            nfeLog('debug', 'Extrafields disponíveis antes de montarCFOP', [
                'produto' => $product['ref'] ?? 'N/A',
                'extrafields_keys' => array_keys($product['extrafields'] ?? []),
                'prd_fornecimento' => $product['extrafields']['prd_fornecimento'] ?? 'AUSENTE',
                'prd_nat_fornecimento' => $product['extrafields']['prd_nat_fornecimento'] ?? 'AUSENTE',
                'options_prd_nat_fornecimento' => $product['extrafields']['options_prd_nat_fornecimento'] ?? 'AUSENTE',
                'prd_origem' => $product['extrafields']['prd_origem'] ?? 'AUSENTE',
                'options_prd_origem' => $product['extrafields']['options_prd_origem'] ?? 'AUSENTE',
                'prd_regime_icms' => $product['extrafields']['prd_regime_icms'] ?? 'AUSENTE',
                'observacao' => 'Valores 1-based: prd_fornecimento(1=Própria,2=Terceiros), prd_origem(1-9 = SEFAZ 0-8)'
            ]);

            $regimeICMS = $product['extrafields']['prd_regime_icms'] ?? '1';
            $origemProduto = (int)(($product['extrafields']['prd_origem'] ?? 1) - 1);

            // ========== USA A NOVA FUNÇÃO DA BIBLIOTECA cfop_builder.php ==========
            $tipoOperacao = $isDevolucao ? 'devolucao' : 'venda';
            
            // Array de trace (opcional, para debug)
            $traceCFOP = [];
            
            try {
                // Chama a função da biblioteca cfop_builder.php
                $cfopDoItem = montarCFOP(
                    $product,           // Dados do produto
                    $mysoc,             // Dados do emitente
                    $dest,              // Dados do destinatário
                    ['tipo' => $tipoOperacao], // Tipo de operação
                    $traceCFOP          // Trace opcional (referência)
                );
                
                nfeLog('info', '✅ CFOP montado pela biblioteca cfop_builder.php', [
                    'cfop' => $cfopDoItem,
                    'produto' => $product['ref'] ?? 'N/A',
                    'tipo_operacao' => $tipoOperacao,
                    'trace' => $traceCFOP // Log do trace para auditoria
                ]);
                
            } catch (\Throwable $e) {
                nfeLog('error', 'Erro na biblioteca CFOP Builder', [
                    'erro' => $e->getMessage(),
                    'produto' => $product['ref'] ?? 'N/A'
                ]);
                throw $e; // Propaga - CFOP é obrigatório
            }
            
            // ========== VALIDAÇÃO DO CFOP CONTRA LISTA OFICIAL DO ES ==========
            // (usa obterInfoCFOP de cfop_utils)
            $infoCfop = obterInfoCFOP($cfopDoItem);
            if (!$infoCfop['valido']) {
                nfeLog('error', '❌ CFOP inválido detectado', [
                    'cfop' => $cfopDoItem,
                    'produto' => $product['ref'] ?? 'N/A',
                    'descricao' => $infoCfop['descricao']
                ]);
                throw new Exception("CFOP {$cfopDoItem} inválido para o produto {$product['ref']}");
            }
            
            // Aviso se CFOP não está na lista oficial do ES
            if (strpos($infoCfop['descricao'], '⚠️') !== false) {
                nfeLog('warning', '⚠️ CFOP não consta na lista oficial do ES', [
                    'cfop' => $cfopDoItem,
                    'produto' => $product['ref'] ?? 'N/A',
                    'aviso' => $infoCfop['descricao']
                ]);
            }

    // ========== BUSCA DE REGRA FISCAL (PULA EM DEVOLUÇÕES) ==========
    $taxRule = null;
    
    if ($isDevolucao) {
        // Para devoluções, usa tributos da nota original (passados via extrafields)
        nfeLog('info', '📥 Devolução: usando tributos da nota original', [
            'cfop' => $cfopDoItem,
            'produto' => $product['ref'] ?? 'N/A'
        ]);
        
        // Extrai tributos do extrafields (populados por devolucao_nfe.php)
        $taxRule = (object)[
            'rowid' => null,
            'label' => 'Devolução (tributos da nota original)',
            'icms_cred_aliq' => (float)($product['extrafields']['icms_cred_aliq'] ?? 0),
            'icms_st_mva' => (float)($product['extrafields']['icms_st_mva'] ?? 0),
            'icms_st_aliq' => (float)($product['extrafields']['icms_st_aliq'] ?? 0),
            'icms_st_red_bc' => (float)($product['extrafields']['icms_st_red_bc'] ?? 0),
            'icms_aliq_interestadual' => (float)($product['extrafields']['icms_aliq_inter'] ?? 0),
            'pis_cst' => $product['extrafields']['pis_cst'] ?? '49',
            'pis_aliq' => (float)($product['extrafields']['pis_aliq'] ?? 0),
            'cofins_cst' => $product['extrafields']['cofins_cst'] ?? '49',
            'cofins_aliq' => (float)($product['extrafields']['cofins_aliq'] ?? 0)
        ];
    } else {
        // 1. Busca a regra fiscal (APENAS para vendas/saídas)
        $taxRule = getTaxRule($db, $product, $mysoc, $dest, $cfopDoItem);
        
        if (!$taxRule) {
            $erro = "Regra fiscal não encontrada para CFOP {$cfopDoItem}, UF origem: {$mysoc['uf']}, UF destino: {$dest['uf']}";
            nfeLog('error', $erro, [
                'produto' => $product['ref'] ?? 'N/A',
                'ncm' => $product['extrafields']['prd_ncm'] ?? 'N/A'
            ]);
            throw new Exception($erro);
        }
    }
    
    // 2. CSOSN: Se devolução, usa original; senão, calcula dinamicamente
    if ($isDevolucao && !empty($product['extrafields']['csosn_original'])) {
        // USA CSOSN ORIGINAL DA NOTA DE SAÍDA
        $csosnCalculado = (string)$product['extrafields']['csosn_original'];
        nfeLog('info', '📥 CSOSN preservado da nota original', [
            'csosn' => $csosnCalculado,
            'produto' => $product['ref'] ?? 'N/A'
        ]);
    } else {
        // Calcula CSOSN dinamicamente para vendas/saídas
        try {
            $csosnCalculado = getCsosn(
                $mysoc,
                $dest,
                $regimeICMS,
                null
            );
        } catch (Exception $e) {
            nfeLog('error', 'Erro ao calcular CSOSN: ' . $e->getMessage(), [
                'produto' => $product['ref'] ?? 'N/A',
                'regime' => $regimeICMS
            ]);
            throw $e;
        }
    }

    // Preenchimento da tag <prod>
    $qCom = toFloat($product['quantidade'] ?? 0);
    $vUnCom = toFloat($product['preco_venda_semtaxa'] ?? 0);
    $vProd = toFloat($product['total_semtaxa'] ?? ($qCom * $vUnCom));

    // NFe não aceita valores negativos (mesmo em devolução)
    // Devolução = tpNF=0 + finNFe=4 + valores POSITIVOS
    $qCom = abs($qCom);
    $vUnCom = abs($vUnCom);
    $vProd = abs($vProd);
    
    if ($isDevolucao && ($qCom <= 0 || $vUnCom <= 0 || $vProd <= 0)) {
        nfeLog('warning', '⚠️ Valores zerados/negativos em devolução detectados e corrigidos', [
            'produto' => $product['ref'] ?? 'N/A',
            'qCom_original' => $product['quantidade'] ?? 0,
            'vUnCom_original' => $product['preco_venda_semtaxa'] ?? 0,
            'vProd_original' => $product['total_semtaxa'] ?? 0,
            'qCom_corrigido' => $qCom,
            'vUnCom_corrigido' => $vUnCom,
            'vProd_corrigido' => $vProd
        ]);
    }

    $stdProd = new stdClass();
    $stdProd->item = $itemCounter;
    $stdProd->cProd = $product['ref'];
    $stdProd->xProd = $product['nome'];
    $stdProd->NCM = preg_replace('/[^0-9]/', '', $product['extrafields']['prd_ncm'] ?? '00000000');
    $stdProd->CFOP = $cfopDoItem;
    $stdProd->cEAN = 'SEM GTIN';
    $stdProd->CEST = $product['extrafields']['prd_cest'] ?? '';
    $stdProd->uCom = 'UN';
    $stdProd->qCom = number_format($qCom, 4, '.', '');
    $stdProd->vUnCom = number_format($vUnCom, 2, '.', '');
    $stdProd->vProd = number_format($vProd, 2, '.', '');
    $stdProd->cEANTrib = 'SEM GTIN';
    $stdProd->uTrib = 'UN';
    $stdProd->qTrib = number_format($qCom, 4, '.', '');
    $stdProd->vUnTrib = number_format($vUnCom, 2, '.', '');
    $stdProd->indTot = 1;
    $nfe->tagprod($stdProd);

    // Preenchimento da tag <imposto>
    $nfe->tagimposto((object) ['item' => $itemCounter]);

    // Cálculo de ICMS
    $stdICMS = new stdClass();
    $stdICMS->item = $itemCounter;
    $stdICMS->orig = $origemProduto;
    $stdICMS->CSOSN = $csosnCalculado;
    $vCredICMSSN = 0;
    $vBCST = 0;
    $vICMSST = 0;

    // CSOSN 101: Tributada com permissão de crédito
    if ($stdICMS->CSOSN == '101') {
        // ALTERADO: Usa alíquota do banco ao invés da regra fiscal
        $pCredSN = $aliqCredPermissao;
        $stdICMS->pCredSN = number_format($pCredSN, 2, '.', '');
        $vCredICMSSN = $vProd * ($pCredSN / 100);
        $stdICMS->vCredICMSSN = number_format($vCredICMSSN, 2, '.', '');
        $nfe->tagICMSSN($stdICMS);
    }

    // CSOSN 201 ou 202: Tributada com ST (SOMENTE PARA CONTRIBUINTES)
    elseif (in_array($stdICMS->CSOSN, ['201', '202'])) {
        // Calcula ST para contribuinte
        $pMVAST = (float)($taxRule->icms_st_mva ?? 0);
        $pICMSST = (float)($taxRule->icms_st_aliq ?? 0);
        $pRedBCST = (float)($taxRule->icms_st_red_bc ?? 0);

        $baseCalculoInicial = $vProd;

        // PASSO 1: BC do ST = Valor Produto * (1 + MVA)
        $vBCST_raw = $baseCalculoInicial * (1 + ($pMVAST / 100));
        
        // PASSO 2: Aplica redução de BC (se houver)
        if ($pRedBCST > 0) {
            $vBCST_raw *= (1 - ($pRedBCST / 100));
        }

        // PASSO 3: Calcula ICMS próprio (interestadual)
        $pICMSInter = (float)($taxRule->icms_aliq_interestadual ?? 0);
        $vICMSProprio = $baseCalculoInicial * ($pICMSInter / 100);
        
        // PASSO 4: ICMS-ST = (BC ST * Alíq ST) - ICMS Próprio
        $vICMSST_raw = ($vBCST_raw * ($pICMSST / 100)) - $vICMSProprio;

        // PASSO 5: Garante que ST não seja negativo
        $vBCST = round($vBCST_raw, 2);
        $vICMSST = ($vICMSST_raw < 0) ? 0 : round($vICMSST_raw, 2);

        $stdICMS->modBCST = 4;
        $stdICMS->pMVAST = number_format($pMVAST, 4, '.', '');
        $stdICMS->vBCST = number_format($vBCST, 2, '.', '');
        $stdICMS->pICMSST = number_format($pICMSST, 2, '.', '');
        $stdICMS->vICMSST = number_format($vICMSST, 2, '.', '');

        // CSOSN 201 também tem crédito
        if ($stdICMS->CSOSN == '201') {
            // ALTERADO: Usa alíquota do banco ao invés da regra fiscal
            $pCredSN = $aliqCredPermissao;
            $stdICMS->pCredSN = number_format($pCredSN, 2, '.', '');
            $vCredICMSSN = $vProd * ($pCredSN / 100);
            $stdICMS->vCredICMSSN = number_format($vCredICMSSN, 2, '.', '');
            $std = new stdClass();
            $std->infAdFisco = 'Permite aproveitamento de crédito conforme Lei 123/2006 no percentual de '.$pCredSN.'% que equivale a R$'.number_format($vProd * ($pCredSN / 100), 2, ',', '').'.';
            //$std->infCpl = 'informacoes complementares';
            $nfe->taginfAdic($std);
        }
        
        $nfe->tagICMSSN($stdICMS);
    }
    
    // CSOSN 203: Isento COM ST (caso raríssimo)
    elseif ($stdICMS->CSOSN == '203') {
        // Calcula ST mesmo sendo isento (caso específico previsto na legislação)
        $pMVAST = (float)($taxRule->icms_st_mva ?? 0);
        $pICMSST = (float)($taxRule->icms_st_aliq ?? 0);
        
        $vBCST_raw = $vProd * (1 + ($pMVAST / 100));
        $vICMSST_raw = $vBCST_raw * ($pICMSST / 100);
        
        $vBCST = round($vBCST_raw, 2);
        $vICMSST = round($vICMSST_raw, 2);
        
        $stdICMS->modBCST = 4;
        $stdICMS->pMVAST = number_format($pMVAST, 4, '.', '');
        $stdICMS->vBCST = number_format($vBCST, 2, '.', '');
        $stdICMS->pICMSST = number_format($pICMSST, 2, '.', '');
        $stdICMS->vICMSST = number_format($vICMSST, 2, '.', '');
        
        $nfe->tagICMSSN($stdICMS);
    }
    
    // TODOS OS DEMAIS CSOSN (102, 103, 300, 400, 500, 900)
    else {
        $nfe->tagICMSSN($stdICMS);
    }

    // ===== PIS / COFINS (corrigido para usar floats reais) =====
    $pisAliq = (float)($taxRule->pis_aliq ?? 0);
    $cofinsAliq = (float)($taxRule->cofins_aliq ?? 0);
    $vPisFloat = $vProd * ($pisAliq / 100);
    $vCofinsFloat = $vProd * ($cofinsAliq / 100);

    // PIS
    $stdPIS = new stdClass();
    $stdPIS->item = $itemCounter;
    $stdPIS->CST = $taxRule->pis_cst ?? '49';
    $stdPIS->vBC = number_format($vProd, 2, '.', '');
    $stdPIS->pPIS = number_format($pisAliq, 2, '.', '');
    $stdPIS->vPIS = number_format($vPisFloat, 2, '.', '');
    $nfe->tagPIS($stdPIS);

    // COFINS
    $stdCOFINS = new stdClass();
    $stdCOFINS->item = $itemCounter;
    $stdCOFINS->CST = $taxRule->cofins_cst ?? '49';
    $stdCOFINS->vBC = number_format($vProd, 2, '.', ''); // Descomentado: Obrigatório se tem pCOFINS
    $stdCOFINS->pCOFINS = number_format($cofinsAliq, 2, '.', '');
    $stdCOFINS->vCOFINS = number_format($vCofinsFloat, 2, '.', '');
    $nfe->tagCOFINS($stdCOFINS);

    // IBS CBS
    $stdIBSCBS = new stdClass();
    $stdIBSCBS->item = $itemCounter;
    $stdIBSCBS->CST = '000'; // Ex: '000' (Tributação integral), '400' (Isenção)
    $stdIBSCBS->cClassTrib = '000001'; // Código de Classificação Tributária
    $stdIBSCBS->indDoacao = null; // null ou 1
    $stdIBSCBS->vBC = number_format($vProd, 2, '.', '');
    
    $aliqIbsUf = 0.1;
    $vIbsUf = $vProd * ($aliqIbsUf / 100);
    $stdIBSCBS->gIBSUF_pIBSUF = $aliqIbsUf;
    $stdIBSCBS->gIBSUF_vIBSUF = $vIbsUf; // Obrigatório

    $aliqIbsMun = 0;
    $vIbsMun = $vProd * ($aliqIbsMun / 100);
    $stdIBSCBS->gIBSMun_pIBSMun = $aliqIbsMun;       
    $stdIBSCBS->gIBSMun_vIBSMun = $vIbsMun; // Obrigatório

    $aliqCbs = 0.90;
    $vCbs = $vProd * ($aliqCbs / 100);
    $stdIBSCBS->gCBS_pCBS = $aliqCbs;
    $stdIBSCBS->gCBS_vCBS = $vCbs; // Obrigatório

    $nfe->tagIBSCBS($stdIBSCBS);

    // [NOVO] IBS e CBS (Reforma Tributária - NT 2025.002)
    // Implementação da tagIBSCBS para tributação geral.
    // Os campos ibs_cst, ibs_aliq_uf, ibs_aliq_mun e cbs_aliq devem estar disponíveis na regra fiscal ($taxRule).
    // if (!empty($taxRule->ibs_cst)) {
    //     $stdIBSCBSTot = new stdClass();
    //     $stdIBSCBSTot->item = $itemCounter;
    //     $stdIBSCBSTot->CST = $taxRule->ibs_cst; // Ex: '000' (Tributação integral), '400' (Isenção)
    //     $stdIBSCBSTot->cClassTrib = $taxRule->ibs_c_class_trib ?? '111111'; // Código de Classificação Tributária
    //     $stdIBSCBSTot->indDoacao = null; // null ou 1

    //     // Base de Cálculo (Geralmente o valor do produto)
    //     $stdIBSCBSTot->vBC = number_format($vProd, 2, '.', '');

    //     // IBS - Estado (UF)
    //     $aliqIbsUf = (float)($taxRule->ibs_aliq_uf ?? 0);
    //     $stdIBSCBSTot->gIBSUF_pIBSUF = number_format($aliqIbsUf, 4, '.', '');
    //     // O valor vIBSUF pode ser calculado automaticamente pela biblioteca se omitido, 
    //     // mas se precisar forçar: $stdIBSCBS->gIBSUF_vIBSUF = number_format($vProd * ($aliqIbsUf / 100), 2, '.', '');

    //     // IBS - Município
    //     $aliqIbsMun = (float)($taxRule->ibs_aliq_mun ?? 0);
    //     $stdIBSCBSTot->gIBSMun_pIBSMun = number_format($aliqIbsMun, 4, '.', '');
            
    //     // CBS - Federal
    //     $aliqCbs = (float)($taxRule->cbs_aliq ?? 0);
    //     $stdIBSCBSTot->gCBS_pCBS = number_format($aliqCbs, 4, '.', '');

    //     // Verifica se a biblioteca NFePHP suporta o método (versão atualizada)
    //     if (method_exists($nfe, 'tagIBSCBS')) {
    //         $nfe->tagIBSCBS($stdIBSCBSTot);
    //     }
    // }
        
        
        
       
        // ========== LOG COMPLETO DO ITEM ==========
        nfeLog('info', "📦 ITEM #{$itemCounter} processado e incluído na NFe", [
            'item' => $itemCounter,
            'produto' => [
                'ref' => $product['ref'] ?? 'N/A',
                'nome' => $product['nome'] ?? 'N/A',
                'ncm' => $stdProd->NCM,
                'cest' => $stdProd->CEST,
                'quantidade' => $stdProd->qCom,
                'valor_unitario' => $stdProd->vUnCom,
                'valor_total' => $stdProd->vProd
            ],
            'fiscal' => [
                'cfop' => $cfopDoItem,
                'origem' => $origemProduto,
                'csosn' => $csosnCalculado,
                'regime_icms' => $regimeICMS
            ],
            'icms' => [
                'csosn' => $stdICMS->CSOSN,
                'origem' => $stdICMS->orig,
                'aliq_credito' => isset($stdICMS->pCredSN) ? $stdICMS->pCredSN : '0.00',
                'valor_credito' => number_format($vCredICMSSN, 2, '.', ''),
                'mva_st' => isset($stdICMS->pMVAST) ? $stdICMS->pMVAST : '0.0000',
                'bc_st' => number_format($vBCST, 2, '.', ''),
                'aliq_st' => isset($stdICMS->pICMSST) ? $stdICMS->pICMSST : '0.00',
                'valor_st' => number_format($vICMSST, 2, '.', '')
            ],
            'pis' => [
                'cst' => $stdPIS->CST,
                'bc' => $stdPIS->vBC,
                'aliquota' => $stdPIS->pPIS,
                'valor' => $stdPIS->vPIS
            ],
            'cofins' => [
                'cst' => $stdCOFINS->CST,
                'bc' => $stdCOFINS->vBC,
                'aliquota' => $stdCOFINS->pCOFINS,
                'valor' => $stdCOFINS->vCOFINS
            ],
            'regra_fiscal' => [
                'id' => $taxRule->rowid ?? $taxRule->id ?? 'N/A',
                'label' => $taxRule->label ?? 'N/A',
                'tipo' => $isDevolucao ? 'devolucao_sem_busca' : 'venda_com_busca'
            ]
        ]);

        // Acúmulos (usando floats)
        $totalProdutos += $vProd;
        $totalPis      += $vPisFloat;
        $totalCofins   += $vCofinsFloat;
        // CORRIGIDO: Crédito de ICMS vai para totalizador separado
        $totalVCredICMSSN += $vCredICMSSN;
        $totalBCST     += $vBCST;
        $totalICMSST   += $vICMSST;
        $itemCounter++;
    }

    $std = new stdClass();

    $valorTotalDaNota = $totalProdutos + $totalICMSST;
    // Totais da NF-e        
    $stdTot = new stdClass();
    
    // CORRIGIDO: Para Simples Nacional (CSOSN), o campo vICMS do totalizador deve ser ZERO
    // O crédito de ICMS (vCredICMSSN) não entra no vICMS, é informativo apenas
    $stdTot->vBC = '0.00';
    $stdTot->vICMS = '0.00';  // SEMPRE ZERO para Simples Nacional
    $stdTot->vICMSDeson = '0.00';
    $stdTot->vFCP = '0.00';
    $stdTot->vBCST = number_format($totalBCST, 2, '.', '');
    $stdTot->vST = number_format($totalICMSST, 2, '.', '');
    $stdTot->vFCPST = '0.00';
    $stdTot->vFCPSTRet = '0.00';
    $stdTot->vProd = number_format($totalProdutos, 2, '.', '');
    $stdTot->vFrete = '0.00';
    $stdTot->vSeg = '0.00';
    $stdTot->vDesc = '0.00';
    $stdTot->vII = '0.00';
    $stdTot->vIPI = '0.00';
    $stdTot->vIPIDevol = '0.00';
    $stdTot->vPIS    = number_format($totalPis, 2, '.', '');
    $stdTot->vCOFINS = number_format($totalCofins, 2, '.', '');
    $stdTot->vOutro = '0.00';
    $stdTot->vNF = number_format($valorTotalDaNota, 2, '.', '');
    
    // IMPORTANTE: O crédito de ICMS (totalVCredICMSSN) é apenas informativo
    // e não entra no cálculo do vICMS do totalizador
    
    $nfe->tagICMSTot($stdTot);
    $stdIBCCBS = new stdClass();

    // FRETE
    $stdTransp = new stdClass();
    $stdTransp->modFrete = 9; // PEGAR NO DOLIBARR
    $nfe->tagtransp($stdTransp);

    // Grupo de PAGAMENTO:
    // Regra:
    // - NF de saída (tpNF=1): informar normalmente com valor total.
    // - Devolução/Entrada (tpNF=0, finNFe=4): schema exige <pag>, mas para evitar rejeição 904 enviar vPag=0.00 e tPag=90 (sem pagamento).
    if ($stdIde->tpNF == '1') {
        $stdPag = new stdClass();
        $stdPag->vTroco = '0.00';
        $nfe->tagpag($stdPag);

        $stdDetPag = new stdClass();
        $formaPagamento = $fatura['forma_pagamento']; // Padrão para Dinheiro se não informado
        if($formaPagamento === 'Credit Transfer') { // transferencia bancaria no dolibarr
            $stdDetPag->tPag = '18'; // 
        }elseif($formaPagamento === 'Cheque'){
            $stdDetPag->tPag = '02';
        }elseif($formaPagamento === '04'){ // cartao de credito no dolibarr
            $stdDetPag->tPag = '03';
        }elseif($formaPagamento === '01'){ // Dinheiro no dolibarr
            $stdDetPag->tPag = '01';
        }elseif($formaPagamento === 'Direct Debit'){ // Débito direto no dolibarr
            $stdDetPag->tPag = '04';
        }else{
            $stdDetPag->tPag = '01';
        }
        $stdDetPag->vPag = number_format($valorTotalDaNota, 2, '.', '');
        $nfe->tagdetPag($stdDetPag);
    } else {
        // Devolução/entrada
        $stdPag = new stdClass();
        $stdPag->vTroco = '0.00';
        $nfe->tagpag($stdPag);
        
        $stdDetPag = new stdClass();
        $stdDetPag->tPag = '90';          // Sem pagamento
        $stdDetPag->vPag = '0.00';        // Evita rejeição 904
        $nfe->tagdetPag($stdDetPag);
        error_log('DEVOLUCAO: Grupo de pagamento incluído com vPag=0.00 para atender schema e evitar rejeição 904.');
    }

    //$logNfe('CHECKPOINT 8: Todos itens processados, gerando XML');
    $xml = $nfe->getXML();
    //$logNfe('CHECKPOINT 9: XML gerado (' . strlen($xml) . ' bytes), assinando');
    $xmlAssinado = $tools->signNFe($xml);
    //$logNfe('CHECKPOINT 10: XML assinado, enviando para SEFAZ');

    // $idLote original usava str_pad(time()) (int). Ajustado para string formatada.
    $idLote = sprintf('%015d', time()); // garante string de 15 dígitos
    // Alternativa simples seria: $idLote = str_pad((string)time(), 15, '0', STR_PAD_LEFT);
    $resp = $tools->sefazEnviaLote([$xmlAssinado], $idLote, 1);
    
    $st = new Standardize();
    $stdResp = $st->toStd($resp);

    if (!in_array($stdResp->cStat, ['103', '104'])) {
        throw new Exception("O lote não foi aceito pela SEFAZ. Motivo: " . $stdResp->xMotivo);
    }

    $protocolo = $resp;
    if ($stdResp->cStat == '103') {
        sleep(3);
        $protocolo = $tools->sefazConsultaRecibo($stdResp->infRec->nRec);
    }
    
    $stdProt = $st->toStd($protocolo);

    if (isset($stdProt->protNFe->infProt->cStat) && $stdProt->protNFe->infProt->cStat == '100') {
        // ----- SUCESSO: NF-e AUTORIZADA -----
        $chave = $stdProt->protNFe->infProt->chNFe;
        $numProtocolo = $stdProt->protNFe->infProt->nProt;
        $xmlProc = montaProcNFe($xmlAssinado, $protocolo);

        // Se número customizado fornecido e maior que o último registrado, atualiza sequência
        if ($numeroCustom !== null && $numeroCustom > 0) {
            $amb = (int)$ambiente;
            $resSeqAtual = $db->query("SELECT ultimo_numero FROM ".MAIN_DB_PREFIX."nfe_sequencia WHERE cnpj='".$db->escape($mysoc['cnpj'])."' AND serie=".(int)$stdIde->serie." AND ambiente=".$amb);
            if ($resSeqAtual && ($objSeqAtual = $db->fetch_object($resSeqAtual))) {
                if ($numeroCustom > (int)$objSeqAtual->ultimo_numero) {
                    $db->query("UPDATE ".MAIN_DB_PREFIX."nfe_sequencia SET ultimo_numero=".(int)$numeroCustom." WHERE cnpj='".$db->escape($mysoc['cnpj'])."' AND serie=".(int)$stdIde->serie." AND ambiente=".$amb);
                }
            }
        }

        $danfe = new Danfe($xmlProc);
        $pdf = $danfe->render();
        $pdfBinario = $db->escape($pdf);
        $statusFinal = $isDevolucao ? 'Autorizada DEV' : 'Autorizada';
        $fkOrig = $isDevolucao && !empty($fatura['extrafields']['fk_nfe_origem']) ? (int)$fatura['extrafields']['fk_nfe_origem'] : 'NULL';

        $facture = new Facture($db);
        $facture->fetch($fatura['id']);
        $dir = DOL_DATA_ROOT.'/facture/'.$facture->ref.'/';
        dol_mkdir($dir);
        $filename = 'DANFE-'.$chave.'.pdf';
        $filepath = $dir.$filename;
        file_put_contents($filepath, $pdf);

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "nfe_emitidas (fk_facture, chave, protocolo, numero_nfe, serie, status, xml_completo, pdf_file, data_emissao"
             . ($fkOrig !== 'NULL' ? ", fk_nfe_origem" : "")
             . ") VALUES ("
             . (int)$fatura['id'] . ", '"
             . $db->escape($chave) . "', '"
             . $db->escape($numProtocolo) . "', "
             . (int)$stdIde->nNF . ", "
             . (int)$stdIde->serie . ", '"
             . $db->escape($statusFinal) . "', '"
             . $db->escape($xmlProc) . "', '"
             . $pdfBinario . "', '"
             . date('Y-m-d H:i:s') . "'"
             . ($fkOrig !== 'NULL' ? ", ".$fkOrig : "")
             . ")";
        $db->query($sql);
        setEventMessages("NFe gerada com sucesso!", null, 'mesgs');
        // header("Location: ".$_SERVER["PHP_SELF"]."?facid=".$fatura['id']);
        // exit;

        // Caminhos dos arquivos XML e PDF
        // $xmlPath = $documentosDir . $nomeArquivoBase . ".xml";
        // $pdfPath = $documentosDir . $nomeArquivoBase . ".pdf";

        // // Envia o e-mail ao cliente
        // $emailCliente = $dest['email'];
        // $nomeCliente = $dest['nome'];
        
        // if (!empty($emailCliente)) {
        //     // Globaliza a variável $conf para garantir que esteja acessível
        //     global $conf;

        //     // Define o assunto e o corpo do e-mail
        //     $subject = "Nota Fiscal Eletrônica (NFe)";
        //     $message = "Prezado(a) $nomeCliente,\n\nSegue em anexo a Nota Fiscal Eletrônica referente à sua compra.\n\nAtenciosamente.";

        //     // Usa o sistema de envio de e-mails do Dolibarr
        //     $mail = new CMailFile(
        //         $subject,
        //         $emailCliente,
        //         $conf->global->MAIN_MAIL_EMAIL_FROM,
        //         $message,
        //         array($pdfPath, $xmlPath), // Anexos: PDF e XML
        //         array('application/pdf', 'application/xml'), // Tipos MIME
        //         array('DANFE.pdf', 'NFe.xml') // Nomes dos anexos
        //     );

        //     // if ($mail->sendfile()) {
        //     //    // echo "📧 E-mail enviado com sucesso para $emailCliente.<br>";
        //     // } else {
        //     //     //echo "⚠️ Não foi possível enviar o e-mail para $emailCliente. Erro: " . $mail->error . "<br>";
        //     // }
        // } else {
        //     //echo "⚠️ E-mail do cliente não informado. Não foi possível enviar o e-mail.<br>";
        // }

    } else {
        $codigoRejeicao = $stdProt->protNFe->infProt->cStat ?? $stdProt->cStat ?? 'N/D';
        $motivoRejeicao = $stdProt->protNFe->infProt->xMotivo ?? 'Motivo não especificado.';
        $motivoCompleto = $codigoRejeicao . ' - ' . $motivoRejeicao;

        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "nfe_emitidas (fk_facture, numero_nfe, serie, status, motivo_status, data_emissao) VALUES ("
             . (int) $fatura['id'] . ", " . (int) $stdIde->nNF . ", " . (int) $stdIde->serie . ", 'Rejeitada', '"
             . $db->escape($motivoCompleto) . "', '" . date('Y-m-d H:i:s') . "')";
        $db->query($sql);
        setEventMessages("NF-e rejeitada: ".$motivoCompleto, null, 'errors');
        nfeLog('warning','NF-e rejeitada',['codigo'=>$codigoRejeicao,'motivo'=>$motivoRejeicao]);
    }

} catch (\Throwable $e) {
    $logNfe('*** ERRO CAPTURADO: ' . get_class($e) . ': ' . $e->getMessage() . ' em ' . $e->getFile() . ':' . $e->getLine());
    $logNfe('Stack trace: ' . $e->getTraceAsString());
    $serieFalha = isset($stdIde->serie) ? (int)$stdIde->serie : 1;
    $ambienteFalha = isset($ambiente) ? (int)$ambiente : 2;
    $msg = $e->getMessage();
    $detalhes = [];
    if (isset($nfe) && method_exists($nfe, 'getErrors')) {
        $errs = array_filter((array)$nfe->getErrors());
        if ($errs) {
            $detalhes = $errs;
            $msg .= ' | '.implode(' | ', $errs);
        }
    }
    
    // Tratamento especial para erros de conexão SOAP
    if (stripos($msg, 'Failed to connect') !== false || stripos($msg, 'Could not connect') !== false) {
        // Diagnóstico detalhado do ambiente
        $diagnostico = [
            'servidor' => $_SERVER['SERVER_NAME'] ?? 'N/A',
            'php_version' => phpversion(),
            'curl_version' => function_exists('curl_version') ? curl_version()['version'] : 'NÃO INSTALADO',
            'openssl' => defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'NÃO DISPONÍVEL',
            'allow_url_fopen' => ini_get('allow_url_fopen') ? 'ON' : 'OFF',
            'default_socket_timeout' => ini_get('default_socket_timeout'),
            'max_execution_time' => ini_get('max_execution_time')
        ];
        
        nfeLog('error', '❌ ERRO DE CONEXÃO SOAP - Diagnóstico do Servidor', array_merge([
            'erro' => $msg,
            'servidor_destino' => 'nfe.svrs.rs.gov.br:443',
        ], $diagnostico));
        
        $msgUsuario = "⚠️ ERRO DE CONEXÃO COM A SEFAZ\n\n";
        $msgUsuario .= "🔍 DIAGNÓSTICO:\n";
        $msgUsuario .= "Servidor: " . $diagnostico['servidor'] . "\n";
        $msgUsuario .= "PHP: " . $diagnostico['php_version'] . "\n";
        $msgUsuario .= "cURL: " . $diagnostico['curl_version'] . "\n";
        $msgUsuario .= "OpenSSL: " . $diagnostico['openssl'] . "\n\n";
        
        if ($diagnostico['curl_version'] === 'NÃO INSTALADO') {
            $msgUsuario .= "❌ PROBLEMA DETECTADO: cURL não está instalado!\n";
            $msgUsuario .= "Contate o suporte da Hostgator e peça para habilitar a extensão PHP cURL.\n\n";
        } elseif ($diagnostico['openssl'] === 'NÃO DISPONÍVEL') {
            $msgUsuario .= "❌ PROBLEMA DETECTADO: OpenSSL não está disponível!\n";
            $msgUsuario .= "Contate o suporte da Hostgator e peça para habilitar OpenSSL.\n\n";
        } else {
            $msgUsuario .= "📋 AÇÕES:\n";
            $msgUsuario .= "1. Execute o teste: https://dev1.labconnecta.com.br/test_sefaz.php\n";
            $msgUsuario .= "2. Compare com o ambiente que funciona: https://demo.labconnecta.com.br/test_sefaz.php\n";
            $msgUsuario .= "3. Envie os resultados ao suporte da Hostgator\n\n";
        }
        
        $msgUsuario .= "Detalhes técnicos: " . $msg;
        setEventMessages($msgUsuario, null, 'errors');
    } else {
        nfeLog('error','Falha ao gerar NF-e',['erro'=>$msg,'serie'=>$serieFalha]);
        setEventMessages("Falha ao gerar NF-e: ".$msg, null, 'errors');
    }
}

}