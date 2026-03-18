<?php
/**
 * Visualizador de PDF da NF-e (DANFE)
 * Retorna o PDF para ser exibido em modal
 */

require '../../main.inc.php';

$id = GETPOSTINT('id');

if ($id <= 0) {
    http_response_code(400);
    echo 'ID inválido';
    exit;
}

// Busca o PDF no banco
$sql = "SELECT pdf_file FROM ".MAIN_DB_PREFIX."nfe_emitidas WHERE id = ".(int)$id;
$resql = $db->query($sql);

if (!$resql) {
    http_response_code(500);
    echo 'Erro ao buscar PDF';
    exit;
}

if ($db->num_rows($resql) === 0) {
    http_response_code(404);
    echo 'NF-e não encontrada';
    exit;
}

$obj = $db->fetch_object($resql);

if (empty($obj->pdf_file)) {
    http_response_code(404);
    echo 'PDF não disponível para esta NF-e';
    exit;
}

// Define headers para PDF
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="nfe_'.$id.'.pdf"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
header('Content-Length: ' . strlen($obj->pdf_file));

// Envia o PDF
echo $obj->pdf_file;
exit;
