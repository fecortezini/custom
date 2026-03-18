<?php
/**
 * Página de Gerenciamento de Regras Fiscais
 * 
 * @package   Dolibarr
 * @subpackage NFe
 * @author    Sistema NFe
 * @copyright 2024
 * 
 */
/** @var DoliDB $db */
// Carregamento do ambiente Dolibarr

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

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';

// Segurança: apenas admin
if (!$user->admin) {
    accessforbidden();
}

// === CSRF token (gerar uma vez e reutilizar) ===
$token = isset($_SESSION['newtoken']) ? $_SESSION['newtoken'] : newToken();

// Parâmetros
$action = GETPOST('action', 'aZ09');
$id = GETPOST('id', 'int');
$confirm = GETPOST('confirm', 'alpha');

// ========== NOVO: Parâmetros para Permissão de Crédito ==========
$action_cred = GETPOST('action_cred', 'alpha');

// ========== NOVOS: Parâmetros de paginação e filtros ==========
// Contexto de sessão para persistência
$contextpage = 'nfe_regras_fiscais';

$page = max(0, (int) GETPOST('page', 'int'));

// Sort (com persistência em sessão)
$sortfield = GETPOST('sortfield', 'alpha');
$sortorder = GETPOST('sortorder', 'alpha');

if (!$sortfield && !empty($_SESSION['sortfield_'.$contextpage])) $sortfield = $_SESSION['sortfield_'.$contextpage];
if (!$sortorder && !empty($_SESSION['sortorder_'.$contextpage])) $sortorder = $_SESSION['sortorder_'.$contextpage];

if (!$sortfield) $sortfield = 'rowid';
if (!$sortorder) $sortorder = 'DESC';

$_SESSION['sortfield_'.$contextpage] = $sortfield;
$_SESSION['sortorder_'.$contextpage] = $sortorder;

// Limit (com persistência em sessão)
$defaultLimit = ($conf->liste_limit > 0) ? (int) $conf->liste_limit : 25;
$limitOptions = [10, 25, 50, 100];

$limit = GETPOST('limit', 'int');
if ($limit > 0) {
    // Veio pela URL, atualiza sessão
    $_SESSION['limit_'.$contextpage] = $limit;
} elseif (isset($_SESSION['limit_'.$contextpage]) && $_SESSION['limit_'.$contextpage] > 0) {
    // Não veio pela URL, usa sessão
    $limit = (int)$_SESSION['limit_'.$contextpage];
} else {
    // Default
    $limit = $defaultLimit;
}

// Validação final
if (!in_array($limit, $limitOptions)) {
    $limit = $defaultLimit;
    $_SESSION['limit_'.$contextpage] = $limit;
}

$offset = $limit * $page;

// Filtros
$search_status_list = GETPOST('search_status_list', 'alphanohtml'); // Changed to alphanohtml to keep commas
$search_label = GETPOST('search_label', 'alpha');
$search_uf_origin = GETPOST('search_uf_origin', 'alpha');
$search_uf_dest = GETPOST('search_uf_dest', 'alpha');
$search_cfop = GETPOST('search_cfop', 'alpha');
$search_ncm = GETPOST('search_ncm', 'alpha');

// String de parâmetros extras para manter em todas as URLs (ordenação, paginação, etc.)
$param = '';
// Sempre inclui limit para garantir consistência visual na URL
$param .= '&limit=' . urlencode($limit);
if (!empty($search_status_list)) $param .= '&search_status_list=' . urlencode($search_status_list);
if (!empty($search_label)) $param .= '&search_label=' . urlencode($search_label);
if (!empty($search_uf_origin)) $param .= '&search_uf_origin=' . urlencode($search_uf_origin);
if (!empty($search_uf_dest)) $param .= '&search_uf_dest=' . urlencode($search_uf_dest);
if (!empty($search_cfop)) $param .= '&search_cfop=' . urlencode($search_cfop);
if (!empty($search_ncm)) $param .= '&search_ncm=' . urlencode($search_ncm);

// Lista de ações que exigem token
$secured_actions = ['create','update','toggle','confirm_delete','save_aliq_cred'];

// Validação CSRF antes de processar ações sensíveis
if (in_array($action, $secured_actions)) {
    $received = GETPOST('token', 'alpha');
    if (empty($received) || $received !== $_SESSION['newtoken']) {
        setEventMessages('Token CSRF ausente ou inválido', null, 'errors');
        $action = ''; // Anula ação
    }
}

// Formulários
$form = new Form($db);

