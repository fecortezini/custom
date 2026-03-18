<?php
// ATIVA exibição de erros para diagnóstico
@ini_set('display_errors', '1');
@ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('America/Sao_Paulo');
// Testa se main.inc.php existe
$mainIncPath = '../../main.inc.php';
if (!file_exists($mainIncPath)) {
    die('<h1>Erro Fatal</h1><p>Arquivo main.inc.php não encontrado em: ' . realpath('.') . '/../../main.inc.php</p>');
}

// Inicia buffer de saída para capturar erros antes do PDF
ob_start();

require $mainIncPath;

// Testa se autoload existe
$autoloadPath = DOL_DOCUMENT_ROOT . '/custom/composerlib/vendor/autoload.php';
if (!file_exists($autoloadPath)) {
    die('<h1>Erro Fatal</h1><p>Autoload do Composer não encontrado em: ' . $autoloadPath . '</p>');
}
// Require PDF Generator
require_once DOL_DOCUMENT_ROOT . '/custom/composerlib/vendor/paseto/nfse-nacional-pdf/src/NfsePdfGenerator.php';

function carregarCertificadoA1Nacional($db) {
    $certPfx = null;
    $certPass = null;

    // Tenta tabela key/value
    $tableKv = (defined('MAIN_DB_PREFIX') ? MAIN_DB_PREFIX : '') . 'nfe_config';
    $res = @$db->query("SELECT name, value FROM `".$tableKv."`");
    if ($res) {
        while ($row = $db->fetch_object($res)) {
            if ($row->name === 'cert_pfx') $certPfx = $row->value;
            if ($row->name === 'cert_pass') $certPass = $row->value;
        }
    }

    // Fallback para tabela com colunas diretas
    if (empty($certPfx)) {
        $tableDirect = MAIN_DB_PREFIX . 'nfe_config';
        $res2 = @$db->query("SELECT cert_pfx, cert_pass FROM `".$tableDirect."` LIMIT 1");
        if ($res2 && $obj = $db->fetch_object($res2)) {
            $certPfx = $obj->cert_pfx;
            $certPass = $obj->cert_pass;
        }
    }

    // Normaliza BLOB/stream
    if (is_resource($certPfx)) {
        $certPfx = stream_get_contents($certPfx);
    }
    
    if ($certPfx === null || $certPfx === '') {
        throw new Exception('Certificado PFX não encontrado no banco de dados.');
    }
    
    $certPass = (string)$certPass;
    
    // Retorna certificado usando a biblioteca NFePHP
    try {
        $cert = \NFePHP\Common\Certificate::readPfx($certPfx, $certPass);
        return $cert;
    } catch (Exception $e) {
        // Tenta decodificar base64 se falhar
        $certPfxDecoded = base64_decode($certPfx, true);
        if ($certPfxDecoded !== false) {
            $cert = \NFePHP\Common\Certificate::readPfx($certPfxDecoded, $certPass);
            return $cert;
        }
        throw new Exception('Erro ao ler certificado: ' . $e->getMessage());
    }
}

/**
 * Busca configuração de ambiente (produção/homologação)
 */
function getAmbienteNacional($db) {
    $ambiente = 2; // Padrão: homologação
    
    $sql = "SELECT value FROM ".MAIN_DB_PREFIX."nfe_config WHERE name = 'ambiente' LIMIT 1";
    $res = $db->query($sql);
    if ($res && $obj = $db->fetch_object($res)) {
        $ambiente = (int)$obj->value;
    }
    
    return $ambiente;
}

require_once $autoloadPath;

