<?php
require '../../../main.inc.php';

// Garante que a função dolibarr_set_const esteja disponível.
// Em algumas instalações a função pode não ter sido carregada automaticamente.
if (!function_exists('dolibarr_set_const')) {
	// arquivo padrão onde a função é definida
	require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
}

if (!$user->admin) accessforbidden();

$langs->load("admin");
$page_name = "Configuração NFS-e";
$action = GETPOST('action', 'aZ09');

// Detecta se está sendo carregado via AJAX
$ajax_mode = GETPOST('ajax_mode', 'int') || !empty($_SERVER['HTTP_X_REQUESTED_WITH']);

if ($action == 'save') {
    $keys = array(
        'NFSE_WSDL','NFSE_COD_MUNICIPIO',
        'NFSE_ITEM_LISTA_SERVICO','NFSE_DEFAULT_SERIE','NFSE_REGIME_ESPECIAL',
        'NFSE_OPTANTE_SIMPLES','NFSE_INCENTIVO_FISCAL', 'NFSE_ULTIMO_LOTE', 'NFSE_ULTIMO_RPS',
    );
    foreach ($keys as $k) {
        dolibarr_set_const($db, $k, GETPOST($k,'restricthtml'), 'chaine', 0, '', $conf->entity);
    }

    // === Atualiza/insere sequência em llx_nfse_sequencias com os valores informados ===
    // Lê valores enviados pelo formulário (últimos números fornecidos pelo usuário)
    $ultimo_lote = (int) GETPOST('NFSE_ULTIMO_LOTE', 'int');
    $ultimo_rps  = (int) GETPOST('NFSE_ULTIMO_RPS', 'int');
    $serie_form  = GETPOST('NFSE_DEFAULT_SERIE', 'alpha');
    $serie = $serie_form !== '' ? $serie_form : ($conf->global->NFSE_DEFAULT_SERIE ?? '1');

    // Carrega dados da empresa (emitente)
    global $mysoc;
    if (empty($mysoc->id)) {
        $mysoc->fetch(0);
    }
    $cnpj_raw = $mysoc->idprof1 ?? '';
    $im_raw   = $mysoc->idprof3 ?? '';
    $cnpj = preg_replace('/\D/', '', $cnpj_raw);
    $im   = preg_replace('/\D/', '', $im_raw);

    if ($cnpj !== '') {
        $tipo = '1'; // tipo padrão RPS
        // next_* guarda o próximo número a ser usado. Se usuário informou "último", definimos next = ultimo + 1
        $next_rps = max(1, $ultimo_rps) + 1;
        $next_lote = max(1, $ultimo_lote) + 1;

        // Verifica existência e atualiza/insere
        $cnpjE = $db->escape($cnpj);
        $imE = $db->escape($im);
        $serieE = $db->escape($serie);
        $tipoE = $db->escape($tipo);
        $nextRpsE = (int)$next_rps;
        $nextLoteE = (int)$next_lote;

        $sqlCheck = "SELECT id FROM ".MAIN_DB_PREFIX."nfse_sequencias WHERE cnpj = '".$cnpjE."' AND im = '".$imE."' AND serie = '".$serieE."' AND tipo = '".$tipoE."' LIMIT 1";
        $resCheck = $db->query($sqlCheck);
        if ($resCheck && $db->num_rows($resCheck) > 0) {
            $row = $db->fetch_object($resCheck);
            $sqlUpd = "UPDATE ".MAIN_DB_PREFIX."nfse_sequencias
                       SET next_numero_rps = ". $nextRpsE .", next_numero_lote = ". $nextLoteE .", updated_at = NOW()
                       WHERE id = ".((int)$row->id);
            $db->query($sqlUpd);
            if ($db->lasterror) dol_syslog('[NFSE SETUP] Atualizado llx_nfse_sequencias id='.((int)$row->id).': '.$db->lasterror, LOG_ERR);
        } else {
            $sqlInsSeq = "INSERT INTO ".MAIN_DB_PREFIX."nfse_sequencias (cnpj, im, serie, tipo, next_numero_rps, next_numero_lote, updated_at)
                          VALUES ('".$cnpjE."','".$imE."','".$serieE."','".$tipoE."', ".$nextRpsE.", ".$nextLoteE.", NOW())";
            $db->query($sqlInsSeq);
            if ($db->lasterror) dol_syslog('[NFSE SETUP] Erro INSERT llx_nfse_sequencias: '.$db->lasterror, LOG_ERR);
        }
    } else {
        dol_syslog('[NFSE SETUP] CNPJ do emitente não encontrado; sequência não atualizada.', LOG_WARNING);
    }
    // === fim atualização de sequência ===
    
    // Se for AJAX, retorna JSON ao invés de reload
    if ($ajax_mode) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => true, 'message' => 'Configurações salvas']);
        exit;
    }
    
    setEventMessages('Configurações salvas', null, 'mesgs');
}

