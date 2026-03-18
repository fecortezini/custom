<?php
/**
 * Handler AJAX para busca de eventos NFS-e na API SEFAZ
 * Consulta a API do governo, insere eventos novos no banco local e retorna JSON
 */
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

// Handler de erros fatais
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log('[BUSCAR_EVENTOS] ERRO FATAL: ' . print_r($error, true));
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(500);
        }
        echo json_encode([
            'sucesso' => false,
            'erro' => 'Erro fatal no servidor: ' . $error['message']
        ]);
    }
});

error_log('[BUSCAR_EVENTOS] Iniciando script...');

if (!defined('NOREQUIREMENU')) {
    define('NOREQUIREMENU', '1');
}

if (!defined('DOL_DOCUMENT_ROOT')) {
    try {
        require '../../main.inc.php';
        error_log('[BUSCAR_EVENTOS] main.inc.php carregado com sucesso');
    } catch (Exception $e) {
        error_log('[BUSCAR_EVENTOS] ERRO ao carregar main.inc.php: ' . $e->getMessage());
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
    
    error_log('[BUSCAR_EVENTOS] Buscando eventos para NFS-e ID: ' . $nfseId);
    
    // Busca chave de acesso e ambiente da nota
    $sql = "SELECT chave_acesso, ambiente, status FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas WHERE id = ".(int)$nfseId;
    $res = $db->query($sql);
    
    if (!$res || $db->num_rows($res) == 0) {
        throw new Exception('NFS-e não encontrada.');
    }
    
    $obj = $db->fetch_object($res);
    $chaveAcesso = $obj->chave_acesso;
    $ambiente = !empty($obj->ambiente) ? (int)$obj->ambiente : 2;
    $statusNota = $obj->status;
    
    if (empty($chaveAcesso)) {
        throw new Exception('Chave de acesso não disponível para esta NFS-e.');
    }
    
    // Busca eventos já existentes no banco local
    $sqlEventosLocais = "SELECT id, tipo_evento, protocolo, data_hora_processamento, status_evento 
                         FROM ".MAIN_DB_PREFIX."nfse_nacional_eventos 
                         WHERE id_nfse = ".(int)$nfseId;
    $resLocais = $db->query($sqlEventosLocais);
    $eventosLocais = [];
    if ($resLocais) {
        while ($evt = $db->fetch_object($resLocais)) {
            $eventosLocais[] = [
                'id' => $evt->id,
                'tipo_evento' => $evt->tipo_evento,
                'protocolo' => $evt->protocolo,
                'data_hora_processamento' => $evt->data_hora_processamento,
                'status_evento' => $evt->status_evento
            ];
        }
    }
    
    error_log('[BUSCAR_EVENTOS] Eventos locais existentes: ' . count($eventosLocais));
    
    // Consulta API do governo
    $cert = carregarCertificadoA1($db);
    
    $config = new stdClass();
    $config->tpamb = $ambiente;
    $configJson = json_encode($config);
    
    $tools = new \Hadder\NfseNacional\Tools($configJson, $cert);
    
    error_log('[BUSCAR_EVENTOS] Consultando eventos na API SEFAZ...');
    
    // Consulta todos os tipos de eventos possíveis
    $tiposEventos = ['101101', '101102']; // Cancelamento e Substituição
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
            error_log('[BUSCAR_EVENTOS] Erro ao consultar tipo '.$tipoEvento.': ' . $e->getMessage());
        }
    }
    
    error_log('[BUSCAR_EVENTOS] Eventos encontrados na API: ' . count($eventosAPI));
    
    // ========== PROCESSAMENTO ==========
    $resultado = [
        'sucesso' => true,
        'total_api' => count($eventosAPI),
        'total_locais_antes' => count($eventosLocais),
        'novos_inseridos' => 0,
        'ja_existentes' => 0,
        'eventos' => [],
        'status_nota_atualizado' => false
    ];
    
    if (count($eventosAPI) === 0) {
        $resultado['mensagem'] = 'Nenhum evento encontrado na API para esta NFS-e.';
        echo json_encode($resultado);
        exit;
    }
    
    $db->begin();
    
    // Para cada evento da API, verifica se já existe localmente
    foreach ($eventosAPI as $eventoApi) {
        $tipoApi = isset($eventoApi['tipoEvento']) ? $eventoApi['tipoEvento'] : '';
        $tipoApiNorm = str_replace('e', '', $tipoApi);
        $protocoloApi = isset($eventoApi['numeroPedidoRegistroEvento']) ? $eventoApi['numeroPedidoRegistroEvento'] : '';
        
        // Verifica se já existe localmente (comparando tipo E protocolo para evitar duplicatas)
        $existeLocal = false;
        foreach ($eventosLocais as $eventoLocal) {
            $tipoLocalNorm = str_replace('e', '', $eventoLocal['tipo_evento']);
            $protocoloLocal = $eventoLocal['protocolo'];
            
            // Compara tipo de evento E protocolo (se ambos tiverem protocolo)
            if ($tipoLocalNorm === $tipoApiNorm) {
                // Se ambos têm protocolo, compara também o protocolo
                if (!empty($protocoloApi) && !empty($protocoloLocal)) {
                    if ($protocoloLocal === $protocoloApi) {
                        $existeLocal = true;
                    }
                } else {
                    // Se não há protocolo, considera apenas o tipo (evento único por tipo)
                    $existeLocal = true;
                }
                
                if ($existeLocal) {
                    // Atualiza status de conciliação do evento existente
                    $sqlUpdate = "UPDATE ".MAIN_DB_PREFIX."nfse_nacional_eventos 
                                  SET status_conciliacao = 'OK', ultima_sincronizacao = NOW()
                                  WHERE id = ".(int)$eventoLocal['id'];
                    $db->query($sqlUpdate);
                    break;
                }
            }
        }
        
        // Monta dados do evento para retorno
        $dhRecebimento = isset($eventoApi['dataHoraRecebimento']) ? $eventoApi['dataHoraRecebimento'] : null;
        $dhProcessamento = isset($eventoApi['dataHoraProcessamento']) ? $eventoApi['dataHoraProcessamento'] : null;
        $dhEvento = $dhProcessamento ?: ($dhRecebimento ?: date('Y-m-d H:i:s'));
        $descMotivo = isset($eventoApi['descricaoMotivo']) ? $eventoApi['descricaoMotivo'] : '';
        $codMotivo = isset($eventoApi['codigoMotivo']) ? $eventoApi['codigoMotivo'] : '';
        $hasXml = isset($eventoApi['arquivoXml']) && !empty($eventoApi['arquivoXml']);
        
        // Descrição do tipo de evento
        $tipoDesc = '';
        switch ($tipoApiNorm) {
            case '101101': $tipoDesc = 'Cancelamento'; break;
            case '101102': $tipoDesc = 'Substituição'; break;
            default: $tipoDesc = 'Tipo '.$tipoApi;
        }
        
        $eventoRetorno = [
            'tipo_evento' => $tipoApi,
            'tipo_descricao' => $tipoDesc,
            'protocolo' => $protocoloApi,
            'data_hora' => $dhEvento,
            'descricao_motivo' => $descMotivo,
            'status' => $hasXml ? 'Registrado' : 'Pendente',
            'origem' => $existeLocal ? 'existente' : 'novo'
        ];
        
        if ($existeLocal) {
            $resultado['ja_existentes']++;
            $resultado['eventos'][] = $eventoRetorno;
            continue;
        }
        
        // Insere evento novo no banco
        $jsonRetorno = json_encode($eventoApi);
        $tipoEventoDb = strpos($tipoApi, 'e') === 0 ? $tipoApi : 'e'.$tipoApi;
        
        $sqlInsert = "INSERT INTO ".MAIN_DB_PREFIX."nfse_nacional_eventos 
                      (id_nfse, tipo_evento, chave_nfse, protocolo, codigo_motivo, descricao_motivo,
                       data_hora_evento, data_hora_processamento, 
                       json_retorno, status_evento, status_conciliacao, ultima_sincronizacao, created_at)
                      VALUES (
                          ".(int)$nfseId.",
                          '".$db->escape($tipoEventoDb)."',
                          '".$db->escape($chaveAcesso)."',
                          ".($protocoloApi ? "'".$db->escape($protocoloApi)."'" : "NULL").",
                          ".($codMotivo ? "'".$db->escape($codMotivo)."'" : "NULL").",
                          ".($descMotivo ? "'".$db->escape($descMotivo)."'" : "NULL").",
                          '".$db->escape($dhEvento)."',
                          ".($dhProcessamento ? "'".$db->escape($dhProcessamento)."'" : "NULL").",
                          '".$db->escape($jsonRetorno)."',
                          'processado',
                          'OK',
                          NOW(),
                          NOW()
                      )";
        
        if (!$db->query($sqlInsert)) {
            error_log('[BUSCAR_EVENTOS] Erro ao inserir evento: ' . $db->lasterror());
        } else {
            $resultado['novos_inseridos']++;
            error_log('[BUSCAR_EVENTOS] Evento novo inserido: tipo='.$tipoEventoDb);
        }
        
        $resultado['eventos'][] = $eventoRetorno;
    }
    
    // Atualiza status da nota se houver cancelamento confirmado
    $temCancelamento = false;
    foreach ($eventosAPI as $eventoApi) {
        $tipoApiNorm = str_replace('e', '', isset($eventoApi['tipoEvento']) ? $eventoApi['tipoEvento'] : '');
        if ($tipoApiNorm === '101101') {
            $temCancelamento = true;
            break;
        }
    }
    
    if ($temCancelamento && strtolower($statusNota) !== 'cancelada') {
        $sqlUpdateNota = "UPDATE ".MAIN_DB_PREFIX."nfse_nacional_emitidas 
                         SET status = 'cancelada' 
                         WHERE id = ".(int)$nfseId;
        if ($db->query($sqlUpdateNota)) {
            $resultado['status_nota_atualizado'] = true;
            error_log('[BUSCAR_EVENTOS] Status da NFS-e atualizado para CANCELADA');
        }
    }
    
    $db->commit();
    
    // Monta mensagem
    $partes = [];
    if ($resultado['novos_inseridos'] > 0) {
        $partes[] = $resultado['novos_inseridos'] . ' evento(s) novo(s) inserido(s)';
    }
    if ($resultado['ja_existentes'] > 0) {
        $partes[] = $resultado['ja_existentes'] . ' já existente(s)';
    }
    if ($resultado['status_nota_atualizado']) {
        $partes[] = 'status da nota atualizado para cancelada';
    }
    
    $resultado['mensagem'] = !empty($partes) 
        ? 'Busca concluída: ' . implode(', ', $partes) . '.'
        : 'Busca concluída. ' . count($eventosAPI) . ' evento(s) encontrado(s).';
    
    error_log('[BUSCAR_EVENTOS] ' . $resultado['mensagem']);
    
    echo json_encode($resultado);
    
} catch (Exception $e) {
    if (isset($db) && $db->transaction_opened) {
        $db->rollback();
    }
    
    error_log('[BUSCAR_EVENTOS] ERRO: ' . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'erro' => $e->getMessage()
    ]);
}

error_log('[BUSCAR_EVENTOS] Script finalizado');
