<?php
require '../../main.inc.php';

$numero_nfe_start = GETPOST('numero_nfe_start', 'int');
$numero_nfe_end = GETPOST('numero_nfe_end', 'int');
$data_emissao_start = GETPOST('data_emissao_start', 'alpha');
$data_emissao_end = GETPOST('data_emissao_end', 'alpha');
$search_fk_facture = GETPOST('search_fk_facture', 'int');
$search_chave = GETPOST('search_chave', 'alpha');
$search_status = GETPOST('search_status', 'alpha');
$search_client_name = GETPOST('search_client_name', 'alpha');

$sql = "SELECT numero_nfe, pdf_file, xml_completo FROM ".MAIN_DB_PREFIX."nfe_emitidas nfe
        LEFT JOIN ".MAIN_DB_PREFIX."facture f ON f.rowid = nfe.fk_facture
        LEFT JOIN ".MAIN_DB_PREFIX."societe s ON f.fk_soc = s.rowid
        WHERE 1=1";

if ($numero_nfe_start && $numero_nfe_end) {
    $sql .= " AND numero_nfe >= ".$db->escape($numero_nfe_start)." AND numero_nfe <= ".$db->escape($numero_nfe_end);
}

if ($data_emissao_start && $data_emissao_end) {
    $sql .= " AND DATE(data_emissao) >= '".$db->escape($data_emissao_start)."' AND DATE(data_emissao) <= '".$db->escape($data_emissao_end)."'";
}

if ($search_fk_facture) {
    $sql .= " AND f.rowid = ".(int)$search_fk_facture;
}

if ($search_chave) {
    $sql .= " AND nfe.chave LIKE '%".$db->escape($search_chave)."%'";
}

if ($search_status) {
    $sql .= " AND nfe.status LIKE '%".$db->escape($search_status)."%'";
}

if ($search_client_name) {
    $sql .= " AND s.nom LIKE '%".$db->escape($search_client_name)."%'";
}

$resql = $db->query($sql);

if ($resql && $db->num_rows($resql) > 0) {
    $zip = new ZipArchive();
    $zipFileName = sys_get_temp_dir() . '/nfe_lote_' . time() . '.zip';

    if ($zip->open($zipFileName, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        print 'Erro ao criar o arquivo ZIP.';
        exit;
    }

    while ($obj = $db->fetch_object($resql)) {
        if (!empty($obj->pdf_file)) {
            $zip->addFromString('danfe_nfe_' . $obj->numero_nfe . '.pdf', $obj->pdf_file);
        }
        if (!empty($obj->xml_completo)) {
            $zip->addFromString('nfe_' . $obj->numero_nfe . '.xml', $obj->xml_completo);
        }
    }

    $zip->close();

    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="nfe_lote_' . time() . '.zip"');
    header('Content-Length: ' . filesize($zipFileName));
    readfile($zipFileName);

    unlink($zipFileName); // Remove o arquivo temporário
    exit;
}

print 'Nenhum PDF ou XML encontrado para os parâmetros especificados.';
?>
