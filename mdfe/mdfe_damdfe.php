<?php
/**
 * Visualização / Download do DAMDFE (PDF) de uma MDF-e emitida.
 *
 * Parâmetros GET:
 *   id     = ID na tabela mdfe_emitidas
 *   action = "view"     → exibe o PDF inline no navegador
 *            "download" → força download do arquivo PDF
 *            "save"     → gera o PDF completo e salva na coluna pdf_damdfe, depois retorna JSON
 *
 * Dependências:
 *   - NFePHP\DA\MDFe\Damdfe  (sped-da)
 *   - Tabela llx_mdfe_emitidas com coluna xml_mdfe (LONGTEXT)
 *   - Coluna pdf_damdfe (LONGBLOB) — será criada automaticamente se não existir
 */

@ini_set('display_errors', '0');
$__lvl = error_reporting();
$__lvl &= ~(E_DEPRECATED | E_USER_DEPRECATED | E_NOTICE | E_USER_NOTICE | E_WARNING | E_USER_WARNING);
error_reporting($__lvl);

require '../../main.inc.php';

/** @var DoliDB $db */
/** @var User $user */

// Autoload da lib NFePHP (sped-da)
if (file_exists(__DIR__ . '/../composerlib/vendor/autoload.php')) {
    require_once __DIR__ . '/../composerlib/vendor/autoload.php';
}

use NFePHP\DA\MDFe\Damdfe;
use Com\Tecnick\Barcode\Barcode;

/**
 * Subclasse que corrige o problema de data:// URI no FPDF em ambientes onde
 * allow_url_include está desabilitado (padrão desde PHP 7.4 / Windows/XAMPP).
 * Mesma técnica usada no DacteCustom do módulo CT-e.
 */
class DamdfeCustom extends Damdfe
{
    /**
     * Override de adjustImage: retorna o caminho real do arquivo ao invés de
     * converter para data://text/plain;base64,... que o FPDF não consegue abrir.
     */
    protected function adjustImage($logo, $turn_bw = false)
    {
        if (!empty($this->logomarca)) {
            return $this->logomarca;
        }
        if (empty($logo)) {
            return null;
        }
        if (!is_file($logo)) {
            return null;
        }
        $info = @getimagesize($logo);
        if (!$info) {
            return null;
        }
        // Somente PNG (3) e JPEG (2) são aceitos pelo FPDF
        if (!in_array($info[2], [2, 3], true)) {
            return null;
        }
        if ($info[2] === 2 && !$turn_bw) {
            // JPEG sem conversão — retorna caminho direto
            return $logo;
        }
        // PNG ou precisa de P&B: converte para JPEG temporário
        $image = ($info[2] === 3) ? imagecreatefrompng($logo) : imagecreatefromjpeg($logo);
        if (!$image) {
            return null;
        }
        if ($turn_bw) {
            imagefilter($image, IMG_FILTER_GRAYSCALE);
        }
        $tmp = tempnam(sys_get_temp_dir(), 'damdfe_logo_');
        imagejpeg($image, $tmp, 100);
        imagedestroy($image);
        register_shutdown_function(function () use ($tmp) {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        });
        return $tmp;
    }

    /**
     * Override de qrCodeDamdfe: salva o PNG do QR code em arquivo temporário
     * ao invés de criar um data:// URI que o FPDF não consegue abrir.
     */
    protected function qrCodeDamdfe($y = 0)
    {
        $margemInterna = $this->margemInterna;
        $barcode = new Barcode();
        $bobj = $barcode->getBarcodeObj(
            'QRCODE,M',
            $this->qrCodMDFe,
            -4,
            -4,
            'black',
            [-2, -2, -2, -2]
        )->setBackgroundColor('white');
        $qrcode = $bobj->getPngData();

        $wQr = 35;
        $hQr = 35;
        $yQr = $y + $margemInterna;
        $xQr = ($this->orientacao === 'P') ? 160 : 235;

        // Salvar em arquivo temporário ao invés de data:// URI
        $tmp = tempnam(sys_get_temp_dir(), 'damdfe_qr_');
        file_put_contents($tmp, $qrcode);
        register_shutdown_function(function () use ($tmp) {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        });

        $this->pdf->image($tmp, $xQr, $yQr, $wQr, $hQr, 'PNG');
    }
}

// ---------------------------------------------------------------------------
// Autenticação
// ---------------------------------------------------------------------------
if (!$user->id) {
    http_response_code(403);
    exit('Acesso negado.');
}

// ---------------------------------------------------------------------------
// Parâmetros
// ---------------------------------------------------------------------------
$mdfeId = (int) GETPOST('id', 'int');
$action = GETPOST('action', 'alpha') ?: 'view'; // view | download | save

if ($mdfeId <= 0) {
    http_response_code(400);
    exit('ID inválido.');
}

// ---------------------------------------------------------------------------
// Garantir que a coluna pdf_damdfe existe na tabela mdfe_emitidas
// ---------------------------------------------------------------------------
$p = MAIN_DB_PREFIX;
@$db->query("ALTER TABLE {$p}mdfe_emitidas ADD COLUMN pdf_damdfe LONGBLOB DEFAULT NULL COMMENT 'Cache do DAMDFE em PDF' AFTER xml_mdfe");

