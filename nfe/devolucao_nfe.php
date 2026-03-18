<?php
require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/custom/composerlib/vendor/autoload.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/nfe/emissao_nfe.php'; // Inclui o arquivo com a função gerarNfe()
require_once DOL_DOCUMENT_ROOT.'/custom/nfe/lib/nfe_security.lib.php';


use NFePHP\NFe\Make;
use NFePHP\NFe\Tools;
use NFePHP\Common\Certificate;
use NFePHP\NFe\Common\Standardize;

$idNFeOrigem = GETPOST('id_nfe_origem', 'int');
if (!$idNFeOrigem) {
    accessforbidden($langs->trans("Nota Fiscal de origem não especificada."));
}

// Busca os dados da NF-e original no banco
$sql = "SELECT * FROM " . MAIN_DB_PREFIX . "nfe_emitidas WHERE id = " . (int)$idNFeOrigem;
$resql = $db->query($sql);
if (!$resql || $db->num_rows($resql) == 0) {
    accessforbidden($langs->trans("Nota Fiscal de origem não encontrada."));
}
$nfeOrigem = $db->fetch_object($resql);

// Decodifica o XML da NF-e original
$xmlOrigem = $nfeOrigem->xml_completo;
$stdOrigem = (new Standardize())->toStd($xmlOrigem);
//var_dump($stdOrigem);
// Adiciona logs para verificar os dados extraídos do XML
dol_syslog("Dados do emitente extraídos do XML: " . var_export($stdOrigem->NFe->infNFe->emit, true), LOG_DEBUG);
dol_syslog("Dados do destinatário extraídos do XML: " . var_export($stdOrigem->NFe->infNFe->dest, true), LOG_DEBUG);

// Verifica e registra os dados do endereço do destinatário
if (empty($stdOrigem->NFe->infNFe->dest->enderDest->xLgr) ||
    empty($stdOrigem->NFe->infNFe->dest->enderDest->nro) ||
    empty($stdOrigem->NFe->infNFe->dest->enderDest->xBairro)) {
    error_log("Erro: Campos obrigatórios do endereço do destinatário estão ausentes.");
    error_log("Endereço do destinatário: " . var_export($stdOrigem->NFe->infNFe->dest->enderDest, true));
    accessforbidden("Erro: Campos obrigatórios do endereço do destinatário estão ausentes.");
}

// Ajusta os dados para a devolução
$mysoc = [
    'cnpj' => $stdOrigem->NFe->infNFe->emit->CNPJ,
    'nome' => $stdOrigem->NFe->infNFe->emit->xNome,
    'uf' => $stdOrigem->NFe->infNFe->emit->enderEmit->UF,
    'crt' => $stdOrigem->NFe->infNFe->emit->CRT,
    'ie' => $stdOrigem->NFe->infNFe->emit->IE,
    'rua' => $stdOrigem->NFe->infNFe->emit->enderEmit->xLgr,
    'numero' => $stdOrigem->NFe->infNFe->emit->enderEmit->nro,
    'bairro' => $stdOrigem->NFe->infNFe->emit->enderEmit->xBairro,
    'cidade' => $stdOrigem->NFe->infNFe->emit->enderEmit->xMun,
    'cep' => $stdOrigem->NFe->infNFe->emit->enderEmit->CEP,
    'cod_municipio' => $stdOrigem->NFe->infNFe->emit->enderEmit->cMun,
    'pais' => $stdOrigem->NFe->infNFe->emit->enderEmit->xPais ?? 'Brasil',
];

