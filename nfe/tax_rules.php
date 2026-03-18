<?php
require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';

// Idiomas
$langs->load("nfe@nfe");

// Permissões (apenas admin)
if (!$user->admin) {
    accessforbidden();
}

// Helpers
function nfe_help($text) {
    return ' <span class="help" title="'.dol_escape_htmltag($text, 1).'">?</span>';
}
// Helper para exibir valor com fallback e chips
function nfe_display_value($val, $opts = array()) {
    global $langs;
    $v = trim((string)$val);
    if ($v === '') {
        return '<span class="nodata">&mdash;</span>';
    }
    $content = dol_escape_htmltag($v, 1);
    if (!empty($opts['chip'])) {
        $cls = 'chip'.(!empty($opts['class']) ? ' '.preg_replace('/[^a-z0-9\-\s_]/i','',$opts['class']) : '');
        return '<span class="'.$cls.'">'.$content.'</span>';
    }
    return $content;
}

$action = GETPOST('action', 'alpha');
$rowid  = GETPOST('rowid', 'int');
$token  = $_SESSION['newtoken'];

// Regras de CSRF: exigir token para ações sensíveis
if (in_array($action, array('add', 'edit', 'delete'))) {
    if (empty(GETPOST('token')) || GETPOST('token') !== $token) {
        accessforbidden($langs->trans("InvalidCSRFToken"));
    }
}

// Processamento das ações
if ($action === 'add' && !empty($_POST)) {
    $label = trim(GETPOST('label', 'alpha'));
    $active = GETPOSTISSET('active') ? 1 : 0;
    $cfop = trim(GETPOST('cfop', 'alpha'));
    $ncm = trim(GETPOST('ncm', 'alpha'));
    $uf_origin = strtoupper(trim(GETPOST('uf_origin', 'alpha')));
    $uf_dest   = strtoupper(trim(GETPOST('uf_dest', 'alpha')));
    $crt_emitter = (int) GETPOST('crt_emitter', 'int');
    $indiedest_recipient = GETPOST('indiedest_recipient', 'int');
    $product_origin = (int) GETPOST('product_origin', 'int');
    $icms_csosn = trim(GETPOST('icms_csosn', 'alpha'));
    $icms_st_mva = price2num(GETPOST('icms_st_mva', 'alpha'));
    $icms_st_aliq = price2num(GETPOST('icms_st_aliq', 'alpha'));
    $icms_st_predbc = price2num(GETPOST('icms_st_predbc', 'alpha'));
    $pis_cst = trim(GETPOST('pis_cst', 'alpha'));
    $pis_aliq = price2num(GETPOST('pis_aliq', 'alpha'));
    $cofins_cst = trim(GETPOST('cofins_cst', 'alpha'));
    $cofins_aliq = price2num(GETPOST('cofins_aliq', 'alpha'));
    $ipi_cst = trim(GETPOST('ipi_cst', 'alpha'));
    $ipi_aliq = price2num(GETPOST('ipi_aliq', 'alpha'));
    $ipi_cenq = trim(GETPOST('ipi_cenq', 'alpha'));
    $icms_interestadual_aliq = trim(GETPOST('icms_interestadual_aliq', 'alpha'));
    $icms_cred_aliq = trim(GETPOST('icms_cred_aliq', 'alpha'));
    $aliq_interna_dest = trim(GETPOST('aliq_interna_dest', 'alpha'));

    $errors = array();
    if ($label === '') $errors[] = $langs->trans("O campo 'Nome da Regra' é obrigatório.");
    if ($uf_origin === '') $errors[] = $langs->trans("UF Origem é obrigatória.");
    if ($uf_dest === '') $errors[] = $langs->trans("UF Destino é obrigatória.");
    if (empty($crt_emitter)) $errors[] = $langs->trans("Regime Tributário é obrigatório.");

    if (empty($errors)) {
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."custom_tax_rules (
                    label, active, cfop, ncm, uf_origin, uf_dest, crt_emitter, indiedest_recipient,
                    product_origin, icms_csosn, icms_st_mva, icms_st_aliq, icms_st_predbc,
                    pis_cst, pis_aliq, cofins_cst, cofins_aliq, ipi_cst, ipi_aliq, ipi_cenq, icms_interestadual_aliq, icms_cred_aliq, aliq_interna_dest
                ) VALUES (
                    '".$db->escape($label)."', ".$active.", '".$db->escape($cfop)."', '".$db->escape($ncm)."', '".$db->escape($uf_origin)."', '".$db->escape($uf_dest)."', ".$crt_emitter.", ".($indiedest_recipient !== '' ? (int)$indiedest_recipient : 'NULL').",
                    ".$product_origin.", '".$db->escape($icms_csosn)."', ".($icms_st_mva !== '' ? (float)$icms_st_mva : '0').", ".($icms_st_aliq !== '' ? (float)$icms_st_aliq : '0').", ".($icms_st_predbc !== '' ? (float)$icms_st_predbc : '0').",
                    '".$db->escape($pis_cst)."', ".($pis_aliq !== '' ? (float)$pis_aliq : '0').", '".$db->escape($cofins_cst)."', ".($cofins_aliq !== '' ? (float)$cofins_aliq : '0').", '".$db->escape($ipi_cst)."', ".($ipi_aliq !== '' ? (float)$ipi_aliq : '0').", '".$db->escape($ipi_cenq)."', '".$db->escape($icms_interestadual_aliq)."', '".$db->escape($icms_cred_aliq)."', '".$db->escape($aliq_interna_dest)."'
                )";
        if ($db->query($sql)) {
            setEventMessages($langs->trans("Regra adicionada com sucesso."), null, 'mesgs');
            header('Location: '.$_SERVER['PHP_SELF']);
            exit;
        } else {
            setEventMessages($db->lasterror(), null, 'errors');
        }
    } else {
        setEventMessages('', $errors, 'errors');
    }
}

