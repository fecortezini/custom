<?php
/**
 * Script para importar municípios IBGE no Dolibarr
 * Coloque este arquivo em: htdocs/custom/seumodulo/scripts/import_municipios.php
 */

// Carrega ambiente Dolibarr
$res = 0;
if (!$res && file_exists("../main.inc.php")) {
    $res = include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
    $res = include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = include "../../../main.inc.php";
}
if (!$res) {
    die("Erro: não foi possível carregar main.inc.php");
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

// Configurações
$jsonFilePath = __DIR__ . '/estados-cidades2.json'; // Caminho para o JSON com códigos IBGE


echo "=== Importação de Municípios IBGE ===\n\n";

// 1. Criar tabela se não existir
echo "1. Criando tabela...\n";
$sql = "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "estados_municipios_ibge (
    codigo_ibge VARCHAR(7) PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    uf CHAR(2) NOT NULL,
    codigo_uf VARCHAR(2) NOT NULL,
    nome_estado VARCHAR(50) NOT NULL,
    active TINYINT DEFAULT 1,
    INDEX idx_nome (nome),
    INDEX idx_uf (uf),
    INDEX idx_codigo_uf (codigo_uf)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

$result = $db->query($sql);
if (!$result) {
    die("Erro ao criar tabela: " . $db->lasterror() . "\n");
}
echo "✓ Tabela criada/verificada com sucesso\n\n";

// 2. Ler arquivo JSON
echo "2. Lendo arquivo JSON...\n";
if (!file_exists($jsonFilePath)) {
    die("Erro: Arquivo não encontrado em $jsonFilePath\n");
}

$jsonContent = file_get_contents($jsonFilePath);
$data = json_decode($jsonContent, true);

if (json_last_error() !== JSON_ERROR_NONE) {
    die("Erro ao decodificar JSON: " . json_last_error_msg() . "\n");
}

echo "✓ JSON carregado com sucesso\n\n";

// 3. Mapear estados
echo "3. Processando dados...\n";
$estados = $data['states'];
$cidades = $data['cities'];

echo "Total de estados: " . count($estados) . "\n";
echo "Total de cidades: " . count($cidades) . "\n\n";

// 4. Limpar tabela (opcional - comente se quiser manter dados existentes)
echo "4. Limpando dados antigos...\n";
$sql = "TRUNCATE TABLE " . MAIN_DB_PREFIX . "estados_municipios_ibge";
$db->query($sql);
echo "✓ Tabela limpa\n\n";

// 5. Mapear códigos UF para siglas
$codigoParaSigla = [
    '11' => 'RO', '12' => 'AC', '13' => 'AM', '14' => 'RR', '15' => 'PA',
    '16' => 'AP', '17' => 'TO', '21' => 'MA', '22' => 'PI', '23' => 'CE',
    '24' => 'RN', '25' => 'PB', '26' => 'PE', '27' => 'AL', '28' => 'SE',
    '29' => 'BA', '31' => 'MG', '32' => 'ES', '33' => 'RJ', '35' => 'SP',
    '41' => 'PR', '42' => 'SC', '43' => 'RS', '50' => 'MS', '51' => 'MT',
    '52' => 'GO', '53' => 'DF'
];

// 6. Inserir dados
echo "5. Inserindo municípios...\n";
$db->begin();
$contador = 0;
$erros = 0;

foreach ($cidades as $cidade) {
    $codigoIbge = $cidade['id'];
    $nomeCidade = $db->escape($cidade['name']);
    $codigoUf = $cidade['state_id'];
    $siglaUf = $codigoParaSigla[$codigoUf] ?? 'XX';
    $nomeEstado = $db->escape($estados[$codigoUf] ?? 'Desconhecido');

    $sql = "INSERT INTO " . MAIN_DB_PREFIX . "estados_municipios_ibge
            (codigo_ibge, nome, uf, codigo_uf, nome_estado, active)
            VALUES (
                '$codigoIbge',
                '$nomeCidade',
                '$siglaUf',
                '$codigoUf',
                '$nomeEstado',
                1
            )";
    
    $result = $db->query($sql);
    
    if ($result) {
        $contador++;
        if ($contador % 500 == 0) {
            echo "  Inseridos: $contador municípios...\n";
        }
    } else {
        $erros++;
        echo "  Erro ao inserir $nomeCidade ($codigoIbge): " . $db->lasterror() . "\n";
    }
}

$db->commit();

echo "\n=== Importação Concluída ===\n";
echo "✓ Total inserido: $contador municípios\n";
if ($erros > 0) {
    echo "✗ Erros: $erros\n";
}
echo "\n";

// 7. Verificação
echo "6. Verificando dados inseridos...\n";
$sql = "SELECT uf, COUNT(*) as total FROM " . MAIN_DB_PREFIX . "estados_cidades_ibge GROUP BY uf ORDER BY uf";
$resql = $db->query($sql);
if ($resql) {
    echo "\nMunicípios por estado:\n";
    while ($obj = $db->fetch_object($resql)) {
        echo "  {$obj->uf}: {$obj->total} municípios\n";
    }
}

echo "\n✓ Importação finalizada com sucesso!\n";
echo "\nPara usar no seu módulo, crie uma classe helper.\n";
echo "Exemplo de consulta:\n";
echo "SELECT codigo_ibge, nome, uf FROM " . MAIN_DB_PREFIX . "estados_cidades_ibge WHERE nome LIKE '%São Paulo%'\n";
?>