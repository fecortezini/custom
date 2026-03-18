<?php

$res = 0;

// Ajuste o caminho conforme a estrutura de pastas (custom dentro de htdocs)
require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
/** @var DoliDB $db */

$langs->load("companies");
$langs->load("bills");
$langs->load("nfse@nfse");

$action = GETPOST('action', 'alpha');
$confirm = GETPOST('confirm', 'alpha');
$id = GETPOST('id', 'int');
$cancel = GETPOST('cancel', 'alpha');

// Parâmetros de lista
$search_codigo = GETPOST('search_codigo', 'alpha');
$search_descricao = GETPOST('search_descricao', 'alpha');
$sortfield = GETPOST('sortfield', 'alpha');
$sortorder = GETPOST('sortorder', 'alpha');
$page = GETPOST('page', 'int');
$limit = GETPOST('limit', 'int') ? GETPOST('limit', 'int') : $conf->liste_limit;

// Inicialização de padrão
if (empty($page) || $page == -1) { $page = 0; }
if (empty($limit)) { $limit = $conf->liste_limit; }
if (empty($sortfield)) { $sortfield = 'codigo'; }
if (empty($sortorder)) { $sortorder = 'ASC'; }

// Botões de busca
if (GETPOST('button_removefilter_x', 'alpha') || GETPOST('button_removefilter.x', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
    $search_codigo = '';
    $search_descricao = '';
}

$codigo = GETPOST('codigo', 'alpha');
$descricao = GETPOST('descricao', 'alpha');
$aliquota_iss = GETPOST('aliquota_iss', 'alpha');

// Verificação de segurança

$hookmanager->initHooks(array('nfseserviceslist'));

/*
 * Actions
 */

if ($cancel) {
    $action = '';
}

if ($action == 'add') {
    $error = 0;
    if (empty($codigo)) {
        setEventMessages('Código é obrigatório', null, 'errors');
        $error++;
    }
    if (empty($descricao)) {
        setEventMessages('Descrição é obrigatória', null, 'errors');
        $error++;
    }

    if (!$error) {
        $aliquota_iss = price2num($aliquota_iss);
        if($aliquota_iss<=5){
            $sql = "INSERT INTO ".MAIN_DB_PREFIX."nfse_codigo_servico (codigo, descricao, aliquota_iss) VALUES ('".$db->escape($codigo)."', '".$db->escape($descricao)."', ".((float)$aliquota_iss).")";

            $resql = $db->query($sql);
            if ($resql) {
                setEventMessages("Serviço adicionado com sucesso", null, 'mesgs');
                $action = '';
                $codigo = ''; $descricao = ''; $aliquota_iss = '';
            } else {
                if ($db->errno() == 1062) setEventMessages("Erro: Código de serviço já existe.", null, 'errors');
                else setEventMessages($db->lasterror(), null, 'errors');
            }
        }else{
            setEventMessages('Nao é possivel informar alíquota maior que 5.00%', null, 'errors');
        }
    }
}

if ($action == 'update') {
    $error = 0;
    if (empty($codigo)) {
        setEventMessages('Código é obrigatório', null, 'errors');
        $error++;
    }
    if (!$error) {
        $aliquota_iss = price2num($aliquota_iss);
        if($aliquota_iss>5){
            setEventMessages('Nao é possivel informar alíquota maior que 5.00%', null, 'errors');
        }else{
            $sql = "UPDATE ".MAIN_DB_PREFIX."nfse_codigo_servico SET codigo='".$db->escape($codigo)."', descricao='".$db->escape($descricao)."', aliquota_iss=".((float)$aliquota_iss)." WHERE rowid=".$id;
            if ($db->query($sql)) {
                setEventMessages("Serviço atualizado com sucesso", null, 'mesgs');
                $action = '';
            } else {
                setEventMessages($db->lasterror(), null, 'errors');
            }
        }
    }
}

if ($action == 'delete') {
    // Não faz nada aqui, apenas mostra o diálogo depois
}

if ($action == 'confirm_delete' && $confirm == 'yes') {
    $sql_delete = "DELETE FROM ".MAIN_DB_PREFIX."nfse_codigo_servico WHERE rowid=".(int)$id;
    $result = $db->query($sql_delete);
    if ($result) {
        setEventMessages("Serviço excluído com sucesso", null, 'mesgs');
        header('Location: '.$_SERVER["PHP_SELF"].'?page='.$page);
        exit;
    } else {
        setEventMessages("Erro ao excluir serviço", null, 'errors');
    }
    $action = '';
}

// Inicializar variáveis do formulário
$form_codigo = '';
$form_aliquota = '';
$form_descricao = '';

// Carregar dados do registro quando a ação for edit
if ($action == 'edit' && $id > 0) {
    $sql_edit = "SELECT codigo, descricao, aliquota_iss FROM ".MAIN_DB_PREFIX."nfse_codigo_servico WHERE rowid=".(int)$id;
    $resql_edit = $db->query($sql_edit);
    if ($resql_edit) {
        $obj_edit = $db->fetch_object($resql_edit);
        if ($obj_edit) {
            $form_codigo = $obj_edit->codigo;
            $form_aliquota = $obj_edit->aliquota_iss;
            $form_descricao = $obj_edit->descricao;
        }
    }
}

/*
 * View
 */
$form = new Form($db);

llxHeader('', 'Serviços e Alíquotas NFS-e');

// Construção da Query
$sql = "SELECT rowid, codigo, descricao, aliquota_iss";
$sql .= " FROM ".MAIN_DB_PREFIX."nfse_codigo_servico";
$sql .= " WHERE 1=1";
if ($search_codigo) $sql .= natural_search('codigo', $search_codigo);
if ($search_descricao) $sql .= natural_search('descricao', $search_descricao);
$sql .= $db->order($sortfield, $sortorder);

// Contagem total
$nbtotalofrecords = 0;
if (empty($conf->global->MAIN_DISABLE_FULL_SCANLIST)) {
    $resql = $db->query($sql);
    $nbtotalofrecords = $db->num_rows($resql);
    if (($page * $limit) > $nbtotalofrecords) {
        $page = 0;
    }
}

$sql .= $db->plimit($limit + 1, $page * $limit);
$resql = $db->query($sql);

// Parâmetros para links
$param = '';
if (!empty($search_codigo)) $param .= '&search_codigo=' . urlencode($search_codigo);
if (!empty($search_descricao)) $param .= '&search_descricao=' . urlencode($search_descricao);

// Calcular total de páginas
$total_pages = ceil($nbtotalofrecords / $limit);

// --- CSS CUSTOMIZADO MODERNO ---
print '
<style>
    @import url("https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap");
    
    * {
        font-family: "Inter", -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }

    /* ============ MODAL DE FORMULÁRIO ============ */
    .ui-dialog {
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15) !important;
        border-radius: 8px !important;
        border: 1px solid #d1d5db !important;
        overflow: hidden !important;
    }
    
    .ui-dialog-titlebar {
        background: #4f46e5 !important;
        color: white !important;
        border: none !important;
        padding: 18px 24px !important;
        font-weight: 600 !important;
        font-size: 18px !important;
        display: flex !important;
        align-items: center !important;
        gap: 10px !important;
    }
    
    .ui-dialog-title {
        display: flex !important;
        align-items: center !important;
        gap: 10px !important;
    }
    
    .ui-dialog-title::before {
        font-size: 20px;
        line-height: 1;
    }
    
    .ui-dialog-titlebar-close {
        background: rgba(255, 255, 255, 0.2) !important;
        border: none !important;
        border-radius: 4px !important;
        width: 32px !important;
        height: 32px !important;
        right: 18px !important;
        top: 18px !important;
    }
    
    .ui-dialog-titlebar-close:hover {
        background: rgba(255, 255, 255, 0.3) !important;
    }
    
    .ui-dialog-titlebar-close .ui-icon {
        background: none !important;
        text-indent: 0 !important;
        color: white;
    }
    
    .ui-dialog-titlebar-close .ui-icon::before {
        content: "×";
        position: absolute;
        top: 50%;
        left: 50%;
        transform: translate(-50%, -50%);
        font-size: 24px;
        line-height: 1;
    }
    
    #dialog-form {
        padding: 30px 24px !important;
        background: #f9fafb;
    }
    
    /* Grid Layout para Formulário */
    .form-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 18px;
        margin-bottom: 20px;
    }
    
    .form-group-full {
        grid-column: 1 / -1;
    }
    
    .form-group {
        display: flex;
        flex-direction: column;
    }
    
    #dialog-form label {
        display: block;
        margin-bottom: 8px;
        color: #374151;
        font-weight: 600;
        font-size: 13px;
    }
    
    #dialog-form label::after {
        content: " *";
        color: #dc2626;
        font-weight: 700;
    }
    
    #dialog-form label.optional::after {
        content: " (opcional)";
        color: #6b7280;
        font-weight: 400;
        font-size: 12px;
    }
    
    #dialog-form input[type="text"],
    #dialog-form input[type="number"] {
        width: 100% !important;
        padding: 10px 14px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 14px;
        background: white;
        box-sizing: border-box;
    }
    
    #dialog-form input[type="text"]:focus,
    #dialog-form input[type="number"]:focus {
        outline: none;
        border-color: #4f46e5;
        box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
    }
    
    .form-footer {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        margin-top: 24px;
        padding-top: 20px;
        border-top: 1px solid #e5e7eb;
    }
    
    .btn-primary {
        background: #4f46e5;
        color: white;
        padding: 10px 24px;
        border: none;
        border-radius: 6px;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
    }
    
    .btn-primary:hover {
        background: #4338ca;
    }
    
    .btn-secondary {
        background: white;
        color: #374151;
        padding: 10px 24px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
    }
    
    .btn-secondary:hover {
        background: #f3f4f6;
    }

    /* ============ SISTEMA DE PESQUISA ============ */
    .search-container {
        background: white;
        border-radius: 8px;
        padding: 20px;
        margin-bottom: 20px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        border: 1px solid #e5e7eb;
    }
    
    .search-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 16px;
    }
    
    .search-title {
        font-size: 16px;
        font-weight: 600;
        color: #1f2937;
    }
    
    .search-fields {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 14px;
        margin-bottom: 16px;
    }
    
    .search-field {
        display: flex;
        flex-direction: column;
    }
    
    .search-field label {
        font-size: 13px;
        font-weight: 600;
        color: #374151;
        margin-bottom: 6px;
    }
    
    .search-field input {
        padding: 10px 12px;
        border: 1px solid #d1d5db;
        border-radius: 6px;
        font-size: 14px;
        background: white;
    }
    
    .search-field input:focus {
        outline: none;
        border-color: #4f46e5;
        box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1);
    }
    
    .search-actions {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
    }
    
    .btn-search {
        padding: 10px 20px;
        border-radius: 6px;
        font-weight: 600;
        font-size: 14px;
        cursor: pointer;
        border: none;
    }
    
    .btn-search-primary {
        background: #4f46e5;
        color: white;
    }
    
    .btn-search-primary:hover {
        background: #4338ca;
    }
    
    .btn-search-secondary {
        background: white;
        color: #374151;
        border: 1px solid #d1d5db;
    }
    
    .btn-search-secondary:hover {
        background: #f3f4f6;
    }
    
    /* Chips de Filtros Ativos */
    .active-filters {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-top: 12px;
        padding-top: 12px;
        border-top: 1px dashed #d5d5db;
    }
    
    .filter-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: #eef2ff;
        color: #4f46e5;
        padding: 6px 12px;
        border-radius: 16px;
        font-size: 13px;
        font-weight: 500;
        border: 1px solid #c7d2fe;
    }
    
    .filter-chip .remove {
        cursor: pointer;
        font-weight: 700;
        font-size: 16px;
        margin-left: 4px;
    }
    
    .filter-chip .remove:hover {
        color: #dc2626;
    }

    /* ============ PAGINAÇÃO ============ */
    .pagination-wrapper {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin: 20px 0;
        padding: 16px 20px;
        background: white;
        border-radius: 8px;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        border: 1px solid #e5e7eb;
    }
    
    .pagination-info {
        color: #6b7280;
        font-size: 14px;
        font-weight: 500;
    }
    
    .pagination-info strong {
        color: #1f2937;
        font-weight: 600;
    }
    
    .pagination-controls {
        display: flex;
        gap: 6px;
        align-items: center;
    }
    
    .pagination-button {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 38px;
        height: 38px;
        padding: 0 12px;
        border: 1px solid #d1d5db;
        background: white;
        color: #374151;
        text-decoration: none !important; /* remover sublinhado */
        border-radius: 6px;
        font-weight: 500;
        font-size: 14px;
        cursor: pointer;
    }
    
    .pagination-button:hover {
        background: #f3f4f6;
        border-color: #9ca3af;
        text-decoration: none !important; /* garantir sem sublinhado no hover */
    }
    
    .pagination-button.active {
        background: #4f46e5;
        color: white;
        border-color: #4f46e5;
        text-decoration: none !important;
    }
    
    .pagination-button.active:hover {
        background: #4338ca;
        border-color: #4338ca;
        text-decoration: none !important;
    }
    
    .pagination-button.disabled {
        opacity: 0.5;
        cursor: not-allowed;
        pointer-events: none;
    }
    
    .pagination-button.prev::before {
        content: "←";
        margin-right: 4px;
    }
    
    .pagination-button.next::after {
        content: "→";
        margin-left: 4px;
    }
    
    .pagination-dots {
        color: #9ca3af;
        font-weight: 600;
        padding: 0 6px;
    }

    /* Botão Novo Serviço */

    
    .btn-new-modern:hover {
        background: #4338ca;
        color: white;
    }
    
    .btn-new-modern::before {
        content: "+";
        font-size: 18px;
        font-weight: 700;
    }

    /* Melhorias gerais */
    .div-table-responsive {
        border-radius: 8px;
        overflow: hidden;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        background: white;
        margin-bottom: 20px;
        border: 1px solid #e5e7eb;
    }
    
    /* Estilização do diálogo de confirmação */
    .ui-dialog.ui-corner-all {
        box-shadow: 0 4px 20px rgba(0, 0, 0, 0.15) !important;
        border-radius: 8px !important;
        border: 1px solid #d5d5db !important;
    }
    
    .ui-dialog .ui-dialog-content {
        padding: 20px !important;
        color: #374151;
        font-size: 14px;
        line-height: 1.6;
    }
    
    .ui-dialog .ui-dialog-buttonpane {
        border-top: 1px solid #e5e7eb !important;
        background: #f9fafb !important;
        padding: 15px 20px !important;
    }
    
    .ui-dialog .ui-dialog-buttonpane button {
        background: #4f46e5 !important;
        color: white !important;
        border: none !important;
        padding: 8px 20px !important;
        border-radius: 6px !important;
        font-weight: 600 !important;
        cursor: pointer !important;
        margin-left: 8px !important;
    }
    
    .ui-dialog .ui-dialog-buttonpane button:hover {
        background: #4338ca !important;
    }
    
    .ui-dialog .ui-dialog-buttonpane button:first-child {
        background: white !important;
        color: #374151 !important;
        border: 1px solid #d1d5db !important;
    }
    
    .ui-dialog .ui-dialog-buttonpane button:first-child:hover {
        background: #f3f4f6 !important;
    }
    
    /* Títulos específicos dos modais */
    .modal-title-novo::before {
        content: "✨";
    }
    
    .modal-title-editar::before {
        content: "✏️";
    }
    
    .modal-title-excluir::before {
        content: "⚠️";
    }

    /* Cabeçalho interno dos modais */
    .modal-header-compact {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 12px 0 16px 0;
        border-bottom: 1px solid #e5e7eb;
        margin-bottom: 16px;
    }
    .modal-header-compact .mh-icon {
        width: 36px;
        height: 36px;
        border-radius: 8px;
        background: #eef2ff;
        color: #4f46e5;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        font-weight: 700;
    }
    .modal-header-compact .mh-text h2 {
        margin: 0;
        font-size: 16px;
        color: #111827;
        font-weight: 700;
    }
    .modal-header-compact .mh-text .mh-sub {
        margin-top: 4px;
        font-size: 12px;
        color: #6b7280;
        font-weight: 500;
    }

    /* Slide panel (adicionar/editar) */
    .panel-backdrop {
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.45);
        visibility: hidden;
        opacity: 0;
        transition: opacity 0.18s ease, visibility 0.18s;
        z-index: 9998;
    }
    .panel-backdrop.open { visibility: visible; opacity: 1; }

    #slide-panel {
        position: fixed;
        top: 0;
        right: -520px;
        width: 480px;
        height: 100%;
        background: #ffffff;
        box-shadow: -8px 0 30px rgba(0,0,0,0.12);
        z-index: 9999;
        transition: right 0.22s ease;
        padding: 22px;
        overflow-y: auto;
    }
    #slide-panel.open { right: 0; }

    #slide-panel .panel-header {
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        margin-bottom:14px;
    }
    #slide-panel .panel-title {
        display:flex;
        align-items:center;
        gap:12px;
    }
    #slide-panel .panel-icon {
        width:40px;height:40px;border-radius:8px;
        background:#eef2ff;color:#4f46e5;display:flex;
        justify-content:center;align-items:center;font-weight:700;
    }
    #slide-panel .panel-title h3 { margin:0;font-size:16px;color:#111827;font-weight:700; }
    #slide-panel .panel-sub { margin-top:4px;font-size:12px;color:#6b7280; }

    #slide-panel .panel-close {
        background:transparent;border:none;color:#374151;font-size:20px;cursor:pointer;
    }

    #slide-panel .panel-form .form-row { margin-bottom:12px; }
    #slide-panel .panel-form label { display:block;font-weight:600;color:#374151;margin-bottom:6px;font-size:13px; }
    #slide-panel .panel-form input[type="text"], #slide-panel .panel-form input[type="number"], #slide-panel .panel-form textarea {
        width:100%;padding:10px 12px;border:1px solid #d1d5db;border-radius:8px;font-size:14px;
        box-sizing:border-box;background:white;
    }
    #slide-panel .panel-actions { display:flex;gap:10px;justify-content:flex-end;margin-top:18px;border-top:1px solid #eef2ff;padding-top:14px; }
    #slide-panel .btn-primary, #slide-panel .btn-secondary { padding:10px 18px;border-radius:8px;font-weight:600;cursor:pointer;border:0; }
    #slide-panel .btn-primary { background:#4f46e5;color:white;border:1px solid #4f46e5; }
    #slide-panel .btn-secondary { background:white;color:#374151;border:1px solid #d5d5db; }

    /* ajusta overflow do conteúdo principal ao abrir panel */
    body.panel-open { overflow: hidden; }

    /* Modal central */
