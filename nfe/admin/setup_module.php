<?php
require '../../../main.inc.php';

// Garante que a função dolibarr_set_const esteja disponível
if (!function_exists('dolibarr_set_const')) {
    require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
}

if (!$user->admin) accessforbidden();

$langs->load("admin");
$page_name = "Configuração NFe";
// Não usar o parâmetro genérico 'action' para não disparar a proteção CSRF do main.inc
// Usaremos 'nfe_action' apenas nesta página.
$nfe_action = GETPOST('nfe_action', 'alpha');

// Garante que o token CSRF seja gerado
if (empty($token)) {
    $token = $_SESSION['newtoken'] = function_exists('newToken') ? newToken() : md5(uniqid(mt_rand(), true));
}

if ($nfe_action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Valida token CSRF gerado no formulário
    if (empty($_POST['token']) || $_POST['token'] !== $_SESSION['newtoken']) {
        setEventMessages($langs->trans("ErrorBadToken"), null, 'errors');
        dol_syslog('[NFE SETUP] Token CSRF inválido ou ausente.', LOG_ERR);
        // redireciona para evitar re-submissão insegura
        header("Location: ".$_SERVER['PHP_SELF']);
        exit;
    }
    // NFE_WSDL removido conforme solicitado — gravamos apenas a série e regime
    $keys = array('NFE_DEFAULT_SERIE', 'NFE_REGIME_TRIBUTARIO');

    foreach ($keys as $k) {
        dolibarr_set_const($db, $k, GETPOST($k, 'restricthtml'), 'chaine', 0, '', $conf->entity);
    }
    setEventMessages('Configurações salvas', null, 'mesgs');

    // Atualiza/insere sequência na tabela nfe_sequencia
    $ultimo_numero = (int) GETPOST('NFE_ULTIMO_NUMERO', 'int');
    $serie_form = GETPOST('NFE_DEFAULT_SERIE', 'alpha');
    $serie = $serie_form !== '' ? $serie_form : ($conf->global->NFE_DEFAULT_SERIE ?? '1');

    // Carrega dados da empresa (emitente)
    global $mysoc;
    if (empty($mysoc->id)) {
        $mysoc->fetch(0);
    }
    $cnpj_raw = $mysoc->idprof1 ?? '';
    $cnpj = preg_replace('/\D/', '', $cnpj_raw);

    if ($cnpj !== '') {
        $cnpjE = $db->escape($cnpj);
        $serieE = $db->escape($serie);
        $ultimoE = max(0, (int)$ultimo_numero);

        // Verifica existência e atualiza/insere (usa PK composta cnpj+serie)
        $sqlCheck = "SELECT cnpj, serie FROM ".MAIN_DB_PREFIX."nfe_sequencia WHERE cnpj = '".$cnpjE."' AND serie = '".$serieE."' LIMIT 1";
        $resCheck = $db->query($sqlCheck);
        if ($resCheck && $db->num_rows($resCheck) > 0) {
            // Atualiza a linha identificada pela PK composta (cnpj, serie)
            $sqlUpd = "UPDATE ".MAIN_DB_PREFIX."nfe_sequencia
                       SET ultimo_numero = ".((int)$ultimoE)."
                       WHERE cnpj = '".$cnpjE."' AND serie = '".$serieE."'";
            $db->query($sqlUpd);
             if ($db->lasterror) dol_syslog('[NFE SETUP] Erro UPDATE nfe_sequencia id='.((int)$row->id).': '.$db->lasterror, LOG_ERR);
        } else {
            $sqlIns = "INSERT INTO ".MAIN_DB_PREFIX."nfe_sequencia (cnpj, serie, ultimo_numero)
                       VALUES ('".$cnpjE."', '".$serieE."', ".$ultimoE.")";
            $db->query($sqlIns);
            if ($db->lasterror) dol_syslog('[NFE SETUP] Erro INSERT nfe_sequencia: '.$db->lasterror, LOG_ERR);
        }
    } else {
        dol_syslog('[NFE SETUP] CNPJ do emitente não encontrado; sequência não atualizada.', LOG_WARNING);
    }
}

