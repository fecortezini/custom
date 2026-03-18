<?php
/**
 * Determina o CSOSN (Código de Situação da Operação no Simples Nacional)
 * Sistema automatizado baseado em regras fiscais e características do produto
 * 
 * @param object $db Conexão com banco de dados
 * @param array $product Dados do produto
 * @param array $emitter Dados da empresa emitente
 * @param array $recipient Dados do destinatário
 * @param string $cfop CFOP da operação
 * @return array ['csosn' => string, 'error' => string|null, 'dados_complementares' => array, 'warnings' => array]
 */
function determinarCSOSN($db, $product, $emitter, $recipient, $cfop) {
    $result = [
        'csosn' => null,
        'error' => null,
        'dados_complementares' => [],
        'warnings' => [],
        'debug_info' => []
    ];
    
    // ===== VALIDAÇÕES INICIAIS =====
    $crt_emitter = $emitter['extrafields']['crt'] ?? $emitter['crt'] ?? null;
    
    if ($crt_emitter != 1 && $crt_emitter != 2) {
        $result['error'] = 'CSOSN só se aplica ao Simples Nacional (CRT 1 ou 2). CRT informado: ' . $crt_emitter;
        return $result;
    }
    
    // ===== EXTRAÇÃO DE DADOS =====
    $regime_icms = $product['extrafields']['prd_regime_icms'] ?? null;
    $uf_origin = strtoupper(trim($emitter['uf'] ?? ''));
    $uf_dest = strtoupper(trim($recipient['uf'] ?? ''));
    $indiedest = $recipient['extrafields']['indiedest'] ?? null;
    $ncm = preg_replace('/[^0-9]/', '', $product['extrafields']['prd_ncm'] ?? $product['extrafields']['NCM'] ?? '');
    
    // Validação de dados obrigatórios
    if (empty($regime_icms)) {
        $result['error'] = 'Regime ICMS do produto não informado (prd_regime_icms)';
        return $result;
    }
    
    if (empty($uf_origin) || empty($uf_dest)) {
        $result['error'] = 'UF de origem ou destino não informada';
        return $result;
    }
    
    // Determina tipo de operação
    $is_operacao_interna = ($uf_origin === $uf_dest);
    $is_operacao_saida = in_array(substr($cfop, 0, 1), ['5', '6', '7']);
    
    // Adiciona info de debug
    $result['debug_info'] = [
        'regime_icms' => $regime_icms,
        'uf_origin' => $uf_origin,
        'uf_dest' => $uf_dest,
        'is_operacao_interna' => $is_operacao_interna,
        'is_operacao_saida' => $is_operacao_saida,
        'cfop' => $cfop,
        'crt' => $crt_emitter
    ];
    
    // ===== BUSCA REGRA FISCAL =====
    $taxRule = getTaxRuleOptimized($db, $product, $emitter, $recipient, $cfop);
    
    // registre metadados de regra aplicada para auditoria/UI
    if ($taxRule) {
        registerAppliedTaxRule($result, $taxRule);
        $result['dados_complementares'] = (array) $taxRule;
    }

    // novo: verifica extrafields de override de crédito
    $creditOverride = obterOverrideCreditoExtrafield($product, $emitter);
    if ($creditOverride === true) $result['debug_info']['credit_override'] = 'product/emitter=ALLOW';
    if ($creditOverride === false) $result['debug_info']['credit_override'] = 'product/emitter=DENY';
    
    // ===== DETERMINAÇÃO DO CSOSN BASEADO NO REGIME ICMS =====
    
    switch ($regime_icms) {
        case '2': // SUBSTITUTO TRIBUTÁRIO (empresa cobra o ST do cliente)
            $result['csosn'] = determinarCSOSN_Substituto($taxRule, $is_operacao_interna, $creditOverride, $result);
            break;
            
        case '3': // SUBSTITUÍDO TRIBUTÁRIO (ST já recolhido anteriormente)
            $result['csosn'] = determinarCSOSN_Substituido($taxRule, $result);
            break;
            
        case '4': // ISENTO DE ICMS
            $result['csosn'] = determinarCSOSN_Isento($taxRule, $result);
            break;
            
        case '5': // NÃO TRIBUTADO PELO ICMS
            $result['csosn'] = determinarCSOSN_NaoTributado($taxRule, $result);
            break;
            
        case '6': // SUSPENSÃO DO ICMS
            $result['csosn'] = determinarCSOSN_Suspenso($taxRule, $result);
            break;
            
        case '1': // TRIBUTADO NORMALMENTE
        default:
            $result['csosn'] = determinarCSOSN_Tributado($taxRule, $is_operacao_saida, $creditOverride, $result);
            break;
    }
    
    // ===== VALIDAÇÕES FINAIS =====
    if (empty($result['csosn'])) {
        $result['error'] = 'Não foi possível determinar o CSOSN. Regime ICMS: ' . $regime_icms;
    }
    
    // Validação de consistência da regra fiscal com o CSOSN determinado
    if ($taxRule) {
        validarConsistenciaRegraFiscal($result['csosn'], $taxRule, $result);
    } else {
        $result['warnings'][] = 'ATENÇÃO: Nenhuma regra fiscal cadastrada para CFOP ' . $cfop . 
                                ', NCM ' . $ncm . ', ' . $uf_origin . ' -> ' . $uf_dest;
    }
    
    return $result;
}

