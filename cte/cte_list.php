<?php
// Silencia avisos/deprecations/notices na página (sem afetar logs)
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
$__lvl = error_reporting();
$__lvl &= ~E_DEPRECATED;
$__lvl &= ~E_USER_DEPRECATED;
$__lvl &= ~E_NOTICE;
$__lvl &= ~E_USER_NOTICE;
$__lvl &= ~E_WARNING;
$__lvl &= ~E_USER_WARNING;
error_reporting($__lvl);

require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/custom/composerlib/vendor/autoload.php';

/** @var DoliDB $db */
/** @var Translate $langs */
/** @var Conf $conf */

use NFePHP\CTe\Tools;
use NFePHP\Common\Certificate;
use NFePHP\CTe\Common\Standardize;
use NFePHP\CTe\Complements;

/**
 * Inicializa tabela de eventos CT-e se não existir
 */
function inicializarBancoDeDadosCTe(DoliDB $db): void
{
    $sql = "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "cte_eventos (
        rowid INT AUTO_INCREMENT PRIMARY KEY,
        fk_cte INT NOT NULL,
        tipo SMALLINT,
        protocolo VARCHAR(50),
        justificativa VARCHAR(255),
        xml_enviado LONGTEXT,
        xml_recebido LONGTEXT,
        data_evento DATETIME,
        INDEX idx_fk_cte (fk_cte)
    )";
    $db->query($sql);
}

/**
 * Processa ação de cancelamento CT-e via AJAX
 */
function processarAcaoCTe(DoliDB $db, Translate $langs, string $action) {
    $isAjax = (GETPOST('ajax', 'int') == 1);
    
    if ($action !== 'submitcancelar_cte') return;
    
    // Validar token CSRF
    if (empty($_POST['token']) || $_POST['token'] !== $_SESSION['newtoken']) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Token CSRF inválido']);
            exit;
        }
        return;
    }
    
    $idCTe = GETPOSTINT('id');
    if ($idCTe <= 0) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'ID do CT-e inválido']);
            exit;
        }
        return;
    }
    
    $justificativa = GETPOST('justificativa', 'restricthtml');
    
    if (mb_strlen($justificativa) < 15) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'A justificativa deve conter pelo menos 15 caracteres.']);
            exit;
        }
        return;
    }
    
    // Buscar dados do CT-e
    $sql = "SELECT rowid, chave, protocolo, dhemi FROM " . MAIN_DB_PREFIX . "cte_emitidos WHERE rowid = " . $idCTe;
    $res = $db->query($sql);
    
    if ($res && $db->num_rows($res) > 0) {
        $obj = $db->fetch_object($res);
        
        // Validar prazo de 7 dias (168 horas) para cancelamento CT-e
        if (!empty($obj->dhemi)) {
            try {
                $emissao = new DateTime($obj->dhemi);
                $agora = new DateTime('now');
                $diffHours = ($agora->getTimestamp() - $emissao->getTimestamp()) / 3600;
                
                if ($diffHours > 168) {
                    $errorMsg = 'O prazo para cancelamento (7 dias) já expirou para este CT-e.';
                    if ($isAjax) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => $errorMsg]);
                        exit;
                    }
                    return;
                }
            } catch (Exception $e) {
                // Continua se houver erro ao validar data
            }
        }
        
        // Incluir arquivo com função de cancelamento
        require_once DOL_DOCUMENT_ROOT . '/custom/cte/cancelar_cte.php';
        
        // Chamar função de cancelamento
        $resultado = cancelarCte($db, $obj->protocolo, $obj->chave, $justificativa, $obj->rowid);
        
        // Usar setEventMessage padrão Dolibarr
        if ($resultado['success']) {
            setEventMessages($resultado['message'], null, 'mesgs');
        } else {
            setEventMessages($resultado['message'], null, 'errors');
        }
        
        // Redirecionar para evitar resubmissão
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
        
    } else {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'CT-e não encontrado.']);
            exit;
        }
    }
}

