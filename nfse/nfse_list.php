<?php
// NOVO: silencia avisos/deprecations/notices na página (sem afetar logs)
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

/** @var DoliDB $db */
/** @var Translate $langs */
/** @var Conf $conf */

// Handlers AJAX - devem ser processados antes de qualquer saída HTML (NFS-e Nacional)
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    // Handler AJAX: consultar NFS-e Nacional
    if (GETPOST('action', 'alpha') === 'consultar') {
        require_once DOL_DOCUMENT_ROOT . '/custom/nfse/consulta_nfse_nacional.php';
        exit;
    }

    // Handler AJAX: cancelamento NFS-e Nacional
    if (in_array(GETPOST('action', 'alpha'), ['mostrar_cancelamento','processar_cancelamento'])) {
        require_once DOL_DOCUMENT_ROOT . '/custom/nfse/modal_cancelamento.php';
        exit;
    }

    // Handler AJAX: carregar configurações (setup.php)
    if (GETPOST('action', 'alpha') === 'carregar_configuracoes') {
        if (!$user->admin) {
            header('Content-Type: text/html; charset=UTF-8');
            echo '<div class="nfse-alert nfse-alert-error">Acesso negado. Apenas administradores podem acessar as configurações.</div>';
            exit;
        }
        
        header('Content-Type: text/html; charset=UTF-8');
        
        // Carrega dados do emitente
        global $mysoc;
        if (empty($mysoc->id)) {
            $mysoc->fetch(0);
        }
        $cnpj_raw = $mysoc->idprof1 ?? '';
        $im_raw   = $mysoc->idprof3 ?? '';
        $cnpj = preg_replace('/\D/', '', $cnpj_raw);
        $im   = preg_replace('/\D/', '', $im_raw);
        $display_cnpj = $cnpj !== '' ? $cnpj : '';
        $display_im   = $im !== '' ? $im : '';

        // Carrega sequências NFS-e Nacional (Produção e Homologação)
        $dps_producao = 0;
        $dps_homologacao = 0;
        $serie_producao = '1';
        $serie_homologacao = '1';
        
        if ($cnpj !== '') {
            $cnpjE = $db->escape($cnpj);
            
            // Ambiente 1 = Produção (pega o registro mais recente)
            $sqlProd = "SELECT next_dps, serie FROM ".MAIN_DB_PREFIX."nfse_nacional_sequencias 
                       WHERE cnpj = '".$cnpjE."' AND ambiente = 1 
                       ORDER BY updated_at DESC, id DESC LIMIT 1";
            $resProd = $db->query($sqlProd);
            if ($resProd && $db->num_rows($resProd) > 0) {
                $rowProd = $db->fetch_object($resProd);
                $dps_producao = max(0, ((int)$rowProd->next_dps) - 1);
                $serie_producao = $rowProd->serie ?: '1';
            }
            
            // Ambiente 2 = Homologação (pega o registro mais recente)
            $sqlHom = "SELECT next_dps, serie FROM ".MAIN_DB_PREFIX."nfse_nacional_sequencias 
                      WHERE cnpj = '".$cnpjE."' AND ambiente = 2 
                      ORDER BY updated_at DESC, id DESC LIMIT 1";
            $resHom = $db->query($sqlHom);
            if ($resHom && $db->num_rows($resHom) > 0) {
                $rowHom = $db->fetch_object($resHom);
                $dps_homologacao = max(0, ((int)$rowHom->next_dps) - 1);
                $serie_homologacao = $rowHom->serie ?: '1';
            }
        }
        
        // Renderiza o formulário (layout pro + wrapper nfse-setup)
        echo '<div class="nfse-setup">
        <div class="nfse-card">
            <div class="nfse-card-header">
                <span class="icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" width="22" height="22">
                        <path d="M12 2L2 7v10c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V7l-10-5z"/>
                    </svg>
                </span>
                <span>Sequências NFS-e Nacional</span>
            </div>
            <div class="nfse-card-body">
                <form id="formSequenciasNacional" method="post" class="nfse-setup-form">
                    <input type="hidden" name="action" value="salvar_sequencias_nacional">
                    <input type="hidden" name="token" value="'.newToken().'">
                    
                    <div style="margin-bottom: 20px;">
                        <h4 style="margin: 0 0 10px 0; color: #28a745;">Ambiente de Produção</h4>
                        <div class="nfse-data-grid-2">
                            <div class="nfse-data-item">
                                <div class="nfse-data-label">Último DPS Enviado (Produção)</div>
                                <div class="nfse-data-value">
                                    <input type="number" name="DPS_PRODUCAO" class="flat" value="'.$dps_producao.'">
                                </div>
                            </div>
                            <div class="nfse-data-item">
                                <div class="nfse-data-label">Série (Produção)</div>
                                <div class="nfse-data-value">
                                    <input type="text" name="SERIE_PRODUCAO" class="flat" value="'.dol_escape_htmltag($serie_producao).'">
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div style="margin-bottom: 20px; border-top: 1px solid #ddd; padding-top: 20px;">
                        <h4 style="margin: 0 0 10px 0; color: #ffc107;">Ambiente de Homologação</h4>
                        <div class="nfse-data-grid-2">
                            <div class="nfse-data-item">
                                <div class="nfse-data-label">Último DPS Enviado (Homologação)</div>
                                <div class="nfse-data-value">
                                    <input type="number" name="DPS_HOMOLOGACAO" class="flat" value="'.$dps_homologacao.'">
                                </div>
                            </div>
                            <div class="nfse-data-item">
                                <div class="nfse-data-label">Série (Homologação)</div>
                                <div class="nfse-data-value">
                                    <input type="text" name="SERIE_HOMOLOGACAO" class="flat" value="'.dol_escape_htmltag($serie_homologacao).'">
                                </div>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        
        <div class="actions">
            <button type="button" class="butActionDelete" onclick="closeNfseModal()">Cancelar</button>
            <button type="button" class="butAction" onclick="salvarSequenciasNacional()">Salvar Configurações</button>
        </div>
    </div>';
    exit;
    }

    // Handler AJAX: salvar sequências NFS-e Nacional
    if (GETPOST('action', 'alpha') === 'salvar_sequencias_nacional') {
        if (!$user->admin) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => 'Acesso negado']);
            exit;
        }
        
        header('Content-Type: application/json; charset=UTF-8');
        
        try {
            global $mysoc;
            if (empty($mysoc->id)) {
                $mysoc->fetch(0);
            }
            $cnpj_raw = $mysoc->idprof1 ?? '';
            $im_raw   = $mysoc->idprof3 ?? '';
            $cnpj = preg_replace('/\D/', '', $cnpj_raw);
            $im   = preg_replace('/\D/', '', $im_raw);

            if (empty($cnpj)) {
                throw new Exception('CNPJ do emitente não configurado');
            }
            
            // Valores do formulário
            $dps_producao = (int) GETPOST('DPS_PRODUCAO', 'int');
            $dps_homologacao = (int) GETPOST('DPS_HOMOLOGACAO', 'int');
            $serie_producao = GETPOST('SERIE_PRODUCAO', 'alpha') ?: '1';
            $serie_homologacao = GETPOST('SERIE_HOMOLOGACAO', 'alpha') ?: '1';
            
            $cnpjE = $db->escape($cnpj);
            $imE = $db->escape($im);
            
            // Inicia transação
            $db->begin();
            
            // Atualiza/Insere sequência de Produção (ambiente = 1)
            $next_dps_prod = max(1, $dps_producao) + 1;
            $serieE_prod = $db->escape($serie_producao);
            
            // INSERT com ON DUPLICATE KEY UPDATE (estrutura simplificada: apenas 1 registro por cnpj/ambiente)
            $sqlUpsert = "INSERT INTO ".MAIN_DB_PREFIX."nfse_nacional_sequencias 
                         (cnpj, im, serie, next_dps, ambiente, updated_at)
                         VALUES ('".$cnpjE."', '".$imE."', '".$serieE_prod."', ".((int)$next_dps_prod).", 1, NOW())
                         ON DUPLICATE KEY UPDATE 
                         next_dps = ".((int)$next_dps_prod).", 
                         serie = '".$serieE_prod."',
                         im = '".$imE."',
                         updated_at = NOW()";
            if (!$db->query($sqlUpsert)) {
                throw new Exception('Erro ao salvar sequência de Produção: ' . $db->lasterror());
            }
            
            // Atualiza/Insere sequência de Homologação (ambiente = 2)
            $next_dps_hom = max(1, $dps_homologacao) + 1;
            $serieE_hom = $db->escape($serie_homologacao);
            
            // INSERT com ON DUPLICATE KEY UPDATE (estrutura simplificada: apenas 1 registro por cnpj/ambiente)
            $sqlUpsert2 = "INSERT INTO ".MAIN_DB_PREFIX."nfse_nacional_sequencias 
                          (cnpj, im, serie, next_dps, ambiente, updated_at)
                          VALUES ('".$cnpjE."', '".$imE."', '".$serieE_hom."', ".((int)$next_dps_hom).", 2, NOW())
                          ON DUPLICATE KEY UPDATE 
                          next_dps = ".((int)$next_dps_hom).", 
                          serie = '".$serieE_hom."',
                          im = '".$imE."',
                          updated_at = NOW()";
            if (!$db->query($sqlUpsert2)) {
                throw new Exception('Erro ao salvar sequência de Homologação: ' . $db->lasterror());
            }
            
            // Commit da transação
            $db->commit();
            
            echo json_encode(['success' => true, 'message' => 'Sequências NFS-e Nacional salvas com sucesso!']);
            
        } catch (Exception $e) {
            // Rollback em caso de erro
            if (isset($db)) {
                $db->rollback();
            }
            error_log('[NFSE] Erro ao salvar sequências: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        
        exit;
    }
}

// Inicia a página normal apenas se não for uma requisição AJAX
llxHeader('', 'NFS-e');

// Verifica se há mensagem flash para exibir
if (!empty($_SESSION['nfse_flash_message'])) {
    $flash = $_SESSION['nfse_flash_message'];
    setEventMessage($flash['message'], $flash['type']);
    unset($_SESSION['nfse_flash_message']);
}
// pegar ambiente
$sql = "SELECT value FROM ". MAIN_DB_PREFIX."nfe_config WHERE name = 'ambiente';";
$resSql = $db->query($sql); // retorna objeto
if($resSql && $db->num_rows($resSql) > 0){ // valida se tem algo
    $res = $db->fetch_object($resSql); // transforma em um (stdClass)
    $ambiente = ($res->value == '2') ? 'Homologação' : 'Produção'; // acessa o resultado no objeto
}


$titulo_morebuts = '';
if ($user->admin) {
    $titulo_morebuts = '<a href="#" onclick="openNfseConfiguracoes(); return false;" class="butAction" title="Configurações NFS-e"><i class="fa fa-cog"></i> Configurações</a>';
}
print load_fiche_titre('Notas Fiscais de Serviço Eletrônicas (NFS-e) ('.$ambiente.')', $titulo_morebuts);



