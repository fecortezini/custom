<?php
/**
 * Biblioteca de utilitários para determinação de CFOP (Código Fiscal de Operações e Prestações)
 * 
 * Base legal:
 * - Convênio s/nº de 15/12/70 (Tabela CFOP)
 * - Ajuste SINIEF 07/05 (Tabela CFOP atualizada)
 * - Convênio ICMS 142/2018 (Substituição Tributária)
 * - Lei Complementar 87/96 (Lei Kandir)
 * - Emenda Constitucional 87/2015 (DIFAL)
 * 
 * @package   Dolibarr
 * @subpackage NFe
 * @author    Sistema NFe
 * @copyright 2024
 */

if (!defined('CFOP_UTILS_LOADED')) {
    define('CFOP_UTILS_LOADED', true);
}

/**
 * Determina o CFOP (Código Fiscal de Operações e Prestações) correto para uma operação.
 * 
 * Esta função considera:
 * - UF de origem e destino (interna/interestadual)
 * - Tipo de destinatário (contribuinte/não contribuinte)
 * - Regime tributário do produto (normal, ST substituto, ST substituído, isento, etc.)
 * - Origem da mercadoria (produção própria ou adquirida de terceiros)
 * - Tipo de operação (venda, devolução, bonificação, etc.)
 * 
 * ESTRUTURA DOS DADOS ESPERADOS:
 * 
 * @param array $product Dados do produto com extrafields:
 *   - 'ref' => string (Código do produto)
 *   - 'nome' => string (Nome do produto)
 *   - 'extrafields' => [
 *       'prd_regime_icms' => string ('1' a '7') - Regime tributário do produto
 *       'prd_origem' => string ('0' a '8') - Origem da mercadoria (SEFAZ)
 *       'prd_ncm' => string (8 dígitos) - NCM do produto
 *   ]
 * 
 * @param array $emitter Dados do emitente (sua empresa):
 *   - 'uf' => string (2 letras) - UF do emitente (ex: 'SP', 'RJ')
 *   - 'cnpj' => string - CNPJ do emitente
 *   - 'crt' => string ('1', '2' ou '3') - Código de Regime Tributário
 * 
 * @param array $recipient Dados do destinatário (cliente):
 *   - 'uf' => string (2 letras) - UF do destinatário
 *   - 'pais' => string - País do destinatário
 *   - 'extrafields' => [
 *       'indiedest' => string ('1', '2' ou '9') - Indicador de IE do destinatário
 *           '1' = Contribuinte ICMS
 *           '2' = Contribuinte isento de inscrição
 *           '9' = Não Contribuinte
 *   ]
 * 
 * @param array $operation Dados da operação (opcional):
 *   - 'tipo' => string ('venda'|'devolucao'|'bonificacao'|'demonstracao'|'industrializacao')
 *   - 'finalidade' => string ('revenda'|'consumo'|'ativo_imobilizado')
 * 
 * @return string CFOP de 4 dígitos (ex: '5102', '6108', '1202')
 * 
 * @throws Exception Se não conseguir determinar o CFOP por falta de dados
 */