/**
 * Determina CSOSN para produtos com regime Substituto Tributário
 */
function determinarCSOSN_Substituto($taxRule, $is_operacao_interna, $creditOverride = null, &$result) {
    // se override definido, força resultado
    if ($creditOverride === true) return '201';
    if ($creditOverride === false) return '202';
    
    // comportamento anterior
    if (!$taxRule) {
        $result['warnings'][] = 'CRÍTICO: Regime ST sem regra fiscal. Impossível calcular MVA e alíquotas de ST.';
        return '202'; // Padrão mais seguro (sem crédito)
    }
    
    // Valida se tem configuração de ST
    $tem_st_configurado = ($taxRule->icms_st_aliq > 0 && $taxRule->icms_st_mva > 0);
    
    if (!$tem_st_configurado) {
        $result['warnings'][] = 'ERRO: Produto configurado como Substituto ST mas regra fiscal não possui MVA ou alíquota ST.';
    }
    
    // Verifica se tem crédito de ICMS
    $tem_credito = ($taxRule->icms_cred_aliq > 0);
    
    return $tem_credito ? '201' : '202';
}

/**
 * Determina CSOSN para produtos Substituídos (ST já recolhido)
 */
function determinarCSOSN_Substituido($taxRule, &$result) {
    // CSOSN 500 - ICMS cobrado anteriormente por substituição tributária
    
    if ($taxRule && ($taxRule->icms_st_aliq > 0 || $taxRule->icms_st_mva > 0)) {
        $result['warnings'][] = 'ATENÇÃO: Produto substituído (CSOSN 500) não deve ter MVA/alíquota ST na regra fiscal.';
    }
    
    return '500';
}

/**
 * Determina CSOSN para produtos Isentos
 */
function determinarCSOSN_Isento($taxRule, &$result) {
    // CSOSN 103 - Isenção do ICMS (mais comum no Simples Nacional)
    // CSOSN 300 - Imune (produtos específicos: livros, jornais, etc)
    // CSOSN 400 - Não tributada
    
    // Por padrão retorna 103 (isenção padrão do Simples Nacional)
    return '103';
}

/**
 * Determina CSOSN para produtos Não Tributados
 */
function determinarCSOSN_NaoTributado($taxRule, &$result) {
    // CSOSN 400 - Não tributada pelo Simples Nacional
    // CSOSN 300 - Imune (casos específicos)
    
    return '400';
}

/**
 * Determina CSOSN para produtos com Suspensão
 */
function determinarCSOSN_Suspenso($taxRule, &$result) {
    // CSOSN 400 - Não tributada (suspensão tratada como não tributação)
    
    return '400';
}

