<?php
require '../../main.inc.php';

$id = (int) GETPOST('id','int');
if ($id <= 0) { echo 'ID inválido.'; exit; }

$sql = "SELECT id, fk_nfe_origem FROM ".MAIN_DB_PREFIX."nfe_emitidas WHERE id = ".$id;
$res = $db->query($sql);
if (!$res || $db->num_rows($res) == 0) { echo 'NF-e não encontrada.'; exit; }
$base = $db->fetch_object($res);
$rootId = $base->fk_nfe_origem ? (int)$base->fk_nfe_origem : (int)$base->id;

$sqlAll = "SELECT id, fk_nfe_origem, numero_nfe, chave, status, data_emissao
           FROM ".MAIN_DB_PREFIX."nfe_emitidas
           WHERE id = ".$rootId." OR fk_nfe_origem = ".$rootId."
           ORDER BY data_emissao ASC, id ASC";
$resAll = $db->query($sqlAll);

echo '<style>
.tbl-docs-nfe{width:100%;border-collapse:collapse;font-size:0.85rem;}
.tbl-docs-nfe th,.tbl-docs-nfe td{border:1px solid #ddd;padding:6px;text-align:center;}
.tbl-docs-nfe th{background:#f7f7f7;}
.badgeR{display:inline-block;padding:3px 7px;border-radius:4px;font-size:0.7rem;font-weight:600;color:#fff;}
.badgeOrig{background:#6c757d;}
.badgeDev{background:#007bff;}
.wrap-actions a{margin:0 4px;text-decoration:none;color:#0d6efd;font-weight:600;}
.wrap-actions a:hover{text-decoration:underline;}
.small-date{font-size:0.7rem;color:#555;}
</style>';

echo '<table class="tbl-docs-nfe">';
echo '<tr><th>ID</th><th>Tipo</th><th>Número</th><th>Status</th><th>Chave</th><th>Data</th><th>Downloads</th></tr>';

while ($row = $db->fetch_object($resAll)) {
    $isDev = !empty($row->fk_nfe_origem);
    $tipo = $isDev ? '<span class="badgeR badgeDev">Devolução</span>' : '<span class="badgeR badgeOrig">Original</span>';
    $chaveShort = $row->chave ? substr($row->chave,0,8).'...'.substr($row->chave,-6) : '-';
    $pdfUrl = DOL_URL_ROOT.'/custom/nfe/download_pdf.php?id='.$row->id;
    $xmlUrl = DOL_URL_ROOT.'/custom/nfe/download_xml.php?id='.$row->id;
    echo '<tr>
        <td>'.$row->id.'</td>
        <td>'.$tipo.'</td>
        <td>'.$row->numero_nfe.'</td>
        <td>'.dol_escape_htmltag($row->status).'</td>
        <td title="'.dol_escape_htmltag($row->chave).'">'.$chaveShort.'</td>
        <td><span class="small-date">'.dol_print_date(dol_stringtotime($row->data_emissao),'dayhour').'</span></td>
        <td class="wrap-actions">
            <a href="'.$pdfUrl.'" target="_blank">PDF</a>
            <a href="'.$xmlUrl.'" target="_blank">XML</a>
        </td>
    </tr>';
}
echo '</table>';
echo '<div style="margin-top:6px;font-size:0.65rem;color:#666;">Use CTRL+Clique (ou abrir em nova aba) para múltiplos downloads.</div>';
echo '</table>';

echo '<div style="margin-top:8px;font-size:0.8em;color:#555;">Você pode abrir múltiplos PDFs/XML em abas separadas.</div>';