// --- Substituir a lógica anterior de "busca valores atuais de série e último número no banco" por esta mais robusta ---
// Mostra CNPJ do emitente (apenas leitura)
global $mysoc;
if (empty($mysoc->id)) $mysoc->fetch(0);
$cnpj_disp = $mysoc->idprof1 ?? '';

// Formatação breve para exibir depois
function _format_cnpj_short($raw) {
	$d = preg_replace('/\D/', '', (string)$raw);
	if (strlen($d)===14) return substr($d,0,2).'.'.substr($d,2,3).'.'.substr($d,5,3).'/'.substr($d,8,4).'-'.substr($d,12,2);
	return $raw ?: '';
}

// Dados iniciais vindos das constantes (fallback)
$wsdlVal = $conf->global->NFE_WSDL ?? '';
$regimeVal = $conf->global->NFE_REGIME_TRIBUTARIO ?? '';

// Busca sequências existentes para este CNPJ
$cnpj_plain = preg_replace('/\D/', '', $cnpj_disp);
$seqRows = array(); // serie => ultimo_numero
if ($cnpj_plain !== '') {
    $cnpjE = $db->escape($cnpj_plain);
    $sqlSeqAll = "SELECT serie, ultimo_numero FROM ".MAIN_DB_PREFIX."nfe_sequencia WHERE cnpj = '".$cnpjE."' ORDER BY serie ASC";
    $resSeqAll = $db->query($sqlSeqAll);
    if ($resSeqAll) {
        while ($r = $db->fetch_object($resSeqAll)) {
            $serieKey = (string)$r->serie;
            $seqRows[$serieKey] = (int)$r->ultimo_numero;
        }
    }
}

// Determina série atual: prioriza constante global, senão primeira série do banco, senão '1'
$currentSerie = (!empty($conf->global->NFE_DEFAULT_SERIE) ? (string)$conf->global->NFE_DEFAULT_SERIE : null);
if (empty($currentSerie) && !empty($seqRows)) {
    reset($seqRows);
    $currentSerie = key($seqRows);
}
if (empty($currentSerie)) $currentSerie = '1';

// Preenche último número a partir do banco se existir para a série escolhida
$currentUltimo = isset($seqRows[$currentSerie]) ? (int)$seqRows[$currentSerie] : 0;

// Formatação simples de CNPJ para exibição
function formatCnpjDisplay(?string $cnpjRaw): string {
	$raw = $cnpjRaw ?? '';
	$digits = preg_replace('/\D/', '', $raw);
	if (strlen($digits) === 14) {
		return substr($digits,0,2).'.'.substr($digits,2,3).'.'.substr($digits,5,3).'/'.substr($digits,8,4).'-'.substr($digits,12,2);
	}
	return $raw ?: '';
}

llxHeader('', $page_name);
print load_fiche_titre($page_name);

