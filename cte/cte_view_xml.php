<?php
/**
 * Visualização de XML do CT-e
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

$id = GETPOSTINT('id');

if (!$id) {
    accessforbidden('ID não informado');
}

// Buscar CT-e
$sql = "SELECT chave FROM ".MAIN_DB_PREFIX."cte_emitidos WHERE rowid = ".((int)$id);
$resql = $db->query($sql);
if (!$resql || $db->num_rows($resql) == 0) {
    accessforbidden('CT-e não encontrado');
}

$obj = $db->fetch_object($resql);
$xmlPath = DOL_DOCUMENT_ROOT.'/custom/cte/xml/'.$obj->chave.'-cte.xml';

if (!file_exists($xmlPath)) {
    accessforbidden('Arquivo XML não encontrado');
}

$xml = file_get_contents($xmlPath);
$dom = new DOMDocument();
$dom->loadXML($xml);
$dom->formatOutput = true;
$xmlFormatted = htmlspecialchars($dom->saveXML());

llxHeader('', 'Visualizar XML - CT-e');

print load_fiche_titre('Visualizar XML do CT-e', '', 'cte@cte');

print '<div style="margin-bottom: 20px;">';
print '<a href="cte_list.php" class="butAction"><span class="fa fa-arrow-left"></span> Voltar</a>';
print '<a href="cte_download_xml.php?id='.$id.'" class="butAction"><span class="fa fa-download"></span> Download</a>';
print '</div>';

print '<div style="background: #f8f9fa; padding: 20px; border-radius: 8px; border: 1px solid #dee2e6;">';
print '<pre style="background: #fff; padding: 15px; overflow-x: auto; border: 1px solid #ddd; border-radius: 4px; font-size: 12px; line-height: 1.5;">';
print $xmlFormatted;
print '</pre>';
print '</div>';

llxFooter();
$db->close();
