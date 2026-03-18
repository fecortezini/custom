<?php
/**
 * Visualização do XML da NFS-e Nacional
 */
require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';

/** @var DoliDB $db */
/** @var User $user */
/** @var Translate $langs */

$id = GETPOST('id', 'int');

llxHeader('', 'Visualizar XML - NFS-e Nacional');

print load_fiche_titre('XML da NFS-e Nacional');

if ($id > 0) {
    $sql = "SELECT 
                id,
                numero_dps,
                numero_nfse,
                chave_acesso,
                status,
                xml_enviado,
                xml_nfse,
                xml_retorno
            FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas 
            WHERE id = ".(int)$id;
    
    $res = $db->query($sql);
    
    if ($res && $db->num_rows($res) > 0) {
        $obj = $db->fetch_object($res);
        
        print '<div class="tabBar">';
        
        // Informações básicas
        print '<div class="fichecenter">';
        print '<table class="border centpercent">';
        print '<tr><td class="titlefield">ID</td><td>'.$obj->id.'</td></tr>';
        print '<tr><td>DPS</td><td>'.dol_escape_htmltag($obj->numero_dps).'</td></tr>';
        print '<tr><td>NFS-e</td><td>'.dol_escape_htmltag($obj->numero_nfse ?: '-').'</td></tr>';
        print '<tr><td>Chave de Acesso</td><td>'.dol_escape_htmltag($obj->chave_acesso ?: '-').'</td></tr>';
        print '<tr><td>Status</td><td><strong>'.strtoupper($obj->status).'</strong></td></tr>';
        print '</table>';
        print '</div>';
        
        // XML da DPS Enviada
        if (!empty($obj->xml_enviado)) {
            print '<br><h3>XML da DPS Enviada</h3>';
            print '<div style="background:#f5f5f5; padding:10px; border:1px solid #ddd; border-radius:4px;">';
            print '<pre style="white-space:pre-wrap; word-wrap:break-word; font-family:monospace; font-size:12px;">';
            print dol_escape_htmltag(formatXml($obj->xml_enviado));
            print '</pre>';
            print '</div>';
        }
        
        // XML da NFS-e Autorizada
        if (!empty($obj->xml_nfse)) {
            print '<br><h3>XML da NFS-e Autorizada</h3>';
            print '<div style="background:#f5f5f5; padding:10px; border:1px solid #ddd; border-radius:4px;">';
            print '<pre style="white-space:pre-wrap; word-wrap:break-word; font-family:monospace; font-size:12px;">';
            print dol_escape_htmltag(formatXml($obj->xml_nfse));
            print '</pre>';
            print '</div>';
        }
        
        // Retorno JSON da SEFAZ
        if (!empty($obj->xml_retorno)) {
            print '<br><h3>Retorno da SEFAZ (JSON)</h3>';
            print '<div style="background:#f5f5f5; padding:10px; border:1px solid #ddd; border-radius:4px;">';
            print '<pre style="white-space:pre-wrap; word-wrap:break-word; font-family:monospace; font-size:12px;">';
            
            $jsonDecoded = json_decode($obj->xml_retorno, true);
            if ($jsonDecoded) {
                print dol_escape_htmltag(json_encode($jsonDecoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            } else {
                print dol_escape_htmltag($obj->xml_retorno);
            }
            
            print '</pre>';
            print '</div>';
        }
        
        print '</div>';
        
    } else {
        print '<div class="warning">NFS-e não encontrada.</div>';
    }
    
} else {
    print '<div class="error">ID inválido.</div>';
}

llxFooter();

/**
 * Formata XML para melhor legibilidade
 */
function formatXml($xml) {
    $dom = new DOMDocument('1.0');
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    
    if (@$dom->loadXML($xml)) {
        return $dom->saveXML();
    }
    
    return $xml;
}