// Processamento de ações
if ($action == 'add') {
	// Formulário de adição
	$action = 'create';
} elseif ($action == 'create') {
	// Inserção no banco
	$db->begin();

	$label = GETPOST('label', 'alphanohtml');
	$uf_origin = GETPOST('uf_origin', 'alpha');
	$uf_dest = GETPOST('uf_dest', 'alpha');
	$cfop = GETPOST('cfop', 'alpha');
	$ncm = GETPOST('ncm', 'alpha');
	$icms_aliq_interna = GETPOST('icms_aliq_interna', 'alpha');
	$icms_aliq_interestadual = GETPOST('icms_aliq_interestadual', 'alpha');
	$icms_cred_aliq = GETPOST('icms_cred_aliq', 'alpha');
	$icms_st_mva = GETPOST('icms_st_mva', 'alpha');
	$icms_st_aliq = GETPOST('icms_st_aliq', 'alpha');
	$icms_st_red_bc = GETPOST('icms_st_red_bc', 'alpha');
	$difal_aliq_fcp = GETPOST('difal_aliq_fcp', 'alpha');
	$pis_cst = GETPOST('pis_cst', 'alpha');
	$pis_aliq = GETPOST('pis_aliq', 'alpha');
	$cofins_cst = GETPOST('cofins_cst', 'alpha');
	$cofins_aliq = GETPOST('cofins_aliq', 'alpha');
	$ipi_cst = GETPOST('ipi_cst', 'alpha');
	$ipi_aliq = GETPOST('ipi_aliq', 'alpha');
	$ipi_cenq = GETPOST('ipi_cenq', 'alpha');
	$date_start = GETPOST('date_start', 'alpha');
	$date_end = GETPOST('date_end', 'alpha');

	// Novo: status ativo/inativo (1 = ativo, 0 = inativo). Default 1.
	$active = GETPOST('active', 'int') !== '' ? (int)GETPOST('active', 'int') : 1;

	$sql = "INSERT INTO " . MAIN_DB_PREFIX . "custom_tax_rules2 (
		label, uf_origin, uf_dest, cfop, ncm,
		icms_aliq_interna, icms_aliq_interestadual, icms_cred_aliq,
		icms_st_mva, icms_st_aliq, icms_st_red_bc, difal_aliq_fcp,
		pis_cst, pis_aliq, cofins_cst, cofins_aliq,
		ipi_cst, ipi_aliq, ipi_cenq, date_start, date_end,
		fk_user_create, active
	) VALUES (
		'" . $db->escape($label) . "',
		'" . $db->escape($uf_origin) . "',
		'" . $db->escape($uf_dest) . "',
		'" . $db->escape($cfop) . "',
		" . ($ncm ? "'" . $db->escape($ncm) . "'" : "NULL") . ",
		" . ($icms_aliq_interna ?: '0.00') . ",
		" . ($icms_aliq_interestadual ?: '0.00') . ",
		" . ($icms_cred_aliq ?: '0.00') . ",
		" . ($icms_st_mva ?: '0.00') . ",
		" . ($icms_st_aliq ?: '0.00') . ",
		" . ($icms_st_red_bc ?: '0.00') . ",
		" . ($difal_aliq_fcp ?: '0.00') . ",
		'" . $db->escape($pis_cst ?: '49') . "',
		" . ($pis_aliq ?: '0.00') . ",
		'" . $db->escape($cofins_cst ?: '49') . "',
		" . ($cofins_aliq ?: '0.00') . ",
		" . ($ipi_cst ? "'" . $db->escape($ipi_cst) . "'" : "NULL") . ",
		" . ($ipi_aliq ?: '0.00') . ",
		'" . $db->escape($ipi_cenq ?: '999') . "',
		" . ($date_start ? "'" . $db->escape($date_start) . "'" : "NULL") . ",
		" . ($date_end ? "'" . $db->escape($date_end) . "'" : "NULL") . ",
		" . $user->id . ",
		" . (int)$active . "
	)";
	
	if ($db->query($sql)) {
		$db->commit();
		setEventMessages("Regra fiscal criada com sucesso!", null, 'mesgs');
		// Retorna com limit padrão
		header("Location: " . $_SERVER['PHP_SELF']);
		exit;
	} else {
		$db->rollback();
		setEventMessages("Erro ao criar regra: " . $db->lasterror(), null, 'errors');
	}
} elseif ($action == 'edit' && $id > 0) {
    // Carrega dados para edição
    $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "custom_tax_rules2 WHERE rowid = " . (int)$id;
    $result = $db->query($sql);
    if ($result && $db->num_rows($result) > 0) {
        $obj = $db->fetch_object($result);
    } else {
        setEventMessages("Regra não encontrada", null, 'errors');
        $action = '';
    }
} elseif ($action == 'update' && $id > 0) {
	// Atualização
	$db->begin();

	$label = GETPOST('label', 'alphanohtml');
	$uf_origin = GETPOST('uf_origin', 'alpha');
	$uf_dest = GETPOST('uf_dest', 'alpha');
	$cfop = GETPOST('cfop', 'alpha');
	$ncm = GETPOST('ncm', 'alpha');
	$icms_aliq_interna = GETPOST('icms_aliq_interna', 'alpha');
	$icms_aliq_interestadual = GETPOST('icms_aliq_interestadual', 'alpha');
	$icms_cred_aliq = GETPOST('icms_cred_aliq', 'alpha');
	$icms_st_mva = GETPOST('icms_st_mva', 'alpha');
	$icms_st_aliq = GETPOST('icms_st_aliq', 'alpha');
	$icms_st_red_bc = GETPOST('icms_st_red_bc', 'alpha');
	$difal_aliq_fcp = GETPOST('difal_aliq_fcp', 'alpha');
	$pis_cst = GETPOST('pis_cst', 'alpha');
	$pis_aliq = GETPOST('pis_aliq', 'alpha');
	$cofins_cst = GETPOST('cofins_cst', 'alpha');
	$cofins_aliq = GETPOST('cofins_aliq', 'alpha');
	$ipi_cst = GETPOST('ipi_cst', 'alpha');
	$ipi_aliq = GETPOST('ipi_aliq', 'alpha');
	$ipi_cenq = GETPOST('ipi_cenq', 'alpha');
	$date_start = GETPOST('date_start', 'alpha');
	$date_end = GETPOST('date_end', 'alpha');

	// Novo: ler status enviado no formulário
	$active = GETPOST('active', 'int') !== '' ? (int)GETPOST('active', 'int') : 1;

	$sql = "UPDATE " . MAIN_DB_PREFIX . "custom_tax_rules2 SET
		label = '" . $db->escape($label) . "',
		uf_origin = '" . $db->escape($uf_origin) . "',
		uf_dest = '" . $db->escape($uf_dest) . "',
		cfop = '" . $db->escape($cfop) . "',
		ncm = " . ($ncm ? "'" . $db->escape($ncm) . "'" : "NULL") . ",
		icms_aliq_interna = " . ($icms_aliq_interna ?: '0.00') . ",
		icms_aliq_interestadual = " . ($icms_aliq_interestadual ?: '0.00') . ",
		icms_cred_aliq = " . ($icms_cred_aliq ?: '0.00') . ",
		icms_st_mva = " . ($icms_st_mva ?: '0.00') . ",
		icms_st_aliq = " . ($icms_st_aliq ?: '0.00') . ",
		icms_st_red_bc = " . ($icms_st_red_bc ?: '0.00') . ",
		difal_aliq_fcp = " . ($difal_aliq_fcp ?: '0.00') . ",
		pis_cst = '" . $db->escape($pis_cst ?: '49') . "',
		pis_aliq = " . ($pis_aliq ?: '0.00') . ",
		cofins_cst = '" . $db->escape($cofins_cst ?: '49') . "',
		cofins_aliq = " . ($cofins_aliq ?: '0.00') . ",
		ipi_cst = " . ($ipi_cst ? "'" . $db->escape($ipi_cst) . "'" : "NULL") . ",
		ipi_aliq = " . ($ipi_aliq ?: '0.00') . ",
		ipi_cenq = '" . $db->escape($ipi_cenq ?: '999') . "',
		date_start = " . ($date_start ? "'" . $db->escape($date_start) . "'" : "NULL") . ",
		date_end = " . ($date_end ? "'" . $db->escape($date_end) . "'" : "NULL") . ",
		fk_user_modify = " . $user->id . ",
		active = " . (int)$active . "
	WHERE rowid = " . (int)$id;
	
	if ($db->query($sql)) {
		$db->commit();
		setEventMessages("Regra fiscal atualizada com sucesso!", null, 'mesgs');
		// Retorna preservando parâmetros
		$return_url = $_SERVER['PHP_SELF'] . '?';
		$return_params = [];
		// Sempre inclui limit
		$return_params[] = 'limit=' . (int)$limit;
		if ($page > 0) $return_params[] = 'page=' . (int)$page;
		if (!empty($search_status_list)) $return_params[] = 'search_status_list=' . urlencode($search_status_list);
		if (!empty($search_label)) $return_params[] = 'search_label=' . urlencode($search_label);
		if (!empty($search_uf_origin)) $return_params[] = 'search_uf_origin=' . urlencode($search_uf_origin);
		if (!empty($search_uf_dest)) $return_params[] = 'search_uf_dest=' . urlencode($search_uf_dest);
		if (!empty($search_cfop)) $return_params[] = 'search_cfop=' . urlencode($search_cfop);
		if (!empty($search_ncm)) $return_params[] = 'search_ncm=' . urlencode($search_ncm);
		$return_url .= implode('&', $return_params);
		header("Location: " . $return_url);
		exit;
	} else {
		$db->rollback();
		setEventMessages("Erro ao atualizar regra: " . $db->lasterror(), null, 'errors');
	}
} elseif ($action == 'confirm_delete' && $id > 0 && $confirm == 'yes') {
    // Exclusão
    $sql = "DELETE FROM " . MAIN_DB_PREFIX . "custom_tax_rules2 WHERE rowid = " . (int)$id;
    if ($db->query($sql)) {
        setEventMessages("Regra fiscal excluída com sucesso!", null, 'mesgs');
        // Retorna preservando parâmetros
        $return_url = $_SERVER['PHP_SELF'] . '?';
        $return_params = [];
        // Sempre inclui limit
        $return_params[] = 'limit=' . (int)$limit;
        if ($page > 0) $return_params[] = 'page=' . (int)$page;
        if (!empty($search_status_list)) $return_params[] = 'search_status_list=' . urlencode($search_status_list);
        if (!empty($search_label)) $return_params[] = 'search_label=' . urlencode($search_label);
        if (!empty($search_uf_origin)) $return_params[] = 'search_uf_origin=' . urlencode($search_uf_origin);
        if (!empty($search_uf_dest)) $return_params[] = 'search_uf_dest=' . urlencode($search_uf_dest);
        if (!empty($search_cfop)) $return_params[] = 'search_cfop=' . urlencode($search_cfop);
        if (!empty($search_ncm)) $return_params[] = 'search_ncm=' . urlencode($search_ncm);
        $return_url .= implode('&', $return_params);
        header("Location: " . $return_url);
        exit;
    } else {
        setEventMessages("Erro ao excluir regra: " . $db->lasterror(), null, 'errors');
    }
} elseif ($action == 'toggle' && $id > 0) {
    // Ativar/Desativar
    $sql = "UPDATE " . MAIN_DB_PREFIX . "custom_tax_rules2 
            SET active = 1 - active 
            WHERE rowid = " . (int)$id;
    $db->query($sql);
    
    // Reconstrói URL de retorno mantendo todos os parâmetros
    $return_url = $_SERVER['PHP_SELF'] . '?';
    $return_params = [];
    // Sempre inclui limit
    $return_params[] = 'limit=' . (int)$limit;
    if ($page > 0) $return_params[] = 'page=' . (int)$page;
    if (!empty($search_status_list)) $return_params[] = 'search_status_list=' . urlencode($search_status_list);
    if (!empty($search_label)) $return_params[] = 'search_label=' . urlencode($search_label);
    if (!empty($search_uf_origin)) $return_params[] = 'search_uf_origin=' . urlencode($search_uf_origin);
    if (!empty($search_uf_dest)) $return_params[] = 'search_uf_dest=' . urlencode($search_uf_dest);
    if (!empty($search_cfop)) $return_params[] = 'search_cfop=' . urlencode($search_cfop);
    if (!empty($search_ncm)) $return_params[] = 'search_ncm=' . urlencode($search_ncm);
    if (!empty($sortfield)) $return_params[] = 'sortfield=' . urlencode($sortfield);
    if (!empty($sortorder)) $return_params[] = 'sortorder=' . urlencode($sortorder);
    
    $return_url .= implode('&', $return_params);
    header("Location: " . $return_url);
    exit;
} elseif ($action == 'save_aliq_cred') {
    // NOVA AÇÃO: Salvar Alíquota de Permissão de Crédito
    $aliq_cred_perm = (float)GETPOST('aliq_cred_perm', 'alpha');
    $mes_referencia = GETPOST('mes_referencia', 'alpha'); // formato YYYY-MM
    
    if (empty($mes_referencia)) {
        setEventMessages("Mês de referência é obrigatório", null, 'errors');
    } elseif ($aliq_cred_perm < 0 || $aliq_cred_perm > 100) {
        setEventMessages("Alíquota deve estar entre 0% e 100%", null, 'errors');
    } else {
        // Verifica se já existe registro para este mês
        $sql_check = "SELECT rowid FROM " . MAIN_DB_PREFIX . "nfe_perm_credito WHERE mes_referencia = '" . $db->escape($mes_referencia) . "'";
        $res_check = $db->query($sql_check);
        
        if ($res_check && $db->num_rows($res_check) > 0) {
            // Atualiza
            $obj_check = $db->fetch_object($res_check);
            $sql = "UPDATE " . MAIN_DB_PREFIX . "nfe_perm_credito 
                    SET aliq_cred_perm = " . $aliq_cred_perm . ",
                        fk_user_modify = " . $user->id . ",
                        date_modification = NOW()
                    WHERE rowid = " . (int)$obj_check->rowid;
        } else {
            // Insere
            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "nfe_perm_credito (
                        aliq_cred_perm, mes_referencia, fk_user_create, date_creation
                    ) VALUES (
                        " . $aliq_cred_perm . ",
                        '" . $db->escape($mes_referencia) . "',
                        " . $user->id . ",
                        NOW()
                    )";
        }
        
        if ($db->query($sql)) {
            setEventMessages("Alíquota de permissão de crédito salva com sucesso!", null, 'mesgs');
        } else {
            setEventMessages("Erro ao salvar: " . $db->lasterror(), null, 'errors');
        }
    }
}