// Adiciona legenda fixa para cores de status
print '<div class="nfse-status-legend" style="margin-bottom: 15px; padding: 10px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; display: flex; align-items: center; gap: 15px;">';
print '<strong>Legenda de Status:</strong>';
print '<div style="display: flex; align-items: center; gap: 8px;"><span class="status-circle status-circle-green"></span> <span>Autorizada</span></div>';
print '<div style="display: flex; align-items: center; gap: 8px;"><span class="status-circle status-circle-red"></span> <span>Cancelada</span></div>';
print '<div style="display: flex; align-items: center; gap: 8px;"><span class="status-circle status-circle-yellow"></span> <span>Rejeitada</span></div>';
print '</div>';

// Parâmetros de paginação e ordenação
$page = max(0, (int) GETPOST('page', 'int'));
$sortfield = GETPOST('sortfield', 'alpha') ?: 'id';
$sortorder = GETPOST('sortorder', 'alpha') ?: 'DESC';

// Limite configurável por página com persistência
$defaultLimit = ($conf->liste_limit > 0) ? (int) $conf->liste_limit : 25;
$limit = (int) GETPOST('limit', 'int');
if ($limit <= 0) { $limit = $defaultLimit; }
$limitOptions = [25, 50, 100, 200];
$offset = $limit * $page;

// Filtros (aceita texto em identificação como no exemplo)
$search_status = GETPOST('search_status', 'alpha');
// NOVO: lista de status (comma-separated)
$search_status_list = GETPOST('search_status_list', 'alpha');
// aceitar texto no campo de identificação (não apenas int) - agora permite texto ou número
$search_fk_facture = GETPOST('search_fk_facture', 'alpha');
// aceitar texto no campo de identificação (não apenas int)
$search_numero_nfse_start = GETPOST('search_numero_nfse_start', 'alpha');
$search_numero_nfse_end = GETPOST('search_numero_nfse_end', 'alpha');
$search_client_name = GETPOST('search_client_name', 'alpha');
$search_data_emissao_start = GETPOST('search_data_emissao_start', 'alpha');
$search_data_emissao_end = GETPOST('search_data_emissao_end', 'alpha');

// Monta cláusulas WHERE para reutilizar na query principal e na contagem
$whereClauses = [];
// NOVO: lista de status permitidos e sanitização do filtro (NFS-e Nacional)
$allowedStatuses = array('pendente','enviando','autorizada','rejeitada','cancelada','erro');
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
    $whereClauses[] = "(LOWER(n.status) IN (".implode(',', $vals)."))";
} elseif (!empty($search_status)) {
    // compat: filtro antigo por texto
    $esc = $db->escape(strtolower(trim($search_status)));
    $whereClauses[] = "(LOWER(n.status) LIKE '%".$esc."%')";
}
if (trim((string)$search_fk_facture) !== '') {
    $s = trim((string)$search_fk_facture);
    if (is_numeric($s)) {
        $whereClauses[] = "n.id_fatura = ".(int)$s;
    } else {
        $esc = $db->escape(strtolower($s));
        // procura pela referência da fatura (f.ref) — necessita JOIN com facture na query
        $whereClauses[] = "(LOWER(COALESCE(f.ref,'')) LIKE '%".$esc."%')";
    }
}

// comportamento do campo "DPS/NFS-e" (Nacional: busca em numero_dps OU numero_nfse)
if ($search_numero_nfse_start !== '') {
    $s = trim((string)$search_numero_nfse_start);
    if ($s !== '' && is_numeric($s)) {
        $whereClauses[] = "(CAST(COALESCE(n.numero_nfse, n.numero_dps) AS UNSIGNED) >= ".(int)$s.")";
    } elseif ($s !== '') {
        $esc = $db->escape(strtolower($s));
        $whereClauses[] = "(LOWER(TRIM(COALESCE(n.numero_nfse, n.numero_dps))) LIKE '%".$esc."%')";
    }
}
if ($search_numero_nfse_end !== '') {
    $s2 = trim((string)$search_numero_nfse_end);
    if ($s2 !== '' && is_numeric($s2)) {
        $whereClauses[] = "(CAST(COALESCE(n.numero_nfse, n.numero_dps) AS UNSIGNED) <= ".(int)$s2.")";
    }
}

if (!empty($search_client_name)) {
    $searchTerm = $db->escape(strtolower(trim($search_client_name)));
    $whereClauses[] = "(LOWER(TRIM(n.tomador_nome)) LIKE '%" . $searchTerm . "%')";
}

// Ajuste nos filtros por data: NFS-e Nacional usa data_emissao (DATE, não DATETIME)
if (!empty($search_data_emissao_start)) {
    $whereClauses[] = "n.data_emissao >= '".$db->escape($search_data_emissao_start)."'";
}
if (!empty($search_data_emissao_end)) {
    $whereClauses[] = "n.data_emissao <= '".$db->escape($search_data_emissao_end)."'";
}

$whereSQL = empty($whereClauses) ? '' : ' AND ' . implode(' AND ', $whereClauses);

// Contagem total COM filtros aplicados (NFS-e Nacional)
$sql_count = "SELECT COUNT(*) as total 
              FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas n
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

// Consulta principal - NFS-e Nacional
$sql = "SELECT 
            n.id,
            n.id_fatura,
            n.status,
            n.numero_dps,
            n.serie,
            n.numero_nfse,
            n.chave_acesso,
            n.data_emissao,
            n.data_hora_autorizacao,
            n.valor_servicos,
            n.valor_iss,
            n.tomador_nome,
            n.ambiente,
            LENGTH(n.xml_nfse) as xml_nfse_size
        FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas n
        WHERE 1=1" . $whereSQL;

// Ordenação segura (NFS-e Nacional)
$allowedSort = array('id','id_fatura','numero_nfse','tomador_nome','data_emissao','valor_servicos','numero_dps','serie');
$sortcol = in_array($sortfield, $allowedSort) ? $sortfield : 'id';
$sql .= " ORDER BY n.".$sortcol." ".($sortorder === 'ASC' ? 'ASC' : 'DESC');

// Paginação (aplica limite calculado)
$sql .= $db->plimit($limit, $offset);

$res = $db->query($sql);
if (!$res) { dol_print_error($db); llxFooter(); exit; }
$num = $db->num_rows($res);