try {
    // Identifica ID e busca chave
    $id = GETPOST('id', 'int');
    $action = GETPOST('action', 'alpha'); // view or download

    if (!$id) {
        throw new Exception("ID da NFS-e não fornecido.");
    }

    // Busca dados no banco, incluindo PDF salvo e XML
    $sql = "SELECT chave_acesso, numero_nfse, ambiente, pdf_danfse, xml_nfse FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas WHERE id = ".(int)$id;
    $res = $db->query($sql);
    if (!$res || $db->num_rows($res) === 0) {
        throw new Exception("NFS-e não encontrada no banco de dados.");
    }
    $obj = $db->fetch_object($res);
    $chave = $obj->chave_acesso;
    $numNfse = $obj->numero_nfse ?: 'NFSe';
    // Usa ambiente do banco, ou o global se não tiver
    $ambiente = !empty($obj->ambiente) ? (int)$obj->ambiente : getAmbienteNacional($db);
    $pdfContent = null;

    // 1. Tenta recuperar PDF já salvo (BLOB)
    if (!empty($obj->pdf_danfse)) {
        $pdfContent = $obj->pdf_danfse;
    } 
    // 2. Se não tiver PDF, tenta gerar localmente usando o XML da nota
    elseif (!empty($obj->xml_nfse)) {
        try {
            // Salva XML temporário para geração
            $xmlTempPath = sys_get_temp_dir() . '/nfse_temp_' . uniqid() . '.xml';
            file_put_contents($xmlTempPath, $obj->xml_nfse);
            
            $pdfGenerator = new \NfsePdf\NfsePdfGenerator();
            $pdf = $pdfGenerator->parseXml($xmlTempPath)->generate();
            $pdfContent = $pdf->Output('danfse.pdf', 'S');
            
            // Opcional: Salvar no banco para a próxima vez?
            // Vamos fazer isso para otimizar futuras requisições
            $sqlUpdate = "UPDATE ".MAIN_DB_PREFIX."nfse_nacional_emitidas SET pdf_danfse = '".$db->escape($pdfContent)."' WHERE id = ".(int)$id;
            $db->query($sqlUpdate);
            
            @unlink($xmlTempPath);
        } catch (Exception $eGen) {
            error_log("[DANFSE] Erro ao gerar localmente: " . $eGen->getMessage());
            // Se falhar, segue para tentar via API
        }
    }

    // 3. Se ainda não tem PDF, tenta via API (apenas se tiver chave)
    if (empty($pdfContent)) {
        if (empty($chave)) {
            throw new Exception("Chave de acesso não encontrada e XML/PDF indisponíveis.");
        }
        
        // Carrega certificado
        $cert = carregarCertificadoA1Nacional($db);
        
        // Cria configuração JSON para a biblioteca com o AMBIENTE CORRETO
        $config = new stdClass();
        $config->tpamb = (int)$ambiente;
        $configJson = json_encode($config);
        
        // Inicializa ferramenta de emissão
        $tools = new \Hadder\NfseNacional\Tools($configJson, $cert);

        // Aumenta tempo limite
        set_time_limit(120);

        try {
            $pdfContent = $tools->consultarDanfse($chave);
        } catch (\Throwable $t) {
            error_log("[DANFSE] Erro na lib: " . $t->getMessage());
            throw new Exception("Falha ao obter PDF da API (e não há cópia local): " . $t->getMessage());
        }
        
        if (strlen($pdfContent) < 100) {
            if (strpos($pdfContent, '<?xml') !== false || strpos($pdfContent, '<error') !== false) {
                 throw new Exception("API retornou XML/Erro em vez de PDF: " . strip_tags($pdfContent));
            }
        }
        
        // Salva o que veio da API para o futuro
        if (!empty($pdfContent)) {
             $sqlUpdate = "UPDATE ".MAIN_DB_PREFIX."nfse_nacional_emitidas SET pdf_danfse = '".$db->escape($pdfContent)."' WHERE id = ".(int)$id;
             $db->query($sqlUpdate);
        }
    }

    // Limpa qualquer lixo do buffer antes de enviar o binário
    while (ob_get_level()) { ob_end_clean(); }

    // Configura headers para PDF
    header("Content-Type: application/pdf");
    header('Content-Transfer-Encoding: binary');
    header('Accept-Ranges: bytes');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . strlen($pdfContent));
    
    $filename = "NFSe_".$numNfse.".pdf";
    
    if ($action === 'download') {
        header('Content-Disposition: attachment; filename="'.$filename.'"');
    } else {
        header('Content-Disposition: inline; filename="'.$filename.'"');
    }
    
    echo $pdfContent;
    exit;
    
} catch (Exception $e) {
    // Garante que o buffer seja limpo para exibir o erro HTML limpo
    while (ob_get_level()) { ob_end_clean(); }
    header('Content-Type: text/html; charset=utf-8');
    echo '<div style="color:#721c24; background-color:#f8d7da; border:1px solid #f5c6cb; padding:20px; font-family:sans-serif; margin:20px; border-radius:5px;">';
    echo '<h3 style="margin-top:0;">Erro ao obter DANFSe</h3>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<hr>';
    echo '</div>';
    exit;
}
?>