/**
 * Determina CSOSN para produtos Tributados Normalmente
 */
function determinarCSOSN_Tributado($taxRule, $is_operacao_saida, $creditOverride = null, &$result) {
    // override explicit
    if ($creditOverride === true && $is_operacao_saida) return '101';
    if ($creditOverride === false && $is_operacao_saida) return '102';
    
    // comportamento anterior
    if (!$is_operacao_saida) {
        // Entrada normalmente não calcula crédito
        return '102';
    }
    
    if (!$taxRule) {
        $result['warnings'][] = 'Produto tributado sem regra fiscal. Usando CSOSN 102 (sem crédito).';
        return '102';
    }
    
    // A presença de alíquota de crédito determina CSOSN 101 vs 102
    $tem_credito = ($taxRule->icms_cred_aliq > 0);
    
    return $tem_credito ? '101' : '102';
}

/**
 * Valida consistência entre CSOSN e regra fiscal
 */
function validarConsistenciaRegraFiscal($csosn, $taxRule, &$result) {
    switch ($csosn) {
        case '101':
            if ($taxRule->icms_cred_aliq <= 0) {
                $result['warnings'][] = 'Inconsistência: CSOSN 101 requer alíquota de crédito na regra fiscal.';
            }
            break;
            
        case '201':
            if ($taxRule->icms_cred_aliq <= 0) {
                $result['warnings'][] = 'Inconsistência: CSOSN 201 requer alíquota de crédito na regra fiscal.';
            }
            if ($taxRule->icms_st_mva <= 0 || $taxRule->icms_st_aliq <= 0) {
                $result['warnings'][] = 'ERRO: CSOSN 201 requer MVA e alíquota ST configuradas.';
            }
            break;
            
        case '202':
        case '203':
            if ($taxRule->icms_st_mva <= 0 || $taxRule->icms_st_aliq <= 0) {
                $result['warnings'][] = 'ERRO: CSOSN ' . $csosn . ' requer MVA e alíquota ST configuradas.';
            }
            break;
            
        case '500':
            if ($taxRule->icms_st_mva > 0 || $taxRule->icms_st_aliq > 0) {
                $result['warnings'][] = 'ATENÇÃO: CSOSN 500 (substituído) não deve ter ST na regra fiscal.';
            }
            break;
    }
}

/**
 * Busca regra fiscal otimizada com cache e fallback
 * ESTRATÉGIA: Busca específica -> Busca genérica por CFOP -> Busca por UFs
 */
