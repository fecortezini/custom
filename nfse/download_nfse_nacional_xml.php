<?php
/**
 * Download de XML da NFS-e Nacional (individual ou em lote)
 */
require '../../main.inc.php';

/** @var DoliDB $db */
/** @var User $user */

$action = GETPOST('action', 'alpha');
$id = GETPOST('id', 'int');

// Download individual
if ($action === 'single' && $id > 0) {
    // 1. Busca dados da NFS-e principal
    $sql = "SELECT numero_nfse, xml_nfse, status 
            FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas 
            WHERE id = ".(int)$id;
    
    $res = $db->query($sql);
    
    if (!$res || $db->num_rows($res) == 0) {
        header('HTTP/1.1 404 Not Found');
        echo 'NFS-e não encontrada';
        exit;
    }
    
    $nfse = $db->fetch_object($res);
    $filesToDownload = [];

    // Adiciona XML da NFS-e se existir
    if (!empty($nfse->xml_nfse)) {
        $numeroNfse = $nfse->numero_nfse ?: 'DPS_'.$id;
        $filesToDownload['NFSeNacional_'.$numeroNfse.'.xml'] = $nfse->xml_nfse;
    }
    
    // 2. Busca eventos relacionados (Cancelamento, etc.) que tenham xml_retorno salvo
    $sqlEventos = "SELECT tipo_evento, xml_retorno, id 
                   FROM ".MAIN_DB_PREFIX."nfse_nacional_eventos 
                   WHERE id_nfse = ".(int)$id." AND xml_retorno IS NOT NULL AND xml_retorno != ''";
    
    $resEventos = $db->query($sqlEventos);
    if ($resEventos) {
        while ($evento = $db->fetch_object($resEventos)) {
            // Nome do arquivo para o evento
            $nomeEvento = 'Evento_'.$evento->tipo_evento.'_'.$evento->id.'.xml';
            
            // Tenta identificar se é cancelamento para dar um nome mais amigável
            if ($evento->tipo_evento == 'e101101') {
                $nomeEvento = 'Cancelamento_NFSe_'.($nfse->numero_nfse ?: $id).'.xml';
            }
            
            // Evita sobreposição de nomes
            if (isset($filesToDownload[$nomeEvento])) {
                 $nomeEvento = 'Evento_'.$evento->tipo_evento.'_'.$evento->id.'_dup.xml';
            }
            
            $filesToDownload[$nomeEvento] = $evento->xml_retorno;
        }
    }
    
    // Se não encontrou nenhum arquivo
    if (empty($filesToDownload)) {
        header('HTTP/1.1 404 Not Found');
        echo 'Nenhum XML encontrado para esta NFS-e';
        exit;
    }

    // 3. Decisão: 1 arquivo -> download direto / >1 arquivo -> ZIP
    if (count($filesToDownload) == 1) {
        reset($filesToDownload); // Garante ponteiro no início
        $filename = key($filesToDownload);
        $content = current($filesToDownload);
        
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$filename.'"');
        header('Content-Length: '.strlen($content));
        
        echo $content;
        exit;
    } else {
        // Multiplos arquivos: cria ZIP
        $zipFilename = tempnam(sys_get_temp_dir(), 'nfse_pkg_');
        $zip = new ZipArchive();
        
        if ($zip->open($zipFilename, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
             header('HTTP/1.1 500 Internal Server Error');
             echo 'Erro ao criar arquivo ZIP temporário';
             exit;
        }
        
        foreach ($filesToDownload as $fname => $fcontent) {
            $zip->addFromString($fname, $fcontent);
        }
        
        $zip->close();
        
        $downloadName = 'Pacote_XMLs_NFSe_'.($nfse->numero_nfse ?: $id).'.zip';
        
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="'.$downloadName.'"');
        header('Content-Length: '.filesize($zipFilename));
        
        readfile($zipFilename);
        @unlink($zipFilename);
        exit;
    }
}

