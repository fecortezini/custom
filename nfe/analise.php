<?php
    // ========== TRATAMENTO DE ICMS POR CSOSN ==========
    
    // CSOSN 101: Tributada com permissão de crédito
    if ($stdICMS->CSOSN == '101') {
        $pCredSN = (float)($taxRule->icms_cred_aliq ?? 0);
        $stdICMS->pCredSN = number_format($pCredSN, 2, '.', '');
        $vCredICMSSN = $vProd * ($pCredSN / 100);
        $stdICMS->vCredICMSSN = number_format($vCredICMSSN, 2, '.', '');
        $nfe->tagICMSSN($stdICMS);
    }
    
    // CSOSN 201 ou 202: Tributada com ST (SOMENTE PARA CONTRIBUINTES)
    elseif (in_array($stdICMS->CSOSN, ['201', '202'])) {
        // Calcula ST para contribuinte
        $pMVAST = (float)($taxRule->icms_st_mva ?? 0);
        $pICMSST = (float)($taxRule->icms_st_aliq ?? 0);
        $pRedBCST = (float)($taxRule->icms_st_red_bc ?? 0);

        $baseCalculoInicial = $vProd;

        // PASSO 1: BC do ST = Valor Produto * (1 + MVA)
        $vBCST_raw = $baseCalculoInicial * (1 + ($pMVAST / 100));
        
        // PASSO 2: Aplica redução de BC (se houver)
        if ($pRedBCST > 0) {
            $vBCST_raw *= (1 - ($pRedBCST / 100));
        }

        // PASSO 3: Calcula ICMS próprio (interestadual)
        $pICMSInter = (float)($taxRule->icms_aliq_interestadual ?? 0);
        $vICMSProprio = $baseCalculoInicial * ($pICMSInter / 100);
        
        // PASSO 4: ICMS-ST = (BC ST * Alíq ST) - ICMS Próprio
        $vICMSST_raw = ($vBCST_raw * ($pICMSST / 100)) - $vICMSProprio;

        // PASSO 5: Garante que ST não seja negativo
        $vBCST = round($vBCST_raw, 2);
        $vICMSST = ($vICMSST_raw < 0) ? 0 : round($vICMSST_raw, 2);

        $stdICMS->modBCST = 4;
        $stdICMS->pMVAST = number_format($pMVAST, 4, '.', '');
        $stdICMS->vBCST = number_format($vBCST, 2, '.', '');
        $stdICMS->pICMSST = number_format($pICMSST, 2, '.', '');
        $stdICMS->vICMSST = number_format($vICMSST, 2, '.', '');

        // CSOSN 201 também tem crédito
        if ($stdICMS->CSOSN == '201') {
            $pCredSN = (float)($taxRule->icms_cred_aliq ?? 0);
            $stdICMS->pCredSN = number_format($pCredSN, 2, '.', '');
            $vCredICMSSN = $vProd * ($pCredSN / 100);
            $stdICMS->vCredICMSSN = number_format($vCredICMSSN, 2, '.', '');
        }

        $nfe->tagICMSSN($stdICMS);
    }
    
    // CSOSN 203: Isento COM ST (caso raríssimo)
    elseif ($stdICMS->CSOSN == '203') {
        // Calcula ST mesmo sendo isento (caso específico previsto na legislação)
        $pMVAST = (float)($taxRule->icms_st_mva ?? 0);
        $pICMSST = (float)($taxRule->icms_st_aliq ?? 0);
        
        $vBCST_raw = $vProd * (1 + ($pMVAST / 100));
        $vICMSST_raw = $vBCST_raw * ($pICMSST / 100);
        
        $vBCST = round($vBCST_raw, 2);
        $vICMSST = round($vICMSST_raw, 2);
        
        $stdICMS->modBCST = 4;
        $stdICMS->pMVAST = number_format($pMVAST, 4, '.', '');
        $stdICMS->vBCST = number_format($vBCST, 2, '.', '');
        $stdICMS->pICMSST = number_format($pICMSST, 2, '.', '');
        $stdICMS->vICMSST = number_format($vICMSST, 2, '.', '');
        
        $nfe->tagICMSSN($stdICMS);
    }
    
    // TODOS OS DEMAIS CSOSN (102, 103, 300, 400, 500, 900)
    // Estes CSOSN possuem apenas orig + CSOSN (sem cálculos adicionais)
    else {
        // A biblioteca NFePHP gera automaticamente a tag correta baseada no CSOSN
        // CSOSN 102 -> <ICMSSN102>
        // CSOSN 103 -> <ICMSSN103>
        // CSOSN 300 -> <ICMSSN300>
        // CSOSN 400 -> <ICMSSN400>
        // CSOSN 500 -> <ICMSSN500>
        // CSOSN 900 -> <ICMSSN900>
        
        $nfe->tagICMSSN($stdICMS);
    }
