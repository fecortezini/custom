<?php
require '../../main.inc.php';

//if (!$user->rights->nfe->read) accessforbidden();

$id = GETPOST('id', 'int');

$sql = "SELECT pdf_file, numero_nfe FROM ".MAIN_DB_PREFIX."nfe_emitidas WHERE id = ".$id;
$resql = $db->query($sql);
if ($resql && $obj = $db->fetch_object($resql)) {
    if (!empty($obj->pdf_file)) {
        $numero_nfe = $obj->numero_nfe; // Obter o número da NFe
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="nfe_'.$numero_nfe.'.pdf"'); // Usar o número da NFe no nome do arquivo
        echo $obj->pdf_file;
        exit;
    }
}

print 'PDF não encontrado.';