// Download em lote (via GET com filtros da busca)
if ($action === 'batch') {
    // Aplica os mesmos filtros da lista
    $search_status_list = GETPOST('search_status_list', 'alpha');
    $search_fk_facture = GETPOST('search_fk_facture', 'alpha');
    $search_numero_nfse_start = GETPOST('search_numero_nfse_start', 'alpha');
    $search_numero_nfse_end = GETPOST('search_numero_nfse_end', 'alpha');
    $search_client_name = GETPOST('search_client_name', 'alpha');
    $search_data_emissao_start = GETPOST('search_data_emissao_start', 'alpha');
    $search_data_emissao_end = GETPOST('search_data_emissao_end', 'alpha');
    
    // Monta SQL com os mesmos filtros da listagem
    $whereClauses = [];
    
    // Filtro de status
    $allowedStatuses = array('pendente','enviando','autorizada','rejeitada','cancelada','erro');
    $selectedStatuses = array();
    if (!empty($search_status_list)) {
        foreach (explode(',', (string)$search_status_list) as $st) {
            $st = trim(strtolower($st));
            if (in_array($st, $allowedStatuses, true)) {
                $selectedStatuses[] = $st;
            }
        }
        $selectedStatuses = array_values(array_unique($selectedStatuses));
    }
    if (!empty($selectedStatuses)) {
        $vals = array_map(function($v) use ($db){ return "'".$db->escape($v)."'"; }, $selectedStatuses);
        $whereClauses[] = "(LOWER(n.status) IN (".implode(',', $vals)."))";
    }
    
    // Filtro de fatura
    if (trim((string)$search_fk_facture) !== '') {
        $s = trim((string)$search_fk_facture);
        if (is_numeric($s)) {
            $whereClauses[] = "n.id_fatura = ".(int)$s;
        }
    }
    
    // Filtro de número DPS/NFS-e
    if ($search_numero_nfse_start !== '') {
        $s = trim((string)$search_numero_nfse_start);
        if ($s !== '' && is_numeric($s)) {
            $whereClauses[] = "(CAST(COALESCE(n.numero_nfse, n.numero_dps) AS UNSIGNED) >= ".(int)$s.")";
        }
    }
    if ($search_numero_nfse_end !== '') {
        $s2 = trim((string)$search_numero_nfse_end);
        if ($s2 !== '' && is_numeric($s2)) {
            $whereClauses[] = "(CAST(COALESCE(n.numero_nfse, n.numero_dps) AS UNSIGNED) <= ".(int)$s2.")";
        }
    }
    
    // Filtro de nome do tomador
    if (!empty($search_client_name)) {
        $searchTerm = $db->escape(strtolower(trim($search_client_name)));
        $whereClauses[] = "(LOWER(TRIM(n.tomador_nome)) LIKE '%" . $searchTerm . "%')";
    }
    
    // Filtros de data
    if (!empty($search_data_emissao_start)) {
        $whereClauses[] = "DATE(n.data_emissao) >= '".$db->escape($search_data_emissao_start)."'";
    }
    if (!empty($search_data_emissao_end)) {
        $whereClauses[] = "DATE(n.data_emissao) <= '".$db->escape($search_data_emissao_end)."'";
    }
    
    $whereSQL = empty($whereClauses) ? '' : ' AND ' . implode(' AND ', $whereClauses);
    
    $sql = "SELECT id, numero_dps, numero_nfse, xml_nfse 
            FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas n
            WHERE 1=1" . $whereSQL . "
            LIMIT 100";
    
    $res = $db->query($sql);
    
    if (!$res) {
        header('HTTP/1.1 500 Internal Server Error');
        echo 'Erro na consulta: ' . $db->lasterror();
        exit;
    }
    
    $numRows = $db->num_rows($res);
    
    if ($numRows === 0) {
        header('Location: nfse_list.php?error=no_records_found');
        exit;
    }
    
    // Cria arquivo ZIP
    $zipFilename = tempnam(sys_get_temp_dir(), 'nfse_nacional_');
    $zip = new ZipArchive();
    
    if ($zip->open($zipFilename, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        header('Location: nfse_list.php?error=zip_creation_failed');
        exit;
    }
    
    $added = 0;
    while ($obj = $db->fetch_object($res)) {
        $filesForThisNfse = [];
        
        // 1. XML da NFS-e
        if (!empty($obj->xml_nfse)) {
            $numeroNfse = $obj->numero_nfse ?: $obj->numero_dps;
            $filesForThisNfse['NFSeNacional_'.$numeroNfse.'.xml'] = $obj->xml_nfse;
        }

        // 2. XMLs de Eventos (Cancelamento, etc.)
        $sqlEventos = "SELECT tipo_evento, xml_retorno, id 
                       FROM ".MAIN_DB_PREFIX."nfse_nacional_eventos 
                       WHERE id_nfse = ".(int)$obj->id." AND xml_retorno IS NOT NULL AND xml_retorno != ''";
        $resEventos = $db->query($sqlEventos);
        if ($resEventos) {
            while ($evt = $db->fetch_object($resEventos)) {
                 $nomeEvt = 'Evento_'.$evt->tipo_evento.'_'.$evt->id.'.xml';
                 if ($evt->tipo_evento == 'e101101') {
                      $numRef = $obj->numero_nfse ?: $obj->numero_dps;
                      $nomeEvt = 'Cancelamento_NFSe_'.$numRef.'.xml';
                 }
                 // Evita duplicidade no pacote
                 if (isset($filesForThisNfse[$nomeEvt])) {
                     $nomeEvt = 'Evento_'.uniqid().'.xml'; 
                 }
                 $filesForThisNfse[$nomeEvt] = $evt->xml_retorno;
            }
        }

        // Adiciona ao ZIP
        foreach ($filesForThisNfse as $name => $content) {
             $zip->addFromString($name, $content);
             $added++;
        }
    }
    
    $zip->close();
    
    if ($added === 0) {
        @unlink($zipFilename);
        header('Location: nfse_list.php?error=no_xml_found');
        exit;
    }
    
    // Envia ZIP para download
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="NFSeNacional_Lote_'.date('Ymd_His').'.zip"');
    header('Content-Length: '.filesize($zipFilename));
    
    readfile($zipFilename);
    @unlink($zipFilename);
    exit;
}

header('Location: nfse_list.php');
exit;