if ($action === 'edit' && !empty($_POST) && $rowid > 0) {
    $label = trim(GETPOST('label', 'alpha'));
    $active = GETPOSTISSET('active') ? 1 : 0;
    $cfop = trim(GETPOST('cfop', 'alpha'));
    $ncm = trim(GETPOST('ncm', 'alpha'));
    $uf_origin = strtoupper(trim(GETPOST('uf_origin', 'alpha')));
    $uf_dest   = strtoupper(trim(GETPOST('uf_dest', 'alpha')));
    $crt_emitter = (int) GETPOST('crt_emitter', 'int');
    $indiedest_recipient = GETPOST('indiedest_recipient', 'int');
    $product_origin = (int) GETPOST('product_origin', 'int');
    $icms_csosn = trim(GETPOST('icms_csosn', 'alpha'));
    $icms_st_mva = price2num(GETPOST('icms_st_mva', 'alpha'));
    $icms_st_aliq = price2num(GETPOST('icms_st_aliq', 'alpha'));
    $icms_st_predbc = price2num(GETPOST('icms_st_predbc', 'alpha'));
    $pis_cst = trim(GETPOST('pis_cst', 'alpha'));
    $pis_aliq = price2num(GETPOST('pis_aliq', 'alpha'));
    $cofins_cst = trim(GETPOST('cofins_cst', 'alpha'));
    $cofins_aliq = price2num(GETPOST('cofins_aliq', 'alpha'));
    $ipi_cst = trim(GETPOST('ipi_cst', 'alpha'));
    $ipi_aliq = price2num(GETPOST('ipi_aliq', 'alpha'));
    $ipi_cenq = trim(GETPOST('ipi_cenq', 'alpha'));
    $icms_interestadual_aliq = trim(GETPOST('icms_interestadual_aliq', 'alpha'));
    $icms_cred_aliq = trim(GETPOST('icms_cred_aliq', 'alpha'));
    $aliq_interna_dest = trim(GETPOST('aliq_interna_dest', 'alpha'));

    $sql = "UPDATE ".MAIN_DB_PREFIX."custom_tax_rules SET
            label='".$db->escape($label)."', 
            active=".$active.", 
            cfop='".$db->escape($cfop)."', 
            ncm='".$db->escape($ncm)."',
            uf_origin='".$db->escape($uf_origin)."', 
            uf_dest='".$db->escape($uf_dest)."', 
            crt_emitter=".$crt_emitter.", 
            indiedest_recipient=".(($indiedest_recipient !== '') ? (int)$indiedest_recipient : 'NULL').",
            product_origin=".$product_origin.", 
            icms_csosn='".$db->escape($icms_csosn)."', 
            icms_st_mva=".(($icms_st_mva !== '') ? (float)$icms_st_mva : '0.00').",
            icms_st_aliq=".(($icms_st_aliq !== '') ? (float)$icms_st_aliq : '0.00').", 
            icms_st_predbc=".(($icms_st_predbc !== '') ? (float)$icms_st_predbc : '0.00').",
            pis_cst='".$db->escape($pis_cst)."', 
            pis_aliq=".(($pis_aliq !== '') ? (float)$pis_aliq : '0.00').",
            cofins_cst='".$db->escape($cofins_cst)."', 
            cofins_aliq=".(($cofins_aliq !== '') ? (float)$cofins_aliq : '0.00').",
            ipi_cst='".$db->escape($ipi_cst)."', 
            ipi_aliq=".(($ipi_aliq !== '') ? (float)$ipi_aliq : '0.00').", 
            ipi_cenq='".$db->escape($ipi_cenq)."', 
            icms_interestadual_aliq=".(($icms_interestadual_aliq !== '') ? (float)$icms_interestadual_aliq : '0.00').", 
            icms_cred_aliq=".(($icms_cred_aliq !== '') ? (float)$icms_cred_aliq : '0.00').",
            aliq_interna_dest=".(($aliq_interna_dest !== '') ? (float)$aliq_interna_dest : '0.00')."
        WHERE rowid=".$rowid;
    if ($db->query($sql)) {
        setEventMessages($langs->trans("Regra atualizada com sucesso."), null, 'mesgs');
        header('Location: '.$_SERVER['PHP_SELF']);
        exit;
    } else {
        setEventMessages($db->lasterror(), null, 'errors');
    }
}

