<?php

/**
 * \file       htdocs/custom/nfe/carta_correcao.php
 * \ingroup    nfe
 * \brief      Página para emissão de Carta de Correção Eletrônica (CC-e)
 */

require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/functions.lib.php';

// --- INCLUSÃO DAS DEPENDÊNCIAS E FUNÇÕES DE BACK-END ---
require_once DOL_DOCUMENT_ROOT . '/custom/composerlib/vendor/autoload.php';
require_once DOL_DOCUMENT_ROOT.'/custom/nfe/lib/nfe_security.lib.php';

// Verificação para garantir que a biblioteca foi carregada corretamente
if (!class_exists('NFePHP\NFe\Tools')) {
    print '<div class="error">Erro Fatal: A biblioteca NFePHP não foi encontrada. Verifique o caminho para o autoload do Composer.</div>';
    llxFooter();
    exit;
}

use NFePHP\NFe\Tools;
use NFePHP\Common\Certificate;
use NFePHP\NFe\Common\Standardize;
use NFePHP\NFe\Complements;

function gerarEventoNFeDolibarr(DoliDB $db, int $idNFe, string $chave, string $protocolo, string $tpEvento, string $justificativa): array
{
    global $langs, $mysoc;

    try {
        // Carrega configurações do banco de dados
        $sql_config = "SELECT name, value FROM ".MAIN_DB_PREFIX."nfe_config";
        $res_config = $db->query($sql_config);
        $nfe_configs = array();
        if ($res_config) {
            while ($obj_config = $db->fetch_object($res_config)) {
                $nfe_configs[$obj_config->name] = $obj_config->value;
            }
        }

        // Gera config.json dinamicamente
        $arr = [
            "atualizacao" => date('Y-m-d H:i:s'),
            "tpAmb"       => (int)($nfe_configs['ambiente'] ?? 2),
            "razaosocial" => $mysoc->name ?? 'Empresa',
            "cnpj"        => preg_replace('/\D/', '', $mysoc->idprof1 ?? ''),
            "siglaUF"     => $mysoc->state_code ?? 'ES',
            "schemes"     => "PL_010_V1",
            "versao"      => '4.00',
            "tokenIBPT"   => "AAAAAAA",
            "CSC"         => $nfe_configs['csc'] ?? "GPB0JBWLUR6HWFTVEAS6RJ69GPCROFPBBB8G",
            "CSCid"       => $nfe_configs['csc_id'] ?? "000001",
            "proxyConf"   => [
                "proxyIp"   => "",
                "proxyPort" => "",
                "proxyUser" => "",
                "proxyPass" => ""
            ]
        ];
        $configJson = json_encode($arr);
        $pfxContent = $nfe_configs['cert_pfx'];
        
        $password = $nfe_configs['cert_pass'];
        $senhaOriginal = nfeDecryptPassword($password, $db);


        // 1. CALCULAR O nSeqEvento
        $sql = "SELECT MAX(nSeqEvento) as max_seq FROM " . MAIN_DB_PREFIX . "nfe_eventos";
        $sql .= " WHERE fk_nfe_emitida = " . $idNFe . " AND tpEvento = '" . $db->escape($tpEvento) . "'";
        
        $resql = $db->query($sql);
        $obj = $db->fetch_object($resql);
        $nSeqEvento = ($obj && $obj->max_seq) ? $obj->max_seq + 1 : 1;

        if ($tpEvento === '110111' && $nSeqEvento > 1) {
            return ['success' => false, 'message' => $langs->trans("NFeAlreadyCancelled")];
        }

        // 2. PREPARAR E ENVIAR O EVENTO PARA A SEFAZ
        $certificate = Certificate::readPfx($pfxContent, $senhaOriginal);
        $tools = new Tools($configJson, $certificate);
        $tools->model('55');

        $responseXml = '';
        if ($tpEvento === '110111') {
            $responseXml = $tools->sefazCancela($chave, $justificativa, $protocolo);
        } elseif ($tpEvento === '110110') {
            $responseXml = $tools->sefazCCe($chave, $justificativa, $nSeqEvento);
        } else {
            return ['success' => false, 'message' => $langs->trans("UnknownEventType") . ': ' . $tpEvento];
        }

        // 3. TRATAR A RESPOSTA DA SEFAZ
        $responseArr = (new Standardize($responseXml))->toArray();
        $cStatLote = $responseArr['cStat'] ?? null;
        $infEvento = $responseArr['retEvento']['infEvento'] ?? null;
        $cStatEvento = $infEvento['cStat'] ?? null;

        if ($cStatLote != '128') {
            return ['success' => false, 'message' => "Rejeição no Lote: {$cStatLote} - {$responseArr['xMotivo']}"];
        }

        if (in_array($cStatEvento, ['135', '136'])) { // 135: Evento registrado e vinculado, 136: Evento registrado mas não vinculado
            $db->begin();
            
            $xmlFinalEvento = Complements::toAuthorize($tools->lastRequest, $responseXml);
            $dataEvento = (new DateTime($infEvento['dhRegEvento']))->format('Y-m-d H:i:s');
            
            $sqlInsert = "INSERT INTO " . MAIN_DB_PREFIX . "nfe_eventos (fk_nfe_emitida, tpEvento, nSeqEvento, protocolo_evento, motivo_evento, data_evento, xml_requisicao, xml_resposta, xml_evento_completo)";
            $sqlInsert .= " VALUES (".$idNFe.", '".$db->escape($tpEvento)."', ".$nSeqEvento.", '".$db->escape($infEvento['nProt'])."', '".$db->escape($justificativa)."', '".$db->escape($dataEvento)."', '".$db->escape($tools->lastRequest)."', '".$db->escape($responseXml)."', '".$db->escape($xmlFinalEvento)."')";
            
            if ($db->query($sqlInsert)) {
                $db->commit();
                return ['success' => true, 'message' => $infEvento['xMotivo']];
            } else {
                $db->rollback();
                return ['success' => false, 'message' => 'Erro ao salvar evento no banco de dados: ' . $db->lasterror()];
            }
        } else {
            return ['success' => false, 'message' => "Rejeição no Evento: {$cStatEvento} - {$infEvento['xMotivo']}"];
        }
    } catch (\Exception $e) {
        if (isset($db) && $db->transaction_opened) {
            $db->rollback();
        }
        return ['success' => false, 'message' => 'Exceção: ' . $e->getMessage()];
    }
}


