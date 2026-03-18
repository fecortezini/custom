<?php
require '../../main.inc.php';


// Função para download individual
function downloadSingleXml($db, $id) {
    $id = (int)$id;
    if ($id <= 0) {
        header('HTTP/1.1 404 Not Found');
        exit('ID inválido');
    }
    
    $sql = "SELECT numero_nota, xml_recebido, xml_enviado, status FROM ".MAIN_DB_PREFIX."nfse_emitidas WHERE id = ".$id;
    $res = $db->query($sql);
    
    if (!$res || $db->num_rows($res) == 0) {
        header('HTTP/1.1 404 Not Found');
        exit('NFSe não encontrada');
    }
    
    $obj = $db->fetch_object($res);
    $xml = $obj->xml_recebido ?: $obj->xml_enviado;
    
    if (empty($xml)) {
        header('HTTP/1.1 404 Not Found');
        exit('XML não disponível para esta NFSe');
    }
    
    $isCancelled = (strtolower(trim($obj->status)) === 'cancelada');
    
    if ($isCancelled) {
        // Busca XML de cancelamento
        $sqlCancel = "SELECT xml_recebido FROM ".MAIN_DB_PREFIX."nfse_eventos 
                      WHERE id_nfse_emitida = ".$id." AND tipo_evento = 'CANCELAMENTO' AND status_evento = 'APROVADO' 
                      ORDER BY id DESC LIMIT 1";
        $resCancel = $db->query($sqlCancel);
        $xmlCancel = null;
        if ($resCancel && $db->num_rows($resCancel) > 0) {
            $objCancel = $db->fetch_object($resCancel);
            $xmlCancel = $objCancel->xml_recebido;
        }
        
        if (!empty($xmlCancel)) {
            // Cria ZIP com ambos os XMLs
            $zipFilename = tempnam(sys_get_temp_dir(), 'nfse_single_') . '.zip';
            $zip = new ZipArchive();
            if ($zip->open($zipFilename, ZipArchive::CREATE) === TRUE) {
                $zip->addFromString('NFSe_' . ($obj->numero_nota ?: $id) . '.xml', $xml);
                $zip->addFromString('NFSe_' . ($obj->numero_nota ?: $id) . '_Cancelamento.xml', $xmlCancel);
                $zip->close();
                
                $downloadFilename = 'NFSe_' . ($obj->numero_nota ?: $id) . '_Completo.zip';
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $downloadFilename . '"');
                header('Content-Length: ' . filesize($zipFilename));
                readfile($zipFilename);
                unlink($zipFilename);
                exit;
            } else {
                // Fallback: baixa apenas o principal se ZIP falhar
                $filename = 'NFSe_' . ($obj->numero_nota ?: $id) . '.xml';
                header('Content-Type: application/xml; charset=utf-8');
                header('Content-Disposition: attachment; filename="' . $filename . '"');
                header('Content-Length: ' . strlen($xml));
                echo $xml;
                exit;
            }
        } else {
            // Sem XML de cancelamento, baixa apenas o principal
            $filename = 'NFSe_' . ($obj->numero_nota ?: $id) . '.xml';
            header('Content-Type: application/xml; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            header('Content-Length: ' . strlen($xml));
            echo $xml;
            exit;
        }
    } else {
        // Não cancelada: baixa apenas o XML principal
        $filename = 'NFSe_' . ($obj->numero_nota ?: $id) . '.xml';
        header('Content-Type: application/xml; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($xml));
        echo $xml;
        exit;
    }
}

// Função para download em lote
function downloadBatchXml($db) {
    // Aplica os mesmos filtros da lista - aceita tanto GET quanto POST
    $search_status = GETPOST('search_status', 'alpha');
    $search_fk_facture = GETPOST('search_fk_facture', 'int'); // Mudado para int direto
    $search_numero_nfse_start = GETPOST('search_numero_nfse_start', 'int');
    $search_numero_nfse_end = GETPOST('search_numero_nfse_end', 'int');
    $search_client_name = GETPOST('search_client_name', 'alpha');
    $search_data_emissao_start = GETPOST('search_data_emissao_start', 'alpha');
    $search_data_emissao_end = GETPOST('search_data_emissao_end', 'alpha');
    
    $sql = "SELECT id, numero_nota, xml_recebido, xml_enviado, tomador_nome, data_hora_emissao, status 
            FROM ".MAIN_DB_PREFIX."nfse_emitidas 
            WHERE 1=1";
    
    // Aplica os mesmos filtros da listagem
    if (!empty($search_status)) {
        $esc = $db->escape(strtolower(trim($search_status)));
        $sql .= " AND (LOWER(status) LIKE '%".$esc."%')";
    }
    if (!empty($search_fk_facture)) { 
        $sql .= " AND id_fatura = ".(int)$search_fk_facture; 
    }
    if (!empty($search_numero_nfse_start)) { 
        $sql .= " AND CAST(numero_nota AS UNSIGNED) >= ".(int)$search_numero_nfse_start; 
    }
    if (!empty($search_numero_nfse_end)) { 
        $sql .= " AND CAST(numero_nota AS UNSIGNED) <= ".(int)$search_numero_nfse_end; 
    }
    if (!empty($search_client_name)) {
        $searchTerm = $db->escape(strtolower(trim($search_client_name)));
        $sql .= " AND (LOWER(TRIM(tomador_nome)) LIKE '%" . $searchTerm . "%')";
    }
    if (!empty($search_data_emissao_start)) { 
        $sql .= " AND DATE(COALESCE(data_hora_emissao)) >= '".$db->escape($search_data_emissao_start)."'"; 
    }
    if (!empty($search_data_emissao_end)) { 
        $sql .= " AND DATE(COALESCE(data_hora_emissao)) <= '".$db->escape($search_data_emissao_end)."'"; 
    }
    
    // Limita para evitar sobrecarga
    $sql .= " LIMIT 100";
    
    $res = $db->query($sql);
    
    if (!$res) {
        header('HTTP/1.1 500 Internal Server Error');
        exit('Erro na consulta: ' . $db->lasterror());
    }
    
    $numRows = $db->num_rows($res);
    if ($numRows == 0) {
        header('HTTP/1.1 404 Not Found');
        exit('Nenhuma NFSe encontrada com os filtros aplicados');
    }
    
    // Verifica se a extensão ZipArchive está disponível
    if (!class_exists('ZipArchive')) {
        header('HTTP/1.1 500 Internal Server Error');
        exit('Extensão ZipArchive não está disponível no servidor');
    }
    
    // Cria arquivo ZIP temporário
    $zipFilename = tempnam(sys_get_temp_dir(), 'nfse_batch_') . '.zip';
    $zip = new ZipArchive();
    
    if ($zip->open($zipFilename, ZipArchive::CREATE) !== TRUE) {
        header('HTTP/1.1 500 Internal Server Error');
        exit('Não foi possível criar o arquivo ZIP');
    }
    
    $addedFiles = 0;
    $i = 0;
    
    while ($i < $numRows) {
        $obj = $db->fetch_object($res);
        $xml = $obj->xml_recebido ?: $obj->xml_enviado;
        
        if (!empty($xml)) {
            $filename = 'NFSe_' . ($obj->numero_nota ?: $obj->id) . '.xml';
            $zip->addFromString($filename, $xml);
            $addedFiles++;
            
            // Se cancelada, adiciona XML de cancelamento
            if (strtolower(trim($obj->status)) === 'cancelada') {
                $sqlCancel = "SELECT xml_recebido FROM ".MAIN_DB_PREFIX."nfse_eventos 
                              WHERE id_nfse_emitida = ".(int)$obj->id." AND tipo_evento = 'CANCELAMENTO' AND status_evento = 'APROVADO' 
                              ORDER BY id DESC LIMIT 1";
                $resCancel = $db->query($sqlCancel);
                if ($resCancel && $db->num_rows($resCancel) > 0) {
                    $objCancel = $db->fetch_object($resCancel);
                    if (!empty($objCancel->xml_recebido)) {
                        $cancelFilename = 'NFSe_' . ($obj->numero_nota ?: $obj->id) . '_Cancelamento.xml';
                        $zip->addFromString($cancelFilename, $objCancel->xml_recebido);
                        $addedFiles++;
                    }
                }
            }
        }
        $i++;
    }
    
    $zip->close();
    
    if ($addedFiles == 0) {
        unlink($zipFilename);
        header('HTTP/1.1 404 Not Found');
        exit('Nenhum XML disponível para as NFSe encontradas');
    }
    
    // Envia o arquivo ZIP
    $downloadFilename = 'NFSe_Lote_' . date('Y-m-d_H-i-s') . '.zip';
    
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $downloadFilename . '"');
    header('Content-Length: ' . filesize($zipFilename));
    
    readfile($zipFilename);
    unlink($zipFilename);
    exit;
}

// Determina a ação - aceita tanto GET quanto POST
$action = GETPOST('action', 'alpha');

// Se não há ação especificada, verifica se é um POST para o download em lote
if (empty($action) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Se é um POST e não tem action, assume que é download em lote
    $action = 'batch';
}

if ($action === 'single') {
    $id = GETPOST('id', 'int');
    downloadSingleXml($db, $id);
} elseif ($action === 'batch') {
    downloadBatchXml($db);
} else {
    // Retorna uma página de erro mais amigável
    llxHeader('', 'Erro - Download NFSe');
    print '<div class="fiche">';
    print '<div class="titre">Download de NFSe - Erro</div>';
    print '<div class="error">Ação não especificada ou inválida.</div>';
    print '<p>Ações disponíveis:</p>';
    print '<ul>';
    print '<li><code>?action=single&id=123</code> - Download de uma NFSe específica</li>';
    print '<li><code>?action=batch</code> - Download em lote (ZIP)</li>';
    print '</ul>';
    print '<p><a href="nfse_list.php">← Voltar para a lista de NFSe</a></p>';
    print '</div>';
    llxFooter();
}
?>
