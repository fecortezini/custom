<?php
/**
 * Mapeamento de municípios do Espírito Santo (código IBGE → nome)
 * Usado para busca automática durante geração de NFSe
 */

function nfse_get_municipios_es() {
    return [
        '3200102' => 'Afonso Cláudio',
        '3200136' => 'Águia Branca',
        '3200169' => 'Água Doce do Norte',
        '3200201' => 'Alegre',
        '3200300' => 'Alfredo Chaves',
        '3200359' => 'Alto Rio Novo',
        '3200409' => 'Anchieta',
        '3200508' => 'Apiacá',
        '3200607' => 'Aracruz',
        '3200706' => 'Atílio Vivácqua',
        '3200805' => 'Baixo Guandu',
        '3200904' => 'Barra de São Francisco',
        '3201001' => 'Boa Esperança',
        '3201100' => 'Bom Jesus do Norte',
        '3201159' => 'Brejetuba',
        '3201209' => 'Cachoeiro de Itapemirim',
        '3201308' => 'Cariacica',
        '3201407' => 'Castelo',
        '3201506' => 'Colatina',
        '3201605' => 'Conceição da Barra',
        '3201704' => 'Conceição do Castelo',
        '3201803' => 'Divino de São Lourenço',
        '3201902' => 'Domingos Martins',
        '3202009' => 'Dores do Rio Preto',
        '3202108' => 'Ecoporanga',
        '3202207' => 'Fundão',
        '3202256' => 'Governador Lindenberg',
        '3202306' => 'Guaçuí',
        '3202405' => 'Guarapari',
        '3202454' => 'Ibatiba',
        '3202504' => 'Ibiraçu',
        '3202553' => 'Ibitirama',
        '3202603' => 'Iconha',
        '3202652' => 'Irupi',
        '3202702' => 'Itaguaçu',
        '3202801' => 'Itapemirim',
        '3202900' => 'Itarana',
        '3203007' => 'Iúna',
        '3203056' => 'Jaguaré',
        '3203106' => 'Jerônimo Monteiro',
        '3203130' => 'João Neiva',
        '3203163' => 'Laranja da Terra',
        '3203205' => 'Linhares',
        '3203304' => 'Mantenópolis',
        '3203320' => 'Marataízes',
        '3203346' => 'Marechal Floriano',
        '3203353' => 'Marilândia',
        '3203403' => 'Mimoso do Sul',
        '3203502' => 'Montanha',
        '3203601' => 'Mucurici',
        '3203700' => 'Muniz Freire',
        '3203809' => 'Muqui',
        '3203908' => 'Nova Venécia',
        '3204005' => 'Pancas',
        '3204054' => 'Pedro Canário',
        '3204104' => 'Pinheiros',
        '3204203' => 'Piúma',
        '3204252' => 'Ponto Belo',
        '3204302' => 'Presidente Kennedy',
        '3204351' => 'Rio Bananal',
        '3204401' => 'Rio Novo do Sul',
        '3204500' => 'Santa Leopoldina',
        '3204559' => 'Santa Maria de Jetibá',
        '3204609' => 'Santa Teresa',
        '3204658' => 'São Domingos do Norte',
        '3204708' => 'São Gabriel da Palha',
        '3204807' => 'São José do Calçado',
        '3204906' => 'São Mateus',
        '3204955' => 'São Roque do Canaã',
        '3205002' => 'Serra',
        '3205010' => 'Sooretama',
        '3205036' => 'Vargem Alta',
        '3205069' => 'Venda Nova do Imigrante',
        '3205101' => 'Viana',
        '3205150' => 'Vila Pavão',
        '3205176' => 'Vila Valério',
        '3205200' => 'Vila Velha',
        '3205309' => 'Vitória'
    ];
}

/**
 * Busca código do município pelo nome com tolerância a diferenças
 * Tenta várias estratégias: exata, normalizada, similar e por palavras-chave
 * Retorna código IBGE ou null se não encontrar
 */
