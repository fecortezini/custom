<?php
/**
 * Handler AJAX para consulta de NFS-e Nacional
 */
if (function_exists('opcache_reset')) { opcache_reset(); }
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
error_reporting(E_ALL);
require_once DOL_DOCUMENT_ROOT.'/custom/nfse/lib/nfse_security.lib.php';
if(!function_exists('buscarDadosIbge')){
    dol_include_once('/custom/labapp/lib/ibge_utils.php');
}

// Registra handler de erro personalizado para capturar erros fatais
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        error_log('[NFSE CONSULTA] ERRO FATAL: ' . print_r($error, true));
        // Limpa qualquer output anterior
        while (ob_get_level()) {
            ob_end_clean();
        }
        header('Content-Type: text/html; charset=utf-8');
        echo '<div class="nfse-alert nfse-alert-error">';
        echo '<strong>Erro crítico na consulta:</strong><br>';
        echo htmlspecialchars($error['message']) . '<br>';
        echo '<small>Arquivo: ' . htmlspecialchars($error['file']) . ' (linha ' . $error['line'] . ')</small>';
        echo '</div>';
        exit;
    }
});

// Só carrega main.inc.php se ainda não foi carregado (standalone vs include)
if (!defined('NOREQUIREMENU')) {
    define('NOREQUIREMENU', '1');
}
if (!defined('DOL_DOCUMENT_ROOT')) {
    require '../../main.inc.php';
}

/** @var DoliDB $db */
/** @var User $user */

// Carrega autoload do Composer para classes externas
require_once DOL_DOCUMENT_ROOT . '/custom/composerlib/vendor/autoload.php';
global $db;
// Inicia buffer de saída
ob_start();

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

/**
 * Busca eventos da NFS-e no banco de dados local (Fluxo 1 - Prioridade Local)
 */
function buscarEventosLocais($db, $nfseId) {
    $eventos = [];
    
    $sql = "SELECT 
                tipo_evento,
                chave_nfse,
                codigo_motivo,
                descricao_motivo,
                chave_substituta,
                status_evento,
                protocolo,
                data_hora_evento,
                data_hora_processamento,
                mensagem_retorno,
                status_conciliacao,
                ultima_sincronizacao,
                json_retorno
            FROM ".MAIN_DB_PREFIX."nfse_nacional_eventos 
            WHERE id_nfse = ".(int)$nfseId."
            ORDER BY data_hora_evento DESC";
    
    $res = $db->query($sql);
    if ($res) {
        while ($obj = $db->fetch_object($res)) {
            $evento = [
                'tipoEvento' => $obj->tipo_evento,
                'chaveNfse' => $obj->chave_nfse,
                'codigoMotivo' => $obj->codigo_motivo,
                'descricaoMotivo' => $obj->descricao_motivo,
                'chaveSubstituta' => $obj->chave_substituta,
                'statusEvento' => $obj->status_evento,
                'protocolo' => $obj->protocolo,
                'dataHoraEvento' => $obj->data_hora_evento,
                'dataHoraProcessamento' => $obj->data_hora_processamento,
                'mensagemRetorno' => $obj->mensagem_retorno,
                'statusConciliacao' => $obj->status_conciliacao,
                'ultimaSincronizacao' => $obj->ultima_sincronizacao,
                'origem' => 'local' // Marca origem dos dados
            ];
            
            // Se há JSON salvo, mescla os dados
            if (!empty($obj->json_retorno)) {
                $jsonData = json_decode($obj->json_retorno, true);
                if ($jsonData && is_array($jsonData)) {
                    $evento = array_merge($evento, $jsonData);
                }
            }
            
            $eventos[] = $evento;
        }
    }
    
    return $eventos;
}

// Define header logo no início para evitar problemas
header('Content-Type: text/html; charset=utf-8');

// Captura ID da NFS-e a ser consultada
$nfseId = GETPOST('id', 'int');

error_log('[NFSE CONSULTA] ID capturado: ' . $nfseId);
error_log('[NFSE CONSULTA] Método: ' . $_SERVER['REQUEST_METHOD']);
error_log('[NFSE CONSULTA] POST data: ' . print_r($_POST, true));
error_log('[NFSE CONSULTA] GET data: ' . print_r($_GET, true));

if (empty($nfseId) || $nfseId <= 0) {
    error_log('[NFSE CONSULTA] ERRO: ID inválido ou vazio');
    $output = ob_get_clean();
    echo '<div class="nfse-alert nfse-alert-error">';
    echo '<strong>Erro:</strong> ID da NFS-e não informado ou inválido.';
    echo '<br><small>ID recebido: ' . htmlspecialchars(var_export($nfseId, true)) . '</small>';
    echo '</div>';
    exit;
}

