<?php
/**
 * Biblioteca Avançada de Construção de CFOP
 * 
 * Abordagem "Assembly": monta CFOP por Prefixo (Direção) + Sufixo (Natureza).
 * Inclui validação, logging e rastreamento para auditoria fiscal.
 */

if (!defined('CFOP_BUILDER_LOADED')) {
    define('CFOP_BUILDER_LOADED', true);
}

// Carrega cfop_utils para validação e helpers
if (!function_exists('validarCFOP')) {
    require_once __DIR__ . '/cfop_utils.php';
}

/**
 * Constrói o CFOP por montagem (Prefixo + Sufixo) com validação completa.
 * 
 * @param array $product Dados do produto
 * @param array $emitter Dados do emitente
 * @param array $recipient Dados do destinatário
 * @param array $operation Dados da operação (opcional)
 * @param array|null &$trace Referência para array de rastreamento (opcional)
 * @return string CFOP de 4 dígitos
 * @throws Exception Se dados obrigatórios ausentes ou CFOP inválido
 */
function montarCFOP($product, $emitter, $recipient, $operation = [], &$trace = null) {
    
    // Helper local para trace
    $addTrace = function($step, $value = '—') use (&$trace) {
        if (is_array($trace)) {
            $trace[] = ['step' => $step, 'value' => $value];
        }
    };
    
    $addTrace('Início da montagem de CFOP', '—');
    
    // ========== VALIDAÇÃO OBRIGATÓRIA ==========
    if (empty($emitter['uf']) || empty($recipient['uf'])) {
        $erro = 'UF de origem e destino são obrigatórias para determinar CFOP';
        nfeLog('error', $erro, [
            'emitter_uf' => $emitter['uf'] ?? 'AUSENTE',
            'recipient_uf' => $recipient['uf'] ?? 'AUSENTE'
        ]);
        throw new Exception($erro);
    }
    
    // 1. Normalização de Variáveis de Decisão
    try {
        $contexto = _extrairContextoFiscal($product, $emitter, $recipient, $operation);
        $addTrace('Contexto fiscal extraído', json_encode($contexto, JSON_UNESCAPED_UNICODE));
    } catch (Exception $e) {
        nfeLog('error', 'Falha ao extrair contexto fiscal: ' . $e->getMessage());
        throw $e;
    }
    
    // 2. Determinação do Prefixo (1º dígito)
    $prefixo = _determinarPrefixo($contexto);
    $addTrace("Prefixo determinado: {$prefixo} (Direção)", $prefixo);
    
    // 3. Determinação do Sufixo (últimos 3 dígitos)
    $sufixo = _determinarSufixo($contexto);
    $addTrace("Sufixo determinado: {$sufixo} (Natureza)", $sufixo);
    
    // 4. Montagem Final
    $cfop = $prefixo . $sufixo;
    $addTrace("CFOP montado: {$cfop}", $cfop);
    
    // ========== VALIDAÇÃO FINAL ==========
    if (!validarCFOP($cfop)) {
        $erro = "CFOP inválido gerado: {$cfop}";
        nfeLog('error', $erro, [
            'cfop' => $cfop,
            'produto' => $product['ref'] ?? 'N/A',
            'contexto' => $contexto
        ]);
        throw new Exception($erro);
    }
    
    // Verifica se CFOP está na lista oficial (warning se não estiver)
    $infoCfop = obterInfoCFOP($cfop);
    if (strpos($infoCfop['descricao'], '⚠️') !== false) {
        nfeLog('warning', "CFOP {$cfop} não consta na lista oficial do ES", [
            'cfop' => $cfop,
            'descricao' => $infoCfop['descricao'],
            'produto' => $product['ref'] ?? 'N/A'
        ]);
    }
    
    // Log final de sucesso
    nfeLog('info', "CFOP montado com sucesso: {$cfop}", [
        'cfop' => $cfop,
        'produto' => $product['ref'] ?? 'N/A',
        'prefixo' => $prefixo,
        'sufixo' => $sufixo,
        'tipo_operacao' => $contexto['tipo_operacao']
    ]);
    
    $addTrace("Validação OK: CFOP {$cfop} aprovado", $cfop);
    
    return $cfop;
}

/**
 * Extrai e normaliza os dados para tomada de decisão
 * 
 * @throws Exception Se campos críticos ausentes
 */