// ---------------------------------------------------------------------------
// Buscar MDF-e
// ---------------------------------------------------------------------------
$sql = "SELECT * FROM {$p}mdfe_emitidas WHERE id = " . $mdfeId;
$res = $db->query($sql);
if (!$res || $db->num_rows($res) === 0) {
    http_response_code(404);
    exit('MDF-e não encontrada.');
}
$row = $db->fetch_object($res);

// ---------------------------------------------------------------------------
// Obter XML da MDF-e
// ---------------------------------------------------------------------------
$xmlStr = '';
if (!empty($row->xml_mdfe)) {
    $xmlStr = is_resource($row->xml_mdfe) ? stream_get_contents($row->xml_mdfe) : (string) $row->xml_mdfe;
}
// Fallback: tentar xml_enviado (XML assinado)
if (empty(trim($xmlStr)) && !empty($row->xml_enviado)) {
    $xmlStr = is_resource($row->xml_enviado) ? stream_get_contents($row->xml_enviado) : (string) $row->xml_enviado;
}

if (empty(trim($xmlStr))) {
    http_response_code(422);
    exit('XML da MDF-e não encontrado no banco de dados.');
}

// ---------------------------------------------------------------------------
// Verificar se o XML possui nó mdfeProc (necessário para DAMDFE com protocolo)
// Se o XML foi salvo sem o envelope mdfeProc, montamos um wrapper mínimo
// ---------------------------------------------------------------------------
if (stripos($xmlStr, '<mdfeProc') === false && stripos($xmlStr, '<MDFe') !== false) {
    // O XML salvo é apenas o MDFe assinado, sem envelope de protocolo.
    // O Damdfe aceita assim, mas se tivermos protocolo podemos enriquecer.
    // Mantemos como está — o Damdfe lida com ambos os formatos.
}

// ---------------------------------------------------------------------------
// Logo da empresa (opcional)
// A lib Damdfe/TCPDF precisa do caminho real do arquivo (não aceita data:URI).
// Somente PNG ou JPG são aceitos.
// ---------------------------------------------------------------------------
$logo = null;
$possibleLogoPaths = [
    DOL_DATA_ROOT . '/mycompany/logos/thumbs/',
    DOL_DATA_ROOT . '/mycompany/logos/',
];

$mimeAceitos = ['image/png', 'image/jpeg'];

foreach ($possibleLogoPaths as $logoDir) {
    if (!is_dir($logoDir)) {
        continue;
    }
    $files = glob($logoDir . '*.{png,jpg,jpeg,PNG,JPG,JPEG}', GLOB_BRACE);
    if (empty($files)) {
        continue;
    }
    foreach ($files as $logoFile) {
        $imgInfo = @getimagesize($logoFile);
        if ($imgInfo !== false && in_array($imgInfo['mime'], $mimeAceitos, true)) {
            // Passa o caminho absoluto real do arquivo (não data:URI)
            $logo = realpath($logoFile);
            break 2;
        }
    }
}

// ---------------------------------------------------------------------------
// Gerar o PDF via Damdfe
// ---------------------------------------------------------------------------
try {
    $damdfe = new DamdfeCustom($xmlStr);
    $damdfe->debugMode(false);
    $damdfe->printParameters('P'); // Retrato
    $pdfContent = $damdfe->render($logo);

} catch (Exception $e) {
    http_response_code(500);
    exit('Erro ao gerar DAMDFE: ' . $e->getMessage());
}

// ---------------------------------------------------------------------------
// Salvar PDF no banco (coluna pdf_damdfe) — sempre que gerar, atualiza o cache
// ---------------------------------------------------------------------------
$pdfBase64 = base64_encode($pdfContent);
$db->query("UPDATE {$p}mdfe_emitidas SET pdf_damdfe = '" . $db->escape($pdfBase64) . "' WHERE id = " . $mdfeId);

// ---------------------------------------------------------------------------
// Nome do arquivo
// ---------------------------------------------------------------------------
$chave  = !empty($row->chave_acesso) ? $row->chave_acesso : 'MDFe_' . $row->numero;
$filename = 'DAMDFE_' . $chave . '.pdf';

// ---------------------------------------------------------------------------
// Ação: salvar no banco e retornar JSON (chamada via AJAX)
// ---------------------------------------------------------------------------
if ($action === 'save') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success'  => true,
        'message'  => 'PDF do DAMDFE salvo com sucesso no banco de dados.',
        'id'       => $mdfeId,
        'filename' => $filename,
        'size'     => strlen($pdfContent),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ---------------------------------------------------------------------------
// Ação: visualizar inline no navegador
// ---------------------------------------------------------------------------
if ($action === 'view') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfContent));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdfContent;
    exit;
}

// ---------------------------------------------------------------------------
// Ação: forçar download
// ---------------------------------------------------------------------------
if ($action === 'download') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfContent));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdfContent;
    exit;
}

// Fallback
http_response_code(400);
exit('Ação inválida. Use action=view, action=download ou action=save.');