try {
    error_log('[NFSE CONSULTA] Buscando chave de acesso no banco...');
    
    // Verifica conexão com banco
    if (!isset($db) || !is_object($db)) {
        throw new Exception('Conexão com banco de dados não disponível.');
    }
    
    // Busca chave de acesso, AMBIENTE e XML armazenado da NFS-e no banco
    $sql = "SELECT chave_acesso, numero_nfse, status, ambiente, xml_nfse 
            FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas 
            WHERE id = ".(int)$nfseId;
    
    error_log('[NFSE CONSULTA] SQL: ' . $sql);
    $res = $db->query($sql);
    
    if (!$res) {
        error_log('[NFSE CONSULTA] ERRO SQL: ' . $db->lasterror());
        throw new Exception('Erro ao consultar banco de dados: ' . $db->lasterror());
    }
    
    if ($db->num_rows($res) == 0) {
        error_log('[NFSE CONSULTA] ERRO: NFS-e não encontrada - ID: ' . $nfseId);
        throw new Exception('NFS-e #' . $nfseId . ' não encontrada no banco de dados. Verifique se a nota foi emitida corretamente.');
    }
    
    $obj = $db->fetch_object($res);
    $chaveAcesso = $obj->chave_acesso;
    $numeroNfse = $obj->numero_nfse;
    $status = $obj->status;
    // Usa o ambiente gravado na nota, ou fallback para função global se nulo
    $ambiente = !empty($obj->ambiente) ? (int)$obj->ambiente : getAmbienteNacional($db);
    $xmlLocal = $obj->xml_nfse;
    
    error_log('[NFSE CONSULTA] Dados da NFS-e:');
    error_log('[NFSE CONSULTA] - ID: ' . $nfseId);
    error_log('[NFSE CONSULTA] - Número: ' . $numeroNfse);
    error_log('[NFSE CONSULTA] - Status: ' . $status);
    error_log('[NFSE CONSULTA] - Chave: ' . $chaveAcesso);
    error_log('[NFSE CONSULTA] - Ambiente: ' . $ambiente . ' (' . ($ambiente == 1 ? 'Produção' : 'Homologação') . ')');
    error_log('[NFSE CONSULTA] - Tem XML local: ' . (empty($xmlLocal) ? 'NÃO' : 'SIM (' . strlen($xmlLocal) . ' bytes)'));
    
    if (empty($chaveAcesso) || trim($chaveAcesso) === '') {
        error_log('[NFSE CONSULTA] ERRO: Chave vazia - ID: ' . $nfseId . ', Número: ' . $numeroNfse . ', Status: ' . $status);
        throw new Exception('Chave de acesso não disponível para esta NFS-e.<br><small>A nota pode ter sido salva sem chave de acesso devido a um erro no envio. Status atual: ' . htmlspecialchars($status) . '</small>');
    }
    
    // ========== FLUXO HÍBRIDO DE CONSULTA (Prioridade Local) ==========
    $xmlResponse = null;
    $origemConsulta = 'nenhum';
    
    // Normaliza BLOB/stream se necessário
    if (is_resource($xmlLocal)) {
        $xmlLocal = stream_get_contents($xmlLocal);
    }
    
    // PRIORIDADE 1: Tenta usar XML armazenado localmente
    if (!empty($xmlLocal) && trim($xmlLocal) !== '') {
        error_log('[NFSE CONSULTA] XML encontrado localmente no banco de dados');
        $xmlResponse = $xmlLocal;
        $origemConsulta = 'local';
    } else {
        // FALLBACK: Consulta API do governo
        error_log('[NFSE CONSULTA] XML local não encontrado, consultando API da SEFAZ...');
        
        try {
            // Carrega certificado
            $cert = carregarCertificadoA1Nacional($db);
            error_log('[NFSE CONSULTA] Certificado carregado com sucesso');

            // Cria configuração JSON para a biblioteca
            $config = new stdClass();
            $config->tpamb = $ambiente;
            $configJson = json_encode($config);
            error_log('[NFSE CONSULTA] Config JSON: ' . $configJson);

            // Inicializa ferramenta de consulta
            $tools = new \Hadder\NfseNacional\Tools($configJson, $cert);
            error_log('[NFSE CONSULTA] Tools inicializado');

            // Consulta NFS-e pela chave de acesso
            error_log('[NFSE CONSULTA] Consultando chave: ' . $chaveAcesso);
            $xmlResponse = $tools->consultarNfseChave($chaveAcesso);
            error_log('[NFSE CONSULTA] XML recebido da API, tamanho: ' . strlen($xmlResponse));
            $origemConsulta = 'api';
        } catch (Exception $apiEx) {
            error_log('[NFSE CONSULTA] ERRO na consulta API: ' . $apiEx->getMessage());
            throw new Exception('Erro ao consultar API da SEFAZ: ' . $apiEx->getMessage() . '<br><small>A API pode estar temporariamente indisponível. Tente novamente em alguns minutos.</small>');
        }
        
        // Salva XML no banco para futuras consultas
        if (!empty($xmlResponse) && strlen($xmlResponse) > 100) { // Valida tamanho mínimo
            error_log('[NFSE CONSULTA] Salvando XML da API no banco de dados...');
            $sqlUpdate = "UPDATE ".MAIN_DB_PREFIX."nfse_nacional_emitidas 
                         SET xml_nfse = '".$db->escape($xmlResponse)."'
                         WHERE id = ".(int)$nfseId;
            if (!$db->query($sqlUpdate)) {
                error_log('[NFSE CONSULTA] Aviso: Não foi possível salvar XML: ' . $db->lasterror());
            } else {
                error_log('[NFSE CONSULTA] XML salvo com sucesso no banco');
            }
        }
    }
    
    // Valida se XML foi obtido
    if (empty($xmlResponse)) {
        error_log('[NFSE CONSULTA] ERRO: XML vazio após tentativas de consulta');
        throw new Exception('Não foi possível obter dados da NFS-e.<br><small>XML não encontrado localmente e a consulta na API não retornou dados.</small>');
    }
    
    error_log('[NFSE CONSULTA] XML obtido via: ' . $origemConsulta . ' | Tamanho: ' . strlen($xmlResponse));

    // ========== FLUXO HÍBRIDO DE EVENTOS (Fase 1) ==========
    // PRIORIDADE 1: Busca eventos no banco local (rápido, sem depender da API)
    error_log('[NFSE CONSULTA] Buscando eventos no banco local...');
    $eventosLocais = buscarEventosLocais($db, $nfseId);
    
    $eventosResponse = null;
    $origemEventos = 'nenhum';
    
    // FASE 1: Usa apenas eventos locais para renderização imediata (sem delay)
    if (!empty($eventosLocais)) {
        error_log('[NFSE CONSULTA] Encontrados ' . count($eventosLocais) . ' eventos locais');
        $eventosResponse = [
            'eventos' => $eventosLocais,
            'origem' => 'local',
            'dataHoraProcessamento' => date('Y-m-d\TH:i:s'),
            'tipoAmbiente' => $ambiente
        ];
        $origemEventos = 'local';
    } else {
        error_log('[NFSE CONSULTA] Nenhum evento local - renderização sem eventos (consulta assíncrona será feita no frontend)');
        $eventosResponse = null;
        $origemEventos = 'nenhum';
    }

    // Formata XML para exibição HTML
    header('Content-Type: text/html; charset=utf-8');
    
    error_log('[NFSE CONSULTA] Gerando HTML de resposta...');
    
    // Processa XML retornado
    $infNFSe = null;
    $emit = null;
    $toma = null;
    $valores = null;
    $dps = null;
    
    if (!empty($xmlResponse)) {
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlResponse);
        
        if ($xml !== false) {
            $xml->registerXPathNamespace('nfse', 'http://www.sped.fazenda.gov.br/nfse');
            $infNFSe = $xml->xpath('//nfse:infNFSe')[0] ?? null;
            
            if ($infNFSe) {
                $infNFSe->registerXPathNamespace('nfse', 'http://www.sped.fazenda.gov.br/nfse');
                $emit = $infNFSe->xpath('nfse:emit')[0] ?? null;
                $valores = $infNFSe->xpath('nfse:valores')[0] ?? null;
                $dps = $infNFSe->xpath('nfse:DPS/nfse:infDPS')[0] ?? null;
                
                if ($dps) {
                    $dps->registerXPathNamespace('nfse', 'http://www.sped.fazenda.gov.br/nfse');
                    $toma = $dps->xpath('nfse:toma')[0] ?? null;
                }
            }
        }
    }
    
    // Status visual - verifica eventos de cancelamento
    $cStat = isset($infNFSe->cStat) ? (string)$infNFSe->cStat : '';
    $statusText = $cStat == '100' ? 'AUTORIZADA' : 'SITUAÇÃO: '.$cStat;
    $statusColor = $cStat == '100' ? '#28a745' : '#dc3545';
    
    // Verifica se há evento de cancelamento processado
    $temCancelamento = false;
    if (!empty($eventosLocais)) {
        foreach ($eventosLocais as $evt) {
            $tipoEvt = str_replace('e', '', $evt['tipoEvento']);
            if ($tipoEvt === '101101' && ($evt['statusEvento'] === 'processado' || $evt['statusConciliacao'] === 'OK')) {
                $temCancelamento = true;
                break;
            }
        }
    }
    
    // Se tem cancelamento, sobrescreve o status
    if ($temCancelamento) {
        $statusText = 'CANCELADA';
        $statusColor = '#dc3545';
    }
    
    // CSS Simples e Limpo - User Request: "nada chamativo, mais informacoes, simples, nao feio"
    echo '<style>
    .nfse-simple-container {
        font-family: Arial, sans-serif;
        font-size: 13px;
        color: #333;
        line-height: 1.5;
        background-color: #fff;
        padding: 5px;
    }
    .nfse-header {
        border-bottom: 1px solid #ccc;
        padding-bottom: 10px;
        margin-bottom: 15px;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    .nfse-title {
        font-size: 16px;
        font-weight: bold;
        color: #444;
    }
    .nfse-status {
        font-weight: bold;
        padding: 4px 8px;
        color: white;
        border-radius: 3px;
        font-size: 12px;
        text-transform: uppercase;
    }
    .nfse-section {
        margin-bottom: 15px;
        border: 1px solid #e0e0e0;
        border-radius: 2px;
    }
    .nfse-section-header {
        background-color: #f5f5f5;
        padding: 8px 10px;
        font-weight: bold;
        color: #555;
        border-bottom: 1px solid #e0e0e0;
        font-size: 12px;
        text-transform: uppercase;
    }
    .nfse-table {
        width: 100%;
        border-collapse: collapse;
    }
    .nfse-table td {
        padding: 6px 10px;
        border-bottom: 1px solid #f0f0f0;
        vertical-align: top;
    }
    .nfse-table tr:last-child td {
        border-bottom: none;
    }
    .nfse-label {
        color: #666;
        font-weight: bold;
        padding-right: 5px;
        font-size: 11px;
        text-transform: uppercase;
        display: block;
        margin-bottom: 2px;
    }
    .nfse-value {
        color: #000;
        font-size: 13px;
    }
    .nfse-row {
        display: flex;
        flex-wrap: wrap;
        margin: 0 -10px;
    }
    .nfse-col {
        flex: 1;
        min-width: 300px;
        padding: 0 10px;
    }
    .nfse-btn-bar {
        margin-top: 15px;
        text-align: right;
        padding-top: 10px;
        border-top: 1px solid #eee;
    }
    .nfse-btn {
        padding: 6px 12px;
        background-color: #f0f0f0;
        border: 1px solid #ccc;
        cursor: pointer;
        color: #333;
        font-size: 12px;
        border-radius: 3px;
        margin-left: 5px;
    }
    .nfse-btn:hover {
        background-color: #e0e0e0;
    }
    .nfse-xml-pre {
        background: #fcfcfc;
        border: 1px solid #eee;
        padding: 10px;
        font-family: "Courier New", monospace;
        font-size: 11px;
        overflow: auto;
        max-height: 200px;
        color: #555;
    }
    .nfse-btn-sync {
        padding: 6px 12px;
        background-color: #007bff;
        border: 1px solid #0056b3;
        cursor: pointer;
        color: white;
        font-size: 12px;
        border-radius: 3px;
        margin-left: 5px;
    }
    .nfse-btn-sync:hover {
        background-color: #0056b3;
    }
    .nfse-btn-sync:disabled {
        background-color: #6c757d;
        border-color: #6c757d;
        cursor: not-allowed;
        opacity: 0.6;
    }
    .nfse-sync-status {
        display: inline-block;
        margin-left: 10px;
        font-size: 11px;
        padding: 3px 8px;
        border-radius: 3px;
    }
    .nfse-sync-loading {
        background: #fff3cd;
        color: #856404;
        border: 1px solid #ffc107;
    }
    .nfse-sync-success {
        background: #d4edda;
        color: #155724;
        border: 1px solid #28a745;
    }
    .nfse-sync-error {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #dc3545;
    }
    </style>';

    // NÃO incluímos <script> aqui porque scripts inline não são executados quando carregados via innerHTML/AJAX
    // A função sincronizarEventosComSEFAZ() está definida globalmente em nfse_list.php

    echo '<div class="nfse-simple-container">';

    // DADOS DA NOTA
    echo '<div class="nfse-section">';
    echo '<div class="nfse-section-header" style="display:flex; justify-content:space-between; align-items:center;">';
    echo '<span>Dados da Nota Fiscal</span>';
    echo '<span class="nfse-status" style="background-color:'.$statusColor.'">'.$statusText.'</span>';
    echo '</div>';
    
    echo '<table class="nfse-table">';
    echo '<tr>';
    echo '<td width="25%"><span class="nfse-label">Número</span><span class="nfse-value">' . ($infNFSe->nNFSe ?? '-') . '</span></td>';
    echo '<td width="25%"><span class="nfse-label">Série</span><span class="nfse-value">' . ($dps->serie ?? '1') . '</span></td>';
    echo '<td width="25%"><span class="nfse-label">Emissão</span><span class="nfse-value">' . (($infNFSe->dhProc) ? date('d/m/Y H:i:s', strtotime((string)$infNFSe->dhProc)) : '-') . '</span></td>';
    echo '<td width="25%"><span class="nfse-label">Competência</span><span class="nfse-value">' . (($dps->dCompet) ? date('d/m/Y', strtotime((string)$dps->dCompet)) : '-') . '</span></td>';
    echo '</tr>';
    $tpAmb = isset($dps->tpAmb) ? (string)$dps->tpAmb : '';
    $ambDesc = ($tpAmb == '1') ? 'Produção' : (($tpAmb == '2') ? 'Homologação' : '-');

    echo '<tr>';
    echo '<td colspan="3"><span class="nfse-label">Chave de Acesso</span><span class="nfse-value" style="font-family:monospace">' . $chaveAcesso . '</span></td>';
    echo '<td colspan="1"><span class="nfse-label">Ambiente</span><span class="nfse-value">' . $ambDesc . '</span></td>';
    echo '</tr>';
    
    if(isset($infNFSe->xLocEmi)) {
        
        echo '<tr>';
        echo '<td colspan="2"><span class="nfse-label">Município de Emissão</span><span class="nfse-value">' . $infNFSe->xLocEmi . '</span></td>';
        // echo '<td colspan="2"><span class="nfse-label">Local de Prestação</span><span class="nfse-value">' . ($infNFSe->xLocPrestacao ?? '-') . '</span></td>';
        echo '<td colspan="2"><span class="nfse-label">Local de Prestação</span><span class="nfse-value">' . ($infNFSe->xLocPrestacao ?? '-') . '</span></td>';
        echo '</tr>';
    }
    echo '</table>';
    echo '</div>';

    // EMITENTE E TOMADOR
    echo '<div class="nfse-row">';
    
    // COLUNA EMITENTE
    echo '<div class="nfse-col">';
    echo '<div class="nfse-section">';
    echo '<div class="nfse-section-header">Prestador de Serviços</div>';
    echo '<table class="nfse-table">';
    if($emit) {
        $cnpjEmit = isset($emit->CNPJ) ? $emit->CNPJ : '';
        // Format CNPJ
        // $cnpjEmit = preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $cnpjEmit);

        echo '<tr><td><span class="nfse-label">Nome / Razão Social</span><span class="nfse-value">' . ($emit->xNome ?? '-') . '</span></td></tr>';
        echo '<tr><td><span class="nfse-label">CNPJ</span><span class="nfse-value">' . $cnpjEmit . '</span></td></tr>';
        
        // Endereço Emitente
        $endEmitStr = [];
        $cEmitente = buscarDadosIbge($db, (string)$emit->enderNac->cMun, null);
        
        if(isset($emit->enderNac->xLgr)) $endEmitStr[] = (string)$emit->enderNac->xLgr . ', ' . (string)$emit->enderNac->nro;
        if(isset($emit->enderNac->xBairro)) $endEmitStr[] = (string)$emit->enderNac->xBairro;
        if(isset($emit->enderNac->cMun)) $endEmitStr[] = (string)$cEmitente->nome . ' - ' . (string)$emit->enderNac->UF;
        if(isset($emit->enderNac->CEP)) $endEmitStr[] = 'CEP ' . (string)$emit->enderNac->CEP;
        
        echo '<tr><td><span class="nfse-label">Endereço</span><span class="nfse-value">' . implode('<br>', $endEmitStr) . '</span></td></tr>';
        echo '<tr><td><span class="nfse-label">Contato</span><span class="nfse-value">' . ($emit->email ?? '') . ' ' . ($emit->fone ?? '') . '</span></td></tr>';
    }
    echo '</table>';
    echo '</div>';
    echo '</div>';

    // COLUNA TOMADOR
    echo '<div class="nfse-col">';
    echo '<div class="nfse-section">';
    echo '<div class="nfse-section-header">Tomador de Serviços</div>';
    echo '<table class="nfse-table">';
    if($toma) {
        $docToma = isset($toma->CNPJ) ? $toma->CNPJ : (isset($toma->CPF) ? $toma->CPF : '-');
        echo '<tr><td><span class="nfse-label">Nome / Razão Social</span><span class="nfse-value">' . ($toma->xNome ?? 'Consumidor Final') . '</span></td></tr>';
        echo '<tr><td><span class="nfse-label">CNPJ / CPF</span><span class="nfse-value">' . $docToma . '</span></td></tr>';
        
        // Endereço Tomador (Estrutura variavel)
        $endTomaStr = [];
        
        if(isset($toma->end->xLgr)) $endTomaStr[] = (string)$toma->end->xLgr . ', ' . (string)$toma->end->nro;
        if(isset($toma->end->xBairro)) $endTomaStr[] = (string)$toma->end->xBairro;
        
        // Verifica estrutura do municipio no toma
        $munToma = '';
        $ufToma = '';
        $cepToma = '';
        
        $cToma = buscarDadosIbge($db, (string)$toma->end->endNac->cMun ?? $toma->end->cMun, null);
        if(isset($toma->end->endNac->cMun)) $munToma = (string)$cToma->nome . ' - ' . $cToma->uf;

        if(isset($toma->end->endNac->CEP)) $cepToma = (string)$toma->end->endNac->CEP;
        else if(isset($toma->end->CEP)) $cepToma = (string)$toma->end->CEP;
        
        if($munToma) $endTomaStr[] = $munToma;
        if($cepToma) $endTomaStr[] = 'CEP ' . $cepToma;
        
        if(!empty($endTomaStr)) echo '<tr><td><span class="nfse-label">Endereço</span><span class="nfse-value">' . implode('<br>', $endTomaStr) . '</span></td></tr>';
        echo '<tr><td><span class="nfse-label">Contato</span><span class="nfse-value">' . ($toma->email ?? '') . ' ' . ($toma->fone ?? '') . '</span></td></tr>';
        
    }
    echo '</table>';
    echo '</div>';
    echo '</div>';
    
    echo '</div>'; // end row

    // DISCRIMINAÇÃO
    echo '<div class="nfse-section">';
    echo '<div class="nfse-section-header">Discriminação dos Serviços</div>';
    echo '<div style="padding: 15px; font-size: 13px; color: #333; min-height: 60px;">';
    if(isset($dps->serv->cServ->xDescServ)) {
         echo nl2br((string)$dps->serv->cServ->xDescServ);
    } else {
        echo '<span style="color:#999; font-style:italic;">Sem descrição</span>';
    }
    echo '</div>';
    
    // TRIB NACIONAL
    if(isset($infNFSe->xTribNac)) {
        $cTribNac = isset($dps->serv->cServ->cTribNac) ? (string)$dps->serv->cServ->cTribNac : '';
        $tribNacCombined = $cTribNac ? $cTribNac . ' - ' . $infNFSe->xTribNac : $infNFSe->xTribNac;
        
        echo '<div style="background:#f9f9f9; padding: 8px 15px; border-top:1px solid #eee; font-size:12px;">';
        echo '<b>Tributação Nacional:</b> ' . $tribNacCombined;
        echo '</div>';
    }
    echo '</div>';

    // VALORES
    echo '<div class="nfse-section">';
    echo '<div class="nfse-section-header">Valores e Impostos</div>';
    echo '<table class="nfse-table">';
    
    // Linha 1: Valores base
    echo '<tr>';
    // $dps = infDPS; <valores> é filho direto de infDPS, não de serv
    $vServ = '0,00';
    $tpRetISSQN = '1';
    if ($dps) {
        $dps->registerXPathNamespace('nfse', 'http://www.sped.fazenda.gov.br/nfse');
        $vServResult = $dps->xpath('nfse:valores/nfse:vServPrest/nfse:vServ');
        if (!empty($vServResult)) {
            $vServ = number_format((float)$vServResult[0], 2, ',', '.');
        }
        $tpRetResult = $dps->xpath('nfse:valores/nfse:trib/nfse:tribMun/nfse:tpRetISSQN');
        if (!empty($tpRetResult)) {
            $tpRetISSQN = (string)$tpRetResult[0];
        } elseif (!empty($xmlResponse)) {
            // fallback regex caso o namespace esteja diferente
            if (preg_match('/<tpRetISSQN>(\d+)<\/tpRetISSQN>/', $xmlResponse, $matchRet)) {
                $tpRetISSQN = $matchRet[1];
            }
        }
    }
    $vBC = isset($valores->vBC) ? number_format((float)$valores->vBC, 2, ',', '.') : '0,00';
    $aliq = isset($valores->pAliqAplic) ? (string)$valores->pAliqAplic : '0';
    
    echo '<td width="33%"><span class="nfse-label">Valor dos Serviços</span><span class="nfse-value">R$ '.$vServ.'</span></td>';
    echo '<td width="33%"><span class="nfse-label">Base de Cálculo</span><span class="nfse-value">R$ '.$vBC.'</span></td>';
    echo '<td width="33%"><span class="nfse-label">Alíquota</span><span class="nfse-value">'.$aliq.'%</span></td>';
    echo '</tr>';
    
    // Linha 2: Impostos e Liquido
    echo '<tr>';
    $vISS = isset($valores->vISSQN) ? number_format((float)$valores->vISSQN, 2, ',', '.') : '0,00';
    $vLiq = isset($valores->vLiq) ? number_format((float)$valores->vLiq, 2, ',', '.') : '0,00';

    $issRetido = ($tpRetISSQN === '2');

    echo '<td><span class="nfse-label">ISSQN</span><span class="nfse-value">R$ '.$vISS.'</span></td>';
    echo '<td><span class="nfse-label">ISS Retido</span><span class="nfse-value">'.($issRetido ? 'Sim' : 'Não').'</span></td>';
    echo '<td style="background-color:#f0f8ff;"><span class="nfse-label" style="color:#000;">VALOR LÍQUIDO</span><span class="nfse-value" style="font-weight:bold; font-size:14px;">R$ '.$vLiq.'</span></td>';
    echo '</tr>';
    echo '</table>';
    echo '</div>';

    // EVENTOS DA NFS-E (apenas dados locais)
    if (!empty($eventosResponse) && isset($eventosResponse['eventos']) && is_array($eventosResponse['eventos']) && count($eventosResponse['eventos']) > 0) {
        echo '<div class="nfse-section">';
        echo '<div class="nfse-section-header">Eventos da NFS-e';
        
        // Indicador de origem dos dados (local vs API)
        if (isset($eventosResponse['origem'])) {
            $origemBadge = $eventosResponse['origem'] === 'local' 
                ? '<span style="background:#28a745; color:white; padding:2px 6px; border-radius:3px; font-size:10px; margin-left:8px;">BANCO LOCAL</span>'
                : '<span style="background:#007bff; color:white; padding:2px 6px; border-radius:3px; font-size:10px; margin-left:8px;">API SEFAZ</span>';
            echo $origemBadge;
        }
        
        echo '</div>';
        
        // Informações do processamento
        echo '<div style="background:#f9f9f9; padding: 8px 15px; border-bottom:1px solid #eee; font-size:11px; color:#666;">';
        
        if($eventosResponse['origem'] === 'local') {
            // Dados do banco local
            $primeiroEvento = $eventosResponse['eventos'][0];
            if(isset($primeiroEvento['ultimaSincronizacao']) && !empty($primeiroEvento['ultimaSincronizacao'])) {
                $dhSync = date('d/m/Y H:i:s', strtotime($primeiroEvento['ultimaSincronizacao']));
                echo '<b>Última Sincronização:</b> '.$dhSync.' | ';
            }
            if(isset($primeiroEvento['statusConciliacao'])) {
                // $statusDesc = [
                //     'OK' => '<span style="color:#28a745;">✓ Conciliado</span>',
                //     'DIVERGENTE' => '<span style="color:#ffc107;">⚠ Divergente</span>',
                //     'NAO_CONFIRMADO' => '<span style="color:#6c757d;">○ Não Confirmado</span>',
                //     'INEXISTENTE_NO_GOV' => '<span style="color:#dc3545;">✗ Inexistente no Governo</span>'
                // ];
                 $statusDesc = [
                    'OK' => '',
                    'DIVERGENTE' => '<span style="color:#ffc107;">⚠ Divergente</span>',
                    'NAO_CONFIRMADO' => '',
                    'INEXISTENTE_NO_GOV' => '<span style="color:#dc3545;">✗ Inexistente no gov.br</span>'
                ];
                $statusText = $statusDesc[$primeiroEvento['statusConciliacao']] ?? $primeiroEvento['statusConciliacao'];
                //echo '<b>Status:</b> '.$statusText.' | ';
                if($statusDesc['OK' !== ''] && $statusDesc['NAO_CONFIRMADO' !== '']){
                    echo '<b>Status:</b> '.$statusText.' | ';
                }
            }
            echo '<b>Fonte:</b> Banco de Dados';
        } else {
            // Dados da API
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
            echo '<b>Fonte:</b> API do Governo';
        }
        echo '</div>';
        
        // Tabela de eventos
        echo '<table class="nfse-table">';
        echo '<tr style="background:#f5f5f5; font-size:11px; text-transform:uppercase;">';
        echo '<td style="border-bottom:1px solid #ddd; padding:8px;"><b>Tipo Evento</b></td>';
        echo '<td style="border-bottom:1px solid #ddd; padding:8px;"><b>Data/Hora</b></td>';
        echo '<td style="border-bottom:1px solid #ddd; padding:8px;"><b>Status</b></td>';
        echo '</tr>';
        
        foreach ($eventosResponse['eventos'] as $evento) {
            $tipoEvento = isset($evento['tipoEvento']) ? $evento['tipoEvento'] : '-';
            
            // Protocolo/Número do pedido (compatível com ambas as fontes)
            $numPedido = isset($evento['numeroPedidoRegistroEvento']) 
                ? $evento['numeroPedidoRegistroEvento'] 
                : (isset($evento['protocolo']) ? $evento['protocolo'] : '-');
            
            // Data/hora (prioriza processamento, senão evento, senão recebimento)
            $dhEvento = '-';
            if(isset($evento['dataHoraProcessamento']) && !empty($evento['dataHoraProcessamento'])) {
                $dhEvento = date('d/m/Y H:i:s', strtotime($evento['dataHoraProcessamento']));
            } elseif(isset($evento['dataHoraEvento']) && !empty($evento['dataHoraEvento'])) {
                $dhEvento = date('d/m/Y H:i:s', strtotime($evento['dataHoraEvento']));
            } elseif(isset($evento['dataHoraRecebimento'])) {
                $dhEvento = date('d/m/Y H:i:s', strtotime($evento['dataHoraRecebimento']));
            }
            
            // Status do evento
            $statusEvento = '-';
            $statusColor = '#6c757d';
            
            if($eventosResponse['origem'] === 'local') {
                // Dados locais: usa campo status_evento
                if(isset($evento['statusEvento'])) {
                    switch($evento['statusEvento']) {
                        case 'processado':
                            $statusEvento = '✓ Processado';
                            $statusColor = '#28a745';
                            break;
                        case 'erro':
                            $statusEvento = '✗ Erro';
                            $statusColor = '#dc3545';
                            break;
                        case 'pendente':
                            $statusEvento = '○ Pendente';
                            $statusColor = '#ffc107';
                            break;
                        default:
                            $statusEvento = $evento['statusEvento'];
                    }
                }
            } else {
                // Dados da API: verifica presença de XML
                $hasXml = isset($evento['arquivoXml']) && !empty($evento['arquivoXml']);
                $statusEvento = $hasXml ? '✓ Registrado' : 'Pendente';
                $statusColor = $hasXml ? '#28a745' : '#6c757d';
            }
            
            // Descrição do tipo de evento
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
            echo '<td><span class="nfse-value">'.$dhEvento.'</span></td>';
            echo '<td><span class="nfse-value" style="color:'.$statusColor.';">'.$statusEvento.'</span></td>';
            echo '</tr>';
            
            // Linha adicional com motivo (se existir)
            if(isset($evento['descricaoMotivo']) && !empty($evento['descricaoMotivo'])) {
                echo '<tr style="background:#fafafa;">';
                echo '<td colspan="3" style="padding:6px 10px 6px 20px; font-size:11px; color:#666;">';
                echo '<b>Motivo:</b> '.htmlspecialchars($evento['descricaoMotivo']);
                echo '</td>';
                echo '</tr>';
            }
        }
        
        echo '</table>';
        echo '</div>';
    }

    // Container para exibir eventos buscados da API (será preenchido via JS)
    echo '<div id="nfseEventosBuscados" style="display:none;"></div>';

    echo '<div class="nfse-btn-bar">';
    
    // Botão de sincronização (Fluxo 2 - Fase 2)
    if (!empty($eventosResponse) && isset($eventosResponse['eventos']) && count($eventosResponse['eventos']) > 0) {
        // Passa o ID da NFS-e para a função global definida em nfse_list.php
        echo '<button id="btnSincronizar" class="nfse-btn-sync" onclick="sincronizarEventosComSEFAZ('.(int)$nfseId.')" title="Sincronizar eventos com a API do governo para validar consistência">';
        echo '🔄 Sincronizar eventos com gov.br';
        echo '</button>';
        echo '<span id="syncStatus" class="nfse-sync-status" style="display:none;"></span>';
    }

    // Botão de buscar eventos na API SEFAZ (só aparece se NÃO houver eventos locais)
    if (empty($eventosResponse) || !isset($eventosResponse['eventos']) || count($eventosResponse['eventos']) === 0) {
        //echo '<button id="btnBuscarEventos" class="nfse-btn-sync" onclick="buscarEventosSEFAZ('.(int)$nfseId.')"style="background-color:#17a2b8; border-color:#138496;">';
        //echo '🔍 Buscar Eventos';
        //echo '</button>';
        //echo '<span id="buscarEventosStatus" class="nfse-sync-status" style="display:none;"></span>';
    }
    
    echo '<button class="nfse-btn" onclick="closeNfseModal()">Fechar</button>';
    echo '</div>';

    echo '</div>'; // Container
    
} catch (Exception $e) {
    error_log('[NFSE CONSULTA] ERRO CATCH: ' . $e->getMessage());
    error_log('[NFSE CONSULTA] Stack trace: ' . $e->getTraceAsString());
    
    // Limpa buffer se houver
    $bufferContent = ob_get_clean();
    if (!empty($bufferContent)) {
        error_log('[NFSE CONSULTA] Buffer não vazio antes do erro: ' . strlen($bufferContent) . ' bytes');
    }
    
    // CSS para alert (caso não tenha sido carregado)
    echo '<style>
    .nfse-alert { padding: 15px; margin: 10px 0; border-radius: 4px; }
    .nfse-alert-error { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
    .nfse-alert strong { display: block; margin-bottom: 8px; }
    .nfse-alert small { display: block; margin-top: 8px; opacity: 0.8; }
    </style>';
    
    echo '<div class="nfse-alert nfse-alert-error">';
    echo '<strong>⚠ Erro na consulta da NFS-e</strong>';
    echo $e->getMessage(); // Já inclui HTML formatting
    echo '<br><br><small>ID da nota: ' . (int)$nfseId . '</small>';
    echo '<br><small>Verifique os logs do servidor para mais detalhes.</small>';
    echo '</div>';
}

error_log('[NFSE CONSULTA] Finalizando script');

// Envia o buffer (só se ainda estiver ativo)
if (ob_get_level() > 0) {
    $output = ob_get_clean();
    error_log('[NFSE CONSULTA] Tamanho do output: ' . strlen($output));
    echo $output;
}

// Garante que a saída seja enviada
if (function_exists('fastcgi_finish_request')) {
    fastcgi_finish_request();
}