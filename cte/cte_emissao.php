<?php
/**
 * Processamento de Emissão do CT-e
 * Utiliza os dados da sessão para gerar e enviar o CT-e via NFePHP
 */

// Carregamento do ambiente Dolibarr
$res = 0;
if (!$res && file_exists("../main.inc.php")) {
    $res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res) {
    die("Include of main fails");
}

function carregarNfeConfig($db){
    $sql = "SELECT name, value FROM ". MAIN_DB_PREFIX ."nfe_config";
    $res = $db->query($sql);
    $config = [];
    if ($res) {
        while ($row = $db->fetch_object($res)) {
            $config[$row->name] = $row->value;
        }
    }
    return $config;
}

/**
 * Busca o próximo número de CT-e disponível no banco de dados
 * Se não houver registro para o CNPJ/série, cria um novo com número 1
 */
function obterProximoNumeroCte($db, $cnpj, $serie, $tipo = 'NORMAL') {
    $cnpj = preg_replace('/\D/', '', $cnpj);
    $serie = $db->escape($serie);
    $tipo = $db->escape($tipo);
    
    // Buscar a sequência existente
    $sql = "SELECT numero_cte FROM ". MAIN_DB_PREFIX ."cte_sequencias ";
    $sql .= "WHERE cnpj = '".$db->escape($cnpj)."' AND serie = '".$serie."' AND tipo = '".$tipo."' ";
    $sql .= "FOR UPDATE"; // Lock para evitar concorrência
    
    $resql = $db->query($sql);
    
    if ($resql) {
        $obj = $db->fetch_object($resql);
        if ($obj) {
            // Já existe, retorna o número atual
            return (int)$obj->numero_cte;
        } else {
            // Não existe, criar novo registro
            $sql_insert = "INSERT INTO ". MAIN_DB_PREFIX ."cte_sequencias (cnpj, serie, tipo, numero_cte) ";
            $sql_insert .= "VALUES ('".$db->escape($cnpj)."', '".$serie."', '".$tipo."', 1)";
            $db->query($sql_insert);
            return 1;
        }
    }
    
    return 1; // Retorna 1 como padrão em caso de erro
}

/**
 * Atualiza o número de sequência do CT-e no banco após emissão bem-sucedida
 */
function incrementarNumeroCte($db, $cnpj, $serie, $tipo = 'NORMAL') {
    $cnpj = preg_replace('/\D/', '', $cnpj);
    $serie = $db->escape($serie);
    $tipo = $db->escape($tipo);
    
    $sql = "UPDATE ". MAIN_DB_PREFIX ."cte_sequencias ";
    $sql .= "SET numero_cte = numero_cte + 1, updated_at = NOW() ";
    $sql .= "WHERE cnpj = '".$db->escape($cnpj)."' AND serie = '".$serie."' AND tipo = '".$tipo."'";
    
    return $db->query($sql);
}

/**
 * Insere os dados do CT-e emitido no banco de dados
 */
function inserirCteEmitido($db, $chave, $numero, $serie, $dhemi, $protocolo, $dest_cnpj, $cnpj_auxiliar, $valor, $xml_enviado, $xml_recebido, $status = '100') {
    $sql = "INSERT INTO ". MAIN_DB_PREFIX ."cte_emitidos ";
    $sql .= "(chave, numero, serie, dhemi, protocolo, dest_cnpj, cnpj_auxiliar, valor, xml_enviado, xml_recebido, status, datec) ";
    $sql .= "VALUES (";
    $sql .= "'".$db->escape($chave)."', ";
    $sql .= "'".$db->escape($numero)."', ";
    $sql .= "'".$db->escape($serie)."', ";
    $sql .= "'".$db->escape($dhemi)."', ";
    $sql .= "'".$db->escape($protocolo)."', ";
    $sql .= "'".$db->escape($dest_cnpj)."', ";
    $sql .= "'".$db->escape($cnpj_auxiliar)."', ";
    $sql .= (float)$valor.", ";
    $sql .= "'".$db->escape($xml_enviado)."', ";
    $sql .= "'".$db->escape($xml_recebido)."', ";
    $sql .= "'".$db->escape($status)."', ";
    $sql .= "NOW())";
    
    return $db->query($sql);
}

// Iniciar sessão
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Verificar se há dados na sessão
if (empty($_SESSION['cte_dados'])) {
    setEventMessages('Nenhum dado encontrado para emissão do CT-e', null, 'errors');
    header('Location: cte_main_home.php');
    exit;
}

// Carregar autoload do Composer
$possibleAutoloads = [
    __DIR__ . '/../../custom/composerlib/vendor/autoload.php',
    __DIR__ . '/../composerlib/vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
];

$autoloadFound = false;
foreach ($possibleAutoloads as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        $autoloadFound = true;
        break;
    }
}

if (!$autoloadFound) {
    setEventMessages('Erro: Biblioteca NFePHP não encontrada. Execute composer install.', null, 'errors');
    header('Location: cte_main_home.php');
    exit;
}

use NFePHP\Common\Certificate;
use NFePHP\CTe\Common\Standardize;
use NFePHP\CTe\MakeCTe;
use NFePHP\CTe\Tools;

