<?php
// bootstrap mínimo para permitir incluir o arquivo com a função
if (!defined('DOL_DOCUMENT_ROOT')) {
    // define duas pastas acima: .../dolibarr/htdocs
    define('DOL_DOCUMENT_ROOT', dirname(dirname(__DIR__)));
}

require_once __DIR__ . '/emissao_nfe.php'; // arquivo fornecido contendo determinarCSOSN

// Helpers rápidos
function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function randChoice($arr) { return $arr[array_rand($arr)]; }

$action = $_POST['action'] ?? null;
$errors = [];
$result = null;
$explain = [];

if ($action === 'randomize') {
    // Gera conjuntos aleatórios e executa o teste
    $_POST['regimeICMS'] = randChoice(['1','2','3','4','5','6','7']);
    $_POST['product_ref'] = 'PRD' . rand(100,999);
    $_POST['product_ncm'] = str_pad((string)rand(10000000,99999999),8,'0',STR_PAD_LEFT);
    $_POST['product_prd_isencao_formal'] = rand(0,1) ? '1' : '0';

    $_POST['mysoc_crt'] = '1'; // sempre simples nacional
    $_POST['mysoc_cnpj'] = str_pad((string)rand(10000000000000,99999999999999),14,'0',STR_PAD_LEFT);

    $_POST['mysoc_uf'] = 'ES'; // sempre ES
    $_POST['dest_uf'] = 'ES'; // sempre ES
    $_POST['dest_indiedest'] = randChoice(['1','9']);
    // NOVO: regime tributário do destinatário (1..4)
    $_POST['dest_regime_tributario'] = randChoice(['1','2','3','4']);

    $hasST = rand(0,1);
    $_POST['tax_icms_cred_aliq'] = rand(0,5) * (rand(0,1) ? 1 : 0); // 0..5%
    $_POST['tax_icms_st_mva'] = $hasST ? rand(5,60) : 0;
    $_POST['tax_icms_st_aliq'] = $hasST ? rand(7,25) : 0;
    $_POST['tax_icms_st_predbc'] = $hasST && rand(0,1) ? rand(0,30) : 0;


    $action = 'test';
}

