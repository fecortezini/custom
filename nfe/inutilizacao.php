<?php
require_once __DIR__ . '/../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/custom/composerlib/vendor/autoload.php';
require_once DOL_DOCUMENT_ROOT.'/custom/nfe/lib/nfe_security.lib.php';

use NFePHP\NFe\Tools;
use NFePHP\Common\Certificate;
use NFePHP\NFe\Common\Standardize;

$langs->load("nfe@nfe");

function obterCNPJEmpresa(): ?string
{
    global $mysoc;

    $cnpj = preg_replace('/\D/', '', $mysoc->idprof1); // Remove caracteres não numéricos
    if (strlen($cnpj) === 14) {
        return $cnpj;
    }

    return null;
}

function inutilizarNFe(DoliDB $db, int $nSerie, int $nIni, int $nFin, string $xJust): array
{
    global $langs;

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

    $configJson = $configs['config_json'];
    $cnpj = obterCNPJEmpresa(); // Busca o CNPJ da empresa principal
    $tpAmb = $configs['ambiente'] ?? '2'; // Ambiente: 1 = Produção, 2 = Homologação

    // Validar se config_json é um JSON válido
    $decodedConfigJson = json_decode($configJson, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return [
            'success' => false,
            'message' => $langs->trans("A configuração JSON é inválida. Verifique as configurações do módulo e tente novamente.")
        ];
    }

    try {
        // Inicializar a biblioteca com o certificado e o JSON de configuração
        $certificate = Certificate::readPfx($pfx, $senhaOriginal);
        $tools = new Tools($configJson, $certificate);
        $tools->model('55'); // Define o modelo da NF-e

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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (empty($_POST['token']) || $_POST['token'] !== $_SESSION['newtoken']) {
            setEventMessages($langs->trans("ErrorBadFormTry"), null, 'errors');
            header("Location: " . $_SERVER['HTTP_REFERER']);
            exit;
        }

        $serie = (int)GETPOST('serie', 'int');
        $ini = (int)GETPOST('nnf_ini', 'int');
        $fin = (int)GETPOST('nnf_fin', 'int');
        $just = GETPOST('justificativa', 'restricthtml');

        if ($serie <= 0 || $ini <= 0 || $fin <= 0 || $fin < $ini || mb_strlen(trim($just)) < 15) {
            setEventMessages($langs->trans("ErrorInvalidParameters"), null, 'errors');
            header("Location: " . $_SERVER['HTTP_REFERER']);
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

    header("Location: " . $_SERVER['HTTP_REFERER']);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'message' => 'Método não permitido.']);
exit;
?>
<div class="inutilizar-wrapper" style="padding:10px;">
	<form id="form-inutilizar" method="post" action="<?php echo $formAction; ?>">
		<input type="hidden" name="token" value="<?php echo dol_escape_htmltag($_SESSION['newtoken']); ?>" />
		<div style="margin-bottom:10px;">
			<label><?php echo $langs->trans("Série"); ?>:</label><br>
			<input type="number" name="serie" id="inutil-serie" class="flat" min="0" required>
		</div>
		<div style="margin-bottom:10px;">
			<label><?php echo $langs->trans("Número inicial"); ?>:</label><br>
			<input type="number" name="nnf_ini" id="inutil-nnf-ini" class="flat" min="1" required>
		</div>
		<div style="margin-bottom:10px;">
			<label><?php echo $langs->trans("Número final"); ?>:</label><br>
			<input type="number" name="nnf_fin" id="inutil-nnf-fin" class="flat" min="1" required>
		</div>
		<div style="margin-bottom:10px;">
			<label><?php echo $langs->trans("Justificativa"); ?> (mín. 15 caracteres):</label><br>
			<textarea name="justificativa" id="inutil-justificativa" rows="4" style="width:98%;" required></textarea>
		</div>
		<div style="text-align:right;">
			<button type="button" id="submit-inutilizar" class="butAction butAction-std"><?php echo $langs->trans("Confirmar"); ?></button>
		</div>
	</form>
</div>
<script>
$(document).ready(function() {
	$('#submit-inutilizar').on('click', function(e) {
		e.preventDefault();
		var form = $('#form-inutilizar');
		$.ajax({
			url: form.attr('action'),
			type: 'POST',
			data: form.serialize(),
			dataType: 'json',
			success: function(response) {
				var dialogClass = response.success ? 'dialog-success' : 'dialog-error';
				var title = response.title;
				var message = response.message;

				// Atualizar o conteúdo da modal existente
				$('#dialog-inutilizar').dialog('option', 'title', title);
				$('#dialog-inutilizar').dialog('option', 'class', dialogClass);
				$('#dialog-inutilizar').html(message);

				// Recarregar a página após fechar a modal
				$('#dialog-inutilizar').dialog({
					modal: true,
					width: 400,
					buttons: {
						"<?php echo $langs->trans("Fechar"); ?>": function() {
							$(this).dialog("close");
							location.reload(); // Recarrega a página para atualizar a lista
						}
					},
					close: function() {
						$(this).dialog("destroy");
					}
				});
			},
			error: function(xhr, status, error) {
				alert('<?php echo $langs->trans("Erro ao processar a solicitação."); ?>\n' + error);
			}
		});
	});
});

</script>