function nfse_buscar_codigo_municipio($nomeCidade) {
    if (empty($nomeCidade)) return null;
    
    $municipios = nfse_get_municipios_es();
    $nomeBusca = nfse_normalizar_texto($nomeCidade);
    
    // 1ª tentativa: busca exata (normalizada)
    foreach ($municipios as $codigo => $nome) {
        if (nfse_normalizar_texto($nome) === $nomeBusca) {
            error_log('[NFSE MUNICIPIO] Encontrado por match exato: '.$nome.' => '.$codigo);
            return $codigo;
        }
    }
    
    // 2ª tentativa: busca por similaridade (permite pequenas diferenças)
    $melhorMatch = null;
    $melhorSimilaridade = 0;
    
    foreach ($municipios as $codigo => $nome) {
        $nomeNormalizado = nfse_normalizar_texto($nome);
        
        // Calcula similaridade (0-100)
        similar_text($nomeBusca, $nomeNormalizado, $percentual);
        
        // Aceita se similaridade >= 85%
        if ($percentual >= 85 && $percentual > $melhorSimilaridade) {
            $melhorMatch = $codigo;
            $melhorSimilaridade = $percentual;
        }
    }
    
    if ($melhorMatch) {
        error_log('[NFSE MUNICIPIO] Encontrado por similaridade ('.$melhorSimilaridade.'%): '.$municipios[$melhorMatch].' => '.$melhorMatch);
        return $melhorMatch;
    }
    
    // 3ª tentativa: busca por palavras-chave principais (primeira palavra significativa)
    $palavrasBusca = explode(' ', $nomeBusca);
    $palavraPrincipal = '';
    
    // Pega primeira palavra com mais de 3 letras (ignora preposições)
    foreach ($palavrasBusca as $palavra) {
        if (strlen($palavra) > 3 && !in_array($palavra, ['nova', 'alto', 'bom', 'sao', 'santa'])) {
            $palavraPrincipal = $palavra;
            break;
        }
    }
    
    if ($palavraPrincipal) {
        foreach ($municipios as $codigo => $nome) {
            $nomeNormalizado = nfse_normalizar_texto($nome);
            if (strpos($nomeNormalizado, $palavraPrincipal) !== false) {
                error_log('[NFSE MUNICIPIO] Encontrado por palavra-chave "'.$palavraPrincipal.'": '.$nome.' => '.$codigo);
                return $codigo;
            }
        }
    }
    
    // 4ª tentativa: busca parcial reversa (cidade digitada contém nome oficial)
    foreach ($municipios as $codigo => $nome) {
        $nomeNormalizado = nfse_normalizar_texto($nome);
        if (strlen($nomeNormalizado) >= 5 && strpos($nomeBusca, $nomeNormalizado) !== false) {
            error_log('[NFSE MUNICIPIO] Encontrado por match parcial reverso: '.$nome.' => '.$codigo);
            return $codigo;
        }
    }
    
    error_log('[NFSE MUNICIPIO] Cidade não encontrada após todas as tentativas: "'.$nomeCidade.'"');
    return null;
}

/**
 * Normaliza texto para comparação (remove acentos, minúsculas, espaços extras)
 */
function nfse_normalizar_texto($texto) {
    $texto = trim((string)$texto);
    $texto = mb_strtolower($texto, 'UTF-8');
    
    // Remove acentuação
    $texto = preg_replace('/[áàâãäå]/u', 'a', $texto);
    $texto = preg_replace('/[éèêë]/u', 'e', $texto);
    $texto = preg_replace('/[íìîï]/u', 'i', $texto);
    $texto = preg_replace('/[óòôõö]/u', 'o', $texto);
    $texto = preg_replace('/[úùûü]/u', 'u', $texto);
    $texto = preg_replace('/[ç]/u', 'c', $texto);
    
    // Remove caracteres especiais e espaços múltiplos
    $texto = preg_replace('/[^a-z0-9\s]/u', '', $texto);
    $texto = preg_replace('/\s+/', ' ', $texto);
    
    return trim($texto);
}

/**
 * Valida se código é válido para o ES
 */
function nfse_validar_codigo_municipio($codigo) {
    if (empty($codigo)) return false;
    
    $codigo = trim((string)$codigo);
    
    // Valida formato (7 dígitos numéricos)
    if (!preg_match('/^\d{7}$/', $codigo)) {
        error_log('[NFSE VALIDACAO] Código município com formato inválido: "'.$codigo.'" (esperado: 7 dígitos)');
        return false;
    }
    
    $municipios = nfse_get_municipios_es();
    
    if (!isset($municipios[$codigo])) {
        error_log('[NFSE VALIDACAO] Código município não encontrado na lista do ES: "'.$codigo.'"');
        return false;
    }
    
    return true;
}

/**
 * NOVA: Obtém nome do município pelo código
 */
function nfse_get_nome_municipio($codigo) {
    $municipios = nfse_get_municipios_es();
    return $municipios[$codigo] ?? null;
}

/**
 * NOVA: Busca e valida código com log detalhado
 */
function nfse_buscar_e_validar_municipio($nomeCidade, $tipoEntidade = 'entidade') {
    if (empty($nomeCidade)) {
        error_log('[NFSE MUNICIPIO] '.$tipoEntidade.': nome da cidade vazio');
        return null;
    }
    
    $codigo = nfse_buscar_codigo_municipio($nomeCidade);
    
    if ($codigo) {
        $nomeOficial = nfse_get_nome_municipio($codigo);
        error_log('[NFSE MUNICIPIO] '.$tipoEntidade.': "'.$nomeCidade.'" => '.$nomeOficial.' ('.$codigo.')');
    } else {
        error_log('[NFSE MUNICIPIO] '.$tipoEntidade.': cidade "'.$nomeCidade.'" NÃO ENCONTRADA no ES');
    }
    
    return $codigo;
}
