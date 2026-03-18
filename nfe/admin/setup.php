<?php
require '../../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

$langs->load("admin");
$langs->load("nfe@nfe");

// Acesso restrito a administradores
if (!$user->admin) {
    accessforbidden();
}

// --- Exibição do Formulário ---
if (GETPOST('ajax', 'int') === 1) {
    // Carregar Configurações Atuais do Banco
    $sql = "SELECT name, value FROM ".MAIN_DB_PREFIX."nfe_config";
    $resql = $db->query($sql);
    $nfe_configs = array();
    if ($resql) {
        while ($obj = $db->fetch_object($resql)) {
            $nfe_configs[$obj->name] = $obj->value;
        }
    }

    $ambiente_atual = $nfe_configs['ambiente'] ?? '2'; // Valor padrão: Homologação
    $config_json_atual = $nfe_configs['config_json'] ?? '';

    // Formulário
    print '<form id="nfe-setup-form" action="'.DOL_URL_ROOT.'/custom/nfe/admin/setup.php" method="post" enctype="multipart/form-data">';
    print '<input type="hidden" name="action" value="update_setup">';

    // Tabela de Configuração
    print '<table class="noborder" width="100%">';

    // Ambiente de Emissão
    print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("Ambiente de Emissão de Nota Fiscal Eletrônica").'</td></tr>';
    print '<tr><td>'.$langs->trans("Ambiente").'</td><td>';
    print '<select name="ambiente" class="flat">';
    print '<option value="2" '.($ambiente_atual == '2' ? 'selected' : '').'>'.$langs->trans("Homologação").'</option>';
    print '<option value="1" '.($ambiente_atual == '1' ? 'selected' : '').'>'.$langs->trans("Produção").'</option>';
    print '</select>';
    print '</td></tr>';

    // Certificado Digital A1
    print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("Certificado Digital A1").'</td></tr>';
    print '<tr><td>'.$langs->trans("Arquivo do Certificado (.pfx)").'</td>';
    print '<td><input type="file" name="cert_file" class="flat"></td></tr>';
    print '<tr><td>'.$langs->trans("Senha do Certificado").'</td>';
    print '<td><input type="password" name="cert_pass" class="flat" placeholder="'.$langs->trans("Preencher apenas para alterar").'"></td></tr>';

    // Configuração JSON
    print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("Configuração JSON").'</td></tr>';
    print '<tr><td valign="top">'.$langs->trans("Conteúdo do JSON").'</td>';
    print '<td><textarea name="config_json" class="flat" rows="10" style="width:98%;">'.dol_escape_htmltag($config_json_atual).'</textarea></td></tr>';
    print '</table>';
    print '</form>';
    exit;
}

// --- Processamento do Formulário (Ação de Salvar) ---
if (GETPOST('action', 'alpha') === 'update_setup') {
    // Define o cabeçalho como JSON para a resposta AJAX
    header('Content-Type: application/json');

    $db->begin();

    try {
        // Salva o ambiente (captura o valor numérico 1 ou 2)
        $ambiente = (int)GETPOST('ambiente', 'int'); // Garante que seja um número
        if (!in_array($ambiente, [1, 2])) {
            throw new Exception($langs->trans("Valor inválido para o ambiente."));
        }
        $sql = "UPDATE ".MAIN_DB_PREFIX."nfe_config SET value = '".$db->escape($ambiente)."' WHERE name = 'ambiente'";
        if (!$db->query($sql)) { throw new Exception($db->lasterror()); }

        // Salva o JSON de configuração
        $config_json = GETPOST('config_json', 'string');
        $sql = "UPDATE ".MAIN_DB_PREFIX."nfe_config SET value = '".$db->escape($config_json)."' WHERE name = 'config_json'";
        if (!$db->query($sql)) { throw new Exception($db->lasterror()); }

        // Salva a senha do certificado (se fornecida)
        $cert_pass = GETPOST('cert_pass', 'string');
        if (!empty($cert_pass)) {
            $sql = "UPDATE ".MAIN_DB_PREFIX."nfe_config SET value = '".$db->escape($cert_pass)."' WHERE name = 'cert_pass'";
            if (!$db->query($sql)) { throw new Exception($db->lasterror()); }
        }

        // Salva o arquivo do certificado (se enviado)
        if (isset($_FILES['cert_file']) && $_FILES['cert_file']['error'] == UPLOAD_ERR_OK) {
            $pfx_content = file_get_contents($_FILES['cert_file']['tmp_name']);
            $sql = "UPDATE ".MAIN_DB_PREFIX."nfe_config SET value = '".$db->escape($pfx_content)."' WHERE name = 'cert_pfx'";
            if (!$db->query($sql)) { throw new Exception($db->lasterror()); }
        }

        $db->commit();
        echo json_encode(['success' => true, 'message' => $langs->trans("SettingsSaved")]);

    } catch (Exception $e) {
        $db->rollback();
        echo json_encode(['success' => false, 'message' => $langs->trans("Error").": ".$e->getMessage()]);
    }

    $db->close();
    exit;
}

// Renderiza a página completa apenas se não for uma chamada AJAX
llxHeader('', $langs->trans("NFeSetup"));
print load_fiche_titre($langs->trans("NFeSetup"), '', 'nfe@nfe/img/title_generic.png');

// Certificado Digital A1
print '<tr><td>'.$langs->trans("Arquivo do Certificado (.pfx)").'</td>';
print '<td><input type="file" name="cert_file" class="flat"></td></tr>';
print '<tr><td>'.$langs->trans("Senha do Certificado").'</td>';
print '<td><input type="password" name="cert_pass" class="flat" placeholder="'.$langs->trans("Preencher apenas para alterar").'"></td></tr>';

// Configuração JSON
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("Configuração JSON").'</td></tr>';
print '<tr><td valign="top">'.$langs->trans("Conteúdo do JSON").'</td>';
print '<td><textarea name="config_json" class="flat" rows="10" style="width:98%;">'.dol_escape_htmltag($config_json_atual).'</textarea></td></tr>';

print '</table>';

// O botão de submit é gerenciado pelo diálogo do jQuery UI, então não é necessário aqui.
// print '<div class="center"><input type="submit" class="button" value="'.$langs->trans("Save").'"></div>';

print '</form>';

// Se não for AJAX, renderiza footer normalmente
if (!$isAjax) {
    llxFooter();
    $db->close();
    exit;
}

// Se for AJAX, apenas encerra (fragmento já emitido)
$db->close();
exit;
?>
