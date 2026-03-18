<?php
/**
 * SIMULADOR DE CENÁRIOS FISCAIS AVANÇADO (NFe Tester)
 * 
 * Permite carregar múltiplos produtos, editar dados em massa e simular
 * cenários fiscais complexos sem persistir dados.
 */

// Inicializa ambiente Dolibarr
$res = 0;
if (! defined('NOTOKENRENEWAL')) define('NOTOKENRENEWAL', '1');
if (! defined('NOREQUIREMENU'))  define('NOREQUIREMENU', '1');
if (! defined('NOREQUIREHTML'))  define('NOREQUIREHTML', '1');
if (! defined('NOREQUIREAJAX'))  define('NOREQUIREAJAX', '1');
if (! defined('NOCSRFCHECK'))    define('NOCSRFCHECK', '1');

require '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT . '/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT . '/product/class/product.class.php';
require_once DOL_DOCUMENT_ROOT . '/societe/class/societe.class.php';

// Carrega as bibliotecas da NFe
require_once DOL_DOCUMENT_ROOT . '/custom/nfe/lib/cfop_utils.php';
require_once DOL_DOCUMENT_ROOT . '/custom/nfe/lib/cfop_builder.php';
require_once DOL_DOCUMENT_ROOT . '/custom/nfe/csosn.php';
require_once DOL_DOCUMENT_ROOT . '/custom/nfe/emissao_nfe.php'; 

// Proteção de acesso
if (!$user->admin) accessforbidden();

$action = GETPOST('action', 'alpha');

// --- GESTÃO DE ESTADO (ITENS E CONFIGURAÇÕES GLOBAIS) ---

// 1. Configurações Globais (Cliente e Emitente)
$socid_load = GETPOST('socid_load', 'int');
$sim_dest_uf = GETPOST('sim_dest_uf', 'alpha');
$sim_dest_pais = GETPOST('sim_dest_pais', 'alpha');
$sim_dest_indiedest = GETPOST('sim_dest_indiedest', 'alpha');
$sim_dest_regime = GETPOST('sim_dest_regime', 'alpha'); // NOVO: Regime do Destinatário

$mysoc = new Societe($db);
$mysoc->setMysoc($conf);
$sim_mysoc_crt = GETPOST('sim_mysoc_crt') ? GETPOST('sim_mysoc_crt') : ($mysoc->array_options['options_crt'] ?? '1');
$sim_mysoc_uf  = GETPOST('sim_mysoc_uf') ? GETPOST('sim_mysoc_uf') : $mysoc->state_code;

// Carrega dados do cliente se selecionado e ainda não preenchido
if ($action == 'load_client' && $socid_load) {
    $objSoc = new Societe($db);
    $objSoc->fetch($socid_load);
    $objSoc->fetch_optionals();
    $sim_dest_uf = $objSoc->state_code;
    $sim_dest_pais = ($objSoc->country_code == 'BR') ? 'BRASIL' : $objSoc->country;
    $sim_dest_indiedest = $objSoc->array_options['options_indiedest'] ?? '9';
    $sim_dest_regime = $objSoc->array_options['options_regime_tributario'] ?? '1'; // NOVO
}

// 2. Gestão da Lista de Itens
$items = GETPOST('items', 'array');
if (!is_array($items)) $items = [];

// Adicionar novo produto à lista
$new_prod_id = GETPOST('new_prod_id', 'int');
if ($action == 'add_product' && $new_prod_id) {
    $p = new Product($db);
    $p->fetch($new_prod_id);
    $p->fetch_optionals();
    
    $newItem = [
        'rowid' => $p->id,
        'ref' => $p->ref,
        'label' => $p->label,
        'ncm' => $p->array_options['options_prd_ncm'] ?? '',
        'origem' => $p->array_options['options_prd_origem'] ?? '0',
        'regime' => $p->array_options['options_prd_regime_icms'] ?? '1',
        // Carrega prd_fornecimento (ou prd_nat_fornecimento) do banco. Default: 2 (Terceiros)
        'fornecimento' => $p->array_options['options_prd_fornecimento'] ?? $p->array_options['options_prd_nat_fornecimento'] ?? '2',
        'qty' => 1,
        'price' => (float)$p->price
    ];
    $items[] = $newItem;
}

// Remover produto da lista
$remove_index = GETPOST('remove_index', 'int');
if ($action == 'remove_product' && isset($items[$remove_index])) {
    array_splice($items, $remove_index, 1);
}