// Não renderiza header/footer se for AJAX
if (!$ajax_mode) {
    llxHeader('', $page_name);
    print load_fiche_titre($page_name);
}

// Adiciona estilos rápidos para melhorar visual dos cards (compacto e centralizado)
print '<style>
/* centraliza o container principal e limita largura total */
.fichecenter { display:flex; flex-direction:column; align-items:center; gap:10px; width:100%; }

/* cards menores e centralizados */
.nfse-card { border:1px solid #ddd; border-radius:6px; box-shadow:0 1px 2px rgba(0,0,0,0.03); margin:8px auto; background:#fff; width:100%; max-width:480px; box-sizing:border-box; }
.nfse-card .card-header { padding:8px 10px; border-bottom:1px solid #eee; background:#f7f7f7; font-weight:700; font-size:0.95rem; box-sizing:border-box; }
.nfse-card .card-body { padding:8px 10px; box-sizing:border-box; overflow-x:auto; }

/* gap: <row-gap> <column-gap> -> garante espaço vertical (row-gap) quando os itens quebram linha
   adiciona margin-bottom nas colunas para garantir espaçamento em todos os navegadores */
.nfse-form-row { display:flex; gap:12px 16px; flex-wrap:wrap; row-gap:14px; box-sizing:border-box; }
.nfse-form-col { flex:1 1 120px; min-width:0; margin-bottom:12px; box-sizing:border-box; } /* min-width:0 permite encolher sem causar overflow */
.nfse-form-label { display:block; font-size:0.9rem; margin-bottom:6px; color:#333; font-weight:600; }
.nfse-input { width:100%; max-width:100%; padding:6px 7px; border:1px solid #ccd0d4; border-radius:4px; background:#fff; font-size:0.95rem; margin-top:4px; box-sizing:border-box; }
.nfse-input[disabled] { background:#f5f6f7; color:#666; }
.center-actions { text-align:center; margin-top:10px; }

/* Visual pro quando aberto standalone (paleta teal) */
.nfse-card{
    border:1px solid #e5f2ef; border-radius:10px; box-shadow:0 6px 16px rgba(0,0,0,0.06);
    background:#fff; max-width:620px;
}
.nfse-card .card-header{
    background: linear-gradient(135deg,#16a085,#1abc9c);
    color:#fff; border-bottom:none; display:flex; align-items:center; gap:8px;
    border-radius:10px 10px 0 0;
}

.nfse-card .card-body{ background:#fff; }
.nfse-form-label{ color:#495057; }
.nfse-input{
    padding:10px 12px; border:1px solid #cfe6e2; border-radius:6px; transition:border-color .2s, box-shadow .2s;
}
.nfse-input:focus{ border-color:#1abc9c; box-shadow:0 0 0 3px rgba(26,188,156,.18); outline:none; }
.center-actions{ margin-top:14px; }
</style>';

// abre formulário
print '<form method="post">';
print '<input type="hidden" name="action" value="save">';

// Adiciona token CSRF exigido por main.inc.php
$token = newToken();
print '<input type="hidden" name="token" value="'.dol_escape_htmltag($token).'">';

// Substitui função confCard por versão que aceita um título e um array de campos
function confCard($title, $fields = array()) {
    print '<div class="nfse-card">';
    print '<div class="card-header">'.dol_escape_htmltag($title).'</div>';
    print '<div class="card-body">';
    print '<div class="nfse-form-row">';
    foreach ($fields as $f) {
        $label = $f['label'] ?? '';
        $name  = $f['name'] ?? '';
        $val   = $f['value'] ?? '';
        $type  = $f['type'] ?? 'text';
        $disabled = !empty($f['disabled']) ? ' disabled' : '';
        print '<div class="nfse-form-col">';
        print '<label class="nfse-form-label">'.dol_escape_htmltag($label).'</label>';
        print '<input class="nfse-input" type="'.dol_escape_htmltag($type).'" name="'.dol_escape_htmltag($name).'" value="'.dol_escape_htmltag($val).'"'.$disabled.'>';
        print '</div>';
    }
    print '</div>'; // nfse-form-row
    print '</div>'; // card-body
    print '</div>'; // nfse-card
}

// Prepara dados do emitente para exibição (somente leitura)
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

// === NOVO: carregar sequência do banco para exibição ===
$ultimo_lote_display = (int) ($conf->global->NFSE_ULTIMO_LOTE ?? 0);
$ultimo_rps_display  = (int) ($conf->global->NFSE_ULTIMO_RPS ?? 0);
$serie_display       = $conf->global->NFSE_DEFAULT_SERIE ?? '1';

if ($cnpj !== '') {
    $tipo = '1';
    $serie_query = $db->escape($serie_display);
    $cnpjE = $db->escape($cnpj);
    $imE   = $db->escape($im);
    $tipoE = $db->escape($tipo);

    $sqlSeq = "SELECT next_numero_rps, next_numero_lote
               FROM ".MAIN_DB_PREFIX."nfse_sequencias
               WHERE cnpj = '".$cnpjE."' AND im = '".$imE."' AND serie = '".$serie_query."' AND tipo = '".$tipoE."' LIMIT 1";
    $resSeq = $db->query($sqlSeq);
    if ($resSeq && $db->num_rows($resSeq) > 0) {
        $rowSeq = $db->fetch_object($resSeq);
        // next_* guarda o próximo número a ser usado; exibimos o último usado = next - 1 (mínimo 0)
        $ultimo_rps_display  = max(0, ((int)$rowSeq->next_numero_rps) - 1);
        $ultimo_lote_display = max(0, ((int)$rowSeq->next_numero_lote) - 1);
    } else {
        // sem linha encontrada, manter os valores vindos de constantes globais (já definidos acima)
    }
}
// === fim leitura sequência ===

// Monta os cards com os campos
print '<div class="fichecenter">';

// Card Sequências
confCard('Sequências', array(
    array('label'=>'Último Lote Enviado','name'=>'NFSE_ULTIMO_LOTE','value'=> (string)$ultimo_lote_display ,'type'=>'number'),
    array('label'=>'Último RPS Enviado','name'=>'NFSE_ULTIMO_RPS','value'=> (string)$ultimo_rps_display ,'type'=>'number'),
    array('label'=>'Série RPS padrão','name'=>'NFSE_DEFAULT_SERIE','value'=>$serie_display,'type'=>'text'),
));

// Card Emitente (somente leitura)
confCard('Emitente (somente leitura)', array(
    array('label'=>'CNPJ (emitente)','name'=>'EMIT_CNPJ','value'=>$display_cnpj,'type'=>'text','disabled'=>1),
    array('label'=>'Inscrição Municipal (IM)','name'=>'EMIT_IM','value'=>$display_im,'type'=>'text','disabled'=>1),
));

// campos adicionais simples (se existirem) podem ficar fora dos cards ou adicionados acima
print '</div>'; // fichecenter

// NOVO: Busca configuração atual de modo de consulta
$modoConsultaAtual = getDolGlobalString('NFSE_CONSULTA_MODO', '2'); // padrão: moderna

// ADICIONAR antes do botão Salvar:
print '<tr class="oddeven">';
print '<td>Modo de Consulta de NFSe</td>';
print '<td>';
print '<select name="nfse_consulta_modo" class="flat">';
print '<option value="1"'.($modoConsultaAtual == '1' ? ' selected' : '').'>Legada (simples, tabela)</option>';
print '<option value="2"'.($modoConsultaAtual == '2' ? ' selected' : '').'>Moderna (cards, visual aprimorado)</option>';
print '</select>';
print ' <span class="opacitymedium">(Escolha como exibir os detalhes da NFSe na modal de consulta)</span>';
print '</td>';
print '</tr>';

print '<div class="center-actions"><input class="button" type="submit" value="Salvar"></div>';
print '</form>';

if (!$ajax_mode) {
    llxFooter();
}
?>
