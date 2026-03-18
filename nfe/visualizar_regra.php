<?php
/**
 * Página de Visualização de Regra Fiscal (Design Aprimorado)
 */

// Carregamento do ambiente Dolibarr
$res = 0;
if (!$res && file_exists("../main.inc.php")) { $res = @include "../main.inc.php"; }
if (!$res && file_exists("../../main.inc.php")) { $res = @include "../../main.inc.php"; }
if (!$res && file_exists("../../../main.inc.php")) { $res = @include "../../../main.inc.php"; }
if (!$res) { die("Include of main fails"); }

require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

// Segurança
if (!$user->admin) accessforbidden();

$id = GETPOST('id','int');
if ($id <= 0) {
    header('Location: ' . DOL_URL_ROOT . '/custom/nfe/regras_fiscais.php');
    exit;
}

// Busca regra
$sql = "SELECT * FROM " . MAIN_DB_PREFIX . "custom_tax_rules2 WHERE rowid = " . (int)$id;
$resql = $db->query($sql);
if (!$resql || $db->num_rows($resql) == 0) {
    setEventMessages('Regra não encontrada', null, 'errors');
    header('Location: ' . DOL_URL_ROOT . '/custom/nfe/regras_fiscais.php');
    exit;
}
$rule = $db->fetch_object($resql);

// Helpers de formatação
function fmt_money($val) {
    return ($val !== null && $val !== '') ? number_format((float)$val, 2, ',', '.') . '%' : '—';
}
function fmt_text($val) {
    return ($val && $val !== '') ? dol_escape_htmltag($val) : '<span style="color:#9ca3af; font-style:italic;">Não informado</span>';
}

// Cabeçalho Dolibarr
llxHeader('', 'Visualizar Regra - NFe', '');
print '<link rel="stylesheet" type="text/css" href="'.DOL_URL_ROOT.'/custom/nfe/css/regras_fiscais.css">';

// === HERO ==========================================================
print '<div class="rf-view-container">';
print '<div class="rf-view-hero">';
print '  <div class="rf-view-hero-title">';
print '    <h1>'.dol_escape_htmltag($rule->label).'</h1>';
print '    <div class="rf-view-hero-meta">';
// Adiciona fluxo (ORIGEM -> DESTINO) junto com as principais infos
print '      <span>Fluxo: <strong>'.fmt_text($rule->uf_origin).'</strong> → <strong>'.fmt_text($rule->uf_dest).'</strong></span>';
print '      <span>📅 Criado em '.dol_print_date($db->jdate($rule->date_creation),'day').'</span>';
print '      <span>🌐 NCM '.($rule->ncm ?: 'Todos').'</span>';
print '    </div>';
print '  </div>';

print '  <div class="rf-view-hero-badges">';
print '    <span class="rf-badge-chip">'.($rule->active ? 'Regra ATIVA' : 'Regra INATIVA').'</span>';
if ($rule->date_start || $rule->date_end) {
	print '    <span class="rf-badge-chip">'.($rule->date_start ? 'Início: '.dol_print_date($db->jdate($rule->date_start),'day') : 'Início indef.').'</span>';
	print '    <span class="rf-badge-chip">'.($rule->date_end ? 'Fim: '.dol_print_date($db->jdate($rule->date_end),'day') : 'Vigência aberta').'</span>';
}
print '  </div>';
print '</div>';

// === MÉTRICAS ======================================================
print '<div class="rf-view-metrics">';

// Card fixo: CFOP
print '<div class="rf-metric-card">';
print '  <div class="rf-metric-label">CFOP</div>';
print '  <div class="rf-metric-value">'.dol_escape_htmltag($rule->cfop).'</div>';
print '  <div class="rf-metric-sub">Código Fiscal</div>';
print '</div>';

// Monta lista preferencial de alíquotas (label, campo no DB, subtítulo)
$aliquotaCandidates = [
    ['label' => 'Aliq. Interest.', 'field' => 'icms_aliq_interestadual', 'sub' => 'Interestadual'],
    ['label' => 'Aliq. Interna',    'field' => 'icms_aliq_interna',        'sub' => 'Aplicada no destino'],
    ['label' => 'FCP / Difal',      'field' => 'difal_aliq_fcp',           'sub' => 'Complemento interestadual'],
    ['label' => 'Alíquota ST',      'field' => 'icms_st_aliq',             'sub' => 'Substituição Tributária'],
    ['label' => 'MVA ST',           'field' => 'icms_st_mva',             'sub' => 'MVA (ST)'],
    ['label' => 'PIS Aliq.',        'field' => 'pis_aliq',                'sub' => 'PIS'],
    ['label' => 'COFINS Aliq.',     'field' => 'cofins_aliq',             'sub' => 'COFINS'],
    ['label' => 'IPI Aliq.',        'field' => 'ipi_aliq',                'sub' => 'IPI'],
];

// 1) pega alíquotas com valor > 0
$selected = [];
foreach ($aliquotaCandidates as $c) {
    if (isset($rule->{$c['field']}) && (float)$rule->{$c['field']} > 0) {
        $selected[] = $c;
        if (count($selected) >= 3) break;
    }
}

// 2) se faltar, pega campos presentes (mesmo zero)
if (count($selected) < 3) {
    foreach ($aliquotaCandidates as $c) {
        // evita duplicar (comparação por campo)
        $found = false;
        foreach ($selected as $s) { if ($s['field'] === $c['field']) { $found = true; break; } }
        if ($found) continue;
        if (isset($rule->{$c['field']})) {
            $selected[] = $c;
            if (count($selected) >= 3) break;
        }
    }
}

