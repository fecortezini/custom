<?php
/**
 * Cancelamento de NFS-e no Padrão Nacional
 * Integrado com Dolibarr
 */
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
$__lvl = error_reporting();
$__lvl &= ~E_DEPRECATED;
$__lvl &= ~E_USER_DEPRECATED;
$__lvl &= ~E_NOTICE;
$__lvl &= ~E_USER_NOTICE;
$__lvl &= ~E_WARNING;
$__lvl &= ~E_USER_WARNING;
error_reporting($__lvl);

date_default_timezone_set('America/Sao_Paulo');

require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/custom/composerlib/vendor/autoload.php';
require_once DOL_DOCUMENT_ROOT.'/custom/nfse/lib/nfse_security.lib.php';

/** @var DoliDB $db */
/** @var Translate $langs */
/** @var Conf $conf */
/** @var User $user */

// Verifica se é requisição AJAX
if (!(!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest')) {
    http_response_code(400);
    echo json_encode(['error' => 'Requisição inválida']);
    exit;
}

$action = GETPOST('action', 'alpha');
$id = GETPOST('id', 'int');

if ($action !== 'cancelar' || !$id) {
    http_response_code(400);
    echo json_encode(['error' => 'Parâmetros inválidos']);
    exit;
}

/**
 * Carrega certificado A1 do banco de dados
 */
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
    $original = nfseDecryptPassword($certPass, $db);
    // Retorna certificado usando a biblioteca NFePHP
    try {
        $cert = \NFePHP\Common\Certificate::readPfx($certPfx, $original);
        return $cert;
    } catch (Exception $e) {
        // Tenta decodificar base64 se falhar
        $certPfxDecoded = base64_decode($certPfx, true);
        if ($certPfxDecoded !== false) {
            $cert = \NFePHP\Common\Certificate::readPfx($certPfxDecoded, $original);
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

try {
    $db->begin();
    
    // Busca dados da NFS-e
    $sql = "SELECT * FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas WHERE id = ".((int)$id);
    $res = $db->query($sql);
    
    if (!$res || $db->num_rows($res) == 0) {
        throw new Exception('NFS-e não encontrada');
    }
    
    $nfse = $db->fetch_object($res);
    
    // Verifica se já está cancelada
    if (strtolower($nfse->status) === 'cancelada') {
        throw new Exception('Esta NFS-e já está cancelada');
    }
    
    // Verifica se está autorizada
    if (strtolower($nfse->status) !== 'autorizada') {
        throw new Exception('Apenas NFS-e autorizadas podem ser canceladas');
    }
    
    // Verifica se tem chave de acesso
    if (empty($nfse->chave_acesso)) {
        throw new Exception('Chave de acesso não encontrada para esta NFS-e');
    }
    
    // Recebe dados do formulário
    $codigo_motivo = GETPOST('codigo_motivo', 'int');
    $descricao_motivo = GETPOST('descricao_motivo', 'alpha');
    
    if (!$codigo_motivo) {
        throw new Exception('Código do motivo é obrigatório');
    }
    
    if (empty($descricao_motivo)) {
        throw new Exception('Descrição do motivo é obrigatória');
    }
    
    // Carrega certificado
    $cert = carregarCertificadoA1Nacional($db);
    
    // Busca ambiente
    $ambiente = getAmbienteNacional($db);
    
    // Cria configuração JSON para a biblioteca
    $config = new stdClass();
    $config->tpamb = $ambiente;
    $configJson = json_encode($config);
    
    // Inicializa ferramenta
    $tools = new \Hadder\NfseNacional\Tools($configJson, $cert);
    
    // Monta estrutura do evento de cancelamento
    $std = new stdClass();
    $std->infPedReg = new stdClass();
    $std->infPedReg->chNFSe = $nfse->chave_acesso;
    $std->infPedReg->CNPJAutor = preg_replace('/\D/', '', $nfse->prestador_cnpj);
    $std->infPedReg->dhEvento = now()->format('Y-m-d\TH:i:sP');
    $std->infPedReg->tpAmb = $ambiente;
    $std->infPedReg->verAplic = 'LABCONNECTA_V1.0';
    
    // Evento e101101 - Cancelamento
    $std->infPedReg->e101101 = new stdClass();
    $std->infPedReg->e101101->xDesc = 'Cancelamento de NFS-e';
    $std->infPedReg->e101101->cMotivo = $codigo_motivo;
    $std->infPedReg->e101101->xMotivo = $descricao_motivo;
    
    // Envia cancelamento
    error_log('[NFSE CANCELAMENTO] Enviando cancelamento da NFS-e #'.$nfse->numero_nfse.' (ID: '.$id.')');
    
    $response = $tools->cancelaNfse($std);
    
    // Variáveis para processamento
    $statusAtualizado = false;
    $mensagemRetorno = '';
    $protocolo = '';
    $xmlRetornoDecodificado = null;
    $dataHoraProcessamento = $response['dataHoraProcessamento'] ?? date('Y-m-d H:i:s');

    // 1. Verifica Sucesso (eventoXmlGZipB64)
    if (isset($response['eventoXmlGZipB64'])) {
        $statusAtualizado = true;
        try {
            $xmlBin = base64_decode($response['eventoXmlGZipB64']);
            if ($xmlBin) {
                // Tenta gunzip (alguns ambientes retornam gz, outros string direta se biblioteca já processou)
                $xmlRetornoDecodificado = @gzdecode($xmlBin);
                if ($xmlRetornoDecodificado === false) {
                    $xmlRetornoDecodificado = $xmlBin; // Fallback se não for GZIP
                }
                
                if ($xmlRetornoDecodificado) {
                    // Busca protocolo nProt no XML do evento
                    if (preg_match('/<nProt>(\d+)<\/nProt>/', $xmlRetornoDecodificado, $matches)) {
                        $protocolo = $matches[1];
                    }
                }
            }
        } catch (Exception $ignore) {}
        
        $mensagemRetorno = 'NFS-e cancelada com sucesso!';
        if ($protocolo) $mensagemRetorno .= ' (Protocolo: '.$protocolo.')';
        
    } elseif (isset($response['erro'])) {
        $erros = [];
        if (is_array($response['erro'])) {
            foreach ($response['erro'] as $e) {
                if (is_array($e)) {
                    $cod = $e['codigo'] ?? '';
                    $desc = $e['descricao'] ?? '';
                    $erros[] = trim($cod . ' - ' . $desc);
                } elseif (is_string($e)) {
                    $erros[] = $e;
                }
            }
        }
        $mensagemRetorno = implode('; ', $erros);
        
    } elseif (isset($response['message'])) {
        $mensagemRetorno = $response['message'];
    } else {
        $mensagemRetorno = 'Resposta inesperada da SEFAZ: ' . json_encode($response);
    }
    

    $dataHoraEvento = date('Y-m-d H:i:s');
    
    $sqlEvento = "INSERT INTO ".MAIN_DB_PREFIX."nfse_nacional_eventos (
        id_nfse,
        tipo_evento,
        chave_nfse,
        codigo_motivo,
        descricao_motivo,
        xml_enviado,
        xml_retorno,
        json_retorno,
        status_evento,
        protocolo,
        mensagem_retorno,
        data_hora_evento,
        data_hora_processamento
    ) VALUES (
        ".((int)$id).",
        'e101101',
        '".$db->escape($nfse->chave_acesso)."',
        ".((int)$codigo_motivo).",
        '".$db->escape($descricao_motivo)."',
        '".$db->escape(json_encode($std))."',
        '".$db->escape($xmlRetornoDecodificado)."',
        '".$db->escape(json_encode($response))."',
        '".($statusAtualizado ? 'processado' : 'erro')."',
        '".$db->escape($protocolo)."',
        '".$db->escape($mensagemRetorno)."',
        '".$db->escape($dataHoraEvento)."',
        '".$db->escape($dataHoraProcessamento)."'
    )";
    
    if (!$db->query($sqlEvento)) {
        throw new Exception('Erro ao salvar evento: ' . $db->lasterror());
    }
    
    if ($statusAtualizado) {
        // Atualiza status na tabela principal
        $sqlUpdate = "UPDATE ".MAIN_DB_PREFIX."nfse_nacional_emitidas 
                      SET status = 'cancelada',
                          data_hora_cancelamento = '".$db->escape($dataHoraEvento)."'
                      WHERE id = ".((int)$id);
        
        if (!$db->query($sqlUpdate)) {
            throw new Exception('Erro ao atualizar status: ' . $db->lasterror());
        }
        
        $db->commit();
        
        error_log('[NFSE CANCELAMENTO] NFS-e #'.$nfse->numero_nfse.' cancelada com sucesso. Protocolo: '.$protocolo);
        
        // Salva mensagem flash na sessão para ser exibida via setEventMessage após reload
        if (!isset($_SESSION)) session_start();
        $_SESSION['nfse_flash_message'] = [
            'type' => 'mesgs',
            'message' => $mensagemRetorno
        ];
        
        echo json_encode([
            'success' => true,
            'message' => $mensagemRetorno,
            'protocolo' => $protocolo
        ]);
        
    } else {
        // Erro no cancelamento
        $db->rollback();
        
        error_log('[NFSE CANCELAMENTO] Erro ao cancelar NFS-e #'.$nfse->numero_nfse.': '.$mensagemRetorno);
        
        echo json_encode([
            'success' => false,
            'error' => $mensagemRetorno
        ]);
    }
    
} catch (Exception $e) {
    $db->rollback();
    
    $mensagemErro = $e->getMessage();
    error_log('[NFSE CANCELAMENTO] Exceção: '.$mensagemErro);
    
    echo json_encode([
        'success' => false,
        'error' => $mensagemErro
    ]);
}
