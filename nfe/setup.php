<?php
require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';


$langs->load("nfe@nfe");

if (!$user->rights->nfe->read && !$user->admin) {
    accessforbidden();
}

$action = GETPOST('action', 'alpha');
require_once DOL_DOCUMENT_ROOT.'/custom/labapp/class/nfecertificate.class.php';
function nfeConfigUpsertLocal(DoliDB $db, string $name, $value): void {
    $sql = "INSERT INTO ".MAIN_DB_PREFIX."nfe_config (name, value) VALUES ('".$db->escape($name)."', ".($value === null ? "NULL" : "'".$db->escape($value)."'").")
            ON DUPLICATE KEY UPDATE value = VALUES(value)";
    $db->query($sql);
}

if ($action === 'savesetup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_POST['token']) || $_POST['token'] !== $_SESSION['newtoken']) {
        setEventMessages($langs->trans("ErrorBadToken"), null, 'errors');
    } else {
        $db->begin();
        try {
            $amb = GETPOST('ambiente', 'int');
            if (!in_array($amb, array(1, 2))) $amb = 2; 
            nfeConfigUpsertLocal($db, 'ambiente', $amb);

            $pass = GETPOST('cert_pass', 'restricthtml');
            if ($pass !== '') nfeConfigUpsertLocal($db, 'cert_pass', $pass);

            if (!empty($_FILES['cert_pfx_file']) && is_uploaded_file($_FILES['cert_pfx_file']['tmp_name'])) {
                $pfxContent = file_get_contents($_FILES['cert_pfx_file']['tmp_name']);
                
                // Obtém a senha (usa a nova se informada, senão usa a existente)
                $certPassword = ($pass !== '') ? $pass : ($cfg['cert_pass'] ?? '');
                
                // Processa e converte o certificado se necessário
                $certHandler = new NfeCertificate();
                $result = $certHandler->processAndConvert($pfxContent, $certPassword);
                
                if ($result === false) {
                    throw new Exception('Erro ao processar certificado: ' . $certHandler->error);
                }
                
                // Salva o certificado (possivelmente convertido)
                nfeConfigUpsertLocal($db, 'cert_pfx', $result['pfx']);
                
                // Mensagem informativa sobre conversão
                if ($result['converted']) {
                    setEventMessages('Certificado convertido automaticamente para compatibilidade com OpenSSL 3.x.', null, 'warnings');
                }
            }
            
            $db->commit();
            setEventMessages($langs->trans("Configurações salvas com sucesso."), null, 'mesgs');
        } catch (Exception $e) {
            $db->rollback();
            setEventMessages($e->getMessage(), null, 'errors');
        }
    }
}

llxHeader('', $langs->trans("Configuração NFe"));

$linkback = '<a href="'.DOL_URL_ROOT.'/custom/nfe/list.php">'.$langs->trans("Voltar para Lista").'</a>';
print load_fiche_titre($langs->trans("Configuração NFe"), $linkback, 'nfe@nfe/img/title_generic.png');

$cfg = array();
$res = $db->query("SELECT name, value FROM ".MAIN_DB_PREFIX."nfe_config WHERE name IN ('ambiente','cert_pfx','cert_pass')");
if ($res) {
    while ($o = $db->fetch_object($res)) $cfg[$o->name] = $o->value;
}

$ambiente = $cfg['ambiente'] ?? '2';
$hasPfx = !empty($cfg['cert_pfx']);
$pass = !empty($cfg['cert_pass']) ? dol_escape_htmltag($cfg['cert_pass']) : '';
$hasPass = !empty($cfg['cert_pass']); // indica se há senha armazenada

// Removida exibição de dados sensíveis do certificado.
// Apenas indicaremos de forma não sensível se há um certificado armazenado.
$certInfoHtml = '';
if ($hasPfx) {
    $certInfoHtml = '<div style="margin-top:8px;"><span class="badge badge-status4" title="'.$langs->trans("Um certificado está presente no sistema").'"><i class="fa fa-shield"></i> '.$langs->trans("Certificado armazenado.").'</span></div>';
}