// Handlers AJAX - devem ser processados antes de qualquer saída HTML
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    // Handler AJAX: consultar CT-e
    if (GETPOST('action', 'alpha') === 'consultar') {
        header('Content-Type: text/html; charset=UTF-8');
        require_once DOL_DOCUMENT_ROOT . '/custom/cte/consulta_cte_handler.php';
        exit;
    }

    // Handler AJAX: carregar configurações
    if (GETPOST('action', 'alpha') === 'carregar_configuracoes') {
        if (!$user->admin) {
            header('Content-Type: text/html; charset=UTF-8');
            echo '<div class="cte-alert cte-alert-error">Acesso negado. Apenas administradores podem acessar as configurações.</div>';
            exit;
        }
        
        header('Content-Type: text/html; charset=UTF-8');
        
        // Carrega dados do emitente
        global $mysoc;
        if (empty($mysoc->id)) {
            $mysoc->fetch(0);
        }
        $cnpj_raw = $mysoc->idprof1 ?? '';
        $cnpj = preg_replace('/\D/', '', $cnpj_raw);
        $display_cnpj = $cnpj !== '' ? $cnpj : '';

        // Carrega sequência do banco
        $ultimo_cte_display  = 0;
        $serie_display       = $conf->global->CTE_DEFAULT_SERIE ?? '1';

        if ($cnpj !== '') {
            $serie_query = $db->escape($serie_display);
            $cnpjE = $db->escape($cnpj);

            $sqlSeq = "SELECT numero_cte
                       FROM ".MAIN_DB_PREFIX."cte_sequencias
                       WHERE cnpj = '".$cnpjE."' AND serie = '".$serie_query."' AND tipo = 'NORMAL' LIMIT 1";
            $resSeq = $db->query($sqlSeq);
            if ($resSeq && $db->num_rows($resSeq) > 0) {
                $rowSeq = $db->fetch_object($resSeq);
                $ultimo_cte_display  = max(0, ((int)$rowSeq->numero_cte) - 1);
            }
        }
        
        echo '<div class="cte-setup">
        <div class="cte-card">
            <div class="cte-card-header">
                <span class="icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22">
                        <path d="M19.14,12.94a7.43,7.43,0,0,0,.05-.94,7.43,7.43,0,0,0-.05-.94l2-1.55a.5.5,0,0,0,.12-.64l-1.9-3.29a.5.5,0,0,0-.6-.22l-2.35,1a7.49,7.49,0,0,0-1.63-.94l-.35-2.49A.5.5,0,0,0,13,2h-3.8a.5.5,0,0,0-.49.42L8.36,4.91a7.49,7.49,0,0,0-1.63.94l-2.35-1a.5.5,0,0,0-.6.22L1.88,8.36a.5.5,0,0,0,.12.64l2,1.55a7.43,7.43,0,0,0,0,1.88l-2,1.55a.5.5,0,0,0-.12.64l1.9,3.29a.5.5,0,0,0,.6.22l2.35-1a7.49,7.49,0,0,0,1.63.94l.35,2.49A.5.5,0,0,0,9.2,22H13a.5.5,0,0,0,.49-.42l.35-2.49a7.49,7.49,0,0,0,1.63-.94l2.35,1a.5.5,0,0,0,.6-.22l1.9-3.29a.5.5,0,0,0-.12-.64ZM12,15.5A3.5,3.5,0,1,1,15.5,12,3.5,3.5,0,0,1,12,15.5Z"/>
                    </svg>
                </span>
                <span>Sequências</span>
            </div>
            <div class="cte-card-body">
                <form id="formConfiguracoes" method="post" class="cte-setup-form">
                    <input type="hidden" name="action" value="salvar_configuracoes">
                    <input type="hidden" name="token" value="'.newToken().'">
                    <input type="hidden" name="cnpj" value="'.dol_escape_htmltag($cnpj).'">
                    <div class="cte-data-grid-2">
                        <div class="cte-data-item">
                            <div class="cte-data-label">Último CT-e Enviado</div>
                            <div class="cte-data-value">
                                <input type="number" name="numero_cte" class="flat" value="'.$ultimo_cte_display.'" min="0">
                            </div>
                        </div>
                        <div class="cte-data-item">
                            <div class="cte-data-label">Série CT-e Padrão</div>
                            <div class="cte-data-value">
                                <input type="text" name="serie" class="flat" value="'.dol_escape_htmltag($serie_display).'">
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <div class="cte-card">
            <div class="cte-card-header">
                <span class="icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22">
                        <path d="M3 4h18v2H3V4zm0 14h18v2H3v-2zM3 10h18v4H3v-4z"/>
                    </svg>
                </span>
                <span>Emitente (somente leitura)</span>
            </div>
            <div class="cte-card-body">
                <div class="cte-data-grid-2">
                    <div class="cte-data-item">
                        <div class="cte-data-label">CNPJ (emitente)</div>
                        <div class="cte-data-value">'.dol_escape_htmltag($display_cnpj).'</div>
                    </div>
                </div>
            </div>
        </div>

        <div class="actions">
            <button type="button" class="butActionDelete" onclick="closeCteModal()">Cancelar</button>
            <button type="button" class="butAction" onclick="salvarConfiguracoes()">Salvar Configurações</button>
        </div>
    </div>';
    exit;
    }

    // Handler AJAX: salvar configurações
    if (GETPOST('action', 'alpha') === 'salvar_configuracoes') {
        if (!$user->admin) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => 'Acesso negado']);
            exit;
        }
        
        require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
        
        header('Content-Type: application/json; charset=UTF-8');
        
        try {
            // Salvar série padrão nas configurações globais
            $serie = GETPOST('serie', 'alpha');
            if (!empty($serie)) {
                dolibarr_set_const($db, 'CTE_DEFAULT_SERIE', $serie, 'chaine', 0, '', $conf->entity);
            }

            // Atualizar sequência na tabela cte_sequencias
            $numero_cte = (int) GETPOST('numero_cte', 'int');
            $cnpj = GETPOST('cnpj', 'alpha');
            $cnpj = preg_replace('/\D/', '', $cnpj);
            
            if (empty($cnpj)) {
                echo json_encode(['success' => false, 'error' => 'CNPJ não informado']);
                exit;
            }

            if (empty($serie)) {
                echo json_encode(['success' => false, 'error' => 'Série não informada']);
                exit;
            }

            // Próximo número será numero_cte + 1
            $next_numero = max(1, $numero_cte + 1);

            $cnpjE = $db->escape($cnpj);
            $serieE = $db->escape($serie);
            $tipo = 'NORMAL';

            // Verificar se já existe registro
            $sqlCheck = "SELECT id FROM ".MAIN_DB_PREFIX."cte_sequencias 
                         WHERE cnpj = '".$cnpjE."' AND serie = '".$serieE."' AND tipo = '".$tipo."' LIMIT 1";
            $resCheck = $db->query($sqlCheck);
            
            if ($resCheck && $db->num_rows($resCheck) > 0) {
                // Atualizar registro existente
                $row = $db->fetch_object($resCheck);
                $sqlUpd = "UPDATE ".MAIN_DB_PREFIX."cte_sequencias
                           SET numero_cte = ". ((int)$next_numero) ."
                           WHERE id = ".((int)$row->id);
                $resultUpd = $db->query($sqlUpd);
                
                if (!$resultUpd) {
                    echo json_encode(['success' => false, 'error' => 'Erro ao atualizar sequência: '.$db->lasterror()]);
                    exit;
                }
            } else {
                // Inserir novo registro
                $sqlIns = "INSERT INTO ".MAIN_DB_PREFIX."cte_sequencias (cnpj, serie, tipo, numero_cte)
                           VALUES ('".$cnpjE."', '".$serieE."', '".$tipo."', ".((int)$next_numero).")";
                $resultIns = $db->query($sqlIns);
                
                if (!$resultIns) {
                    echo json_encode(['success' => false, 'error' => 'Erro ao criar sequência: '.$db->lasterror()]);
                    exit;
                }
            }
            
            echo json_encode(['success' => true, 'message' => 'Configurações salvas com sucesso!']);
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        
        exit;
    }
}

// Processar ação de cancelamento antes de renderizar página
if (GETPOST('action')) {
    processarAcaoCTe($db, $langs, GETPOST('action'));
}

// Inicia a página normal apenas se não for uma requisição AJAX
llxHeader('', 'CT-e - Conhecimento de Transporte Eletrônico');
inicializarBancoDeDadosCTe($db);

// Verifica se há mensagem flash para exibir
if (!empty($_SESSION['cte_flash_message'])) {
    $flash = $_SESSION['cte_flash_message'];
    setEventMessage($flash['message'], $flash['type']);
    unset($_SESSION['cte_flash_message']);
}

print load_fiche_titre('Conhecimentos de Transporte Eletrônicos (CT-e)');

