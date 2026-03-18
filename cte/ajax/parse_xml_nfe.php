<?php
/**
 * Parser de XML NF-e para CT-e
 * Processa o XML e retorna dados em JSON
 */

// Carregamento do ambiente Dolibarr
$res = 0;
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res) {
    die(json_encode(['error' => 'Include of main fails']));
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Método não permitido']);
    exit;
}

if (!isset($_FILES['xml_nfe']) || $_FILES['xml_nfe']['error'] != 0) {
    echo json_encode(['error' => 'Arquivo não enviado ou erro no upload']);
    exit;
}

try {
    $xml_content = file_get_contents($_FILES['xml_nfe']['tmp_name']);
    $xml = simplexml_load_string($xml_content);
    
    if ($xml === false) {
        throw new Exception('Erro ao ler XML');
    }
    
    // Registrar namespace
    $xml->registerXPathNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');
    
    // Extrair dados
    $ide = $xml->xpath('//nfe:ide')[0];
    $emit = $xml->xpath('//nfe:emit')[0];
    $dest = $xml->xpath('//nfe:dest')[0];
    $total = $xml->xpath('//nfe:total/nfe:ICMSTot')[0];
    $transp = $xml->xpath('//nfe:transp')[0];
    
    $dados = [
        'success' => true,
        'cfop' => (string)$ide->CFOP,
        'natOp' => (string)$ide->natOp,
        'dest_cnpj' => isset($dest->CNPJ) ? (string)$dest->CNPJ : (string)$dest->CPF,
        'dest_xNome' => (string)$dest->xNome,
        'dest_xLgr' => (string)$dest->enderDest->xLgr,
        'dest_nro' => (string)$dest->enderDest->nro,
        'dest_xBairro' => (string)$dest->enderDest->xBairro,
        'dest_xMun' => (string)$dest->enderDest->xMun,
        'dest_uf' => (string)$dest->enderDest->UF,
        'dest_cep' => (string)$dest->enderDest->CEP,
        'vCarga' => number_format((float)$total->vNF, 2, '.', ''),
        'chave_nfe' => str_replace('NFe', '', (string)$xml->xpath('//nfe:infNFe')[0]['Id']),
        'nDoc' => (string)$ide->nNF,
        'serie_nf' => (string)$ide->serie,
    ];
    
    echo json_encode($dados);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
