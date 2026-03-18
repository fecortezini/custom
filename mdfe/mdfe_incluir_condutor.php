<?php
/**
 * mdfe_incluir_condutor.php
 * =========================
 * Endpoint AJAX para o evento "Inclusão de Condutor" em MDF-e já autorizada.
 *
 * Ação suportada:
 *   - incluir_condutor : Envia o evento 110114 (Inclusão Condutor) à SEFAZ e grava no banco.
 */

// Silencia warnings/notices para evitar poluir a resposta JSON
@ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

// Carrega Dolibarr
require_once '../../main.inc.php';

// Bibliotecas auxiliares
require_once __DIR__ . '/../composerlib/vendor/autoload.php';
require_once DOL_DOCUMENT_ROOT . '/custom/mdfe/lib/certificate_security.lib.php';

use NFePHP\MDFe\Tools;
use NFePHP\MDFe\Common\Standardize;

// -------------------------------------------------------------------
// Apenas requisições AJAX
// -------------------------------------------------------------------
if (
    empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'
) {
    http_response_code(403);
    exit('Acesso negado.');
}

header('Content-Type: application/json; charset=UTF-8');

/**
 * Resposta JSON padronizada de erro.
 */
function jsonErrorCondutor(string $msg, int $httpCode = 200): void
{
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Resposta JSON padronizada de sucesso.
 */
function jsonSuccessCondutor(array $extra = []): void
{
    echo json_encode(array_merge(['success' => true], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Carrega o certificado A1 do banco (mesma lógica usada nos demais módulos).
 */
function incCondutor_carregarCertificado($db)
{
    $certPfx = null;
    $certPass = null;

    $tbl = MAIN_DB_PREFIX . 'nfe_config';
    $res = @$db->query("SELECT name, value FROM `{$tbl}`");
    if ($res) {
        while ($row = $db->fetch_object($res)) {
            if ($row->name === 'cert_pfx')  $certPfx  = $row->value;
            if ($row->name === 'cert_pass') $certPass = $row->value;
        }
    }
    if (empty($certPfx)) {
        $res2 = @$db->query("SELECT cert_pfx, cert_pass FROM `{$tbl}` LIMIT 1");
        if ($res2 && $obj = $db->fetch_object($res2)) {
            $certPfx  = $obj->cert_pfx;
            $certPass = $obj->cert_pass;
        }
    }
    if (is_resource($certPfx)) {
        $certPfx = stream_get_contents($certPfx);
    }
    if ($certPfx === null || $certPfx === '') {
        throw new Exception('Certificado PFX não encontrado no banco de dados.');
    }

    $pass = decryptPassword((string) $certPass, $db);

    try {
        return \NFePHP\Common\Certificate::readPfx($certPfx, $pass);
    } catch (Exception $e) {
        $decoded = base64_decode($certPfx, true);
        if ($decoded !== false) {
            return \NFePHP\Common\Certificate::readPfx($decoded, (string) $certPass);
        }
        throw new Exception('Erro ao ler certificado: ' . $e->getMessage());
    }
}

// ===================================================================
// ROTEAMENTO POR action
// ===================================================================
$action = GETPOST('action', 'alpha');

// -------------------------------------------------------------------
// ACTION: incluir_condutor
// Recebe: id (int), xNome (string), cpf (string - 11 dígitos)
// -------------------------------------------------------------------
if ($action === 'incluir_condutor') {
    $mdfeId = (int) GETPOST('id', 'int');
    $xNome  = trim(GETPOST('xNome', 'restricthtml'));
    $cpf    = preg_replace('/\D/', '', trim(GETPOST('cpf', 'restricthtml')));

    // ── Validações simples ──
    if ($mdfeId <= 0)              jsonErrorCondutor('ID da MDF-e inválido.');
    if (empty($xNome))             jsonErrorCondutor('Informe o nome do condutor.');
    if (mb_strlen($xNome) < 2)     jsonErrorCondutor('O nome do condutor deve ter pelo menos 2 caracteres.');
    if (strlen($cpf) !== 11)       jsonErrorCondutor('O CPF deve ter 11 dígitos.');
    if (!ctype_digit($cpf))        jsonErrorCondutor('O CPF deve conter apenas números.');

    // ── Buscar MDF-e no banco ──
    $sqlMdfe = "SELECT * FROM " . MAIN_DB_PREFIX . "mdfe_emitidas WHERE id = " . $mdfeId;
    $resMdfe = $db->query($sqlMdfe);
    if (!$resMdfe || $db->num_rows($resMdfe) === 0) {
        jsonErrorCondutor('MDF-e não encontrada.');
    }
    $mdfe = $db->fetch_object($resMdfe);

    if (strtolower($mdfe->status) !== 'autorizada') {
        jsonErrorCondutor('Só é possível incluir condutor em MDF-e autorizada (status atual: ' . $mdfe->status . ').');
    }
    if (empty($mdfe->chave_acesso)) jsonErrorCondutor('Chave de acesso não encontrada.');
    if (empty($mdfe->protocolo))    jsonErrorCondutor('Protocolo de autorização não encontrado.');

    // ── Próximo nSeqEvento (único por tipo de evento 110114) ──
    $sqlSeq = "SELECT COALESCE(MAX(nSeqEvento), 0) AS ultimo
               FROM " . MAIN_DB_PREFIX . "mdfe_eventos
               WHERE fk_mdfe_emitida = " . $mdfeId . "
               AND tpEvento = '110114'";
    $resSeq = $db->query($sqlSeq);
    $ultimoSeq = 0;
    if ($resSeq && $db->num_rows($resSeq) > 0) {
        $ultimoSeq = (int) $db->fetch_object($resSeq)->ultimo;
    }
    $nSeqEvento = $ultimoSeq + 1;

    // ── Montar config e enviar à SEFAZ ──
    try {
        // Ambiente
        $ambienteVal = 2;
        $resAmb = $db->query("SELECT value FROM " . MAIN_DB_PREFIX . "nfe_config WHERE name = 'ambiente'");
        if ($resAmb && $db->num_rows($resAmb) > 0) {
            $ambienteVal = (int) $db->fetch_object($resAmb)->value;
        }

        // Dados do emitente
        global $mysoc;
        if (empty($mysoc->id)) $mysoc->fetch(0);
        $cnpj = preg_replace('/\D/', '', $mysoc->idprof1 ?? '');

        $configMdfe = [
            'atualizacao' => date('Y-m-d H:i:s'),
            'tpAmb'       => $ambienteVal,
            'razaosocial' => $mysoc->name ?? '',
            'cnpj'        => $cnpj,
            'ie'          => preg_replace('/\D/', '', $mysoc->idprof3 ?? ''),
            'siglaUF'     => $mysoc->state_code ?? 'ES',
            'versao'      => '3.00',
        ];

        $cert  = incCondutor_carregarCertificado($db);
        $tools = new Tools(json_encode($configMdfe), $cert);

        // Chama o método sefazIncluiCondutor da biblioteca NFePHP
        $resp = $tools->sefazIncluiCondutor(
            $mdfe->chave_acesso,
            (string) $nSeqEvento,
            strtoupper($xNome),
            $cpf
        );

        $xmlRequisicao = $tools->lastRequest ?? '';

        $st  = new Standardize();
        $std = $st->toStd($resp);

        $cStat       = (int) ($std->infEvento->cStat      ?? 0);
        $xMotivo     = $std->infEvento->xMotivo            ?? 'Resposta inválida da SEFAZ';
        $nProtEvt    = $std->infEvento->nProt              ?? '';
        $dhRegEvento = $std->infEvento->dhRegEvento        ?? date('Y-m-d\TH:i:sP');
        $tpEvento    = $std->infEvento->tpEvento           ?? '110114';
        $nSeqResp    = (int) ($std->infEvento->nSeqEvento  ?? $nSeqEvento);

        // cStat 135 = evento registrado e vinculado
        if ($cStat !== 135) {
            jsonErrorCondutor("SEFAZ recusou a inclusão (cStat={$cStat}): {$xMotivo}");
        }

        // ── Gravar na tabela mdfe_eventos (histórico geral) ──
        $dtEvento = date('Y-m-d H:i:s', strtotime($dhRegEvento));
        $sqlInsertEvento = "INSERT INTO " . MAIN_DB_PREFIX . "mdfe_eventos
            (fk_mdfe_emitida, tpEvento, nSeqEvento, protocolo_evento, motivo_evento,
             data_evento, xml_requisicao, xml_resposta, xml_evento_completo)
            VALUES (
                " . $mdfeId . ",
                '" . $db->escape($tpEvento) . "',
                " . $nSeqResp . ",
                '" . $db->escape($nProtEvt) . "',
                '" . $db->escape($xMotivo) . "',
                '" . $db->escape($dtEvento) . "',
                '" . $db->escape($xmlRequisicao) . "',
                '" . $db->escape($resp) . "',
                '" . $db->escape($resp) . "'
            )";
        $db->query($sqlInsertEvento);

        // ── Gravar na tabela específica mdfe_inclusao_condutor ──
        $sqlInsertCondutor = "INSERT INTO " . MAIN_DB_PREFIX . "mdfe_inclusao_condutor
            (fk_mdfe_emitida, chave_mdfe, nSeqEvento, xNome, cpf,
             protocolo_evento, cStat, xMotivo, data_evento,
             xml_requisicao, xml_resposta)
            VALUES (
                " . $mdfeId . ",
                '" . $db->escape($mdfe->chave_acesso) . "',
                " . $nSeqResp . ",
                '" . $db->escape(strtoupper($xNome)) . "',
                '" . $db->escape($cpf) . "',
                '" . $db->escape($nProtEvt) . "',
                " . $cStat . ",
                '" . $db->escape($xMotivo) . "',
                '" . $db->escape($dtEvento) . "',
                '" . $db->escape($xmlRequisicao) . "',
                '" . $db->escape($resp) . "'
            )";
        $db->query($sqlInsertCondutor);

        // Mensagem de sucesso via sessão do Dolibarr (aparece após reload)
    setEventMessages('Condutor incluído com sucesso na MDF-e!', null, 'mesgs');

        jsonSuccessCondutor([
            'cStat'      => $cStat,
            'xMotivo'    => $xMotivo,
            'protocolo'  => $nProtEvt,
            'nSeqEvento' => $nSeqResp,
            'xNome'      => strtoupper($xNome),
            'cpf'        => $cpf,
        ]);
    } catch (Exception $e) {
        error_log('[MDF-e IncluirCondutor] ' . $e->getMessage());
        jsonErrorCondutor($e->getMessage());
    }
}

// Ação desconhecida
jsonErrorCondutor('Ação não reconhecida.');