// Legenda de status com botão de emissão
print '<div class="cte-status-legend" style="margin-bottom: 15px; padding: 10px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px;">';
print '<div class="cte-status-legend-left">';
print '<strong>Legenda de Status:</strong>';
print '<div style="display: flex; align-items: center; gap: 8px;"><span class="status-circle status-circle-green"></span> <span>Autorizado</span></div>';
print '<div style="display: flex; align-items: center; gap: 8px;"><span class="status-circle status-circle-red"></span> <span>Cancelado</span></div>';
print '<div style="display: flex; align-items: center; gap: 8px;"><span class="status-circle status-circle-yellow"></span> <span>Rejeitado</span></div>';
print '</div>';
print '<a href="'.dol_buildpath('/custom/cte/cte_main_home.php', 1).'" class="butAction">';
print '<i class="fa fa-plus" style="margin-right: 6px;" aria-hidden="true"></i>Emitir Nova CT-e';
print '</a>';
print '</div>';

// Parâmetros de paginação e ordenação
$page = max(0, (int) GETPOST('page', 'int'));
$sortfield = GETPOST('sortfield', 'alpha') ?: 'rowid';
$sortorder = GETPOST('sortorder', 'alpha') ?: 'DESC';

$defaultLimit = ($conf->liste_limit > 0) ? (int) $conf->liste_limit : 25;
$limit = (int) GETPOST('limit', 'int');
if ($limit <= 0) { $limit = $defaultLimit; }
$limitOptions = [25, 50, 100, 200];
$offset = $limit * $page;

// Filtros
$search_status_list = GETPOST('search_status_list', 'alpha');
$search_numero_cte_start = GETPOST('search_numero_cte_start', 'alpha');
$search_numero_cte_end = GETPOST('search_numero_cte_end', 'alpha');
$search_destinatario = GETPOST('search_destinatario', 'alpha');
$search_data_emissao_start = GETPOST('search_data_emissao_start', 'alpha');
$search_data_emissao_end = GETPOST('search_data_emissao_end', 'alpha');

// Monta cláusulas WHERE
$whereClauses = [];
$allowedStatuses = array('autorizado','cancelado','rejeitado','denegado','processando','erro_envio');
$selectedStatuses = array();
if (!empty($search_status_list)) {
    foreach (explode(',', (string)$search_status_list) as $st) {
        $stl = strtolower(trim($st));
        if ($stl !== '' && in_array($stl, $allowedStatuses, true)) $selectedStatuses[] = $stl;
    }
    $selectedStatuses = array_values(array_unique($selectedStatuses));
}
if (!empty($selectedStatuses)) {
    $vals = array_map(function($v) use ($db){ return "'".$db->escape($v)."'"; }, $selectedStatuses);
    $whereClauses[] = "(LOWER(c.status) IN (".implode(',', $vals)."))";
}

if ($search_numero_cte_start !== '') {
    $s = trim((string)$search_numero_cte_start);
    if ($s !== '' && is_numeric($s)) {
        $whereClauses[] = "CAST(c.numero AS UNSIGNED) >= ".(int)$s;
    } elseif ($s !== '') {
        $esc = $db->escape(strtolower($s));
        $whereClauses[] = "(LOWER(TRIM(c.numero)) LIKE '%".$esc."%')";
    }
}
if ($search_numero_cte_end !== '') {
    $s2 = trim((string)$search_numero_cte_end);
    if ($s2 !== '' && is_numeric($s2)) {
        $whereClauses[] = "CAST(c.numero AS UNSIGNED) <= ".(int)$s2;
    }
}

if (!empty($search_destinatario)) {
    $searchTerm = $db->escape(strtolower(trim($search_destinatario)));
    $whereClauses[] = "(LOWER(TRIM(c.chave)) LIKE '%" . $searchTerm . "%')";
}

if (!empty($search_data_emissao_start)) {
    $whereClauses[] = "DATE(c.dhemi) >= '".$db->escape($search_data_emissao_start)."'";
}
if (!empty($search_data_emissao_end)) {
    $whereClauses[] = "DATE(c.dhemi) <= '".$db->escape($search_data_emissao_end)."'";
}

$whereSQL = empty($whereClauses) ? '' : ' AND ' . implode(' AND ', $whereClauses);

// Contagem total COM filtros aplicados
$sql_count = "SELECT COUNT(*) as total 
              FROM ".MAIN_DB_PREFIX."cte_emitidos c
              WHERE 1=1" . $whereSQL;
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
$sql = "SELECT 
            c.rowid,
            c.chave,
            c.numero,
            c.serie,
            c.dhemi,
            c.protocolo,
            c.dest_cnpj,
            c.valor,
            c.status,
            c.datec
        FROM ".MAIN_DB_PREFIX."cte_emitidos c
        WHERE 1=1" . $whereSQL;

// Ordenação segura
$allowedSort = array('rowid','numero','chave','dhemi','valor','serie');
$sortcol = in_array($sortfield, $allowedSort) ? $sortfield : 'rowid';
$sql .= " ORDER BY c.".$sortcol." ".($sortorder === 'ASC' ? 'ASC' : 'DESC');

// Paginação
$sql .= $db->plimit($limit, $offset);

$res = $db->query($sql);
if (!$res) { dol_print_error($db); llxFooter(); exit; }
$num = $db->num_rows($res);

