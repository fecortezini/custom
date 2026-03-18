<?php
/**
 * Endpoint AJAX para buscar alíquota ISS por código de serviço
 */

// Silencia avisos
@ini_set('display_errors', '0');
@ini_set('log_errors', '1');
$__lvl = error_reporting();
$__lvl &= ~E_DEPRECATED;
$__lvl &= ~E_USER_DEPRECATED;
$__lvl &= ~E_NOTICE;
$__lvl &= ~E_USER_NOTICE;
$__lvl &= ~E_WARNING;
$__lvl &= ~E_USER_WARNING;
error_reporting($__lvl);

require '../../main.inc.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$response = array('success' => false, 'aliquota' => null, 'error' => '', 'debug' => array());

try {
    $codigo = GETPOST('codigo', 'alpha');
    $response['debug'][] = "Código recebido: {$codigo}";
    
    if (empty($codigo)) {
        throw new Exception('Código do serviço não informado.');
    }
    
    // NÃO remove pontos - mantém formato original (ex: "01.07")
    $codigo = trim($codigo);
    $response['debug'][] = "Código após trim: {$codigo}";
    
    if (empty($codigo)) {
        throw new Exception('Código do serviço inválido.');
    }
    
    // Cache de alíquotas
    static $cache = array();
    if (isset($cache[$codigo])) {
        $response['success'] = true;
        $response['aliquota'] = $cache[$codigo];
        $response['debug'][] = "Alíquota encontrada no cache: {$cache[$codigo]}";
        echo json_encode($response);
        exit;
    }
    
    // CORREÇÃO 1: Tenta múltiplas tabelas possíveis
    $tabelasTentativas = array(
        'nfse_servicos_padrao'
    );
    
    // CORREÇÃO 2: Tenta múltiplas colunas possíveis
    $colunasTentativas = array('aliquota', 'aliquota_iss', 'aliq_iss');
    
    $aliquotaEncontrada = false;
    
    foreach ($tabelasTentativas as $tabela) {
        foreach ($colunasTentativas as $coluna) {
            $sql = "SELECT {$coluna} 
                    FROM ".MAIN_DB_PREFIX."{$tabela} 
                    WHERE codigo = '".$db->escape($codigo)."' 
                    LIMIT 1";
            
            $response['debug'][] = "Tentando SQL: {$sql}";
            
            $resql = @$db->query($sql);
            
            if ($resql && $db->num_rows($resql) > 0) {
                $obj = $db->fetch_object($resql);
                $valorAliquota = (float)$obj->$coluna;
                
                $response['debug'][] = "Valor bruto encontrado: {$valorAliquota}";
                
                if ($valorAliquota > 0) {
                    $aliquota = number_format($valorAliquota, 2, '.', '');
                    $cache[$codigo] = $aliquota;
                    $response['success'] = true;
                    $response['aliquota'] = $aliquota;
                    $response['debug'][] = "✓ Alíquota encontrada na tabela {$tabela}, coluna {$coluna}: {$aliquota}";
                    $aliquotaEncontrada = true;
                    break 2; // Sai dos dois loops
                }
            }
        }
    }
    
    if (!$aliquotaEncontrada) {
        // FALLBACK: Busca alíquota padrão nas configurações
        $aliquotaPadrao = getDolGlobalString('NFSE_ALIQUOTA_ISS_PADRAO', '');
        
        if (!empty($aliquotaPadrao)) {
            $response['success'] = true;
            $response['aliquota'] = $aliquotaPadrao;
            $response['debug'][] = "Usando alíquota padrão das configurações: {$aliquotaPadrao}";
        } else {
            $response['error'] = 'Alíquota não encontrada para o código: ' . $codigo;
            $response['debug'][] = "✗ Nenhuma alíquota encontrada em nenhuma tabela";
            
            // DIAGNÓSTICO: Lista as tabelas existentes
            $sqlTables = "SHOW TABLES LIKE '".MAIN_DB_PREFIX."nfse%'";
            $resTables = $db->query($sqlTables);
            $tabelasExistentes = array();
            while ($objTable = $db->fetch_row($resTables)) {
                $tabelasExistentes[] = $objTable[0];
            }
            $response['debug'][] = "Tabelas NFSe existentes: " . implode(', ', $tabelasExistentes);
        }
    }
    
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
    $response['debug'][] = "Exceção: " . $e->getMessage();
}

echo json_encode($response);
exit;