$dest = [
    'cnpj' => $stdOrigem->NFe->infNFe->dest->CNPJ,
    'nome' => $stdOrigem->NFe->infNFe->dest->xNome,
    'uf' => $stdOrigem->NFe->infNFe->dest->enderDest->UF,
    'extrafields' => [
        'indiedest' => $stdOrigem->NFe->infNFe->dest->indIEDest,
        // Endereço também dentro de extrafields para retrocompatibilidade
        'rua' => $stdOrigem->NFe->infNFe->dest->enderDest->xLgr,
        'numero_de_endereco' => $stdOrigem->NFe->infNFe->dest->enderDest->nro,
        'bairro' => $stdOrigem->NFe->infNFe->dest->enderDest->xBairro,
        'codigo_do_municipio' => $stdOrigem->NFe->infNFe->dest->enderDest->cMun,
    ],
    // Mesmos campos no nível raiz (caso a função consuma assim)
    'rua' => $stdOrigem->NFe->infNFe->dest->enderDest->xLgr,
    'numero_de_endereco' => $stdOrigem->NFe->infNFe->dest->enderDest->nro,
    'bairro' => $stdOrigem->NFe->infNFe->dest->enderDest->xBairro,
    'cidade' => $stdOrigem->NFe->infNFe->dest->enderDest->xMun,
    'cep' => $stdOrigem->NFe->infNFe->dest->enderDest->CEP,
    'codigo_do_municipio' => $stdOrigem->NFe->infNFe->dest->enderDest->cMun,
    'pais' => $stdOrigem->NFe->infNFe->dest->enderDest->xPais ?? 'Brasil',
    'ie' => $stdOrigem->NFe->infNFe->dest->IE ?? null,
    'fone' => $stdOrigem->NFe->infNFe->dest->enderDest->fone ?? null,
];

// Log resumido pós normalização
error_log("DEVOLUCAO - Destinatário normalizado: ".json_encode([
    'rua'=>$dest['rua'],
    'numero'=>$dest['numero_de_endereco'],
    'bairro'=>$dest['bairro'],
    'cidade'=>$dest['cidade'],
    'cMun'=>$dest['codigo_do_municipio'],
    'uf'=>$dest['uf']
], JSON_UNESCAPED_UNICODE));

// Adiciona logs para verificar os dados que serão enviados para a função gerarNfe
dol_syslog("Dados do emitente para gerarNfe: " . var_export($mysoc, true), LOG_DEBUG);
dol_syslog("Dados do destinatário para gerarNfe: " . var_export($dest, true), LOG_DEBUG);

function _extrairOrigICMS($impostoICMSStd) {
    if (empty($impostoICMSStd)) return '0';
    // Pode vir como objeto com filhos (ICMS00, ICMSSN102, etc.)
    foreach ($impostoICMSStd as $k => $v) {
        if (is_object($v) && isset($v->orig)) {
            return (string)$v->orig;
        }
    }
    // fallback direto
    return isset($impostoICMSStd->orig) ? (string)$impostoICMSStd->orig : '0';
}

/**
 * Extrai o CSOSN original do XML (ex: 101, 201, 500)
 * @param object $impostoICMSStd Nó ICMS do XML
 * @return string CSOSN extraído (ex: '101', '500')
 */
function _extrairCSOSNOriginal($impostoICMSStd) {
    if (empty($impostoICMSStd)) return '102'; // Fallback padrão
    
    // Lista de possíveis CSOSN
    $csosnTags = ['ICMSSN101', 'ICMSSN102', 'ICMSSN103', 'ICMSSN201', 'ICMSSN202', 'ICMSSN203', 
                  'ICMSSN300', 'ICMSSN400', 'ICMSSN500', 'ICMSSN900'];
    
    foreach ($csosnTags as $tag) {
        if (isset($impostoICMSStd->$tag) && isset($impostoICMSStd->$tag->CSOSN)) {
            return (string)$impostoICMSStd->$tag->CSOSN;
        }
    }
    
    return '102'; // Fallback padrão
}

/**
 * Extrai tributos (ICMS, PIS, COFINS) do item do XML original
 * @param object $item Item do XML (det)
 * @return array Tributos extraídos
 */
