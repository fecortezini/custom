<?php
/**
 * MDF-e Download XML
 *
 * Suporta dois modos:
 *   action=individual&id=X  → ZIP com XML da MDF-e + XMLs de eventos (encerramento/cancelamento/inclusão DF-e)
 *   action=batch&<filtros>  → ZIP com todos os XML das MDF-e filtradas + eventos
 */

@ini_set('display_errors', '0');
$__lvl = error_reporting();
$__lvl &= ~(E_DEPRECATED | E_USER_DEPRECATED | E_NOTICE | E_USER_NOTICE | E_WARNING | E_USER_WARNING);
error_reporting($__lvl);

require '../../main.inc.php';

/** @var DoliDB $db */
/** @var User $user */

if (!$user->id) {
    http_response_code(403);
    exit('Acesso negado.');
}

$action = GETPOST('action', 'alpha');

// ---------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------

/**
 * Retorna o XML da MDF-e (emissão) limpo como string.
 */
function mdfe_dl_getXmlEmissao($row)
{
    $xml = '';
    if (!empty($row->xml_mdfe)) {
        $xml = is_resource($row->xml_mdfe) ? stream_get_contents($row->xml_mdfe) : (string) $row->xml_mdfe;
    }
    return trim($xml);
}

/**
 * Busca eventos de uma MDF-e e retorna array de XMLs relevantes
 * (cancelamento=110111, encerramento=110112, inclusão DF-e=110115, condutor=110114).
 */
function mdfe_dl_getEventosXml($db, $mdfeId)
{
    $tpDescMap = [
        '110111' => 'cancelamento',
        '110112' => 'encerramento',
        '110114' => 'inclusao-condutor',
        '110115' => 'inclusao-dfe',
        '110116' => 'pagamento',
    ];

    $eventos = [];
    $sql = "SELECT tpEvento, nSeqEvento, xml_requisicao, xml_resposta
            FROM " . MAIN_DB_PREFIX . "mdfe_eventos
            WHERE fk_mdfe_emitida = " . (int)$mdfeId . "
            ORDER BY nSeqEvento ASC";
    $res = $db->query($sql);
    if (!$res) return $eventos;
    while ($row = $db->fetch_object($res)) {
        $tpDesc = $tpDescMap[$row->tpEvento] ?? ('evento-' . $row->tpEvento);
        $seq    = str_pad((string)$row->nSeqEvento, 3, '0', STR_PAD_LEFT);

        // XML de resposta (retorno da SEFAZ)
        $respXml = is_resource($row->xml_resposta)
            ? stream_get_contents($row->xml_resposta)
            : (string)($row->xml_resposta ?? '');
        if (trim($respXml) !== '') {
            $eventos[] = ['tipo' => $tpDesc, 'xml' => trim($respXml)];
        }
    }
    return $eventos;
}

/**
 * Monta o nome base de arquivo para uma MDF-e.
 * Padrão: MDFe_<chave>  ou  MDFe_<numero>-<serie>
 */
function mdfe_dl_nomeBase($row)
{
    if (!empty($row->chave_acesso)) {
        return 'MDFe_' . preg_replace('/\W/', '', $row->chave_acesso);
    }
    return 'MDFe_' . intval($row->numero) . '-' . intval($row->serie);
}

/**
 * Adiciona um arquivo ao ZIP, garantindo nome único se já existir.
 */
function mdfe_dl_addToZip(ZipArchive $zip, string $filename, string $content): void
{
    // Se o nome já existe, incrementa sufixo
    $base = pathinfo($filename, PATHINFO_FILENAME);
    $ext  = pathinfo($filename, PATHINFO_EXTENSION);
    $name = $filename;
    $i    = 1;
    while ($zip->locateName($name) !== false) {
        $name = $base . '_' . $i . '.' . $ext;
        $i++;
    }
    $zip->addFromString($name, $content);
}

