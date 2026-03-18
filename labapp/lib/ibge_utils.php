<?php
declare(strict_types=1);

/**
 * Busca dados do município e estado na tabela do IBGE.
 * Retorna o código do município (7 dígitos), código da UF, nome do estado, etc.
 *
 * @param DoliDB $db Conexão com o banco de dados
 * @param string $nomeMunicipio Nome do município para busca
 * @param string|null $uf Sigla do estado (opcional, para desambiguação)
 * @return stdClass|null Objeto com {codigo_ibge, nome, uf, codigo_uf, nome_estado} ou null se não encontrar
 */
function buscarDadosIbge($db, string $nomeMunicipio, ?string $uf = null)
{
    // Limpeza básica
    $nome = trim($nomeMunicipio);
    $uf = !empty($uf) ? strtoupper(trim($uf)) : null;

    // NOVO: Se o parâmetro for só dígitos, trata como código IBGE e busca diretamente
    // Isso permite chamar buscarDadosIbge($db, '3550308', null) sem mudar as chamadas existentes
    if (preg_match('/^\d+$/', $nome)) {
        $res = $db->query("SELECT codigo_ibge, nome, uf, codigo_uf, nome_estado
                           FROM " . MAIN_DB_PREFIX . "estados_municipios_ibge
                           WHERE active = 1 AND codigo_ibge = '" . $db->escape($nome) . "'
                           LIMIT 1");
        if ($res && $db->num_rows($res) > 0) {
            return $db->fetch_object($res);
        }
        // Código não encontrado na tabela — retorna null direto (não faz busca por nome com número)
        return null;
    }

    // 1. Tentativa: Busca Exata (Case/Accent Insensitive dependendo da collation do DB)
    $sql = "SELECT codigo_ibge, nome, uf, codigo_uf, nome_estado 
            FROM " . MAIN_DB_PREFIX . "estados_municipios_ibge 
            WHERE active = 1 
            AND nome = '" . $db->escape($nome) . "'";

    // Se UF for informada, filtra também pelo estado (recomendado)
    if ($uf) {
        $sql .= " AND uf = '" . $db->escape($uf) . "'";
    }

    $sql .= " LIMIT 1";

    $res = $db->query($sql);

    if ($res && $db->num_rows($res) > 0) {
        return $db->fetch_object($res);
    }

    // Se não encontrou e temos UF, tentamos estratégias de fallback para erros de digitação
    if ($uf) {
        // 2. Tentativa: Busca Fonética (SOUNDEX)
        // Resolve casos como "Sao Paulo" vs "São Paulo" ou trocas de letras com som similar
        $sqlSoundex = "SELECT codigo_ibge, nome, uf, codigo_uf, nome_estado 
                       FROM " . MAIN_DB_PREFIX . "estados_municipios_ibge 
                       WHERE active = 1 
                       AND uf = '" . $db->escape($uf) . "'
                       AND SOUNDEX(nome) = SOUNDEX('" . $db->escape($nome) . "')
                       LIMIT 1";
        
        $resSoundex = $db->query($sqlSoundex);
        if ($resSoundex && $db->num_rows($resSoundex) > 0) {
            return $db->fetch_object($resSoundex);
        }

        // 3. Tentativa: Busca Parcial (LIKE)
        // Resolve casos onde o usuário digitou apenas parte do nome
        // Só executa se o nome tiver pelo menos 4 caracteres para evitar falsos positivos
        if (strlen($nome) >= 4) {
            $sqlLike = "SELECT codigo_ibge, nome, uf, codigo_uf, nome_estado 
                        FROM " . MAIN_DB_PREFIX . "estados_municipios_ibge 
                        WHERE active = 1 
                        AND uf = '" . $db->escape($uf) . "'
                        AND nome LIKE '%" . $db->escape($nome) . "%'
                        ORDER BY LENGTH(nome) ASC 
                        LIMIT 1";
            
            $resLike = $db->query($sqlLike);
            if ($resLike && $db->num_rows($resLike) > 0) {
                return $db->fetch_object($resLike);
            }
        }
    }

    return null;
}