function _extrairContextoFiscal($product, $emitter, $recipient, $operation) {
    $ufOrigem = strtoupper(trim($emitter['uf'] ?? ''));
    $ufDestino = strtoupper(trim($recipient['uf'] ?? ''));
    $paisDestino = strtoupper(trim($recipient['pais'] ?? 'BRASIL'));
    
    // Validação de UFs
    if (empty($ufOrigem) || empty($ufDestino)) {
        throw new Exception('UF origem e destino obrigatórias');
    }
    
    // ========== NORMALIZAÇÃO: ESQUEMA 1-BASED (Dolibarr não aceita 0) ==========
    // prd_fornecimento: 1 = Produção Própria, 2 = Adquirido/Revenda
    // prd_origem: 1 = '0 Nacional', 2 = '1 Estrangeira', ..., 9 = '8 Nacional >70%'
    
    $rawFornecimento = $product['extrafields']['prd_fornecimento'] 
                    ?? $product['extrafields']['prd_nat_fornecimento'] 
                    ?? $product['extrafields']['options_prd_nat_fornecimento'] 
                    ?? null;
    
    $rawOrigem = $product['extrafields']['prd_origem'] 
              ?? $product['extrafields']['options_prd_origem'] 
              ?? null;
    
    // Converte prd_fornecimento (1-based) para lógica interna (0-based)
    // 1 -> 0 (Produção Própria), 2 -> 1 (Adquirido), outros -> 1
    if ($rawFornecimento !== null && $rawFornecimento !== '') {
        $fornecimentoInt = (int)$rawFornecimento;
        $tipoProduto = ($fornecimentoInt === 1) ? '0' : '1';
    } else {
        // CORREÇÃO: Não inferir "produção própria" a partir da origem.
        // Fallback CONSERVADOR: assumir Adquirido/Revenda (sufixo 202) e logar para correção do produto.
        $tipoProduto = '1';
        nfeLog('warning', 'prd_fornecimento ausente — fallback conservador aplicado: Adquirido/Revenda', [
            'produto' => $product['ref'] ?? 'N/A',
            'prd_origem_raw' => $rawOrigem ?? 'AUSENTE',
            'acao_necessaria' => 'Preencha prd_fornecimento no produto para comportamento correto'
        ]);
    }
    
    // Converte prd_origem (1-based) para código SEFAZ (0-based)
    $origemMercadoria = '1'; // fallback
    if ($rawOrigem !== null && $rawOrigem !== '') {
        $origemInt = (int)$rawOrigem;
        // 1->0, 2->1, 3->2, ..., 9->8
        $origemMercadoria = (string)(($origemInt >= 1) ? ($origemInt - 1) : 0);
        // Clamp entre 0 e 8
        $origemMercadoria = (string)min(8, max(0, (int)$origemMercadoria));
    }
    
    return [
        // Geografia
        'eh_interna' => ($ufOrigem === $ufDestino),
        'eh_exportacao' => ($paisDestino !== 'BRASIL' && $paisDestino !== ''),
        'eh_interestadual' => ($ufOrigem !== $ufDestino && $paisDestino === 'BRASIL'),
        
        // Produto (já normalizados para 0-based)
        'origem_mercadoria' => $origemMercadoria,
        'tipo_produto' => $tipoProduto, // '0' = Própria, '1' = Terceiros
        'regime_icms' => trim($product['extrafields']['prd_regime_icms'] ?? '1'),
        
        // Destinatário
        'indiedest' => trim($recipient['extrafields']['indiedest'] ?? '9'),
        'eh_contribuinte' => (trim($recipient['extrafields']['indiedest'] ?? '9') == '1'),
        'eh_nao_contribuinte' => (trim($recipient['extrafields']['indiedest'] ?? '9') == '9'),
        
        // Operação
        'tipo_operacao' => strtolower(trim($operation['tipo'] ?? 'venda')),
        
        // Dados brutos para log
        'uf_origem' => $ufOrigem,
        'uf_destino' => $ufDestino,
        'pais_destino' => $paisDestino
    ];
}

/**
 * Determina o 1º dígito (Prefixo/Direção)
 */
function _determinarPrefixo($ctx) {
    if ($ctx['eh_exportacao']) {
        return '7'; // Saída para exterior
    }

    if ($ctx['tipo_operacao'] === 'devolucao') {
        return $ctx['eh_interna'] ? '1' : '2'; // Entrada (devolução)
    }

    // Saída padrão (venda, bonificação, etc)
    return $ctx['eh_interna'] ? '5' : '6';
}

/**
 * Determina os 3 últimos dígitos (Sufixo/Natureza)
 */
function _determinarSufixo($ctx) {
    $op = $ctx['tipo_operacao'];
    $regime = $ctx['regime_icms'];
    $ehPropria = ($ctx['tipo_produto'] == '0');
    
    // --- CASOS ESPECIAIS DE OPERAÇÃO ---
    if ($op === 'bonificacao') return '910';
    if ($op === 'demonstracao') return '912';
    
    // --- DEVOLUÇÃO ---
    if ($op === 'devolucao') {
        if ($regime == '2') return '410'; // ST Substituto
        if ($regime == '3') return '411'; // ST Substituído
        return $ehPropria ? '201' : '202'; // Devolução normal
    }

    // --- EXPORTAÇÃO ---
    if ($ctx['eh_exportacao']) {
        return $ehPropria ? '101' : '102';
    }

    // --- VENDA (Lógica Principal) ---
    
    // 1. DIFAL (Interestadual + Não Contribuinte)
    if ($ctx['eh_interestadual'] && $ctx['eh_nao_contribuinte']) {
        return '108';
    }

    // 2. Substituição Tributária
    if ($regime == '2') { // Substituto
        if ($ctx['eh_nao_contribuinte']) {
            return $ehPropria ? '101' : '102'; // Sem ST para NC
        }
        return $ehPropria ? '401' : '403'; // Com ST
    }

    if ($regime == '3') { // Substituído
        return '405'; // ST retido anteriormente
    }

    // 3. Venda a Não Contribuinte (Interna)
    if ($ctx['eh_interna'] && $ctx['eh_nao_contribuinte']) {
        return $ehPropria ? '103' : '104';
    }

    // 4. Venda Normal (Tributada, Isenta, etc)
    return $ehPropria ? '101' : '102';
}

// Helper de log (compatível com cfop_utils)
if (!function_exists('nfeLog')) {
    function nfeLog($level, $msg, $ctx = []) {
        if ($ctx) $msg .= ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        error_log('[NFe][CFOP_BUILDER][' . strtoupper($level) . '] ' . $msg);
    }
}
?>