// ---------------------------------------------------------------
// Monta cláusula WHERE (reutiliza mesma lógica do mdfe_list.php)
// ---------------------------------------------------------------
function mdfe_dl_buildWhere($db)
{
    $whereClauses = [];
    $allowedStatuses = ['autorizada','rejeitada','cancelada','encerrada','pendente','erro'];
    $selectedStatuses = [];
    $search_status_list = GETPOST('search_status_list', 'alpha');
    if (!empty($search_status_list)) {
        foreach (explode(',', $search_status_list) as $st) {
            $stl = strtolower(trim($st));
            if ($stl !== '' && in_array($stl, $allowedStatuses, true)) $selectedStatuses[] = $stl;
        }
        $selectedStatuses = array_values(array_unique($selectedStatuses));
    }
    if (!empty($selectedStatuses)) {
        $vals = array_map(function($v) use ($db){ return "'".$db->escape($v)."'"; }, $selectedStatuses);
        $whereClauses[] = "(LOWER(m.status) IN (".implode(',', $vals)."))";
    }

    $search_numero_start = GETPOST('search_numero_start', 'alpha');
    $search_numero_end   = GETPOST('search_numero_end', 'alpha');
    if ($search_numero_start !== '' && is_numeric($search_numero_start)) {
        $whereClauses[] = "m.numero >= " . (int)$search_numero_start;
    }
    if ($search_numero_end !== '' && is_numeric($search_numero_end)) {
        $whereClauses[] = "m.numero <= " . (int)$search_numero_end;
    }

    $search_chave = GETPOST('search_chave', 'alpha');
    if (!empty($search_chave)) {
        $whereClauses[] = "m.chave_acesso LIKE '%" . $db->escape(trim($search_chave)) . "%'";
    }

    $search_uf_ini = GETPOST('search_uf_ini', 'alpha');
    if (!empty($search_uf_ini)) {
        $whereClauses[] = "m.uf_ini = '" . $db->escape(strtoupper(trim($search_uf_ini))) . "'";
    }
    $search_uf_fim = GETPOST('search_uf_fim', 'alpha');
    if (!empty($search_uf_fim)) {
        $whereClauses[] = "m.uf_fim = '" . $db->escape(strtoupper(trim($search_uf_fim))) . "'";
    }

    $search_placa = GETPOST('search_placa', 'alpha');
    if (!empty($search_placa)) {
        $whereClauses[] = "UPPER(m.placa) LIKE '%" . $db->escape(strtoupper(trim($search_placa))) . "%'";
    }

    $search_data_start = GETPOST('search_data_emissao_start', 'alpha');
    $search_data_end   = GETPOST('search_data_emissao_end', 'alpha');
    if (!empty($search_data_start)) {
        $whereClauses[] = "m.data_emissao >= '" . $db->escape($search_data_start) . " 00:00:00'";
    }
    if (!empty($search_data_end)) {
        $whereClauses[] = "m.data_emissao <= '" . $db->escape($search_data_end) . " 23:59:59'";
    }

    return empty($whereClauses) ? '' : ' AND ' . implode(' AND ', $whereClauses);
}

// ---------------------------------------------------------------
// MODO INDIVIDUAL
// ---------------------------------------------------------------
if ($action === 'individual') {
    $mdfeId = (int) GETPOST('id', 'int');
    if ($mdfeId <= 0) {
        http_response_code(400);
        exit('ID inválido.');
    }

    $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "mdfe_emitidas WHERE id = " . $mdfeId;
    $res = $db->query($sql);
    if (!$res || $db->num_rows($res) === 0) {
        http_response_code(404);
        exit('MDF-e não encontrada.');
    }
    $row = $db->fetch_object($res);

    $nomeBase  = mdfe_dl_nomeBase($row);
    $xmlEmissao = mdfe_dl_getXmlEmissao($row);
    $eventos    = mdfe_dl_getEventosXml($db, $mdfeId);

    // Se não há eventos e há apenas o XML principal → entrega direto sem ZIP
    if (empty($eventos) && $xmlEmissao !== '') {
        $nomeArquivo = $nomeBase . '.xml';
        header('Content-Type: application/xml; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $nomeArquivo . '"');
        header('Content-Length: ' . strlen($xmlEmissao));
        echo $xmlEmissao;
        exit;
    }

    // Caso contrário, monta ZIP
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        exit('Extensão ZipArchive não disponível no servidor.');
    }

    $tmpFile = tempnam(sys_get_temp_dir(), 'mdfe_dl_');
    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        exit('Não foi possível criar o arquivo ZIP.');
    }

    // XML de emissão
    if ($xmlEmissao !== '') {
        mdfe_dl_addToZip($zip, $nomeBase . '.xml', $xmlEmissao);
    }

    // XMLs de eventos
    foreach ($eventos as $evt) {
        $nomeEvt = $nomeBase . '-' . $evt['tipo'] . '.xml'; 
        mdfe_dl_addToZip($zip, $nomeEvt, $evt['xml']);
    }

    $zip->close();

    $zipContent = file_get_contents($tmpFile);
    @unlink($tmpFile);

    $nomeZip = $nomeBase . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $nomeZip . '"');
    header('Content-Length: ' . strlen($zipContent));
    echo $zipContent;
    exit;
}

