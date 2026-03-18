<?php
/**
 * Visualização do PDF DANFSE da NFS-e Nacional
 */

// Carregamento do ambiente Dolibarr
$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
    $res = include "../../main.inc.php";
}
if (!$res) {
    die("Main include failed");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

// Controle de acesso
if (!$user->rights->facture->lire) {
    accessforbidden();
}

$id = GETPOST('id', 'int');

if (empty($id)) {
    httponly_accessforbidden('ID da NFS-e não informado');
}

// Busca registro da NFS-e
$sql = "SELECT numero_nfse, pdf_danfse, chave_acesso 
        FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas 
        WHERE id = ".(int)$id;

$resql = $db->query($sql);

if (!$resql) {
    httponly_accessforbidden('Erro ao buscar NFS-e: '.$db->lasterror());
}

if ($db->num_rows($resql) == 0) {
    httponly_accessforbidden('NFS-e não encontrada');
}

$obj = $db->fetch_object($resql);

// Verifica se existe PDF
if (empty($obj->pdf_danfse)) {
    httponly_accessforbidden('PDF da DANFSE não disponível para esta NFS-e');
}

// Normaliza BLOB/stream
$pdfContent = $obj->pdf_danfse;
if (is_resource($pdfContent)) {
    $pdfContent = stream_get_contents($pdfContent);
}

// Nome do arquivo
$filename = 'DANFSE_' . ($obj->numero_nfse ?? $obj->chave_acesso) . '.pdf';

// Define headers para visualização inline
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdfContent));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

// Envia conteúdo
echo $pdfContent;
exit;