// 3) se ainda faltar, completa com defaults (z zeros)
$defaults = [
    ['label' => 'Aliq. Interest.', 'field' => 'icms_aliq_interestadual', 'sub' => 'Interestadual'],
    ['label' => 'Aliq. Interna',    'field' => 'icms_aliq_interna',        'sub' => 'Aplicada no destino'],
    ['label' => 'FCP / Difal',      'field' => 'difal_aliq_fcp',           'sub' => 'Complemento interestadual'],
];
foreach ($defaults as $d) {
    if (count($selected) >= 3) break;
    $already = false;
    foreach ($selected as $s) { if ($s['field'] === $d['field']) { $already = true; break; } }
    if (!$already) $selected[] = $d;
}

// Renderiza os cards de alíquotas (até 3)
foreach ($selected as $item) {
    $field = $item['field'];
    $rawVal = isset($rule->{$field}) ? $rule->{$field} : '0.00';
    $displayVal = fmt_money($rawVal);
    $label = $item['label'];
    $sub = $item['sub'];
    print '<div class="rf-metric-card">';
    print '<div class="rf-metric-label">'.dol_escape_htmltag($label).'</div>';
    print '<div class="rf-metric-value">'.$displayVal.'</div>';
    print '<div class="rf-metric-sub">'.dol_escape_htmltag($sub).'</div>';
    print '</div>';
}

print '</div>'; // fim rf-view-metrics

// === SEÇÕES PRINCIPAIS ============================================
print '<div class="rf-view-sections">';

// Seção Tributação (dinâmica: ICMS / PIS-COFINS / IPI)
print '<div class="rf-section-card">';
print '  <div class="rf-section-header"><div class="rf-section-title">💰 Tributação Completa</div></div>';
print '  <div class="rf-view-tax-grid">';

// Definição dos campos por grupo (label, field, type)
$groups = [
    'ICMS & ST' => [
        ['label'=>'Aliq. Interna','field'=>'icms_aliq_interna','type'=>'num'],
        ['label'=>'Aliq. Interest.','field'=>'icms_aliq_interestadual','type'=>'num'],
        ['label'=>'Crédito SN','field'=>'icms_cred_aliq','type'=>'num'],
        ['label'=>'MVA ST','field'=>'icms_st_mva','type'=>'num'],
        ['label'=>'Aliq. ST','field'=>'icms_st_aliq','type'=>'num'],
        ['label'=>'Red. BC ST','field'=>'icms_st_red_bc','type'=>'num'],
        ['label'=>'FCP / Difal','field'=>'difal_aliq_fcp','type'=>'num'],
    ],
    'PIS / COFINS' => [
        ['label'=>'PIS CST','field'=>'pis_cst','type'=>'text'],
        ['label'=>'PIS Aliq.','field'=>'pis_aliq','type'=>'num'],
        ['label'=>'COFINS CST','field'=>'cofins_cst','type'=>'text'],
        ['label'=>'COFINS Aliq.','field'=>'cofins_aliq','type'=>'num'],
    ],
    'IPI' => [
        ['label'=>'IPI CST','field'=>'ipi_cst','type'=>'text'],
        ['label'=>'IPI Aliq.','field'=>'ipi_aliq','type'=>'num'],
        ['label'=>'IPI Cód. Enq.','field'=>'ipi_cenq','type'=>'text'],
    ],
];

// Renderiza cada grupo como bloco (mantém layout consistente)
foreach ($groups as $groupTitle => $fields) {
    print '<div class="rf-tax-section">';
    print '<div class="rf-tax-title">'.dol_escape_htmltag($groupTitle).'</div>';
    // agrupamos em linhas de 3 colunas por .rf-data-row
    $count = 0;
    print '<div class="rf-data-row">';
    foreach ($fields as $f) {
        $valueRaw = isset($rule->{$f['field']}) ? $rule->{$f['field']} : null;
        if ($f['type'] === 'num') {
            $value = fmt_money($valueRaw);
        } else {
            $value = fmt_text($valueRaw);
        }
        print '<div>';
        print '<div class="rf-data-label">'.dol_escape_htmltag($f['label']).'</div>';
        print '<div class="rf-data-value">'.$value.'</div>';
        print '</div>';
        $count++;
        // quebra automática de linhas é tratada pelo grid; não precisamos forçar <div> de fechamento
    }
    print '</div>'; // fim rf-data-row
    print '</div>'; // fim rf-tax-section
}

print '  </div>'; // rf-view-tax-grid
print '</div>'; // rf-section-card

print '</div>'; // rf-view-sections

// === AÇÕES ========================================================
print '<div class="rf-actions" style="margin-top:24px; background:transparent; border:none; padding:0;">';
print '  <a class="butAction rf-btn rf-btn-secondary" role="button" aria-label="Voltar para lista" href="'.DOL_URL_ROOT.'/custom/nfe/regras_fiscais.php">← Voltar para Lista</a>';
print '  <a class="butAction rf-btn rf-btn-primary" role="button" aria-label="Editar regra" href="'.DOL_URL_ROOT.'/custom/nfe/regras_fiscais.php?action=edit&id='.$rule->rowid.'&token='.$_SESSION['newtoken'].'">✏️ Editar Regra</a>';
print '</div>';
print '</div>'; // container

llxFooter();
$db->close();
?>