// ---------------------------------------------------------------
// MODO BATCH (em lote)
// ---------------------------------------------------------------
if ($action === 'batch') {
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        exit('Extensão ZipArchive não disponível no servidor.');
    }

    $whereSQL = mdfe_dl_buildWhere($db);

    $sql = "SELECT id, numero, serie, chave_acesso, status, xml_mdfe
            FROM " . MAIN_DB_PREFIX . "mdfe_emitidas m
            WHERE 1=1" . $whereSQL . "
            ORDER BY m.numero ASC";

    $res = $db->query($sql);
    if (!$res || $db->num_rows($res) === 0) {
        // Entrega HTML simples (será aberto em nova aba)
        header('Content-Type: text/html; charset=UTF-8');
        echo '<html><body style="font-family:sans-serif;padding:40px;"><h3>Nenhum registro encontrado com os filtros aplicados.</h3><p><a href="javascript:window.close()">Fechar</a></p></body></html>';
        exit;
    }

    $tmpFile = tempnam(sys_get_temp_dir(), 'mdfe_lote_');
    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500);
        exit('Não foi possível criar o arquivo ZIP.');
    }

    $total = 0;
    while ($row = $db->fetch_object($res)) {
        $nomeBase    = mdfe_dl_nomeBase($row);
        $xmlEmissao  = mdfe_dl_getXmlEmissao($row);
        $eventos     = mdfe_dl_getEventosXml($db, (int)$row->id);

        if ($xmlEmissao === '' && empty($eventos)) continue;

        // Se tem eventos → subpasta por MDF-e
        if (!empty($eventos)) {
            $pasta = $nomeBase . '/';
            if ($xmlEmissao !== '') {
                mdfe_dl_addToZip($zip, $pasta . $nomeBase . '.xml', $xmlEmissao);
            }
            foreach ($eventos as $evt) {
                $nomeEvt = $nomeBase . '-' . $evt['tipo'] . '-seq' . ($evt['seq'] ?? '001') . '.xml';
                mdfe_dl_addToZip($zip, $pasta . $nomeEvt, $evt['xml']);
            }
        } else {
            // Sem eventos → arquivo direto na raiz do ZIP
            if ($xmlEmissao !== '') {
                mdfe_dl_addToZip($zip, $nomeBase . '.xml', $xmlEmissao);
            }
        }
        $total++;
    }

    $zip->close();

    if ($total === 0) {
        @unlink($tmpFile);
        header('Content-Type: text/html; charset=UTF-8');
        echo '<html><body style="font-family:sans-serif;padding:40px;"><h3>Nenhum XML disponível para os registros encontrados.</h3><p><a href="javascript:window.close()">Fechar</a></p></body></html>';
        exit;
    }

    $zipContent = file_get_contents($tmpFile);
    @unlink($tmpFile);

    $nomeZip = 'MDFe_lote_' . date('Ymd_His') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $nomeZip . '"');
    header('Content-Length: ' . strlen($zipContent));
    echo $zipContent;
    exit;
}

// Ação não reconhecida
http_response_code(400);
exit('Ação inválida.');