function getTaxRuleOptimized($db, $product, $emitter, $recipient, $cfop) {
    static $cache = [];
    
    $uf_origin = strtoupper(trim($emitter['uf'] ?? ''));
    $uf_dest = strtoupper(trim($recipient['uf'] ?? ''));
    $crt_emitter = $emitter['extrafields']['crt'] ?? $emitter['crt'] ?? null;
    $ncm = preg_replace('/[^0-9]/', '', $product['extrafields']['prd_ncm'] ?? $product['extrafields']['NCM'] ?? '');
    
    // Cache key
    $cache_key = "{$uf_origin}_{$uf_dest}_{$cfop}_{$ncm}_{$crt_emitter}";
    
    if (isset($cache[$cache_key])) {
        return $cache[$cache_key];
    }
    
    // 1. BUSCA MAIS ESPECÍFICA: UF + CFOP + NCM + CRT
    $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "custom_tax_rules
            WHERE active = 1
              AND uf_origin = '" . $db->escape($uf_origin) . "'
              AND uf_dest = '" . $db->escape($uf_dest) . "'
              AND cfop = '" . $db->escape($cfop) . "'
              AND ncm = '" . $db->escape($ncm) . "'
              AND crt_emitter = " . intval($crt_emitter) . "
            LIMIT 1";
    
    $result = $db->query($sql);
    if ($result && $db->num_rows($result) > 0) {
        $obj = $db->fetch_object($result);
        $obj->match_level = 'specific'; // 'ncm+cfop+crt', 'ncm+cfop', 'cfop+crt', 'cfop'
        $cache[$cache_key] = $obj;
        return $cache[$cache_key];
    }
    
    // 2. BUSCA POR CFOP + NCM (sem CRT específico)
    $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "custom_tax_rules
            WHERE active = 1
              AND uf_origin = '" . $db->escape($uf_origin) . "'
              AND uf_dest = '" . $db->escape($uf_dest) . "'
              AND cfop = '" . $db->escape($cfop) . "'
              AND ncm = '" . $db->escape($ncm) . "'
            LIMIT 1";
    
    $result = $db->query($sql);
    if ($result && $db->num_rows($result) > 0) {
        $obj = $db->fetch_object($result);
        $obj->match_level = 'ncm+cfop'; // 'ncm+cfop', 'cfop'
        $cache[$cache_key] = $obj;
        return $cache[$cache_key];
    }
    
    // 3. BUSCA GENÉRICA POR CFOP (sem NCM específico)
    $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "custom_tax_rules
            WHERE active = 1
              AND uf_origin = '" . $db->escape($uf_origin) . "'
              AND uf_dest = '" . $db->escape($uf_dest) . "'
              AND cfop = '" . $db->escape($cfop) . "'
              AND (ncm IS NULL OR ncm = '')
              AND crt_emitter = " . intval($crt_emitter) . "
            LIMIT 1";
    
    $result = $db->query($sql);
    if ($result && $db->num_rows($result) > 0) {
        $obj = $db->fetch_object($result);
        $obj->match_level = 'cfop+crt'; // 'cfop+crt', 'cfop'
        $cache[$cache_key] = $obj;
        return $cache[$cache_key];
    }
    
    // 4. ÚLTIMA TENTATIVA: Só UFs + CFOP
    $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "custom_tax_rules
            WHERE active = 1
              AND uf_origin = '" . $db->escape($uf_origin) . "'
              AND uf_dest = '" . $db->escape($uf_dest) . "'
              AND cfop = '" . $db->escape($cfop) . "'
              AND (ncm IS NULL OR ncm = '')
            LIMIT 1";
    
    $result = $db->query($sql);
    if ($result && $db->num_rows($result) > 0) {
        $obj = $db->fetch_object($result);
        $obj->match_level = 'cfop'; // 'cfop'
        $cache[$cache_key] = $obj;
        return $cache[$cache_key];
    }
    
    $cache[$cache_key] = null;
    return null;
}

/**
 * Função auxiliar para validar e enriquecer dados fiscais do produto
 * Adiciona alíquotas de crédito baseado em faixas de receita (opcional)
 * 
 * @param array $product Dados do produto
 * @param float $receita_bruta_12meses Receita bruta dos últimos 12 meses
 * @return array Alíquotas de crédito por faixa
 */
function calcularAliquotaCreditoSimplesNacional($product, $receita_bruta_12meses) {
    // Tabela do Anexo I do Simples Nacional (Comércio)
    // Você deve adaptar conforme o anexo da sua empresa
    
    $faixas = [
        ['limite' => 180000.00, 'aliq_icms' => 1.25],
        ['limite' => 360000.00, 'aliq_icms' => 1.86],
        ['limite' => 720000.00, 'aliq_icms' => 2.33],
        ['limite' => 1800000.00, 'aliq_icms' => 2.56],
        ['limite' => 3600000.00, 'aliq_icms' => 2.58],
        ['limite' => 4800000.00, 'aliq_icms' => 2.82],
    ];
    
    foreach ($faixas as $faixa) {
        if ($receita_bruta_12meses <= $faixa['limite']) {
            return $faixa['aliq_icms'];
        }
    }
    
    return 0; // Acima do limite do Simples Nacional
}

/**
 * Exemplo de uso da função
 */