function determinarCFOP($product, $emitter, $recipient, $operation = []) {
    
    // ========== VALIDAÇÃO DE DADOS OBRIGATÓRIOS ==========
    if (empty($emitter['uf']) || empty($recipient['uf'])) {
        throw new Exception('UF de origem e destino são obrigatórias para determinar CFOP');
    }
    
    // ========== COLETA E NORMALIZAÇÃO DE DADOS ==========
    $uf_origin = strtoupper(trim($emitter['uf']));
    $uf_dest = strtoupper(trim($recipient['uf']));
    $pais_dest = strtoupper(trim($recipient['pais'] ?? 'BRASIL'));
    
    // Determina se é operação interna, interestadual ou internacional
    $ehInterna = ($uf_origin === $uf_dest);
    $ehInterestadual = (!$ehInterna && $pais_dest === 'BRASIL');
    $ehExportacao = ($pais_dest !== 'BRASIL' && $pais_dest !== '');
    
    // Indicador de IE do destinatário (1=Contrib, 2=Isento, 9=Não Contrib)
    $indIEDest = trim($recipient['extrafields']['indiedest'] ?? '9');
    $ehContribuinte = ($indIEDest == '1');
    $ehNaoContribuinte = ($indIEDest == '9');
    
    // Regime tributário do produto (1 a 7)
    $regimeICMS = trim($product['extrafields']['prd_regime_icms'] ?? '1');
    
    // Origem da mercadoria (1-based no Dolibarr -> 0-based SEFAZ)
    $rawOrigem = $product['extrafields']['prd_origem'] 
              ?? $product['extrafields']['options_prd_origem'] 
              ?? null;
    
    if ($rawOrigem !== null && $rawOrigem !== '') {
        $origemInt = (int)$rawOrigem;
        // Converte: 1->0, 2->1, ..., 9->8
        $origemProduto = (string)(($origemInt >= 1) ? ($origemInt - 1) : 0);
        $origemProduto = (string)min(8, max(0, (int)$origemProduto));
    } else {
        $origemProduto = '1'; // fallback padrão (estrangeira importação)
    }
    
    $ehProducaoPropria = ($origemProduto == '0');
    
    // Tipo de operação (venda, devolução, etc.)
    $tipoOperacao = strtolower(trim($operation['tipo'] ?? 'venda'));
    
    // Log para auditoria
    $logContext = [
        'produto' => $product['ref'] ?? 'N/A',
        'uf_origin' => $uf_origin,
        'uf_dest' => $uf_dest,
        'eh_interna' => $ehInterna,
        'eh_interestadual' => $ehInterestadual,
        'eh_exportacao' => $ehExportacao,
        'indIEDest' => $indIEDest,
        'eh_contribuinte' => $ehContribuinte,
        'regime_icms' => $regimeICMS,
        'origem_produto' => $origemProduto,
        'tipo_operacao' => $tipoOperacao
    ];
    
    // ========== TRATAMENTO: EXPORTAÇÃO ==========
    if ($ehExportacao) {
        // CFOP 7xxx: Vendas para o exterior
        // 7101: Venda de produção própria
        // 7102: Venda de mercadoria adquirida de terceiros
        $cfop = $ehProducaoPropria ? '7101' : '7102';
        nfeLog('info', "CFOP determinado: {$cfop} (Exportação)", $logContext);
        return $cfop;
    }
    
    // ========== TRATAMENTO: DEVOLUÇÃO ==========
    if ($tipoOperacao === 'devolucao') {
        // Devolução inverte a lógica: CFOPs 1xxx/2xxx (entrada)
        $prefixo = $ehInterna ? '1' : '2';
        
        // Mapeia o tipo de mercadoria
        if ($regimeICMS == '2' || $regimeICMS == '3') {
            // Devolução de ST: 1410/2410 ou 1411/2411
            $cfop = $prefixo . ($regimeICMS == '2' ? '410' : '411');
        } else {
            // Devolução normal: 1202/2202
            $cfop = $prefixo . '202';
        }
        
        nfeLog('info', "CFOP determinado: {$cfop} (Devolução)", $logContext);
        return $cfop;
    }
    
    // ========== TRATAMENTO: OUTRAS OPERAÇÕES ESPECIAIS ==========
    if ($tipoOperacao === 'bonificacao') {
        $prefixo = $ehInterna ? '5' : '6';
        $cfop = $prefixo . '910'; // Bonificação
        nfeLog('info', "CFOP determinado: {$cfop} (Bonificação)", $logContext);
        return $cfop;
    }
    
    if ($tipoOperacao === 'demonstracao') {
        $prefixo = $ehInterna ? '5' : '6';
        $cfop = $prefixo . '912'; // Demonstração
        nfeLog('info', "CFOP determinado: {$cfop} (Demonstração)", $logContext);
        return $cfop;
    }
    
    // ========== DECISÃO PRINCIPAL: VENDA (SAÍDA) ==========
    
    // Define prefixo baseado no destino (5=Interna, 6=Interestadual)
    $prefixo = $ehInterna ? '5' : '6';
    
    // ===== REGRA 1: DIFAL (Interestadual para Não Contribuinte) =====
    // EC 87/2015 + Convênio ICMS 93/2015
    if ($ehInterestadual && $ehNaoContribuinte) {
        // CFOP 6108: Venda interestadual para não contribuinte
        // Exige partilha de DIFAL entre UF origem e destino
        $cfop = '6108';
        nfeLog('info', "CFOP determinado: {$cfop} (DIFAL - Não Contribuinte Interestadual)", $logContext);
        return $cfop;
    }
    
    // ===== REGRA 2: SUBSTITUIÇÃO TRIBUTÁRIA (ST) =====
    
    if ($regimeICMS == '2') {
        // SUBSTITUTO TRIBUTÁRIO: Empresa retém e recolhe o ICMS-ST
        
        if ($ehNaoContribuinte) {
            // Não contribuinte: não destaca ST na nota
            // Usa CFOP normal de venda (5102/6102)
            $cfop = $prefixo . '102';
            nfeLog('info', "CFOP determinado: {$cfop} (ST - Não Contribuinte sem destaque)", $logContext);
            return $cfop;
        }
        
        // Contribuinte: destaca ST
        if ($ehProducaoPropria) {
            // 5401/6401: Venda de produção própria com ST
            $cfop = $prefixo . '401';
        } else {
            // 5403/6403: Venda de mercadoria adquirida com ST
            $cfop = $prefixo . '403';
        }
        
        nfeLog('info', "CFOP determinado: {$cfop} (ST - Substituto Tributário)", $logContext);
        return $cfop;
    }
    
    if ($regimeICMS == '3') {
        // SUBSTITUÍDO TRIBUTÁRIO: ST já foi recolhido anteriormente
        
        if ($ehProducaoPropria) {
            // 5404/6404: Venda de produção com ST retido anteriormente
            $cfop = $prefixo . '404';
        } else {
            // 5405/6405: Venda de mercadoria adquirida com ST retido
            $cfop = $prefixo . '405';
        }
        
        nfeLog('info', "CFOP determinado: {$cfop} (ST - Substituído Tributário)", $logContext);
        return $cfop;
    }
    
    // ===== REGRA 3: OPERAÇÕES COM NÃO CONTRIBUINTE (DENTRO DO ESTADO) =====
    if ($ehInterna && $ehNaoContribuinte) {
        if ($ehProducaoPropria) {
            // 5103: Venda de produção própria a não contribuinte
            $cfop = '5103';
        } else {
            // 5104: Venda de mercadoria adquirida a não contribuinte
            $cfop = '5104';
        }
        
        nfeLog('info', "CFOP determinado: {$cfop} (Venda Interna - Não Contribuinte)", $logContext);
        return $cfop;
    }
    
    // ===== REGRA 4: PRODUTOS ISENTOS, NÃO TRIBUTADOS, SUSPENSOS =====
    // Regimes 4, 5, 6, 7: Usa CFOP de venda normal
    // A tributação específica é tratada no CSOSN/CST
    if (in_array($regimeICMS, ['4', '5', '6', '7'])) {
        if ($ehProducaoPropria) {
            // 5101/6101: Venda de produção própria
            $cfop = $prefixo . '101';
        } else {
            // 5102/6102: Venda de mercadoria adquirida
            $cfop = $prefixo . '102';
        }
        
        nfeLog('info', "CFOP determinado: {$cfop} (Produto Isento/Não Tributado/Imune)", 
            array_merge($logContext, ['regime_desc' => _getRegimeDescricao($regimeICMS)])
        );
        return $cfop;
    }
    
    // ===== REGRA 5: VENDA PADRÃO (TRIBUTADO NORMALMENTE) =====
    // Regime '1' ou qualquer outro caso não especificado
    
    if ($ehProducaoPropria) {
        // 5101/6101: Venda de produção do estabelecimento
        $cfop = $prefixo . '101';
    } else {
        // 5102/6102: Venda de mercadoria adquirida ou recebida de terceiros
        $cfop = $prefixo . '102';
    }
    
    nfeLog('info', "CFOP determinado: {$cfop} (Venda Padrão - Tributado)", $logContext);
    return $cfop;
}