// Carrega traduções
$langs->load("bills");
$langs->load("nfe@nfe");

$action = GETPOST('action', 'alpha');
$id = GETPOST('id', 'int');
$backtopage = GETPOST('backtopage', 'alpha');

// Variáveis
$xCorrecao = GETPOST('xCorrecao', 'alpha');
$nfe_emitida = null;
$error = 0;

// --- Lógica para buscar dados da NF-e ---
if ($id > 0) {
    $sql = "SELECT id, fk_facture, chave, protocolo, numero_nfe, serie, data_emissao, status";
    $sql .= " FROM " . MAIN_DB_PREFIX . "nfe_emitidas";
    $sql .= " WHERE id = " . $id;

    $resql = $db->query($sql);
    if ($resql) {
        if ($db->num_rows($resql) > 0) {
            $nfe_emitida = $db->fetch_object($resql);
        } else {
            setEventMessage($langs->trans("NFeNotFound"), 'error');
            $error++;
        }
    } else {
        dol_print_error($db);
        $error++;
    }
} else {
    setEventMessage($langs->trans("ErrorMissingNFeId"), 'error');
    $error++;
}


/*
 * Actions
 */

if ($action == 'send_cce' && !$error && $nfe_emitida) {
    // Validação do texto da correção
    if (strlen($xCorrecao) < 15 || strlen($xCorrecao) > 1000) {
        setEventMessage($langs->trans("ErrorCorrectionTextLength"), 'error');
        $error++;
    }

    if (!$error) {
        // Chama a função central para gerar o evento de CC-e
        $resultado = gerarEventoNFeDolibarr(
            $db,
            $nfe_emitida->id,
            $nfe_emitida->chave,
            $nfe_emitida->protocolo,
            '110110', // Código do evento para Carta de Correção
            $xCorrecao
        );

        if ($resultado['success']) {
            setEventMessage($resultado['message'], 'mesgs');
            // Redireciona para a lista após o sucesso
            session_write_close();
            header("Location: " . dol_buildpath('/custom/nfe/list.php', 1));
            exit;
        } else {
            setEventMessage($resultado['message'], 'error');
            $error++;
        }
    }
}