function exemploUso($db) {
    // Simula dados
    $product = [
        'extrafields' => [
            'prd_regime_icms' => '1', // Tributado normalmente
            'prd_ncm' => '12345678'
        ]
    ];
    
    $emitter = [
        'extrafields' => ['crt' => '1'], // Simples Nacional
        'uf' => 'SP'
    ];
    
    $recipient = [
        'extrafields' => ['indiedest' => '1'], // Contribuinte
        'uf' => 'RJ'
    ];
    
    $cfop = '5102';
    
    $resultado = determinarCSOSN($db, $product, $emitter, $recipient, $cfop);
    
    if ($resultado['error']) {
        echo "ERRO: " . $resultado['error'];
    } else {
        echo "CSOSN determinado: " . $resultado['csosn'];
    }
    
    return $resultado;
}

/**
 * helper: retorna true/false/null (null = sem override)
 */
function obterOverrideCreditoExtrafield($product, $emitter) {
    // product-level override: prd_permite_credito => '1' | '0'
    $valP = $product['extrafields']['prd_permite_credito'] ?? null;
    if ($valP !== null) {
        if ((string)$valP === '1' || (int)$valP === 1) return true;
        if ((string)$valP === '0' || (int)$valP === 0) return false;
    }
    // emitter-level override: permite_credito => '1' | '0'
    $valE = $emitter['extrafields']['permite_credito'] ?? null;
    if ($valE !== null) {
        if ((string)$valE === '1' || (int)$valE === 1) return true;
        if ((string)$valE === '0' || (int)$valE === 0) return false;
    }
    return null;
}

/**
 * Papel exato das regras fiscais (llx_custom_tax_rules) neste fluxo:
 *
 * 1) O que elas armazenam (dados técnicos):
 *    - icms_st_mva, icms_st_aliq  -> definem existência e parâmetros da Substituição Tributária (ST)
 *    - icms_cred_aliq             -> indica se há direito a crédito (usado para 101/201)
 *    - icms_interestadual_aliq, aliq_interna_dest, etc. -> alíquotas usadas em cálculos
 *    - cfop, ncm, uf_origin, uf_dest, crt_emitter -> chaves para selecionar a regra correta
 *
 * 2) Papel no fluxo (não determinam o CSOSN por si só):
 *    - Fornecer os valores técnicos necessários para calcular impostos e validar regras.
 *    - Indicar se há direito a crédito (impactando 101 vs 102 / 201 vs 202).
 *    - Indicar configuração de ST (impactando 201/202/203 ou gerando warnings).
 *    - Servir como fonte para cálculos de base/valor de ST (MVA, aliquotas).
 *    - Permitir validações de consistência (ex.: CSOSN 500 não deve ter ST configurado).
 *
 * 3) Como o sistema usa essas regras:
 *    - Busca em cascata (específica -> genérica) via getTaxRuleOptimized.
 *    - Usa os campos retornados para decidir crédito e presença de ST na lógica central.
 *    - Gera warnings se a regra estiver incompleta/inconsistente.
 *    - Mantém as decisões de CSOSN centralizadas no código (prd_regime_icms + lógica),
 *      em vez de permitir que a regra no DB sobrescreva a decisão do sistema.
 *
 * 4) Recomendações para produção:
 *    - Cadastre regras específicas para NCM+CFOP+UF quando necessário e regras genéricas por CFOP.
 *    - Assegure que icms_cred_aliq, icms_st_mva e icms_st_aliq estejam corretos e testados.
 *    - Use 'active' e versionamento/histórico das regras para auditoria.
 *    - Logue qual regra foi aplicada em cada NF-e para rastreio em caso de auditoria/contábil.
 */
function registerAppliedTaxRule(&$result, $taxRule) {
    $result['dados_complementares']['applied_rule_rowid'] = $taxRule->rowid ?? null;
    $result['dados_complementares']['applied_rule_match_level'] = $taxRule->match_level ?? null;
    $result['dados_complementares']['applied_rule_label'] = $taxRule->label ?? null;
    $result['debug_info']['applied_rule_source'] = 'custom_tax_rules';
    $result['debug_info']['applied_rule_timestamp'] = date('c');
}