try {
    $dados = $_SESSION['cte_dados'];
    
    // Configuração
    $config = [
        "atualizacao" => date('Y-m-d H:i:s'),
        "tpAmb" => 1,
        "razaosocial" => $dados['rem_xNome'] ?? '',
        "cnpj" => '40344276000101',
        "siglaUF" => $dados['rem_uf'] ?? 'ES',
        "schemes" => "PL_CTe_400",
        "versao" => '4.00',
        "proxyConf" => [
            "proxyIp" => "",
            "proxyPort" => "",
            "proxyUser" => "",
            "proxyPass" => ""
        ]
    ];
    
    $configJson = json_encode($config);
    
    // Carregar certificado (ajustar caminho conforme necessário)
    //$certPath = DOL_DOCUMENT_ROOT . '/custom/cte/certificado.pfx';
    //certPassword = getDolGlobalString('CTE_CERT_PASSWORD', 'senha');

    try{    
        $nfe_cfg = carregarNfeConfig($db);
        $certPath = $nfe_cfg['cert_pfx'];
        $certPassword = $nfe_cfg['cert_pass'];
        $ambiente = $nfe_cfg['ambiente'];
    }catch(Exception $e){
        throw new Exception('Erro ao carregar configurações de ambiente: '.$e->getMessage());
    }
    //$certTeste = DOL_DOCUMENT_ROOT . '/custom/cte/cert_trans_novo.pfx';
    $tools = new Tools($configJson, Certificate::readPfx($certPath, $certPassword));
    $tools->model('57');
    
    $cte = new MakeCTe();
    $dhEmi = date("Y-m-d\TH:i:sP");
    
    // Buscar próximo número de CT-e do banco
    $cnpjEmitente = preg_replace('/\D/', '', $dados['rem_cnpj'] ?? '');
    $serieEmissao = $dados['serie'] ?? '1';
    $numeroCTE = obterProximoNumeroCte($db, $cnpjEmitente, $serieEmissao, 'NORMAL');
    
    // Montar chave de acesso
    $chave = montaChave(
        substr($config['siglaUF'], 0, 2),
        date('y'),
        date('m'),
        $config['cnpj'],
        '57',
        $dados['serie'] ?? '1',
        $numeroCTE,
        $dados['tpEmis'] ?? '1',
        rand(10000000, 99999999)
    );
    
    // infCTe
    $infCte = new stdClass();
    $infCte->Id = "";
    $infCte->versao = "4.00";
    $cte->taginfCTe($infCte);
    
    $cDV = substr($chave, -1);
    
    // IDE - Identificação (CAMPOS OBRIGATÓRIOS MARCADOS)
    $ide = new stdClass();
    $ide->cUF = obterCodigoUF($dados['UFEnv'] ?? $dados['rem_uf'] ?? 'ES'); // *
    $ide->cCT = substr($chave, 25, 8); // *
    $ide->CFOP = $dados['cfop'] ?? '5353'; // *
    $ide->natOp = $dados['natOp'] ?? 'PRESTACAO DE SERVICO DE TRANSPORTE'; // *
    $ide->mod = '57'; // *
    $ide->serie = $dados['serie'] ?? '1'; // *
    $ide->nCT = $numeroCTE; // *
    $ide->dhEmi = $dhEmi; // *
    $ide->tpImp = '1'; // *
    $ide->tpEmis = $dados['tpEmis'] ?? '1'; // *
    $ide->cDV = $cDV; // *
    $ide->tpAmb = 1; // *
    $ide->tpCTe = $dados['tpCTe'] ?? '0'; // *
    $ide->procEmi = '0'; // *
    $ide->verProc = '4.0'; // *
    //$ide->indGlobalizado = !empty($dados['indGlobalizado']) ? $dados['indGlobalizado'] : ''; // Opcional
    
    // Município de envio (onde o CT-e foi emitido/transmitido) - OBRIGATÓRIO
    $ide->cMunEnv = $dados['cMunEnv']; // *
    $ide->xMunEnv = $dados['xMunEnv']; // *
    $ide->UFEnv = $dados['UFEnv']; // *
    
    $ide->modal = $dados['modal'] ?? '01'; // *
    $ide->tpServ = $dados['tpServ'] ?? '0'; // *
    
    // Município de início da prestação - OBRIGATÓRIO
    $ide->cMunIni = $dados['cMunIni']; // *
    $ide->xMunIni = $dados['xMunIni']; // *
    $ide->UFIni = $dados['UFIni']; // *
    
    // Município de fim da prestação - OBRIGATÓRIO
    $ide->cMunFim = $dados['cMunFim']; // *
    $ide->xMunFim = $dados['xMunFim']; // *
    $ide->UFFim = $dados['UFFim']; // *
    
    $ide->retira = $dados['retira'] ?? '1'; // *
    $ide->xDetRetira = !empty($dados['xDetRetira']) ? $dados['xDetRetira'] : ''; // Opcional
    $ide->indIEToma = $dados['indIEToma'] ?? '1'; // *
    //$ide->dhCont = ''; // Opcional - contingência
    //$ide->xJust = ''; // Opcional - contingência
    $cte->tagide($ide);
    
    // Tomador (apenas uma das tags deve ser adicionada: toma3 para 0..3; toma4 para 4)
    $toma = isset($dados['toma']) ? (string)$dados['toma'] : '';
    if ($toma === '4') {
        // Usar toma4 (Outros) — informar CNPJ ou CPF e xNome (xNome obrigatório)
        $toma4 = new stdClass();
        $toma4->toma = '4';
        $cCnpj = preg_replace('/\D/', '', $dados['toma4_CNPJ']);
        $cCpf = preg_replace('/\D/', '', $dados['toma4_CPF'] ?? NULL);
        if (!empty($cCnpj)) $toma4->CNPJ = $cCnpj;
        if (!empty($cCpf)) $toma4->CPF = $cCpf;
        $toma4->xNome = $dados['toma4_xNome'];
        // Opcional: telefone/email podem ser adicionados se presentes no formulário
        if (!empty($dados['toma4_fone'])) $toma4->fone = preg_replace('/\D/', '', $dados['toma4_fone']);
        if (!empty($dados['toma4_email'])) $toma4->email = $dados['toma4_email'];
        $cte->tagtoma4($toma4);
    } else {
        // Usar toma3 (0..3). Se não informado, assume '0' (Remetente)
        $toma_val = $toma === '' ? '0' : $toma;
        $toma3 = new stdClass();
        $toma3->toma = $toma_val;
        $cte->tagtoma3($toma3);
    }
    
    // Endereço do Tomador (CAMPOS OBRIGATÓRIOS)
    $tomador = $dados['toma'];
    $prefixoToma = ($tomador == '0' ? 'rem' : ($tomador == '3' ? 'dest' : 'dest'));
    
    $enderToma = new stdClass();
    $enderToma->xLgr = $dados[$prefixoToma.'_xLgr']; // *
    $enderToma->nro = $dados[$prefixoToma.'_nro'] ?? ''; // *
    $enderToma->xCpl = $dados[$prefixoToma.'_xCpl'] ?? ''; // Opcional
    $enderToma->xBairro = $dados[$prefixoToma.'_xBairro'] ?? ''; // *
    $enderToma->cMun = !empty($dados[$prefixoToma.'_cMun']) ? $dados[$prefixoToma.'_cMun'] : '3201209'; // *
    $enderToma->xMun = $dados[$prefixoToma.'_xMun'] ?? ''; // *
    $enderToma->CEP = preg_replace('/\D/', '', $dados[$prefixoToma.'_cep'] ?? ''); // Opcional
    $enderToma->UF = $dados[$prefixoToma.'_uf'] ?? ''; // *
    $cte->tagenderToma($enderToma);
    
    // Emitente (CAMPOS OBRIGATÓRIOS)
    $emit = new stdClass();
    $emit->CNPJ = '40344276000101';
    //$emit->CNPJ = preg_replace('/\D/', '', $dados['rem_cnpj'] ?? '');
    //$emit->IE = preg_replace('/\D/', '', $dados['rem_ie'] ?? ''); // Opcional mas comum
    $emit->IE = '083726438';
    $emit->IEST = ''; // Opcional
    $emit->xNome = $dados['rem_xNome'] ?? ''; // *
    $emit->xFant = $dados['rem_xFant'] ?? NULL; // Opcional
    $emit->CRT = '1'; // *
    $cte->tagemit($emit);
    
    $enderEmit = new stdClass();
    $enderEmit->xLgr = $dados['rem_xLgr'] ?? ''; // *
    $enderEmit->nro = $dados['rem_nro'] ?? ''; // *
    $enderEmit->xCpl = $dados['rem_xCpl'] ?? ''; // Opcional
    $enderEmit->xBairro = $dados['rem_xBairro'] ?? ''; // *
    $enderEmit->cMun = !empty($dados['rem_cMun']) ? $dados['rem_cMun'] : '3201209'; // *
    $enderEmit->xMun = $dados['rem_xMun'] ?? ''; // *
    $enderEmit->CEP = !empty($dados['rem_cep']) ? preg_replace('/\D/', '', $dados['rem_cep']) : ''; // Opcional
    $enderEmit->UF = $dados['rem_uf'] ?? ''; // *
    $enderEmit->fone = !empty($dados['rem_fone']) ? preg_replace('/\D/', '', $dados['rem_fone']) : ''; // Opcional
    $cte->tagenderEmit($enderEmit);
    
    // Remetente (CAMPOS OBRIGATÓRIOS)
    $rem = new stdClass();
    $rem->CNPJ = preg_replace('/\D/', '', $dados['rem_cnpj'] ?? ''); // *
    $rem->CPF = ''; // Opcional (usar quando não tiver CNPJ)
   //$rem->IE = !empty($dados['rem_ie']) ? preg_replace('/\D/', '', $dados['rem_ie']) : ''; // Opcional
    $rem->IE = '083726438'; // Opcional
    if($ambiente == 2 && $dados['rem_xNome']){
        $rem->xNome = 'CTE EMITIDO EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL'; // *
    }else{
        $rem->xNome = $dados['rem_xNome'] ?? NULL; // *
    }
    
    $rem->xFant = $dados['rem_xFant'] ?? NULL; // Opcional
    $rem->fone = !empty($dados['rem_fone']) ? preg_replace('/\D/', '', $dados['rem_fone']) : ''; // Opcional
    $rem->email = $dados['rem_email'] ?? ''; // Opcional
    $cte->tagrem($rem);
    
    $enderReme = new stdClass();
    $enderReme->xLgr = $dados['rem_xLgr'] ?? ''; // *
    $enderReme->nro = $dados['rem_nro'] ?? ''; // *
    $enderReme->xCpl = $dados['rem_xCpl'] ?? ''; // Opcional
    $enderReme->xBairro = $dados['rem_xBairro'] ?? ''; // *
    $enderReme->cMun = !empty($dados['rem_cMun']) ? $dados['rem_cMun'] : '3201209'; // *
    $enderReme->xMun = $dados['rem_xMun'] ?? ''; // *
    $enderReme->CEP = !empty($dados['rem_cep']) ? preg_replace('/\D/', '', $dados['rem_cep']) : ''; // Opcional
    $enderReme->UF = $dados['rem_uf'] ?? ''; // *
    $enderReme->cPais = $dados['rem_cPais'] ?? '1058'; // Opcional
    $enderReme->xPais = $dados['rem_xPais'] ?? 'Brasil'; // Opcional
    $cte->tagenderReme($enderReme);
    
    // Destinatário (CAMPOS OBRIGATÓRIOS)
    $dest = new stdClass();
    $dest->CNPJ = preg_replace('/\D/', '', $dados['dest_cnpj'] ?? ''); // *
    $dest->CPF = ''; // Opcional (usar quando não tiver CNPJ)
    $dest->IE = !empty($dados['dest_ie']) ? preg_replace('/\D/', '', $dados['dest_ie']) : ''; // Opcional
    $dest->xNome = $dados['dest_xNome'] ?? ''; // *
    $dest->fone = !empty($dados['dest_fone']) ? preg_replace('/\D/', '', $dados['dest_fone']) : ''; // Opcional
    $dest->ISUF = !empty($dados['dest_ISUF']) ? $dados['dest_ISUF'] : ''; // Opcional
    $dest->email = $dados['dest_email'] ?? ''; // Opcional
    $cte->tagdest($dest);
    
    $enderDest = new stdClass();
    $enderDest->xLgr = $dados['dest_xLgr'] ?? ''; // *
    $enderDest->nro = $dados['dest_nro'] ?? ''; // *
    $enderDest->xCpl = $dados['dest_xCpl'] ?? ''; // Opcional
    $enderDest->xBairro = $dados['dest_xBairro'] ?? ''; // *
    $enderDest->cMun = !empty($dados['dest_cMun']) ? $dados['dest_cMun'] : '3201209'; // *
    $enderDest->xMun = $dados['dest_xMun'] ?? ''; // *
    $enderDest->CEP = !empty($dados['dest_cep']) ? preg_replace('/\D/', '', $dados['dest_cep']) : ''; // Opcional
    $enderDest->UF = $dados['dest_uf'] ?? ''; // *
    $enderDest->cPais = $dados['dest_cPais'] ?? '1058'; // Opcional
    $enderDest->xPais = $dados['dest_xPais'] ?? 'Brasil'; // Opcional
    $cte->tagenderDest($enderDest);
    
    // Valores (CAMPOS OBRIGATÓRIOS)
    $vPrest = new stdClass();
    $vPrest->vTPrest = (float)($dados['vTPrest'] ?? 0); // *
    $vPrest->vRec = (float)($dados['vRec'] ?? 0); // *
    $cte->tagvPrest($vPrest);
    
    $comp = new stdClass();
    $comp->xNome = $dados['comp_xNome'] ?? 'FRETE VALOR'; // *
    $comp->vComp = (float)($dados['comp_vComp'] ?? 0); // *
    $cte->tagComp($comp);
    
    // ICMS (CAMPOS OBRIGATÓRIOS)
    $icms = new stdClass();
    $cst = $dados['cst_icms'] ?? '00';
    $icms->cst = $cst;
    
    // Configurar campos conforme CST
    switch ($cst) {
        case '00': // Tributação Normal
            $vBC = !empty($dados['vBC']) ? (float)$dados['vBC'] : (float)($dados['vTPrest'] ?? 0);
            $pICMS = !empty($dados['pICMS']) ? (float)$dados['pICMS'] : 0;
            
            // Se não informou BC, usa o valor da prestação
            if ($vBC == 0 && !empty($dados['vTPrest'])) {
                $vBC = (float)$dados['vTPrest'];
            }
            
            // Calcula ICMS se não foi informado
            $vICMS = !empty($dados['vICMS']) ? (float)$dados['vICMS'] : ($vBC * ($pICMS / 100));
            
            $icms->vBC = $vBC;
            $icms->pICMS = $pICMS;
            $icms->vICMS = $vICMS;
            break;
            
        case '20': // Tributação com Redução de BC
            $vBC_Original = !empty($dados['vTPrest']) ? (float)$dados['vTPrest'] : 0;
            $pRedBC = !empty($dados['pRedBC']) ? (float)$dados['pRedBC'] : 0;
            $pICMS = !empty($dados['pICMS']) ? (float)$dados['pICMS'] : 0;
            
            // Calcula BC com redução
            $vBC = $vBC_Original * (1 - ($pRedBC / 100));
            
            // Calcula ICMS sobre BC reduzida
            $vICMS = $vBC * ($pICMS / 100);
            
            $icms->pRedBC = $pRedBC;
            $icms->vBC = $vBC;
            $icms->pICMS = $pICMS;
            $icms->vICMS = $vICMS;
            
            // Mantém cálculo de vICMSDeson se aplicável, sem atribuir cBenef
            if (!empty($dados['vICMSDeson']) || (!empty($pRedBC) && isset($vBC_Original))) {
                // se vICMSDeson foi fornecido, usa-o; senão calcula pela redução (opcional)
                if (!empty($dados['vICMSDeson'])) {
                    $icms->vICMSDeson = (float)$dados['vICMSDeson'];
                } else {
                    $icms->vICMSDeson = $vBC_Original * ($pICMS / 100) - $vICMS;
                }
            }
            break;
            
        case '40': // Isento
        case '41': // Não Tributado
        case '51': // Diferido
            // Apenas CST, sem valores
            // Pode haver vICMSDeson informado — aceitar apenas o valor numérico
            if (!empty($dados['vICMSDeson'])) {
                $icms->vICMSDeson = (float)$dados['vICMSDeson'];
            }
            break;
            
        case '60': // ICMS ST Retido
            $icms->vBCSTRet = !empty($dados['vBCSTRet']) ? (float)$dados['vBCSTRet'] : 0;
            $icms->vICMSSTRet = !empty($dados['vICMSSTRet']) ? (float)$dados['vICMSSTRet'] : 0;
            $icms->pICMSSTRet = !empty($dados['pICMSSTRet']) ? (float)$dados['pICMSSTRet'] : 0;
            
            // Crédito presumido (opcional)
            if (!empty($dados['vCred'])) {
                $icms->vCred = (float)$dados['vCred'];
            }
            break;
            
        case '90': // Outros
            // Verifica se é outra UF
            if (!empty($dados['outraUF']) && $dados['outraUF'] == true) {
                $vBC_Original = !empty($dados['vBCOutraUF']) ? (float)$dados['vBCOutraUF'] : (float)($dados['vTPrest'] ?? 0);
                $pRedBC = !empty($dados['pRedBCOutraUF']) ? (float)$dados['pRedBCOutraUF'] : 0;
                $pICMS = !empty($dados['pICMSOutraUF']) ? (float)$dados['pICMSOutraUF'] : 0;
                
                // Calcula BC com redução (se houver)
                $vBC = $pRedBC > 0 ? $vBC_Original * (1 - ($pRedBC / 100)) : $vBC_Original;
                
                // Calcula ICMS
                $vICMS = $vBC * ($pICMS / 100);
                
                if ($pRedBC > 0) {
                    $icms->pRedBCOutraUF = $pRedBC;
                }
                $icms->vBCOutraUF = $vBC;
                $icms->pICMSOutraUF = $pICMS;
                $icms->vICMSOutraUF = $vICMS;
                $icms->outraUF = true;
            } else {
                // CST 90 normal
                $vBC_Original = !empty($dados['vTPrest']) ? (float)$dados['vTPrest'] : 0;
                $pRedBC = !empty($dados['pRedBC']) ? (float)$dados['pRedBC'] : 0;
                $pICMS = !empty($dados['pICMS']) ? (float)$dados['pICMS'] : 0;
                
                // Calcula BC com redução (se houver)
                $vBC = $pRedBC > 0 ? $vBC_Original * (1 - ($pRedBC / 100)) : $vBC_Original;
                
                // Calcula ICMS
                $vICMS = $vBC * ($pICMS / 100);
                
                if ($pRedBC > 0) {
                    $icms->pRedBC = $pRedBC;
                }
                $icms->vBC = $vBC;
                $icms->pICMS = $pICMS;
                $icms->vICMS = $vICMS;
            }
            
            // Crédito presumido (opcional)
            if (!empty($dados['vCred'])) {
                $icms->vCred = (float)$dados['vCred'];
            }
            break;
            
        case 'SN': // Simples Nacional
            // Não tem valores de ICMS
            break;
            
        default:
            // CST inválido, usa 00 como padrão
            $vBC = !empty($dados['vBC']) ? (float)$dados['vBC'] : (float)($dados['vTPrest'] ?? 0);
            $pICMS = !empty($dados['pICMS']) ? (float)$dados['pICMS'] : 0;
            $vICMS = $vBC * ($pICMS / 100);
            
            $icms->vBC = $vBC;
            $icms->pICMS = $pICMS;
            $icms->vICMS = $vICMS;
            break;
    }
    
    // Valor Total de Tributos (Lei da Transparência) - Opcional
    if (!empty($dados['vTotTrib'])) {
        $icms->vTotTrib = (float)$dados['vTotTrib'];
    }
    
    // Informações Adicionais do Fisco - Opcional
    if (!empty($dados['infAdFisco']) && trim($dados['infAdFisco']) !== '') {
        $icms->infAdFisco = $dados['infAdFisco'];
    }
    
    $cte->tagicms($icms);
    
    $cte->taginfCTeNorm(); // *
    
    // Carga (CAMPOS OBRIGATÓRIOS)
    $infCarga = new stdClass();
    $infCarga->vCarga = !empty($dados['vCarga']) ? (float)$dados['vCarga'] : null; // Opcional
    $infCarga->proPred = $dados['proPred'] ?? 'MERCADORIA'; // *
    
    // NÃO incluir xOutCat se estiver vazio
    if (!empty($dados['xOutCat']) && trim($dados['xOutCat']) !== '') {
        $infCarga->xOutCat = $dados['xOutCat'];
    }
    
    // NÃO incluir vCargaAverb se for zero ou vazio
    if (!empty($dados['vCargaAverb']) && (float)$dados['vCargaAverb'] > 0) {
        $infCarga->vCargaAverb = (float)$dados['vCargaAverb'];
    }
    
    $cte->taginfCarga($infCarga);
    
    // Quantidade (OBRIGATÓRIO - ao menos uma ocorrência)
    $infQ = new stdClass();
    $infQ->cUnid = $dados['cUnid'] ?? '01'; // *
    $infQ->tpMed = $dados['tpMed'] ?? 'PESO BRUTO'; // *
    $infQ->qCarga = (float)($dados['qCarga'] ?? 0); // *
    $cte->taginfQ($infQ);
    
    // Documento NF-e (OBRIGATÓRIO quando houver)
    if (!empty($dados['chave_nfe'])) {
        $infNFe = new stdClass();
        $infNFe->chave = $dados['chave_nfe']; // *
        $infNFe->PIN = !empty($dados['PIN']) ? $dados['PIN'] : ''; // Opcional
        $infNFe->dPrev = !empty($dados['dPrev']) ? $dados['dPrev'] : ''; // Opcional
        $infNFe->infUnidCarga = null; // Não implementado
        $infNFe->infUnidTransp = null; // Não implementado
        $cte->taginfNFe($infNFe);
    }
    
    // $comData = new stdClass();
    // $comData->tpPer = '1'; // *
    // $comData->dProg = '2025-10-10'; // *
    // $cte->tagcomData($comData);

    // Modal (OBRIGATÓRIO)
    $infModal = new stdClass();
    $infModal->versaoModal = '4.00'; // *
    $cte->taginfModal($infModal);
    
    // Modal Rodoviário (OBRIGATÓRIO quando modal=01)
    if (($dados['modal'] ?? '01') == '01') {
        $rodo = new stdClass();
        $rodo->RNTRC = $dados['RNTRC'] ?? ''; // *
        $cte->tagrodo($rodo);
    }
    
    // Modal Aéreo (02)
    if (($dados['modal'] ?? '') == '02' && !empty($dados['aereo_nMinu'])) {
        $aereo = new stdClass();
        $aereo->nMinu = $dados['aereo_nMinu'];
        if(!empty($dados['aereo_nOCA'])) $aereo->nOCA = $dados['aereo_nOCA'];
        if(!empty($dados['aereo_dPrevAereo'])) $aereo->dPrevAereo = $dados['aereo_dPrevAereo'];
        $aereo->natCarga_xDime = ''; // Dimensões 1234x1234x1234 em cm
        $aereo->natCarga_cInfManu = [
            '01',
            '02'
        ]; 
        // Tarifa
        if(!empty($dados['aereo_tarifa_CL'])) {
            $aereo->tarifa_CL = $dados['aereo_tarifa_CL'];
            if(!empty($dados['aereo_tarifa_vTar'])) {
                $aereo->tarifa_vTar = (float)$dados['aereo_tarifa_vTar'];
            }
        }
        $cte->tagaereo($aereo);
    }
    
    // Modal Aquaviário (03)
    if (($dados['modal'] ?? '') == '03') {
        $aquav = new stdClass();
        if(!empty($dados['aquav_vPrest'])) $aquav->vPrest = (float)$dados['aquav_vPrest'];
        if(!empty($dados['aquav_vAFRMM'])) $aquav->vAFRMM = (float)$dados['aquav_vAFRMM'];
        if(!empty($dados['aquav_xNavio'])) $aquav->xNavio = $dados['aquav_xNavio'];
        if(!empty($dados['aquav_nViag'])) $aquav->nViag = $dados['aquav_nViag'];
        if(!empty($dados['aquav_direc'])) $aquav->direc = $dados['aquav_direc'];
        if(!empty($dados['aquav_irin'])) $aquav->irin = $dados['aquav_irin'];
        if(!empty($dados['aquav_tpNav'])) $aquav->tpNav = $dados['aquav_tpNav'];
        
        // Só adiciona se tiver ao menos um campo preenchido
        if(isset($aquav->vPrest) || isset($aquav->xNavio)) {
            $cte->tagaquav($aquav);
        }
        $balsa = new stdClass();
        if(!empty($dados['balsa_xBalsa'])) $balsa->xBalsa = $dados['balsa_xBalsa'];
        $cte->tagbalsa($balsa);
    }
    
    // Modal Ferroviário (04)
    if (($dados['modal'] ?? '') == '04') {
        $ferrov = new stdClass();
        if(!empty($dados['ferrov_tpTraf'])) $ferrov->tpTraf = $dados['ferrov_tpTraf'];
        $ferrov->respFat = '1'; // assumir padrão
        $ferrov->ferrEmi = '1'; // assumir padrão
        if(!empty($dados['vTPrest'])) $ferrov->vFrete = (float)$dados['vTPrest'];
        if(!empty($dados['ferrov_fluxo'])) $ferrov->fluxo = $dados['ferrov_fluxo'];
        
        if(isset($ferrov->tpTraf)) {
            $cte->tagferrov($ferrov);
        }
    }
    
    // Modal Dutoviário (05)
    if (($dados['modal'] ?? '') == '05') {
        $duto = new stdClass();
        if(!empty($dados['duto_vTar'])) $duto->vTar = (float)$dados['duto_vTar'];
        if(!empty($dados['duto_dIni'])) $duto->dIni = $dados['duto_dIni'];
        if(!empty($dados['duto_dFim'])) $duto->dFim = $dados['duto_dFim'];
        
        if(isset($duto->vTar)) {
            $cte->tagduto($duto);
        }
    }
    
    // Modal Multimodal (06)
    if (($dados['modal'] ?? '') == '06') {
        $multimodal = new stdClass();
        if(!empty($dados['multi_COTM'])) $multimodal->COTM = $dados['multi_COTM'];
        if(!empty($dados['multi_indNegociavel'])) $multimodal->indNegociavel = $dados['multi_indNegociavel'];
        
        if(isset($multimodal->COTM)) {
            $cte->tagmultimodal($multimodal);
        }
        
        // Seguro para multimodal (se informado)
        if(!empty($dados['xSeg']) && !empty($dados['nApol'])) {
            $segMultimodal = new stdClass();
            $segMultimodal->xSeg = $dados['xSeg'];
            if(!empty($dados['respSeg'])) {
                // Mapear respSeg para CNPJ da seguradora se necessário
                $segMultimodal->nApol = $dados['nApol'];
                if(!empty($dados['nAver'])) $segMultimodal->nAver = $dados['nAver'];
                $cte->tagSegMultimodal($segMultimodal);
            }
        }
    }
    
    // Fluxo (Opcional)
    if (!empty($dados['fluxo_xOrig']) || !empty($dados['fluxo_xDest']) || !empty($dados['fluxo_xRota'])) {
        $fluxo = new stdClass();
        $fluxo->xOrig = $dados['fluxo_xOrig'] ?? '';
        $fluxo->xDest = $dados['fluxo_xDest'] ?? '';
        $fluxo->xRota = $dados['fluxo_xRota'] ?? '';
        $cte->tagfluxo($fluxo);
    }
    
    // Complementos (ajustado para incluir novos campos)
    if (!empty($dados['xObs']) || !empty($dados['xCaracAd']) || !empty($dados['xCaracSer'])) {
        $compl = new stdClass();
        $compl->xCaracAd = !empty($dados['xCaracAd']) ? $dados['xCaracAd'] : '';
        $compl->xCaracSer = !empty($dados['xCaracSer']) ? $dados['xCaracSer'] : '';
        $compl->xEmi = '';
        $compl->origCalc = '';
        $compl->destCalc = '';
        $compl->xObs = !empty($dados['xObs']) ? $dados['xObs'] : '';
        $cte->tagcompl($compl);
    }
    
    // Documentos Anteriores (se informado)
    if (!empty($dados['emiDocAnt_CNPJ']) || !empty($dados['emiDocAnt_CPF'])) {
        $cte->tagdocAnt();
        
        $emiDocAnt = new stdClass();
        if(!empty($dados['emiDocAnt_CNPJ'])) $emiDocAnt->CNPJ = preg_replace('/\D/', '', $dados['emiDocAnt_CNPJ']);
        if(!empty($dados['emiDocAnt_CPF'])) $emiDocAnt->CPF = preg_replace('/\D/', '', $dados['emiDocAnt_CPF']);
        if(!empty($dados['emiDocAnt_IE'])) $emiDocAnt->IE = $dados['emiDocAnt_IE'];
        if(!empty($dados['emiDocAnt_UF'])) $emiDocAnt->UF = $dados['emiDocAnt_UF'];
        if(!empty($dados['emiDocAnt_xNome'])) $emiDocAnt->xNome = $dados['emiDocAnt_xNome'];
        $cte->tagemiDocAnt($emiDocAnt);
        
        if(!empty($dados['chCTeAnt'])) {
            $cte->tagidDocAnt();
            $idDocAntEle = new stdClass();
            $idDocAntEle->chCTe = $dados['chCTeAnt'];
            $cte->tagidDocAntEle($idDocAntEle);
        }
    }

    // Montar CT-e
    try {
        $cte->montaCTe();
    } catch (\Exception $e) {
        $msg = $e->getMessage();
        $detail = '';
        if (isset($cte) && method_exists($cte, 'getErrors')) {
            $errs = $cte->getErrors();
            if (!empty($errs) && is_array($errs)) {
                $detail .= '<br><br><strong>Detalhes dos erros:</strong><ul>';
                foreach ($errs as $err) {
                    $detail .= '<li>'.dol_escape_htmltag((string)$err).'</li>';
                }
                $detail .= '</ul>';
            }
        }
        setEventMessages('Erro ao montar CT-e: ' . $msg . $detail, null, 'errors');
        header('Location: cte_main_home.php?etapa=5');
        exit;
    }
    
    $chave = $cte->chCTe;
    $xml = $cte->getXML();
    
    // Assinar
    try {
        $xml = $tools->signCTe($xml);
    } catch (\Exception $e) {
        setEventMessages('Erro ao assinar CT-e: ' . $e->getMessage(), null, 'errors');
        header('Location: cte_main_home.php?etapa=5');
        exit;
    }
    
    // Enviar para SEFAZ
    try {
        $response = $tools->sefazEnviaCTe($xml);
        file_put_contents('cte-enviado.xml', $xml);
    } catch (\Exception $e) {
        setEventMessages('Erro ao enviar CT-e para SEFAZ: ' . $e->getMessage(), null, 'errors');
        header('Location: cte_main_home.php?etapa=5');
        exit;
    }
    
    // Processar resposta
    $stdCl = new Standardize($response);
    $std = $stdCl->toStd();
    $arr = $stdCl->toArray();

    if ($std->cStat == 100) {
        // Sucesso - CT-e Autorizado
        
        // Extrair protocolo e outros dados da resposta
        $protocolo = $std->protCTe->infProt->nProt ?? '';
        $dest_cnpj = preg_replace('/\D/', '', $dados['dest_cnpj'] ?? '');
        //$cnpj_auxiliar = preg_replace('/\D/', '', $dados['rem_cnpj'] ?? ''); // CNPJ do emitente
        $cnpj_auxiliar = '';
        $valor = (float)($dados['vTPrest'] ?? 0);
        $dhemiFormatado = str_replace('T', ' ', substr($dhEmi, 0, 19)); // Converter para formato MySQL
        
        // Inserir na tabela cte_emitidos
        $insertResult = inserirCteEmitido(
            $db, 
            $chave, 
            $numeroCTE, 
            $serieEmissao, 
            $dhemiFormatado, 
            $protocolo, 
            $dest_cnpj, 
            $cnpj_auxiliar, 
            $valor, 
            $xml, 
            $response, 
            '100'
        );
        // Incrementar número na tabela de sequências
        $incrementResult = incrementarNumeroCte($db, $cnpjEmitente, $serieEmissao, 'NORMAL');

        setEventMessages('CT-e autorizado com sucesso!', null, 'mesgs');
        
        // Limpar sessão
        unset($_SESSION['cte_dados']);
        unset($_SESSION['cte_erros']);
        
        header('Location: cte_main_home.php');
        exit;
    } else {
        // Erro na autorização
        $erro = $std->xMotivo ?? 'Erro desconhecido';
        $codigoErro = $std->cStat ?? '';
        
        $msgErro = "Erro ao autorizar CT-e<br>";
        $msgErro .= "<strong>Código:</strong> {$codigoErro}<br>";
        $msgErro .= "<strong>Mensagem:</strong> {$erro}";
        
        setEventMessages($msgErro, null, 'errors');
        header('Location: cte_main_home.php?etapa=5');
        exit;
    }
    
} catch (Exception $e) {
    // Erro geral
    $msgErro = 'Erro na emissão do CT-e:<br><br>';
    $msgErro .= '<strong>Mensagem:</strong> ' . $e->getMessage() . '<br>';
    $msgErro .= '<strong>Arquivo:</strong> ' . $e->getFile() . '<br>';
    $msgErro .= '<strong>Linha:</strong> ' . $e->getLine();
    
    setEventMessages($msgErro, null, 'errors');
    header('Location: cte_main_home.php?etapa=5');
    exit;
}

