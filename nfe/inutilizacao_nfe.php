<?php
require_once __DIR__ . '/../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
$_autoload = DOL_DOCUMENT_ROOT . '/custom/composerlib/vendor/autoload.php';
if (!file_exists($_autoload)) {
    llxHeader('', 'Erro NFe');
    print '<div class="error">Erro: A biblioteca NFePHP não foi encontrada em: ' . dol_escape_htmltag($_autoload) . '<br>Verifique se a pasta <code>custom/composerlib</code> foi enviada para o servidor.</div>';
    llxFooter();
    exit;
}
require_once $_autoload;
require_once DOL_DOCUMENT_ROOT.'/custom/nfe/lib/nfe_security.lib.php';

use NFePHP\NFe\Tools;
use NFePHP\Common\Certificate;
use NFePHP\NFe\Common\Standardize;

$langs->load("nfe@nfe");

// Verificar permissões
if (!$user->admin) {
    accessforbidden();
}

$mainmenu = 'nfe';
$leftmenu = 'nfe_inutilizacao';

function obterCNPJEmpresa(): ?string
{
    global $mysoc;
    $cnpj = preg_replace('/\D/', '', $mysoc->idprof1);
    if (strlen($cnpj) === 14) {
        return $cnpj;
    }
    return null;
}

function inutilizarNFe(DoliDB $db, int $nSerie, int $nIni, int $nFin, string $xJust): array
{
    global $langs, $mysoc;

    // Carregar configurações do banco de dados
    $sql = "SELECT name, value FROM ".MAIN_DB_PREFIX."nfe_config";
    $res = $db->query($sql);
    $configs = [];
    if ($res) {
        while ($r = $db->fetch_object($res)) $configs[$r->name] = $r->value;
    }

    $pfx = $configs['cert_pfx'];
    $pass = $configs['cert_pass'];
    $senhaOriginal = nfeDecryptPassword($pass, $db);

    $cnpj = obterCNPJEmpresa();
    $tpAmb = $configs['ambiente'] ?? '2';

    $arr = [
    "atualizacao" => "2025-11-13 09:01:21",
    "tpAmb"       => (int)$tpAmb ?? 2, // 1=Produção, 2=Homologação
    "razaosocial" => $mysoc->name,
    "cnpj"        => $cnpj,
    "siglaUF"     => $mysoc->state_code,
    "schemes"     => "PL_010_V1",
    "versao"      => '4.00',
    "tokenIBPT"   => "AAAAAAA",
    "CSC"         => "GPB0JBWLUR6HWFTVEAS6RJ69GPCROFPBBB8G",
    "CSCid"       => "000001",
    "proxyConf"   => [
        "proxyIp"   => "",
        "proxyPort" => "",
        "proxyUser" => "",
        "proxyPass" => ""
        ]
    ];

    $cfg_json = json_encode($arr);

    try {
        // Inicializar a biblioteca com o certificado e o JSON de configuração
        $certificate = Certificate::readPfx($pfx, $senhaOriginal);
        $tools = new Tools($cfg_json, $certificate);
        $tools->model('55');

        // Enviar a solicitação de inutilização para a SEFAZ
        $responseXml = $tools->sefazInutiliza($nSerie, $nIni, $nFin, $xJust);

        // Processar a resposta da SEFAZ
        $responseArr = (new Standardize($responseXml))->toArray();
        $cStat = $responseArr['infInut']['cStat'] ?? null;

        if ($cStat == '102') {
            $protocolo = $responseArr['infInut']['nProt'] ?? '';
            $motivo = $responseArr['infInut']['xMotivo'] ?? '';

            // Registrar a inutilização no banco de dados
            $sqlInsert = "INSERT INTO ".MAIN_DB_PREFIX."nfe_inutilizadas (serie, numero_inicial, numero_final, justificativa, protocolo, data_inutilizacao, xml_resposta)";
            $sqlInsert .= " VALUES (".$nSerie.", ".$nIni.", ".$nFin.", '".$db->escape($xJust)."', '".$db->escape($protocolo)."', NOW(), '".$db->escape($responseXml)."')";
            $db->query($sqlInsert);

            return ['success' => true, 'message' => "Faixa inutilizada com sucesso. Protocolo: {$protocolo}"];
        }

        $motivo = $responseArr['infInut']['xMotivo'] ?? 'Erro desconhecido';
        return ['success' => false, 'message' => "Erro ao inutilizar a faixa: {$motivo}"];
    } catch (\Exception $e) {
        dol_syslog("Erro na inutilização de NF-e: " . $e->getMessage(), LOG_ERR);
        return ['success' => false, 'message' => 'Ocorreu um erro inesperado: ' . $e->getMessage()];
    }
}