// CSS
print '<style>
.status-circle{display:inline-block;width:14px;height:14px;border-radius:50%;margin:0 auto;box-shadow:0 0 5px rgba(0,0,0,.2);vertical-align:middle;}
.status-circle-green{background:#28a745;border:2px solid #218838;}
.status-circle-red{background:#dc3545;border:2px solid #c82333;}
.status-circle-yellow{background:#ffc107;border:2px solid #e0a800;}
.status-circle-gray{background:#6c757d;border:2px solid #5a6268;}
.liste tr.row-status-autorizado td{background-color:rgba(73,182,76,.12);}
.liste tr.row-status-cancelado td{background-color:rgba(220,53,69,.11);}
.liste tr.row-status-rejeitado td{background-color:rgba(255,193,7,.12);}
.liste tr:hover td{background-color:rgba(0,0,0,.02);}
.liste{font-size:1em;border-collapse:collapse;width:100%;}
.liste th,.liste td{padding:8px;text-align:center;vertical-align:middle;border:1px solid #ddd;}
.liste th{background:#f4f4f4;font-weight:bold;}
@media (max-width:768px){
 .liste{font-size:.85em;}
 .liste th,.liste td{padding:6px;}
}
.nfe-dropdown{position:relative;display:inline-block;}
.nfe-dropdown-menu{display:none;position:absolute;top:calc(100% + 5px);left:0;background:#fff;min-width:160px;box-shadow:0 8px 16px rgba(0,0,0,.2);z-index:1050;border-radius:4px;padding:4px 0;text-align:start;}
.nfe-dropdown-menu .nfe-dropdown-item{padding:8px 14px;text-decoration:none;display:block;color:#333;font-size:.95em;cursor:pointer;}
.nfe-dropdown-menu .nfe-dropdown-item:hover{background:#070973ff;color:#fff;}
.nfe-dropdown-menu .nfe-dropdown-item.disabled{pointer-events:none;opacity:.55;color:#888;}

.cte-modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.6);
    display: none; z-index: 100000;
    align-items: center; justify-content: center;
    padding: 20px;
    opacity: 0;
    transition: opacity 0.2s ease-in-out;
}
.cte-modal-overlay.visible {
    display: flex;
    opacity: 1;
}
.cte-modal {
    background: #f4f6f9;
    border-radius: 10px;
    max-width: 900px; width: 95%;
    max-height: 90vh;
    display: flex; flex-direction: column;
    box-shadow: 0 10px 30px rgba(0,0,0,.35);
    transform: scale(0.98);
    transition: transform 0.2s ease-in-out;
}
.cte-modal-overlay.visible .cte-modal {
    transform: scale(1);
}
.cte-modal-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 20px;
    border-bottom: 1px solid #dee2e6;
    background: #fff;
    border-radius: 10px 10px 0 0;
}
.cte-modal-header strong { font-size: 1.1em; color: #333; }
.cte-modal-body {
    padding: 20px;
    overflow-y: auto;
    flex-grow: 1;
}
.cte-modal-close {
    background: transparent; border: 0; font-size: 24px; cursor: pointer;
    line-height: 1; padding: 4px 8px; color: #6c757d;
}
.cte-modal-close:hover { color: #343a40; }

.cte-card {
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    margin-bottom: 16px;
}
.cte-card-header {
    padding: 10px 15px;
    background: #f8f9fa;
    border-bottom: 1px solid #e9ecef;
    font-weight: bold;
    color: #495057;
    border-radius: 8px 8px 0 0;
}
.cte-card-body {
    padding: 15px;
    display: grid;
    gap: 12px 16px;
}
.cte-data-grid-2 { grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); }
.cte-data-grid-3 { grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
.cte-data-grid-4 { grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }

.cte-data-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.cte-data-label {
    font-size: 0.8em;
    color: #6c757d;
    font-weight: bold;
    text-transform: uppercase;
}
.cte-data-value {
    font-size: 1em;
    color: #212529;
    word-break: break-word;
}

.cte-alert { padding: 12px 15px; border-radius: 6px; margin-bottom: 16px; border: 1px solid transparent; }
.cte-alert-error { background-color: #f8d7da; color: #721c24; border-color: #f5c6cb; }

.cte-pagination-wrapper {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 15px 10px;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    margin: 15px 0;
    flex-wrap: wrap;
    gap: 15px;
}
.cte-pagination-info {
    font-size: 0.95em;
    color: #495057;
    font-weight: 500;
}
.cte-pagination-controls {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.cte-page-size-selector {
    display: flex;
    align-items: center;
    gap: 8px;
}
.cte-page-size-selector label {
    font-size: 0.9em;
    color: #6c757d;
    white-space: nowrap;
}
.cte-page-size-selector select {
    padding: 6px 12px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    background: white;
    font-size: 0.9em;
    cursor: pointer;
}
.cte-page-nav {
    display: flex;
    gap: 5px;
}
.cte-page-btn {
    padding: 6px 12px;
    border: 1px solid #ced4da;
    background: white;
    color: #495057;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.9em;
    text-decoration: none;
    transition: all 0.2s;
}
.cte-page-btn:hover:not(.disabled) {
    background: #007bff;
    color: white;
    border-color: #007bff;
}
.cte-page-btn.active {
    background: #007bff;
    color: white;
    border-color: #007bff;
    font-weight: bold;
}
.cte-page-btn.disabled {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
}

.status-filter-cell{ position: relative; }
.cte-status-filter-toggle{
    padding: 6px 12px;
    border: 1px solid #cfe6e2;
    background: #fff;
    color: #2c7a7b;
    border-radius: 16px;
    cursor: pointer;
    font-size: .88em;
    line-height: 1.3;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.cte-status-filter-toggle:hover{ background:#f0fbf8; border-color:#1abc9c; }
.cte-status-filter-toggle .badge{
    display: inline-block;
    min-width: 18px;
    padding: 2px 6px;
    border-radius: 10px;
    background: #1abc9c;
    color: #fff;
    font-size: .78em;
}

.cte-status-filter-popover{
    position: absolute !important;
    top: calc(100% + 6px);
    left: 0;
    z-index: 9999;
    background: #fff;
    border: 1px solid #e5f2ef;
    box-shadow: 0 8px 20px rgba(0,0,0,.12);
    border-radius: 8px;
    padding: 10px 12px;
    width: max-content;
    max-width: 360px;
    display: none;
}
.cte-status-filter-popover.visible{ display:block; }

.cte-status-filter-popover .opt{
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 8px;
    border-radius: 6px;
    cursor: pointer;
    transition: background .12s ease;
}
.cte-status-filter-popover .opt + .opt{ margin-top: 4px; }
.cte-status-filter-popover .opt:hover{ background: rgba(22,160,133,0.06); }

.cte-status-filter-popover .opt input[type="checkbox"]{
    width: 14px;
    height: 14px;
    accent-color: #16a085;
    border-radius: 3px;
    margin: 0 2px 0 0;
}
.cte-status-filter-popover .opt .cte-chip{
    width: 8px;
    height: 8px;
    border-radius: 50%;
    box-shadow: inset 0 -1px 0 rgba(0,0,0,0.05);
}
.cte-chip.autorizado{ background: #28a745; }
.cte-chip.cancelado{ background: #dc3545; }
.cte-chip.rejeitado{ background: #ffc107; }
.cte-chip.erro_envio{ background: #6c757d; }

.cte-status-filter-popover .actions{
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    margin-top: 10px;
    padding-top: 8px;
    border-top: 1px solid #f0f0f0;
}

.cte-config-container {
    display: inline-flex;
    align-items: center;
    margin-left: 8px;
    vertical-align: middle;
}
.cte-config-button {
    background: transparent;
    border: none;
    padding: 6px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    vertical-align: middle;
    transition: opacity 0.2s ease;
}
.cte-config-button:hover {
    opacity: 0.7;
}
.cte-config-button:focus {
    outline: none;
    box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.25);
}
.cte-config-fa-icon {
    font-size: 24px;
    color: #6c757d;
}
.cte-config-button:hover .cte-config-fa-icon {
    color: #495057;
}

.cte-setup .cte-card{
    border: 1px solid #e5f2ef; border-radius: 10px;
    box-shadow: 0 6px 16px rgba(0,0,0,0.06); background: #fff;
}
.cte-setup .cte-card-header{
    display:flex; align-items:center; gap:8px;
    background: #eafaf7; border-bottom: 1px solid #d8eee9; color:#2f4f4f;
    font-weight: 700;
}
.cte-setup .cte-card-header .icon svg{ width:22px; height:22px; fill:#16a085; }
.cte-setup .cte-data-grid-2{
    display:grid; grid-template-columns: repeat(auto-fit, minmax(240px,1fr)); gap:14px 18px;
}
.cte-setup .cte-data-item .cte-data-label{
    font-size:.85em; color:#64748b; font-weight:600; text-transform:none; margin-bottom:4px;
}
.cte-setup input.flat{
    width:100%; padding:10px 12px; border:1px solid #cfe6e2; border-radius:6px; background:#fff;
    transition: border-color .2s, box-shadow .2s;
}
.cte-setup input.flat:focus{
    border-color:#1abc9c; box-shadow:0 0 0 3px rgba(26,188,156,.18); outline:none;
}
.cte-setup .actions{ text-align:center; margin-top:16px; display:flex; gap:10px; justify-content:center; flex-wrap:wrap; }

/* Normalização completa dos botões - previne mudança de tamanho no hover */
.butAction, 
input.butAction, 
button.butAction,
a.butAction {
    display: inline-block;
    padding: 8px 15px !important;
    font-size: 0.95em !important;
    line-height: 1.4 !important;
    height: auto !important;
    min-height: 36px !important;
    box-sizing: border-box !important;
    vertical-align: middle !important;
    text-decoration: none !important;
    border: none !important;
    transition: background-color 0.2s ease, color 0.2s ease !important;
}

/* Garante que o hover não altere dimensões */
.butAction:hover,
input.butAction:hover,
button.butAction:hover,
a.butAction:hover {
    text-decoration: none !important;
    transform: none !important;
}

/* Remove qualquer outline ou shadow que possa afetar tamanho */
.butAction:focus,
input.butAction:focus,
button.butAction:focus,
a.butAction:focus {
    outline: none !important;
    box-shadow: none !important;
}

/* Específico para botões de submit */
input[type="submit"].butAction {
    min-height: 36px !important;
    height: auto !important;
    padding: 8px 15px !important;
}

/* Garante consistência do botão dropdown */
button.butAction.dropdown-toggle {
    padding: 8px 15px !important;
    min-height: 36px !important;
}

.cte-status-legend {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 15px;
}
.cte-status-legend-left {
    display: flex;
    align-items: center;
    gap: 15px;
}

/* Remove estilo anterior do botão emitir */
.cte-emit-button {
    /* Removido - agora usa apenas .butAction padrão */
}

/* Mensagem quando não há registros */
.nfe-empty-message {
    padding: 40px 20px;
    text-align: center;
    color: #6c757d;
    font-size: 1.1em;
}
.nfe-empty-message i {
    font-size: 3em;
    color: #dee2e6;
    margin-bottom: 15px;
    display: block;
}
</style>';

// Formulário e tabela
print '<div class="table-responsive-wrapper">';
print '<form method="GET" id="cteListFilterForm" action="'.$_SERVER["PHP_SELF"].'">';
print '<table class="liste noborder centpercent">';
print '<tr class="liste_titre">';

print_liste_field_titre('Status', $_SERVER["PHP_SELF"], "status", "", "", 'align="center"', $sortfield, $sortorder);
print_liste_field_titre('Número', $_SERVER["PHP_SELF"], "numero", "", "", 'align="center"', $sortfield, $sortorder);
print_liste_field_titre('Chave CT-e', $_SERVER["PHP_SELF"], "chave", "", "", 'align="center"', $sortfield, $sortorder);
print_liste_field_titre('Emissão', $_SERVER["PHP_SELF"], "dhemi", "", "", 'align="center"', $sortfield, $sortorder);
print_liste_field_titre('Valor', $_SERVER["PHP_SELF"], "valor", "", "", 'align="center right"', $sortfield, $sortorder);
print_liste_field_titre('Ações', '', '', '', '', 'align="center"');
print "</tr>";

// Linha de filtros
print '<tr class="liste_titre_filter">';

$selectedStatusesPhp = !empty($selectedStatuses) ? $selectedStatuses : array();
$selectedStatusListStr = dol_escape_htmltag(implode(',', $selectedStatusesPhp));
$badgeCount = count($selectedStatusesPhp);
print '<td class="center status-filter-cell">';
print '<input type="hidden" name="search_status_list" id="search_status_list" value="'.$selectedStatusListStr.'">';
print '<button type="button" class="cte-status-filter-toggle" onclick="toggleStatusFilter(event)">Filtrar'.($badgeCount? ' <span id="statusSelCount" class="badge">'.$badgeCount.'</span>':'').'</button>';
print '<div class="cte-status-filter-popover" id="statusFilterPopover">';
$opts = array(
    'autorizado' => 'Autorizado',
    'cancelado' => 'Cancelado',
    'rejeitado' => 'Rejeitado',
    'erro_envio' => 'Erro de Envio'
);
foreach ($opts as $key => $label) {
    $checked = in_array($key, $selectedStatusesPhp, true) ? ' checked' : '';
    print '<label class="opt"><input type="checkbox" value="'.$key.'"'.$checked.'> <span class="cte-chip '.$key.'"></span> '.$label.'</label>';
}
print '<div class="actions">';
print '<button type="button" class="butActionDelete" onclick="clearStatusFilter()">Limpar</button>';
print '<button type="button" class="butAction" onclick="applyStatusFilter()">Aplicar</button>';
print '</div></div>';
print '</td>';

print '<td class="center">';
print '<input type="text" name="search_numero_cte_start" value="'.dol_escape_htmltag($search_numero_cte_start).'" class="flat" size="6" placeholder="De"> - ';
print '<input type="text" name="search_numero_cte_end" value="'.dol_escape_htmltag($search_numero_cte_end).'" class="flat" size="6" placeholder="Até">';
print '</td>';
print '<td class="center"><input type="text" name="search_destinatario" value="'.dol_escape_htmltag($search_destinatario).'" class="flat" size="25" placeholder="Chave CT-e"></td>';
print '<td class="center">';
print '<input type="date" name="search_data_emissao_start" value="'.dol_escape_htmltag($search_data_emissao_start).'" class="flat"> - ';
print '<input type="date" name="search_data_emissao_end" value="'.dol_escape_htmltag($search_data_emissao_end).'" class="flat">';
print '</td>';
print '<td class="center"></td>';
print '<td class="center">';
print '<input type="submit" class="butAction search-button" value="'.$langs->trans("Search").'"> ';
print '<button type="submit" name="action" value="batch" formaction="'.DOL_URL_ROOT.'/custom/cte/cte_download_xml.php" class="butAction">'.$langs->trans("Baixar em Lote").'</button>';
if ($user->admin) {
    print '<div class="cte-config-container">';
    print '  <button type="button" id="cteConfigBtn" class="cte-config-button" onclick="openCteConfiguracoes()">';
    print '    <i class="fa fa-cog cte-config-fa-icon"></i>';
    print '  </button>';
    print '</div>';
}
print '</td>';
print '</tr>';

// Linhas de dados
$i = 0;

// ADICIONAR: Verificar se há registros
if ($num == 0) {
    print '<tr class="oddeven">';
    print '<td colspan="6" class="nfe-empty-message">';
    print '<i class="fa fa-inbox" aria-hidden="true"></i>';
    print '<div><strong>Nenhum CT-e encontrado</strong></div>';
    print '<div style="font-size: 0.9em; margin-top: 8px;">Nenhum registro corresponde aos filtros aplicados.</div>';
    print '</td>';
    print '</tr>';
}

while ($i < min($num, $limit)) {
    $obj = $db->fetch_object($res);

    $status = strtolower((string)$obj->status);
    $statusClass = '';
    $statusCircle = '<span class="status-circle status-circle-gray"></span>';

    if ($status === '100') {
        $statusClass = 'row-status-autorizado';
        $statusCircle = '<span class="status-circle status-circle-green"></span>';
    } elseif ($status === 'cancelado') {
        $statusClass = 'row-status-cancelado';
        $statusCircle = '<span class="status-circle status-circle-red"></span>';
    } elseif ($status === 'rejeitado' || $status === 'erro_envio') {
        $statusClass = 'row-status-rejeitado';
        $statusCircle = '<span class="status-circle status-circle-yellow"></span>';
    }

    $canCancel = ($status === '100');
    $cancelClass = $canCancel ? '' : 'disabled';
    $cancelOnClick = $canCancel ? 'onclick="openCteCancelamento('.(int)$obj->rowid.');return false;"' : 'onclick="return false;"';

    $ts = !empty($obj->dhemi) ? $db->jdate($obj->dhemi) : 0;
    $valor = price($obj->valor);

    $numeroCte = $obj->numero ? dol_escape_htmltag($obj->numero) : '-';
    
    $urlDownloadXml = dol_buildpath('/custom/cte/cte_download_xml.php', 1).'?action=single&id='.(int)$obj->rowid;
    $urlConsultar = dol_buildpath('/custom/cte/view_xml.php', 1).'?id='.(int)$obj->rowid;
    $urlDacteDownload = dol_buildpath('/custom/cte/dacte_pdf.php', 1).'?id='.(int)$obj->rowid.'&mode=download';
    $urlDacteView = dol_buildpath('/custom/cte/dacte_pdf.php', 1).'?id='.(int)$obj->rowid.'&mode=view';

    print '<tr class="oddeven '.$statusClass.'" data-id="'.(int)$obj->rowid.'">';
    print '<td class="center">'.$statusCircle.'</td>';
    print '<td class="center">'.$numeroCte.'</td>';
    print '<td class="center" style="font-size:0.85em;">'.dol_escape_htmltag($obj->chave ?: '-').'</td>';
    print '<td class="center">'.dol_print_date($ts, 'day').'</td>';
    print '<td class="right">R$ '.$valor.'</td>';

    print '<td class="center"><div class="nfe-dropdown">';
    print '<button class="butAction dropdown-toggle" type="button" onclick="toggleDropdown(event, \'cteDropdownMenu'.$obj->rowid.'\')">'.$langs->trans("Ações").'</button>';
    print '<div class="nfe-dropdown-menu" id="cteDropdownMenu'.$obj->rowid.'">';
    print '<a class="nfe-dropdown-item" href="'.$urlDownloadXml.'">Download XML</a>';
    print '<a class="nfe-dropdown-item" href="#" onclick="openCteConsulta('.(int)$obj->rowid.');return false;">Consultar</a>';
    print '<a class="nfe-dropdown-item" href="'.$urlDacteView.'" target="_blank">Visualizar DACTE</a>';
    print '<a class="nfe-dropdown-item" href="'.$urlDacteDownload.'">Download DACTE</a>';
    
    // Botão de cancelamento (apenas para CT-e autorizados - status '100')
    if ($status === '100') {
        $dataEmissao = !empty($obj->dhemi) ? dol_escape_htmltag($obj->dhemi) : '';
        print '<a class="nfe-dropdown-item cancelar-cte-btn" href="#" data-id="'.$obj->rowid.'" data-emissao="'.$dataEmissao.'">Cancelar</a>';
    }
    
    print '</div></div></td>';

    print '</tr>';
    $i++;
}
print '</table>';
print '</form>';
print '</div>';

// Paginação
$filters = [
    'search_status_list' => $search_status_list,
    'search_numero_cte_start' => $search_numero_cte_start,
    'search_numero_cte_end' => $search_numero_cte_end,
    'search_destinatario' => $search_destinatario,
    'search_data_emissao_start' => $search_data_emissao_start,
    'search_data_emissao_end' => $search_data_emissao_end,
    'sortfield' => $sortfield,
    'sortorder' => $sortorder
];

if (!function_exists('cte_buildURL')) {
    function cte_buildURL($page, $limitVal, $filtersArray) {
        $params = array_merge(array_filter($filtersArray), [
            'page' => $page,
            'limit' => $limitVal
        ]);
        return $_SERVER["PHP_SELF"] . '?' . http_build_query($params);
    }
}

$totalPages = ($total_rows > 0) ? ceil($total_rows / $limit) : 1;
$currentPage = $page + 1;
$startRecord = ($total_rows > 0) ? ($offset + 1) : 0;
$endRecord = min($offset + $limit, $total_rows);

print '<div class="cte-pagination-wrapper">';
print '<div class="cte-pagination-info">';
print 'Mostrando <strong>'.$startRecord.'</strong> a <strong>'.$endRecord.'</strong> de <strong>'.$total_rows.'</strong> registros';
print '</div>';

print '<div class="cte-pagination-controls">';
print '<div class="cte-page-size-selector">';
print '<label>Por página:</label>';
print '<select onchange="window.location.href=this.value">';
foreach ($limitOptions as $opt) {
    $selected = ($opt == $limit) ? ' selected' : '';
    $url = cte_buildURL(0, $opt, $filters);
    print '<option value="'.$url.'"'.$selected.'>'.$opt.'</option>';
}
print '</select>';
print '</div>';

print '<div class="cte-page-nav">';
$prevPage = max(0, $page - 1);
$prevDisabled = ($page == 0) ? 'disabled' : '';
$prevUrl = cte_buildURL($prevPage, $limit, $filters);
print '<a href="'.$prevUrl.'" class="cte-page-btn '.$prevDisabled.'">‹ Anterior</a>';

$startPage = max(0, $page - 2);
$endPage = min($totalPages - 1, $page + 2);

if ($startPage > 0) {
    $firstUrl = cte_buildURL(0, $limit, $filters);
    print '<a href="'.$firstUrl.'" class="cte-page-btn">1</a>';
    if ($startPage > 1) print '<span class="cte-page-btn disabled">...</span>';
}

for ($p = $startPage; $p <= $endPage; $p++) {
    $pageUrl = cte_buildURL($p, $limit, $filters);
    $activeClass = ($p == $page) ? 'active' : '';
    print '<a href="'.$pageUrl.'" class="cte-page-btn '.$activeClass.'">'.($p + 1).'</a>';
}

if ($endPage < $totalPages - 1) {
    if ($endPage < $totalPages - 2) print '<span class="cte-page-btn disabled">...</span>';
    $lastUrl = cte_buildURL($totalPages - 1, $limit, $filters);
    print '<a href="'.$lastUrl.'" class="cte-page-btn">'.$totalPages.'</a>';
}

$nextPage = min($totalPages - 1, $page + 1);
$nextDisabled = ($page >= $totalPages - 1) ? 'disabled' : '';
$nextUrl = cte_buildURL($nextPage, $limit, $filters);
print '<a href="'.$nextUrl.'" class="cte-page-btn '.$nextDisabled.'">Próximo ›</a>';
print '</div>';

print '</div>';
print '</div>';

// Modal HTML
print '<div id="cteModal" class="cte-modal-overlay" role="dialog" aria-modal="true">
  <div class="cte-modal">
    <div class="cte-modal-header">
      <strong id="cteModalTitle">CT-e</strong>
      <button class="cte-modal-close" onclick="closeCteModal()">&times;</button>
    </div>
    <div id="cteModalBody" class="cte-modal-body">
      Carregando...
    </div>
  </div>
</div>';

// JavaScript
print '<script>
function toggleDropdown(event, menuId) {
    event.stopPropagation();
    var menu = document.getElementById(menuId);
    if (!menu) return;
    var isOpen = menu.style.display === "block";
    document.querySelectorAll(".nfe-dropdown-menu").forEach(function(m){
        m.style.display = "none";
    });
    menu.style.display = isOpen ? "none" : "block";
}

document.addEventListener("click", function(e) {
    if (!e.target.closest(".nfe-dropdown")) {
        document.querySelectorAll(".nfe-dropdown-menu").forEach(function(m){
            m.style.display = "none";
        });
    }
});

function openCteModal(title) {
  var el = document.getElementById("cteModal");
  var titleEl = document.getElementById("cteModalTitle");
  if (titleEl && title) titleEl.textContent = title;
  if (el) { el.classList.add("visible"); }
}
function closeCteModal() {
  var el = document.getElementById("cteModal");
  if (el) { el.classList.remove("visible"); }
}

function openCteConsulta(id) {
  document.querySelectorAll(".nfe-dropdown-menu").forEach(m => m.style.display = "none");
  var body = document.getElementById("cteModalBody");
  if (body) body.innerHTML = "Carregando...";
  openCteModal("Consulta de CT-e");
  var url = "'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'?action=consultar&id=" + encodeURIComponent(id);
  fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest" } })
    .then(function(r){ return r.text(); })
    .then(function(html){
      var bodyEl = document.getElementById("cteModalBody");
      if (bodyEl) bodyEl.innerHTML = html;
    })
    .catch(function(err){
      var bodyEl = document.getElementById("cteModalBody");
      if (bodyEl) bodyEl.innerHTML = "<div class=\\"cte-alert cte-alert-error\\">Falha ao consultar: "+ (err && err.message ? err.message : "erro desconhecido") +"</div>";
    });
}

function openCteCancelamento(id) {
  document.querySelectorAll(".nfe-dropdown-menu").forEach(m => m.style.display = "none");
  var body = document.getElementById("cteModalBody");
  if (body) body.innerHTML = "Carregando...";
  openCteModal("Cancelar CT-e");
  var url = "'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'?action=mostrar_cancelamento&id=" + encodeURIComponent(id);
  fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest" } })
    .then(function(r){ return r.text(); })
    .then(function(html){
      var bodyEl = document.getElementById("cteModalBody");
      if (bodyEl) bodyEl.innerHTML = html;
    })
    .catch(function(err){
      var bodyEl = document.getElementById("cteModalBody");
      if (bodyEl) bodyEl.innerHTML = "<div class=\\"cte-alert cte-alert-error\\">Falha ao carregar: "+ (err && err.message ? err.message : "erro desconhecido") +"</div>";
    });
}

function openCteConfiguracoes() {
  document.querySelectorAll(".nfe-dropdown-menu").forEach(m => m.style.display = "none");
  var body = document.getElementById("cteModalBody");
  if (body) body.innerHTML = "Carregando...";
  openCteModal("Configurações CT-e");
  
  var url = "'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'?action=carregar_configuracoes";
  fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest" } })
    .then(function(r){ return r.text(); })
    .then(function(html){
      var bodyEl = document.getElementById("cteModalBody");
      if (bodyEl) bodyEl.innerHTML = html;
    })
    .catch(function(err){
      var bodyEl = document.getElementById("cteModalBody");
      if (bodyEl) bodyEl.innerHTML = "<div class=\\"cte-alert cte-alert-error\\">Falha: "+ (err && err.message ? err.message : "erro") +"</div>";
    });
}

function salvarConfiguracoes() {
  var form = document.getElementById("formConfiguracoes");
  if (!form) return;
  
  var body = document.getElementById("cteModalBody");
  if (body) body.innerHTML = "Salvando...";
  
  var formData = new FormData(form);
  
  fetch("'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'", {
    method: "POST",
    body: formData,
    headers: { "X-Requested-With": "XMLHttpRequest" }
  })
  .then(function(r){ return r.json(); })
  .then(function(response){
    if (response.success) {
      var bodyEl = document.getElementById("cteModalBody");
      if (bodyEl) bodyEl.innerHTML = "<div class=\\"cte-alert\\" style=\\"background:#d4edda;color:#155724;\\">✓ " + (response.message || "Salvo!") + "</div>";
      setTimeout(function() {
        closeCteModal();
        window.location.reload();
      }, 1500);
    } else {
      throw new Error(response.error || "Erro desconhecido");
    }
  })
  .catch(function(err){
    var bodyEl = document.getElementById("cteModalBody");
    if (bodyEl) bodyEl.innerHTML = "<div class=\\"cte-alert cte-alert-error\\">Erro: "+ (err && err.message ? err.message : "erro") +"</div>";
  });
}

function toggleStatusFilter(e){
    e.stopPropagation();
    var pop = document.getElementById("statusFilterPopover");
    if (!pop) return;
    var open = pop.classList.contains("visible");
    pop.classList.toggle("visible", !open);
}

function applyStatusFilter(){
    var pop = document.getElementById("statusFilterPopover");
    var hidden = document.getElementById("search_status_list");
    var form = document.getElementById("cteListFilterForm");
    if (!pop || !hidden || !form) return;
    var vals = Array.from(pop.querySelectorAll("input[type=checkbox]:checked")).map(function(i){ return i.value; });
    hidden.value = vals.join(",");
    form.submit();
}

function clearStatusFilter(){
    var pop = document.getElementById("statusFilterPopover");
    var hidden = document.getElementById("search_status_list");
    if (!pop || !hidden) return;
    pop.querySelectorAll("input[type=checkbox]").forEach(function(i){ i.checked = false; });
    hidden.value = "";
    var badge = document.getElementById("statusSelCount");
    if (badge) badge.remove();
}

document.addEventListener("click", function(e){
    if (!e.target.closest(".status-filter-cell")) {
        document.querySelectorAll(".cte-status-filter-popover").forEach(function(p){ p.classList.remove("visible"); });
    }
});
</script>';

// Modal de Cancelamento CT-e
print '<div id="dialog-cancel-cte" title="Cancelar CT-e" style="display:none;">';
print '<p id="cancel-expired-msg-cte" style="display:none; color:#dc3545; font-weight:bold; margin-bottom:10px;"></p>';
print '<form id="form-cancel-cte">';
print '<div style="margin-bottom:15px;">';
print '<label for="justificativa_cancelamento_cte" style="display:block; margin-bottom:5px; font-weight:600;">Justificativa de Cancelamento:</label>';
print '<textarea id="justificativa_cancelamento_cte" name="justificativa" rows="3" style="width:100%; padding:8px; border:1px solid #ccc; border-radius:4px; resize:vertical;" placeholder="Digite a justificativa (mínimo 15 caracteres)" required></textarea>';
print '<small style="color:#666;">Mínimo de 15 caracteres</small>';
print '</div>';
print '</form>';
print '</div>';

?>
<script>
$(document).ready(function() {
    // Modal de cancelamento CT-e
    var dialogCancelCte = $("#dialog-cancel-cte").dialog({
        autoOpen: false,
        modal: true,
        width: 650,
        buttons: {
            "Cancelar CT-e": function() {
                var dialog = $(this);
                var justificativa = $("#justificativa_cancelamento_cte").val().trim();
                var cte_id = $(this).data("cte_id");
                
                if (justificativa.length < 15) {
                    alert("A justificativa deve conter pelo menos 15 caracteres.");
                    return;
                }
                
                // Criar form e submeter
                var form = $("<form>", {
                    method: "POST",
                    action: "<?php echo $_SERVER['PHP_SELF']; ?>"
                });
                
                form.append($("<input>", {type: "hidden", name: "action", value: "submitcancelar_cte"}));
                form.append($("<input>", {type: "hidden", name: "id", value: cte_id}));
                form.append($("<input>", {type: "hidden", name: "justificativa", value: justificativa}));
                form.append($("<input>", {type: "hidden", name: "token", value: "<?php echo dol_escape_js($_SESSION['newtoken']); ?>"}));
                
                $("body").append(form);
                form.submit();
            },
            "Fechar": function() { $(this).dialog("close"); }
        },
        open: function() {
            $("#justificativa_cancelamento_cte").val("");
        }
    });
    
    // Handler para abrir modal de cancelamento
    $(".cancelar-cte-btn").on("click", function(e) {
        e.preventDefault();
        var cte_id = $(this).data("id");
        var dataEmissao = $(this).data("emissao");
        
        var dialog = $("#dialog-cancel-cte");
        var cancelButton = dialog.closest(".ui-dialog").find(".ui-dialog-buttonpane button:first");
        
        // Validar prazo de 7 dias (168 horas)
        if (dataEmissao) {
            var emissaoDate = new Date(dataEmissao.replace(/-/g, "/"));
            var hoje = new Date();
            var diffTime = hoje.getTime() - emissaoDate.getTime();
            var diffHours = diffTime / (1000 * 60 * 60);
            
            if (diffHours > 168) {
                dialog.find("#cancel-expired-msg-cte").text("O prazo para cancelamento deste CT-e expirou. A legislação permite o cancelamento em até 7 dias (168 horas) após a autorização.").show();
                cancelButton.prop("disabled", true).addClass("ui-state-disabled");
                $("#justificativa_cancelamento_cte").prop("disabled", true);
            } else {
                dialog.find("#cancel-expired-msg-cte").hide();
                cancelButton.prop("disabled", false).removeClass("ui-state-disabled");
                $("#justificativa_cancelamento_cte").prop("disabled", false);
            }
        }
        
        dialog.data("cte_id", cte_id).dialog("open");
    });
});
</script>
<?php

llxFooter();
?>