function _extrairTributosOriginais($item) {
    $tributos = [
        'csosn_original' => '102', // NOVO: Preserva CSOSN original
        'icms_cred_aliq' => 0,
        'icms_st_mva' => 0,
        'icms_st_aliq' => 0,
        'icms_st_red_bc' => 0,
        'icms_aliq_inter' => 0,
        'pis_cst' => '49',
        'pis_aliq' => 0,
        'cofins_cst' => '49',
        'cofins_aliq' => 0
    ];
    
    // Extrai ICMS (pode estar em ICMSSN101, ICMSSN201, ICMSSN102, etc.)
    if (!empty($item->imposto->ICMS)) {
        // NOVO: Extrai CSOSN original
        $tributos['csosn_original'] = _extrairCSOSNOriginal($item->imposto->ICMS);
        error_log("DEBUG ICMS: CSOSN original extraído = {$tributos['csosn_original']}");
        
        // CORREÇÃO: Acesso direto às propriedades do ICMS
        $icmsObj = $item->imposto->ICMS;
        
        // Tenta acessar todas as possíveis tags de CSOSN
        $csosnTags = ['ICMSSN101', 'ICMSSN102', 'ICMSSN103', 'ICMSSN201', 'ICMSSN202', 'ICMSSN203', 
                      'ICMSSN300', 'ICMSSN400', 'ICMSSN500', 'ICMSSN900'];
        
        foreach ($csosnTags as $tag) {
            if (isset($icmsObj->$tag)) {
                $icmsData = $icmsObj->$tag;
                
                // Crédito de ICMS (CSOSN 101/201)
                if (isset($icmsData->pCredSN)) {
                    $tributos['icms_cred_aliq'] = (float)$icmsData->pCredSN;
                }
                
                // ST (CSOSN 201/202/203)
                if (isset($icmsData->pMVAST)) {
                    $tributos['icms_st_mva'] = (float)$icmsData->pMVAST;
                }
                if (isset($icmsData->pICMSST)) {
                    $tributos['icms_st_aliq'] = (float)$icmsData->pICMSST;
                }
                if (isset($icmsData->pRedBCST)) {
                    $tributos['icms_st_red_bc'] = (float)$icmsData->pRedBCST;
                }
                
                break; // Pega o primeiro CSOSN encontrado
            }
        }
    }
    
    // Extrai PIS
    if (!empty($item->imposto->PIS)) {
        $pisObj = $item->imposto->PIS;
        
        // Possíveis tags PIS
        $pisTags = ['PISAliq', 'PISQtde', 'PISNT', 'PISOutr'];
        
        foreach ($pisTags as $tag) {
            if (isset($pisObj->$tag)) {
                $pisData = $pisObj->$tag;
                
                if (isset($pisData->CST)) {
                    $tributos['pis_cst'] = (string)$pisData->CST;
                }
                if (isset($pisData->pPIS)) {
                    $tributos['pis_aliq'] = (float)$pisData->pPIS;
                }
                
                break; // Pega o primeiro válido
            }
        }
    }
    
    // Extrai COFINS
    if (!empty($item->imposto->COFINS)) {
        $cofinsObj = $item->imposto->COFINS;
        
        // Possíveis tags COFINS
        $cofinsTags = ['COFINSAliq', 'COFINSQtde', 'COFINSNT', 'COFINSOutr'];
        
        foreach ($cofinsTags as $tag) {
            if (isset($cofinsObj->$tag)) {
                $cofinsData = $cofinsObj->$tag;
                
                if (isset($cofinsData->CST)) {
                    $tributos['cofins_cst'] = (string)$cofinsData->CST;
                }
                if (isset($cofinsData->pCOFINS)) {
                    $tributos['cofins_aliq'] = (float)$cofinsData->pCOFINS;
                }
                
                break; // Pega o primeiro válido
            }
        }
    }
    
    // Log de debug para verificar extração
    error_log("DEVOLUCAO - Tributos extraídos: " . json_encode($tributos, JSON_UNESCAPED_UNICODE));
    
    return $tributos;
}

// Normaliza itens (det pode ser objeto único)
$detItens = $stdOrigem->NFe->infNFe->det ?? [];
if (!is_array($detItens)) {
    // Se for um único item obj, encapsula
    $detItens = [$detItens];
}