// Processar submissão do formulário
if ($_SERVER['REQUEST_METHOD'] === 'POST' && GETPOST('action') === 'inutilizar') {
    try {
        if (empty($_POST['token']) || $_POST['token'] !== $_SESSION['newtoken']) {
            setEventMessages($langs->trans("ErrorBadToken"), null, 'errors');
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }

        $serie = (int)GETPOST('serie', 'int');
        $ini = (int)GETPOST('nnf_ini', 'int');
        $fin = (int)GETPOST('nnf_fin', 'int');
        $just = GETPOST('justificativa', 'restricthtml');

        if ($serie < 0 || $ini <= 0 || $fin <= 0 || $fin < $ini || mb_strlen(trim($just)) < 15) {
            setEventMessages($langs->trans("Parâmetros inválidos. Verifique os dados informados."), null, 'errors');
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        }

        $result = inutilizarNFe($db, $serie, $ini, $fin, $just);
        if ($result['success']) {
            setEventMessages($result['message'], null, 'mesgs');
        } else {
            setEventMessages($result['message'], null, 'errors');
        }
    } catch (Exception $e) {
        setEventMessages($langs->trans("Erro inesperado: ") . $e->getMessage(), null, 'errors');
    }

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}


llxHeader('', $langs->trans("Inutilizar Faixa de NF-e"));

print load_fiche_titre($langs->trans("Inutilização de NF-e"), '', 'nfe@nfe/img/title_generic.png');

?>

<style>
.inutilizar-container {
    max-width: 800px;
    margin: 0 auto;
    padding: 20px;
}

.inutilizar-form {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 25px;
    margin-bottom: 30px;
}

.form-row {
    display: flex;
    gap: 15px;
    margin-bottom: 18px;
}

.form-group {
    flex: 1;
    display: flex;
    flex-direction: column;
}

.form-group.full-width {
    width: 100%;
}

.form-group label {
    font-weight: 600;
    color: #444;
    margin-bottom: 6px;
    font-size: 0.95em;
}

.form-group input[type="number"],
.form-group textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-size: 1em;
    box-sizing: border-box;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.form-group input:focus,
.form-group textarea:focus {
    border-color: #007bff;
    box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.25);
    outline: none;
}

.form-group textarea {
    resize: vertical;
    min-height: 100px;
}

.form-actions {
    text-align: right;
    margin-top: 20px;
    padding-top: 15px;
    border-top: 1px solid #dee2e6;
}

.alert-info {
    background: #d1ecf1;
    border: 1px solid #bee5eb;
    color: #0c5460;
    padding: 12px 15px;
    border-radius: 6px;
    margin-bottom: 20px;
}

.alert-info strong {
    display: block;
    margin-bottom: 5px;
}

.historico-section {
    margin-top: 40px;
}

.historico-section h3 {
    margin-bottom: 15px;
    color: #333;
}

@media (max-width: 768px) {
    .form-row {
        flex-direction: column;
        gap: 15px;
    }
    
    .inutilizar-form {
        padding: 15px;
    }
}
</style>

<div class="inutilizar-container">
    <div class="alert-info">
        <strong><?php echo $langs->trans("Atenção"); ?>:</strong>
        <?php echo $langs->trans("A inutilização de numeração é irreversível. Use este recurso apenas quando houver quebra na sequência de numeração (ex: erro no sistema, perda de nota)."); ?>
    </div>

    <div class="inutilizar-form">
        <h3><?php echo $langs->trans("Inutilizar Faixa de Numeração"); ?></h3>
        
        <form method="post" action="<?php echo $_SERVER['PHP_SELF']; ?>">
            <input type="hidden" name="token" value="<?php echo newToken(); ?>" />
            <input type="hidden" name="action" value="inutilizar" />
            
            <div class="form-row">
                <div class="form-group">
                    <label for="serie"><?php echo $langs->trans("Série"); ?>:</label>
                    <input type="number" name="serie" id="serie" min="0" max="999" value="1" required />
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label for="nnf_ini"><?php echo $langs->trans("Número Inicial"); ?>:</label>
                    <input type="number" name="nnf_ini" id="nnf_ini" min="1" required />
                </div>
                
                <div class="form-group">
                    <label for="nnf_fin"><?php echo $langs->trans("Número Final"); ?>:</label>
                    <input type="number" name="nnf_fin" id="nnf_fin" min="1" required />
                </div>
            </div>
            
            <div class="form-row">
                <div class="form-group full-width">
                    <label for="justificativa"><?php echo $langs->trans("Justificativa"); ?> (mínimo 15 caracteres):</label>
                    <textarea name="justificativa" id="justificativa" required placeholder="<?php echo $langs->trans("Ex: Erro no sistema resultou em quebra de numeração"); ?>"></textarea>
                    <!-- Mensagem de erro inline (substitui alert de tamanho de justificativa) -->
                    <span id="justificativa_error" style="display:none;color:#D8000C;font-size:0.95em;margin-top:6px;display:block;"></span>
                </div>
            </div>
            
            <div class="form-actions">
                <button type="submit" class="butAction" id="btn-inutilizar">
                    <?php echo $langs->trans("Confirmar Inutilização"); ?>
                </button>
            </div>
        </form>
    </div>

    <?php if ($db->num_rows($res_historico) > 0): ?>
    <div class="historico-section">
        <h3><?php echo $langs->trans("Histórico de Inutilizações"); ?></h3>
        
        <table class="liste noborder centpercent">
            <tr class="liste_titre">
                <th><?php echo $langs->trans("Data"); ?></th>
                <th><?php echo $langs->trans("Série"); ?></th>
                <th><?php echo $langs->trans("Faixa"); ?></th>
                <th><?php echo $langs->trans("Protocolo"); ?></th>
                <th><?php echo $langs->trans("Justificativa"); ?></th>
            </tr>
            
            <?php
            $i = 0;
            while ($obj = $db->fetch_object($res_historico)) {
                print '<tr class="oddeven">';
                print '<td>'.dol_print_date(dol_stringtotime($obj->data_inutilizacao), 'dayhour').'</td>';
                print '<td class="center">'.$obj->serie.'</td>';
                print '<td class="center">'.$obj->numero_inicial.' - '.$obj->numero_final.'</td>';
                print '<td class="center">'.$obj->protocolo.'</td>';
                print '<td>'.dol_trunc($obj->justificativa, 60).'</td>';
                print '</tr>';
                $i++;
            }
            ?>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Modal de confirmação padrão Dolibarr (jQuery UI) -->
