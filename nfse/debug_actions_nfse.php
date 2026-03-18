<?php
/**
 * Página de Debug - Visualiza TODOS os valores processados pelo actions_nfse.class.php
 * Acesse: /custom/nfse/debug_actions_nfse.php?facid=ID_DA_FATURA
 */

// Configuração de exibição de erros
@ini_set('display_errors', '1');
@ini_set('log_errors', '1');
error_reporting(E_ALL);

// Carrega Dolibarr
$res = 0;
if (!$res && file_exists("../main.inc.php")) $res = @include "../main.inc.php";
if (!$res && file_exists("../../main.inc.php")) $res = @include "../../main.inc.php";
if (!$res && file_exists("../../../main.inc.php")) $res = @include "../../../main.inc.php";
if (!$res) die("Include of main fails");

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';
require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

// Cabeçalho HTML
llxHeader('', 'Debug - Actions NFSe', '', '', 0, 0, '', '', '', 'mod-custom-nfse page-debug-actions');

print '<style>
body { font-family: monospace; background: #f5f5f5; }
.debug-container { max-width: 1400px; margin: 20px auto; background: white; padding: 20px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
.debug-section { margin: 20px 0; padding: 15px; border: 2px solid #4CAF50; border-radius: 5px; background: #f9f9f9; }
.debug-section h2 { margin: 0 0 15px 0; color: #4CAF50; font-size: 18px; border-bottom: 2px solid #4CAF50; padding-bottom: 5px; }
.debug-section h3 { color: #FF9800; font-size: 16px; margin: 15px 0 10px 0; }
.debug-section pre { background: #263238; color: #aed581; padding: 15px; border-radius: 4px; overflow-x: auto; font-size: 12px; line-height: 1.5; }
.debug-value { color: #81C784; }
.debug-key { color: #FFB74D; }
.debug-null { color: #E57373; font-style: italic; }
.debug-empty { color: #64B5F6; font-style: italic; }
.alert { padding: 15px; margin: 15px 0; border-radius: 4px; }
.alert-info { background: #E3F2FD; border-left: 4px solid #2196F3; color: #1565C0; }
.alert-warning { background: #FFF3E0; border-left: 4px solid #FF9800; color: #E65100; }
.alert-error { background: #FFEBEE; border-left: 4px solid #F44336; color: #C62828; }
.alert-success { background: #E8F5E9; border-left: 4px solid #4CAF50; color: #2E7D32; }
.toc { background: #E8F5E9; padding: 20px; border-radius: 5px; margin-bottom: 20px; }
.toc h3 { margin: 0 0 10px 0; color: #2E7D32; }
.toc ul { list-style: none; padding-left: 0; }
.toc li { margin: 5px 0; }
.toc a { color: #4CAF50; text-decoration: none; font-weight: bold; }
.toc a:hover { text-decoration: underline; }
.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }
.stat-card { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px; border-radius: 8px; text-align: center; }
.stat-card h4 { margin: 0 0 10px 0; font-size: 14px; opacity: 0.9; }
.stat-card .number { font-size: 32px; font-weight: bold; }
</style>';

print '<div class="debug-container">';

// ============ INÍCIO DA CAPTURA DE DADOS ============

// 1. Busca ID da fatura
$id_fatura = GETPOST('facid', 'int');

if (empty($id_fatura)) {
    print '<div class="alert alert-error">';
    print '<strong>⚠️ ERRO:</strong> Informe o ID da fatura na URL: <code>?facid=123</code>';
    print '</div>';
    print '</div>';
    llxFooter();
    exit;
}

// 2. Carrega mysoc (empresa emitente)
global $mysoc;
if (empty($mysoc->id)) {
    $mysoc->fetch(getDolGlobalInt('MAIN_INFO_SOCIETE_NOM'));
}
if (method_exists($mysoc, 'fetch_optionals')) {
    $mysoc->fetch_optionals();
}

// 3. Carrega fatura
$fatura = new Facture($db);
$result = $fatura->fetch($id_fatura);

if ($result <= 0) {
    print '<div class="alert alert-error">';
    print '<strong>⚠️ ERRO:</strong> Fatura #'.$id_fatura.' não encontrada no banco de dados.';
    print '</div>';
    print '</div>';
    llxFooter();
    exit;
}

// 4. Carrega cliente (thirdparty)
$cliente = null;
if ($fatura->fetch_thirdparty() > 0 && !empty($fatura->thirdparty) && is_object($fatura->thirdparty)) {
    $cliente = $fatura->thirdparty;
    $cliente->fetch_optionals();
}

// 5. Carrega extrafields
$extrafields = new ExtraFields($db);
$extrafields->fetch_name_optionals_label('facture');
$extrafields->fetch_name_optionals_label('societe');
$extrafields->fetch_name_optionals_label('product');

// 6. Monta lista de serviços (igual ao actions_nfse.class.php)
$listaServicos = [];
if (!empty($fatura->lines)) {
    foreach ($fatura->lines as $linha) {
        if ($linha->product_type == 1) { // Serviço
            $prod = new Product($db);
            if ($prod->fetch($linha->fk_product) > 0) {
                $prod->fetch_optionals();
            }
            
            $listaServicos[] = [
                'id_linha' => $linha->rowid,
                'fk_product' => $linha->fk_product,
                'product_type' => $linha->product_type,
                'product_ref' => $linha->product_ref,
                'descricao' => $linha->desc,
                'qty' => $linha->qty,
                'subprice' => $linha->subprice,
                'total_ht' => $linha->total_ht,
                'total_tva' => $linha->total_tva,
                'total_ttc' => $linha->total_ttc,
                'tva_tx' => $linha->tva_tx,
                'localtax1_tx' => $linha->localtax1_tx,
                'localtax2_tx' => $linha->localtax2_tx,
                'extrafields' => $prod->array_options ?? [],
                'linha_extrafields' => $linha->array_options ?? []
            ];
        }
    }
}

// 7. Monta dados do emitente (igual ao actions_nfse.class.php)
$extrafieldsEmitente = [];
if (!empty($mysoc->array_options)) {
    foreach ($mysoc->array_options as $k => $v) {
        $cleanKey = str_replace('options_', '', $k);
        $extrafieldsEmitente[$cleanKey] = $v;
    }
}

$prestadorIM = '';
if (!empty($mysoc->array_options['options_inscricao_municipal'])) {
    $prestadorIM = $mysoc->array_options['options_inscricao_municipal'];
} elseif (!empty($mysoc->idprof3)) {
    $prestadorIM = $mysoc->idprof3;
}

$dadosEmitente = [
    'id' => $mysoc->id,
    'nome' => $mysoc->name,
    'razao_social' => $mysoc->name,
    'cnpj' => $mysoc->idprof1,
    'ie' => $mysoc->idprof2,
    'im' => $prestadorIM,
    'endereco' => $mysoc->address,
    'numero' => '',
    'complemento' => '',
    'bairro' => '',
    'municipio' => $mysoc->town,
    'town' => $mysoc->town,
    'uf' => $mysoc->state_code,
    'cep' => $mysoc->zip,
    'telefone' => $mysoc->phone,
    'email' => $mysoc->email,
    'codigo_municipio' => getDolGlobalString('MAIN_INFO_COD_MUNICIPIO'),
    'crt' => getDolGlobalInt('MAIN_INFO_CRT'),
    'cnae' => getDolGlobalString('MAIN_INFO_CNAE'),
    'incentivoFiscal' => getDolGlobalInt('MAIN_INFO_INCENTIVOFISCAL'),
    'regimeTributacao' => getDolGlobalInt('MAIN_INFO_REGIMETRIBUTACAO'),
    'extrafields' => $extrafieldsEmitente
];

// 8. Monta dados do destinatário (igual ao actions_nfse.class.php)
$extrafieldsDestinatario = [];
if (!empty($cliente->array_options)) {
    foreach ($cliente->array_options as $k => $v) {
        $cleanKey = str_replace('options_', '', $k);
        $extrafieldsDestinatario[$cleanKey] = $v;
    }
}

$dadosDestinatario = [
    'id' => $cliente->id ?? null,
    'nome' => $cliente->name ?? '',
    'razao_social' => $cliente->name ?? '',
    'cnpj' => $cliente->idprof1 ?? '',
    'ie' => $cliente->idprof2 ?? '',
    'im' => $cliente->idprof3 ?? '',
    'endereco' => $cliente->address ?? '',
    'numero' => '',
    'complemento' => '',
    'bairro' => '',
    'municipio' => $cliente->town ?? '',
    'town' => $cliente->town ?? '',
    'uf' => $cliente->state_code ?? '',
    'cep' => $cliente->zip ?? '',
    'telefone' => $cliente->phone ?? '',
    'email' => $cliente->email ?? '',
    'codigo_municipio' => $cliente->array_options['options_codigo_do_municipio'] ?? '',
    'extrafields' => $extrafieldsDestinatario
];

// 9. Monta dados da fatura (igual ao actions_nfse.class.php)
$dadosFatura = [
    'id' => $fatura->id,
    'ref' => $fatura->ref,
    'ref_client' => $fatura->ref_client,
    'total_ht' => $fatura->total_ht,
    'total_tva' => $fatura->total_tva,
    'total_ttc' => $fatura->total_ttc,
    'date' => $fatura->date,
    'date_lim_reglement' => $fatura->date_lim_reglement,
    'statut' => $fatura->statut,
    'status' => $fatura->status,
    'paye' => $fatura->paye,
    'type' => $fatura->type,
    'extrafields' => $fatura->array_options ?? []
];

// 10. Dados de configuração NFe/NFSe do banco
$configNfe = [];
$sqlConfig = "SELECT name, value FROM ".MAIN_DB_PREFIX."nfe_config";
$resConfig = $db->query($sqlConfig);
if ($resConfig) {
    while ($rowConfig = $db->fetch_object($resConfig)) {
        // Não exibe certificado PFX por segurança
        if ($rowConfig->name === 'cert_pfx') {
            $configNfe[$rowConfig->name] = '[CERTIFICADO OCULTADO POR SEGURANÇA - '.strlen($rowConfig->value).' bytes]';
        } else {
            $configNfe[$rowConfig->name] = $rowConfig->value;
        }
    }
}

// 11. Busca NFSe emitida (se houver)
$nfseEmitida = null;
$sqlNfse = "SELECT * FROM ".MAIN_DB_PREFIX."nfse_emitidas WHERE id_fatura=".(int)$id_fatura." ORDER BY id DESC LIMIT 1";
$resNfse = $db->query($sqlNfse);
if ($resNfse && $db->num_rows($resNfse) > 0) {
    $nfseEmitida = $db->fetch_object($resNfse);
}

// 12. Busca NFSe Nacional emitida (se houver)
$nfseNacionalEmitida = null;
$sqlNfseNac = "SELECT * FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas WHERE id_fatura=".(int)$id_fatura." ORDER BY id DESC LIMIT 1";
$resNfseNac = $db->query($sqlNfseNac);
if ($resNfseNac && $db->num_rows($resNfseNac) > 0) {
    $nfseNacionalEmitida = $db->fetch_object($resNfseNac);
}

// 13. Variáveis GET e POST
$getParams = $_GET;
$postParams = $_POST;

// 14. Estatísticas
$totalLinhas = count($fatura->lines ?? []);
$totalServicos = count($listaServicos);
$totalProdutos = $totalLinhas - $totalServicos;
$totalExtrafieldsEmitente = count($extrafieldsEmitente);
$totalExtrafieldsDestinatario = count($extrafieldsDestinatario);
$totalExtrafieldsServicos = 0;
foreach ($listaServicos as $srv) {
    $totalExtrafieldsServicos += count($srv['extrafields']);
}

// ============ FUNÇÕES AUXILIARES DE FORMATAÇÃO ============

function formatValue($value, $key = '') {
    if ($value === null) {
        return '<span class="debug-null">NULL</span>';
    }
    if ($value === '') {
        return '<span class="debug-empty">[VAZIO]</span>';
    }
    if (is_bool($value)) {
        return '<span class="debug-value">' . ($value ? 'TRUE' : 'FALSE') . '</span>';
    }
    if (is_numeric($value)) {
        return '<span class="debug-value">' . htmlspecialchars($value) . '</span>';
    }
    if (is_string($value)) {
        // Oculta senhas e certificados
        if (stripos($key, 'pass') !== false || stripos($key, 'senha') !== false || stripos($key, 'password') !== false) {
            return '<span class="debug-value">[SENHA OCULTADA - '.strlen($value).' caracteres]</span>';
        }
        if (stripos($key, 'cert') !== false && strlen($value) > 100) {
            return '<span class="debug-value">[CERTIFICADO OCULTADO - '.strlen($value).' bytes]</span>';
        }
        return '<span class="debug-value">"' . htmlspecialchars($value) . '"</span>';
    }
    if (is_array($value) || is_object($value)) {
        return '<span class="debug-value">[Array/Object - ver abaixo]</span>';
    }
    return '<span class="debug-value">' . htmlspecialchars(print_r($value, true)) . '</span>';
}

function printArray($array, $title = '', $level = 0) {
    if (!is_array($array) && !is_object($array)) {
        echo '<pre>'.formatValue($array).'</pre>';
        return;
    }
    
    $array = (array)$array;
    
    if (empty($array)) {
        echo '<pre class="debug-empty">[ARRAY VAZIO]</pre>';
        return;
    }
    
    echo '<pre>';
    foreach ($array as $key => $value) {
        $indent = str_repeat('  ', $level);
        echo $indent . '<span class="debug-key">' . htmlspecialchars($key) . '</span>: ';
        
        if (is_array($value) || is_object($value)) {
            echo "\n";
            printArray($value, '', $level + 1);
        } else {
            echo formatValue($value, $key) . "\n";
        }
    }
    echo '</pre>';
}

// ============ EXIBIÇÃO DO DEBUG ============

print '<h1 style="color: #4CAF50; border-bottom: 3px solid #4CAF50; padding-bottom: 10px;">
🔍 Dados Enviados para Emissão de NFSe
</h1>';

print '<div class="alert alert-info">';
print '<strong>📋 Fatura:</strong> '.$fatura->ref.' (ID: '.$fatura->id.')<br>';
print '<strong>🏢 Cliente:</strong> '.($cliente->name ?? '[NÃO ENCONTRADO]').' (ID: '.($cliente->id ?? 'N/A').')<br>';
print '<strong>📅 Data:</strong> '.dol_print_date($fatura->date, 'day').'<br>';
print '<strong>💰 Total:</strong> '.price($fatura->total_ttc).'<br>';
print '<strong>📊 Status:</strong> '.$fatura->getLibStatut(5);
print '</div>';

print '<div class="alert alert-success">';
print '<strong>ℹ️ IMPORTANTE:</strong> Esta página mostra os <strong>4 arrays</strong> que são passados para as funções:<br>';
print '• <code>gerarNfse($db, $dadosFatura, $dadosEmitente, $dadosDestinatario, $listaServicos)</code><br>';
print '• <code>gerarNfseNacional($db, $dadosFatura, $dadosEmitente, $dadosDestinatario, $listaServicos)</code>';
print '</div>';

// Estatísticas
print '<div class="stats">';
print '<div class="stat-card">';
print '<h4>Total de Serviços</h4>';
print '<div class="number">'.$totalServicos.'</div>';
print '</div>';
print '<div class="stat-card">';
print '<h4>Extrafields Emitente</h4>';
print '<div class="number">'.$totalExtrafieldsEmitente.'</div>';
print '</div>';
print '<div class="stat-card">';
print '<h4>Extrafields Destinatário</h4>';
print '<div class="number">'.$totalExtrafieldsDestinatario.'</div>';
print '</div>';
print '<div class="stat-card">';
print '<h4>Extrafields Serviços</h4>';
print '<div class="number">'.$totalExtrafieldsServicos.'</div>';
print '</div>';
print '</div>';

// ========== DADOS ENVIADOS PARA EMISSÃO ==========

// 1. Dados do Emitente
print '<div class="debug-section" id="sec1">';
print '<h2>1️⃣ $dadosEmitente</h2>';
print '<p style="background: #FFF3E0; padding: 10px; border-left: 4px solid #FF9800; margin: 10px 0;">';
print '<strong>📤 Este array é passado como 3º parâmetro para gerarNfse() e gerarNfseNacional()</strong>';
print '</p>';
printArray($dadosEmitente);
print '</div>';

// 2. Dados do Destinatário
print '<div class="debug-section" id="sec2">';
print '<h2>2️⃣ $dadosDestinatario</h2>';
print '<p style="background: #FFF3E0; padding: 10px; border-left: 4px solid #FF9800; margin: 10px 0;">';
print '<strong>📤 Este array é passado como 4º parâmetro para gerarNfse() e gerarNfseNacional()</strong>';
print '</p>';
printArray($dadosDestinatario);
print '</div>';

// 3. Dados da Fatura
print '<div class="debug-section" id="sec3">';
print '<h2>3️⃣ $dadosFatura</h2>';
print '<p style="background: #FFF3E0; padding: 10px; border-left: 4px solid #FF9800; margin: 10px 0;">';
print '<strong>📤 Este array é passado como 2º parâmetro para gerarNfse() e gerarNfseNacional()</strong>';
print '</p>';
printArray($dadosFatura);
print '</div>';

// 4. Lista de Serviços
print '<div class="debug-section" id="sec4">';
print '<h2>4️⃣ $listaServicos</h2>';
print '<p style="background: #FFF3E0; padding: 10px; border-left: 4px solid #FF9800; margin: 10px 0;">';
print '<strong>📤 Este array é passado como 5º parâmetro para gerarNfse() e gerarNfseNacional()</strong>';
print '</p>';
if (empty($listaServicos)) {
    print '<div class="alert alert-warning">⚠️ Nenhum serviço encontrado na fatura</div>';
} else {
    print '<p><strong>Total de serviços:</strong> '.count($listaServicos).'</p>';
    foreach ($listaServicos as $idx => $srv) {
        print '<h3>Serviço #'.($idx+1).'</h3>';
        printArray($srv);
    }
}
print '</div>';

print '</div>'; // fim debug-container

print '<div style="text-align: center; margin: 30px 0; padding: 20px; background: #E8F5E9; border-radius: 5px;">';
print '<strong style="color: #2E7D32; font-size: 16px;">✅ Estes são os 4 arrays enviados para emissão</strong><br>';
print '<span style="color: #666; font-size: 14px; margin-top: 10px; display: block;">gerarNfse($db, <span style="color: #FF9800; font-weight: bold;">$dadosFatura, $dadosEmitente, $dadosDestinatario, $listaServicos</span>)</span>';
print '</div>';

llxFooter();
$db->close();