$products = [];
$idx = 0;
foreach ($detItens as $item) {
    $idx++;
    if (empty($item->prod->cProd) || empty($item->prod->xProd)) {
        // Log e continua (não adiciona item inconsistente)
        error_log("DEVOLUCAO: Item $idx sem cProd/xProd no XML original. Ignorado.");
        continue;
    }

    $cProd   = (string)$item->prod->cProd;
    $xProd   = (string)$item->prod->xProd;
    $qCom    = (string)$item->prod->qCom;
    $vUnCom  = (string)$item->prod->vUnCom;
    $vProd   = (string)$item->prod->vProd;

    // Origem do produto (ICMS)
    $origIcms = _extrairOrigICMS($item->imposto->ICMS ?? null);
   
   // NOVO: Extrai tributos do XML original
   $tributos = _extrairTributosOriginais($item);

    $products[] = [
        'ref'                  => $cProd,
        'nome'                 => $xProd,
        'descricao'            => $xProd,
        'quantidade'           => $qCom,
        'preco_venda_semtaxa'  => $vUnCom,
        'total_semtaxa'        => $vProd,
        'total_comtaxa'        => $vProd,
        'imposto_%'            => 0,
        'extrafields' => [
            'prd_ncm'          => (string)($item->prod->NCM ?? ''),
            'prd_cest'         => (string)($item->prod->CEST ?? ''),
            'prd_regime_icms'  => '1',
            'prd_origem'       => $origIcms,
            // NOVO: CSOSN original (para usar direto na devolução)
            'csosn_original'   => $tributos['csosn_original'],
            // NOVO: Tributos da nota original (para emissao_nfe.php usar)
            'icms_cred_aliq'   => $tributos['icms_cred_aliq'],
            'icms_st_mva'      => $tributos['icms_st_mva'],
            'icms_st_aliq'     => $tributos['icms_st_aliq'],
            'icms_st_red_bc'   => $tributos['icms_st_red_bc'],
            'icms_aliq_inter'  => $tributos['icms_aliq_inter'],
            'pis_cst'          => $tributos['pis_cst'],
            'pis_aliq'         => $tributos['pis_aliq'],
            'cofins_cst'       => $tributos['cofins_cst'],
            'cofins_aliq'      => $tributos['cofins_aliq'],
        ],
    ];
}

// Log para debug (verificar se tributos foram extraídos)
dol_syslog("DEVOLUCAO - Tributos extraídos do primeiro item: " . json_encode($products[0]['extrafields'] ?? [], JSON_UNESCAPED_UNICODE), LOG_DEBUG);

// Valida se ao menos 1 item coerente foi montado
if (empty($products)) {
    setEventMessages("Nenhum item válido encontrado para gerar a NF-e de devolução (cProd/xProd ausentes).", null, 'errors');
    header("Location: ".DOL_URL_ROOT."/custom/nfe/list.php");
    exit;
}

// Adiciona chave da NF-e origem (coluna 'chave')
$chaveOrigem = !empty($nfeOrigem->chave) ? $nfeOrigem->chave : null;


$fatura = [
    'id' => $nfeOrigem->fk_facture,
    'extrafields' => [
        'nat_op'        => 'Devolução de Mercadoria',
        'indpres'       => '1',
        'is_devolucao'  => 1,
        'fk_nfe_origem' => $nfeOrigem->id,
        'chave_origem'  => $chaveOrigem,
        'fin_nfe'       => 4,
    ],
    'forma_pagamento' => '90',
    'tpNF'            => 0,  // entrada
    'finNFe'          => 4,  // devolução
];

dol_syslog('[NFE DEV SCRIPT] Emissão devolução -> tpNF='.$fatura['tpNF'].' finNFe='.$fatura['finNFe'].' chave_origem='.($chaveOrigem?:'(vazia)'), LOG_DEBUG);

function obterUltimaNfeId($db, $factureId)
{
    $factureId = (int) $factureId;
    if ($factureId <= 0) {
        return 0;
    }

    $sql = "SELECT id FROM ".MAIN_DB_PREFIX."nfe_emitidas WHERE fk_facture = ".$factureId." ORDER BY id DESC LIMIT 1";
    $res = $db->query($sql);
    if ($res && $db->num_rows($res) > 0) {
        return (int) $db->fetch_object($res)->id;
    }
    return 0;
}

$idAntes = obterUltimaNfeId($db, $nfeOrigem->fk_facture);

try {
    gerarNfe($db, $mysoc, $dest, $products, $fatura);
    $idDepois = obterUltimaNfeId($db, $nfeOrigem->fk_facture);
    if (!$idDepois || $idDepois === $idAntes) {
        throw new Exception($langs->trans("Erro ao gerar NF-e de devolução. Verifique os logs."));
    }
    setEventMessages($langs->trans("NF-e de devolução gerada com sucesso!"), null, 'mesgs');
} catch (Exception $e) {
    setEventMessages($e->getMessage(), null, 'errors');
    header("Location: " . $_SERVER['PHP_SELF'].'?id_nfe_origem='.(int)$idNFeOrigem);
    exit;
}
header("Location: " . DOL_URL_ROOT . "/custom/nfe/list.php");
exit;
?>
