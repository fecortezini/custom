<?php
/**
 * Handler AJAX para consulta ASSÍNCRONA de eventos de NFS-e Nacional (FASE 2)
 * Este arquivo é chamado em background após a renderização da nota
 */
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);
require_once DOL_DOCUMENT_ROOT.'/custom/nfse/lib/nfse_security.lib.php';

// Só carrega main.inc.php se ainda não foi carregado
if (!defined('NOREQUIREMENU')) {
    define('NOREQUIREMENU', '1');
}
if (!defined('DOL_DOCUMENT_ROOT')) {
    require '../../main.inc.php';
}

/** @var DoliDB $db */
/** @var User $user */

require_once DOL_DOCUMENT_ROOT . '/custom/composerlib/vendor/autoload.php';

header('Content-Type: text/html; charset=utf-8');

$nfseId = GETPOST('id', 'int');

error_log('[NFSE EVENTOS ASYNC] Iniciando consulta assíncrona para ID: ' . $nfseId);

if (empty($nfseId) || $nfseId <= 0) {
    error_log('[NFSE EVENTOS ASYNC] ID inválido');
    exit; // Retorna vazio
}

try {
    // Busca dados da NFS-e
    $sql = "SELECT chave_acesso, ambiente FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas WHERE id = ".(int)$nfseId;
    $res = $db->query($sql);
    
    if (!$res || $db->num_rows($res) == 0) {
        error_log('[NFSE EVENTOS ASYNC] NFS-e não encontrada');
        exit;
    }
    
    $obj = $db->fetch_object($res);
    $chaveAcesso = $obj->chave_acesso;
    $ambiente = !empty($obj->ambiente) ? (int)$obj->ambiente : 2;
    
    if (empty($chaveAcesso)) {
        error_log('[NFSE EVENTOS ASYNC] Chave de acesso vazia');
        exit;
    }
    
    error_log('[NFSE EVENTOS ASYNC] Consultando eventos na API para chave: ' . $chaveAcesso);
    
    // Carrega certificado
    function carregarCertificadoA1($db) {
        $certPfx = null;
        $certPass = null;
        
        $tableKv = MAIN_DB_PREFIX . 'nfe_config';
        $res = @$db->query("SELECT name, value FROM `".$tableKv."`");
        if ($res) {
            while ($row = $db->fetch_object($res)) {
                if ($row->name === 'cert_pfx') $certPfx = $row->value;
                if ($row->name === 'cert_pass') $certPass = $row->value;
            }
        }
        
        if (empty($certPfx)) {
            $res2 = @$db->query("SELECT cert_pfx, cert_pass FROM `".$tableKv."` LIMIT 1");
            if ($res2 && $obj = $db->fetch_object($res2)) {
                $certPfx = $obj->cert_pfx;
                $certPass = $obj->cert_pass;
            }
        }
        
        if (is_resource($certPfx)) {
            $certPfx = stream_get_contents($certPfx);
        }
        
        if ($certPfx === null || $certPfx === '') {
            throw new Exception('Certificado não encontrado');
        }
        
        $certPass = (string)$certPass;
        $original = nfseDecryptPassword($certPass, $db);
        
        try {
            return \NFePHP\Common\Certificate::readPfx($certPfx, $original);
        } catch (Exception $e) {
            $certPfxDecoded = base64_decode($certPfx, true);
            if ($certPfxDecoded !== false) {
                return \NFePHP\Common\Certificate::readPfx($certPfxDecoded, $original);
            }
            throw $e;
        }
    }
    
    $cert = carregarCertificadoA1($db);
    
    // Inicializa tools
    $config = new stdClass();
    $config->tpamb = $ambiente;
    $configJson = json_encode($config);
    $tools = new \Hadder\NfseNacional\Tools($configJson, $cert);
    
    // Consulta eventos
    $eventosApiResponse = $tools->consultarNfseEventos($chaveAcesso, '101101', 1);
    
    if (empty($eventosApiResponse) || !isset($eventosApiResponse['eventos']) || count($eventosApiResponse['eventos']) == 0) {
        error_log('[NFSE EVENTOS ASYNC] Nenhum evento retornado pela API');
        exit; // Retorna vazio - a seção será ocultada
    }
    
    error_log('[NFSE EVENTOS ASYNC] Eventos recebidos: ' . count($eventosApiResponse['eventos']));
    
    // Marca origem como API
    foreach ($eventosApiResponse['eventos'] as &$evt) {
        $evt['origem'] = 'api';
    }
    
    $eventosResponse = $eventosApiResponse;
    $eventosResponse['origem'] = 'api';
    
    // Renderiza HTML da seção de eventos (igual ao código principal)
    echo '<div id="nfseEventosSection" class="nfse-section">';
    echo '<div class="nfse-section-header">Eventos da NFS-e';
    
    // Indicador de origem
    echo '<span style="background:#007bff; color:white; padding:2px 6px; border-radius:3px; font-size:10px; margin-left:8px;">API SEFAZ</span>';
    echo '</div>';
    
    // Informações do processamento
    echo '<div style="background:#f9f9f9; padding: 8px 15px; border-bottom:1px solid #eee; font-size:11px; color:#666;">';
    
    if(isset($eventosResponse['dataHoraProcessamento'])) {
        $dhProc = date('d/m/Y H:i:s', strtotime($eventosResponse['dataHoraProcessamento']));
        echo '<b>Processado em:</b> '.$dhProc.' | ';
    }
    if(isset($eventosResponse['tipoAmbiente'])) {
        $ambEvento = $eventosResponse['tipoAmbiente'] == 1 ? 'Produção' : 'Homologação';
        echo '<b>Ambiente:</b> '.$ambEvento.' | ';
    }
    if(isset($eventosResponse['versaoAplicativo'])) {
        echo '<b>Versão:</b> '.$eventosResponse['versaoAplicativo'].' | ';
    }
    echo '<b>Fonte:</b> API do Governo (consulta assíncrona)';
    echo '</div>';
    
    // Tabela de eventos
    echo '<table class="nfse-table">';
    echo '<tr style="background:#f5f5f5; font-size:11px; text-transform:uppercase;">';
    echo '<td style="border-bottom:1px solid #ddd; padding:8px;"><b>Tipo Evento</b></td>';
    echo '<td style="border-bottom:1px solid #ddd; padding:8px;"><b>Nº Pedido</b></td>';
    echo '<td style="border-bottom:1px solid #ddd; padding:8px;"><b>Data/Hora</b></td>';
    echo '<td style="border-bottom:1px solid #ddd; padding:8px;"><b>Status</b></td>';
    echo '</tr>';
    
    foreach ($eventosResponse['eventos'] as $evento) {
        $tipoEvento = isset($evento['tipoEvento']) ? $evento['tipoEvento'] : '-';
        
        $numPedido = isset($evento['numeroPedidoRegistroEvento']) 
            ? $evento['numeroPedidoRegistroEvento'] 
            : (isset($evento['protocolo']) ? $evento['protocolo'] : '-');
        
        $dhEvento = '-';
        if(isset($evento['dataHoraProcessamento']) && !empty($evento['dataHoraProcessamento'])) {
            $dhEvento = date('d/m/Y H:i:s', strtotime($evento['dataHoraProcessamento']));
        } elseif(isset($evento['dataHoraEvento']) && !empty($evento['dataHoraEvento'])) {
            $dhEvento = date('d/m/Y H:i:s', strtotime($evento['dataHoraEvento']));
        } elseif(isset($evento['dataHoraRecebimento'])) {
            $dhEvento = date('d/m/Y H:i:s', strtotime($evento['dataHoraRecebimento']));
        }
        
        $hasXml = isset($evento['arquivoXml']) && !empty($evento['arquivoXml']);
        $statusEvento = $hasXml ? '✓ Registrado' : 'Pendente';
        $statusColor = $hasXml ? '#28a745' : '#6c757d';
        
        $tipoEventoDesc = '';
        switch($tipoEvento) {
            case '101101': 
            case 'e101101': 
                $tipoEventoDesc = 'Cancelamento'; 
                break;
            case '101102': 
            case 'e101102': 
                $tipoEventoDesc = 'Substituição'; 
                break;
            default: 
                $tipoEventoDesc = 'Tipo '.$tipoEvento;
        }
        
        echo '<tr>';
        echo '<td><span class="nfse-value">'.$tipoEventoDesc.' ('.$tipoEvento.')</span></td>';
        echo '<td><span class="nfse-value">'.$numPedido.'</span></td>';
        echo '<td><span class="nfse-value">'.$dhEvento.'</span></td>';
        echo '<td><span class="nfse-value" style="color:'.$statusColor.';">'.$statusEvento.'</span></td>';
        echo '</tr>';
        
        if(isset($evento['descricaoMotivo']) && !empty($evento['descricaoMotivo'])) {
            echo '<tr style="background:#fafafa;">';
            echo '<td colspan="4" style="padding:6px 10px 6px 20px; font-size:11px; color:#666;">';
            echo '<b>Motivo:</b> '.htmlspecialchars($evento['descricaoMotivo']);
            echo '</td>';
            echo '</tr>';
        }
    }
    
    echo '</table>';
    echo '</div>';
    
    error_log('[NFSE EVENTOS ASYNC] HTML renderizado com sucesso');
    
} catch (Exception $e) {
    error_log('[NFSE EVENTOS ASYNC] Erro: ' . $e->getMessage());
    // Retorna vazio em caso de erro - a seção será ocultada
    exit;
}