<div id="dialog-confirm-inutil" title="<?php echo $langs->trans('Confirmar Inutilização'); ?>" style="display:none;">
    <p id="dialog-confirm-inutil-msg" style="margin:8px 0;"></p>
</div>

<script>
jQuery(function($){
    var form = $('form[action="<?php echo $_SERVER['PHP_SELF']; ?>"]');
    var dialog = $('#dialog-confirm-inutil').dialog({
        autoOpen: false,
        modal: true,
        width: 520,
        resizable: false,
        buttons: [
            {
                text: "<?php echo dol_escape_js($langs->trans('Sim')); ?>",
                click: function() {
                    $('#btn-inutilizar').prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> <?php echo dol_escape_js($langs->trans("Processando...")); ?>');
                    $(this).dialog('close');
                    try { form[0].submit(); } catch(e) { form.submit(); }
                }
            },
            {
                text: "<?php echo dol_escape_js($langs->trans('Cancelar')); ?>",
                click: function() {
                    $(this).dialog('close');
                }
            }
        ]
    });

    form.on('submit', function(e) {
        e.preventDefault();

        var ini = parseInt($('#nnf_ini').val() || '0', 10);
        var fin = parseInt($('#nnf_fin').val() || '0', 10);
        var just = $('#justificativa').val() ? $('#justificativa').val().trim() : '';

        if (ini <= 0 || fin <= 0) {
            alert('<?php echo dol_escape_js($langs->trans("Números inicial e final devem ser maiores que zero.")); ?>');
            return false;
        }

        if (ini > fin) {
            alert('<?php echo dol_escape_js($langs->trans("Número inicial não pode ser maior que o número final.")); ?>');
            return false;
        }

        // Substitui alert por mensagem inline abaixo do textarea
        if (just.length < 15) {
            $('#justificativa_error').text('<?php echo dol_escape_js($langs->trans("A justificativa precisa ter pelo menos 15 caracteres.")); ?>').show();
            $('#justificativa').focus();
            return false;
        } else {
            // garante que a mensagem esteja oculta se já válida
            $('#justificativa_error').hide().text('');
        }

        var mensagem = '<?php echo dol_escape_js($langs->trans("Deseja realmente inutilizar a numeração ")); ?>' + ' ' + ini + ' a ' + fin + '?';
        $('#dialog-confirm-inutil-msg').text(mensagem);
        dialog.dialog('open');
        return false;
    });
    
    // Auto-ajuste do número final ao digitar inicial
    $('#nnf_ini').on('input', function() {
        var val = parseInt($(this).val() || '0', 10);
        if (val > 0 && $('#nnf_fin').val() === '') {
            $('#nnf_fin').val(val);
        }
    });

    // Oculta a mensagem de erro enquanto o usuário digita e atinge o mínimo
    $('#justificativa').on('input', function() {
        var len = ($(this).val() || '').trim().length;
        if (len >= 15) {
            $('#justificativa_error').hide().text('');
        }
    });
});
</script>

<?php
llxFooter();
$db->close();
?>