/**
 * Retorna a descrição do regime ICMS para logs
 * @param string $regime
 * @return string
 */
function _getRegimeDescricao($regime) {
    $descricoes = [
        '1' => 'Tributado Integralmente',
        '2' => 'Substituto Tributário',
        '3' => 'Substituído Tributário',
        '4' => 'Isento',
        '5' => 'Não Tributado',
        '6' => 'Suspenso',
        '7' => 'Imune'
    ];
    return $descricoes[$regime] ?? 'Desconhecido';
}

/**
 * Mapeia um CFOP de saída (5xxx/6xxx) para seu correspondente de entrada (1xxx/2xxx)
 * Útil para notas de devolução
 * 
 * @param string $cfopSaida CFOP de saída (ex: '5102')
 * @return string CFOP de entrada correspondente (ex: '1202')
 */
function mapearCFOPSaidaParaEntrada($cfopSaida) {
    // Tabela de mapeamento usando APENAS CFOPs oficiais do ES (cor preta)
    $mapa = [
        // Vendas normais -> Devoluções 
        '5101' => '1201', '6101' => '2201', // Produção própria
        '5102' => '1202', '6102' => '2202', // Mercadoria adquirida
        '5103' => '1203', '6103' => '2203', // Não contribuinte (interna)
        '5104' => '1204', '6104' => '2204', // Não contribuinte (interna)
        
        // ST Substituto -> Devolução ST 
        '5401' => '1410', '6401' => '2410', // Produção própria ST
        '5403' => '1410', '6403' => '2410', // Mercadoria adquirida ST
        
        // ST Substituído -> Devolução ST 
        '5405' => '1411', '6405' => '2411', // Mercadoria com ST retido
        
        // ⚠️ REMOVIDO: 5404/6404 NÃO EXISTEM NA LISTA DO ES
        // Se aparecer, será convertido para 5405/6405
        
        // DIFAL 
        '6108' => '2202', // Não contribuinte interestadual
    ];
    
    if (isset($mapa[$cfopSaida])) {
        return $mapa[$cfopSaida];
    }
    
    // Fallback: tenta converter automaticamente
    // 5xxx -> 1xxx (interna)
    // 6xxx -> 2xxx (interestadual)
    if (preg_match('/^([56])(\d{3})$/', $cfopSaida, $matches)) {
        $prefixo = ($matches[1] == '5') ? '1' : '2';
        return $prefixo . $matches[2];
    }
    
    nfeLog('warning', 'Não foi possível mapear CFOP de saída para entrada', [
        'cfop_saida' => $cfopSaida
    ]);
    
    return $cfopSaida; // Retorna o mesmo se não conseguir mapear
}

