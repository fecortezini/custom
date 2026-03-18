<?php

/**
 * Determina o código CSOSN baseado no CRT, Destinatário e Regime do Produto.
 *
 * @param int $crt Código de Regime Tributário do Emitente (1=Simples)
 * @param int $indIEDest Indicador da IE do Destinatário (1=Contribuinte, 2=Isento, 9=Não Contribuinte)
 * @param string $regimeDest Regime Tributário do Destinatário
 * @param int $prdRegimeIcms Regime ICMS do Produto (1=Normal, 2=ST Subst, 3=ST Substituído, 4=Isento, 5=Não Trib, 6=Suspensão)
 * @return string|null Retorna o código CSOSN ou null se não for Simples Nacional
 */
function getCsosn($mysoc, $indIEDest, $regimeDest, $prdRegimeIcms, $isencaoICMS)
{   
    $regime_tributario = $regimeDest['extrafields']['regime_tributario'];
    $crt = $mysoc['crt'];
    $regimeNormal = in_array($regime_tributario, ['3', '4']);

    if (!in_array($crt, ['1', '2'])) {
        return null; // Deve-se usar a Tabela B (CST) padrão, não CSOSN.
    }

    // Mapeamento baseado no Regime ICMS do Produto ($prdRegimeIcms)
    switch ($prdRegimeIcms) {
        case 1: // Tributado Normalmente
            // Se regime do destinatario for Normal (3 ou 4) -> 101 (com crédito)
            return ($regimeNormal) ? '101' : '102';

        case 2: // Substituto Tributário (Responsável pelo recolhimento do ST)
            // Se destinatário for Contribuinte (1) -> 201 (Com crédito + ST)
            // Se não (2 ou 9) -> 202 (Sem crédito + ST)
            // Nota: 203 seria Isenção + ST, mas não temos essa combinação explícita aqui.
            if($indIEDest == 2){ // Isento
                return '203';
            } else if($indIEDest == 1){ // Contribuinte
                return ($regimeNormal) ? '201' : '202';
            } else if($indIEDest == 9){ // Não Contribuinte
                return '500';
            }

        case 3: // Substituído Tributário (ST recolhido anteriormente)
            // 500 - ICMS cobrado anteriormente por substituição tributária
            return '500';

        case 4: // Isento de ICMS
            // 103 - Isenção do ICMS no Simples Nacional para faixa de receita bruta
            // Nota: Se for Imunidade, deveria ser 300.
            return '103';

        case 5: // Não Tributado pelo ICMS
            // 400 - Não tributada pelo Simples Nacional
            return '400';

        case 6: // Suspensão do ICMS
            // Suspensão geralmente cai em "Outros"
            // 900 - Outros
            return '900';
        
        case 7: // Imune ao ICMS
            return '300';
        
        default:
            // Fallback para Outros
            return '900';
    }
}

// Exemplo de utilização:
// $csosnCalculado = getCsosn($mysoc['crt'], $dest['extrafields']['indiedest'], $dest['extrafields']['regime_tributario'], $product['extrafields']['prd_regime_icms']);