if ($action === 'delete' && $rowid > 0) {
    $sql = "DELETE FROM ".MAIN_DB_PREFIX."custom_tax_rules WHERE rowid = ".$rowid;
    if ($db->query($sql)) {
        setEventMessages($langs->trans("Regra deletada com sucesso."), null, 'mesgs');
    } else {
        setEventMessages($db->lasterror(), null, 'errors');
    }
    header('Location: '.$_SERVER['PHP_SELF']);
    exit;
}

// --- Visual ---
$page_name = $langs->trans("Regras Fiscais");
llxHeader('', $page_name);
print load_fiche_titre($page_name, '', 'title_setup');

// Formulários de Inclusão/Edição
if ($action === 'add_form' || ($action === 'edit_form' && $rowid > 0)) {
    $isEdit = ($action === 'edit_form');
    $obj = null;
    if ($isEdit) {
        $sql = "SELECT * FROM ".MAIN_DB_PREFIX."custom_tax_rules WHERE rowid = ".$rowid;
        $resql = $db->query($sql);
        if ($resql && $db->num_rows($resql) > 0) $obj = $db->fetch_object($resql);
        else setEventMessages($langs->trans("Regra não encontrada."), null, 'errors');
    }

    $urlAction = $_SERVER['PHP_SELF'].'?action='.($isEdit?'edit':'add').($isEdit?'&rowid='.$rowid:'');

    print '<form method="POST" action="'.$urlAction.'" class="form-tax-rules">';
    print '<input type="hidden" name="token" value="'.$token.'">';

    print '<div class="fichecenter view-tax-rules">';

    // Informações Básicas (card)
    print '<div class="fichehalfleft">';
    print '<div class="view-card">';
    print '<h3 class="viewblock-title"><span class="fas fa-info-circle"></span> '.$langs->trans("Informações Básicas").'</h3>';
    print '<table class="noborder viewtable centpercent">';

    print '<tr><td class="pair"><span class="k">'.$langs->trans("Nome da Regra").'</span><span class="v"><input class="flat minwidth300" type="text" name="label" value="'.($obj?dol_escape_htmltag($obj->label,1):'').'" placeholder="'.$langs->trans("Ex: Venda SN ES").'" required></span></td></tr>';

    print '<tr><td class="pair"><span class="k">'.$langs->trans("Ativo").'</span><span class="v"><label class="checkbox-inline"><input class="flat" type="checkbox" name="active" '.(($obj && (int)$obj->active===1)?'checked':'').'> '.$langs->trans("Sim").'</label></span></td></tr>';

    print '<tr><td class="pair"><span class="k">'.$langs->trans("CFOP").'</span><span class="v"><input class="flat minwidth150" type="text" name="cfop" value="'.($obj?dol_escape_htmltag($obj->cfop,1):'').'" placeholder="Ex: 5102"></span></td></tr>';

    print '<tr><td class="pair"><span class="k">'.$langs->trans("NCM").'</span><span class="v"><input class="flat minwidth200" type="text" name="ncm" value="'.($obj?dol_escape_htmltag($obj->ncm,1):'').'" placeholder="Ex: 84713012"></span></td></tr>';

    print '<tr><td class="pair"><span class="k">'.$langs->trans("UF Origem").'</span><span class="v"><input class="flat minwidth75" maxlength="2" type="text" name="uf_origin" value="'.($obj?dol_escape_htmltag($obj->uf_origin,1):'').'" placeholder="Ex: ES" required></span></td></tr>';

    print '<tr><td class="pair"><span class="k">'.$langs->trans("UF Destino").'</span><span class="v"><input class="flat minwidth75" maxlength="2" type="text" name="uf_dest" value="'.($obj?dol_escape_htmltag($obj->uf_dest,1):'').'" placeholder="Ex: SP" required></span></td></tr>';

    print '<tr><td class="pair"><span class="k">'.$langs->trans("Regime Tributário").'</span><span class="v"><select class="flat minwidth200" name="crt_emitter" required>';
    $crt = $obj?$obj->crt_emitter:'';
    foreach (array(1=>'Simples Nacional',2=>'Excesso',3=>'Normal') as $k=>$v) print '<option value="'.$k.'" '.((string)$crt===(string)$k?'selected':'').'>'.$k.' - '.$v.'</option>';
    print '</select></span></td></tr>';

    print '<tr><td class="pair"><span class="k">'.$langs->trans("Indicador IE").'</span><span class="v"><select class="flat minwidth200" name="indiedest_recipient">';
    $ie = $obj?$obj->indiedest_recipient:'';
    foreach (array(''=>'',1=>'1 - Contribuinte',2=>'2 - Isento',9=>'9 - Não contribuinte') as $k=>$v) print '<option value="'.$k.'" '.(((string)$ie!=='') && (string)$ie===(string)$k?'selected':'').'>'.$v.'</option>';
    print '</select></span></td></tr>';

    print '<tr><td class="pair"><span class="k">'.$langs->trans("Origem do Produto").'</span><span class="v"><select class="flat minwidth250" name="product_origin">';
    $po = $obj?$obj->product_origin:0;
    foreach (array(0=>'0 - Nacional',1=>'1 - Estrangeira') as $k=>$v) print '<option value="'.$k.'" '.((string)$po===(string)$k?'selected':'').'>'.$v.'</option>';
    print '</select></span></td></tr>';

    print '</table>';
    print '</div>';
    print '</div>';

    // Tributação (card)
print '<div class="fichehalfright">';
print '<div class="view-card">';
print '<h3 class="viewblock-title"><span class="fas fa-balance-scale"></span> '.$langs->trans("Tributação").'</h3>';
print '<table class="noborder viewtable centpercent">';

// row 1: ICMS CSOSN | IPI CEnq
print '<tr>';
print '<td class="pair"><span class="k">'.$langs->trans("ICMS CSOSN").'</span><span class="v"><input class="flat minwidth150" type="text" name="icms_csosn" value="'.($obj?dol_escape_htmltag($obj->icms_csosn,1):'').'" placeholder="102"></span></td>';
print '<td class="pair"><span class="k">'.$langs->trans("IPI CEnq").'</span><span class="v"><input class="flat minwidth150" type="text" name="ipi_cenq" value="'.($obj?dol_escape_htmltag($obj->ipi_cenq,1):'999').'" placeholder="999"></span></td>';
print '</tr>';

// row 2: ICMS ST MVA | ICMS ST Alíquota
print '<tr>';
print '<td class="pair"><span class="k">'.$langs->trans("ICMS ST MVA (%)").'</span><span class="v"><input class="flat minwidth150" type="number" step="0.0001" name="icms_st_mva" value="'.($obj?dol_escape_htmltag($obj->icms_st_mva,1):'0.0000').'" placeholder="0.0000"></span></td>';
print '<td class="pair"><span class="k">'.$langs->trans("ICMS ST Alíquota (%)").'</span><span class="v"><input class="flat minwidth150" type="number" step="0.01" name="icms_st_aliq" value="'.($obj?dol_escape_htmltag($obj->icms_st_aliq,1):'0.00').'" placeholder="0.00"></span></td>';
print '</tr>';

// row 3: ICMS ST Redução BC | PIS CST
print '<tr>';
print '<td class="pair"><span class="k">'.$langs->trans("ICMS ST Redução BC (%)").'</span><span class="v"><input class="flat minwidth150" type="number" step="0.01" name="icms_st_predbc" value="'.($obj?dol_escape_htmltag($obj->icms_st_predbc,1):'0.00').'" placeholder="0.00"></span></td>';
print '<td class="pair"><span class="k">'.$langs->trans("PIS CST").'</span><span class="v"><input class="flat minwidth150" type="text" name="pis_cst" value="'.($obj?dol_escape_htmltag($obj->pis_cst,1):'').'" placeholder=""></span></td>';
print '</tr>';

// row 4: PIS Alíquota | COFINS CST
print '<tr>';
print '<td class="pair"><span class="k">'.$langs->trans("PIS Alíquota (%)").'</span><span class="v"><input class="flat minwidth150" type="number" step="0.01" name="pis_aliq" value="'.($obj?dol_escape_htmltag($obj->pis_aliq,1):'0.00').'" placeholder="0.00"></span></td>';
print '<td class="pair"><span class="k">'.$langs->trans("COFINS CST").'</span><span class="v"><input class="flat minwidth150" type="text" name="cofins_cst" value="'.($obj?dol_escape_htmltag($obj->cofins_cst,1):'').'" placeholder=""></span></td>';
print '</tr>';

// row 5: COFINS Alíquota | IPI CST
print '<tr>';
print '<td class="pair"><span class="k">'.$langs->trans("COFINS Alíquota (%)").'</span><span class="v"><input class="flat minwidth150" type="number" step="0.01" name="cofins_aliq" value="'.($obj?dol_escape_htmltag($obj->cofins_aliq,1):'0.00').'" placeholder=""></span></td>';
print '<td class="pair"><span class="k">'.$langs->trans("IPI CST").'</span><span class="v"><input class="flat minwidth150" type="text" name="ipi_cst" value="'.($obj?dol_escape_htmltag($obj->ipi_cst,1):'').'" placeholder=""></span></td>';
print '</tr>';

// row 6: IPI Alíquota | ICMS ST Alíquota Interestadual
print '<tr>';
print '<td class="pair"><span class="k">'.$langs->trans("IPI Alíquota (%)").'</span><span class="v"><input class="flat minwidth150" type="number" step="0.01" name="ipi_aliq" value="'.($obj?dol_escape_htmltag($obj->ipi_aliq,1):'0.00').'" placeholder="0.00"></span></td>';
print '<td class="pair"><span class="k">'.$langs->trans("ICMS ST Alíquota Interstadual(%)").'</span><span class="v"><input class="flat minwidth150" type="number" step="0.01" name="icms_interestadual_aliq" value="'.($obj?dol_escape_htmltag($obj->icms_interestadual_aliq,1):'0.00').'" placeholder="0.00"></span></td>';
print '</tr>';

// row 7: ICMS ST Alíquota de Crédito | Alíquota ICMS Interestadual DIFAL
print '<tr>';
print '<td class="pair"><span class="k">'.$langs->trans("ICMS ST Alíquota de Crédito(%)").'</span><span class="v"><input class="flat minwidth150" type="number" step="0.01" name="icms_cred_aliq" value="'.($obj?dol_escape_htmltag($obj->icms_cred_aliq,1):'0.00').'" placeholder="0.00"></span></td>';
print '<td class="pair"><span class="k">'.$langs->trans("Alíquota ICMS Interestadual DIFAL(%)").'</span><span class="v"><input class="flat minwidth150" type="number" step="0.01" name="aliq_interna_dest" value="'.($obj?dol_escape_htmltag($obj->aliq_interna_dest,1):'0.00').'" placeholder="0.00"></span></td>';
print '</tr>';

print '</table>';
print '</div>';
print '</div>';

    print '</div>';

    // Barra de ações centralizada
    print '<div class="form-actions center">';
    print '<button type="submit" class="button">'.($isEdit?$langs->trans("Salvar"):$langs->trans("Adicionar")).'</button> ';
    print '<a class="button" href="'.$_SERVER['PHP_SELF'].'">'.$langs->trans("Cancelar").'</a>';
    print '</div>';

    print '</form>';

} elseif ($action === 'view' && $rowid > 0) {
    $sql = "SELECT * FROM ".MAIN_DB_PREFIX."custom_tax_rules WHERE rowid = ".$rowid;
    $resql = $db->query($sql);
    if ($resql && $db->num_rows($resql) > 0) {
        $o = $db->fetch_object($resql);

        // Título com badge de status
        $title = dol_escape_htmltag($o->label,1).' '.(((int)$o->active)
            ? '<span class="status-badge active">'.$langs->trans("Ativa").'</span>'
            : '<span class="status-badge inactive">'.$langs->trans("Inativa").'</span>');
        print load_fiche_titre($title, '', '');

        // Mapas amigáveis
        $crtLabel = ($o->crt_emitter == 1 ? '1 - Simples Nacional' : ($o->crt_emitter == 2 ? '2 - Excesso' : '3 - Normal'));
        $ieMap = array(1=>'1 - Contribuinte',2=>'2 - Isento',9=>'9 - Não contribuinte');
        $ieLabel = isset($ieMap[(int)$o->indiedest_recipient]) ? $ieMap[(int)$o->indiedest_recipient] : '';
        $poLabel = ((int)$o->product_origin === 0) ? '0 - Nacional' : '1 - Estrangeira (Importação direta ou adquirida no mercado interno)';

        print '<div class="fichecenter view-tax-rules">';

        // Informações Básicas (card)
        print '<div class="fichehalfleft">';
        print '<div class="view-card">';
        print '<h3 class="viewblock-title"><span class="fas fa-info-circle"></span> '.$langs->trans("Informações Básicas").'</h3>';
        print '<table class="noborder viewtable centpercent">';
        print '<tr>';
        print '<td class="pair"><span class="k">'.$langs->trans("CFOP").'</span><span class="v">'.nfe_display_value($o->cfop, array('chip'=>true,'class'=>'chip-strong chip-emph')).'</span></td>';
        print '<td class="pair"><span class="k">'.$langs->trans("NCM").'</span><span class="v">'.nfe_display_value($o->ncm, array('chip'=>true,'class'=>'chip-ncm')).'</span></td>';
        print '</tr>';
        print '<tr>';
        print '<td class="pair"><span class="k">'.$langs->trans("UF Origem").'</span><span class="v">'.nfe_display_value($o->uf_origin).'</span></td>';
        print '<td class="pair"><span class="k">'.$langs->trans("UF Destino").'</span><span class="v">'.nfe_display_value($o->uf_dest).'</span></td>';
        print '</tr>';
        print '<tr>';
        print '<td class="pair"><span class="k">'.$langs->trans("Regime Tributário").'</span><span class="v">'.nfe_display_value($crtLabel).'</span></td>';
        print '<td class="pair"><span class="k">'.$langs->trans("Indicador IE").'</span><span class="v">'.nfe_display_value($ieLabel).'</span></td>';
        print '</tr>';
        print '<tr>';
        print '<td class="pair"><span class="k">'.$langs->trans("Origem do Produto").'</span><span class="v">'.nfe_display_value($poLabel).'</span></td>';
        print '<td class="pair"></td>';
        print '</tr>';
        print '</table>';
        print '</div>';
        print '</div>';

        // Tributação (card)
        print '<div class="fichehalfright">';
        print '<div class="view-card">';
        print '<h3 class="viewblock-title"><span class="fas fa-balance-scale"></span> '.$langs->trans("Tributação").'</h3>';
        print '<table class="noborder viewtable centpercent">';
        print '<tr>';
        print '<td class="pair"><span class="k">'.$langs->trans("ICMS CSOSN").'</span><span class="v">'.nfe_display_value($o->icms_csosn, array('chip'=>true,'class'=>'chip-emph')).'</span></td>';
        print '<td class="pair"><span class="k">'.$langs->trans("IPI CEnq").'</span><span class="v">'.nfe_display_value($o->ipi_cenq, array('chip'=>true,'class'=>'chip-emph')).'</span></td>';
        print '</tr>';
        print '<tr>';
        print '<td class="pair"><span class="k">'.$langs->trans("ICMS ST MVA(%)").'</span><span class="v">'.nfe_display_value($o->icms_st_mva).'</span></td>';
        print '<td class="pair"><span class="k">'.$langs->trans("ICMS ST Alíquota(%)").'</span><span class="v">'.nfe_display_value($o->icms_st_aliq).'</span></td>';
        print '</tr>';
        print '<tr>';
        print '<td class="pair"><span class="k">'.$langs->trans("ICMS ST Redução BC(%)").'</span><span class="v">'.nfe_display_value($o->icms_st_predbc).'</span></td>';
        print '<td class="pair"><span class="k">'.$langs->trans("PIS CST").'</span><span class="v">'.nfe_display_value($o->pis_cst, array('chip'=>true,'class'=>'chip-emph')).'</span></td>';
        print '</tr>';
        print '<tr>';
        print '<td class="pair"><span class="k">'.$langs->trans("PIS Alíquota(%)").'</span><span class="v">'.nfe_display_value($o->pis_aliq).'</span></td>';
        print '<td class="pair"><span class="k">'.$langs->trans("COFINS CST").'</span><span class="v">'.nfe_display_value($o->cofins_cst, array('chip'=>true,'class'=>'chip-emph')).'</span></td>';
        print '</tr>';
        print '<tr>';
        print '<td class="pair"><span class="k">'.$langs->trans("COFINS Alíquota(%)").'</span><span class="v">'.nfe_display_value($o->cofins_aliq).'</span></td>';
        print '<td class="pair"><span class="k">'.$langs->trans("IPI CST").'</span><span class="v">'.nfe_display_value($o->ipi_cst, array('chip'=>true,'class'=>'chip-strong')).'</span></td>';
        print '</tr>';
        print '<tr>';
        print '<td class="pair"><span class="k">'.$langs->trans("IPI Alíquota(%)").'</span><span class="v">'.nfe_display_value($o->ipi_aliq).'</span></td>';       
        print '<td class="pair"><span class="k">'.$langs->trans("ICMS ST Alíquota Interestadual(%)").'</span><span class="v">'.nfe_display_value($o->icms_interestadual_aliq).'</span></td>';
        print '<td class="pair"></td>';
        print '</tr>';

        print '<tr>';
        print '<td class="pair"><span class="k">'.$langs->trans("ICMS ST Alíquota Interestadual Credito(%)").'</span><span class="v">'.nfe_display_value($o->icms_cred_aliq).'</span></td>';
        print '<td class="pair"><span class="k">'.$langs->trans("Alíquota ICMS Interestadual DIFAL(%)").'</span><span class="v">'.nfe_display_value($o->aliq_interna_dest).'</span></td>';
        print '<td class="pair"></td>';
        print '</tr>';
        //print '<tr>;
        //print '<td class="pair"><span class="k">'.$langs->trans("Alíquota ICMS Interestadual DIFAL(%)").'</span><span class="v"><input class="flat minwidth150" type="number" step="0.01" name="aliq_interna_dest" value="'.($obj?dol_escape_htmltag($obj->aliq_interna_dest,1):'0.00').'" placeholder="0.00"></span></td></tr>';
        print '</table>';
        print '</div>';
        print '</div>';

        print '</div>'; // fichecenter

        // Ações
        print '<div class="form-actions center">';
        print '<a class="button" href="'.$_SERVER['PHP_SELF'].'?action=edit_form&rowid='.(int)$o->rowid.'&token='.$token.'">'.$langs->trans("Editar").'</a> ';
        print '<a class="button" href="'.$_SERVER['PHP_SELF'].'">'.$langs->trans("Voltar").'</a>';
        print '</div>';
    } else {
        setEventMessages($langs->trans("Regra não encontrada."), null, 'errors');
    }
} else {
    // Lista
    print '<div class="tabsAction tabs-left">';
    print '<a class="button" href="'.$_SERVER['PHP_SELF'].'?action=add_form&token='.$token.'">'.$langs->trans("Adicionar Nova Regra").'</a>';
    print '</div>';

    $sql = "SELECT rowid, label, active, cfop, ncm, uf_origin, uf_dest, crt_emitter, icms_csosn FROM ".MAIN_DB_PREFIX."custom_tax_rules ORDER BY rowid DESC";
    $resql = $db->query($sql);

    print '<table class="noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<th>'.$langs->trans("Nome da Regra").'</th>';
    print '<th class="center">'.$langs->trans("Ativo").'</th>';
    print '<th>'.$langs->trans("CFOP").'</th>';
    print '<th>'.$langs->trans("NCM").'</th>';
    print '<th>'.$langs->trans("UF Origem").'</th>';
    print '<th>'.$langs->trans("UF Destino").'</th>';
    print '<th>'.$langs->trans("CSOSN").'</th>';
    print '<th class="right">'.$langs->trans("Ações").'</th>';
    print '</tr>';

    if ($resql && $db->num_rows($resql) > 0) {
        $odd = false;
        while ($o = $db->fetch_object($resql)) {
            $odd = !$odd;
            print '<tr class="'.($odd ? 'oddeven' : '').'">';
            print '<td><a href="'.$_SERVER['PHP_SELF'].'?action=view&rowid='.(int)$o->rowid.'&token='.$token.'">'.dol_escape_htmltag($o->label,1).'</a></td>';
            print '<td class="center">'.(((int)$o->active)?$langs->trans("Sim"): $langs->trans("Não")).'</td>';
            print '<td>'.dol_escape_htmltag($o->cfop,1).'</td>';
            print '<td>'.dol_escape_htmltag($o->ncm,1).'</td>';
            print '<td>'.dol_escape_htmltag($o->uf_origin,1).'</td>';
            print '<td>'.dol_escape_htmltag($o->uf_dest,1).'</td>';
            print '<td>'.dol_escape_htmltag($o->icms_csosn,1).'</td>';
            print '<td class="right">';
            print '<a class="button-action edit" href="'.$_SERVER['PHP_SELF'].'?action=edit_form&rowid='.(int)$o->rowid.'&token='.$token.'">'.$langs->trans("Editar").'</a> ';
            print '<a class="button-action delete" href="'.$_SERVER['PHP_SELF'].'?action=delete&rowid='.(int)$o->rowid.'&token='.$token.'" onclick="return confirm(\''.$langs->trans("Tem certeza que deseja deletar?").'\');">'.$langs->trans("Excluir").'</a>';
            print '</td>';
            print '</tr>';
        }
    } else {
        print '<tr><td colspan="8" class="center">'.$langs->trans("Nenhuma regra fiscal encontrada.").'</td></tr>';
    }
    print '</table>';
}

