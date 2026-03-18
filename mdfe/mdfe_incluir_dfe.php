<?php
/**
 * mdfe_incluir_dfe.php
 * =====================
 * Endpoint AJAX para o evento "Inclusão de DF-e" em MDF-e já autorizada.
 *
 * Ações suportadas:
 *   - buscar_cidades : Retorna lista de cidades de uma UF (tabela IBGE).
 *   - incluir_dfe    : Envia o evento 110115 (Inclusão DF-e) à SEFAZ e grava no banco.
 */

// Silencia warnings/notices para evitar poluir a resposta JSON
@ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

// Carrega Dolibarr
require_once '../../main.inc.php';

// Bibliotecas auxiliares
require_once __DIR__ . '/../composerlib/vendor/autoload.php';
require_once DOL_DOCUMENT_ROOT . '/custom/labapp/lib/ibge_utils.php';
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
function jsonError(string $msg, int $httpCode = 200): void
{
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Resposta JSON padronizada de sucesso.
 */
function jsonSuccess(array $extra = []): void
{
    echo json_encode(array_merge(['success' => true], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Carrega o certificado A1 do banco (mesma lógica usada nos demais módulos).
 */
function incDfe_carregarCertificado($db)
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
// ACTION: buscar_cidades
// Recebe: uf (sigla, ex.: "ES")
// Retorna: [ { codigo_ibge, nome } , ... ]
// -------------------------------------------------------------------
if ($action === 'buscar_cidades') {
    $uf = strtoupper(trim(GETPOST('uf', 'alpha')));
    if (strlen($uf) !== 2) {
        jsonError('Informe uma UF válida (2 letras).');
    }

    $sql = "SELECT codigo_ibge, nome
            FROM " . MAIN_DB_PREFIX . "estados_municipios_ibge
            WHERE active = 1 AND uf = '" . $db->escape($uf) . "'
            ORDER BY nome ASC";
    $res = $db->query($sql);
    if (!$res) {
        jsonError('Erro ao consultar municípios: ' . $db->lasterror());
    }

    $cidades = [];
    while ($obj = $db->fetch_object($res)) {
        $cidades[] = [
            'codigo_ibge' => $obj->codigo_ibge,
            'nome'        => $obj->nome,
        ];
    }
    jsonSuccess(['cidades' => $cidades]);
}

// -------------------------------------------------------------------
// ACTION: incluir_dfe
// Recebe: id (int), uf_carrega, mun_carrega, uf_descarga, mun_descarga, chNFe
// -------------------------------------------------------------------
if ($action === 'incluir_dfe') {
    $mdfeId       = (int) GETPOST('id', 'int');
    $ufCarrega    = strtoupper(trim(GETPOST('uf_carrega', 'alpha')));
    $munCarrega   = trim(GETPOST('mun_carrega', 'restricthtml'));
    $ufDescarga   = strtoupper(trim(GETPOST('uf_descarga', 'alpha')));
    $munDescarga  = trim(GETPOST('mun_descarga', 'restricthtml'));
    $chNFe        = preg_replace('/\D/', '', trim(GETPOST('chNFe', 'restricthtml')));

    // ── Validações simples ──
    if ($mdfeId <= 0)                          jsonError('ID da MDF-e inválido.');
    if (strlen($ufCarrega) !== 2)              jsonError('Selecione a UF de carregamento.');
    if (empty($munCarrega))                    jsonError('Selecione o município de carregamento.');
    if (strlen($ufDescarga) !== 2)             jsonError('Selecione a UF de descarga.');
    if (empty($munDescarga))                   jsonError('Selecione o município de descarga.');
    if (strlen($chNFe) !== 44)                 jsonError('A chave da NF-e deve ter 44 dígitos.');
    if (!ctype_digit($chNFe))                  jsonError('A chave da NF-e deve conter apenas números.');

    // ── Buscar MDF-e no banco ──
    $sqlMdfe = "SELECT * FROM " . MAIN_DB_PREFIX . "mdfe_emitidas WHERE id = " . $mdfeId;
    $resMdfe = $db->query($sqlMdfe);
    if (!$resMdfe || $db->num_rows($resMdfe) === 0) {
        jsonError('MDF-e não encontrada.');
    }
    $mdfe = $db->fetch_object($resMdfe);

    if (strtolower($mdfe->status) !== 'autorizada') {
        jsonError('Só é possível incluir DF-e em MDF-e autorizada (status atual: ' . $mdfe->status . ').');
    }
    if (empty($mdfe->chave_acesso)) jsonError('Chave de acesso não encontrada.');
    if (empty($mdfe->protocolo))    jsonError('Protocolo de autorização não encontrado.');

    // Verificar se já possui CT-e ou NF-e vinculados (originais ou incluídos via evento)
    $totalDocsOriginais = ((int)($mdfe->qtd_cte ?? 0)) + ((int)($mdfe->qtd_nfe ?? 0));
    $sqlIncCount = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . "mdfe_inclusao_nfe WHERE fk_mdfe_emitida = " . $mdfeId;
    $resIncCount = $db->query($sqlIncCount);
    $totalDocsIncluidos = 0;
    if ($resIncCount && $db->num_rows($resIncCount) > 0) {
        $totalDocsIncluidos = (int)$db->fetch_object($resIncCount)->total;
    }
    if (($totalDocsOriginais + $totalDocsIncluidos) > 0) {
        jsonError('Esta MDF-e já possui documento(s) fiscal(is) vinculado(s). Não é possível incluir mais DF-e.');
    }

    // ── Resolver municípios via IBGE ──
    $ibgeCarrega = buscarDadosIbge($db, $munCarrega, $ufCarrega);
    if (!$ibgeCarrega) {
        jsonError("Município de carregamento '{$munCarrega}' não encontrado na base IBGE.");
    }

    $ibgeDescarga = buscarDadosIbge($db, $munDescarga, $ufDescarga);
    if (!$ibgeDescarga) {
        jsonError("Município de descarga '{$munDescarga}' não encontrado na base IBGE.");
    }

    // ── Próximo nSeqEvento (único por tipo de evento 110115) ──
    $sqlSeq = "SELECT COALESCE(MAX(nSeqEvento), 0) AS ultimo
               FROM " . MAIN_DB_PREFIX . "mdfe_eventos
               WHERE fk_mdfe_emitida = " . $mdfeId . "
               AND tpEvento = '110115'";
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

        $cert  = incDfe_carregarCertificado($db);
        $tools = new Tools(json_encode($configMdfe), $cert);

        // Monta o array infDoc exigido pelo método sefazIncluiDFe
        $infDoc = [
            [
                'cMunDescarga' => $ibgeDescarga->codigo_ibge,
                'xMunDescarga' => strtoupper($ibgeDescarga->nome),
                'chNFe'        => $chNFe,
            ],
        ];

        $resp = $tools->sefazIncluiDFe(
            $mdfe->chave_acesso,
            $mdfe->protocolo,
            $ibgeCarrega->codigo_ibge,
            strtoupper($ibgeCarrega->nome),
            $infDoc,
            (string) $nSeqEvento
        );

        $xmlRequisicao = $tools->lastRequest ?? '';

        $st  = new Standardize();
        $std = $st->toStd($resp);

        $cStat       = (int) ($std->infEvento->cStat      ?? 0);
        $xMotivo     = $std->infEvento->xMotivo            ?? 'Resposta inválida da SEFAZ';
        $nProtEvt    = $std->infEvento->nProt              ?? '';
        $dhRegEvento = $std->infEvento->dhRegEvento        ?? date('Y-m-d\TH:i:sP');
        $tpEvento    = $std->infEvento->tpEvento           ?? '110115';
        $nSeqResp    = (int) ($std->infEvento->nSeqEvento  ?? $nSeqEvento);

        // cStat 135 = evento registrado e vinculado
        if ($cStat !== 135) {
            jsonError("$xMotivo");
        }

        // ── Gravar evento no banco (tabela geral de eventos) ──
        $dtEvento = date('Y-m-d H:i:s', strtotime($dhRegEvento));
        $sqlInsert = "INSERT INTO " . MAIN_DB_PREFIX . "mdfe_eventos
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
        $db->query($sqlInsert);

        // ── Gravar na tabela específica mdfe_inclusao_nfe ──
        $sqlInsertNfe = "INSERT INTO " . MAIN_DB_PREFIX . "mdfe_inclusao_nfe
            (fk_mdfe_emitida, chave_mdfe, protocolo_mdfe, nSeqEvento,
             cMunCarrega, xMunCarrega, cMunDescarga, xMunDescarga, chNFe,
             protocolo_evento, cStat, xMotivo, data_evento,
             xml_requisicao, xml_resposta)
            VALUES (
                " . $mdfeId . ",
                '" . $db->escape($mdfe->chave_acesso) . "',
                '" . $db->escape($mdfe->protocolo) . "',
                " . $nSeqResp . ",
                '" . $db->escape($ibgeCarrega->codigo_ibge) . "',
                '" . $db->escape(strtoupper($ibgeCarrega->nome)) . "',
                '" . $db->escape($ibgeDescarga->codigo_ibge) . "',
                '" . $db->escape(strtoupper($ibgeDescarga->nome)) . "',
                '" . $db->escape($chNFe) . "',
                '" . $db->escape($nProtEvt) . "',
                " . $cStat . ",
                '" . $db->escape($xMotivo) . "',
                '" . $db->escape($dtEvento) . "',
                '" . $db->escape($xmlRequisicao) . "',
                '" . $db->escape($resp) . "'
            )";
        $db->query($sqlInsertNfe);

        // Mensagem de sucesso via sessão do Dolibarr (aparece após reload)
        setEventMessages('NF-e incluída com sucesso na MDF-e!', null, 'mesgs');

        jsonSuccess([
            'cStat'      => $cStat,
            'xMotivo'    => $xMotivo,
            'protocolo'  => $nProtEvt,
            'nSeqEvento' => $nSeqResp,
            'chNFe'      => $chNFe,
        ]);
    } catch (Exception $e) {
        error_log('[MDF-e IncluirDFe] ' . $e->getMessage());
        jsonError($e->getMessage());
    }
}

// Ação desconhecida
jsonError('Ação não reconhecida.');