// Cabeçalho da página
llxHeader('', 'Regras Fiscais — Configuração', '');
// Adiciona CSS externo para o novo layout
print '<link rel="stylesheet" type="text/css" href="'.DOL_URL_ROOT.'/custom/nfe/css/regras_fiscais.css">';
print '<style>
.div-table-responsive .tagtable.liste {
	background: transparent !important;
	border: 1px solid #dfe6ea;
	border-radius: 6px;
	overflow: hidden;
}
.div-table-responsive .tagtable.liste .liste_titre {
	background: #f6f8f9;
	color: #2b2b2b;
	font-weight: 600;
	border-bottom: 1px solid #e6ecef;
}
.div-table-responsive .tagtable.liste tr {
	background: transparent;
}
.div-table-responsive .tagtable.liste tr.oddeven {
	background: #ffffff;
	transition: background .2s ease;
}
.div-table-responsive .tagtable.liste tr.oddeven:hover {
	background: #f4f8fa;
}
.div-table-responsive .tagtable.liste td,
.div-table-responsive .tagtable.liste th {
	border-bottom: 1px solid #f0f4f6;
	padding: 8px 10px;
	vertical-align: middle;
}
.rf-status-circle {
	display: inline-block;
	width: 10px;
	height: 10px;
	border-radius: 50%;
	margin-right: 6px;
	vertical-align: middle;
}
.rf-status-active { background: #2aa26f; box-shadow: 0 0 0 4px rgba(42,162,111,0.06); }
.rf-status-inactive { background: #c1c7cc; box-shadow: 0 0 0 4px rgba(193,199,204,0.04); }
.rf-actions-row .butAction {
	margin-right: 6px;
	padding: 4px 10px;
	font-size: 12px;
	height: 34px;
	line-height: 1.2;
	border-radius: 6px;
	display: inline-flex;
	align-items: center;
	justify-content: center;
}
@media (max-width: 800px) {
	.rf-actions-row .butAction { display: inline-block; margin-bottom: 6px; padding: 6px 10px; font-size: 13px; }
}
/* NOVO: estilo consistente para o select de mês */
.rf-select-month {
	width: 100%;
	height: 40px;
	padding: 8px 12px;
	font-size: 14px;
	border: 1px solid #d1dbe6;
	border-radius: 6px;
	background: #ffffff;
	box-sizing: border-box;
	-webkit-appearance: none;
	-moz-appearance: none;
	appearance: none;
	background-image: linear-gradient(45deg, transparent 50%, #94a3b8 50%), linear-gradient(135deg, #94a3b8 50%, transparent 50%);
	background-position: calc(100% - 18px) calc(50% - 3px), calc(100% - 12px) calc(50% - 3px);
	background-size: 6px 6px, 6px 6px;
	background-repeat: no-repeat;
}
.rf-select-month option { white-space: nowrap; }
.rf-fields-grid .rf-field select { width: 100%; box-sizing: border-box; }

/* ===== NOVOS ESTILOS: Histórico compacto e discreto ===== */
.rf-history {
	display: flex;
	flex-direction: column;
	gap: 6px;
	margin-top: 8px;
}
.rf-history-item {
	display: flex;
	justify-content: space-between;
	align-items: center;
	padding: 6px 10px;
	border: 1px solid #eef2f6;
	background: #ffffff;
	border-radius: 4px;
	font-size: 13px;
	color: #0f172a;
}
.rf-history-item.current { border-color: #dbeafe; background: #fbfdff; }
.rf-history-left { display:flex; flex-direction: column; gap:2px; }
.rf-history-month { font-weight:600; font-size:13px; color:#0f172a; }
.rf-history-meta { font-size:11px; color:#94a3b8; }
.rf-history-rate { font-weight:700; color:#0ea5e9; min-width:64px; text-align:right; }
/* Mobile: empilha e alinha à esquerda */
@media (max-width:720px) {
	.rf-history-item { flex-direction: column; align-items: flex-start; gap:6px; }
	.rf-history-rate { text-align:left; min-width: auto; }
}
</style>';

// Estados brasileiros
$estados_brasil = array(
    'AC' => 'Acre', 'AL' => 'Alagoas', 'AP' => 'Amapá', 'AM' => 'Amazonas',
    'BA' => 'Bahia', 'CE' => 'Ceará', 'DF' => 'Distrito Federal', 'ES' => 'Espírito Santo',
    'GO' => 'Goiás', 'MA' => 'Maranhão', 'MT' => 'Mato Grosso', 'MS' => 'Mato Grosso do Sul',
    'MG' => 'Minas Gerais', 'PA' => 'Pará', 'PB' => 'Paraíba', 'PR' => 'Paraná',
    'PE' => 'Pernambuco', 'PI' => 'Piauí', 'RJ' => 'Rio de Janeiro', 'RN' => 'Rio Grande do Norte',
    'RS' => 'Rio Grande do Sul', 'RO' => 'Rondônia', 'RR' => 'Roraima', 'SC' => 'Santa Catarina',
    'SP' => 'São Paulo', 'SE' => 'Sergipe', 'TO' => 'Tocantins'
);

// ========== CARREGA ALÍQUOTA ATUAL (MÊS CORRENTE) ==========
$mesCorrente = date('Y-m');
// NOVO: Se veio mês pela URL/POST, usa ele
$mesSelecionado = GETPOST('mes_referencia', 'alpha');
if (empty($mesSelecionado)) {
    $mesSelecionado = $mesCorrente;
}

$aliqCredAtual = null;
$sql_cred = "SELECT * FROM " . MAIN_DB_PREFIX . "nfe_perm_credito WHERE mes_referencia = '" . $db->escape($mesSelecionado) . "' LIMIT 1";
$res_cred = $db->query($sql_cred);
if ($res_cred && $db->num_rows($res_cred) > 0) {
    $aliqCredAtual = $db->fetch_object($res_cred);
}

// Título da página
print '<div class="fichecenter">';
print load_fiche_titre('Regras Fiscais — Configuração', '', 'nfe@nfe');

// ========== SEÇÃO: ALÍQUOTA DE PERMISSÃO DE CRÉDITO ==========
if ($action != 'create' && $action != 'edit') {
    // Gerenciamento de abas
    $activeTab = GETPOST('tab_cred', 'alpha');
    if (empty($activeTab)) {
        $activeTab = 'config'; // Tab padrão
    }
    
    print '<div class="rf-wrapper" style="margin-bottom: 20px;">';
    print '<div class="rf-header">';
    print '<h2>📊 Alíquota de Permissão de Crédito (Simples Nacional)</h2>';
    print '<span class="rf-status-badge">Mensal</span>';
    print '</div>';
    
    // ========== SISTEMA DE ABAS ==========
    print '<div style="border-bottom: 2px solid #e5e7eb; margin-bottom: 20px;">';
    print '<div style="display: flex; gap: 8px;">';
    
    // Aba: Configuração Mensal
    $tabConfigClass = ($activeTab === 'config') ? 'rf-tab-active' : 'rf-tab-inactive';
    print '<a href="'.$_SERVER['PHP_SELF'].'?tab_cred=config" class="'.$tabConfigClass.'" style="text-decoration: none;">';
    print '💰 Configuração Mensal';
    print '</a>';
    
    // Aba: Histórico
    $tabHistClass = ($activeTab === 'historico') ? 'rf-tab-active' : 'rf-tab-inactive';
    print '<a href="'.$_SERVER['PHP_SELF'].'?tab_cred=historico" class="'.$tabHistClass.'" style="text-decoration: none;">';
    print '📊 Histórico';
    print '</a>';
    
    print '</div>';
    print '</div>';
    
    print '<div class="rf-main-grid">';
    print '<div class="rf-panel span-12">';
    // print '<div class="rf-panel-title">💰 Configuração Mensal</div>';
    
    // ========== CONTEÚDO DA ABA: CONFIGURAÇÃO MENSAL ==========
    if ($activeTab === 'config') {
    print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '" style="padding: 20px;">';
    print '<input type="hidden" name="token" value="' . $token . '">';
    print '<input type="hidden" name="action" value="save_aliq_cred">';
    print '<input type="hidden" name="tab_cred" value="config">'; // Mantém na aba
    
    // NOVO: Grid com 2 colunas (Mês e Alíquota na primeira linha, Botão na segunda)
    print '<div class="rf-fields-grid" style="grid-template-columns: 1fr; gap: 8px;">';
    
    // Campo Alíquota
    print '<div class="rf-field">';
    print '<label style="font-weight: 600; color: #1e293b; font-size: 12px;">Alíquota de Crédito (%)</label>';
    print '<input type="number" step="0.01" min="0" max="100" name="aliq_cred_perm" required ';
    print 'style="font-size: 12px; font-weight: 500; padding: 6px; width: 20%;" '; // Reduzido para 80%
    print 'value="' . ($aliqCredAtual ? number_format($aliqCredAtual->aliq_cred_perm, 2, '.', '') : '0.00') . '" ';
    print 'title="Alíquota de crédito permitida (conforme DAS)">';
    print '</div>';

    // Seletor de Mês
    print '<div class="rf-field">';
    print '<label style="font-weight: 600; color: #1e293b; font-size: 12px;">Mês/Ano</label>';
    print '<select name="mes_referencia" class="rf-select-month" required title="Selecione o mês" style="font-size: 12px; padding: 6px; width: 20%;">'; // Reduzido para 80%

    // Tradução manual dos meses
$mesesPortugues = [
    'January' => 'Janeiro', 'February' => 'Fevereiro', 'March' => 'Março',
    'April' => 'Abril', 'May' => 'Maio', 'June' => 'Junho',
    'July' => 'Julho', 'August' => 'Agosto', 'September' => 'Setembro',
    'October' => 'Outubro', 'November' => 'Novembro', 'December' => 'Dezembro'
];

$mesesDisponiveis = [];
for ($i = -3; $i <= 6; $i++) {
    $timestamp = strtotime("$i month");
    $mesValor = date('Y-m', $timestamp);
    $mesNomeIngles = date('F Y', $timestamp); // Nome do mês em inglês
    $mesNome = str_replace(array_keys($mesesPortugues), array_values($mesesPortugues), $mesNomeIngles); // Traduz para português
    $mesesDisponiveis[] = ['valor' => $mesValor, 'label' => $mesNome];
}

foreach ($mesesDisponiveis as $m) {
    $selected = ($m['valor'] == $mesSelecionado) ? ' selected' : '';
    print '<option value="'.$m['valor'].'"'.$selected.'>'.$m['label'].'</option>';
}
    print '</select>';
    print '</div>';

    print '</div>'; // Fim grid
    
    // Botão Salvar (grid completo)
    print '<div style="grid-column: 1 / -1; margin-top: 16px;">';
    print '<button type="submit" class="rf-btn rf-btn-primary">💾 Salvar Alíquota do Mês</button>';
    print '</div>';
    
    print '</form>';
    
    } // Fim aba config
    
    // ========== CONTEÚDO DA ABA: HISTÓRICO ==========
    elseif ($activeTab === 'historico') {
    print '<div style="padding: 20px;">';

    // Container com limite de largura e alinhamento à esquerda
    print '<div style="display: flex; flex-wrap: wrap; gap: 8px; max-width: 600px;">';

    // Busca últimos 12 meses
    $sql_hist = "SELECT * FROM " . MAIN_DB_PREFIX . "nfe_perm_credito ORDER BY mes_referencia DESC LIMIT 12";
    $res_hist = $db->query($sql_hist);

    if ($res_hist && $db->num_rows($res_hist) > 0) {
        // Tradução manual dos meses para português
        $mesesPortugues = [
            'January' => 'Janeiro', 'February' => 'Fevereiro', 'March' => 'Março',
            'April' => 'Abril', 'May' => 'Maio', 'June' => 'Junho',
            'July' => 'Julho', 'August' => 'Agosto', 'September' => 'Setembro',
            'October' => 'Outubro', 'November' => 'Novembro', 'December' => 'Dezembro'
        ];

        while ($hist = $db->fetch_object($res_hist)) {
            $isCurrent = ($hist->mes_referencia == $mesSelecionado);
            $timestamp = strtotime($hist->mes_referencia . '-01');
            $mesAnoIngles = date('F Y', $timestamp); // Nome do mês em inglês
            $mesAno = str_replace(array_keys($mesesPortugues), array_values($mesesPortugues), $mesAnoIngles); // Traduz para português

            print '<div style="flex: 1 1 calc(33.33% - 8px); padding: 8px 10px; background: ' . ($isCurrent ? '#e5f3ff' : '#f8f9fa') . '; border-radius: 6px; font-size: 12px; border: 1px solid #e2e8f0; min-width: 150px;">';
            print '<span style="font-weight: 500; color: #1e293b;">' . $mesAno . '</span>';
            print '<span style="font-weight: 600; color: #0ea5e9; float: right;">' . number_format($hist->aliq_cred_perm, 2, ',', '.') . '%</span>';
            print '</div>';
        }
    } else {
        print '<div style="text-align: center; padding: 16px; background: #f8fafc; border-radius: 8px; border: 1px solid #e2e8f0; width: 100%;">';
        print '<p style="color: #64748b; font-size: 14px; margin: 0;">Nenhum histórico disponível</p>';
        print '</div>';
    }

    print '</div>'; // Fim container
    print '</div>'; // Fim padding historico
    
    } // Fim aba historico
    
    print '</div>'; // Fim painel
    print '</div>'; // Fim grid
    print '</div>'; // Fim wrapper
}

// Modal de confirmação de exclusão (Padrão Dolibarr)
if ($action == 'delete') {
    print $form->form_confirm(
        $_SERVER["PHP_SELF"] . '?id=' . $id . $param . '&page=' . $page,
        'Excluir Regra',
        'Tem certeza que deseja excluir esta regra fiscal?',
        'confirm_delete',
        '',
        0,
        1
    );
}

// ===== FORM (CREATE / EDIT) - NOVO LAYOUT =====
if ($action == 'create' || $action == 'edit') {

	print '<form method="POST" action="' . $_SERVER['PHP_SELF'] . '">';
	print '<input type="hidden" name="token" value="' . $token . '">';
	print '<input type="hidden" name="action" value="' . ($action == 'create' ? 'create' : 'update') . '">';
	if ($action == 'edit') print '<input type="hidden" name="id" value="' . $id . '">';

	print '<div class="rf-wrapper">';
	
	// Header Compacto
	print '<div class="rf-header">';
	print '<h2>' . ($action == 'create' ? 'Nova Regra Fiscal' : 'Editando Regra #' . $id) . '</h2>';
	print '<span class="rf-status-badge">' . ($action == 'create' ? 'Modo Criação' : 'Modo Edição') . '</span>';
	print '</div>';

	print '<div class="rf-main-grid">';

	// === PAINEL: IDENTIFICAÇÃO (Ocupa toda a largura) ===
	print '<div class="rf-panel span-12">';
	print '<div class="rf-panel-title">📍 Identificação e Vigência</div>';
	print '<div class="rf-fields-grid" style="grid-template-columns: 2.5fr 1fr 1fr 1.5fr 1fr 1.5fr 1.5fr;">';
	
	// Label
	print '<div class="rf-field">';
	print '<label>Descrição da Regra *</label>';
	print '<input type="text" name="label" required placeholder="Ex: Venda SP para RJ" value="' . dol_escape_htmltag($obj->label ?? '') . '" title="Nome descritivo para identificar esta regra fiscal">';
	print '</div>';

	// UF Origem
	print '<div class="rf-field">';
	print '<label>UF Origem *</label>';
	print '<select name="uf_origin" required title="Estado de origem da mercadoria">';
	print '<option value="">Selecione</option>';
	foreach ($estados_brasil as $sigla => $nome) {
		$sel = (isset($obj) && $obj->uf_origin == $sigla) ? ' selected' : '';
		print '<option value="' . $sigla . '"' . $sel . '>' . $sigla . '</option>';
	}
	print '</select>';
	print '</div>';

	// UF Destino
	print '<div class="rf-field">';
	print '<label>UF Destino *</label>';
	print '<select name="uf_dest" required title="Estado de destino da mercadoria">';
	print '<option value="">Selecione</option>';
	foreach ($estados_brasil as $sigla => $nome) {
		$sel = (isset($obj) && $obj->uf_dest == $sigla) ? ' selected' : '';
		print '<option value="' . $sigla . '"' . $sel . '>' . $sigla . '</option>';
	}
	print '</select>';
	print '</div>';

	// NCM (MOVIDO PARA CÁ - PRIORIDADE)
	print '<div class="rf-field">';
	print '<label>NCM (Opcional)</label>';
	print '<input type="text" name="ncm" maxlength="8" placeholder="Ex: 12345678" value="' . dol_escape_htmltag($obj->ncm ?? '') . '" title="Nomenclatura Comum do Mercosul (8 dígitos). Deixe vazio para aplicar a todos os NCMs">';
	print '</div>';

	// CFOP
	print '<div class="rf-field">';
	print '<label>CFOP *</label>';
	print '<input type="text" name="cfop" maxlength="4" required placeholder="5102" value="' . dol_escape_htmltag($obj->cfop ?? '') . '" title="Código Fiscal de Operações e Prestações (4 dígitos)">';
	print '</div>';

	// Data Inicio
	print '<div class="rf-field">';
	print '<label>Início Vigência</label>';
	print '<input type="date" name="date_start" value="' . dol_escape_htmltag($obj->date_start ?? '') . '" title="Data de início da vigência desta regra">';
	print '</div>';

	// Data Fim
	print '<div class="rf-field">';
	print '<label>Fim Vigência</label>';
	print '<input type="date" name="date_end" value="' . dol_escape_htmltag($obj->date_end ?? '') . '" title="Data de término da vigência. Deixe vazio para vigência permanente">';
	print '</div>';
    
	// Novo: campo Status (Ativa / Inativa)
	print '<div class="rf-field">';
	print '<label>Status</label>';
	print '<select name="active" title="Selecione se a regra estará ativa ou inativa">';
	$selected_active = isset($obj) ? (int)$obj->active : 1;
	print '<option value="1"' . ($selected_active === 1 ? ' selected' : '') . '>Ativa</option>';
	print '<option value="0"' . ($selected_active === 0 ? ' selected' : '') . '>Inativa</option>';
	print '</select>';
	print '</div>';

	print '</div>'; // Fim grid
	print '</div>'; // Fim painel



	// === PAINEL: ICMS ===
	print '<div class="rf-panel span-6">';
	print '<div class="rf-panel-title">💰 ICMS (Próprio e ST)</div>';
	print '<div class="rf-fields-grid cols-4">';
	
	print '<div class="rf-field"><label>Aliq. Interna (%)</label><input type="number" step="0.01" name="icms_aliq_interna" value="' . ($obj->icms_aliq_interna ?? '0.00') . '" title="Alíquota interna do ICMS no estado de destino"></div>';
	print '<div class="rf-field"><label>Aliq. Interest. (%)</label><input type="number" step="0.01" name="icms_aliq_interestadual" value="' . ($obj->icms_aliq_interestadual ?? '0.00') . '" title="Alíquota interestadual do ICMS (7% ou 12%)"></div>';
	print '<div class="rf-field"><label>Crédito SN (%)</label><input type="number" step="0.01" name="icms_cred_aliq" value="' . ($obj->icms_cred_aliq ?? '0.00') . '" title="Alíquota de crédito para Simples Nacional (CSOSN 101)"></div>';
	print '<div class="rf-field"><label>FCP / Difal (%)</label><input type="number" step="0.01" name="difal_aliq_fcp" value="' . ($obj->difal_aliq_fcp ?? '0.00') . '" title="Alíquota do Fundo de Combate à Pobreza (FCP) para DIFAL"></div>';
	print '<div class="rf-field"><label>MVA ST (%)</label><input type="number" step="0.0001" name="icms_st_mva" value="' . ($obj->icms_st_mva ?? '0.00') . '" title="Margem de Valor Agregado para cálculo do ICMS-ST"></div>';
	print '<div class="rf-field"><label>Alíquota ST (%)</label><input type="number" step="0.01" name="icms_st_aliq" value="' . ($obj->icms_st_aliq ?? '0.00') . '" title="Alíquota do ICMS para Substituição Tributária"></div>';
	print '<div class="rf-field span-2"><label>Redução BC ST (%)</label><input type="number" step="0.01" name="icms_st_red_bc" value="' . ($obj->icms_st_red_bc ?? '0.00') . '" title="Percentual de redução da Base de Cálculo do ICMS-ST"></div>';
	
	print '</div>';
	print '</div>';

	// === PAINEL: PIS/COFINS ===
	print '<div class="rf-panel span-3">';
	print '<div class="rf-panel-title">📊 PIS / COFINS</div>';
	print '<div class="rf-fields-grid cols-2">';
	
	print '<div class="rf-field"><label>CST PIS</label><input type="text" maxlength="2" name="pis_cst" value="' . dol_escape_htmltag($obj->pis_cst ?? '49') . '" title="Código de Situação Tributária do PIS (ex: 01, 02, 49, 99)"></div>';
	print '<div class="rf-field"><label>Aliq. PIS (%)</label><input type="number" step="0.01" name="pis_aliq" value="' . ($obj->pis_aliq ?? '0.00') . '" title="Alíquota do PIS (geralmente 0,65% ou 1,65%)"></div>';
	print '<div class="rf-field"><label>CST COFINS</label><input type="text" maxlength="2" name="cofins_cst" value="' . dol_escape_htmltag($obj->cofins_cst ?? '49') . '" title="Código de Situação Tributária do COFINS (ex: 01, 02, 49, 99)"></div>';
	print '<div class="rf-field"><label>Aliq. COFINS (%)</label><input type="number" step="0.01" name="cofins_aliq" value="' . ($obj->cofins_aliq ?? '0.00') . '" title="Alíquota do COFINS (geralmente 3% ou 7,6%)"></div>';
	
	print '</div>';
	print '</div>';

	// === PAINEL: IPI (SEM NCM AGORA) ===
	print '<div class="rf-panel span-3">';
	print '<div class="rf-panel-title">🏭 IPI</div>';
	print '<div class="rf-fields-grid cols-2">';
	
	print '<div class="rf-field"><label>CST IPI</label><input type="text" maxlength="2" name="ipi_cst" value="' . dol_escape_htmltag($obj->ipi_cst ?? '') . '" title="Código de Situação Tributária do IPI (ex: 00, 49, 50, 99)"></div>';
	print '<div class="rf-field"><label>Aliq. IPI (%)</label><input type="number" step="0.01" name="ipi_aliq" value="' . ($obj->ipi_aliq ?? '0.00') . '" title="Alíquota do IPI conforme TIPI"></div>';
	print '<div class="rf-field span-2"><label>Cód. Enquad. IPI</label><input type="text" maxlength="3" name="ipi_cenq" value="' . dol_escape_htmltag($obj->ipi_cenq ?? '999') . '" title="Código de Enquadramento Legal do IPI (999 para não enquadrado)"></div>';
	
	print '</div>';
	print '</div>';

	print '</div>'; // Fim grid principal

	// Ações
	print '<div class="rf-actions">';
	print '<button type="button" class="rf-btn rf-btn-secondary" onclick="window.location.href=\'' . $_SERVER['PHP_SELF'] . '\'">Cancelar</button>';
	print '<button type="submit" class="rf-btn rf-btn-primary">' . ($action == 'create' ? 'Salvar Regra' : 'Salvar Alterações') . '</button>';
	print '</div>';

	print '</div>'; // Fim wrapper
	print '</form>';

} else {
    // ========== LISTAGEM COM FILTROS E PAGINAÇÃO ==========
    
    // Monta cláusulas WHERE
    $whereClauses = [];
    
    // Filtro de status (ativo/inativo) - CORRIGIDO
    $selectedStatuses = array();
    // Fix: Check length instead of empty() because empty('0') is true in PHP
    if (strlen((string)$search_status_list) > 0) {
        foreach (explode(',', (string)$search_status_list) as $st) {
            $stVal = trim($st);
            // Aceita apenas '0' ou '1' como strings
            if ($stVal === '0' || $stVal === '1') {
                $selectedStatuses[] = (int)$stVal;
            }
        }
        $selectedStatuses = array_values(array_unique($selectedStatuses));
    }
    if (!empty($selectedStatuses)) {
        $vals = array_map(function($v){ return (int)$v; }, $selectedStatuses);
        $whereClauses[] = "active IN (".implode(',', $vals).")";
    }
    
    // Filtro por label
    if (!empty($search_label)) {
        $searchTerm = $db->escape(strtolower(trim($search_label)));
        $whereClauses[] = "LOWER(label) LIKE '%" . $searchTerm . "%'";
    }
    
    // Filtro por UF origem
    if (!empty($search_uf_origin)) {
        $whereClauses[] = "uf_origin = '" . $db->escape(strtoupper($search_uf_origin)) . "'";
    }
    
    // Filtro por UF destino
    if (!empty($search_uf_dest)) {
        $whereClauses[] = "uf_dest = '" . $db->escape(strtoupper($search_uf_dest)) . "'";
    }
    
    // Filtro por CFOP
    if (!empty($search_cfop)) {
        $whereClauses[] = "cfop LIKE '%" . $db->escape($search_cfop) . "%'";
    }
    
    // Filtro por NCM
    if (!empty($search_ncm)) {
        $whereClauses[] = "ncm LIKE '%" . $db->escape($search_ncm) . "%'";
    }
    
    $whereSQL = empty($whereClauses) ? '' : ' WHERE ' . implode(' AND ', $whereClauses);
    
    // Contagem total COM filtros
    $sql_count = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . "custom_tax_rules2" . $whereSQL;
    $res_count = $db->query($sql_count);
    $total_rows = 0;
    if ($res_count) {
        $objc = $db->fetch_object($res_count);
        $total_rows = $objc ? (int)$objc->total : 0;
    }
    
    // Ajusta página se offset exceder total
    if ($offset >= $total_rows && $total_rows > 0) {
        $page = floor(($total_rows - 1) / $limit);
        $offset = $limit * $page;
    }
    
    // Consulta principal
    $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "custom_tax_rules2" . $whereSQL;
    
    // Ordenação segura
    $allowedSort = array('rowid','label','uf_origin','uf_dest','cfop','ncm','active');
    $sortcol = in_array($sortfield, $allowedSort) ? $sortfield : 'rowid';
    $sql .= " ORDER BY " . $sortcol . " " . ($sortorder === 'ASC' ? 'ASC' : 'DESC');
    
    // Paginação - usar LIMIT direto para garantir funcionamento
    $sql .= " LIMIT " . (int)$limit . " OFFSET " . (int)$offset;
    
    $result = $db->query($sql);
    
    if ($result) {
        $num = $db->num_rows($result);
        
        // ========== BARRA DE FILTROS ACIMA DA TABELA ==========
        print '<div class="rf-filter-bar">';
        print '<form method="GET" id="rfListFilterForm" action="' . $_SERVER['PHP_SELF'] . '" class="rf-filter-form">';
        // Preserva o limit atual ao submeter o formulário de filtros
        print '<input type="hidden" name="limit" value="' . (int)$limit . '">';
        
        // Linha 1: Filtros principais (repaginar)
        print '<div class="rf-filter-row">';

        // Filtro Status (CHECKBOX DROPDOWN)
        $selectedStatusesPhp = !empty($selectedStatuses) ? $selectedStatuses : array();
        $selectedStatusListStr = dol_escape_htmltag(implode(',', $selectedStatusesPhp));
        $btnLabel = (count($selectedStatusesPhp) > 0) ? (count($selectedStatusesPhp) . " selecionado(s)") : "Todos";

        print '<div class="rf-filter-group">';
        print '<label class="rf-filter-label">Status</label>';
        print '<input type="hidden" name="search_status_list" id="search_status_list" value="'.$selectedStatusListStr.'">';
        print '<div class="rf-filter-status-wrapper">';
        print '<button type="button" class="rf-status-btn" onclick="toggleStatusDropdown(event)">';
        print '<span id="rfStatusBtnLabel">'.$btnLabel.'</span>';
        print '<span class="arrow">▼</span>';
        print '</button>';
        print '<div class="rf-status-dropdown" id="rfStatusDropdown">';
        print '<label><input type="checkbox" value="1" '.(in_array(1, $selectedStatusesPhp) ? 'checked' : '').' onchange="updateStatusHidden()"> Ativa</label>';
        print '<label><input type="checkbox" value="0" '.(in_array(0, $selectedStatusesPhp) ? 'checked' : '').' onchange="updateStatusHidden()"> Inativa</label>';
        print '</div>';
        print '</div>';
        print '</div>';

        // UF Origem
        print '<div class="rf-filter-group">';
        print '<label class="rf-filter-label">UF Origem</label>';
        print '<select name="search_uf_origin" class="rf-filter-select">';
        print '<option value="">Todas</option>';
        foreach ($estados_brasil as $sigla => $nome) {
            $sel = ($search_uf_origin == $sigla) ? ' selected' : '';
            print '<option value="'.$sigla.'"'.$sel.'>'.$sigla.'</option>';
        }
        print '</select>';
        print '</div>';

        // UF Destino
        print '<div class="rf-filter-group">';
        print '<label class="rf-filter-label">UF Destino</label>';
        print '<select name="search_uf_dest" class="rf-filter-select">';
        print '<option value="">Todas</option>';
        foreach ($estados_brasil as $sigla => $nome) {
            $sel = ($search_uf_dest == $sigla) ? ' selected' : '';
            print '<option value="'.$sigla.'"'.$sel.'>'.$sigla.'</option>';
        }
        print '</select>';
        print '</div>';

        // CFOP
        print '<div class="rf-filter-group">';
        print '<label class="rf-filter-label">CFOP</label>';
        print '<input type="text" name="search_cfop" value="'.dol_escape_htmltag($search_cfop).'" class="rf-filter-input" placeholder="Ex: 5102">';
        print '</div>';

        // NCM
        print '<div class="rf-filter-group">';
        print '<label class="rf-filter-label">NCM</label>';
        print '<input type="text" name="search_ncm" value="'.dol_escape_htmltag($search_ncm).'" class="rf-filter-input" placeholder="8 dígitos">';
        print '</div>';
        
        // Descrição com input e botões ao lado (mesma linha)
        print '<div class="rf-filter-group full-width" style="grid-column: 1 / -1;">';
        print '<label class="rf-filter-label">Descrição</label>';
        print '<div class="rf-filter-inline">';
        print '<input type="text" name="search_label" value="'.dol_escape_htmltag($search_label).'" class="rf-filter-input" placeholder="Buscar por descrição...">';
        print '<div class="rf-filter-actions">';
        print '<button type="submit" class="rf-search-btn-new" aria-label="Buscar regras fiscais">';
        print '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zM9.5 14C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>';
        print 'Buscar';
        print '</button>';
        print '<button type="button" class="rf-clear-btn" onclick="clearFilters()">Limpar</button>';
        print '</div>'; // .rf-filter-actions
        print '</div>'; // .rf-filter-inline
        print '</div>'; // .rf-filter-group.full-width
        
        print '</div>'; // fim rf-filter-row
        print '</form>';
        print '</div>'; // fim rf-filter-bar
        
        // ========== LEGENDA DE STATUS + BOTÃO ADICIONAR ==========
        print '<div class="rf-status-legend">';
        
        // Lado Esquerdo: Legenda
        print '<div class="rf-status-legend-left">';
        print '<strong>Legenda:</strong>';
        print '<div class="rf-status-item"><span class="rf-status-circle rf-status-active"></span> <span>Ativa</span></div>';
        print '<div class="rf-status-item"><span class="rf-status-circle rf-status-inactive"></span> <span>Inativa</span></div>';
        print '</div>';
        
        // Lado Direito: Botão Adicionar (PADRÃO DOLIBARR)
        if ($action != 'create' && $action != 'edit') {
            print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?action=add&token=' . $token . '">Adicionar Nova Regra</a>';
        }
        
        print '</div>'; // Fim rf-status-legend
        
        // ========== TABELA SEM FILTROS ==========
        print '<div class="div-table-responsive">';
        print '<table class="tagtable liste">';
        print '<tr class="liste_titre">';
        print_liste_field_titre('Status', $_SERVER["PHP_SELF"], "active", "", $param, 'align="center"', $sortfield, $sortorder);
        print_liste_field_titre('Descrição', $_SERVER["PHP_SELF"], "label", "", $param, '', $sortfield, $sortorder);
        print_liste_field_titre('Origem', $_SERVER["PHP_SELF"], "uf_origin", "", $param, 'align="center"', $sortfield, $sortorder);
        print_liste_field_titre('Destino', $_SERVER["PHP_SELF"], "uf_dest", "", $param, 'align="center"', $sortfield, $sortorder);
        print_liste_field_titre('NCM', $_SERVER["PHP_SELF"], "ncm", "", $param, 'align="center"', $sortfield, $sortorder);
        print_liste_field_titre('CFOP', $_SERVER["PHP_SELF"], "cfop", "", $param, 'align="center"', $sortfield, $sortorder);
        print_liste_field_titre('Vigência', '', '', '', '', 'align="center"');
        print_liste_field_titre('Ações', '', '', '', '', 'align="center"');
        print "</tr>\n";
        
        // ========== LINHAS DE DADOS (SEM MUDANÇA) ==========
        if ($num > 0) {
            $i = 0;
            while ($i < $num) {
                $obj = $db->fetch_object($result);
                
                $statusClass = $obj->active ? 'rf-row-active' : 'rf-row-inactive';
                $statusCircle = $obj->active 
                    ? '<span class="rf-status-circle rf-status-active"></span>' 
                    : '<span class="rf-status-circle rf-status-inactive"></span>';
                
                print '<tr class="oddeven '.$statusClass.'">';
                
                // Status com link toggle
                print '<td class="center">';
                print '<a href="' . $_SERVER['PHP_SELF'] . '?action=toggle&id=' . $obj->rowid . '&token=' . $token . $param . '&page=' . $page . '" title="Clique para alternar status">';
                print $statusCircle;
                print '</a>';
                print '</td>';
                
                // Descrição
                print '<td>' . dol_escape_htmltag($obj->label) . '</td>';
                
                // UF Origem
                print '<td class="center">' . dol_escape_htmltag($obj->uf_origin) . '</td>';
                
                // UF Destino
                print '<td class="center">' . dol_escape_htmltag($obj->uf_dest) . '</td>';
                
                // NCM
                print '<td class="center">';
                if ($obj->ncm) {
                    print dol_escape_htmltag($obj->ncm);
                } else {
                    print '<span class="opacitymedium">Todos</span>';
                }
                print '</td>';
                
                // CFOP
                print '<td class="center">' . dol_escape_htmltag($obj->cfop) . '</td>';
                
                // Vigência
                print '<td class="center nowrap">';
                if ($obj->date_start || $obj->date_end) {
                    if ($obj->date_start) print dol_print_date($db->jdate($obj->date_start), 'day');
                    if ($obj->date_start && $obj->date_end) print ' a ';
                    if ($obj->date_end) print dol_print_date($db->jdate($obj->date_end), 'day');
                } else {
                    print '<span class="opacitymedium">Sempre</span>';
                }
                print '</td>';
                
                // Ações
                print '<td class="center nowraponall">';
                print '<div class="rf-actions-row">';
                // Botão Visualizar (leva para a nova página)
                print '<a class="butAction" href="' . DOL_URL_ROOT . '/custom/nfe/visualizar_regra.php?id=' . $obj->rowid . '">Visualizar</a>';
                print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?action=edit&id=' . $obj->rowid . '&token=' . $token . $param . '">Editar</a>';
                // Link atualizado para abrir modal (action=delete) sem alert JS
                print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?action=delete&id=' . $obj->rowid . '&token=' . $token . $param . '&page=' . $page . '">Excluir</a>';
                print '</div>';
                print '</td>';
                
                print '</tr>'."\n";
                $i++;
            }
        } else {
            print '<tr><td colspan="8" class="opacitymedium center">Nenhuma regra fiscal encontrada</td></tr>';
        }
        
        print '</table>'."\n";
        print '</div>';
        
        // ========== PAGINAÇÃO ==========
        // Função corrigida para construir URL - SEMPRE inclui limit
        if (!function_exists('rf_buildURL')) {
            function rf_buildURL($pageNum, $limitVal, $filtersArray) {
                $params = [];
                
                // SEMPRE adiciona limit primeiro (crítico para persistência)
                $params['limit'] = (int)$limitVal;
                
                // Adiciona page
                $params['page'] = (int)$pageNum;
                
                // Adiciona filtros não vazios
                foreach ($filtersArray as $key => $value) {
                    if ($key === 'limit' || $key === 'page') continue; // já adicionados
                    if ($value !== null && $value !== '' && strlen((string)$value) > 0) {
                        $params[$key] = $value;
                    }
                }
                
                return $_SERVER["PHP_SELF"] . '?' . http_build_query($params);
            }
        }
        
        $filters = [
            'search_status_list' => $search_status_list,
            'search_label' => $search_label,
            'search_uf_origin' => $search_uf_origin,
            'search_uf_dest' => $search_uf_dest,
            'search_cfop' => $search_cfop,
            'search_ncm' => $search_ncm,
            'sortfield' => $sortfield,
            'sortorder' => $sortorder
            // NÃO incluir 'limit' aqui - já é passado como segundo parâmetro
        ];
        
        $totalPages = ($total_rows > 0) ? (int)ceil($total_rows / $limit) : 1;
        $currentPage = $page + 1;
        $startRecord = ($total_rows > 0) ? ($offset + 1) : 0;
        $endRecord = min($offset + $limit, $total_rows);
        
        print '<div class="rf-pagination-wrapper">';
        print '<div class="rf-pagination-info">';
        print 'Mostrando <strong>'.$startRecord.'</strong> a <strong>'.$endRecord.'</strong> de <strong>'.$total_rows.'</strong> registros';
        print '</div>';
        
        print '<div class="rf-pagination-controls">';
        print '<div class="rf-page-size-selector">';
        print '<label>Por página:</label>';
        print '<select onchange="window.location.href=this.value">';
        foreach ($limitOptions as $opt) {
            $selected = ($opt == $limit) ? ' selected' : '';
            $url = rf_buildURL(0, $opt, $filters);
            print '<option value="'.$url.'"'.$selected.'>'.$opt.'</option>';
        }
        print '</select>';
        print '</div>';
        
        print '<div class="rf-page-nav">';
        $prevPage = max(0, $page - 1);
        $prevDisabled = ($page == 0) ? 'disabled' : '';
        $prevUrl = rf_buildURL($prevPage, $limit, $filters);
        print '<a href="'.$prevUrl.'" class="rf-page-btn '.$prevDisabled.'">‹ Anterior</a>';
        
        $startPage = max(0, $page - 2);
        $endPage = min($totalPages - 1, $page + 2);
        
        if ($startPage > 0) {
            $firstUrl = rf_buildURL(0, $limit, $filters);
            print '<a href="'.$firstUrl.'" class="rf-page-btn">1</a>';
            if ($startPage > 1) print '<span class="rf-page-btn disabled">...</span>';
        }
        
        for ($p = $startPage; $p <= $endPage; $p++) {
            $pageUrl = rf_buildURL($p, $limit, $filters);
            $activeClass = ($p == $page) ? 'active' : '';
            print '<a href="'.$pageUrl.'" class="rf-page-btn '.$activeClass.'">'.($p + 1).'</a>';
        }
        
        if ($endPage < $totalPages - 1) {
            if ($endPage < $totalPages - 2) print '<span class="rf-page-btn disabled">...</span>';
            $lastUrl = rf_buildURL($totalPages - 1, $limit, $filters);
            print '<a href="'.$lastUrl.'" class="rf-page-btn">'.$totalPages.'</a>';
        }
        
        $nextPage = min($totalPages - 1, $page + 1);
        $nextDisabled = ($page >= $totalPages - 1) ? 'disabled' : '';
        $nextUrl = rf_buildURL($nextPage, $limit, $filters);
        print '<a href="'.$nextUrl.'" class="rf-page-btn '.$nextDisabled.'">Próximo ›</a>';
        print '</div>';
        
        print '</div>';
        print '</div>';

    }
}

// ========== JAVASCRIPT PARA FILTROS ==========
print '<script>
// ========== ESTILOS PARA ABAS (INJETADO UMA VEZ) ==========
(function(){
  if(document.getElementById("rfTabStyles")) return;
  var style = document.createElement("style");
  style.id = "rfTabStyles";
  style.textContent = `
    .rf-tab-active, .rf-tab-inactive {
      display: inline-block;
      padding: 12px 24px;
      font-size: 15px;
      font-weight: 600;
      border-bottom: 3px solid transparent;
      transition: all 0.2s ease;
      cursor: pointer;
    }

    .rf-tab-active {
      color: #3b82f6;
      border-bottom-color: #3b82f6;
      background: linear-gradient(to bottom, rgba(59,130,246,0.05), transparent);
    }
    .rf-tab-inactive {
      color: #64748b;
      border-bottom-color: transparent;
    }
    .rf-tab-inactive:hover {
      color: #334155;
      background: rgba(0,0,0,0.02);
    }
  `;
 
  document.head.appendChild(style);
})();

function toggleStatusDropdown(e) {
    e.stopPropagation();
    var dd = document.getElementById("rfStatusDropdown");
    if (dd) dd.classList.toggle("show");
}

function updateStatusHidden() {
    var dd = document.getElementById("rfStatusDropdown");
    var hidden = document.getElementById("search_status_list");
    var btnLabel = document.getElementById("rfStatusBtnLabel");
    
    
    if (!dd || !hidden) return;
    
    var checkboxes = dd.querySelectorAll("input[type=checkbox]:checked");
    var values = [];
    checkboxes.forEach(function(cb) {
        values.push(cb.value);
    });
    
    hidden.value = values.join(",");
    
    // Atualiza label do botão
    if (values.length === 0) {
       
        btnLabel.textContent = "Todos";
    } else {
        btnLabel.textContent = values.length + " selecionado(s)";
    }
}

// Fecha dropdown ao clicar fora
document.addEventListener("click", function(e) {
    var dd = document.getElementById("rfStatusDropdown");
    var btn = document.querySelector(".rf-status-btn");
    if (dd && dd.classList.contains("show")) {
        if (!dd.contains(e.target) && !btn.contains(e.target)) {
            dd.classList.remove("show");
        }
    }
});

function clearFilters() {
    var form = document.getElementById("rfListFilterForm");
    if (!form) return;
    // limpa inputs de texto/selects e hidden de status
    form.search_label.value = "";
    form.search_cfop.value = "";
    form.search_ncm.value = "";
    if (form.search_uf_origin) form.search_uf_origin.value = "";
    if (form.search_uf_dest) form.search_uf_dest.value = "";
    var hidden = document.getElementById("search_status_list");
    if (hidden) hidden.value = "";
    // limpa checkboxes do dropdown
    var dd = document.getElementById("rfStatusDropdown");
    if (dd) {
        dd.querySelectorAll("input[type=checkbox]").forEach(function(cb){ cb.checked = false; });
    }
    // atualiza label do botão status
    var lbl = document.getElementById("rfStatusBtnLabel");
    if (lbl) lbl.textContent = "Todos";
}
</script>';

// Rodapé
llxFooter();
$db->close();
?>