// Start of UI improvements
$head = array();
$head[0][0] = $_SERVER['PHP_SELF'];
$head[0][1] = $langs->trans("Parâmetros");
$head[0][2] = 'settings';

print dol_get_fiche_head($head, 'settings', $langs->trans("Configuração do Módulo NFe"), -1, 'nfe@nfe');

print '<span class="opacitymedium">'.$langs->trans("Configure aqui os dados de acesso à API da NFe e o certificado digital.").'</span><br><br>';

print '<form action="'.$_SERVER['PHP_SELF'].'" method="post" enctype="multipart/form-data">';
print '<input type="hidden" name="token" value="'.dol_escape_htmltag($_SESSION['newtoken']).'"/>';
print '<input type="hidden" name="action" value="savesetup"/>';

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent">';

// Ambiente Section
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("Ambiente de Emissão").'</td></tr>';

print '<tr><td class="titlefield">'.$langs->trans("Ambiente").'</td><td>';
print '<input type="radio" name="ambiente" id="amb_homolog" value="2" '.($ambiente == '2' ? 'checked' : '').'> <label for="amb_homolog">'.$langs->trans("Homologação").'</label> &nbsp;&nbsp;';
print '<input type="radio" name="ambiente" id="amb_prod" value="1" '.($ambiente == '1' ? 'checked' : '').'> <label for="amb_prod">'.$langs->trans("Produção").'</label>';
print '</td></tr>';

// Certificado Section
print '<tr class="liste_titre"><td colspan="2">'.$langs->trans("Certificado Digital").'</td></tr>';

print '<tr><td class="titlefield">'.$langs->trans("Arquivo do Certificado (.pfx/.p12)").'</td><td>';
print '<input type="file" name="cert_pfx_file" accept=".pfx,.p12"/> ';
if ($hasPfx) {
    // Nova forma de indicar que já existe certificado armazenado (sem revelar dados)
    print $certInfoHtml;
}
print '</td></tr>';

print '<tr><td>'.$langs->trans("Senha do Certificado").'</td><td>';
print '<input type="password" name="cert_pass" id="cert_pass" value="'.$pass.'" autocomplete="new-password" class="minwidth200"/>';
print '<a href="#" id="togglePass" style="margin-left: 8px; text-decoration: none; color: #666;" title="'.$langs->trans("Visualizar Senha").'"><i class="fa fa-eye"></i></a>';

print '<div style="margin-top:8px;">';
if ($hasPass) {
    print '<div><span class="badge badge-status4" title="'.$langs->trans("Uma senha do certificado já está armazenada no sistema e não é exibida por segurança").'"><i class="fa fa-key"></i> '.$langs->trans("Senha armazenada").'</span></div>';
}
print '<div class="opacitymedium" style="margin-top:6px; font-size:0.85em;">'.$langs->trans("Deixe em branco para manter a senha atual.").'</div>';
print '</div>';

print '</td></tr>';

print '</table>';
print '</div>';

print dol_get_fiche_end();

print '<div class="center"><input type="submit" class="button" value="'.$langs->trans("Salvar Configurações").'"></div>';

print '</form>';

?>
<script>
$(document).ready(function() {
    // Toggle Password Visibility
    $('#togglePass').on('click', function(e) {
        e.preventDefault();
        var input = $('#cert_pass');
        var icon = $(this).find('i');
        if (input.attr('type') === 'password') {
            input.attr('type', 'text');
            icon.removeClass('fa-eye').addClass('fa-eye-slash');
        } else {
            input.attr('type', 'password');
            icon.removeClass('fa-eye-slash').addClass('fa-eye');
        }
    });

    $('input[name="ambiente"]').on('change', function() {
        if (this.value === '1') {
            if (!confirm('<?php echo dol_escape_js($langs->trans("Tem certeza que deseja alterar o ambiente para Produção?")); ?>')) {
                $('#amb_homolog').prop('checked', true);
            }
        }
    });
});
</script>
<?php
llxFooter();
?>
