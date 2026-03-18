<?php
/**
 * Download do XML do CT-e
 */

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

$action = GETPOST('action', 'alpha');

// ============================================
// DOWNLOAD EM LOTE
// ============================================
if ($action === 'batch') {
    // Recuperar os mesmos filtros usados na listagem
    $search_status_list = GETPOST('search_status_list', 'alpha');
    $search_numero_cte_start = GETPOST('search_numero_cte_start', 'alpha');
    $search_numero_cte_end = GETPOST('search_numero_cte_end', 'alpha');
    $search_destinatario = GETPOST('search_destinatario', 'alpha');
    $search_data_emissao_start = GETPOST('search_data_emissao_start', 'alpha');
    $search_data_emissao_end = GETPOST('search_data_emissao_end', 'alpha');
    
    // Monta cláusulas WHERE (mesma lógica do cte_list.php)
    $whereClauses = [];
    $allowedStatuses = array('autorizado','cancelado','rejeitado','denegado','processando','erro_envio');
    $selectedStatuses = array();
    if (!empty($search_status_list)) {
        foreach (explode(',', (string)$search_status_list) as $st) {
            $st = strtolower(trim($st));
            if (in_array($st, $allowedStatuses)) {
                $selectedStatuses[] = $st;
            }
        }
        $selectedStatuses = array_values(array_unique($selectedStatuses));
    }
    if (!empty($selectedStatuses)) {
        $vals = array_map(function($v) use ($db){ return "'".$db->escape($v)."'"; }, $selectedStatuses);
        $whereClauses[] = "(LOWER(c.status) IN (".implode(',', $vals)."))";
    }

    if ($search_numero_cte_start !== '') {
        $s = trim((string)$search_numero_cte_start);
        if ($s !== '' && is_numeric($s)) {
            $whereClauses[] = "CAST(c.numero AS UNSIGNED) >= ".(int)$s;
        } elseif ($s !== '') {
            $whereClauses[] = "c.numero LIKE '%".$db->escape($s)."%'";
        }
    }
    if ($search_numero_cte_end !== '') {
        $s2 = trim((string)$search_numero_cte_end);
        if ($s2 !== '' && is_numeric($s2)) {
            $whereClauses[] = "CAST(c.numero AS UNSIGNED) <= ".(int)$s2;
        }
    }

    if (!empty($search_destinatario)) {
        $searchTerm = $db->escape(strtolower(trim($search_destinatario)));
        $whereClauses[] = "(LOWER(TRIM(c.chave)) LIKE '%" . $searchTerm . "%')";
    }

    if (!empty($search_data_emissao_start)) {
        $whereClauses[] = "DATE(c.dhemi) >= '".$db->escape($search_data_emissao_start)."'";
    }
    if (!empty($search_data_emissao_end)) {
        $whereClauses[] = "DATE(c.dhemi) <= '".$db->escape($search_data_emissao_end)."'";
    }

    $whereSQL = empty($whereClauses) ? '' : ' AND ' . implode(' AND ', $whereClauses);

    // Buscar todas as CT-es com os filtros aplicados
    $sql = "SELECT rowid, chave, numero, serie, xml_enviado 
            FROM ".MAIN_DB_PREFIX."cte_emitidos c 
            WHERE 1=1" . $whereSQL . " 
            ORDER BY c.numero ASC";
    
    $resql = $db->query($sql);
    if (!$resql) {
        setEventMessages('Erro ao buscar CT-es: '.$db->lasterror(), null, 'errors');
        header('Location: '.dol_buildpath('/custom/cte/cte_list.php', 1));
        exit;
    }
    
    $ctes = [];
    while ($obj = $db->fetch_object($resql)) {
        if (!empty($obj->xml_enviado)) {
            $ctes[] = $obj;
        }
    }
    
    if (empty($ctes)) {
        setEventMessages('Nenhum CT-e encontrado para download', null, 'warnings');
        header('Location: '.dol_buildpath('/custom/cte/cte_list.php', 1));
        exit;
    }
    
    // Criar ZIP com todos os XMLs
    $zipFilename = 'CTes_lote_'.date('Ymd_His').'.zip';
    $tempZipPath = sys_get_temp_dir().'/cte_lote_'.uniqid().'.zip';
    
    $zip = new ZipArchive();
    if ($zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
        setEventMessages('Erro ao criar arquivo ZIP', null, 'errors');
        header('Location: '.dol_buildpath('/custom/cte/cte_list.php', 1));
        exit;
    }
    
    $tipoEventoNome = [
        1 => 'cancelamento',
        2 => 'carta_correcao',
        3 => 'epec'
    ];
    
    // Processar cada CT-e
    foreach ($ctes as $cte) {
        // Criar pasta para a CT-e dentro do ZIP
        $folderName = 'CTe_'.$cte->numero.'_Serie_'.$cte->serie.'/';
        
        // Adicionar XML de emissão
        $xmlEmissaoFilename = $folderName.'CTe_'.$cte->numero.'_emissao.xml';
        $zip->addFromString($xmlEmissaoFilename, $cte->xml_enviado);
        
        // Buscar eventos relacionados
        $sqlEventos = "SELECT tipo, xml_recebido 
                      FROM ".MAIN_DB_PREFIX."cte_eventos 
                      WHERE fk_cte = ".((int)$cte->rowid)." 
                      AND xml_recebido IS NOT NULL 
                      AND xml_recebido != ''";
        $resEventos = $db->query($sqlEventos);
        
        if ($resEventos) {
            while ($evento = $db->fetch_object($resEventos)) {
                $tipoNome = isset($tipoEventoNome[$evento->tipo]) ? $tipoEventoNome[$evento->tipo] : 'evento';
                $eventoFilename = $folderName.'CTe_'.$cte->numero.'_'.$tipoNome.'.xml';
                $zip->addFromString($eventoFilename, $evento->xml_recebido);
            }
        }
    }
    
    $zip->close();
    
    // Fazer download do ZIP
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="'.$zipFilename.'"');
    header('Content-Length: '.filesize($tempZipPath));
    readfile($tempZipPath);
    
    // Limpar arquivo temporário
    @unlink($tempZipPath);
    exit;
}