llxFooter();
$db->close();
?>
<style>
/* Badges de status */
.status-badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:12px;vertical-align:middle}
.status-badge.active{font-size:15px;background:#e6f4ea;color:#1e7e34;border:1px solid #b5e0c7}
.status-badge.inactive{font-size:15px;background:#fdecea;color:#a71d2a;border:1px solid #f5c2c7}
/* Cards de visualização */
.view-card{background:#fff;border:1px solid #eaeaea;border-radius:6px;padding:12px;margin-bottom:10px}
/* Visualização em pares (duas colunas) */
.viewblock-title{font-weight:600;color:#333;margin:0 0 8px}
.viewtable{border-collapse:collapse}
.viewtable td.pair{width:50%;vertical-align:top;padding:10px 12px;border-top:1px solid #e9ecef}
.viewtable tr:first-child td.pair{border-top:0}
.viewtable .k{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.3px;color:#6c757d}
.viewtable .v{display:block;margin-top:2px;font-weight:500;color:#212529}
/* Chips para códigos */
.chip{display:inline-block;padding:2px 8px;border-radius:12px;background:#f1f3f5;color:#495057;font-weight:500;font-family:monospace;font-size:12px}
.chip-strong{background:#e7f1ff;color:#0b5ed7}
.chip-ncm{font-size:15px;font-weight:600}
.chip-emph{font-size:15px;font-weight:600}
/* Indicador de ausência de dados */
.nodata{color:#9aa0a6}
.tabsAction.tabs-left{ text-align:left }
/* Ajustes para formularios usando viewtable */
.viewtable td{padding:10px 12px}
.viewtable tr + tr td{border-top:1px solid #e9ecef}
.viewtable .titlefield{width:35%;vertical-align:middle}
/* Estilo para os botões de ação na aba 'Ações' */
.button-action {
    display: inline-block;
    padding: 6px 12px;
    margin: 0 4px;
    font-size: 14px;
    color: #fff;
    text-decoration: none;
    border-radius: 4px;
    transition: background-color 0.3s ease;
}
.button-action.edit {
    background-color: #007bff;
}
.button-action.edit:hover {
    background-color: #0056b3;
}
.button-action.delete {
    background-color: #dc3545;
}
.button-action.delete:hover {
    background-color: #a71d2a;
}
</style>