// Limpar tudo
if ($action == 'clear_all') {
    $items = [];
    $socid_load = 0;
    $sim_dest_uf = '';
}

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <title>Simulador Fiscal NFe (Multi-Item)</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f4f6f9; padding: 20px; color: #333; }
        .container { max-width: 1400px; margin: 0 auto; }
        .card { background: #fff; padding: 20px; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); margin-bottom: 20px; }
        h1, h2, h3 { color: #2c3e50; margin-top: 0; }
        h1 { border-bottom: 1px solid #eee; padding-bottom: 10px; font-size: 1.5rem; }
        h2 { font-size: 1.1rem; border-left: 4px solid #3498db; padding-left: 10px; margin-bottom: 15px; }
        
        .grid-row { display: flex; gap: 15px; flex-wrap: wrap; align-items: flex-end; }
        .col { flex: 1; min-width: 150px; }
        .col-wide { flex: 2; }
        
        label { display: block; font-weight: 600; margin-bottom: 5px; font-size: 0.85rem; color: #555; }
        select, input { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; font-size: 0.9rem; box-sizing: border-box; }
        select:focus, input:focus { border-color: #3498db; outline: none; }
        
        .btn { padding: 8px 15px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; color: white; transition: background 0.2s; font-size: 0.9rem; }
        .btn-primary { background: #3498db; } .btn-primary:hover { background: #2980b9; }
        .btn-success { background: #27ae60; } .btn-success:hover { background: #219150; }
        .btn-danger { background: #e74c3c; } .btn-danger:hover { background: #c0392b; }
        .btn-warning { background: #f39c12; } .btn-warning:hover { background: #d35400; }
        
        /* Tabela de Itens */
        .items-table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        .items-table th { background: #f8f9fa; text-align: left; padding: 10px; border-bottom: 2px solid #ddd; font-size: 0.9rem; color: #555; }
        .items-table td { padding: 8px; border-bottom: 1px solid #eee; vertical-align: middle; }
        .items-table input, .items-table select { padding: 5px; font-size: 0.85rem; }
        
        /* Resultados */
        .result-row { display: flex; gap: 20px; border-bottom: 1px solid #eee; padding: 15px 0; }
        .result-meta { width: 250px; font-size: 0.9rem; }
        .result-data { flex: 1; }
        
        .badge { padding: 3px 6px; border-radius: 3px; color: #fff; font-size: 0.8em; font-weight: bold; display: inline-block; margin-right: 5px; }
        .bg-green { background-color: #27ae60; }
        .bg-blue { background-color: #2980b9; }
        .bg-orange { background-color: #e67e22; }
        .bg-red { background-color: #c0392b; }
        
        .mini-table { width: 100%; font-size: 0.85rem; border-collapse: collapse; }
        .mini-table td, .mini-table th { border: 1px solid #eee; padding: 4px 8px; }
        .mini-table th { background: #f9f9f9; }
    </style>
</head>
<body>

<div class="container">
    <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>">
        
        <!-- 1. CONFIGURAÇÃO GLOBAL -->
        <div class="card">
            <h1>1. Configuração Global (Operação)</h1>
            <div class="grid-row">
                <div class="col-wide">
                    <label>Cliente (Carregar Dados)</label>
                    <div style="display:flex; gap:5px;">
                        <?php $form = new Form($db); echo $form->select_thirdparty_list($socid_load, 'socid_load', 'status=1', 1); ?>
                        <button type="submit" name="action" value="load_client" class="btn btn-primary">Carregar</button>
                    </div>
                </div>
                <div class="col">
                    <label>UF Destino</label>
                    <input type="text" name="sim_dest_uf" value="<?php echo $sim_dest_uf; ?>" maxlength="2" style="text-transform:uppercase">
                </div>
                <div class="col">
                    <label>País Destino</label>
                    <input type="text" name="sim_dest_pais" value="<?php echo $sim_dest_pais ? $sim_dest_pais : 'BRASIL'; ?>" style="text-transform:uppercase">
                </div>
                <div class="col">
                    <label>Ind. IE Destinatário</label>
                    <select name="sim_dest_indiedest">
                        <option value="1" <?php echo $sim_dest_indiedest == '1' ? 'selected' : ''; ?>>1 - Contribuinte</option>
                        <option value="2" <?php echo $sim_dest_indiedest == '2' ? 'selected' : ''; ?>>2 - Isento</option>
                        <option value="9" <?php echo $sim_dest_indiedest == '9' ? 'selected' : ''; ?>>9 - Não Contribuinte</option>
                    </select>
                </div>
                <div class="col">
                    <label>Regime Trib. Destinatário</label> <!-- NOVO CAMPO -->
                    <select name="sim_dest_regime">
                        <option value="1" <?php echo $sim_dest_regime == '1' ? 'selected' : ''; ?>>1 - Simples Nacional</option>
                        <option value="3" <?php echo $sim_dest_regime == '3' ? 'selected' : ''; ?>>3 - Regime Normal (Lucro)</option>
                    </select>
                </div>
                <div class="col">
                    <label>UF Origem (Você)</label>
                    <input type="text" name="sim_mysoc_uf" value="<?php echo $sim_mysoc_uf; ?>" maxlength="2" style="text-transform:uppercase">
                </div>
                <div class="col">
                    <label>CRT Emitente</label>
                    <select name="sim_mysoc_crt">
                        <option value="1" <?php echo $sim_mysoc_crt == '1' ? 'selected' : ''; ?>>1 - Simples Nacional</option>
                        <option value="2" <?php echo $sim_mysoc_crt == '2' ? 'selected' : ''; ?>>2 - Simples (Excesso)</option>
                        <option value="3" <?php echo $sim_mysoc_crt == '3' ? 'selected' : ''; ?>>3 - Regime Normal</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- 2. ADICIONAR PRODUTOS -->
        <div class="card">
            <h1>2. Adicionar Produtos</h1>
            <div class="grid-row" style="background: #f0f7fb; padding: 15px; border-radius: 5px; border: 1px solid #dcebf7;">
                <div class="col-wide">
                    <label>Selecionar Produto (Filtro: Ref começa com 'P')</label>
                    <?php 
                    // FILTRO APLICADO AQUI: AND p.ref LIKE 'P%'
                    echo $form->select_produits('', 'new_prod_id', "AND p.ref LIKE 'P%'", 0); 
                    ?>
                </div>
                <div class="col" style="max-width: 150px;">
                    <button type="submit" name="action" value="add_product" class="btn btn-success" style="width:100%">+ Adicionar</button>
                </div>
            </div>

            <?php if (count($items) > 0): ?>
                <div style="margin-top: 20px;">
                    <table class="items-table">
                        <thead>
                            <tr>
                                <th style="width: 15%;">Ref / Label</th>
                                <th style="width: 10%;">NCM</th>
                                <th style="width: 10%;">Origem</th>
                                <th style="width: 10%;">Nat. Forn.</th> <!-- Nova Coluna -->
                                <th style="width: 20%;">Regime Trib.</th>
                                <th style="width: 10%;">Qtd</th>
                                <th style="width: 10%;">Preço Unit.</th>
                                <th style="width: 5%;">Ação</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($items as $k => $item): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo $item['ref']; ?></strong><br>
                                        <small><?php echo substr($item['label'], 0, 20); ?>...</small>
                                        <input type="hidden" name="items[<?php echo $k; ?>][ref]" value="<?php echo $item['ref']; ?>">
                                        <input type="hidden" name="items[<?php echo $k; ?>][label]" value="<?php echo $item['label']; ?>">
                                    </td>
                                    <td>
                                        <input type="text" name="items[<?php echo $k; ?>][ncm]" value="<?php echo $item['ncm']; ?>">
                                    </td>
                                    <td>
                                        <select name="items[<?php echo $k; ?>][origem]">
                                            <?php
                                            $origens = ['0'=>'0-Nac','1'=>'1-Imp','2'=>'2-Est','3'=>'3-Nac>40','4'=>'4-NacConf','5'=>'5-Nac<40','6'=>'6-ImpS','7'=>'7-EstS','8'=>'8-Nac>70'];
                                            foreach($origens as $ov => $ol) {
                                                $sel = ((string)$item['origem'] === (string)$ov) ? 'selected' : '';
                                                echo "<option value='$ov' $sel>$ol</option>";
                                            }
                                            ?>
                                        </select>
                                    </td>
                                    <td> <!-- Nova Coluna Input -->
                                        <select name="items[<?php echo $k; ?>][fornecimento]">
                                            <option value="1" <?php echo ((string)$item['fornecimento'] === '1') ? 'selected' : ''; ?>>1-Própria</option>
                                            <option value="2" <?php echo ((string)$item['fornecimento'] === '2') ? 'selected' : ''; ?>>2-Terceiros</option>
                                        </select>
                                    </td>
                                    <td>
                                        <select name="items[<?php echo $k; ?>][regime]">
                                            <?php
                                            $regimes = ['1'=>'1-Trib','2'=>'2-ST Subst','3'=>'3-ST Substdo','4'=>'4-Isento','5'=>'5-NaoTrib','6'=>'6-Susp','7'=>'7-Imune'];
                                            foreach($regimes as $rv => $rl) {
                                                $sel = ((string)$item['regime'] === (string)$rv) ? 'selected' : '';
                                                echo "<option value='$rv' $sel>$rl</option>";
                                            }
                                            ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" name="items[<?php echo $k; ?>][qty]" value="<?php echo $item['qty']; ?>" style="width: 60px;">
                                    </td>
                                    <td>
                                        <input type="number" step="0.01" name="items[<?php echo $k; ?>][price]" value="<?php echo $item['price']; ?>" style="width: 80px;">
                                    </td>
                                    <td style="text-align: center;">
                                        <button type="submit" name="action" value="remove_product" onclick="this.form.appendChild(document.createElement('input')).setAttribute('name', 'remove_index'); this.form.lastChild.value='<?php echo $k; ?>';" class="btn btn-danger" style="padding: 4px 8px;">X</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div style="margin-top: 20px; text-align: center; display: flex; gap: 10px; justify-content: center;">
                    <button type="submit" name="action" value="simulate" class="btn btn-primary" style="font-size: 1.2rem; padding: 12px 30px;">🚀 SIMULAR TODOS</button>
                    <button type="submit" name="action" value="clear_all" class="btn btn-warning">Limpar Tudo</button>
                </div>
            <?php else: ?>
                <p style="text-align: center; color: #999; margin-top: 20px;">Nenhum produto adicionado. Selecione acima e clique em Adicionar.</p>
            <?php endif; ?>
        </div>
    </form>

    <!-- 3. RESULTADOS -->
    <?php if ($action == 'simulate' && count($items) > 0): ?>
        <div class="card">
            <h1>3. Resultados da Simulação</h1>
            
            <?php
            // Prepara dados globais
            $mysocData = [
                'uf' => strtoupper($sim_mysoc_uf),
                'crt' => $sim_mysoc_crt,
                'cnpj' => '00000000000000'
            ];
            $destData = [
                'uf' => strtoupper($sim_dest_uf),
                'pais' => strtoupper($sim_dest_pais),
                'extrafields' => [
                    'indiedest' => $sim_dest_indiedest,
                    'regime_tributario' => $sim_dest_regime // NOVO: Passa o regime para a função de CSOSN
                ]
            ];

            foreach ($items as $k => $item) {
                // Prepara dados do item
                $productData = [
                    'ref' => $item['ref'],
                    'quantidade' => (float)$item['qty'],
                    'preco_venda_semtaxa' => (float)$item['price'],
                    'total_semtaxa' => (float)$item['qty'] * (float)$item['price'],
                    'extrafields' => [
                        'prd_ncm' => $item['ncm'],
                        'prd_origem' => $item['origem'],
                        'prd_regime_icms' => $item['regime'],
                        'prd_fornecimento' => $item['fornecimento'] // Usa o valor explícito do form
                    ]
                ];

                echo "<div class='result-row'>";
                
                // Coluna Esquerda: Meta dados
                echo "<div class='result-meta'>";
                echo "<h3>Item #".($k+1)." - {$item['ref']}</h3>";
                echo "<div style='color:#666; font-size:0.85rem;'>";
                echo "NCM: <b>{$item['ncm']}</b><br>";
                echo "Origem: <b>{$item['origem']}</b><br>";
                echo "Nat. Forn.: <b>".($item['fornecimento']=='1'?'Própria':'Terceiros')."</b><br>";
                echo "Regime: <b>{$item['regime']}</b><br>";
                echo "Valor: <b>R$ ".number_format($productData['total_semtaxa'], 2, ',', '.')."</b>";
                echo "</div>";
                echo "</div>";

                // Coluna Direita: Cálculos
                echo "<div class='result-data'>";
                
                // A. CFOP
                $traceCFOP = [];
                try {
                    $cfop = montarCFOP($productData, $mysocData, $destData, ['tipo' => 'venda'], $traceCFOP);
                    $cfopInfo = obterInfoCFOP($cfop);
                    $cfopClass = $cfopInfo['valido'] ? 'bg-green' : 'bg-red';
                    echo "<div><span class='badge $cfopClass'>CFOP $cfop</span> <span style='font-size:0.9rem'>{$cfopInfo['descricao']}</span></div>";
                } catch (Exception $e) {
                    echo "<div class='badge bg-red'>ERRO CFOP: {$e->getMessage()}</div>";
                    $cfop = null;
                }

                // B. Regra e Impostos
                if ($cfop) {
                    $taxRule = getTaxRule($db, $productData, $mysocData, $destData, $cfop);
                    
                    if ($taxRule) {
                        // CSOSN
                        try {
                            if (function_exists('determinarCSOSN')) {
                                $csosn = determinarCSOSN($item['regime'], $productData, $mysocData, $destData, ($sim_dest_indiedest == '9'), $taxRule);
                            } else {
                                $csosn = getCsosn($mysocData, $destData, $item['regime'], null);
                            }
                            echo "<div style='margin-top:5px'><span class='badge bg-orange'>CSOSN $csosn</span> <span style='font-size:0.85rem; color:#555'>Regra ID: {$taxRule->rowid}</span></div>";
                        } catch (Exception $e) {
                            echo "<div class='badge bg-red'>Erro CSOSN</div>";
                            $csosn = 'ERRO';
                        }

                        // Cálculos
                        $vProd = $productData['total_semtaxa'];
                        $vBCST = 0; $vICMSST = 0;
                        
                        // Cálculo de Crédito (CSOSN 101/201)
                        $vCredICMSSN = 0;
                        $pCredSN = 0;
                        if (in_array($csosn, ['101', '201'])) {
                             $pCredSN = (float)($taxRule->icms_cred_aliq ?? 0);
                             $vCredICMSSN = $vProd * ($pCredSN / 100);
                        }

                        if (in_array($csosn, ['201', '202', '203', '900'])) {
                            $pMVAST = (float)($taxRule->icms_st_mva ?? 0);
                            $pICMSST = (float)($taxRule->icms_st_aliq ?? 0);
                            $pRedBCST = (float)($taxRule->icms_st_red_bc ?? 0);
                            $pICMSInter = (float)($taxRule->icms_aliq_interestadual ?? 0);

                            $vBCST_raw = $vProd * (1 + ($pMVAST / 100));
                            if ($pRedBCST > 0) $vBCST_raw *= (1 - ($pRedBCST / 100));
                            $vICMSProprio = $vProd * ($pICMSInter / 100);
                            $vICMSST_raw = ($vBCST_raw * ($pICMSST / 100)) - $vICMSProprio;
                            $vBCST = round($vBCST_raw, 2);
                            $vICMSST = ($vICMSST_raw < 0) ? 0 : round($vICMSST_raw, 2);
                        }
                        
                        $vPIS = $vProd * ((float)($taxRule->pis_aliq ?? 0) / 100);
                        $vCOFINS = $vProd * ((float)($taxRule->cofins_aliq ?? 0) / 100);

                        echo "<table class='mini-table' style='margin-top:10px'>";
                        echo "<tr><th>Imposto</th><th>Base Calc</th><th>Alíquota</th><th>Valor</th></tr>";
                        
                        // Exibe Crédito se houver
                        if ($vCredICMSSN > 0) {
                            echo "<tr style='background-color:#e8f5e9'><td><b>Crédito ICMS</b></td><td>".number_format($vProd,2)."</td><td>{$pCredSN}%</td><td><b>".number_format($vCredICMSSN,2)."</b></td></tr>";
                        }

                        if ($vICMSST > 0) {
                            echo "<tr><td>ICMS ST</td><td>".number_format($vBCST,2)."</td><td>MVA:{$pMVAST}%</td><td><b>".number_format($vICMSST,2)."</b></td></tr>";
                        }
                        echo "<tr><td>PIS</td><td>".number_format($vProd,2)."</td><td>".($taxRule->pis_aliq??0)."%</td><td>".number_format($vPIS,2)."</td></tr>";
                        echo "<tr><td>COFINS</td><td>".number_format($vProd,2)."</td><td>".($taxRule->cofins_aliq??0)."%</td><td>".number_format($vCOFINS,2)."</td></tr>";
                        echo "</table>";

                    } else {
                        echo "<div class='badge bg-red'>SEM REGRA FISCAL</div>";
                    }
                }
                echo "</div>"; // End result-data
                echo "</div>"; // End result-row
            }
            ?>
        </div>
    <?php endif; ?>

</div>
</body>
</html>