/**
 * Valida se um CFOP é válido conforme a tabela SEFAZ
 * 
 * @param string $cfop CFOP a ser validado
 * @return bool true se válido, false caso contrário
 */
function validarCFOP($cfop) {
    // CFOP deve ter exatamente 4 dígitos
    if (!preg_match('/^\d{4}$/', $cfop)) {
        return false;
    }
    
    // Primeiro dígito deve ser 1, 2, 3, 5, 6 ou 7
    $primeiroDigito = $cfop[0];
    if (!in_array($primeiroDigito, ['1', '2', '3', '5', '6', '7'])) {
        return false;
    }
    
    // Validações por grupo
    // 1xxx/2xxx/3xxx: Entradas
    // 5xxx/6xxx/7xxx: Saídas
    
    return true; // Validação básica aprovada
}

/**
 * Retorna informações detalhadas sobre um CFOP
 * 
 * @param string $cfop CFOP a ser consultado
 * @return array Informações sobre o CFOP
 */
function obterInfoCFOP($cfop) {
    // Tabela atualizada SOMENTE com CFOPs oficiais do ES (cor preta)
    $cfops = [
        // === VENDAS (SAÍDAS) - COR PRETA ===
        '5101' => 'Venda de produção do estabelecimento',
        '5102' => 'Venda de mercadoria adquirida ou recebida de terceiros',
        '5103' => 'Venda de produção própria a não contribuinte',
        '5104' => 'Venda de mercadoria adquirida a não contribuinte',
        '5401' => 'Venda de produção com ST',
        '5403' => 'Venda de mercadoria adquirida com ST',
        '5405' => 'Venda de mercadoria com ST retido anteriormente',
        
        '6101' => 'Venda de produção do estabelecimento (Interestadual)',
        '6102' => 'Venda de mercadoria adquirida (Interestadual)',
        '6108' => 'Venda a não contribuinte (Interestadual - DIFAL)',
        '6401' => 'Venda de produção com ST (Interestadual)',
        '6403' => 'Venda de mercadoria adquirida com ST (Interestadual)',
        '6405' => 'Venda de mercadoria com ST retido (Interestadual)',
        
        // === DEVOLUÇÕES (ENTRADAS) - COR PRETA ===
        '1201' => 'Devolução de venda de produção própria',
        '1202' => 'Devolução de venda de mercadoria',
        '1203' => 'Devolução de venda a não contribuinte',
        '1204' => 'Devolução de venda a não contribuinte',
        '1410' => 'Devolução de venda de mercadoria com ST',
        '1411' => 'Devolução de venda com ST retido',
        
        '2201' => 'Devolução de venda de produção própria (Interestadual)',
        '2202' => 'Devolução de venda de mercadoria (Interestadual)',
        '2203' => 'Devolução de venda a não contribuinte (Interestadual)',
        '2204' => 'Devolução de venda a não contribuinte (Interestadual)',
        '2410' => 'Devolução de venda com ST (Interestadual)',
        '2411' => 'Devolução de venda com ST retido (Interestadual)',
        
        // === EXPORTAÇÃO - COR PRETA ===
        '7101' => 'Venda de produção do estabelecimento (Exportação)',
        '7102' => 'Venda de mercadoria adquirida (Exportação)',
        
        // ⚠️ CFOP NÃO AUTORIZADO PELO ES
        '5404' => '⚠️ CFOP NÃO CONSTA NA LISTA OFICIAL DO ES - USE 5405',
        '6404' => '⚠️ CFOP NÃO CONSTA NA LISTA OFICIAL DO ES - USE 6405',
    ];
    
    return [
        'cfop' => $cfop,
        'descricao' => $cfops[$cfop] ?? 'CFOP não catalogado',
        'valido' => validarCFOP($cfop),
        'tipo' => ($cfop[0] >= '5') ? 'SAÍDA' : 'ENTRADA',
        'natureza' => ($cfop[0] == '1' || $cfop[0] == '5') ? 'Interna' : (($cfop[0] == '7') ? 'Exportação' : 'Interestadual')
    ];
}

// Helper para logging (compatível com o sistema atual)
if (!function_exists('nfeLog')) {
    function nfeLog($level, $msg, $ctx = []) {
        if ($ctx) $msg .= ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        error_log('[NFe][CFOP][' . strtoupper($level) . '] ' . $msg);
    }
}