// CSS (cores, bolinhas, linhas por status, responsividade, dropdown)
print '<style>
/* Semáforo */
.status-circle{display:inline-block;width:14px;height:14px;border-radius:50%;margin:0 auto;box-shadow:0 0 5px rgba(0,0,0,.2);vertical-align:middle;}
.status-circle-green{background:#28a745;border:2px solid #218838;}
.status-circle-red{background:#dc3545;border:2px solid #c82333;}
.status-circle-yellow{background:#ffc107;border:2px solid #e0a800;}
.status-circle-gray{background:#6c757d;border:2px solid #5a6268;}
.status-circle-denied{background:#343a40;border:2px solid #23272b;}
/* Linhas por status */
.liste tr.row-status-autorizada td{background-color:rgba(73,182,76,.12);}
.liste tr.row-status-cancelada td{background-color:rgba(220,53,69,.11);}
.liste tr.row-status-rejeitada td{background-color:rgba(255,193,7,.12);}
.liste tr.row-status-denegada td{background-color:rgba(52,58,64,.12);}
.liste tr:hover td{background-color:rgba(0,0,0,.02);}
/* Tabela e responsivo */
.liste{font-size:1em;border-collapse:collapse;width:100%;}
.liste th,.liste td{padding:8px;text-align:center;vertical-align:middle;border:1px solid #ddd;}
.liste th{background:#f4f4f4;font-weight:bold;}
@media (max-width:768px){
 .liste{font-size:.85em;}
 .liste th,.liste td{padding:6px;}
 .actions-cell{display:flex;flex-direction:column;align-items:center;gap:10px;}
}
/* Dropdown */
.nfe-dropdown{position:relative;display:inline-block;}
.nfe-dropdown-menu{display:none;position:absolute;top:calc(100% + 5px);left:0;background:#fff;min-width:160px;box-shadow:0 8px 16px rgba(0,0,0,.2);z-index:1050;border-radius:4px;padding:4px 0;text-align:start;}
.nfe-dropdown-menu .nfe-dropdown-item{padding:8px 14px;text-decoration:none;display:block;color:#333;font-size:.95em;cursor:pointer;line-height:1.2em;}
.nfe-dropdown-menu .nfe-dropdown-item:hover{background:#070973ff;color:#fff;}
.nfe-dropdown-menu .nfe-dropdown-item.disabled{pointer-events:none;opacity:.55;color:#888;cursor:not-allowed;}
.nfe-dropdown-menu .nfe-dropdown-item.disabled-visual{opacity:.6;color:#999;}

/* ===== Modal de Consulta (Novo Visual) ===== */
.nfse-modal-overlay {
    position: fixed; inset: 0; background: rgba(0,0,0,.6);
    display: none; z-index: 100000;
    align-items: center; justify-content: center;
    padding: 20px;
    opacity: 0;
    transition: opacity 0.2s ease-in-out;
}
.nfse-modal-overlay.visible {
    display: flex;
    opacity: 1;
}
.nfse-modal {
    background: #f4f6f9;
    border-radius: 10px;
    max-width: 680px; width: 90%; /* Padrão para cancelamento */
    max-height: 90vh;
    display: flex; flex-direction: column;
    box-shadow: 0 10px 30px rgba(0,0,0,.35);
    transform: scale(0.98);
    transition: transform 0.2s ease-in-out;
}
.nfse-modal-overlay.visible .nfse-modal {
    transform: scale(1);
}
.nfse-modal-consulta {
    max-width: 900px !important; width: 95% !important; /* Modal de consulta maior */
}
.nfse-modal-cancelamento {
    max-width: 680px !important; width: 90% !important; /* Modal de cancelamento mantida */
}
.nfse-modal-substituicao {
    max-width: 820px !important; width: 95% !important; /* Modal otimizada para substituição */
}
.nfse-modal-configuracoes {
    max-width: 680px !important;
    width: 90% !important;
}

.nfse-modal-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 12px 20px;
    border-bottom: 1px solid #dee2e6;
    background: #fff;
    border-radius: 10px 10px 0 0;
}
.nfse-modal-header strong { font-size: 1.1em; color: #333; }
.nfse-modal-body {
    padding: 20px;
    overflow-y: auto;
    flex-grow: 1;
}
.nfse-modal-close {
    background: transparent; border: 0; font-size: 24px; cursor: pointer;
    line-height: 1; padding: 4px 8px; color: #6c757d;
}
.nfse-modal-close:hover { color: #343a40; }

/* Cards e Grid de Dados */
.nfse-card {
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    margin-bottom: 16px;
}
.nfse-card-header {
    padding: 10px 15px;
    background: #f8f9fa;
    border-bottom: 1px solid #e9ecef;
    font-weight: bold;
    color: #495057;
    border-radius: 8px 8px 0 0;
}
.nfse-card-body {
    padding: 15px;
    display: grid;
    gap: 12px 16px;
}
.nfse-data-grid-1 { grid-template-columns: 1fr; }
.nfse-data-grid-2 { grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); }
.nfse-data-grid-3 { grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
.nfse-data-grid-4 { grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }

.nfse-card-parties {
    grid-template-columns: 1fr 1fr;
}
.party-column {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.party-title {
    font-size: 1.05em;
    color: #343a40;
    border-bottom: 2px solid #007bff;
    padding-bottom: 6px;
    margin-bottom: 4px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
@media (max-width: 600px) {
    .nfse-card-parties { grid-template-columns: 1fr; }
}

.nfse-data-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.nfse-data-label {
    font-size: 0.8em;
    color: #6c757d;
    font-weight: bold;
    text-transform: uppercase;
}
.nfse-data-value {
    font-size: 1em;
    color: #212529;
    word-break: break-word;
}
.nfse-data-item.full-width { grid-column: 1 / -1; }

/* Alertas e XML */
.nfse-alert { padding: 12px 15px; border-radius: 6px; margin-bottom: 16px; border: 1px solid transparent; }
.nfse-alert-warn { background-color: #fff3cd; color: #856404; border-color: #ffeeba; }
.nfse-alert-error { background-color: #f8d7da; color: #721c24; border-color: #f5c6cb; }
.nfse-alert-success { background-color: #d4edda; color: #155724; border-color: #c3e6cb; }
.nfse-xml-details summary { cursor: pointer; font-weight: bold; color: #007bff; margin-top: 16px; }
.nfse-xml-details pre {
    white-space: pre-wrap; font-family: monospace; font-size: 12px;
    background: #2b3035; color: #c0c5ce;
    border: 1px solid #3e444c; border-radius: 6px;
    padding: 12px; max-height: 350px; overflow: auto; margin-top: 8px;
}

/* JS para modal e dropdown */
.nfse-spinner {
    display: inline-block;
    width: 40px;
    height: 40px;
    border: 4px solid rgba(0, 0, 0, 0.1);
    border-radius: 50%;
    border-top-color: #3498db;
    animation: spin 1s ease-in-out infinite;
    margin: 20px auto;
    text-align: center;
}
@keyframes spin {
    to { transform: rotate(360deg); }
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}
.nfse-loading-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px 30px;
    min-height: 200px;
}
.nfse-loading-container p {
    margin-top: 20px;
    font-size: 15px;
    color: #666;
    font-weight: 500;
}
.nfse-success-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px 30px;
    text-align: center;
}
.nfse-success-icon {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 20px;
    animation: successPulse 0.6s ease-out;
}
.nfse-success-icon svg {
    width: 45px;
    height: 45px;
    fill: white;
    animation: successCheck 0.8s ease-out 0.2s both;
}
.nfse-success-message {
    font-size: 18px;
    font-weight: 600;
    color: #28a745;
    margin-bottom: 10px;
}
.nfse-success-submessage {
    font-size: 14px;
    color: #666;
}
@keyframes successPulse {
    0% { transform: scale(0); opacity: 0; }
    50% { transform: scale(1.1); }
    100% { transform: scale(1); opacity: 1; }
}
@keyframes successCheck {
    0% { transform: scale(0) rotate(-45deg); opacity: 0; }
    100% { transform: scale(1) rotate(0deg); opacity: 1; }
}

/* ===== Modal de Confirmação - Usando Classes Dolibarr ===== */
.nfse-confirm-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0, 0, 0, 0.3);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 200000;
}
.nfse-confirm-overlay.visible {
    display: flex;
}

/* Melhoria do dropdown de motivo */
.nfse-data-item select.flat {
    padding: 10px 14px; /* Aumenta o padding interno */
    border: 1px solid #ccc;
    border-radius: 6px; /* Bordas mais arredondadas */
    background: #f9f9f9; /* Fundo mais claro */
    font-size: 15px; /* Fonte maior */
    width: 100%;
    color: #333;
    max-width: 100%;
    box-sizing: border-box;
    transition: border-color 0.3s, box-shadow 0.3s; /* Transição suave */
}
.nfse-data-item select.flat:focus {
    border-color: #007bff;
    outline: none;
    box-shadow: 0 0 0 3px rgba(0,123,255,0.25); /* Destaque ao focar */
}
.nfse-data-item textarea.flat {
    padding: 10px 14px; /* Aumenta o padding interno */
    border: 1px solid #ccc;
    border-radius: 6px; /* Bordas mais arredondadas */
    font-size: 15px; /* Fonte maior */
    resize: vertical;
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    font-family: inherit;
    background: #f9f9f9; /* Fundo mais claro */
    transition: border-color 0.3s, box-shadow 0.3s; /* Transição suave */
    
}
.nfse-data-item textarea.flat:focus {
    border-color: #007bff;
    outline: none;
    box-shadow: 0 0 0 3px rgba(0,123,255,0.25); /* Destaque ao focar */
}
.nfse-data-item {
    margin-bottom: 20px; /* Espaçamento entre os itens */
}
.nfse-data-item.full-width {
    grid-column: 1 / -1; /* Garante que ocupa toda a largura */
}

/* Tag de Status na Modal - Visual Melhorado */
.nfse-status-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.75em;
    font-weight: 500;
    color: #fff;
    margin-right: 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.15);
    border: 1px solid transparent;
    opacity: 0.85;
}
.nfse-status-tag.autorizada {
    background: linear-gradient(135deg, #6dc77a, #5cb85c);
    border-color: #4cae4c;
    color: #fff;
}
.nfse-status-tag.autorizada::after {
    content: "✓";
    font-size: 1em;
    font-weight: bold;
}
.nfse-status-tag.cancelada {
    background: linear-gradient(135deg, #d9534f, #c9302c);
    border-color: #ac2925;
    color: #fff;
}
.nfse-status-tag.cancelada::after {

    font-size: 1em;
    font-weight: bold;
}
.nfse-status-tag.rejeitada {
    background: linear-gradient(135deg, #f0ad4e, #ec971f);
    color: #8a6d3b;
    border-color: #d58512;
}
.nfse-status-tag.rejeitada::after {
    content: "⚠";
    font-size: 1em;
    font-weight: bold;
}
.nfse-status-tag.processando {
    background: linear-gradient(135deg, #777, #666);
    border-color: #555;
    color: #fff;
}
.nfse-status-tag.processando::after {
    content: "⏳";
    font-size: 0.9em;
}

/* ===== Paginação Customizada ===== */
.nfse-pagination-wrapper {
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
.nfse-pagination-info {
    font-size: 0.95em;
    color: #495057;
    font-weight: 500;
}
.nfse-pagination-controls {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.nfse-page-size-selector {
    display: flex;
    align-items: center;
    gap: 8px;
}
.nfse-page-size-selector label {
    font-size: 0.9em;
    color: #6c757d;
    white-space: nowrap;
}
.nfse-page-size-selector select {
    padding: 6px 12px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    background: white;
    font-size: 0.9em;
    cursor: pointer;
}
.nfse-page-nav {
    display: flex;
    gap: 5px;
}
.nfse-page-btn {
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
.nfse-page-btn:hover:not(.disabled) {
    background: #007bff;
    color: white;
    border-color: #007bff;
}
.nfse-page-btn.active {
    background: #007bff;
    color: white;
    border-color: #007bff;
    font-weight: bold;
}
.nfse-page-btn.disabled {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
}
.nfse-page-jump {
    display: flex;
    align-items: center;
    gap: 5px;
}
.nfse-page-jump input {
    width: 60px;
    padding: 6px 8px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    text-align: center;
    font-size: 0.9em;
}
.nfse-page-jump button {
    padding: 6px 12px;
    border: 1px solid #007bff;
    background: #007bff;
    color: white;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.9em;
}
@media (max-width: 768px) {
    .nfse-pagination-wrapper {
        flex-direction: column;
        align-items: stretch;
    }
    .nfse-pagination-controls {
        flex-direction: column;
    }
}

/* Normalize altura e paddings dos botões de ação para ficarem iguais */
.butAction, input.butAction, button.butAction {
    display: inline-block;
    font-size: 0.95em;
    line-height: 1.4;   /* garante altura visual consistente */
    height: auto;
    box-sizing: border-box;
    vertical-align: middle;
}

/* Específico para o botão de busca (input) */
input.search-button {
    font-size: 0.95em;
    line-height: 1.4;
    vertical-align: middle;
}

/* Pequeno ajuste caso algum estilo antigo force altura diferente */
input[type="submit"].butAction, button.butAction {
    min-height: 36px; /* garante mínimo visual coerente */
    height: auto;
}

/* ===== Fim da Normalização ===== */

/* Atualizar estilos para campos obrigatórios com erro */

.nfse-field-error {
    border: 2px solid #dc3545 !important;
    
}
.nfse-error-message {
    color: #dc3545;
    font-size: 0.85em; /* Reduz o tamanho da mensagem */
    margin-top: 2px; /* Reduz o espaçamento */
    display: block;
}

/* Ícone de engrenagem (SVG + acessibilidade) - nova paleta teal */
.nfse-config-wrap { display:inline-flex; align-items:center; margin-left:6px; }
.nfse-config-btn{
    background: transparent;
    border: 0;
    padding: 6px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    vertical-align: middle;
    transition: opacity .18s ease;
}
.nfse-config-btn:hover{
    opacity: 0.7;
}
.nfse-config-btn:focus{
    outline: none;
    box-shadow: 0 0 0 4px rgba(26,188,156,0.12);
}
.nfse-config-icon {
    display:inline-block;
    width:20px; height:20px;
}
.nfse-config-icon path { fill: #2c7a7b; transition: fill .18s ease; }
.nfse-config-btn:hover .nfse-config-icon path { fill: #1abc9c; }

/* Modal de Configurações - paleta teal */
.nfse-modal-configuracoes{ max-width: 880px !important; width: 96% !important; }
.nfse-modal.nfse-modal-configuracoes .nfse-modal-header{
    background: linear-gradient(135deg,#16a085,#1abc9c);
    color: #fff; border-bottom: none;
}
.nfse-modal.nfse-modal-configuracoes .nfse-modal-header strong{ color: #fff; }
.nfse-modal.nfse-modal-configuracoes .nfse-modal-body{
    background: #f6fffb;
}

/* Layout do conteúdo de setup dentro da modal (teal) */
.nfse-setup .nfse-card{
    border: 1px solid #e5f2ef; border-radius: 10px; overflow: hidden;
    box-shadow: 0 6px 16px rgba(0,0,0,0.06); background: #fff;
}
.nfse-setup .nfse-card-header{
    display:flex; align-items:center; gap:8px;
    background: #eafaf7; border-bottom: 1px solid #d8eee9; color:#2f4f4f;
    font-weight: 700;
}
.nfse-setup .nfse-card-header .icon svg{ width:22px; height:22px; fill:#16a085; }
.nfse-setup .nfse-card-body{ padding: 16px; background: #fff; }
.nfse-setup .nfse-data-grid-2{
    display:grid; grid-template-columns: repeat(auto-fit, minmax(240px,1fr)); gap:14px 18px;
}
.nfse-setup .nfse-data-item .nfse-data-label{
    font-size:.85em; color:#64748b; font-weight:600; text-transform:none; margin-bottom:4px;
}
.nfse-setup input.flat{
    width:100%; padding:10px 12px; border:1px solid #cfe6e2; border-radius:6px; background:#fff; box-sizing:border-box;
    transition: border-color .2s, box-shadow .2s;
}
.nfse-setup input.flat:focus{
    border-color:#1abc9c; box-shadow:0 0 0 3px rgba(26,188,156,.18); outline:none;
}
.nfse-setup .actions{ text-align:center; margin-top:16px; display:flex; gap:10px; justify-content:center; flex-wrap:wrap; }

/* NOVO: Popover compacto para filtro de status */
.status-filter-cell{ position: relative; }
.nfse-status-filter-toggle{
    padding: 6px 12px;         /* aumentado para melhor clique */
    border: 1px solid #cfe6e2;
    background: #fff;
    color: #2c7a7b;
    border-radius: 16px;
    cursor: pointer;
    font-size: .88em;          /* ligeiramente maior */
    line-height: 1.3;
    display: inline-flex;
    align-items: center;
    gap: 6px;                  /* aumentado */
}
.nfse-status-filter-toggle:hover{ background:#f0fbf8; border-color:#1abc9c; }
.nfse-status-filter-toggle .badge{
    display: inline-block;
    min-width: 18px;
    padding: 2px 6px;
    border-radius: 10px;
    background: #1abc9c;
    color: #fff;
    font-size: .78em;
}

/* Popover com melhor espaçamento interno */
.nfse-status-filter-popover{
    position: absolute !important;
    top: calc(100% + 6px);
    left: 0;
    right: auto;
    transform: none;
    z-index: 9999;
    background: #fff;
    border: 1px solid #e5f2ef;
    box-shadow: 0 8px 20px rgba(0,0,0,.12);
    border-radius: 8px;
    padding: 10px 12px;        /* aumentado */
    width: max-content;
    max-width: 360px;
    box-sizing: border-box;
    display: none;
}
.nfse-status-filter-popover.visible{ display:block; }

/* Opções com melhor espaçamento entre linhas */
.nfse-status-filter-popover .opt{
    display: flex;
    align-items: center;
    gap: 8px;                  /* aumentado */
    padding: 6px 8px;          /* aumentado */
    border-radius: 6px;
    cursor: pointer;
    transition: background .12s ease;
    line-height: 1.3;
}
.nfse-status-filter-popover .opt + .opt{ margin-top: 4px; } /* aumentado */
.nfse-status-filter-popover .opt:hover{ background: rgba(22,160,133,0.06); }

/* Checkbox e chip mantidos compactos */
.nfse-status-filter-popover .opt input[type="checkbox"]{
    width: 14px;
    height: 14px;
    accent-color: #16a085;
    border-radius: 3px;
    margin: 0 2px 0 0;
    flex: 0 0 auto;
}
.nfse-status-filter-popover .opt .nfse-chip{
    width: 8px;
    height: 8px;
    border-radius: 50%;
    box-shadow: inset 0 -1px 0 rgba(0,0,0,0.05);
    flex: 0 0 auto;
}
.nfse-status-filter-popover .opt input[type="checkbox"]:checked + .nfse-chip{
    box-shadow: none !important;
}

/* Foco acessível */
.nfse-status-filter-popover .opt:focus-within{
    outline: 2px solid rgba(26,188,156,0.2);
    outline-offset: 2px;
}

/* Botões da área de ações com melhor espaçamento */
.nfse-status-filter-popover .actions{
    display: flex;
    gap: 8px;                  /* aumentado */
    justify-content: flex-end;
    margin-top: 10px;          /* aumentado */
    padding-top: 8px;          /* adiciona separação visual */
    border-top: 1px solid #f0f0f0; /* linha sutil de separação */
}
.nfse-status-filter-popover .actions .butAction,
.nfse-status-filter-popover .actions .butActionDelete{
    padding: 5px 10px;         /* aumentado */
    font-size: .86em;
    line-height: 1.3;
    border-radius: 14px;
}

/* Realce leve quando marcado */
@supports selector(label:has(input:checked)){
    .nfse-status-filter-popover .opt:has(input:checked){
        background: rgba(22,160,133,0.08);
        outline: 1px solid #bfece0;
    }
}

/* Novo estilo para botão de configurações: apenas ícone, sem fundo */
.nfse-config-container {
    display: inline-flex;
    align-items: center;
    margin-left: 8px;
    vertical-align: middle; /* Melhor alinhamento vertical */
}
.nfse-config-button {
    background: transparent;
    border: none;
    padding: 6px; /* Aumentado para melhor clique */
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    vertical-align: middle;
    transition: opacity 0.2s ease;
}
.nfse-config-button:hover {
    opacity: 0.7;
}
.nfse-config-button:focus {
    outline: none;
    box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.25);
}
.nfse-config-fa-icon {
    font-size: 24px; /* Aumentado de 18px para 24px */
    color: #6c757d;
}
.nfse-config-button:hover .nfse-config-fa-icon {
    color: #495057;
}

/* ...existing styles... */
</style>';

// Cabeçalho tabela e filtros
print '<div class="table-responsive-wrapper">';
// NOVO: adiciona id ao form para facilitar submit via JS
print '<form method="GET" id="nfseListFilterForm" action="'.$_SERVER["PHP_SELF"].'">';
print '<table class="liste noborder centpercent">';
print '<tr class="liste_titre">';

// SUBSTITUI o título simples por HTML com tooltip e bolinhas
$status_header_html = '
<div class="status-header-wrapper" tabindex="0" aria-label="'.dol_escape_htmltag($langs->trans("Legenda de status")).'">
  <span class="status-title">'.$langs->trans("Status").'</span>
  
  </div>
</div>';

print_liste_field_titre($status_header_html, $_SERVER["PHP_SELF"], "status", "", "", 'align="center"', $sortfield, $sortorder);
print_liste_field_titre('Fatura', $_SERVER["PHP_SELF"], "id_fatura", "", "", 'align="center"', $sortfield, $sortorder);
print_liste_field_titre('Nº NFS-e', $_SERVER["PHP_SELF"], "numero_nfse", "", "", 'align="center"', $sortfield, $sortorder);
print_liste_field_titre('Tomador', $_SERVER["PHP_SELF"], "tomador_nome", "", "", 'align="center"', $sortfield, $sortorder);
print_liste_field_titre('Emissão', $_SERVER["PHP_SELF"], "data_emissao", "", "", 'align="center"', $sortfield, $sortorder);
print_liste_field_titre('Valor', $_SERVER["PHP_SELF"], "valor_servicos", "", "", 'align="center right"', $sortfield, $sortorder);
$acoes_header = 'Ações';
print_liste_field_titre('Ações', '', '', '', '', 'align="center"');
print "</tr>";

// Linha de filtros
print '<tr class="liste_titre_filter">';

// NOVO: filtro Status com botão e popover
// prepara seleção atual para marcar checkboxes e badge
$selectedStatusesPhp = !empty($selectedStatuses) ? $selectedStatuses : array();
$selectedStatusListStr = dol_escape_htmltag(implode(',', $selectedStatusesPhp));
$badgeCount = count($selectedStatusesPhp);
print '<td class="center status-filter-cell">';
print '<input type="hidden" name="search_status_list" id="search_status_list" value="'.$selectedStatusListStr.'">';
print '<button type="button" class="nfse-status-filter-toggle" onclick="toggleStatusFilter(event)" aria-expanded="false" title="Filtrar Status">Filtrar'.($badgeCount? ' <span id="statusSelCount" class="badge">'.$badgeCount.'</span>':'').'</button>';
print '<div class="nfse-status-filter-popover" id="statusFilterPopover">';
// opções (NFS-e Nacional)
$opts = array(
    'autorizada' => 'Autorizada',
    'rejeitada' => 'Rejeitada',
    'cancelada' => 'Cancelada',
);
foreach ($opts as $key => $label) {
    $checked = in_array($key, $selectedStatusesPhp, true) ? ' checked' : '';
    print '<label class="opt"><input type="checkbox" value="'.$key.'"'.$checked.'> <span class="nfse-chip '.$key.'"></span> '.$label.'</label>';
}
print '<div class="actions">';
print '<button type="button" class="butActionDelete" onclick="clearStatusFilter()">Limpar</button>';
print '<button type="button" class="butAction" onclick="applyStatusFilter()">Aplicar</button>';
print '</div></div>';
print '</td>';

// ...existing code for the remaining filter cells...
print '<td class="center"><input type="text" name="search_fk_facture" value="'.dol_escape_htmltag($search_fk_facture).'" class="flat" size="6"></td>';
print '<td class="center">';
print '<input type="text" name="search_numero_nfse_start" value="'.dol_escape_htmltag($search_numero_nfse_start).'" class="flat" size="6" placeholder="De"> - ';
print '<input type="text" name="search_numero_nfse_end" value="'.dol_escape_htmltag($search_numero_nfse_end).'" class="flat" size="6" placeholder="Até">';
print '</td>';
print '<td class="center"><input type="text" name="search_client_name" value="'.dol_escape_htmltag($search_client_name).'" class="flat" size="20" placeholder="Nome/Razão do Tomador"></td>';
print '<td class="center">';
print '<input type="date" name="search_data_emissao_start" value="'.dol_escape_htmltag($search_data_emissao_start).'" class="flat"> - ';
print '<input type="date" name="search_data_emissao_end" value="'.dol_escape_htmltag($search_data_emissao_end).'" class="flat">';
print '</td>';
print '<td class="center"></td>';
print '<td class="center">';
print '<input type="submit" class="butAction search-button" value="'.$langs->trans("Search").'"> ';
// Monta URL com filtros para download em lote
$batchUrl = DOL_URL_ROOT.'/custom/nfse/download_nfse_nacional_xml.php?action=batch';
$batchUrl .= '&search_status_list='.urlencode($search_status_list);
$batchUrl .= '&search_fk_facture='.urlencode($search_fk_facture);
$batchUrl .= '&search_numero_nfse_start='.urlencode($search_numero_nfse_start);
$batchUrl .= '&search_numero_nfse_end='.urlencode($search_numero_nfse_end);
$batchUrl .= '&search_client_name='.urlencode($search_client_name);
$batchUrl .= '&search_data_emissao_start='.urlencode($search_data_emissao_start);
$batchUrl .= '&search_data_emissao_end='.urlencode($search_data_emissao_end);
print '<a href="'.$batchUrl.'" class="butAction" target="_blank">'.$langs->trans("Baixar em Lote").'</a>';
print '</td>';
print '</tr>';

// Linhas
if($num >0){
    $i = 0;
    while ($i < min($num, $limit)) {
        $obj = $db->fetch_object($res);

        // Determina classe e bolinha de status (NFS-e Nacional)
        $status = strtolower((string)$obj->status);
        $statusClass = '';
        $statusCircle = '<span class="status-circle status-circle-gray" title="'.dol_escape_htmltag($status).'"></span>';

        if ($status === 'autorizada') {
            $statusClass = 'row-status-autorizada';
            $statusCircle = '<span class="status-circle status-circle-green" title="AUTORIZADA"></span>';
        } elseif ($status === 'cancelada') {
            $statusClass = 'row-status-cancelada';
            $statusCircle = '<span class="status-circle status-circle-red" title="CANCELADA"></span>';
        } elseif ($status === 'rejeitada' || $status === 'erro') {
            $statusClass = 'row-status-rejeitada';
            $statusCircle = '<span class="status-circle status-circle-yellow" title="'.strtoupper($status).'"></span>';
        } elseif ($status === 'pendente' || $status === 'enviando') {
            $statusCircle = '<span class="status-circle status-circle-yellow" title="'.strtoupper($status).'"></span>';
        }

        // Habilita/desabilita botões (NFS-e Nacional - sem substituição)
        $canCancel = ($status === 'autorizada');
        $canViewDanfse = (!empty($obj->xml_nfse_size) && $obj->xml_nfse_size > 0);
        
        $cancelClass = $canCancel ? '' : 'disabled-visual';
        $danfseClass = $canViewDanfse ? '' : 'disabled-visual';
        $cancelOnClick = $canCancel ? 'onclick="openNfseCancelamento('.(int)$obj->id.');return false;"' : 'onclick="alert(\'Apenas notas autorizadas podem ser canceladas\'); return false;"';

        // Datas e valores (NFS-e Nacional)
        $ts = !empty($obj->data_emissao) ? strtotime($obj->data_emissao) : 0;
        if ($ts <= 0 && !empty($obj->data_hora_autorizacao)) {
            $ts = $db->jdate($obj->data_hora_autorizacao);
        }
        $valor = price($obj->valor_servicos);

        // Links
        $urlfac = dol_buildpath('/compta/facture/card.php', 1).'?id='.(int)$obj->id_fatura;
        $urlDownloadXml = dol_buildpath('/custom/nfse/download_nfse_nacional_xml.php', 1).'?action=single&id='.(int)$obj->id;
        // $urlConsultar = dol_buildpath('/custom/nfse/view_nfse_nacional_xml.php', 1).'?id='.(int)$obj->id;
        
        // Uso temporario do consulta_danfse.php para PDF e View conforme solicitado
        $urlDanfsePdf = dol_buildpath('/custom/nfse/consulta_danfse.php', 1).'?id='.(int)$obj->id.'&action=download';
        $urlDanfseView = dol_buildpath('/custom/nfse/consulta_danfse.php', 1).'?id='.(int)$obj->id.'&action=view';

        // NFSe display (Nacional: DPS → NFS-e)
        $numeroDps = $obj->numero_dps ? dol_escape_htmltag($obj->numero_dps) : '-';
        $numeroNfse = $obj->numero_nfse ? dol_escape_htmltag($obj->numero_nfse) : '-';
        $serie = $obj->serie ? dol_escape_htmltag($obj->serie) : '1';
        
        // Se tiver NFS-e, mostra em destaque. Se não, mostra a DPS
        if ($numeroNfse !== '-') {
             $nfseDisplay = '<span style="">'.$numeroNfse.'</span>';
             // Tooltip com detalhes da DPS
             $nfseDisplay .= ' <span class="" title="DPS: '.$numeroDps.' / Série: '.$serie.'" style="font-size:0.8em; color:#888;"></span>';
        } else {
             $nfseDisplay = '<span style="color:#666;">DPS: '.$numeroDps . '/' . $serie.'</span>';
        }

        print '<tr class="oddeven '.$statusClass.'" data-id="'.(int)$obj->id.'">';
        print '<td class="center">'.$statusCircle.'</td>';
        print '<td class="center"><a href="'.$urlfac.'">#'.(int)$obj->id_fatura.'</a></td>';
        print '<td class="center">'.$nfseDisplay.'</td>';
        print '<td class="center">'.dol_escape_htmltag($obj->tomador_nome).'</td>';
        print '<td class="center">'.dol_print_date($ts, 'day').'</td>';
        print '<td class="right">' . 'R$ ' .$valor.'</td>';

        // Dropdown com ações: Consultar, Cancelar, Baixar XML, Visualizar DANFSe, Baixar DANFSe
        print '<td class="center actions-cell"><div class="nfe-dropdown">';
        print '<button class="butAction dropdown-toggle" type="button" onclick="toggleDropdown(event, \'nfeDropdownMenu'.$obj->id.'\')">'.$langs->trans("Ações").'</button>';
        print '<div class="nfe-dropdown-menu" id="nfeDropdownMenu'.$obj->id.'">';
        print '<a class="nfe-dropdown-item" href="#" onclick="openNfseConsulta('.(int)$obj->id.');return false;">'.$langs->trans("Consultar").'</a>';
        print '<a class="nfe-dropdown-item '.$cancelClass.'" href="#" '.$cancelOnClick.'>'.$langs->trans("Cancelar").'</a>';
        print '<a class="nfe-dropdown-item" href="'.$urlDownloadXml.'" target="_blank">Baixar XML</a>';
        
        // DANFSE - sempre clicável, com tratamento JavaScript se não disponível
        if ($canViewDanfse) {
            print '<a class="nfe-dropdown-item" href="'.$urlDanfseView.'" target="_blank">Visualizar DANFSe</a>';
            print '<a class="nfe-dropdown-item" href="'.$urlDanfsePdf.'" target="_blank">Baixar DANFSe (PDF)</a>';
        } else {
            print '<a class="nfe-dropdown-item '.$danfseClass.'" href="#" onclick="alert(\'DANFSe não disponível. Nota deve estar autorizada.\'); return false;">Visualizar DANFSe</a>';
            print '<a class="nfe-dropdown-item '.$danfseClass.'" href="#" onclick="alert(\'DANFSe não disponível. Nota deve estar autorizada.\'); return false;">Baixar DANFSe (PDF)</a>';
        }
        print '</div></div></td>';

        print '</tr>';
        $i++;
    }
}else{
    print '<tr><td colspan="7" class="opacitymedium center">Nenhum registro encontrado.</td></tr>';
}

print '</table>';
print '</form>';
print '</div>';

// Substituir o bloco antigo de contagem + print_barre_liste por paginação customizada:
// Ajusta filtros para propagarem a nova chave no buildURL
$filters = [
    'search_status_list' => $search_status_list, // NOVO

    'search_fk_facture' => $search_fk_facture,
    'search_numero_nfse_start' => $search_numero_nfse_start,
    'search_numero_nfse_end' => $search_numero_nfse_end,
    'search_client_name' => $search_client_name,
    'search_data_emissao_start' => $search_data_emissao_start,
    'search_data_emissao_end' => $search_data_emissao_end,
    'sortfield' => $sortfield,
    'sortorder' => $sortorder
];

if (!function_exists('nfse_buildURL')) {
    function nfse_buildURL($page, $limitVal, $filtersArray) {
        $params = array_merge(array_filter($filtersArray), [
            'page' => $page,
            'limit' => $limitVal
        ]);
        return $_SERVER["PHP_SELF"] . '?' . http_build_query($params);
    }
}

// Renderiza paginação customizada
$totalPages = ($total_rows > 0) ? ceil($total_rows / $limit) : 1;
$currentPage = $page + 1;
$startRecord = ($total_rows > 0) ? ($offset + 1) : 0;
$endRecord = min($offset + $limit, $total_rows);

print '<div class="nfse-pagination-wrapper">';

// Info de registros
print '<div class="nfse-pagination-info">';
print 'Mostrando <strong>'.$startRecord.'</strong> a <strong>'.$endRecord.'</strong> de <strong>'.$total_rows.'</strong> registros';
print '</div>';

print '<div class="nfse-pagination-controls">';

// Seletor de tamanho de página
print '<div class="nfse-page-size-selector">';
print '<label>Por página:</label>';
print '<select onchange="window.location.href=this.value">';
foreach ($limitOptions as $opt) {
    $selected = ($opt == $limit) ? ' selected' : '';
    $url = nfse_buildURL(0, $opt, $filters);
    print '<option value="'.$url.'"'.$selected.'>'.$opt.'</option>';
}
print '</select>';
print '</div>';

// Navegação de páginas
print '<div class="nfse-page-nav">';

// Botão Anterior
$prevPage = max(0, $page - 1);
$prevDisabled = ($page == 0) ? 'disabled' : '';
$prevUrl = nfse_buildURL($prevPage, $limit, $filters);
print '<a href="'.$prevUrl.'" class="nfse-page-btn '.$prevDisabled.'">‹ Anterior</a>';

// Páginas numeradas (mostra até 5 ao redor da atual)
$startPage = max(0, $page - 2);
$endPage = min($totalPages - 1, $page + 2);

if ($startPage > 0) {
    $firstUrl = nfse_buildURL(0, $limit, $filters);
    print '<a href="'.$firstUrl.'" class="nfse-page-btn">1</a>';
    if ($startPage > 1) print '<span class="nfse-page-btn disabled">...</span>';
}

for ($p = $startPage; $p <= $endPage; $p++) {
    $pageUrl = nfse_buildURL($p, $limit, $filters);
    $activeClass = ($p == $page) ? 'active' : '';
    print '<a href="'.$pageUrl.'" class="nfse-page-btn '.$activeClass.'">'.($p + 1).'</a>';
}

if ($endPage < $totalPages - 1) {
    if ($endPage < $totalPages - 2) print '<span class="nfse-page-btn disabled">...</span>';
    $lastUrl = nfse_buildURL($totalPages - 1, $limit, $filters);
    print '<a href="'.$lastUrl.'" class="nfse-page-btn">'.$totalPages.'</a>';
}

// Botão Próximo
$nextPage = min($totalPages - 1, $page + 1);
$nextDisabled = ($page >= $totalPages - 1) ? 'disabled' : '';
$nextUrl = nfse_buildURL($nextPage, $limit, $filters);
print '<a href="'.$nextUrl.'" class="nfse-page-btn '.$nextDisabled.'">Próximo ›</a>';

print '</div>'; // .nfse-page-nav

// Salto direto para página
if ($totalPages > 5) {
    print '<div class="nfse-page-jump">';
    print '<span>Ir para:</span>';
    print '<input type="number" id="nfseJumpPage" min="1" max="'.$totalPages.'" value="'.$currentPage.'">';
    print '<button onclick="jumpToPageNfse('.$totalPages.', '.$limit.')">Ir</button>';
    print '</div>';
}

print '</div>'; // .nfse-pagination-controls
print '</div>'; // .nfse-pagination-wrapper

// JS para controle de paginação
$filtersJson = json_encode($filters);
print '<script>
function jumpToPageNfse(totalPages, currentLimit) {
    var input = document.getElementById("nfseJumpPage");
    var pageNum = parseInt(input.value);
    if (!pageNum || pageNum < 1 || pageNum > totalPages) {
        alert("Página inválida. Digite um número entre 1 e " + totalPages);
        return;
    }
    var filters = '.$filtersJson.';
    filters.page = pageNum - 1;
    filters.limit = currentLimit;
    window.location.href = "' . $_SERVER["PHP_SELF"] . '?" + new URLSearchParams(Object.entries(filters).filter(([k,v]) => v)).toString();
}
</script>';

// Modal HTML
print '<div id="nfseModal" class="nfse-modal-overlay" role="dialog" aria-modal="true" aria-hidden="true">

  <div class="nfse-modal nfse-modal-consulta">
    <div class="nfse-modal-header">
      <strong id="nfseModalTitle">Consulta de NFS-e</strong>
      <div style="display: flex; align-items: center;">
        <span id="nfseStatusTag" class="nfse-status-tag" style="display: none;"></span>
        <button class="nfse-modal-close" onclick="closeNfseModal()" aria-label="Fechar">&times;</button>
      </div>
    </div>
    <div id="nfseModalBody" class="nfse-modal-body">
      Carregando...
    </div>
  </div>
</div>';

// Modal de Confirmação usando padrões do Dolibarr
print '<div id="nfseConfirmModal" class="nfse-confirm-overlay" role="dialog" aria-modal="true">
  <div class="center" style="background: white; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.2); max-width: 400px; padding: 20px;">
    <div class="titre" style="font-size: 1.2em; font-weight: bold; margin-bottom: 15px;">Confirmação de Cancelamento</div>
    <div style="font-size: 0.95em; color: #333; margin-bottom: 15px;">
      Tem certeza de que deseja cancelar esta NFSe? Esta operação não poderá ser desfeita.
    </div>
    <div class="opacitymedium" style="font-size: 0.85em; color: #666; margin-bottom: 20px;">
      Confirme para prosseguir ou clique em "Fechar" para voltar.
    </div>
    <div class="center" style="display: flex; justify-content: center; gap: 10px;">
      <button class="butActionDelete" onclick="closeConfirmModal()">Fechar</button>
      <button class="butAction" onclick="confirmarCancelamento()">Confirmar</button>
    </div>
  </div>
</div>';

// Nova modal de confirmação para substituição
print '<div id="nfseConfirmSubstituicaoModal" class="nfse-confirm-overlay" role="dialog" aria-modal="true">
  <div class="center" style="background: white; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.2); max-width: 400px; padding: 20px;">
    <div class="titre" style="font-size: 1.2em; font-weight: bold; margin-bottom: 15px;">Confirmação de Substituição</div>
    <div style="font-size: 0.95em; color: #333; margin-bottom: 15px;">
      Tem certeza de que deseja substituir esta NFSe? Esta operação cancelará a NFSe original e gerará uma nova.
    </div>
    <div class="opacitymedium" style="font-size: 0.85em; color: #666; margin-bottom: 20px;">
      Confirme para prosseguir ou clique em "Fechar" para voltar.
    </div>
    <div class="center" style="display: flex; justify-content: center; gap: 10px;">
      <button class="butActionDelete" onclick="closeConfirmSubstituicaoModal()">Fechar</button>
      <button class="butAction" onclick="confirmarSubstituicao()">Confirmar</button>
    </div>
  </div>
</div>';

print '<script>
/* Reativa dropdowns de Ações */
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

// Fecha dropdowns ao clicar fora ou pressionar ESC
document.addEventListener("click", function(e) {
    if (!e.target.closest(".nfe-dropdown")) {
        document.querySelectorAll(".nfe-dropdown-menu").forEach(function(m){
            m.style.display = "none";
        });
    }
});
document.addEventListener("keydown", function(e) {
    if (e.key === "Escape" || e.key === "Esc") {
        document.querySelectorAll(".nfe-dropdown-menu").forEach(function(m){
            m.style.display = "none";
        });
    }
});

/* Modal e operações (reaproveita os mesmos endpoints do arquivo) */
function openNfseModal(title) {
  var el = document.getElementById("nfseModal");
  var titleEl = document.getElementById("nfseModalTitle");
  var statusTag = document.getElementById("nfseStatusTag");
  if (titleEl && title) titleEl.textContent = title;
  if (statusTag) { statusTag.style.display = "none"; statusTag.className = "nfse-status-tag"; statusTag.textContent = ""; }
  if (el) { el.classList.add("visible"); el.setAttribute("aria-hidden","false"); }
}
function closeNfseModal() {
  var el = document.getElementById("nfseModal");
  if (el) { el.classList.remove("visible"); el.setAttribute("aria-hidden","true"); }
}

// Função de sincronização de eventos com SEFAZ (Fase 2)
function sincronizarEventosComSEFAZ(nfseId) {
  
  var btnSync = document.getElementById("btnSincronizar");
  var statusDiv = document.getElementById("syncStatus");
  
  if (!btnSync || !statusDiv) {
    console.error("[SYNC] Elementos não encontrados no DOM");
    alert("Erro: Elementos da interface não encontrados. Tente recarregar a página.");
    return;
  }
  
  btnSync.disabled = true;
  statusDiv.className = "nfse-sync-status nfse-sync-loading";
  statusDiv.innerHTML = "⏳ Sincronizando com gov.br...";
  statusDiv.style.display = "inline-block";
  
  var url = "'.DOL_URL_ROOT.'/custom/nfse/reconciliar_eventos_nfse.php?id=" + nfseId;
  
  fetch(url, {
    method: "GET",
    headers: {
      "Content-Type": "application/json"
    },
    credentials: "same-origin"
  })
  .then(function(response) {
    if (!response.ok) {
      throw new Error("HTTP " + response.status + ": " + response.statusText);
    }
    
    return response.text();
  })
  .then(function(text) {
    try {
      var data = JSON.parse(text);
      
      if (data.sucesso) {
        statusDiv.className = "nfse-sync-status nfse-sync-success";
        statusDiv.innerHTML = "✓ " + data.mensagem;
        // Mantém o botão habilitado para nova sincronização se necessário
        btnSync.disabled = false;
      } else {
        statusDiv.className = "nfse-sync-status nfse-sync-error";
        statusDiv.innerHTML = "✗ Erro: " + (data.erro || "Falha na sincronização");
        btnSync.disabled = false;
      }
    } catch (parseError) {
      statusDiv.className = "nfse-sync-status nfse-sync-error";
      statusDiv.innerHTML = "✗ Resposta inválida do servidor";
      btnSync.disabled = false;
    }
  })
  .catch(function(error) {
    statusDiv.className = "nfse-sync-status nfse-sync-error";
    statusDiv.innerHTML = "✗ Erro: " + error.message;
    btnSync.disabled = false;
  });
}

function openNfseConsulta(id) {

  document.querySelectorAll(".nfe-dropdown-menu").forEach(m => m.style.display = "none");
  var body = document.getElementById("nfseModalBody");
  if (body) body.innerHTML = "Carregando...";
  var modal = document.getElementById("nfseModal").querySelector(".nfse-modal");
  if (modal) modal.className = "nfse-modal nfse-modal-consulta";
  openNfseModal("Consulta de NFS-e");
  var url = "'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'?action=consultar&id=" + encodeURIComponent(id);
  fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest" } })
    .then(function(r){ 
    
      return r.text(); 
    })
    .then(function(html){
      var bodyEl = document.getElementById("nfseModalBody");
      if (bodyEl) bodyEl.innerHTML = html;
      var statusElement = document.querySelector("tr[data-id=\"" + id + "\"] .status-circle");
      var status = "processando";
    
    })
    .catch(function(err){
      var bodyEl = document.getElementById("nfseModalBody");
      if (bodyEl) bodyEl.innerHTML = "<div class=\\"nfse-alert nfse-alert-error\\">Falha ao consultar: "+ (err && err.message ? err.message : "erro desconhecido") +"</div>";
    });
}

// Função para buscar eventos na API SEFAZ e inserir no banco local
function buscarEventosSEFAZ(nfseId) {
  var btnBuscar = document.getElementById("btnBuscarEventos");
  var statusDiv = document.getElementById("buscarEventosStatus");
  var containerEventos = document.getElementById("nfseEventosBuscados");
  
  if (!btnBuscar || !statusDiv) {
    console.error("[BUSCAR_EVENTOS] Elementos não encontrados no DOM");
    alert("Erro: Elementos da interface não encontrados. Tente recarregar a página.");
    return;
  }
  
  // Desabilita botão e mostra loading
  btnBuscar.disabled = true;
  btnBuscar.style.opacity = "0.6";
  statusDiv.className = "nfse-sync-status nfse-sync-loading";
  statusDiv.innerHTML = "⏳ Consultando API...";
  statusDiv.style.display = "inline-block";
  
  var url = "'.DOL_URL_ROOT.'/custom/nfse/buscar_eventos_nfse.php?id=" + nfseId;
  
  fetch(url, {
    method: "GET",
    headers: { "Content-Type": "application/json" },
    credentials: "same-origin"
  })
  .then(function(response) {
    if (!response.ok) {
      throw new Error("HTTP " + response.status + ": " + response.statusText);
    }
    return response.text();
  })
  .then(function(text) {
    try {
      var data = JSON.parse(text);
      
      if (data.sucesso) {
        statusDiv.className = "nfse-sync-status nfse-sync-success";
        statusDiv.innerHTML = "✓ " + data.mensagem;
        btnBuscar.disabled = false;
        btnBuscar.style.opacity = "1";
        
        // Se encontrou eventos, renderiza na tela
        if (data.eventos && data.eventos.length > 0 && containerEventos) {
          var html = renderizarEventosBuscados(data);
          containerEventos.innerHTML = html;
          containerEventos.style.display = "block";
        }
        
        // Se o status da nota foi atualizado, avisa e recarrega a lista ao fechar
        if (data.status_nota_atualizado) {
          statusDiv.innerHTML += "";
        }
      } else {
        statusDiv.className = "nfse-sync-status nfse-sync-error";
        statusDiv.innerHTML = "✗ Erro: " + (data.erro || "Falha na busca");
        btnBuscar.disabled = false;
        btnBuscar.style.opacity = "1";
      }
    } catch (parseError) {
      console.error("[BUSCAR_EVENTOS] Erro ao parsear resposta:", text);
      statusDiv.className = "nfse-sync-status nfse-sync-error";
      statusDiv.innerHTML = "✗ Resposta inválida do servidor";
      btnBuscar.disabled = false;
      btnBuscar.style.opacity = "1";
    }
  })
  .catch(function(error) {
    console.error("[BUSCAR_EVENTOS] Erro fetch:", error);
    statusDiv.className = "nfse-sync-status nfse-sync-error";
    statusDiv.innerHTML = "✗ Erro: " + error.message;
    btnBuscar.disabled = false;
    btnBuscar.style.opacity = "1";
  });
}

// Renderiza HTML da tabela de eventos buscados
function renderizarEventosBuscados(data) {
  var html = "<div class=\\"nfse-section\\" style=\\"margin-top:15px;\\">";
  html += "<div class=\\"nfse-section-header\\">Eventos Encontrados na API";
  html += "<span style=\\"background:#17a2b8; color:white; padding:2px 6px; border-radius:3px; font-size:10px; margin-left:8px;\\">API</span>";
  html += "</div>";
  
  // Info resumo
  html += "<div style=\\"background:#f9f9f9; padding: 8px 15px; border-bottom:1px solid #eee; font-size:11px; color:#666;\\">";
  html += "<b>Total encontrado:</b> " + data.total_api + " evento(s) | ";
  if (data.novos_inseridos > 0) {
    html += "<b style=\\"color:#28a745;\\">Novos inseridos:</b> " + data.novos_inseridos + " | ";
  }
  if (data.ja_existentes > 0) {
    html += "<b>Já existentes:</b> " + data.ja_existentes + " | ";
  }
  html += "<b>Consultado em:</b> " + new Date().toLocaleString("pt-BR");
  html += "</div>";
  
  // Tabela
  html += "<table class=\\"nfse-table\\">";
  html += "<tr style=\\"background:#f5f5f5; font-size:11px; text-transform:uppercase;\\">";
  html += "<td style=\\"border-bottom:1px solid #ddd; padding:8px;\\"><b>Tipo Evento</b></td>";
  html += "<td style=\\"border-bottom:1px solid #ddd; padding:8px;\\"><b>Data/Hora</b></td>";
  html += "<td style=\\"border-bottom:1px solid #ddd; padding:8px;\\"><b>Status</b></td>";
  html += "<td style=\\"border-bottom:1px solid #ddd; padding:8px;\\"><b>Origem</b></td>";
  html += "</tr>";
  
  for (var i = 0; i < data.eventos.length; i++) {
    var evt = data.eventos[i];
    var statusColor = evt.status === "Registrado" ? "#28a745" : "#6c757d";
    var origemBadge = evt.origem === "novo" 
      ? "<span style=\\"background:#28a745; color:white; padding:1px 5px; border-radius:3px; font-size:10px;\\">NOVO</span>"
      : "<span style=\\"background:#6c757d; color:white; padding:1px 5px; border-radius:3px; font-size:10px;\\">EXISTENTE</span>";
    
    var dhFormatada = "-";
    if (evt.data_hora) {
      try {
        var d = new Date(evt.data_hora);
        if (!isNaN(d.getTime())) {
          dhFormatada = d.toLocaleString("pt-BR");
        } else {
          dhFormatada = evt.data_hora;
        }
      } catch(e) {
        dhFormatada = evt.data_hora;
      }
    }
    
    html += "<tr>";
    html += "<td><span class=\\"nfse-value\\">" + evt.tipo_descricao + " (" + evt.tipo_evento + ")</span></td>";
    html += "<td><span class=\\"nfse-value\\">" + dhFormatada + "</span></td>";
    html += "<td><span class=\\"nfse-value\\" style=\\"color:" + statusColor + ";\\">" + (evt.status === "Registrado" ? "✓ " : "") + evt.status + "</span></td>";
    html += "<td class=\\"center\\">" + origemBadge + "</td>";
    html += "</tr>";
    
    if (evt.descricao_motivo) {
      html += "<tr style=\\"background:#fafafa;\\">";
      html += "<td colspan=\\"4\\" style=\\"padding:6px 10px 6px 20px; font-size:11px; color:#666;\\">";
      html += "<b>Motivo:</b> " + evt.descricao_motivo;
      html += "</td>";
      html += "</tr>";
    }
  }
  
  html += "</table>";
  html += "</div>";
  
  return html;
}

function openNfseCancelamento(id) {
  document.querySelectorAll(".nfe-dropdown-menu").forEach(m => m.style.display = "none");
  var body = document.getElementById("nfseModalBody");
  if (body) body.innerHTML = "<div class=\\"nfse-loading-container\\"><div class=\\"nfse-spinner\\"></div><p>Carregando formulário de cancelamento...</p></div>";
  var modal = document.getElementById("nfseModal").querySelector(".nfse-modal");
  if (modal) modal.className = "nfse-modal nfse-modal-cancelamento";
  openNfseModal("Cancelar NFSe");
  var url = "'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'?action=mostrar_cancelamento&id=" + encodeURIComponent(id);
  fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest" } })
    .then(function(r){ return r.text(); })
    .then(function(html){
      var bodyEl = document.getElementById("nfseModalBody");
      if (bodyEl) bodyEl.innerHTML = html;
    })
    .catch(function(err){
      var bodyEl = document.getElementById("nfseModalBody");
      if (bodyEl) bodyEl.innerHTML = "<div class=\\"nfse-alert nfse-alert-error\\">Falha ao carregar formulário: "+ (err && err.message ? err.message : "erro desconhecido") +"</div>";
    });
}

function openNfseSubstituicao(id) {
  document.querySelectorAll(".nfe-dropdown-menu").forEach(m => m.style.display = "none");
  var body = document.getElementById("nfseModalBody");
  if (body) body.innerHTML = "<div class=\\"nfse-loading-container\\"><div class=\\"nfse-spinner\\"></div><p>Carregando formulário de substituição...</p></div>";
  var modal = document.getElementById("nfseModal").querySelector(".nfse-modal");
  if (modal) modal.className = "nfse-modal nfse-modal-substituicao";
  openNfseModal("Substituir NFSe");
  var url = "'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'?action=mostrar_substituicao&id=" + encodeURIComponent(id);
  fetch(url, { headers: { "X-Requested-With": "XMLHttpRequest" } })
    .then(function(r){ return r.text(); })
    .then(function(html){
      var bodyEl = document.getElementById("nfseModalBody");
      if (bodyEl) bodyEl.innerHTML = html;
    })
    .catch(function(err){
      var bodyEl = document.getElementById("nfseModalBody");
      if (bodyEl) bodyEl.innerHTML = "<div class=\\"nfse-alert nfse-alert-error\\">Falha ao carregar formulário: "+ (err && err.message ? err.message : "erro desconhecido") +"</div>";
    });
}

function openConfirmModal() {
  var modal = document.getElementById("nfseConfirmModal");
  if (modal) modal.classList.add("visible");
}
function closeConfirmModal() {
  var modal = document.getElementById("nfseConfirmModal");
  if (modal) modal.classList.remove("visible");
}

// Novas funções para modal de substituição
function openConfirmSubstituicaoModal() {
  var modal = document.getElementById("nfseConfirmSubstituicaoModal");
  if (modal) modal.classList.add("visible");
}
function closeConfirmSubstituicaoModal() {
  var modal = document.getElementById("nfseConfirmSubstituicaoModal");
  if (modal) modal.classList.remove("visible");
}

function processarCancelamento() {
  var form = document.getElementById("formCancelamento");
  if (!form) return;
  
  // Limpar erros anteriores
  form.querySelectorAll(".nfse-field-error").forEach(function(el) {
    el.classList.remove("nfse-field-error");
  });
  form.querySelectorAll(".nfse-error-message").forEach(function(el) {
    el.remove();
  });
  
  var isValid = true;
  
  var codigoMotivo = form.querySelector("[name=codigo_motivo]");
  if (!codigoMotivo || !codigoMotivo.value) {
    isValid = false;
    if (codigoMotivo) {
      codigoMotivo.classList.add("nfse-field-error");
      codigoMotivo.insertAdjacentHTML("afterend", "<span class=\\"nfse-error-message\\">Campo obrigatório.</span>");
    }
  }
  
  var descricaoMotivo = form.querySelector("[name=descricao_motivo]");
  if (!descricaoMotivo || !descricaoMotivo.value.trim()) {
    isValid = false;
    if (descricaoMotivo) {
      descricaoMotivo.classList.add("nfse-field-error");
      descricaoMotivo.insertAdjacentHTML("afterend", "<span class=\\"nfse-error-message\\">Campo obrigatório.</span>");
    }
  }
  
  if (!isValid) return;
  
  openConfirmModal();
}

function confirmarCancelamento() {
  closeConfirmModal();
  var form = document.getElementById("formCancelamento");
  if (!form) return;
  
  var body = document.getElementById("nfseModalBody");
  if (body) body.innerHTML = "<div class=\\"nfse-loading-container\\"><div class=\\"nfse-spinner\\"></div><p>Processando cancelamento...</p></div>";
  
  var formData = new FormData(form);
  
  fetch("'.DOL_URL_ROOT.'/custom/nfse/cancela_nfse_nacional.php", {
    method: "POST",
    body: formData,
    headers: { "X-Requested-With": "XMLHttpRequest" },
    credentials: "same-origin"
  })
  .then(function(r){
    if (!r.ok) {
      if (r.status === 403) throw new Error("Acesso negado. Sua sessão pode ter expirado. Recarregue a página.");
      throw new Error("Erro no servidor: " + r.status);
    }
    return r.json();
  })
  .then(function(response){
    if (response && response.success === true) {
      closeNfseModal();
      // Exibe mensagem de sucesso usando setEventMessages do Dolibarr
      // (a mensagem já foi definida na sessão pelo backend, então basta recarregar)
      window.location.reload();
    } else {
      var errorMsg = response && response.error ? response.error : "Erro desconhecido no processamento";
      var errorHtml = "<div class=\\"nfse-alert nfse-alert-error\\">" + errorMsg + "</div>";
      errorHtml += "<div style=\\"text-align:center;margin-top:20px;\\"><button class=\\"butAction\\" onclick=\\"closeNfseModal()\\">Fechar</button></div>";
      if (body) body.innerHTML = errorHtml;
    }
  })
  .catch(function(err){
    if (body) body.innerHTML = "<div class=\\"nfse-alert nfse-alert-error\\">Erro ao processar: "+ (err && err.message ? err.message : "erro desconhecido") +"</div><div style=\\"text-align:center;margin-top:20px;\\"><button class=\\"butAction\\" onclick=\\"closeNfseModal()\\">Fechar</button></div>";
  });
}

function processarSubstituicao() {
  var form = document.getElementById("formSubstituicao");
  if (!form) return;

  // Limpar erros anteriores
  form.querySelectorAll(".nfse-field-error").forEach(function(el) {
    el.classList.remove("nfse-field-error");
  });
  form.querySelectorAll(".nfse-error-message").forEach(function(el) {
    el.remove();
  });

  // Validar campos obrigatórios
  var isValid = true;

  var codigoCancelamento = form.querySelector("[name=codigo_cancelamento]");
  if (!codigoCancelamento.value) {
    isValid = false;
    codigoCancelamento.classList.add("nfse-field-error");
    codigoCancelamento.insertAdjacentHTML("afterend", "<span class=\\"nfse-error-message\\">Campo Obrigatório.</span>");
  }

  var motivo = form.querySelector("[name=motivo]");
  if (!motivo.value.trim()) {
    isValid = false;
    motivo.classList.add("nfse-field-error");
    motivo.insertAdjacentHTML("afterend", "<span class=\\"nfse-error-message\\">Campo Obrigatório.</span>");
  }

  var valorServicos = form.querySelector("[name=valor_servicos]");
  if (!valorServicos.value || parseFloat(valorServicos.value) <= 0) {
    isValid = false;
    valorServicos.classList.add("nfse-field-error");
    valorServicos.insertAdjacentHTML("afterend", "<span class=\\"nfse-error-message\\">Valor inválido.</span>");
  }

  var codServico = form.querySelector("[name=cod_servico]");
  if (!codServico.value.trim()) {
    isValid = false;
    codServico.classList.add("nfse-field-error");
    codServico.insertAdjacentHTML("afterend", "<span class=\\"nfse-error-message\\">Campo Obrigatório.</span>");
  }

  var discriminacao = form.querySelector("[name=discriminacao]");
  if (!discriminacao.value.trim()) {
    isValid = false;
    discriminacao.classList.add("nfse-field-error");
    discriminacao.insertAdjacentHTML("afterend", "<span class=\\"nfse-error-message\\">Campo Obrigatório.</span>");
  }

  // Se houver erros, não prosseguir
  if (!isValid) return;

  // Abrir modal de confirmação
  openConfirmSubstituicaoModal();
}

function confirmarSubstituicao() {
  closeConfirmSubstituicaoModal(); // Fecha modal
  var form = document.getElementById("formSubstituicao");
  if (!form) return;
  var body = document.getElementById("nfseModalBody");
  if (body) body.innerHTML = "<div class=\\"nfse-loading-container\\"><div class=\\"nfse-spinner\\"></div><p>Processando substituição...</p></div>";
  var formData = new FormData(form);
  formData.append("action", "processar_substituicao");
  fetch("'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'", {
    method: "POST",
    body: formData,
    headers: { "X-Requested-With": "XMLHttpRequest" },
    credentials: "same-origin"
  })
  .then(function(r){
    if (!r.ok) {
      if (r.status === 403) throw new Error("Acesso negado. Sua sessão pode ter expirado.");
      throw new Error("Erro no servidor: " + r.status);
    }
    var contentType = r.headers.get("content-type");
    if (contentType && contentType.indexOf("application/json") !== -1) return r.json();
   
    return r.text().then(function(text){ throw new Error("Resposta inválida do servidor"); });
  })
  .then(function(response){
    if (response && response.success === true) {
      closeNfseModal();
      window.location.reload();
    } else {
      var errorMsg = response && response.error ? response.error : "Erro desconhecido no processamento";
      var errorHtml = "<div class=\\"nfse-alert nfse-alert-error\\">" + errorMsg + "</div>";
      errorHtml += "<div style=\\"text-align:center;margin-top:20px;\\"><button class=\\"butAction\\" onclick=\\"closeNfseModal()\\">Fechar</button></div>";
      if (body) body.innerHTML = errorHtml;
    }
  })
  .catch(function(err){
    if (body) body.innerHTML = "<div class=\\"nfse-alert nfse-alert-error\\">Erro ao processar: "+ (err && err.message ? err.message : "erro desconhecido") +"</div><div style=\\"text-align:center;margin-top:20px;\\"><button class=\\"butAction\\" onclick=\\"closeNfseModal()\\">Fechar</button></div>";
  });
}

// Fecha modal de confirmação ao clicar fora
document.addEventListener("click", function(e) {
  var modal = document.getElementById("nfseConfirmModal");
  if (modal && e.target === modal) closeConfirmModal();
 
  var modalSub = document.getElementById("nfseConfirmSubstituicaoModal");
  if (modalSub && e.target === modalSub) closeConfirmSubstituicaoModal();
});

// Função para abrir configurações
function openNfseConfiguracoes() {
  document.querySelectorAll(".nfe-dropdown-menu").forEach(m => m.style.display = "none");
  var body = document.getElementById("nfseModalBody");
  if (body) body.innerHTML = "<div class=\\"nfse-loading-container\\"><div class=\\"nfse-spinner\\"></div><p>Carregando configurações...</p></div>";
  var modal = document.getElementById("nfseModal").querySelector(".nfse-modal");
  if (modal) modal.className = "nfse-modal nfse-modal-configuracoes";
  openNfseModal("⚙ Configurações NFS-e");
  
  var url = "'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'?action=carregar_configuracoes";
  fetch(url, { 
    headers: { "X-Requested-With": "XMLHttpRequest" },
    credentials: "same-origin"
  })
    .then(function(r){ 
      if (!r.ok) {
        return r.text().then(function(text) {
          throw new Error("Erro " + r.status);
        });
      }
      return r.text(); 
    })
    .then(function(html){
      var bodyEl = document.getElementById("nfseModalBody");
      if (bodyEl) {
        bodyEl.innerHTML = html;
      }
    })
    .catch(function(err){
      var bodyEl = document.getElementById("nfseModalBody");
      if (bodyEl) bodyEl.innerHTML = "<div class=\\"nfse-alert nfse-alert-error\\">Falha ao carregar configurações: "+ (err && err.message ? err.message : "erro desconhecido") +"</div><div style=\\"text-align:center;margin-top:20px;\\"><button class=\\"butAction\\" onclick=\\"closeNfseModal()\\">Fechar</button></div>";
    });
}

function salvarSequenciasNacional() {
  var form = document.getElementById("formSequenciasNacional");
  if (!form) return;
  
  // Desabilita o botão para evitar cliques múltiplos
  var saveBtn = event.target;
  if (saveBtn) {
    saveBtn.disabled = true;
    saveBtn.style.opacity = "0.6";
  }
  
  var body = document.getElementById("nfseModalBody");
  if (body) {
    body.innerHTML = "<div class=\\"nfse-loading-container\\">"+
      "<div class=\\"nfse-spinner\\"></div>"+
      "<p style=\\"animation: fadeIn 0.5s;\\">Salvando configurações...</p>"+
      "</div>";
  }
  
  var formData = new FormData(form);
  
  fetch("'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'", {
    method: "POST",
    body: formData,
    headers: { "X-Requested-With": "XMLHttpRequest" },
    credentials: "same-origin"
  })
  .then(function(r){ 
    if (!r.ok) throw new Error("Erro ao salvar (" + r.status + ")");
    return r.json();
  })
  .then(function(response){
    if (response.success) {
      var bodyEl = document.getElementById("nfseModalBody");
      if (bodyEl) {
        bodyEl.innerHTML = 
          "<div class=\\"nfse-success-container\\">"+
            "<div class=\\"nfse-success-icon\\">"+
              "<svg viewBox=\\"0 0 24 24\\">"+
                "<path d=\\"M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z\\"/>"+
              "</svg>"+
            "</div>"+
            "<div class=\\"nfse-success-message\\">" + (response.message || "Configurações salvas com sucesso!") + "</div>"+
            "<div class=\\"nfse-success-submessage\\">Recarregando página...</div>"+
          "</div>";
      }
      setTimeout(function() {
        closeNfseModal();
        window.location.reload();
      }, 1800);
    } else {
      throw new Error(response.error || "Erro desconhecido");
    }
  })
  .catch(function(err){
    var bodyEl = document.getElementById("nfseModalBody");
    if (bodyEl) {
      bodyEl.innerHTML = 
        "<div style=\\"padding: 30px; text-align: center;\\">"+
          "<div style=\\"width: 70px; height: 70px; margin: 0 auto 20px; border-radius: 50%; background: #dc3545; display: flex; align-items: center; justify-content: center;\\">"+
            "<svg viewBox=\\"0 0 24 24\\" width=\\"35\\" height=\\"35\\" style=\\"fill: white;\\">"+
              "<path d=\\"M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z\\"/>"+
            "</svg>"+
          "</div>"+
          "<div style=\\"font-size: 18px; font-weight: 600; color: #dc3545; margin-bottom: 10px;\\">Erro ao Salvar</div>"+
          "<div style=\\"color: #666; margin-bottom: 25px;\\">"+ (err && err.message ? err.message : "erro desconhecido") +"</div>"+
          "<button class=\\"butAction\\" onclick=\\"abrirConfiguracoes()\\">Tentar Novamente</button> "+
          "<button class=\\"butActionDelete\\" onclick=\\"closeNfseModal()\\" style=\\"margin-left: 10px;\\">Fechar</button>"+
        "</div>";
    }
  });
}

// === Filtro de Status (popover) ===
function toggleStatusFilter(e){
    e.stopPropagation();
    var pop = document.getElementById("statusFilterPopover");
    if (!pop) return;
    var open = pop.classList.contains("visible");
    document.querySelectorAll(".nfse-status-filter-popover").forEach(function(p){ p.classList.remove("visible"); });
    pop.classList.toggle("visible", !open);
}
function applyStatusFilter(){
    var pop = document.getElementById("statusFilterPopover");
    var hidden = document.getElementById("search_status_list");
    var form = document.getElementById("nfseListFilterForm");
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

// Fecha popover ao clicar fora ou ESC
document.addEventListener("click", function(e){
    if (!e.target.closest(".status-filter-cell")) {
        document.querySelectorAll(".nfse-status-filter-popover").forEach(function(p){ p.classList.remove("visible"); });
    }
});
document.addEventListener("keydown", function(e){
    if (e.key === "Escape" || e.key === "Esc") {
        document.querySelectorAll(".nfse-status-filter-popover").forEach(function(p){ p.classList.remove("visible"); });
    }
});

// Atualiza badge ao abrir (opcional)
document.addEventListener("DOMContentLoaded", function(){
    var pop = document.getElementById("statusFilterPopover");
    var badge = document.getElementById("statusSelCount");
    if (pop) {
        var cnt = pop.querySelectorAll("input[type=checkbox]:checked").length;
        if (cnt > 0 && !badge) {
            var btn = document.querySelector(".nfse-status-filter-toggle");
            if (btn){
                var b = document.createElement("span");
                b.id = "statusSelCount"; b.className = "badge"; b.textContent = cnt;
                btn.appendChild(b);
            }
        }
    }
});
</script>';

// Fecha a página corretamente
llxFooter();
?>