/*
 * View
 */

$form = new Form($db);

llxHeader('', $langs->trans("CartaDeCorrecaoEletronica"));

print load_fiche_titre($langs->trans("CartaDeCorrecaoEletronica"));

dol_fiche_head();

// Exibe mensagens de erro ou sucesso
dol_htmloutput_errors(null);
dol_htmloutput_mesg(null);


if ($nfe_emitida) {
    print '<table class="border" width="100%">';

    // Título
    print '<tr>';
    print '<td class="titlefield">' . $langs->trans("NFeData") . '</td>';
    print '<td colspan="3"></td>';
    print '</tr>';

    // Número / Série
    print '<tr>';
    print '<td>' . $langs->trans("NFeNumber") . ' / ' . $langs->trans("NFeSeries") . '</td>';
    print '<td>' . $nfe_emitida->numero_nfe . ' / ' . $nfe_emitida->serie . '</td>';
    print '</tr>';

    // Chave de Acesso
    print '<tr>';
    print '<td>' . $langs->trans("AccessKey") . '</td>';
    print '<td>' . $nfe_emitida->chave . '</td>';
    print '</tr>';

    // Data de Emissão
    print '<tr>';
    print '<td>' . $langs->trans("EmissionDate") . '</td>';
    print '<td>' . dol_print_date($db->jdate($nfe_emitida->data_emissao), 'dayhour') . '</td>';
    print '</tr>';

    print '</table>';
    print '<br>';

    // Formulário para a correção
    print '<form action="' . $_SERVER["PHP_SELF"] . '" method="POST">';
    print '<input type="hidden" name="token" value="' . $_SESSION['newtoken'] . '">';
    print '<input type="hidden" name="action" value="send_cce">';
    print '<input type="hidden" name="id" value="' . $nfe_emitida->id . '">';

    print '<table class="border" width="100%">';

    // Título do formulário
    print '<tr>';
    print '<td class="titlefield" colspan="2">' . $langs->trans("CorrectionInformation") . '</td>';
    print '</tr>';

    // Campo de texto para a correção
    print '<tr>';
    print '<td width="20%"><label for="xCorrecao">' . $langs->trans("CorrectionText") . '</label></td>';
    print '<td>';
    print '<textarea name="xCorrecao" id="xCorrecao" rows="6" class="flat" required>' . dol_escape_htmltag($xCorrecao) . '</textarea>';
    print '<br><span class="opacitymedium">' . $langs->trans("CorrectionTextHelp") . '</span>'; // (Mínimo 15, máximo 1000 caracteres)
    print '</td>';
    print '</tr>';

    print '</table>';

    print '<div class="center">';
    print '<br>';
    print '<input type="submit" class="button" value="' . $langs->trans("SendCorrection") . '">';
    print '&nbsp;&nbsp;';
    $url_back = $backtopage ? $backtopage : dol_buildpath('/custom/nfe/list.php', 1);
    print '<a href="' . $url_back . '" class="button button-cancel">' . $langs->trans("Cancel") . '</a>';
    print '</div>';

    print '</form>';
}

dol_fiche_end();

llxFooter();

$db->close();

?>