if ($action === 'test') {

    $regimeICMS = (string)($_POST['regimeICMS'] ?? '1');

    $product = [
        'ref' => $_POST['product_ref'] ?? 'PRD000',
        'extrafields' => [
            'prd_ncm' => $_POST['product_ncm'] ?? '',
            'prd_regime_icms' => $regimeICMS,
            'prd_isencao_formal' => ($_POST['product_prd_isencao_formal'] ?? '0') ? '1' : '0'
        ]
    ];

    $mysoc = [
        'crt' => $_POST['mysoc_crt'] ?? '1',
        'cnpj' => $_POST['mysoc_cnpj'] ?? '00000000000000',
        'uf' => $_POST['mysoc_uf'] ?? 'SP'
    ];

    $dest = [
        'uf' => $_POST['dest_uf'] ?? 'RJ',
        'extrafields' => [
            'indiedest' => $_POST['dest_indiedest'] ?? '1',
            // NOVO: passa regime_tributario do destinatário para a estrutura esperada pela função temCredito
            'regime_tributario' => $_POST['dest_regime_tributario'] ?? '1'
        ]
    ];

    $taxRule = null;
    // Se preencher alguma alíquota, monta o objeto similar ao esperado
    if (strlen(trim((string)($_POST['tax_icms_cred_aliq'] ?? ''))) ||
        strlen(trim((string)($_POST['tax_icms_st_mva'] ?? '')))) {
        $taxRule = new stdClass();
        $taxRule->icms_cred_aliq = (float)($_POST['tax_icms_cred_aliq'] ?? 0.0);
        $taxRule->icms_st_mva = (float)($_POST['tax_icms_st_mva'] ?? 0.0);
        $taxRule->icms_st_aliq = (float)($_POST['tax_icms_st_aliq'] ?? 0.0);
        $taxRule->icms_st_predbc = (float)($_POST['tax_icms_st_predbc'] ?? 0.0);
    }

    // ===== Novo: tentar obter a regra aplicada do banco (se possível) =====
    $appliedTaxRule = null;
    $cfopToSearch = null;
    // tenta calcular CFOP se a função existe
    if (function_exists('determinarCfopPorItem')) {
        try {
            $cfopToSearch = determinarCfopPorItem($product, $mysoc, $dest);
        } catch (Exception $e) {
            // ignore - continua sem CFOP
        }
    }

    // se temos acesso ao $db e à função getTaxRule, consulta a regra real
    if (function_exists('getTaxRule') && !empty($GLOBALS['db'])) {
        try {
            $appliedTaxRule = getTaxRule($GLOBALS['db'], $product, $mysoc, $dest, $cfopToSearch ?? '');
        } catch (Exception $e) {
            // ignore - fallback abaixo
            $appliedTaxRule = null;
        }
    }

    // se não encontrou no banco, usa a regra enviada pelo formulário (se houver)
    if (!$appliedTaxRule && $taxRule) {
        $appliedTaxRule = $taxRule;
        if (!empty($_POST['tax_rule_id'])) $appliedTaxRule->id = $_POST['tax_rule_id'];
        if (!empty($_POST['tax_rule_name'])) $appliedTaxRule->name = $_POST['tax_rule_name'];
    }

    // Executa a função em try/catch
    try {
        $csosn = determinarCSOSN($regimeICMS, $product, $mysoc, $dest, (($dest['extrafields']['indiedest'] ?? '1') === '9'), $taxRule);
        $result = [
            'csosn' => $csosn,
            'inputs' => compact('regimeICMS','product','mysoc','dest','taxRule')
        ];

        // Monta explicação resumida (não reexecução da lógica inteira, apenas flags)
        $crt = trim((string)($mysoc['crt'] ?? '3'));
        $aliqCredito = ($taxRule->icms_cred_aliq ?? 0.0) + 0.0;
        $temCredito = ($aliqCredito > 0.0);
        $mva = ($taxRule->icms_st_mva ?? 0.0) + 0.0;
        $aliqST = ($taxRule->icms_st_aliq ?? 0.0) + 0.0;
        $temDadosST = ($mva > 0 && $aliqST > 0);
        $temIsencaoFormal = (($product['extrafields']['prd_isencao_formal'] ?? '') === '1');

        $explain[] = "Validação CRT: emitente CRT={$crt} (CSOSN aplicável apenas para CRT=1/2).";                                                                   
        $explain[] = "Regime ICMS informado: {$regimeICMS}.";
        $explain[] = "Crédito de ICMS presente? " . ($temCredito ? "SIM (aliquota={$aliqCredito}%)" : "NÃO");
        $explain[] = "Dados de ST presentes? " . ($temDadosST ? "SIM (MVA={$mva}%, AliqST={$aliqST}%)" : "NÃO");
        $explain[] = "Isenção formal no produto? " . ($temIsencaoFormal ? "SIM" : "NÃO");

        // Exibe id e nome/descrição da regra fiscal aplicada (quando disponível)
        if ($appliedTaxRule) {
            // tenta extrair propriedades de forma robusta
            $vars = is_object($appliedTaxRule) ? get_object_vars($appliedTaxRule) : (is_array($appliedTaxRule) ? $appliedTaxRule : []);
            $idKeys = ['id','rowid','rule_id','pk','codigo','codigo_regra'];
            $nameKeys = ['name','nome','descricao','description','label','titulo','rule_name'];

            $ruleId = 'N/D';
            foreach ($idKeys as $k) {
                if (array_key_exists($k, $vars) && $vars[$k] !== null && $vars[$k] !== '') {
                    $ruleId = $vars[$k];
                    break;
                }
            }

            $ruleName = null;
            foreach ($nameKeys as $k) {
                if (array_key_exists($k, $vars) && $vars[$k] !== null && $vars[$k] !== '') {
                    $ruleName = $vars[$k];
                    break;
                }
            }

            // fallback: construir rótulo a partir de cfop/ncm se disponível
            if (!$ruleName) {
                $cfop = $vars['cfop'] ?? $vars['cfop_aplic'] ?? null;
                $ncm  = $vars['ncm'] ?? null;
                if ($cfop || $ncm) {
                    $parts = [];
                    if ($cfop) $parts[] = "CFOP: {$cfop}";
                    if ($ncm)  $parts[] = "NCM: {$ncm}";
                    $ruleName = implode(' ', $parts);
                }
            }

            $explain[] = "Regra fiscal aplicada: id={$ruleId}" . ($ruleName ? ", nome=\"{$ruleName}\"" : '');
            // sempre incluir detalhes completos para inspeção
            $explain[] = "Regra fiscal (detalhes): " . json_encode($appliedTaxRule, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        } else {
            $explain[] = "Regra fiscal aplicada: NENHUMA (sem taxRule encontrada ou fornecida)";
        }

        $explain[] = "Resultado final CSOSN: {$csosn}";

    } catch (Exception $e) {
        $errors[] = $e->getMessage();
    }
}

// HTML de saída simples
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"/>
<title>Teste determinarCSOSN</title>
<style>
:root{
  --bg:#f5f7fb; --card:#ffffff; --accent:#2b7be4; --muted:#7a869a; --success:#1b7a3a; --danger:#a31b1b;
  --border:#e3e8ef; --shadow:rgba(23,33,60,0.08);
}
*{box-sizing:border-box}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Oxygen,Ubuntu,sans-serif;background:var(--bg);color:#2d3748;margin:24px;font-size:14px;line-height:1.6}
.container{max-width:1300px;margin:0 auto}
.header{display:flex;align-items:center;gap:16px;margin-bottom:24px}
.logo{width:60px;height:60px;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);border-radius:12px;display:flex;align-items:center;justify-content:center;color:#fff;font-weight:700;font-size:18px;box-shadow:0 4px 14px rgba(102,126,234,0.4)}
.header-text h2{margin:0;font-size:22px;font-weight:600;color:#1a202c}
.header-text .subtitle{font-size:14px;color:var(--muted);margin-top:2px}

/* SPLIT LAYOUT */
.layout{display:flex;gap:24px;align-items:flex-start}
.left{flex:1;min-width:0}
.right{width:420px;flex:0 0 420px}

/* cards */
.card{background:var(--card);border-radius:12px;padding:24px;box-shadow:0 1px 3px var(--shadow);border:1px solid var(--border)}
.panel{border-radius:12px;padding:20px;background:var(--card);box-shadow:0 1px 3px var(--shadow);border:1px solid var(--border)}

/* form */
.form-section{margin-bottom:20px}
.form-section-title{font-size:15px;font-weight:600;color:#1a202c;margin-bottom:12px;display:flex;align-items:center;gap:8px}
.form-section-title::before{content:'';width:4px;height:16px;background:var(--accent);border-radius:2px}
.form-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:16px}
.form-grid .full{grid-column:1/-1}
.form-group{position:relative}
label.field{display:block;font-size:13px;font-weight:500;color:#4a5568;margin-bottom:6px}
input[type=text], select{width:100%;padding:11px 14px;border:1.5px solid var(--border);border-radius:8px;background:#fafbfc;font-size:14px;transition:all 0.2s;color:#2d3748}
input[type=text]:focus, select:focus{outline:none;border-color:var(--accent);background:#fff;box-shadow:0 0 0 3px rgba(43,123,228,0.1)}
input[type=text]::placeholder{color:#a0aec0}
select{appearance:none;background-image:url("data:image/svg+xml,%3csvg xmlns='http://www.w3.org/2000/svg' fill='none' viewBox='0 0 20 20'%3e%3cpath stroke='%236b7280' stroke-linecap='round' stroke-linejoin='round' stroke-width='1.5' d='M6 8l4 4 4-4'/%3e%3c/svg%3e");background-position:right 10px center;background-repeat:no-repeat;background-size:20px;padding-right:40px}

.row-actions{display:flex;gap:12px;justify-content:flex-end;margin-top:24px;padding-top:20px;border-top:1px solid var(--border)}
.btn{border:0;padding:12px 20px;border-radius:8px;font-weight:600;cursor:pointer;transition:all 0.2s;font-size:14px;display:inline-flex;align-items:center;gap:8px}
.btn:hover{transform:translateY(-1px)}
.btn-primary{background:var(--accent);color:#fff;box-shadow:0 4px 12px rgba(43,123,228,0.3)}
.btn-primary:hover{background:#2563eb;box-shadow:0 6px 16px rgba(43,123,228,0.4)}
.btn-ghost{background:#f7fafc;border:1.5px solid var(--border);color:var(--accent)}
.btn-ghost:hover{background:#edf2f7;border-color:var(--accent)}

/* result panel */
.result-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;padding-bottom:12px;border-bottom:2px solid #e6f0ff}
.csosn-badge{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:#fff;padding:8px 16px;border-radius:8px;font-size:20px;font-weight:700;letter-spacing:0.5px;box-shadow:0 4px 12px rgba(102,126,234,0.3)}
.section-title{font-size:14px;font-weight:600;color:#1a202c;margin:16px 0 8px 0;display:flex;align-items:center;gap:6px}
.section-title::before{content:'▸';color:var(--accent)}
.explain{font-size:13px;color:#4a5568;line-height:1.7}
.explain ol{margin:8px 0;padding-left:20px}
.explain li{margin-bottom:6px}

/* tax rule table */
.tax-table{width:100%;border-collapse:collapse;font-size:12px;margin-top:8px}
.tax-table th{background:#f7fafc;padding:8px 10px;text-align:left;font-weight:600;color:#4a5568;border-bottom:2px solid var(--border)}
.tax-table td{padding:8px 10px;border-bottom:1px solid #f0f4f8;color:#2d3748}
.tax-table tr:last-child td{border-bottom:none}
.tax-table td:first-child{font-weight:500;color:#718096;width:40%}
.badge{display:inline-block;padding:3px 8px;border-radius:4px;font-size:11px;font-weight:600}
.badge-active{background:#d1fae5;color:#065f46}
.badge-inactive{background:#fee;color:#991b1b}

.err{background:#fff5f5;border:1.5px solid #feb2b2;color:#c53030;border-radius:10px;padding:14px}
.err strong{display:block;margin-bottom:6px}
.note{font-size:13px;color:var(--muted);margin-top:12px}
.empty-state{text-align:center;padding:40px 20px;color:var(--muted)}
.empty-state svg{width:48px;height:48px;margin-bottom:12px;opacity:0.5}

/* responsive */
@media(max-width:1100px){
  .layout{flex-direction:column}
  .right{width:100%;max-width:none}
  .form-grid{grid-template-columns:1fr}
}
</style>
</head>
<body>
<div class="container">
  <div class="header">
    <div class="logo">NFe</div>
    <div class="header-text">
      <h2>Testador CSOSN (Simples Nacional)</h2>
      <div class="subtitle">Valide a lógica de escolha do CSOSN baseada em regras fiscais</div>
    </div>
  </div>

  <div class="layout">
    <div class="left">
      <div class="card">
        <form method="post" autocomplete="off">
          <div class="form-section">
            <div class="form-section-title">Produto e Regime</div>
            <div class="form-grid">
              <div class="form-group">
                <label class="field">Regime ICMS</label>
                <select name="regimeICMS">
                  <?php foreach(['1','2','3','4','5','6','7'] as $r): $sel = (isset($_POST['regimeICMS']) && $_POST['regimeICMS']==$r) ? 'selected' : ''; ?>
                  <option value="<?=h($r)?>" <?=$sel?>><?=$r?> — <?=['1'=>'Tributado','2'=>'Subst.Trib','3'=>'Substituído','4'=>'Isento','5'=>'Não trib.','6'=>'Suspenso','7'=>'Imune'][$r]?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="form-group">
                <label class="field">Produto - ref</label>
                <input type="text" name="product_ref" placeholder="Ex: PRD001" value="<?=h($_POST['product_ref'] ?? 'PRD001')?>"/>
              </div>
              <div class="form-group">
                <label class="field">Produto - NCM</label>
                <input type="text" name="product_ncm" placeholder="Ex: 49010000" value="<?=h($_POST['product_ncm'] ?? '49010000')?>"/>
              </div>
              <div class="form-group">
                <label class="field">Isenção formal (prd_isencao_formal)</label>
                <select name="product_prd_isencao_formal">
                  <option value="0" <?=(!($_POST['product_prd_isencao_formal'] ?? '')||$_POST['product_prd_isencao_formal']=='0')?'selected':''?>>0 — Não</option>
                  <option value="1" <?=(($_POST['product_prd_isencao_formal'] ?? '')==='1')?'selected':''?>>1 — Sim</option>
                </select>
              </div>
            </div>
          </div>

          <div class="form-section">
            <div class="form-section-title">Emitente e Destinatário</div>
            <div class="form-grid">
              <div class="form-group">
                <label class="field">Emitente - CRT</label>
                <select name="mysoc_crt">
                  <option value="1" <?=(($_POST['mysoc_crt'] ?? '')==='1')?'selected':''?>>1 — Simples Nacional</option>
                  <option value="2" <?=(($_POST['mysoc_crt'] ?? '')==='2')?'selected':''?>>2 — SN com ST</option>
                  <option value="3" <?=(($_POST['mysoc_crt'] ?? '')==='3')?'selected':''?>>3 — Regime Normal</option>
                </select>
              </div>
              <div class="form-group">
                <label class="field">Emitente - UF</label>
                <input type="text" name="mysoc_uf" placeholder="Ex: ES" value="<?=h($_POST['mysoc_uf'] ?? 'ES')?>"/>
              </div>
              <div class="form-group">
                <label class="field">Destinatário - UF</label>
                <input type="text" name="dest_uf" placeholder="Ex: ES" value="<?=h($_POST['dest_uf'] ?? 'ES')?>"/>
              </div>
              <div class="form-group">
                <label class="field">Destinatário - indIEDest</label>
                <select name="dest_indiedest">
                  <option value="1" <?=(($_POST['dest_indiedest'] ?? '')==='1')?'selected':''?>>1 — Contribuinte</option>
                  <option value="9" <?=(($_POST['dest_indiedest'] ?? '')==='9')?'selected':''?>>9 — Não contribuinte</option>
                </select>
              </div>

              <!-- NOVO: regime tributário do destinatário -->
              <div class="form-group">
                <label class="field">Regime Tributário do Destinatário</label>
                <select name="dest_regime_tributario">
                  <option value="1" <?=(($_POST['dest_regime_tributario'] ?? '')==='1')?'selected':''?>>1 — Simples Nacional</option>
                  <option value="2" <?=(($_POST['dest_regime_tributario'] ?? '')==='2')?'selected':''?>>2 — Simples Nacional c/ excesso</option>
                  <option value="3" <?=(($_POST['dest_regime_tributario'] ?? '')==='3')?'selected':''?>>3 — Lucro Presumido</option>
                  <option value="4" <?=(($_POST['dest_regime_tributario'] ?? '')==='4')?'selected':''?>>4 — Lucro Real</option>
                </select>
              </div>
            </div>
          </div>

          <div class="form-section">
            <div class="form-section-title">Alíquotas da Regra Fiscal (opcional)</div>
            <div class="form-grid">
              <div class="form-group">
                <label class="field">Crédito ICMS (%)</label>
                <input type="text" name="tax_icms_cred_aliq" placeholder="Ex: 2.00" value="<?=h($_POST['tax_icms_cred_aliq'] ?? '0')?>"/>
              </div>
              <div class="form-group">
                <label class="field">MVA ST (%)</label>
                <input type="text" name="tax_icms_st_mva" placeholder="Ex: 40.00" value="<?=h($_POST['tax_icms_st_mva'] ?? '0')?>"/>
              </div>
              <div class="form-group">
                <label class="field">Alíquota ST (%)</label>
                <input type="text" name="tax_icms_st_aliq" placeholder="Ex: 18.00" value="<?=h($_POST['tax_icms_st_aliq'] ?? '0')?>"/>
              </div>
              <div class="form-group">
                <label class="field">Redução BC ST (%)</label>
                <input type="text" name="tax_icms_st_predbc" placeholder="Ex: 12.00" value="<?=h($_POST['tax_icms_st_predbc'] ?? '0')?>"/>
              </div>
            </div>
          </div>

          <div class="row-actions">
            <button class="btn btn-ghost" type="submit" name="action" value="randomize">
              <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
              Aleatorizar
            </button>
            <button class="btn btn-primary" type="submit" name="action" value="test">
              <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
              Executar Teste
            </button>
          </div>
        </form>
      </div>
    </div>

    <aside class="right">
      <div class="panel">
        <?php if ($errors): ?>
          <div class="err">
            <strong>❌ Erros encontrados:</strong>
            <ul style="margin:8px 0 0 0;padding-left:20px"><?php foreach($errors as $e) echo '<li>'.h($e).'</li>'; ?></ul>
          </div>
        <?php elseif ($result): ?>
          <div class="result-header">
            <strong style="font-size:15px">CSOSN Calculado</strong>
            <span class="csosn-badge"><?=h($result['csosn'])?></span>
          </div>

          <div class="section-title">Processo de Decisão</div>
          <div class="explain">
            <ol style="margin:8px 0;padding-left:20px">
              <?php foreach($explain as $line): ?>
                <?php if (strpos($line, 'Regra fiscal (detalhes)') === false): ?>
                  <li><?=h($line)?></li>
                <?php endif; ?>
              <?php endforeach; ?>
            </ol>
          </div>

          <?php if ($appliedTaxRule): ?>
            <div class="section-title">Regra Fiscal Aplicada</div>
            <?php
              $vars = is_object($appliedTaxRule) ? get_object_vars($appliedTaxRule) : (is_array($appliedTaxRule) ? $appliedTaxRule : []);
              
              // Extrai label com fallback robusto
              $ruleLabel = null;
              $labelKeys = ['label','name','nome','descricao','description','titulo'];
              foreach ($labelKeys as $k) {
                  if (isset($vars[$k]) && $vars[$k] !== null && trim($vars[$k]) !== '') {
                      $ruleLabel = $vars[$k];
                      break;
                  }
              }
              if (!$ruleLabel) {
                  // fallback: construir label a partir de cfop/ncm
                  $parts = [];
                  if (!empty($vars['cfop'])) $parts[] = "CFOP {$vars['cfop']}";
                  if (!empty($vars['ncm'])) $parts[] = "NCM {$vars['ncm']}";
                  $ruleLabel = $parts ? implode(' - ', $parts) : 'Sem identificação';
              }
              
              // Extrai ID
              $ruleId = 'N/D';
              $idKeys = ['rowid','id','rule_id','pk'];
              foreach ($idKeys as $k) {
                  if (isset($vars[$k]) && $vars[$k] !== null && $vars[$k] !== '') {
                      $ruleId = $vars[$k];
                      break;
                  }
              }
            ?>
            <table class="tax-table">
              <tr><td>Nome da Regra</td><td><strong><?=h($ruleLabel)?></strong></td></tr>
              <tr><td>ID da Regra</td><td><strong><?=h($ruleId)?></strong></td></tr>
              <?php if(isset($vars['cfop']) && $vars['cfop']): ?><tr><td>CFOP</td><td><strong><?=h($vars['cfop'])?></strong></td></tr><?php endif; ?>
              <?php if(isset($vars['ncm']) && $vars['ncm']): ?><tr><td>NCM</td><td><?=h($vars['ncm'])?></td></tr><?php endif; ?>
              <?php if(isset($vars['uf_origin'])): ?><tr><td>UF Origem</td><td><?=h($vars['uf_origin'])?></td></tr><?php endif; ?>
              <?php if(isset($vars['uf_dest'])): ?><tr><td>UF Destino</td><td><?=h($vars['uf_dest'])?></td></tr><?php endif; ?>
              <?php if(isset($vars['icms_cred_aliq']) && $vars['icms_cred_aliq'] > 0): ?><tr><td>Crédito ICMS</td><td><?=number_format($vars['icms_cred_aliq'],2,',','.')?>%</td></tr><?php endif; ?>
              <?php if(isset($vars['icms_st_mva']) && $vars['icms_st_mva'] > 0): ?><tr><td>MVA ST</td><td><?=number_format($vars['icms_st_mva'],4,',','.')?>%</td></tr><?php endif; ?>
              <?php if(isset($vars['icms_st_aliq']) && $vars['icms_st_aliq'] > 0): ?><tr><td>Alíquota ST</td><td><?=number_format($vars['icms_st_aliq'],2,',','.')?>%</td></tr><?php endif; ?>
              <?php if(isset($vars['pis_aliq']) && $vars['pis_aliq'] > 0): ?><tr><td>PIS</td><td><?=number_format($vars['pis_aliq'],2,',','.')?>%</td></tr><?php endif; ?>
              <?php if(isset($vars['cofins_aliq']) && $vars['cofins_aliq'] > 0): ?><tr><td>COFINS</td><td><?=number_format($vars['cofins_aliq'],2,',','.')?>%</td></tr><?php endif; ?>
            </table>
          <?php else: ?>
            <div class="section-title">Regra Fiscal</div>
            <div style="padding:12px;background:#f7fafc;border-radius:6px;color:var(--muted);font-size:13px">
              Nenhuma regra fiscal aplicada (usando valores manuais do formulário)
            </div>
          <?php endif; ?>
        <?php else: ?>
          <div class="empty-state">
            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
            <div>Preencha o formulário e clique em <strong>Executar Teste</strong> para ver o resultado.</div>
          </div>
        <?php endif; ?>
      </div>
    </aside>
  </div>

  <div style="margin-top:20px;padding:16px;background:var(--card);border-radius:10px;border:1px solid var(--border)">
    <div style="font-size:13px;color:var(--muted)">
      💡 <strong>Observação:</strong> Esta ferramenta usa diretamente a função <code style="background:#f0f4f8;padding:2px 6px;border-radius:4px">determinarCSOSN()</code> do arquivo de emissão. Não altera dados no banco de dados.
    </div>
  </div>
</div>
</body>
</html>
