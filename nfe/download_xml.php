<?php
require '../../main.inc.php';

$id = (int) GETPOST('id','int');
if ($id <= 0) { echo 'ID inválido.'; exit; }

$sql = "SELECT numero_nfe, xml_completo FROM ".MAIN_DB_PREFIX."nfe_emitidas WHERE id = ".$id;
$res = $db->query($sql);
if (!$res || $db->num_rows($res)==0) { echo 'NF-e não encontrada.'; exit; }
$obj = $db->fetch_object($res);

if (empty($obj->xml_completo)) {
    echo 'XML não armazenado.';
    exit;
}

$fname = 'nfe_'.$obj->numero_nfe.'_id'.$id.'.xml';
header('Content-Type: application/xml; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$fname.'"');
echo $obj->xml_completo;
exit;