.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,0.5);
  display: none;
  align-items: center;
  justify-content: center;
  z-index: 9999;
}
.modal-backdrop.open { display: flex; }

.modal-card {
  width: 560px;
  max-width: calc(100% - 40px);
  background: #fff;
  border-radius: 12px;
  box-shadow: 0 20px 50px rgba(0,0,0,0.15);
  overflow: hidden;
  font-family: inherit;
}

.modal-header {
  display:flex;
  align-items:center;
  justify-content:space-between;
  padding:18px 20px;
  background:#f8fafc;
  border-bottom:1px solid #eef2ff;
}
.modal-title { display:flex; align-items:center; gap:12px; }
.modal-icon {
  width:44px;height:44px;border-radius:10px;background:#eef2ff;color:#4f46e5;display:flex;
  align-items:center;justify-content:center;font-weight:700;font-size:18px;
}
.modal-title h3 { margin:0;font-size:16px;color:#111827;font-weight:700; }
.modal-sub { margin-top:6px;font-size:13px;color:#6b7280; }

/* Corpo do formulário */
.modal-body { padding:20px; }
.form-grid-modal { display:grid; grid-template-columns: 1fr 1fr; gap:12px; }
.form-row-full { grid-column:1 / -1; }
label.field-label { display:block; font-weight:600; color:#374151; margin-bottom:6px; font-size:13px; }
input.field-input, textarea.field-input {
  width:100%; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px;
  box-sizing:border-box; background:white;
}

/* Actions */
.modal-actions { display:flex; gap:10px; justify-content:flex-end; padding:18px 20px; border-top:1px solid #eef2ff; }
.btn-primary { background:#4f46e5; color:white; padding:10px 18px; border-radius:8px; border:0; font-weight:600; cursor:pointer; }
.btn-secondary { background:white; color:#374151; padding:10px 18px; border-radius:8px; border:1px solid #d5d5db; cursor:pointer; }

/* pequenas melhorias responsivas */
@media (max-width:600px){ .form-grid-modal{ grid-template-columns: 1fr; } .modal-card{ width:95%; } }
</style>
';

// Botão Novo Serviço no Topo
$new_button = '<button type="button" id="btn-new-service" class="btn-search btn-search-primary">NOVO SERVIÇO</button>';
print load_fiche_titre('<span class="fas fa-tools valignmiddle widthpictotitle pictotitle" style=""></span> Serviços e Alíquotas NFS-e', $new_button, 'nfse@nfse');

// Diálogo de confirmação de exclusão (substituído por modal consistente)
if ($action == 'delete') {
    // obtém dados do serviço para mostrar no modal (se disponível)
    $del_codigo = '';
    $del_descricao = '';
    $resd = $db->query("SELECT codigo, descricao FROM ".MAIN_DB_PREFIX."nfse_codigo_servico WHERE rowid=".(int)$id);
    if ($resd && ($objd = $db->fetch_object($resd))) {
        $del_codigo = $objd->codigo;
        $del_descricao = $objd->descricao;
    }

    print '
    <!-- Modal de confirmação de exclusão -->
    <div id="modal-delete-backdrop" class="modal-backdrop open">
      <div class="modal-card" role="dialog" aria-modal="true" aria-label="Confirmação de exclusão">
        <form id="modal-delete-form" method="POST" action="'.$_SERVER["PHP_SELF"].'">
          <input type="hidden" name="token" value="'.newToken().'">
          <input type="hidden" name="action" value="confirm_delete">
          <input type="hidden" name="confirm" value="yes">
          <input type="hidden" name="id" value="'.(int)$id.'">
          <input type="hidden" name="page" value="'.$page.'">
          <div class="modal-header">
            <div class="modal-title">
              <div class="modal-icon">⚠️</div>
              <div>
                <h3>Confirmar Exclusão</h3>
                <div class="modal-sub">Tem certeza que deseja excluir este serviço? Esta ação não pode ser desfeita.</div>
              </div>
            </div>
            <button type="button" id="modal-delete-close" class="btn-secondary" aria-label="Fechar">Fechar</button>
          </div>

          <div class="modal-body">
            <div style="font-size:14px;color:#374151;">
              '.($del_codigo ? 'Código: <strong>'.htmlspecialchars($del_codigo, ENT_QUOTES).'</strong><br>' : '').'
              '.($del_descricao ? 'Descrição: <strong>'.htmlspecialchars($del_descricao, ENT_QUOTES).'</strong>' : '').'
            </div>
          </div>

          <div class="modal-actions">
            <button type="button" class="btn-secondary" id="modal-delete-cancel">Cancelar</button>
            <button type="submit" class="btn-primary">Excluir</button>
          </div>
        </form>
      </div>
    </div>
    ';
}

// Substituir antigo bloco do slide-panel pelo novo modal HTML (coloque antes do formulário de listagem)
print '
<!-- Modal central para Adicionar / Editar -->
<div id="modal-backdrop" class="modal-backdrop'.($action == "edit" ? " open" : "").'">
  <div class="modal-card" role="dialog" aria-modal="true" aria-label="Formulário de Serviço">
    <form id="modal-form" method="POST" action="'.$_SERVER["PHP_SELF"].'">
      <input type="hidden" name="token" value="'.newToken().'">
      <input type="hidden" name="action" value="'.($action == "edit" ? "update" : "add").'">
      '.($action == "edit" ? '<input type="hidden" name="id" value="'.(int)$id.'">' : '').'
      <div class="modal-header">
        <div class="modal-title">
          <div class="modal-icon">'.($action == "edit" ? "✎" : "+").'</div>
          <div>
            <h3>'.($action == "edit" ? "Editar Serviço" : "Novo Serviço").'</h3>
            <div class="modal-sub">'.($action == "edit" ? "Altere os dados e clique em Atualizar." : "Preencha os dados e clique em Cadastrar.").'</div>
          </div>
        </div>
        <button type="button" id="modal-close" class="btn-secondary" aria-label="Fechar">Fechar</button>
      </div>

      <div class="modal-body">
        <div class="form-grid-modal">
          <div>
            <label class="field-label" for="m-codigo">Código</label>
            <input id="m-codigo" class="field-input" type="text" name="codigo" value="'.htmlspecialchars($form_codigo, ENT_QUOTES).'" required>
          </div>

          <div>
            <label class="field-label" for="m-aliquota">Alíquota ISS (%)</label>
            <input id="m-aliquota" class="field-input" type="number" step="0.01" name="aliquota_iss" value="'.htmlspecialchars($form_aliquota, ENT_QUOTES).'">
          </div>

          <div class="form-row-full">
            <label class="field-label" for="m-descricao">Descrição</label>
            <input id="m-descricao" class="field-input" type="text" name="descricao" value="'.htmlspecialchars($form_descricao, ENT_QUOTES).'" required>
          </div>
        </div>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-secondary" id="modal-cancel">Cancelar</button>
        <button type="submit" class="btn-primary">'.($action == "edit" ? "Atualizar" : "Cadastrar").'</button>
      </div>
    </form>
  </div>
</div>
';

// --- SCRIPT PARA CONTROLAR O MODAL ---
print '
<script type="text/javascript">
jQuery(function($){
  function openModal(mode, data){
    // mode: "add" or "update"
    $("#modal-form input[name=action]").val(mode);
    if(mode === "add"){
      // remove id hidden if exists
      $("#modal-form input[name=id]").remove();
      $("#m-codigo").val("");
      $("#m-descricao").val("");
      $("#m-aliquota").val("");
      $(".modal-icon").text("+");
      $(".modal-title h3").text("Novo Serviço");
      $(".modal-sub").text("Preencha os dados e clique em Cadastrar.");
      // atualiza texto do botão para cadastrar
      $("#modal-form .btn-primary").text("Cadastrar");
    } else {
      // ensure id input exists
      if($("#modal-form input[name=id]").length === 0 && data && data.id){
        $("<input>").attr({type:"hidden", name:"id", value:data.id}).appendTo("#modal-form");
      } else if(data && data.id){
        $("#modal-form input[name=id]").val(data.id);
      }
      if(data){
        $("#m-codigo").val(data.codigo || "");
        $("#m-descricao").val(data.descricao || "");
        $("#m-aliquota").val(data.aliquota || "");
      }
      $(".modal-icon").text("✎");
      $(".modal-title h3").text("Editar Serviço");
      $(".modal-sub").text("Altere os dados e clique em Atualizar.");
      // atualiza texto do botão para atualizar
      $("#modal-form .btn-primary").text("Atualizar");
    }
    $("#modal-backdrop").addClass("open");
  }

  function closeModal(){
    $("#modal-backdrop").removeClass("open");
    // if page was loaded with action=edit or action=delete, reload to remove querystring
    var qs = window.location.search || "";
    if (qs.indexOf("action=edit") !== -1 || qs.indexOf("action=delete") !== -1) {
        window.location.href = "'.$_SERVER["PHP_SELF"].'?page='.$page.'";
    }
  }

  // novo
  $("#btn-new-service").on("click", function(e){
    e.preventDefault();
    openModal("add");
  });

  // abrir edição a partir do link (popula com data-attrs)
  $(document).on("click", ".open-edit", function(e){
    e.preventDefault();
    var $a = $(this);
    var data = {
      id: $a.data("id"),
      codigo: $a.data("codigo"),
      descricao: $a.data("descricao"),
      aliquota: $a.data("aliquota")
    };
    openModal("update", data);
  });

  // fechar: only when clicking the backdrop itself or the explicit close buttons
  $("#modal-backdrop").on("click", function(e){
    if (e.target !== this) return; // click dentro do modal não fecha
    closeModal();
  });
  $("#modal-close, #modal-cancel").on("click", function(e){
    e.preventDefault();
    closeModal();
  });

  // fechar modal de exclusão (botões específicos)
  $("#modal-delete-close, #modal-delete-cancel").on("click", function(e){
      e.preventDefault();
      // fecha apenas o modal de exclusão
      $("#modal-delete-backdrop").removeClass("open");
      // recarregar sem params se necessário
      if (window.location.search.indexOf("action=delete") !== -1) {
          window.location.href = "'.$_SERVER["PHP_SELF"].'?page='.$page.'";
      }
  });

  // evitar submissão duplamente por enter em inputs (opcional)
  $("#modal-form").on("submit", function(){
    // deixa o servidor tratar; aqui só fecha backdrop durante submit para feedback
    $("#modal-backdrop").removeClass("open");
    return true;
  });

  // Limpar filtros de pesquisa
  $("#btn-clear-filters").on("click", function(){
    $("input[name=\'search_codigo\']").val("");
    $("input[name=\'search_descricao\']").val("");
    $("#search-form").submit();
  });
});
</script>
';

// Listagem
if ($resql) {
    $num = $db->num_rows($resql);
    
    print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" id="search-form">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="list">';
    print '<input type="hidden" name="sortfield" value="'.$sortfield.'">';
    print '<input type="hidden" name="sortorder" value="'.$sortorder.'">';
    print '<input type="hidden" name="page" value="'.$page.'">';

    // Sistema de Pesquisa Reformulado
    print '<div class="search-container">';
    print '<div class="search-header">';
    print '<div class="search-title">Filtros de Pesquisa</div>';
    print '</div>';
    
    print '<div class="search-fields">';
    
    print '<div class="search-field">';
    print '<label>Código do Serviço</label>';
    print '<input type="text" name="search_codigo" value="'.$search_codigo.'" placeholder="Digite o código...">';
    print '</div>';
    
    print '<div class="search-field">';
    print '<label>Descrição</label>';
    print '<input type="text" name="search_descricao" value="'.$search_descricao.'" placeholder="Digite a descrição...">';
    print '</div>';
    
    print '</div>';
    
    print '<div class="search-actions">';
    print '<button type="submit" name="button_search" class="btn-search btn-search-primary">Buscar</button>';
    print '<button type="button" id="btn-clear-filters" class="btn-search btn-search-secondary">Limpar Filtros</button>';
    print '</div>';
    
    print '</div>';

    // Paginação Superior
    if ($total_pages > 1) {
        print '<div class="pagination-wrapper">';
        print '<div class="pagination-info">';
        print 'Exibindo <strong>'.($page * $limit + 1).'</strong> a <strong>'.min(($page + 1) * $limit, $nbtotalofrecords).'</strong> de <strong>'.$nbtotalofrecords.'</strong> registros';
        print '</div>';
        
        print '<div class="pagination-controls">';
        
        // Botão Anterior
        if ($page > 0) {
            print '<a href="'.$_SERVER["PHP_SELF"].'?page='.($page - 1).$param.'" class="pagination-button prev">Anterior</a>';
        } else {
            print '<span class="pagination-button prev disabled">Anterior</span>';
        }
        
        // Números de página
        $start_page = max(0, $page - 2);
        $end_page = min($total_pages - 1, $page + 2);
        
        if ($start_page > 0) {
            print '<a href="'.$_SERVER["PHP_SELF"].'?page=0'.$param.'" class="pagination-button">1</a>';
            if ($start_page > 1) print '<span class="pagination-dots">...</span>';
        }
        
        for ($i = $start_page; $i <= $end_page; $i++) {
            if ($i == $page) {
                print '<span class="pagination-button active">'.($i + 1).'</span>';
            } else {
                print '<a href="'.$_SERVER["PHP_SELF"].'?page='.$i.$param.'" class="pagination-button">'.($i + 1).'</a>';
            }
        }
        
        if ($end_page < $total_pages - 1) {
            if ($end_page < $total_pages - 2) print '<span class="pagination-dots">...</span>';
            print '<a href="'.$_SERVER["PHP_SELF"].'?page='.($total_pages - 1).$param.'" class="pagination-button">'.$total_pages.'</a>';
        }
        
        // Botão Próximo
        if ($page < $total_pages - 1) {
            print '<a href="'.$_SERVER["PHP_SELF"].'?page='.($page + 1).$param.'" class="pagination-button next">Próximo</a>';
        } else {
            print '<span class="pagination-button next disabled">Próximo</span>';
        }
        
        print '</div>';
        print '</div>';
    }

    print '<div class="div-table-responsive">';
    print '<table class="tagtable liste" width="100%">';
    
    // Cabeçalhos
    print '<tr class="liste_titre">';
    print_liste_field_titre('Código', $_SERVER["PHP_SELF"], 'codigo', '', $param, '', $sortfield, $sortorder);
    print_liste_field_titre('Descrição', $_SERVER["PHP_SELF"], 'descricao', '', $param, '', $sortfield, $sortorder);
    print_liste_field_titre('Alíquota ISS (%)', $_SERVER["PHP_SELF"], 'aliquota_iss', '', $param, 'align="right"', $sortfield, $sortorder);
    print_liste_field_titre('', $_SERVER["PHP_SELF"], '', '', $param, '', $sortfield, $sortorder, 'maxwidthsearch');
    print '</tr>';

    if ($num) {
        $i = 0;
        while ($i < $num) {
            
            $obj = $db->fetch_object($resql);
            print '<tr class="oddeven">';
            print '<td>'.$obj->codigo.'</td>';
            print '<td>'.$obj->descricao.'</td>';
            print '<td class="right">'.price($obj->aliquota_iss).'%</td>';
            print '<td class="right">';
            print '<a href="#" class="open-edit" data-id="'.(int)$obj->rowid.'" data-codigo="'.htmlspecialchars($obj->codigo, ENT_QUOTES).'" data-descricao="'.htmlspecialchars($obj->descricao, ENT_QUOTES).'" data-aliquota="'.htmlspecialchars($obj->aliquota_iss, ENT_QUOTES).'" title="Editar">'.img_edit().'</a> &nbsp; ';
            print '<a class="deletefilelink" href="'.$_SERVER["PHP_SELF"].'?action=delete&id='.$obj->rowid.'&page='.$page.'&token='.newToken().'" title="Excluir">'.img_delete().'</a>';
            print '</td></tr>';
            $i++;
        }
    } else {
        print '<tr><td colspan="4" class="opacitymedium">Nenhum serviço encontrado.</td></tr>';
    }
    print '</table></div>';
    
    
    
    print '</form>';
} else {
    dol_print_error($db);
}

llxFooter();
$db->close();