// ============================================
// DOWNLOAD INDIVIDUAL
// ============================================
$id = GETPOSTINT('id');

if (!$id) {
    setEventMessages('ID não informado', null, 'errors');
    header('Location: '.dol_buildpath('/custom/cte/cte_list.php', 1));
    exit;
}

// Buscar CT-e com XML
$sql = "SELECT chave, numero, serie, xml_enviado FROM ".MAIN_DB_PREFIX."cte_emitidos WHERE rowid = ".((int)$id);
$resql = $db->query($sql);
if (!$resql || $db->num_rows($resql) == 0) {
    setEventMessages('CT-e não encontrado', null, 'errors');
    header('Location: '.dol_buildpath('/custom/cte/cte_list.php', 1));
    exit;
}

$obj = $db->fetch_object($resql);

if (empty($obj->xml_enviado)) {
    setEventMessages('XML do CT-e não disponível', null, 'errors');
    header('Location: '.dol_buildpath('/custom/cte/cte_list.php', 1));
    exit;
}

// Buscar eventos relacionados
$sqlEventos = "SELECT tipo, xml_recebido FROM ".MAIN_DB_PREFIX."cte_eventos WHERE fk_cte = ".((int)$id)." AND xml_recebido IS NOT NULL AND xml_recebido != ''";
$resEventos = $db->query($sqlEventos);
$eventos = [];
if ($resEventos) {
    while ($evento = $db->fetch_object($resEventos)) {
        $eventos[] = $evento;
    }
}

// Se não houver eventos, fazer download direto do XML
if (empty($eventos)) {
    $filename = 'CTe_'.$obj->numero.'_Serie_'.$obj->serie.'.xml';
    
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    header('Content-Length: '.strlen($obj->xml_enviado));
    echo $obj->xml_enviado;
    exit;
}

// Se houver eventos, criar ZIP com XML de emissão + eventos
$zipFilename = 'CTe_'.$obj->numero.'_Serie_'.$obj->serie.'_completo.zip';
$tempZipPath = sys_get_temp_dir().'/cte_'.uniqid().'.zip';

$zip = new ZipArchive();
if ($zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) {
    setEventMessages('Erro ao criar arquivo ZIP', null, 'errors');
    header('Location: '.dol_buildpath('/custom/cte/cte_list.php', 1));
    exit;
}

// Adicionar XML de emissão
$xmlEmissaoFilename = 'CTe_'.$obj->numero.'_Serie_'.$obj->serie.'_emissao.xml';
$zip->addFromString($xmlEmissaoFilename, $obj->xml_enviado);

// Adicionar XMLs dos eventos
$tipoEventoNome = [
    1 => 'cancelamento',
    2 => 'carta_correcao',
    3 => 'epec'
];

foreach ($eventos as $index => $evento) {
    $tipoNome = isset($tipoEventoNome[$evento->tipo]) ? $tipoEventoNome[$evento->tipo] : 'evento';
    $eventoFilename = 'CTe_'.$obj->numero.'_Serie_'.$obj->serie.'_'.$tipoNome.'.xml';
    $zip->addFromString($eventoFilename, $evento->xml_recebido);
}

$zip->close();

// Fazer download do ZIP
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="'.$zipFilename.'"');
header('Content-Length: '.filesize($tempZipPath));
readfile($tempZipPath);

// Limpar arquivo temporário
@unlink($tempZipPath);
exit;
