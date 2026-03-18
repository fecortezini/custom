<?php
/**
 * Handler AJAX para reconciliação de eventos NFS-e (Fluxo 2 - Fase 2)
 * Compara eventos locais com API do governo e atualiza status_conciliacao
 */
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);


// Handler de erros fatais
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log('[RECONCILIACAO] ERRO FATAL: ' . print_r($error, true));
        
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        
        echo json_encode([
            'sucesso' => false,
            'erro' => 'Erro fatal no servidor: ' . $error['message'],
            'debug' => [
                'file' => $error['file'],
                'line' => $error['line'],
                'type' => $error['type']
            ]
        ]);
    }
});

error_log('[RECONCILIACAO] Iniciando script...');

if (!defined('NOREQUIREMENU')) {
    define('NOREQUIREMENU', '1');
}

error_log('[RECONCILIACAO] Carregando main.inc.php...');

if (!defined('DOL_DOCUMENT_ROOT')) {
    try {
        require '../../main.inc.php';
        error_log('[RECONCILIACAO] main.inc.php carregado com sucesso');
    } catch (Exception $e) {
        error_log('[RECONCILIACAO] ERRO ao carregar main.inc.php: ' . $e->getMessage());
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode([
            'sucesso' => false,
            'erro' => 'Erro ao carregar framework Dolibarr: ' . $e->getMessage()
        ]);
        exit;
    }
}

/** @var DoliDB $db */
/** @var User $user */

try {
    require_once DOL_DOCUMENT_ROOT . '/custom/composerlib/vendor/autoload.php';
    
} catch (Exception $e) {
    
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'erro' => 'Erro ao carregar bibliotecas: ' . $e->getMessage()
    ]);
    exit;
}
require_once DOL_DOCUMENT_ROOT.'/custom/nfse/lib/nfse_security.lib.php';
header('Content-Type: application/json; charset=utf-8');

function carregarCertificadoA1($db) {
    $certPfx = null;
    $certPass = null;

    $tableKv = (defined('MAIN_DB_PREFIX') ? MAIN_DB_PREFIX : '') . 'nfe_config';
    $res = @$db->query("SELECT name, value FROM `".$tableKv."`");
    if ($res) {
        while ($row = $db->fetch_object($res)) {
            if ($row->name === 'cert_pfx') $certPfx = $row->value;
            if ($row->name === 'cert_pass') $certPass = $row->value;
        }
    }

    if (empty($certPfx)) {
        $tableDirect = MAIN_DB_PREFIX . 'nfe_config';
        $res2 = @$db->query("SELECT cert_pfx, cert_pass FROM `".$tableDirect."` LIMIT 1");
        if ($res2 && $obj = $db->fetch_object($res2)) {
            $certPfx = $obj->cert_pfx;
            $certPass = $obj->cert_pass;
        }
    }

    if (is_resource($certPfx)) {
        $certPfx = stream_get_contents($certPfx);
    }
    
    if ($certPfx === null || $certPfx === '') {
        throw new Exception('Certificado PFX não encontrado.');
    }
    
    $certPass = (string)$certPass;
    $original = nfseDecryptPassword($certPass, $db);
    try {
        $cert = \NFePHP\Common\Certificate::readPfx($certPfx, $original);
        return $cert;
    } catch (Exception $e) {
        $certPfxDecoded = base64_decode($certPfx, true);
        if ($certPfxDecoded !== false) {
            $cert = \NFePHP\Common\Certificate::readPfx($certPfxDecoded, $original);
            return $cert;
        }
        throw new Exception('Erro ao ler certificado: ' . $e->getMessage());
    }
}

