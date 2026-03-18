<?php
require '../../main.inc.php';
//if (empty($user->rights->nfse->read)) accessforbidden();

$id = (int) GETPOST('id', 'int');
$res = $db->query("SELECT xml_envio, xml_retorno FROM ".MAIN_DB_PREFIX."nfse WHERE rowid = ".$id." LIMIT 1");
if (!$res || !$db->num_rows($res)) accessforbidden();

$rec = $db->fetch_object($res);
header('Content-Type: text/plain; charset=UTF-8');
echo "==== XML Envio (SOAP) ====\n";
echo $rec->xml_envio;
echo "\n\n==== XML Retorno ====\n";
echo $rec->xml_retorno;
exit;