// Render do formulário com visual aprimorado
print '<style>
/* Layout simplificado */
.nfe-setup-wrapper { max-width:900px; margin:14px auto; background:#fff; border:1px solid #e6e6e6; border-radius:8px; padding:18px; box-shadow:0 2px 6px rgba(0,0,0,0.04); }
.nfe-setup-header { display:flex; align-items:center; justify-content:space-between; margin-bottom:12px; gap:8px; flex-wrap:wrap; }
.nfe-setup-title { font-size:1.15rem; font-weight:600; color:#203143; }
.nfe-setup-note { font-size:0.9rem; color:#6b7280; }
.nfe-grid { display:grid; grid-template-columns: 1fr 1fr; gap:12px; margin-top:12px; }
.nfe-field { display:flex; flex-direction:column; gap:6px; }
.nfe-field label { font-weight:600; font-size:0.95rem; color:#25364a; }
.nfe-field .help { font-size:0.85rem; color:#6b7280; }
.nfe-field input[type="text"], .nfe-field input[type="number"], .nfe-field input[type="password"], .nfe-field select { padding:8px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:0.95rem; background:#fff; }
.nfe-field input[readonly] { background:#f7fafc; color:#374151; }

/* Centraliza botões e usa o estilo padrão Dolibarr (.butAction) */
.nfe-actions { text-align:center; margin-top:18px; }
.nfe-actions .butAction { margin:0 6px; min-width:140px; display:inline-block; vertical-align:middle; }

/* Estado atual: linha única com fundo verde */
.nfe-status-inline {
    background: #e6ffed; /* tom verde claro */
    border: 1px solid #b6f0c8;
    padding: 10px 12px;
    border-radius: 8px;
    color: #0b6628; /* texto escuro verde */
    font-weight:600;
    display:inline-block;
    white-space:nowrap;
    line-height:1.4;
}
.nfe-status-inline .nfe-status-value {
    background: transparent;
    padding: 0 8px;
    border-radius: 6px;
    font-weight:700;
    color: #05431a;
}
@media (max-width:720px){ .nfe-grid{ grid-template-columns:1fr; } .nfe-actions .butAction { display:block; width:100%; margin:8px 0; } }
</style>';

print '<div class="nfe-setup-wrapper">';
print '<div class="nfe-setup-header">';
print '<div class="nfe-setup-title">Configuração do módulo NFe</div>';
print '<div class="nfe-setup-note">Ajuste série e sequência usada para emissão. Alterações são aplicadas ao salvar.</div>';
print '</div>';

print '<form method="post" style="margin:0;">';
print '<input type="hidden" name="nfe_action" value="save">'; // evita conflito com main.inc CSRF check
print '<input type="hidden" name="token" value="'.dol_escape_htmltag($token).'">';

// Campos principais em grid
print '<div class="nfe-grid">';

// Série padrão (agora sempre input)
print '<div class="nfe-field">';
print '<label for="NFE_DEFAULT_SERIE">Série Padrão</label>';
print '<input id="NFE_DEFAULT_SERIE" name="NFE_DEFAULT_SERIE" type="text" value="'.dol_escape_htmltag($currentSerie).'" />';
print '<div class="help">Informe a série que será usada nas emissões (ex.: 1). O valor acima foi carregado do banco.</div>';
print '</div>';

// Último número (editable)
print '<div class="nfe-field">';
print '<label for="NFE_ULTIMO_NUMERO">Último Número</label>';
print '<input id="NFE_ULTIMO_NUMERO" name="NFE_ULTIMO_NUMERO" type="number" min="0" value="'.(int)$currentUltimo.'" />';
print '<div class="help">Valor atualmente armazenado no banco (será atualizado ao salvar).</div>';
print '</div>';

// CNPJ emitente (readonly)
$cnpj_display_value = _format_cnpj_short($cnpj_disp);
print '<div class="nfe-field">';
print '<label>CNPJ do Emitente</label>';
print '<input type="text" readonly value="'.dol_escape_htmltag($cnpj_display_value).'" />';
print '<div class="help">CNPJ lido do cadastro da empresa (somente informação).</div>';
print '</div>';

// Substitui bloco de Estado Atual por linha única com fundo verde
print '<div class="nfe-field">';
print '<label>Estado Atual</label>';
print '<div class="nfe-status-inline">Último número: '.(int)$currentUltimo.' Série: '.dol_escape_htmltag($currentSerie).'</div>';
print '</div>';
print '</div>'; 
// Ações visuais e botão Salvar (visível e funcional)
print '<div class="nfe-actions" style="padding-top:8px;">';

print '<input type="submit" class="butAction" value="'.dol_escape_htmltag($langs->trans("Save")).'">';
print '</div>';


// Fecha formulário e wrapper
print '</form>';
print '</div>'; // .nfe-setup-wrapper

llxFooter();
$db->close();
?>