/**
 * Montar chave de acesso
 */
function montaChave($cUF, $ano, $mes, $cnpj, $mod, $serie, $numero, $tpEmis, $codigo = '')
{
    if ($codigo == '') {
        $codigo = $numero;
    }
    $forma = "%02d%02d%02d%s%02d%03d%09d%01d%08d";
    $chave = sprintf(
        $forma, $cUF, $ano, $mes, $cnpj, $mod, $serie, $numero, $tpEmis, $codigo
    );
    return $chave . calculaDV($chave);
}

function calculaDV($chave43)
{
    $multiplicadores = array(2, 3, 4, 5, 6, 7, 8, 9);
    $iCount = 42;
    $somaPonderada = 0;
    while ($iCount >= 0) {
        for ($mCount = 0; $mCount < count($multiplicadores) && $iCount >= 0; $mCount++) {
            $num = (int)substr($chave43, $iCount, 1);
            $peso = (int)$multiplicadores[$mCount];
            $somaPonderada += $num * $peso;
            $iCount--;
        }
    }
    $resto = $somaPonderada % 11;
    if ($resto == '0' || $resto == '1') {
        $cDV = 0;
    } else {
        $cDV = 11 - $resto;
    }
    return (string)$cDV;
}

function obterCodigoUF($sigla)
{
    $ufs = [
        'AC' => 12, 'AL' => 27, 'AP' => 16, 'AM' => 13, 'BA' => 29,
        'CE' => 23, 'DF' => 53, 'ES' => 32, 'GO' => 52, 'MA' => 21,
        'MT' => 51, 'MS' => 50, 'MG' => 31, 'PA' => 15, 'PB' => 25,
        'PR' => 41, 'PE' => 26, 'PI' => 22, 'RJ' => 33, 'RN' => 24,
        'RS' => 43, 'RO' => 11, 'RR' => 14, 'SC' => 42, 'SP' => 35,
        'SE' => 28, 'TO' => 17
    ];
    return $ufs[$sigla] ?? 32;
}