try {
    $nfseId = GETPOST('id', 'int');
    
    if (empty($nfseId)) {
        throw new Exception('ID da NFS-e não informado.');
    }
    
    error_log('[RECONCILIACAO] Iniciando reconciliação para NFS-e ID: ' . $nfseId);
    
    // Busca chave de acesso e ambiente da nota
    $sql = "SELECT chave_acesso, ambiente FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas WHERE id = ".(int)$nfseId;
    $res = $db->query($sql);
    
    if (!$res || $db->num_rows($res) == 0) {
        throw new Exception('NFS-e não encontrada.');
    }
    
    $obj = $db->fetch_object($res);
    $chaveAcesso = $obj->chave_acesso;
    $ambiente = !empty($obj->ambiente) ? (int)$obj->ambiente : 2;
    
    if (empty($chaveAcesso)) {
        throw new Exception('Chave de acesso não disponível.');
    }
    
    // Busca eventos locais
    $sqlEventosLocais = "SELECT id, tipo_evento, protocolo, data_hora_processamento, status_evento 
                         FROM ".MAIN_DB_PREFIX."nfse_nacional_eventos 
                         WHERE id_nfse = ".(int)$nfseId;
    
    $resLocais = $db->query($sqlEventosLocais);
    $eventosLocais = [];
    
    if ($resLocais) {
        while ($evt = $db->fetch_object($resLocais)) {
            $eventosLocais[$evt->id] = [
                'id' => $evt->id,
                'tipo_evento' => $evt->tipo_evento,
                'protocolo' => $evt->protocolo,
                'data_hora_processamento' => $evt->data_hora_processamento,
                'status_evento' => $evt->status_evento
            ];
        }
    }
    
    error_log('[RECONCILIACAO] Encontrados ' . count($eventosLocais) . ' eventos locais');
    
    // Consulta API do governo
    $cert = carregarCertificadoA1($db);
    
    $config = new stdClass();
    $config->tpamb = $ambiente;
    $configJson = json_encode($config);
    
    $tools = new \Hadder\NfseNacional\Tools($configJson, $cert);
    
    error_log('[RECONCILIACAO] Consultando eventos na API do governo...');
    
    // Consulta todos os tipos de eventos possíveis
    $tiposEventos = ['101101', '101102']; // Cancelamento, Substituição
    $eventosAPI = [];
    
    foreach ($tiposEventos as $tipoEvento) {
        try {
            $response = $tools->consultarNfseEventos($chaveAcesso, $tipoEvento, 1);
            
            if (!empty($response) && isset($response['eventos'])) {
                foreach ($response['eventos'] as $evt) {
                    $eventosAPI[] = $evt;
                }
            }
        } catch (Exception $e) {
            error_log('[RECONCILIACAO] Erro ao consultar tipo '.$tipoEvento.': ' . $e->getMessage());
        }
    }
    
    // ========== RECONCILIAÇÃO ==========
    $resultado = [
        'sucesso' => true,
        'total_locais' => count($eventosLocais),
        'total_api' => count($eventosAPI),
        'conciliados' => 0,
        'divergentes' => 0,
        'inexistentes_governo' => 0,
        'novos_inseridos' => 0,
        'detalhes' => []
    ];
    
    $db->begin();
    
    // 1. Verifica eventos locais vs API
    foreach ($eventosLocais as $idEvento => $eventoLocal) {
        $encontrado = false;
        
        foreach ($eventosAPI as $eventoApi) {
            // Normaliza tipo de evento (remove 'e' se houver)
            $tipoLocal = str_replace('e', '', $eventoLocal['tipo_evento']);
            $tipoApi = isset($eventoApi['tipoEvento']) ? str_replace('e', '', $eventoApi['tipoEvento']) : '';
            
            // Compara por tipo de evento
            if ($tipoLocal === $tipoApi) {
                $encontrado = true;
                // Não verifica divergências - se o tipo é igual, considera OK
                break;
            }
        }
        
        // Atualiza status de conciliação
        $novoStatus = 'NAO_CONFIRMADO';
        
        if ($encontrado) {
            $novoStatus = 'OK';
            $resultado['conciliados']++;
        } else {
            $novoStatus = 'INEXISTENTE_NO_GOV';
            $resultado['inexistentes_governo']++;
        }
        
        // Valores ENUM não precisam de escape, são constantes controladas
        $sqlUpdate = "UPDATE ".MAIN_DB_PREFIX."nfse_nacional_eventos 
                      SET status_conciliacao = '".$novoStatus."',
                          ultima_sincronizacao = NOW()
                      WHERE id = ".(int)$idEvento;
        
        error_log('[RECONCILIACAO] SQL Update: ' . $sqlUpdate);
        
        if (!$db->query($sqlUpdate)) {
            $errorMsg = 'Erro ao atualizar status de conciliação: ' . $db->lasterror();
            error_log('[RECONCILIACAO] ' . $errorMsg);
            throw new Exception($errorMsg);
        }
        
        error_log('[RECONCILIACAO] Evento ID '.$idEvento.' atualizado para: ' . $novoStatus);
        
        $resultado['detalhes'][] = [
            'evento_local_id' => $idEvento,
            'tipo' => $eventoLocal['tipo_evento'],
            'status_anterior' => $eventoLocal['status_evento'],
            'status_conciliacao' => $novoStatus
        ];
    }
    
    // 2. Verifica se há eventos na API que não existem localmente (caso raro, mas possível)
    foreach ($eventosAPI as $eventoApi) {
        $tipoApi = isset($eventoApi['tipoEvento']) ? str_replace('e', '', $eventoApi['tipoEvento']) : '';
        $existeLocal = false;
        
        foreach ($eventosLocais as $eventoLocal) {
            $tipoLocal = str_replace('e', '', $eventoLocal['tipo_evento']);
            if ($tipoLocal === $tipoApi) {
                $existeLocal = true;
                break;
            }
        }
        
        // Se não existe localmente, insere
        if (!$existeLocal) {
            $protocoloApi = isset($eventoApi['numeroPedidoRegistroEvento']) ? $eventoApi['numeroPedidoRegistroEvento'] : null;
            $dhRecebimento = isset($eventoApi['dataHoraRecebimento']) ? $eventoApi['dataHoraRecebimento'] : date('Y-m-d H:i:s');
            $dhProcessamento = isset($eventoApi['dataHoraProcessamento']) ? $eventoApi['dataHoraProcessamento'] : null;
            $jsonRetorno = json_encode($eventoApi);
            
            $sqlInsert = "INSERT INTO ".MAIN_DB_PREFIX."nfse_nacional_eventos 
                          (id_nfse, tipo_evento, chave_nfse, protocolo, data_hora_evento, data_hora_processamento, 
                           json_retorno, status_evento, status_conciliacao, ultima_sincronizacao, created_at)
                          VALUES (
                              ".(int)$nfseId.",
                              '".$db->escape('e'.$tipoApi)."',
                              '".$db->escape($chaveAcesso)."',
                              ".($protocoloApi ? "'".$db->escape($protocoloApi)."'" : "NULL").",
                              '".$db->idate($dhRecebimento)."',
                              ".($dhProcessamento ? "'".$db->idate($dhProcessamento)."'" : "NULL").",
                              '".$db->escape($jsonRetorno)."',
                              'processado',
                              'OK',
                              NOW(),
                              NOW()
                          )";
            
            if (!$db->query($sqlInsert)) {
                error_log('[RECONCILIACAO] Erro ao inserir evento da API: ' . $db->lasterror());
            } else {
                $resultado['novos_inseridos']++;
                error_log('[RECONCILIACAO] Evento da API inserido localmente: tipo='.$tipoApi);
            }
        }
    }
    
    // Atualiza status da NFS-e principal se houver cancelamento confirmado
    $temCancelamentoConfirmado = false;
    foreach ($eventosLocais as $eventoLocal) {
        $tipoLocal = str_replace('e', '', $eventoLocal['tipo_evento']);
        if ($tipoLocal === '101101') {
            // Verifica se foi conciliado com sucesso
            $sqlCheckStatus = "SELECT status_conciliacao FROM ".MAIN_DB_PREFIX."nfse_nacional_eventos WHERE id = ".(int)$eventoLocal['id'];
            $resCheck = $db->query($sqlCheckStatus);
            if ($resCheck && $objCheck = $db->fetch_object($resCheck)) {
                if ($objCheck->status_conciliacao === 'OK') {
                    $temCancelamentoConfirmado = true;
                    break;
                }
            }
        }
    }
    
    // Atualiza status da nota principal para cancelada
    if ($temCancelamentoConfirmado) {
        $sqlUpdateNota = "UPDATE ".MAIN_DB_PREFIX."nfse_nacional_emitidas 
                         SET status = 'cancelada' 
                         WHERE id = ".(int)$nfseId;
        
        if (!$db->query($sqlUpdateNota)) {
            error_log('[RECONCILIACAO] Erro ao atualizar status da nota para cancelada: ' . $db->lasterror());
        } else {
            error_log('[RECONCILIACAO] Status da NFS-e atualizado para CANCELADA');
        }
    }
    
    $db->commit();
    
    // Monta mensagem amigável dependendo dos resultados
    $mensagemParts = array();
    
    if ($resultado['conciliados'] > 0) {
        $mensagemParts[] = $resultado['conciliados'] . ' evento(s) confirmado(s)';
    }
    if ($resultado['divergentes'] > 0) {
        $mensagemParts[] = $resultado['divergentes'] . ' com divergência';
    }
    if ($resultado['inexistentes_governo'] > 0) {
        $mensagemParts[] = $resultado['inexistentes_governo'] . ' não encontrado(s) no governo';
    }
    if ($resultado['novos_inseridos'] > 0) {
        $mensagemParts[] = $resultado['novos_inseridos'] . ' novo(s) adicionado(s)';
    }
    
    if (!empty($mensagemParts)) {
        $resultado['mensagem'] = 'Sincronização concluída: ' . implode(', ', $mensagemParts) . '.';
    } else {
        $resultado['mensagem'] = 'Sincronização concluída. Nenhuma alteração necessária.';
    }
    
    error_log('[RECONCILIACAO] ' . $resultado['mensagem']);
    
    echo json_encode($resultado);
    
} catch (Exception $e) {
    if ($db->transaction_opened) {
        $db->rollback();
    }
    
    $errorMsg = $e->getMessage();
    $errorTrace = $e->getTraceAsString();
    
    error_log('[RECONCILIACAO] ERRO EXCEPTION: ' . $errorMsg);
    error_log('[RECONCILIACAO] Stack trace: ' . $errorTrace);
    
    http_response_code(500);
    
    echo json_encode([
        'sucesso' => false,
        'erro' => $errorMsg,
        'debug' => [
            'file' => $e->getFile(),
            'line' => $e->getLine()
        ]
    ]);
}

error_log('[RECONCILIACAO] Script finalizado');
