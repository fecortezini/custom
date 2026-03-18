<?php
/**
 * Formulário de Emissão de MDF-e
 * Manifesto Eletrônico de Documentos Fiscais
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', 'Off');
ini_set('log_errors', '1');

// Carrega as configurações do Dolibarr
require_once '../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formfile.class.php';
require_once DOL_DOCUMENT_ROOT.'/custom/labapp/lib/ibge_utils.php'; // Função de busca de dados do IBGE



$langs->loadLangs(array("admin", "companies", "products"));

$action = GETPOST('action', 'aZ09');

// Carrega empresa (mysoc)
if (empty($mysoc->id)) {
    $mysoc->fetch(0);
}
$ruaEmitente = $conf->global->MAIN_INFO_RUA;
$numeroEmitente = getDolGlobalInt('MAIN_INFO_NUMERO');
$bairroEmitente = $conf->global->MAIN_INFO_BAIRRO;
$nomeFantasia = $conf->global->MAIN_INFO_NOME_FANTASIA;

// Dados da empresa (emitente) - CORRIGIDO: garantir extrafields completos
$extrafieldsEmitente = [];
if (!empty($mysoc->array_options)) {
    foreach ($mysoc->array_options as $k => $v) {
        $extrafieldsEmitente[str_replace('options_', '', $k)] = $v;
    }
}

$dadosEmitente = [
        'id' => $mysoc->id,
        'nome' => $mysoc->name,
        'cnpj' => $mysoc->idprof1,
        'ie' => $mysoc->idprof2 ?? '',
        'nome_fantasia' => $mysoc->name_alias ?? '',
        'prestadorIM' => $prestadorIM,
        'municipio' => $mysoc->town, // CAMPO PRINCIPAL para busca
        'town' => $mysoc->town,
        'rua' => $mysoc->address,
        'bairro' => $mysoc->array_options['options_bairro'] ?? '',
        'numero' => $mysoc->array_options['options_numero_de_endereco'] ?? '',
        'uf' => $mysoc->state_code,
        'cep' => $mysoc->zip,
        'telefone' => $mysoc->phone ?? '',
        'email' => $mysoc->email ?? '',
        'extrafields' => $extrafieldsEmitente // ADICIONADO: passa todos extrafields
];
// Arrays de apoio
$ufs = array(
    'AC' => 'Acre', 'AL' => 'Alagoas', 'AP' => 'Amapá', 'AM' => 'Amazonas',
    'BA' => 'Bahia', 'CE' => 'Ceará', 'DF' => 'Distrito Federal', 'ES' => 'Espírito Santo',
    'GO' => 'Goiás', 'MA' => 'Maranhão', 'MT' => 'Mato Grosso', 'MS' => 'Mato Grosso do Sul',
    'MG' => 'Minas Gerais', 'PA' => 'Pará', 'PB' => 'Paraíba', 'PR' => 'Paraná',
    'PE' => 'Pernambuco', 'PI' => 'Piauí', 'RJ' => 'Rio de Janeiro', 'RN' => 'Rio Grande do Norte',
    'RS' => 'Rio Grande do Sul', 'RO' => 'Rondônia', 'RR' => 'Roraima', 'SC' => 'Santa Catarina',
    'SP' => 'São Paulo', 'SE' => 'Sergipe', 'TO' => 'Tocantins'
);

$codUFs = array(
    'AC' => '12', 'AL' => '27', 'AP' => '16', 'AM' => '13', 'BA' => '29', 'CE' => '23',
    'DF' => '53', 'ES' => '32', 'GO' => '52', 'MA' => '21', 'MT' => '51', 'MS' => '50',
    'MG' => '31', 'PA' => '15', 'PB' => '25', 'PR' => '41', 'PE' => '26', 'PI' => '22',
    'RJ' => '33', 'RN' => '24', 'RS' => '43', 'RO' => '11', 'RR' => '14', 'SC' => '42',
    'SP' => '35', 'SE' => '28', 'TO' => '17'
);

$tiposEmitente = array(
    '1' => 'Prestador de serviço de transporte',
    '2' => 'Transportador de Carga Própria',
    '3' => 'Prestador de serviço de transporte que emitirá CT-e Globalizado'
);

$tiposTransportador = array(
    '1' => 'ETC - Empresa de Transporte de Cargas',
    '2' => 'TAC - Transportador Autônomo de Cargas',
    '3' => 'CTC - Cooperativa de Transporte de Cargas'
);

$modais = array(
    '1' => 'Rodoviário',
    '2' => 'Aéreo',
    '3' => 'Aquaviário',
    '4' => 'Ferroviário'
);

$tiposRodado = array(
    '01' => 'Truck',
    '02' => 'Toco',
    '03' => 'Cavalo Mecânico',
    '04' => 'VAN',
    '05' => 'Utilitário',
    '06' => 'Outros'
);

$tiposCarroceria = array(
    '00' => 'Não aplicável',
    '01' => 'Aberta',
    '02' => 'Fechada/Baú',
    '03' => 'Granelera',
    '04' => 'Porta Container',
    '05' => 'Sider'
);

$tiposCarga = array(
    '01' => 'Granel sólido',
    '02' => 'Granel líquido',
    '03' => 'Frigorificada',
    '04' => 'Conteinerizada',
    '05' => 'Carga Geral',
    '06' => 'Neogranel',
    '07' => 'Perigosa (granel sólido)',
    '08' => 'Perigosa (granel líquido)',
    '09' => 'Perigosa (carga frigorificada)',
    '10' => 'Perigosa (conteinerizada)',
    '11' => 'Perigosa (carga geral)'
);

$unidadesCarga = array(
    '01' => 'KG',
    '02' => 'TON'
);

$tiposProprietario = array(
    '0' => 'TAC Agregado',
    '1' => 'TAC Independente',
    '2' => 'Outros'
);

$respSeguro = array(
    '1' => 'Emitente do MDF-e',
    '2' => 'Responsável pela contratação do serviço de transporte'
);

/*
 * Actions
 */
if ($action == 'emitir') {
    // Processar o formulário e chamar mdfe_processar.php
    // Os dados serão enviados via POST
    
    // Validações básicas
    $errors = array();
    
    // CNPJ ou CPF obrigatório (um dos dois)
    if (empty(GETPOST('cnpj_emit', 'alpha')) && empty(GETPOST('cpf_emit', 'alpha'))) {
        $errors[] = 'CNPJ ou CPF do Emitente é obrigatório';
    }
    if (empty(GETPOST('ie_emit', 'alpha'))) {
        $errors[] = 'Inscrição Estadual do Emitente é obrigatória';
    }
    if (empty(GETPOST('razao_social', 'alpha'))) {
        $errors[] = 'Razão Social é obrigatória';
    }
    
    if (empty($errors)) {
        // Redirecionar para processamento ou processar aqui
        // Por enquanto, vamos incluir o processamento
        include 'mdfe_processar.php';
        exit;
    } else {
        setEventMessages(null, $errors, 'errors');
    }
}

/*
 * View
 */
$form = new Form($db);

$title = 'Emissão de MDF-e';
$help_url = '';

llxHeader('', $title, $help_url);

print load_fiche_titre($title, '', 'object_mdfe@mdfe');

?>

<style>
.mdfe-form { max-width: 1400px; margin: 0 auto; }
.mdfe-section { 
    background: #fff; 
    border: 1px solid #ddd; 
    border-radius: 8px; 
    margin-bottom: 20px; 
    padding: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    overflow: hidden;
}
.mdfe-section h3 { 
    color: #333; 
    border-bottom: 2px solid #0073aa; 
    padding-bottom: 10px; 
    margin-bottom: 20px;
    font-size: 1.2em;
}
.mdfe-row { 
    display: flex; 
    flex-wrap: wrap; 
    gap: 15px; 
    margin-bottom: 15px;
    align-items: flex-end;
}
.mdfe-field { 
    flex: 1 1 200px;
    min-width: 150px;
    max-width: 100%;
    box-sizing: border-box;
}
.mdfe-field label { 
    display: block; 
    font-weight: bold; 
    margin-bottom: 5px; 
    color: #555;
    font-size: 0.85em;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.mdfe-field input, .mdfe-field select, .mdfe-field textarea { 
    width: 100%; 
    padding: 8px 10px; 
    border: 1px solid #ccc; 
    border-radius: 4px; 
    font-size: 13px;
    transition: border-color 0.3s;
    box-sizing: border-box;
}
.mdfe-field input:focus, .mdfe-field select:focus, .mdfe-field textarea:focus { 
    border-color: #0073aa; 
    outline: none;
    box-shadow: 0 0 5px rgba(0,115,170,0.3);
}
.mdfe-field.small { flex: 0 0 100px; min-width: 100px; max-width: 130px; }
.mdfe-field.medium { flex: 0 0 180px; min-width: 150px; max-width: 220px; }
.mdfe-field.large { flex: 1 1 280px; min-width: 200px; }
.mdfe-field.xlarge { flex: 1 1 100%; min-width: 100%; }

/* Fix para inputs number */
.mdfe-field input[type="number"] {
    -moz-appearance: textfield;
    appearance: textfield;
}
.mdfe-field input[type="number"]::-webkit-outer-spin-button,
.mdfe-field input[type="number"]::-webkit-inner-spin-button {
    -webkit-appearance: none;
    appearance: none;
    margin: 0;
}

/* Placeholder styling */
.mdfe-field input::placeholder {
    color: #999;
    font-size: 12px;
    font-style: italic;
}

.mdfe-subsection {
    background: #f9f9f9;
    border: 1px solid #e0e0e0;
    border-radius: 6px;
    padding: 15px;
    margin: 15px 0;
    overflow: hidden;
}
.mdfe-subsection h4 {
    color: #666;
    margin: 0 0 15px 0;
    font-size: 0.95em;
}
.mdfe-subsection h5 {
    color: #777;
    margin: 10px 0 8px 0;
    font-size: 0.9em;
}

.dynamic-list {
    border: 1px dashed #ccc;
    padding: 10px;
    margin: 10px 0;
    border-radius: 6px;
    background: #fafafa;
    min-height: 20px;
}
.dynamic-item {
    background: #fff;
    border: 1px solid #ddd;
    padding: 15px 40px 15px 15px;
    margin: 8px 0;
    border-radius: 4px;
    position: relative;
}
.btn-add {
    background: #28a745;
    color: white;
    border: none;
    padding: 6px 14px;
    border-radius: 4px;
    cursor: pointer;
    font-size: 13px;
}
.btn-add:hover { background: #218838; }
.btn-remove {
    background: #dc3545;
    color: white;
    border: none;
    padding: 4px 8px;
    border-radius: 4px;
    cursor: pointer;
    position: absolute;
    top: 8px;
    right: 8px;
    font-size: 14px;
    line-height: 1;
}
.btn-remove:hover { background: #c82333; }

.required::after {
    content: ' *';
    color: #dc3545;
}

/* Tabs */
.mdfe-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    margin-bottom: 20px;
    border-bottom: 2px solid #ddd;
    padding-bottom: 10px;
}
.mdfe-tab {
    padding: 10px 20px;
    background: #f0f0f0;
    border: 1px solid #ddd;
    border-radius: 4px 4px 0 0;
    cursor: pointer;
    font-weight: bold;
    transition: all 0.3s;
}
.mdfe-tab:hover { background: #e0e0e0; }
.mdfe-tab.active { 
    background: #0073aa; 
    color: white; 
    border-color: #0073aa;
}
.mdfe-tab-content { display: none; }
.mdfe-tab-content.active { display: block; }

.submit-area {
    text-align: center;
    padding: 20px;
    background: #f5f5f5;
    border-radius: 8px;
    margin-top: 20px;
}
.btn-submit {
    background: #0073aa;
    color: white;
    border: none;
    padding: 15px 40px;
    font-size: 16px;
    border-radius: 6px;
    cursor: pointer;
    font-weight: bold;
}
.btn-submit:hover { background: #005a87; }

/* Responsividade */
@media (max-width: 768px) {
    .mdfe-field.small, .mdfe-field.medium, .mdfe-field.large {
        flex: 1 1 100%;
        max-width: 100%;
        min-width: 100%;
    }
    .mdfe-tabs {
        flex-direction: column;
    }
    .mdfe-tab {
        text-align: center;
    }
}
</style>

<div class="mdfe-form">
    <form method="POST" action="<?php echo $_SERVER['PHP_SELF']; ?>" id="mdfeForm">
        <input type="hidden" name="token" value="<?php echo newToken(); ?>">
        <input type="hidden" name="action" value="emitir">

        <!-- Tabs de Navegação -->
        <div class="mdfe-tabs">
            <div class="mdfe-tab active" data-tab="identificacao">1. Identificação</div>
            <div class="mdfe-tab" data-tab="emitente">2. Emitente</div>
            <div class="mdfe-tab" data-tab="rodoviario">3. Modal Rodoviário</div>
            <div class="mdfe-tab" data-tab="documentos">4. Documentos</div>
            <div class="mdfe-tab" data-tab="seguro">5. Seguro</div>
            <div class="mdfe-tab" data-tab="totais">6. Totais</div>
            <div class="mdfe-tab" data-tab="adicionais">7. Informações Adicionais</div>
        </div>

        <!-- Tab 1: Identificação -->
        <div class="mdfe-tab-content active" id="tab-identificacao">
            <div class="mdfe-section">
                <h3>Identificação do MDF-e</h3>
                
                <div class="mdfe-row">
                    <div class="mdfe-field small">
                        <label class="required">UF Emissão</label>
                        <select name="cUF" required>
                            <?php foreach($ufs as $sigla => $nome): ?>
                                <option value="<?php echo $codUFs[$sigla]; ?>" <?php echo ($sigla == $dadosEmitente['uf'] ? 'selected' : ''); ?>><?php echo $sigla; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mdfe-field large">
                        <label class="required">Tipo Emitente</label>
                        <select name="tpEmit" id="tpEmit" required onchange="handleTpEmitChange()">
                            <?php foreach($tiposEmitente as $cod => $desc): ?>
                                <option value="<?php echo $cod; ?>"><?php echo $desc; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mdfe-field large">
                        <label>Tipo Transportador</label>
                        <select name="tpTransp">
                            <option value="">Não informar</option>
                            <?php foreach($tiposTransportador as $cod => $desc): ?>
                                <option value="<?php echo $cod; ?>"><?php echo $desc; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="mdfe-row">
                    <div class="mdfe-field medium">
                        <label class="required">Modal</label>
                        <select name="modal" id="modal" required onchange="handleModalChange()">
                            <?php foreach($modais as $cod => $desc): ?>
                                <option value="<?php echo $cod; ?>"><?php echo $desc; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mdfe-field medium">
                        <label class="required">Tipo Emissão</label>
                        <select name="tpEmis" required>
                            <option value="1">Normal</option>
                            <option value="2">Contingência</option>
                        </select>
                    </div>
                </div>

                <div class="mdfe-row">
                    <!-- <div class="mdfe-field medium">
                        <label class="required">Data/Hora Emissão</label>
                        <input type="datetime-local" name="dhEmi" id="dhEmi" required>
                    </div> -->
                    <div class="mdfe-field medium">
                        <label>Data/Hora Início Viagem</label>
                        <input type="datetime-local" name="dhIniViagem" value="<?php echo date('Y-m-d\TH:i'); ?>">
                    </div>
                    <div class="mdfe-field small">
                        <label class="required">UF Início</label>
                        <select name="UFIni" required>
                            <?php foreach($ufs as $sigla => $nome): ?>
                                <option value="<?php echo $sigla; ?>" <?php echo ($sigla == $dadosEmitente['uf'] ? 'selected' : ''); ?>><?php echo $sigla; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mdfe-field small">
                        <label class="required">UF Fim</label>
                        <select name="UFFim" required>
                            <?php foreach($ufs as $sigla => $nome): ?>
                                <option value="<?php echo $sigla; ?>" <?php echo ($sigla == $dadosEmitente['uf'] ? 'selected' : ''); ?>><?php echo $sigla; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="mdfe-row">
                    <div class="mdfe-field small">
                        <label>Canal Verde</label>
                        <select name="indCanalVerde">
                            <option value="">Não</option>
                            <option value="1">Sim</option>
                        </select>
                    </div>
                    <div class="mdfe-field small">
                        <label>Carga Posterior</label>
                        <select name="indCarregaPosterior">
                            <option value="">Não</option>
                            <option value="1">Sim</option>
                        </select>
                    </div>
                </div>
                
                <!-- Municípios de Carregamento -->
                <div class="mdfe-subsection">
                    <h4>Municípios de Carregamento</h4>
                    <div id="municipios-carrega" class="dynamic-list">
                        <div class="dynamic-item" data-index="0">
                            <button type="button" class="btn-remove" onclick="removeMunCarrega(this)">×</button>
                            <div class="mdfe-row">
                                <div class="mdfe-field medium">
                                    <label>Estado</label>
                                   <select name="UFCarregamento[0]" id="selUFCarrega-0" onchange="formCarregarCidades(this,0)" required>
                                        <?php foreach($ufs as $sigla => $nome): ?>
                                            <option value="<?php echo $sigla; ?>" <?php echo ($sigla == $dadosEmitente['uf'] ? 'selected' : ''); ?>><?php echo $sigla; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="mdfe-field large">
                                    <label class="required">Município</label>
                                    <select name="mun_carrega[0][xMunCarrega]" id="selMunCarrega-0" required disabled>
                                        <option value="">Selecione a UF primeiro</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    <button type="button" class="btn-add" onclick="addMunCarrega()">+ Adicionar Município de Carregamento</button>
                </div>

                <!-- UFs de Percurso -->
                <div class="mdfe-subsection">
                    <h4>UFs de Percurso (opcional)</h4>
                    <div id="ufs-percurso" class="dynamic-list">
                        <!-- Itens dinâmicos serão adicionados aqui -->
                    </div>
                    <button type="button" class="btn-add" onclick="addUFPercurso()">+ Adicionar UF de Percurso</button>
                </div>
            </div>
        </div>

        <!-- Tab 2: Emitente -->
        <div class="mdfe-tab-content" id="tab-emitente">
            <div class="mdfe-section">
                <h3>Dados do Emitente</h3>
                
                <div class="mdfe-row">
                    <div class="mdfe-field medium">
                        <label>CNPJ <small style="color:#666;">(ou CPF)</small></label>
                        <input type="text" name="cnpj_emit" id="cnpj_emit" maxlength="14" value="<?php echo htmlspecialchars($dadosEmitente['cnpj'] ?? ''); ?>" placeholder="Ex: 12345678000199" onchange="handleDocEmitChange()">
                    </div>
                    <div class="mdfe-field medium">
                        <label>CPF <small style="color:#666;">(ou CNPJ)</small></label>
                        <input type="text" name="cpf_emit" id="cpf_emit" maxlength="11" placeholder="Ex: 12345678901" onchange="handleDocEmitChange()">
                    </div>
                    <div class="mdfe-field medium">
                        <label class="required">Inscrição Estadual</label>
                        <input type="text" name="ie_emit" maxlength="14" value="<?php echo htmlspecialchars($dadosEmitente['ie'] ?? ''); ?>" required placeholder="Ex: 123456789">
                    </div>
                </div>

                <div class="mdfe-row">
                    <div class="mdfe-field large">
                        <label class="required">Razão Social</label>
                        <input type="text" name="razao_social" maxlength="60" value="<?php echo htmlspecialchars(strtoupper($dadosEmitente['nome'] ?? '')); ?>" required placeholder="Ex: TRANSPORTES BRASIL LTDA">
                    </div>
                    <div class="mdfe-field large">
                        <label>Nome Fantasia</label>
                        <input type="text" name="nome_fantasia" maxlength="60" value="<?php echo htmlspecialchars(strtoupper($nomeFantasia ?? '')); ?>" placeholder="Ex: TRANS BRASIL">
                    </div>
                </div>

                <div class="mdfe-subsection">
                    <h4>Endereço do Emitente</h4>
                    <div class="mdfe-row">
                        <div class="mdfe-field large">
                            <label class="required">Logradouro</label>
                            <input type="text" name="xLgr" maxlength="60" value="<?php echo htmlspecialchars(mb_strtoupper($ruaEmitente ?? '', 'UTF-8')); ?>" required placeholder="Ex: RUA DAS FLORES">
                        </div>
                        <div class="mdfe-field small">
                            <label class="required">Número</label>
                            <input type="text" name="nro" maxlength="60" value="<?php echo htmlspecialchars($numeroEmitente ?? ''); ?>" required placeholder="Ex: 123">
                        </div>
                        <div class="mdfe-field medium">
                            <label>Complemento</label>
                            <input type="text" name="xCpl" maxlength="60" placeholder="Ex: SALA 01">
                        </div>
                    </div>

                    <div class="mdfe-row">
                        <div class="mdfe-field medium">
                            <label class="required">Bairro</label>
                            <input type="text" name="xBairro" maxlength="60" value="<?php echo htmlspecialchars(mb_strtoupper($bairroEmitente ?? '', 'UTF-8')); ?>" required placeholder="Ex: CENTRO">
                        </div>
                        <div class="mdfe-field large">
                            <label class="required">Nome Município</label>
                            <input type="text" name="xMun_emit" maxlength="60" value="<?php echo htmlspecialchars(strtoupper($dadosEmitente['municipio'] ?? '')); ?>" required placeholder="Ex: SAO PAULO">
                        </div>
                    </div>

                    <div class="mdfe-row">
                        <div class="mdfe-field small">
                            <label class="required">CEP</label>
                            <input type="text" name="CEP_emit" maxlength="8" value="<?php echo htmlspecialchars(preg_replace('/[^0-9]/', '', $dadosEmitente['cep'] ?? '')); ?>" required placeholder="Ex: 01310100">
                        </div>
                        <div class="mdfe-field small">
                            <label class="required">UF</label>
                            <select name="UF_emit" required>
                                <?php foreach($ufs as $sigla => $nome): ?>
                                    <option value="<?php echo $sigla; ?>" <?php echo ($sigla == ($dadosEmitente['uf'] ?? '')) ? 'selected' : ''; ?>><?php echo $sigla; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mdfe-field medium">
                            <label>Telefone</label>
                            <input type="text" name="fone_emit" maxlength="12" value="<?php echo htmlspecialchars(preg_replace('/[^0-9]/', '', $dadosEmitente['telefone'] ?? '')); ?>" placeholder="Ex: 11999998888">
                        </div>
                        <div class="mdfe-field large">
                            <label>E-mail</label>
                            <input type="email" name="email_emit" maxlength="60" value="<?php echo htmlspecialchars($dadosEmitente['email'] ?? ''); ?>" placeholder="Ex: contato@empresa.com.br">
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab 3: Modal Rodoviário -->
        <div class="mdfe-tab-content" id="tab-rodoviario">
            <div class="mdfe-section" id="section-rodoviario">
                <h3>Informações do Modal Rodoviário</h3>
                <p id="aviso-modal-nao-rodo" style="display:none; color:#856404; background:#fff3cd; padding:10px; border-radius:4px; margin-bottom:15px;">⚠️ Esta aba é específica para Modal Rodoviário. O modal selecionado é diferente.</p>

                <!-- infANTT - Grupo de informações para Agência Reguladora -->
                <div class="mdfe-subsection">
                    <h4>Grupo de informações para Agência Reguladora</h4>
                    <div class="mdfe-row">
                        <div class="mdfe-field medium">
                            <label>RNTRC <small style="color:#888;">(8 dígitos)</small></label>
                            <input type="text" name="RNTRC" maxlength="8" pattern="[0-9]{8}" placeholder="Ex: 12345678" title="Registro Nacional de Transportadores Rodoviários de Carga">
                        </div>
                    </div>

                    <!-- CIOT -->
                    <div class="mdfe-subsection" style="margin-top:10px;">
                        <h5>CIOT - Código Identificador da Operação de Transporte</h5>
                        <small style="color:#666; display:block; margin-bottom:10px;">CPF ou CNPJ do responsável pela geração do CIOT (mutuamente exclusivos)</small>
                        <div id="ciot-list" class="dynamic-list">
                            <!-- Itens dinâmicos -->
                        </div>
                        <button type="button" class="btn-add" onclick="addCIOT()">+ Adicionar CIOT</button>
                    </div>

                    <!-- Vale Pedágio -->
                    <div class="mdfe-subsection" style="margin-top:10px;">
                        <h5>Vale Pedágio</h5>
                        <small style="color:#666; display:block; margin-bottom:10px;">Informações dos dispositivos do Vale Pedágio</small>
                        <div id="vale-pedagio-list" class="dynamic-list">
                            <!-- Itens dinâmicos -->
                        </div>
                        <button type="button" class="btn-add" onclick="addValePedagio()">+ Adicionar Vale Pedágio</button>
                    </div>

                    <!-- Contratantes -->
                    <div class="mdfe-subsection" style="margin-top:10px;">
                        <h5>Contratantes do Serviço de Transporte <span id="aviso-contratante-obrig" style="display:none; color:#dc3545; font-size:0.9em;">(Obrigatório quando Tipo Emitente for Prestador de serviço de transporte)</span></h5>
                        <small style="color:#666; display:block; margin-bottom:10px;">Informar CPF ou CNPJ</small>
                        <div id="contratantes-list" class="dynamic-list">
                            <!-- Itens dinâmicos -->
                        </div>
                        <button type="button" class="btn-add" onclick="addContratante()">+ Adicionar Contratante</button>
                    </div>
                </div>

                <!-- veicTracao - Dados do Veículo com a Tração -->
                <div class="mdfe-subsection">
                    <h4>Veículo de Tração</h4>
                    <div class="mdfe-row">
                        <div class="mdfe-field small">
                            <label>Cód. Interno</label>
                            <input type="text" name="veic_tracao[cInt]" maxlength="10" placeholder="Ex: 001">
                        </div>
                        <div class="mdfe-field small">
                            <label class="required">Placa</label>
                            <input type="text" name="veic_tracao[placa]" maxlength="7" required placeholder="Ex: ABC1D23">
                        </div>
                        <div class="mdfe-field medium">
                            <label>RENAVAM <small style="color:#888;">(9-11 dígitos)</small></label>
                            <input type="text" name="veic_tracao[RENAVAM]" maxlength="11" minlength="9" placeholder="Ex: 12345678901">
                        </div>
                    </div>
                    <div class="mdfe-row">
                        <div class="mdfe-field small">
                            <label class="required">Tara (kg)</label>
                            <input type="number" name="veic_tracao[tara]" min="1" max="999999" required placeholder="Ex: 8000">
                        </div>
                        <div class="mdfe-field small">
                            <label>Cap. (kg)</label>
                            <input type="number" name="veic_tracao[capKG]" min="1" max="999999" placeholder="Ex: 15000">
                        </div>
                        <div class="mdfe-field small">
                            <label>Cap. (m³)</label>
                            <input type="number" name="veic_tracao[capM3]" min="1" max="999" placeholder="Ex: 50">
                        </div>
                    </div>
                    <div class="mdfe-row">
                        <div class="mdfe-field medium">
                            <label class="required">Tipo Rodado</label>
                            <select name="veic_tracao[tpRod]" required>
                                <?php foreach($tiposRodado as $cod => $desc): ?>
                                    <option value="<?php echo $cod; ?>"><?php echo $desc; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mdfe-field medium">
                            <label class="required">Tipo Carroceria</label>
                            <select name="veic_tracao[tpCar]" required>
                                <?php foreach($tiposCarroceria as $cod => $desc): ?>
                                    <option value="<?php echo $cod; ?>"><?php echo $desc; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mdfe-field small">
                            <label class="required">UF Licenciamento</label>
                            <select name="veic_tracao[UF]" required>
                                <?php foreach($ufs as $sigla => $nome): ?>
                                    <option value="<?php echo $sigla; ?>"><?php echo $sigla; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Condutores (1-10) -->
                    <div class="mdfe-subsection" style="margin-top:10px;">
                        <h5>Condutores <small style="color:#888;">(1 a 10 condutores obrigatórios)</small></h5>
                        <div id="condutores-list" class="dynamic-list">
                            <div class="dynamic-item" data-index="0">
                                <button type="button" class="btn-remove" onclick="removeCondutor(this)">×</button>
                                <div class="mdfe-row">
                                    <div class="mdfe-field large">
                                        <label class="required">Nome do Condutor</label>
                                        <input type="text" name="condutores[0][xNome]" maxlength="60" required placeholder="Ex: JOSE DA SILVA">
                                    </div>
                                    <div class="mdfe-field medium">
                                        <label class="required">CPF</label>
                                        <input type="text" name="condutores[0][CPF]" maxlength="11" required placeholder="Ex: 12345678901">
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn-add" onclick="addCondutor()">+ Adicionar Condutor</button>
                    </div>

                    <!-- Proprietário do Veículo de Tração (prop) -->
                    <div class="mdfe-subsection" style="margin-top:10px;">
                        <h5>Proprietário do Veículo de Tração <small style="color:#888;">(só se diferente do emitente)</small></h5>
                        <small style="color:#666; display:block; margin-bottom:10px;">Preencher CPF ou CNPJ (mutuamente exclusivos)</small>
                        <div class="mdfe-row">
                            <div class="mdfe-field medium">
                                <label>CPF</label>
                                <input type="text" name="prop_tracao[CPF]" maxlength="11" placeholder="Ex: 12345678901">
                            </div>
                            <div class="mdfe-field medium">
                                <label>CNPJ</label>
                                <input type="text" name="prop_tracao[CNPJ]" maxlength="14" placeholder="Ex: 12345678000199">
                            </div>
                            <div class="mdfe-field medium">
                                <label>RNTRC</label>
                                <input type="text" name="prop_tracao[RNTRC]" maxlength="8" placeholder="Ex: 12345678">
                            </div>
                        </div>
                        <div class="mdfe-row">
                            <div class="mdfe-field large">
                                <label>Razão Social/Nome</label>
                                <input type="text" name="prop_tracao[xNome]" maxlength="60" placeholder="Ex: TRANSPORTADORA XYZ LTDA">
                            </div>
                            <div class="mdfe-field medium">
                                <label>Inscrição Estadual</label>
                                <input type="text" name="prop_tracao[IE]" maxlength="14" placeholder="Ex: 123456789">
                            </div>
                        </div>
                        <div class="mdfe-row">
                            <div class="mdfe-field small">
                                <label>UF</label>
                                <select name="prop_tracao[UF]">
                                    <option value="">-</option>
                                    <?php foreach($ufs as $sigla => $nome): ?>
                                        <option value="<?php echo $sigla; ?>"><?php echo $sigla; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mdfe-field medium">
                                <label>Tipo Proprietário</label>
                                <select name="prop_tracao[tpProp]">
                                    <option value="">-</option>
                                    <?php foreach($tiposProprietario as $cod => $desc): ?>
                                        <option value="<?php echo $cod; ?>"><?php echo $desc; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- veicReboque - Dados dos reboques (0-3) -->
                <div class="mdfe-subsection">
                    <h4>Veículos Reboque <small style="color:#888;">(até 3 reboques)</small></h4>
                    <div id="reboques-list" class="dynamic-list">
                        <!-- Itens dinâmicos -->
                    </div>
                    <button type="button" class="btn-add" onclick="addReboque()">+ Adicionar Reboque</button>
                </div>

                <!-- codAgPorto - Código de Agendamento no porto -->
                <div class="mdfe-subsection">
                    <h4>Código de Agendamento no Porto</h4>
                    <div class="mdfe-row">
                        <div class="mdfe-field medium">
                            <label>Código Agendamento</label>
                            <input type="text" name="codAgPorto" maxlength="16" placeholder="Ex: PORTO123456">
                        </div>
                    </div>
                </div>

                <!-- lacRodo - Lacres -->
                <div class="mdfe-subsection">
                    <h4>Lacres do Modal Rodoviário</h4>
                    <div id="lacres-rodo-list" class="dynamic-list">
                        <!-- Itens dinâmicos -->
                    </div>
                    <button type="button" class="btn-add" onclick="addLacreRodo()">+ Adicionar Lacre</button>
                </div>
            </div>
        </div>

        <!-- Tab 4: Documentos -->
        <div class="mdfe-tab-content" id="tab-documentos">
            <div id="aviso-carga-posterior" class="mdfe-section" style="display:none; background:#fff3cd; border-color:#ffc107;">
                <p style="margin:0; color:#856404;">
                    <strong>&#9888; Carga Posterior:</strong> Os documentos fiscais (NF-e / CT-e) não devem ser inseridos agora. Eles poderão ser incluídos no MDF-e após a emissão.
                </p>
            </div>
            <div class="mdfe-section">
                <h3>Municípios de Descarga e Documentos Fiscais</h3>
                
                <div id="municipios-descarga" class="dynamic-list">
                    <div class="dynamic-item mun-descarga-item" data-index="0">
                        <button type="button" class="btn-remove" onclick="removeMunDescarga(this)">×</button>
                        
                        <div class="mdfe-row">
                            <div class="mdfe-field medium">
                                <label>Estado</label>
                                <select name="UFDescarga[0]" id="selUFDescarga-0" onchange="formCarregarCidadesDescarga(this, 0)" required>
                                    <?php foreach($ufs as $sigla => $nome): ?>
                                        <option value="<?php echo $sigla; ?>" <?php echo ($sigla == $dadosEmitente['uf'] ? 'selected' : ''); ?>><?php echo $sigla; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mdfe-field large">
                                <label class="required">Município</label>
                                <select name="mun_descarga[0][xMunDescarga]" id="selMunDescarga-0" required disabled>
                                    <option value="">Selecione a UF primeiro</option>
                                </select>
                            </div>
                        </div>

                        <!-- CT-e vinculados (tpEmit=1 Prestador serviço transporte) -->
                        <div class="mdfe-subsection cte-section" id="cte-section-0">
                            <h4>CT-e Vinculados <small style="color:#666;">(Tipo Emitente = 1)</small></h4>
                            <div class="cte-list" data-mun="0">
                                <!-- Itens dinâmicos -->
                            </div>
                            <button type="button" class="btn-add" onclick="addCTe(0)">+ Adicionar CT-e</button>
                        </div>

                        <!-- NF-e vinculadas (tpEmit=2 ou 3 Carga própria) -->
                        <div class="mdfe-subsection nfe-section" id="nfe-section-0" style="display:none;">
                            <h4>NF-e Vinculadas <small style="color:#666;">(Tipo Emitente = 2 ou 3)</small></h4>
                            <div class="nfe-list" data-mun="0">
                                <!-- Itens dinâmicos -->
                            </div>
                            <button type="button" class="btn-add" onclick="addNFe(0)">+ Adicionar NF-e</button>
                        </div>
                    </div>
                </div>
                <button type="button" class="btn-add" onclick="addMunDescarga()">+ Adicionar Município de Descarga</button>
            </div>
        </div>

        <!-- Tab 5: Seguro -->
        <div class="mdfe-tab-content" id="tab-seguro">
            <div class="mdfe-section">
                <h3>Informações de Seguro</h3>
                <p style="color:#666; font-size:0.9em; margin-bottom:15px;">
                    <strong>Nota:</strong> Para o modal Rodoviário, o seguro é obrigatório conforme Lei 11.442/07 (RCTRC).
                </p>
                
                <div id="seguros-list" class="dynamic-list">
                    <!-- Itens dinâmicos -->
                </div>
                <button type="button" class="btn-add" onclick="addSeguro()">+ Adicionar Seguro</button>
            </div>
        </div>

        <!-- Tab 6: Totais -->
        <div class="mdfe-tab-content" id="tab-totais">
            <div class="mdfe-section">
                <h3>Totalizadores da Carga</h3>
                
                <div class="mdfe-row">
                    <div class="mdfe-field medium">
                        <label class="required">Valor Total (R$)</label>
                        <input type="text" name="vCarga" required placeholder="0,00" data-money>
                    </div>
                    <div class="mdfe-field small">
                        <label class="required">Unidade</label>
                        <select name="cUnid" required>
                            <?php foreach($unidadesCarga as $cod => $desc): ?>
                                <option value="<?php echo $cod; ?>"><?php echo $desc; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mdfe-field medium">
                        <label class="required">Peso Bruto (kg)</label>
                        <input type="text" name="qCarga" required placeholder="Ex: 15000.0000">
                    </div>
                </div>

                <!-- Produto Predominante -->
                <div class="mdfe-subsection">
                    <h4>Produto Predominante</h4>
                    <div class="mdfe-row">
                        <div class="mdfe-field medium">
                            <label>Tipo de Carga</label>
                            <select name="prodPred[tpCarga]">
                                <option value="">-</option>
                                <?php foreach($tiposCarga as $cod => $desc): ?>
                                    <option value="<?php echo $cod; ?>"><?php echo $desc; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mdfe-field large">
                            <label>Descrição do Produto</label>
                            <input type="text" name="prodPred[xProd]" maxlength="120" placeholder="Ex: SOJA EM GRAOS">
                        </div>
                    </div>
                    <div class="mdfe-row">
                        <div class="mdfe-field medium">
                            <label>Código EAN/GTIN</label>
                            <input type="text" name="prodPred[cEAN]" maxlength="14" placeholder="Ex: 7891234567890">
                        </div>
                        <div class="mdfe-field medium">
                            <label>NCM</label>
                            <input type="text" name="prodPred[NCM]" maxlength="8" placeholder="Ex: 12019000">
                        </div>
                    </div>
                </div>
                
                <!-- Informações de Lotação (infLotacao) -->
                <div class="mdfe-subsection" id="secao-lotacao">
                    <h4>Informações de Lotação</h4>
                    <div class="mdfe-row">
                        <div class="mdfe-field">
                            <label style="display:flex; align-items:center; gap:10px; cursor:pointer;">
                                <input type="checkbox" id="chkUsaLotacao" name="prodPred[usaLotacao]" value="1" onchange="toggleLotacao(this)" style="width:18px; height:18px;">
                                <span>Informar dados de Lotação (carga lotação)</span>
                            </label>
                            <small style="color:#666; display:block; margin-top:5px;">Obrigatório quando houver apenas 1 documento fiscal (NF-e ou CT-e) vinculado ao MDF-e.</small>
                        </div>
                    </div>
                    <div id="info-lotacao" style="display: none; margin-top:15px; padding:15px; background:#f8f9fa; border-radius:5px;">
                        <h5 style="color:#333; margin: 0 0 15px 0; border-bottom:1px solid #ddd; padding-bottom:10px;">Local de Carregamento</h5>
                        <div class="mdfe-row">
                            <div class="mdfe-field medium">
                                <label id="lblCepCarrega">CEP Carregamento</label>
                                <input type="text" id="cepCarrega" name="prodPred[infLotacao][infLocalCarrega][CEP]" maxlength="8" placeholder="Ex: 01310100">
                            </div>
                            <div class="mdfe-field medium">
                                <label>Latitude</label>
                                <input type="text" name="prodPred[infLotacao][infLocalCarrega][latitude]" placeholder="Ex: -23.550520">
                            </div>
                            <div class="mdfe-field medium">
                                <label>Longitude</label>
                                <input type="text" name="prodPred[infLotacao][infLocalCarrega][longitude]" placeholder="Ex: -46.633308">
                            </div>
                        </div>
                        <!-- 📍  -->
                        <h5 style="color:#333; margin: 20px 0 15px 0; border-bottom:1px solid #ddd; padding-bottom:10px;">Local de Descarregamento</h5> 
                        <div class="mdfe-row">
                            <div class="mdfe-field medium">
                                <label id="lblCepDescarrega">CEP Descarregamento</label>
                                <input type="text" id="cepDescarrega" name="prodPred[infLotacao][infLocalDescarrega][CEP]" maxlength="8" placeholder="Ex: 30130000">
                            </div>
                            <div class="mdfe-field medium">
                                <label>Latitude</label>
                                <input type="text" name="prodPred[infLotacao][infLocalDescarrega][latitude]" placeholder="Ex: -19.912998">
                            </div>
                            <div class="mdfe-field medium">
                                <label>Longitude</label>
                                <input type="text" name="prodPred[infLotacao][infLocalDescarrega][longitude]" placeholder="Ex: -43.940933">
                            </div>
                        </div>
                    </div>
                </div>

                    <!-- Informações de Pagamento do Frete (infPag) -->
                <div class="mdfe-section">
                    <h3>Informações de Pagamento do Frete</h3>

                    <div id="pagamentos-list" class="dynamic-list">
                        <!-- Itens dinâmicos -->
                    </div>
                    <button type="button" class="btn-add" onclick="addPagamento()">+ Adicionar Informação de Pagamento</button>
                </div>
            </div>

                <!-- Lacres -->
                <div class="mdfe-subsection">
                    <h4>Lacres do MDF-e</h4>
                    <div id="lacres-list" class="dynamic-list">
                        <!-- Itens dinâmicos -->
                    </div>
                    <button type="button" class="btn-add" onclick="addLacre()">+ Adicionar Lacre</button>
                </div>
            </div>

        <!-- Tab 7: Informações Adicionais -->
        <div class="mdfe-tab-content" id="tab-adicionais">
            <div class="mdfe-section">
                <h3>Informações Adicionais</h3>
                
                <div class="mdfe-row">
                    <div class="mdfe-field large">
                        <label>Informações Complementares</label>
                        <textarea name="infCpl" rows="4" maxlength="5000" placeholder="Informações de interesse do contribuinte"></textarea>
                    </div>
                </div>
                <div class="mdfe-row">
                    <div class="mdfe-field large">
                        <label>Informações Adicionais de Interesse do Fisco</label>
                        <textarea name="infAdFisco" rows="4" maxlength="2000" placeholder="Informações de interesse do fisco"></textarea>
                    </div>
                </div>

                <!-- Autorizados para Download -->
                <div class="mdfe-subsection">
                    <h4>Autorizados para Download do XML</h4>
                    <div id="autorizados-list" class="dynamic-list">
                        <!-- Itens dinâmicos -->
                    </div>
                    <button type="button" class="btn-add" onclick="addAutorizado()">+ Adicionar Autorizado</button>
                </div>
            </div>

        </div>

        <!-- Botões de Ação -->
        <div class="submit-area">
            <button type="submit" class="btn-submit">Emitir MDF-e</button>
        </div>
    </form>
</div>

<script>
// Controle de Tabs
var _formCidadesCache={};

function formCarregarCidades(selectedUf, index){
    var uf = selectedUf.value;
    var munSel = document.getElementById('selMunCarrega-' + index);
    if(!munSel){
        return;
    }
    if(!uf){
        munSel.innerHTML = '<option value="">Selecione</option>';
        munSel.disabled = true;
        return;
    }
     // Cache — evita novo fetch se a UF já foi buscada antes
    if (_formCidadesCache[uf]) {
        _formPreencherMunicipios(munSel, _formCidadesCache[uf]);
        return;
    }
    munSel.innerHTML = '<option value="">Carregando...</option>';
    munSel.disabled = true;
    // Mesmo endpoint usado pelo incDfeCarregarCidades do mdfe_list.php
    fetch('mdfe_incluir_dfe.php?action=buscar_cidades&uf=' + encodeURIComponent(uf), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }, // obrigatório — o endpoint exige
        credentials: 'same-origin'
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.success) throw new Error(data.error || 'Erro');
        _formCidadesCache[uf] = data.cidades;
        _formPreencherMunicipios(munSel, data.cidades);
    })
    .catch(function() {
        munSel.innerHTML = '<option value="">Erro ao carregar</option>';
        munSel.disabled = true;
    });
}
function _formPreencherMunicipios(selEL, cidades){
    var html = '<option value="">Selecione</option>';
    for(var i=0; i < cidades.length; i++) {
        html += '<option value="'+ cidades[i].nome +'">'+ cidades[i].nome +'</option>';
    }
    selEL.innerHTML=html;
    selEL.disabled = false;
}
document.querySelectorAll('.mdfe-tab').forEach(tab => {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.mdfe-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.mdfe-tab-content').forEach(c => c.classList.remove('active'));
        
        this.classList.add('active');
        document.getElementById('tab-' + this.dataset.tab).classList.add('active');
    });
});
function handleTpEmitChange() {
    const tpEmit = document.getElementById('tpEmit').value;
    const usaCTe = (tpEmit === '1');
    
    // Atualiza seções de CT-e e NF-e em todos os municípios de descarga
    document.querySelectorAll('.cte-section').forEach(section => {
        section.style.display = usaCTe ? 'block' : 'none';
        // Remove required dos inputs quando oculto
        section.querySelectorAll('input[required]').forEach(input => {
            if (!usaCTe) input.removeAttribute('required');
        });
    });
    
    document.querySelectorAll('.nfe-section').forEach(section => {
        section.style.display = usaCTe ? 'none' : 'block';
        // Remove required dos inputs quando oculto
        section.querySelectorAll('input[required]').forEach(input => {
            if (usaCTe) input.removeAttribute('required');
        });
    });
    
    // Mostrar aviso de contratante obrigatório quando tpEmit = 1 (Prestador de serviço)
    const avisoContratante = document.getElementById('aviso-contratante-obrig');
    if (avisoContratante) {
        avisoContratante.style.display = (tpEmit === '1') ? 'inline' : 'none';
    }
}

/**
 * Modal:
 * 1 = Rodoviário -> habilita campos do modal rodoviário
 * 2,3,4 = Outros modais -> desabilita campos específicos do rodoviário
 */
function handleModalChange() {
    const modal = document.getElementById('modal').value;
    const isRodoviario = (modal === '1');
    
    // Campos do modal rodoviário (todos dentro de section-rodoviario)
    const camposRodo = document.querySelectorAll('#section-rodoviario input, #section-rodoviario select, #section-rodoviario textarea');
    
    // Avisos
    const avisoRodo = document.getElementById('aviso-modal-nao-rodo');
    
    if (avisoRodo) avisoRodo.style.display = isRodoviario ? 'none' : 'block';
    
    // Habilitar/desabilitar campos
    camposRodo.forEach(campo => {
        campo.disabled = !isRodoviario;
        if (!isRodoviario) {
            campo.style.backgroundColor = '#f0f0f0';
        } else {
            campo.style.backgroundColor = '';
        }
    });
}

/**
 * CNPJ/CPF Emitente: preencher um desabilita o outro
 */
function handleDocEmitChange() {
    const cnpj = document.getElementById('cnpj_emit');
    const cpf = document.getElementById('cpf_emit');
    
    if (cnpj.value.trim().length > 0) {
        cpf.disabled = true;
        cpf.value = '';
        cpf.style.backgroundColor = '#f0f0f0';
    } else if (cpf.value.trim().length > 0) {
        cnpj.disabled = true;
        cnpj.value = '';
        cnpj.style.backgroundColor = '#f0f0f0';
    } else {
        cnpj.disabled = false;
        cpf.disabled = false;
        cnpj.style.backgroundColor = '';
        cpf.style.backgroundColor = '';
    }
}

// Inicializar estados condicionais ao carregar a página
document.addEventListener('DOMContentLoaded', function() {
    handleTpEmitChange();
    handleModalChange();
    handleDocEmitChange();

    // Preenche dhEmi com a hora local do navegador (evita fuso do servidor PHP)
    var dhEmiInput = document.getElementById('dhEmi');
    if (dhEmiInput && !dhEmiInput.value) {
        var now = new Date();
        var local = new Date(now.getTime() - now.getTimezoneOffset() * 60000)
                        .toISOString().slice(0, 16);
        dhEmiInput.value = local;
    }
});

// ========================================
// FIM LÓGICA CONDICIONAL
// ========================================

// Array de UFs para uso em JavaScript
const ufsArray = [
    <?php foreach($ufs as $sigla => $nome): ?>
    { sigla: '<?php echo $sigla; ?>', nome: '<?php echo $nome; ?>' },
    <?php endforeach; ?>
];

// Array de tipos de carroceria
const tiposCarroceriaArray = [
    <?php foreach($tiposCarroceria as $cod => $desc): ?>
    { cod: '<?php echo $cod; ?>', desc: '<?php echo $desc; ?>' },
    <?php endforeach; ?>
];

// Array de tipos de proprietário
const tiposProprietarioArray = [
    <?php foreach($tiposProprietario as $cod => $desc): ?>
    { cod: '<?php echo $cod; ?>', desc: '<?php echo $desc; ?>' },
    <?php endforeach; ?>
];

// Array de responsáveis pelo seguro
const respSeguroArray = [
    <?php foreach($respSeguro as $cod => $desc): ?>
    { cod: '<?php echo $cod; ?>', desc: '<?php echo $desc; ?>' },
    <?php endforeach; ?>
];

// Contadores para itens dinâmicos
let counters = {
    munCarrega: 1,
    ufPercurso: 0,
    ciot: 0,
    valePedagio: 0,
    contratante: 0,
    lacreRodo: 0,
    condutor: 1,
    reboque: 0,
    munDescarga: 1,
    cte: {},
    nfe: {},
    seguro: 0,
    lacre: 0,
    autorizado: 0,
    pagamento: 0
};

document.addEventListener('DOMContentLoaded', function () {
    var selInicial = document.getElementById('selUFCarrega-0');
    if (selInicial) formCarregarCidades(selInicial, 0);
    // descarga
    var selInicialDesc = document.getElementById('selUFDescarga-0');
    if (selInicialDesc) formCarregarCidadesDescarga(selInicialDesc, 0);
});
function formCarregarCidadesDescarga(selectUF, index) {
    var uf = selectUF.value;
    var munSel = document.getElementById('selMunDescarga-' + index);
    if (!munSel) return;

    if (!uf) {
        munSel.innerHTML = '<option value="">Selecione a UF primeiro</option>';
        munSel.disabled = true;
        return;
    }

    // Reutiliza o mesmo cache do carregamento — mesma tabela, mesma UF = mesmo resultado
    if (_formCidadesCache[uf]) {
        _formPreencherMunicipios(munSel, _formCidadesCache[uf]);
        return;
    }

    munSel.innerHTML = '<option value="">Carregando...</option>';
    munSel.disabled = true;

    fetch('mdfe_incluir_dfe.php?action=buscar_cidades&uf=' + encodeURIComponent(uf), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        credentials: 'same-origin'
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (!data.success) throw new Error(data.error || 'Erro');
        _formCidadesCache[uf] = data.cidades; // popula cache para ambos (carrega + descarga)
        _formPreencherMunicipios(munSel, data.cidades);
    })
    .catch(function() {
        munSel.innerHTML = '<option value="">Erro ao carregar</option>';
        munSel.disabled = true;
    });
}

function addMunCarrega() {
    const container = document.getElementById('municipios-carrega');
    const index = counters.munCarrega++;

    const ufOptions = ufsArray.map(uf =>
        `<option value="${uf.sigla}">${uf.sigla}</option>`
    ).join('');

    const html = `
        <div class="dynamic-item" data-index="${index}">
            <button type="button" class="btn-remove" onclick="removeMunCarrega(this)">×</button>
            <div class="mdfe-row">
                <div class="mdfe-field medium">
                    <label>Estado</label>
                    <select name="UFCarregamento[${index}]" id="selUFCarrega-${index}"
                            onchange="formCarregarCidades(this, ${index})" required>
                        ${ufOptions}
                    </select>
                </div>
                <div class="mdfe-field large">
                    <label class="required">Município</label>
                    <select name="mun_carrega[${index}][xMunCarrega]" id="selMunCarrega-${index}" 
                            required disabled>
                        <option value="">Selecione a UF primeiro</option>
                    </select>
                </div>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', html);

    // Já carrega as cidades da UF padrão selecionada
    const novoSelectUF = document.getElementById('selUFCarrega-' + index);
    if (novoSelectUF) formCarregarCidades(novoSelectUF, index);
}

function removeMunCarrega(btn) {
    btn.closest('.dynamic-item').remove();
}

function addUFPercurso() {
    const container = document.getElementById('ufs-percurso');
    const index = counters.ufPercurso++;
    let options = ufsArray.map(uf => `<option value="${uf.sigla}">${uf.sigla}</option>`).join('');
    const html = `
        <div class="dynamic-item" data-index="${index}">
            <button type="button" class="btn-remove" onclick="this.closest('.dynamic-item').remove()">×</button>
            <div class="mdfe-row">
                <div class="mdfe-field">
                    <label>UF de Percurso</label>
                    <select name="ufs_percurso[${index}][UFPer]">
                        ${options}
                    </select>
                </div>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', html);
}

function addCIOT() {
    const container = document.getElementById('ciot-list');
    const index = counters.ciot++;
    const html = `
        <div class="dynamic-item" data-index="${index}">
            <button type="button" class="btn-remove" onclick="this.closest('.dynamic-item').remove()">×</button>
            <div class="mdfe-row">
                <div class="mdfe-field medium">
                    <label>Código CIOT</label>
                    <input type="text" name="ciot[${index}][CIOT]" maxlength="12" placeholder="Ex: 123456789012">
                </div>
                <div class="mdfe-field medium">
                    <label>CPF Responsável</label>
                    <input type="text" name="ciot[${index}][CPF]" maxlength="11" placeholder="Ex: 12345678901">
                </div>
                <div class="mdfe-field medium">
                    <label>CNPJ Responsável</label>
                    <input type="text" name="ciot[${index}][CNPJ]" maxlength="14" placeholder="Ex: 12345678000199">
                </div>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', html);
}

function addValePedagio() {
    const container = document.getElementById('vale-pedagio-list');
    const index = counters.valePedagio++;
    const html = `
        <div class="dynamic-item" data-index="${index}">
            <button type="button" class="btn-remove" onclick="this.closest('.dynamic-item').remove()">×</button>
            <div class="mdfe-row">
                <div class="mdfe-field medium">
                    <label>CNPJ Fornecedor</label>
                    <input type="text" name="vale_pedagio[${index}][CNPJForn]" maxlength="14" placeholder="Ex: 12345678000199">
                </div>
                <div class="mdfe-field medium">
                    <label>CNPJ Pagador</label>
                    <input type="text" name="vale_pedagio[${index}][CNPJPg]" maxlength="14" placeholder="Ex: 12345678000199">
                </div>
                <div class="mdfe-field medium">
                    <label>CPF Pagador</label>
                    <input type="text" name="vale_pedagio[${index}][CPFPg]" maxlength="11" placeholder="Ex: 12345678901">
                </div>
            </div>
            <div class="mdfe-row">
                <div class="mdfe-field medium">
                    <label>Número Compra</label>
                    <input type="text" name="vale_pedagio[${index}][nCompra]" maxlength="20" placeholder="Ex: 12345">
                </div>
                <div class="mdfe-field small">
                    <label>Valor (R$)</label>
                    <input type="text" name="vale_pedagio[${index}][vValePed]" placeholder="Ex: 150.00">
                </div>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', html);
}

function addContratante() {
    const container = document.getElementById('contratantes-list');
    const index = counters.contratante++;
    const html = `
        <div class="dynamic-item" data-index="${index}">
            <button type="button" class="btn-remove" onclick="this.closest('.dynamic-item').remove()">×</button>
            <div class="mdfe-row">
                <div class="mdfe-field medium">
                    <label>CPF</label>
                    <input type="text" name="contratantes[${index}][CPF]" maxlength="11" placeholder="Ex: 12345678901">
                </div>
                <div class="mdfe-field medium">
                    <label>CNPJ</label>
                    <input type="text" name="contratantes[${index}][CNPJ]" maxlength="14" placeholder="Ex: 12345678000199">
                </div>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', html);
}

function addLacreRodo() {
    const container = document.getElementById('lacres-rodo-list');
    const index = counters.lacreRodo++;
    const html = `
        <div class="dynamic-item" data-index="${index}">
            <button type="button" class="btn-remove" onclick="this.closest('.dynamic-item').remove()">×</button>
            <div class="mdfe-row">
                <div class="mdfe-field">
                    <label>Número do Lacre</label>
                    <input type="text" name="lacres_rodo[${index}][nLacre]" maxlength="20" placeholder="Ex: LAC123456">
                </div>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', html);
}

function addCondutor() {
    const container = document.getElementById('condutores-list');
    const index = counters.condutor++;
    const html = `
        <div class="dynamic-item" data-index="${index}">
            <button type="button" class="btn-remove" onclick="removeCondutor(this)">×</button>
            <div class="mdfe-row">
                <div class="mdfe-field large">
                    <label class="required">Nome do Condutor</label>
                    <input type="text" name="condutores[${index}][xNome]" maxlength="60" required placeholder="Ex: João da Silva">
                </div>
                <div class="mdfe-field medium">
                    <label class="required">CPF</label>
                    <input type="text" name="condutores[${index}][CPF]" maxlength="11" required placeholder="Ex: 12345678901">
                </div>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', html);
}

function removeCondutor(btn) {
    const container = document.getElementById('condutores-list');
    if (container.querySelectorAll('.dynamic-item').length > 1) {
        btn.closest('.dynamic-item').remove();
    } else {
        alert('É necessário pelo menos um condutor.');
    }
}

function addReboque() {
    const container = document.getElementById('reboques-list');
    const index = counters.reboque++;
    
    // Verifica limite de 3 reboques
    if (container.querySelectorAll('.dynamic-item').length >= 3) {
        alert('Máximo de 3 reboques permitidos.');
        return;
    }
    
    let ufOptions = ufsArray.map(uf => `<option value="${uf.sigla}">${uf.sigla}</option>`).join('');
    let tpCarOptions = tiposCarroceriaArray.map(tc => `<option value="${tc.cod}">${tc.desc}</option>`).join('');
    let tpPropOptions = tiposProprietarioArray.map(tp => `<option value="${tp.cod}">${tp.desc}</option>`).join('');
    
    const html = `
        <div class="dynamic-item" data-index="${index}" style="border-left: 3px solid #4a90d9; padding-left: 15px; margin-bottom: 20px;">
            <button type="button" class="btn-remove" onclick="this.closest('.dynamic-item').remove()">×</button>
            <h5 style="margin: 0 0 10px 0; color: #4a90d9;">Reboque ${index + 1}</h5>
            
            <div class="mdfe-row">
                <div class="mdfe-field small">
                    <label>Cód. Interno</label>
                    <input type="text" name="reboques[${index}][cInt]" maxlength="10" placeholder="Ex: REB001">
                </div>
                <div class="mdfe-field small">
                    <label class="required">Placa</label>
                    <input type="text" name="reboques[${index}][placa]" maxlength="7" required placeholder="Ex: ABC1D23">
                </div>
                <div class="mdfe-field medium">
                    <label>RENAVAM <small style="color:#888;">(9-11 dígitos)</small></label>
                    <input type="text" name="reboques[${index}][RENAVAM]" maxlength="11" minlength="9" placeholder="Ex: 12345678901">
                </div>
            </div>
            <div class="mdfe-row">
                <div class="mdfe-field small">
                    <label class="required">Tara (kg)</label>
                    <input type="number" name="reboques[${index}][tara]" min="1" max="999999" required placeholder="Ex: 3500">
                </div>
                <div class="mdfe-field small">
                    <label>Cap. (kg)</label>
                    <input type="number" name="reboques[${index}][capKG]" min="1" max="999999" placeholder="Ex: 25000">
                </div>
                <div class="mdfe-field small">
                    <label>Cap. (m³)</label>
                    <input type="number" name="reboques[${index}][capM3]" min="1" max="999" placeholder="Ex: 80">
                </div>
            </div>
            <div class="mdfe-row">
                <div class="mdfe-field medium">
                    <label class="required">Tipo Carroceria</label>
                    <select name="reboques[${index}][tpCar]" required>
                        ${tpCarOptions}
                    </select>
                </div>
                <div class="mdfe-field small">
                    <label class="required">UF</label>
                    <select name="reboques[${index}][UF]" required>
                        ${ufOptions}
                    </select>
                </div>
            </div>
            
            <!-- Proprietário do Reboque (prop) -->
            <div class="mdfe-subsection" style="margin-top:10px; background:#f9f9f9; padding:10px; border-radius:4px;">
                <h6 style="margin:0 0 10px 0; color:#555;">Proprietário do Reboque <small style="color:#888;">(só se diferente do emitente)</small></h6>
                <div class="mdfe-row">
                    <div class="mdfe-field medium">
                        <label>CPF</label>
                        <input type="text" name="reboques[${index}][prop][CPF]" maxlength="11" placeholder="Ex: 12345678901">
                    </div>
                    <div class="mdfe-field medium">
                        <label>CNPJ</label>
                        <input type="text" name="reboques[${index}][prop][CNPJ]" maxlength="14" placeholder="Ex: 12345678000199">
                    </div>
                    <div class="mdfe-field medium">
                        <label>RNTRC</label>
                        <input type="text" name="reboques[${index}][prop][RNTRC]" maxlength="8" placeholder="Ex: 12345678">
                    </div>
                </div>
                <div class="mdfe-row">
                    <div class="mdfe-field large">
                        <label>Razão Social/Nome</label>
                        <input type="text" name="reboques[${index}][prop][xNome]" maxlength="60" placeholder="Ex: TRANSPORTADORA XYZ LTDA">
                    </div>
                    <div class="mdfe-field medium">
                        <label>IE</label>
                        <input type="text" name="reboques[${index}][prop][IE]" maxlength="14" placeholder="Ex: 123456789">
                    </div>
                </div>
                <div class="mdfe-row">
                    <div class="mdfe-field small">
                        <label>UF</label>
                        <select name="reboques[${index}][prop][UF]">
                            <option value="">-</option>
                            ${ufOptions}
                        </select>
                    </div>
                    <div class="mdfe-field medium">
                        <label>Tipo Proprietário</label>
                        <select name="reboques[${index}][prop][tpProp]">
                            <option value="">-</option>
                            ${tpPropOptions}
                        </select>
                    </div>
                </div>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', html);
}

function addMunDescarga() {
    const container = document.getElementById('municipios-descarga');
    const index = counters.munDescarga++;
    counters.cte[index] = 0;
    counters.nfe[index] = 0;
    
    // Verifica tipo emitente atual para mostrar CT-e ou NF-e
    const tpEmit = document.getElementById('tpEmit').value;
    const usaCTe = (tpEmit === '1');
    
    const html = `
        <div class="dynamic-item mun-descarga-item" data-index="${index}">
            <button type="button" class="btn-remove" onclick="removeMunDescarga(this)">×</button>
            
            <div class="mdfe-row">
                <div class="mdfe-field medium">
                    <label>Estado</label>
                    <select name="UFDescarga[${index}]" id="selUFDescarga-${index}"
                            onchange="formCarregarCidadesDescarga(this, ${index})" required>
                        ${ufsArray.map(uf => `<option value="${uf.sigla}">${uf.sigla}</option>`).join('')}
                    </select>
                </div>
                <div class="mdfe-field large">
                    <label class="required">Município</label>
                    <select name="mun_descarga[${index}][xMunDescarga]" id="selMunDescarga-${index}"
                            required disabled>
                        <option value="">Selecione a UF primeiro</option>
                    </select>
                </div>
            </div>

            <div class="mdfe-subsection cte-section" id="cte-section-${index}" style="display:${usaCTe ? 'block' : 'none'};">
                <h4>CT-e Vinculados <small style="color:#666;">(Tipo Emitente = 1)</small></h4>
                <div class="cte-list" data-mun="${index}">
                </div>
                <button type="button" class="btn-add" onclick="addCTe(${index})">+ Adicionar CT-e</button>
            </div>

            <div class="mdfe-subsection nfe-section" id="nfe-section-${index}" style="display:${usaCTe ? 'none' : 'block'};">
                <h4>NF-e Vinculadas <small style="color:#666;">(Tipo Emitente = 2 ou 3)</small></h4>
                <div class="nfe-list" data-mun="${index}">
                </div>
                <button type="button" class="btn-add" onclick="addNFe(${index})">+ Adicionar NF-e</button>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', html);
    const novoSelectUF = document.getElementById('selUFDescarga-' + index);
    if (novoSelectUF) formCarregarCidadesDescarga(novoSelectUF, index);
}

function removeMunDescarga(btn) {
    const container = document.getElementById('municipios-descarga');
    if (container.querySelectorAll('.mun-descarga-item').length > 1) {
        btn.closest('.dynamic-item').remove();
    } else {
        alert('É necessário pelo menos um município de descarga.');
    }
}

// Inicializar contadores para o primeiro município de descarga
counters.cte[0] = 0;
counters.nfe[0] = 0;

function addCTe(munIndex) {
    const container = document.querySelector(`.cte-list[data-mun="${munIndex}"]`);
    if (!counters.cte[munIndex]) counters.cte[munIndex] = 0;
    const index = counters.cte[munIndex]++;
    const html = `
        <div class="dynamic-item" data-index="${index}">
            <button type="button" class="btn-remove" onclick="this.closest('.dynamic-item').remove(); verificarQtdDocumentos();">×</button>
            <div class="mdfe-row">
                <div class="mdfe-field large">
                    <label class="required">Chave CT-e (44 dígitos)</label>
                    <input type="text" name="mun_descarga[${munIndex}][cte][${index}][chCTe]" maxlength="44" required placeholder="Ex: 35240312345678000199570010000012341123456789" onblur="verificarQtdDocumentos()">
                </div>
            </div>
            <div class="mdfe-row">
                <div class="mdfe-field medium">
                    <label>Segundo Código de Barras</label>
                    <input type="text" name="mun_descarga[${munIndex}][cte][${index}][SegCodBarra]" maxlength="36" placeholder="Ex: 12345678901234567890123456789012345">
                </div>
                <div class="mdfe-field small">
                    <label>Reentrega</label>
                    <select name="mun_descarga[${munIndex}][cte][${index}][indReentrega]">
                        <option value="">Não</option>
                        <option value="1">Sim</option>
                    </select>
                </div>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', html);
    verificarQtdDocumentos();
}

function addNFe(munIndex) {
    const container = document.querySelector(`.nfe-list[data-mun="${munIndex}"]`);
    if (!counters.nfe[munIndex]) counters.nfe[munIndex] = 0;
    const index = counters.nfe[munIndex]++;
    const html = `
        <div class="dynamic-item" data-index="${index}">
            <button type="button" class="btn-remove" onclick="this.closest('.dynamic-item').remove(); verificarQtdDocumentos();">×</button>
            <div class="mdfe-row">
                <div class="mdfe-field large">
                    <label class="required">Chave NF-e (44 dígitos)</label>
                    <input type="text" name="mun_descarga[${munIndex}][nfe][${index}][chNFe]" maxlength="44" required placeholder="Ex: 35240312345678000199550010000012341123456789" onblur="verificarQtdDocumentos()">
                </div>
            </div>
            <div class="mdfe-row">
                <div class="mdfe-field medium">
                    <label>Segundo Código de Barras</label>
                    <input type="text" name="mun_descarga[${munIndex}][nfe][${index}][SegCodBarra]" maxlength="36" placeholder="Ex: 12345678901234567890123456789012345">
                </div>
                <div class="mdfe-field small">
                    <label>Reentrega</label>
                    <select name="mun_descarga[${munIndex}][nfe][${index}][indReentrega]">
                        <option value="">Não</option>
                        <option value="1">Sim</option>
                    </select>
                </div>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', html);
    verificarQtdDocumentos();
}

function addSeguro() {
    const container = document.getElementById('seguros-list');
    const index = counters.seguro++;
    let respSegOptions = respSeguroArray.map(rs => `<option value="${rs.cod}">${rs.desc}</option>`).join('');
    const html = `
        <div class="dynamic-item" data-index="${index}">
            <button type="button" class="btn-remove" onclick="this.closest('.dynamic-item').remove()">×</button>
            <div class="mdfe-row">
                <div class="mdfe-field medium">
                    <label class="required">Responsável pelo Seguro</label>
                    <select name="seguros[${index}][respSeg]" required onchange="toggleRespSeguroFields(this, ${index})">
                        ${respSegOptions}
                    </select>
                </div>
                <div class="mdfe-field medium" id="respCNPJ_${index}" style="display:none;">
                    <label>CNPJ Responsável</label>
                    <input type="text" name="seguros[${index}][respCNPJ]" maxlength="14" placeholder="Ex: 12345678000199">
                </div>
                <div class="mdfe-field medium" id="respCPF_${index}" style="display:none;">
                    <label>CPF Responsável</label>
                    <input type="text" name="seguros[${index}][respCPF]" maxlength="11" placeholder="Ex: 12345678901">
                </div>
            </div>
            <div class="mdfe-row">
                <div class="mdfe-field large">
                    <label>Nome Seguradora</label>
                    <input type="text" name="seguros[${index}][xSeg]" maxlength="30" placeholder="Ex: Seguros ABC Ltda">
                </div>
                <div class="mdfe-field medium">
                    <label>CNPJ Seguradora</label>
                    <input type="text" name="seguros[${index}][CNPJSeg]" maxlength="14" placeholder="Ex: 12345678000199">
                </div>
            </div>
            <div class="mdfe-row">
                <div class="mdfe-field medium">
                    <label>Número Apólice</label>
                    <input type="text" name="seguros[${index}][nApol]" maxlength="20" placeholder="Ex: APOL123456">
                </div>
                <div class="mdfe-field large">
                    <label>Números de Averbação (separados por vírgula)</label>
                    <input type="text" name="seguros[${index}][nAver]" placeholder="Ex: AVER001, AVER002, AVER003">
                </div>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', html);
}

function toggleRespSeguroFields(select, index) {
    const cnpjField = document.getElementById('respCNPJ_' + index);
    const cpfField = document.getElementById('respCPF_' + index);
    if (select.value === '2') {
        cnpjField.style.display = 'block';
        cpfField.style.display = 'block';
    } else {
        cnpjField.style.display = 'none';
        cpfField.style.display = 'none';
    }
}

function addLacre() {
    const container = document.getElementById('lacres-list');
    const index = counters.lacre++;
    const html = `
        <div class="dynamic-item" data-index="${index}">
            <button type="button" class="btn-remove" onclick="this.closest('.dynamic-item').remove()">×</button>
            <div class="mdfe-row">
                <div class="mdfe-field">
                    <label>Número do Lacre</label>
                    <input type="text" name="lacres[${index}][nLacre]" maxlength="60" placeholder="Ex: LACRE123456789">
                </div>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', html);
}

function addAutorizado() {
    const container = document.getElementById('autorizados-list');
    const index = counters.autorizado++;
    const html = `
        <div class="dynamic-item" data-index="${index}">
            <button type="button" class="btn-remove" onclick="this.closest('.dynamic-item').remove()">×</button>
            <div class="mdfe-row">
                <div class="mdfe-field medium">
                    <label>CPF</label>
                    <input type="text" name="autorizados[${index}][CPF]" maxlength="11" placeholder="Ex: 12345678901">
                </div>
                <div class="mdfe-field medium">
                    <label>CNPJ</label>
                    <input type="text" name="autorizados[${index}][CNPJ]" maxlength="14" placeholder="Ex: 12345678000199">
                </div>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', html);
}

// Contadores para componentes de pagamento
let compCounters = {};

function addPagamento() {
    const container = document.getElementById('pagamentos-list');
    const index = counters.pagamento++;
    compCounters[index] = 0;
    const html = `
        <div class="dynamic-item" data-index="${index}">
            <button type="button" class="btn-remove" onclick="this.closest('.dynamic-item').remove(); renumerarPagamentos()">×</button>
            <h4 style="margin:0 0 15px 0; color:#666;">Pagamento #${index + 1}</h4>

            <p style="font-size:12px;color:#888;margin:0 0 12px 0;">Informe apenas um identificador do responsável: CPF, CNPJ ou Estrangeiro.</p>
            <div class="mdfe-row">
                <div class="mdfe-field large">
                    <label>Nome do responsável</label>
                    <input type="text" name="pagamentos[${index}][xNome]" maxlength="60" placeholder="Ex: Transportadora XYZ Ltda">
                </div>
            </div>
            <div class="mdfe-row">
                <div class="mdfe-field medium">
                    <label>CPF</label>
                    <input type="text" name="pagamentos[${index}][CPF]" maxlength="11" placeholder="Ex: 12345678901"
                        oninput="toggleDocPag(${index}, 'CPF')">
                </div>
                <div class="mdfe-field medium">
                    <label>CNPJ</label>
                    <input type="text" name="pagamentos[${index}][CNPJ]" maxlength="14" placeholder="Ex: 12345678000199"
                        oninput="toggleDocPag(${index}, 'CNPJ')">
                </div>
                <div class="mdfe-field medium">
                    <label>Estrangeiro</label>
                    <input type="text" name="pagamentos[${index}][idEstrangeiro]" maxlength="20" placeholder="Ex: PASS123456"
                        oninput="toggleDocPag(${index}, 'EST')">
                </div>
            </div>
            
            <!-- Componentes do Pagamento -->
            <div class="mdfe-subsection">
                <h4 class="required">Componentes do Pagamento</h4>
                <div class="comp-list" data-pag="${index}">
                </div>
                <button type="button" class="btn-add" onclick="addComponente(${index})">+ Adicionar Componente</button>
            </div>
            
            <div class="mdfe-row">
                <div class="mdfe-field medium">
                    <label>Valor Total Contrato (R$) <small style="color:red">*</small></label>
                    <input type="text" name="pagamentos[${index}][vContrato]" placeholder="0,00" required data-money>
                </div>
                <div class="mdfe-field medium">
                    <label>Indicador Pagamento <small style="color:red">*</small></label>
                    <select name="pagamentos[${index}][indPag]" id="indPag_${index}" onchange="toggleParcelas(${index}, this.value)" required>
                        <option value="0">Pagamento à Vista</option>
                        <option value="1">Pagamento a Prazo</option>
                    </select>
                </div>
            </div>

            <!-- Parcelas (infPrazo) - visível somente quando indPag = 1 -->
            <div class="mdfe-subsection" id="secao-parcelas-${index}" style="display:none;">
                <h4>Parcelas <small style="color:#dc3545;font-weight:bold;">*</small>
                    <small style="font-weight:normal;color:#888;"> (obrigatório para pagamento a prazo)</small></h4>
                <div class="parcela-list" data-pag="${index}">
                </div>
                <button type="button" class="btn-add" onclick="addParcela(${index})">+ Adicionar Parcela</button>
            </div>

            <!-- Informações Bancárias (infBanc) - obrigatório para Pagamento À Vista -->
            <div class="mdfe-subsection" id="secao-banc-${index}">
                <h4>Informações Bancárias <small style="color:#dc3545;font-weight:bold;">*</small>
                    <small style="font-weight:normal;color:#888;"> (Banco + Agência <em>ou</em> CNPJ IPEF)</small></h4>
                <div class="mdfe-row">
                    <div class="mdfe-field small">
                        <label>Código Banco</label>
                        <input type="text" name="pagamentos[${index}][codBanco]" maxlength="5" placeholder="Ex: 001"
                            oninput="toggleBanc(${index}, 'banco')">
                    </div>
                    <div class="mdfe-field small">
                        <label>Agência</label>
                        <input type="text" name="pagamentos[${index}][codAgencia]" maxlength="10" placeholder="Ex: 1234"
                            oninput="toggleBanc(${index}, 'banco')">
                    </div>
                    <div class="mdfe-field medium">
                        <label>CNPJ IPEF</label>
                        <input type="text" name="pagamentos[${index}][CNPJIPEF]" maxlength="14" placeholder="Ex: 12345678000199"
                            oninput="toggleBanc(${index}, 'ipef')">
                    </div>
                </div>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', html);
    // Pré-adiciona 1 componente obrigatório (Comp é required pelo schema)
    addComponente(index);
}

function renumerarPagamentos() {
    document.querySelectorAll('#pagamentos-list > .dynamic-item').forEach(function(el, i) {
        const h4 = el.querySelector('h4');
        if (h4) h4.textContent = 'Pagamento #' + (i + 1);
    });
}

// Contadores para parcelas
let parcelaCounters = {};

function addComponente(pagIndex) {
    const container = document.querySelector(`.comp-list[data-pag="${pagIndex}"]`);
    if (!compCounters[pagIndex]) compCounters[pagIndex] = 0;
    const index = compCounters[pagIndex]++;
    const html = `
        <div class="dynamic-item" data-index="${index}">
            <button type="button" class="btn-remove" onclick="this.closest('.dynamic-item').remove()">×</button>
            <div class="mdfe-row">
                <div class="mdfe-field medium">
                    <label class="required">Tipo Componente</label>
                    <select name="pagamentos[${pagIndex}][Comp][${index}][tpComp]" required>
                        <option value="01">Vale Pedágio</option>
                        <option value="02">Impostos, taxas e contribuições</option>
                        <option value="03">Despesas (bancárias, etc)</option>
                        <option value="99">Outros</option>
                    </select>
                </div>
                <div class="mdfe-field medium">
                    <label class="required">Valor (R$)</label>
                    <input type="text" name="pagamentos[${pagIndex}][Comp][${index}][vComp]" placeholder="0,00" required data-money>
                </div>
                <div class="mdfe-field large">
                    <label>Descrição</label>
                    <input type="text" name="pagamentos[${pagIndex}][Comp][${index}][xComp]" maxlength="60" placeholder="Ex: Descrição do componente">
                </div>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', html);
}

function addParcela(pagIndex) {
    const container = document.querySelector(`.parcela-list[data-pag="${pagIndex}"]`);
    if (!parcelaCounters[pagIndex]) parcelaCounters[pagIndex] = 0;
    const index = parcelaCounters[pagIndex]++;
    const html = `
        <div class="dynamic-item" data-index="${index}">
            <button type="button" class="btn-remove" onclick="this.closest('.dynamic-item').remove()">×</button>
            <div class="mdfe-row">
                <div class="mdfe-field small">
                    <label>Nº Parcela</label>
                    <input type="text" name="pagamentos[${pagIndex}][infPrazo][${index}][nParcela]" maxlength="3" value="${String(index + 1).padStart(3, '0')}" placeholder="Ex: 001">
                </div>
                <div class="mdfe-field medium">
                    <label>Data Vencimento</label>
                    <input type="date" name="pagamentos[${pagIndex}][infPrazo][${index}][dVenc]">
                </div>
                <div class="mdfe-field medium">
                    <label>Valor Parcela (R$)</label>
                    <input type="text" name="pagamentos[${pagIndex}][infPrazo][${index}][vParcela]" placeholder="0,00" data-money>
                </div>
            </div>
        </div>
    `;
    container.insertAdjacentHTML('beforeend', html);
}

/**
 * Exibe/oculta seções de parcelas e dados bancários conforme indPag
 */
function toggleParcelas(pagIndex, valor) {
    const secaoParcelas = document.getElementById(`secao-parcelas-${pagIndex}`);
    const secaoBanc     = document.getElementById(`secao-banc-${pagIndex}`);
    const aPrazo = valor === '1';
    if (secaoParcelas) secaoParcelas.style.display = aPrazo ? 'block' : 'none';
    if (secaoBanc)     secaoBanc.style.display     = aPrazo ? 'none'  : 'block';
}

/**
 * Garante exclusividade entre CPF, CNPJ e idEstrangeiro no pagamento
 */
function toggleDocPag(pagIndex, tipo) {
    const cpfEl  = document.querySelector(`input[name="pagamentos[${pagIndex}][CPF]"]`);
    const cnpjEl = document.querySelector(`input[name="pagamentos[${pagIndex}][CNPJ]"]`);
    const estEl  = document.querySelector(`input[name="pagamentos[${pagIndex}][idEstrangeiro]"]`);
    if (!cpfEl || !cnpjEl || !estEl) return;

    const hasCpf  = cpfEl.value.trim()  !== '';
    const hasCnpj = cnpjEl.value.trim() !== '';
    const hasEst  = estEl.value.trim()  !== '';

    const disable = (el, cond) => { el.disabled = cond; el.style.backgroundColor = cond ? '#f0f0f0' : ''; };

    if (tipo === 'CPF' && hasCpf)  { disable(cnpjEl, true);  disable(estEl,  true); }
    else if (tipo === 'CNPJ' && hasCnpj) { disable(cpfEl, true);   disable(estEl,  true); }
    else if (tipo === 'EST'  && hasEst)  { disable(cpfEl, true);   disable(cnpjEl, true); }
    else { disable(cpfEl, false); disable(cnpjEl, false); disable(estEl, false); }
}

/**
 * Valida exclusividade entre Banco/Agência e CNPJ IPEF em infBanc
 */
function toggleBanc(pagIndex, origem) {
    const bancoEl = document.querySelector(`input[name="pagamentos[${pagIndex}][codBanco]"]`);
    const agencEl = document.querySelector(`input[name="pagamentos[${pagIndex}][codAgencia]"]`);
    const ipefEl  = document.querySelector(`input[name="pagamentos[${pagIndex}][CNPJIPEF]"]`);
    if (!bancoEl || !ipefEl) return;

    const disable = (el, cond) => { el.disabled = cond; el.style.backgroundColor = cond ? '#f0f0f0' : ''; };

    const hasBanco = bancoEl.value.trim() !== '' || agencEl.value.trim() !== '';
    const hasIpef  = ipefEl.value.trim()  !== '';

    if (origem === 'banco' && hasBanco) {
        disable(ipefEl, true);
    } else if (origem === 'ipef' && hasIpef) {
        disable(bancoEl, true);
        disable(agencEl, true);
    } else {
        disable(bancoEl, false);
        disable(agencEl, false);
        disable(ipefEl, false);
    }
}

function validarFormulario() {
    const form = document.getElementById('mdfeForm');
    const requiredFields = form.querySelectorAll('[required]:not([disabled])');
    let errors = [];
    
    // Validar campos required (apenas os não desabilitados)
    requiredFields.forEach(field => {
        if (!field.value.trim()) {
            const label = field.closest('.mdfe-field')?.querySelector('label');
            errors.push(label ? label.textContent.replace(' *', '').replace(/\(ou.*\)/g, '').trim() : field.name);
            field.style.borderColor = '#dc3545';
        } else {
            field.style.borderColor = '#ccc';
        }
    });
    
    // Validar CNPJ ou CPF do emitente (um dos dois é obrigatório)
    const cnpjEmit = document.getElementById('cnpj_emit');
    const cpfEmit = document.getElementById('cpf_emit');
    if (!cnpjEmit.value.trim() && !cpfEmit.value.trim()) {
        errors.push('CNPJ ou CPF do Emitente é obrigatório');
        cnpjEmit.style.borderColor = '#dc3545';
        cpfEmit.style.borderColor = '#dc3545';
    }
    
    // Verificar tipo emitente para validar documento correto
    const tpEmit = document.getElementById('tpEmit').value;
    const usaCTe = (tpEmit === '1');
    const indCarregaPosterior = document.querySelector('select[name="indCarregaPosterior"]')?.value || '';
    
    // Verificar se há pelo menos um documento (CT-e ou NF-e) conforme tipo emitente
    // Exceto quando indCarregaPosterior = 1 (documentos serão inseridos posteriormente)
    let temDocumento = false;
    if (indCarregaPosterior !== '1') {
        if (usaCTe) {
            const cteInputs = form.querySelectorAll('input[name*="[cte]"][name*="[chCTe]"]');
            cteInputs.forEach(input => { if (input.value.trim().length === 44) temDocumento = true; });
            if (!temDocumento) {
                errors.push('Tipo Emitente = 1: Informe pelo menos um CT-e com chave de 44 dígitos');
            }
        } else {
            const nfeInputs = form.querySelectorAll('input[name*="[nfe]"][name*="[chNFe]"]');
            nfeInputs.forEach(input => { if (input.value.trim().length === 44) temDocumento = true; });
            if (!temDocumento) {
                errors.push('Tipo Emitente = 2 ou 3: Informe pelo menos uma NF-e com chave de 44 dígitos');
            }
        }
    }
    
    // Verificar condutor para modal rodoviário
    const modal = document.getElementById('modal').value;
    if (modal === '1') {
        const condutorNome = form.querySelector('input[name*="condutores"][name*="[xNome]"]');
        const condutorCPF = form.querySelector('input[name*="condutores"][name*="[CPF]"]');
        if (!condutorNome?.value.trim() || !condutorCPF?.value.trim()) {
            errors.push('Para modal Rodoviário, informe pelo menos um condutor com nome e CPF');
        }
    }
    
    if (errors.length > 0) {
        alert('Problemas encontrados:\n\n• ' + errors.join('\n• '));
        return false;
    } else {
        alert('✓ Validação OK! Todos os campos obrigatórios estão preenchidos.');
        return true;
    }
}

function toggleLotacao(checkbox) {
    const container = document.getElementById('info-lotacao');
    container.style.display = checkbox.checked ? 'block' : 'none';
    
    // Atualizar labels de obrigatoriedade quando lotação está habilitada
    const lblCepCarrega = document.getElementById('lblCepCarrega');
    const lblCepDescarrega = document.getElementById('lblCepDescarrega');
    const cepCarrega = document.getElementById('cepCarrega');
    const cepDescarrega = document.getElementById('cepDescarrega');
    
    if (checkbox.checked) {
        lblCepCarrega.classList.add('required');
        lblCepDescarrega.classList.add('required');
        cepCarrega.setAttribute('required', 'required');
        cepDescarrega.setAttribute('required', 'required');
    } else {
        lblCepCarrega.classList.remove('required');
        lblCepDescarrega.classList.remove('required');
        cepCarrega.removeAttribute('required');
        cepDescarrega.removeAttribute('required');
    }
}

// Função para verificar quantidade de documentos e alertar sobre infLotacao
function verificarQtdDocumentos() {
    const form = document.getElementById('mdfeForm');
    const tpEmit = document.getElementById('tpEmit').value;
    const usaCTe = (tpEmit === '1');
    
    let qtdDocumentos = 0;
    
    if (usaCTe) {
        const cteInputs = form.querySelectorAll('input[name*="[cte]"][name*="[chCTe]"]');
        cteInputs.forEach(input => { if (input.value.trim().length === 44) qtdDocumentos++; });
    } else {
        const nfeInputs = form.querySelectorAll('input[name*="[nfe]"][name*="[chNFe]"]');
        nfeInputs.forEach(input => { if (input.value.trim().length === 44) qtdDocumentos++; });
    }
    
    const avisoLotacao = document.getElementById('aviso-lotacao');
    const chkUsaLotacao = document.getElementById('chkUsaLotacao');
    
    if (qtdDocumentos === 1) {
        // Apenas 1 documento - infLotacao é obrigatório
        avisoLotacao.style.display = 'block';
        if (!chkUsaLotacao.checked) {
            chkUsaLotacao.checked = true;
            toggleLotacao(chkUsaLotacao);
        }
    } else {
        avisoLotacao.style.display = 'none';
    }
    
    return qtdDocumentos;
}

// Atualizar aviso de carga posterior na aba Documentos e ocultar/exibir seção de documentos
document.addEventListener('DOMContentLoaded', function() {
    const selectCargaPosterior = document.querySelector('select[name="indCarregaPosterior"]');
    const avisoCargas = document.getElementById('aviso-carga-posterior');
    const secaoMunicipios = document.getElementById('municipios-descarga');
    const btnAddMun = secaoMunicipios ? secaoMunicipios.nextElementSibling : null;

    function atualizarAvisoCargaPosterior() {
        if (!selectCargaPosterior) return;
        const ativo = selectCargaPosterior.value === '1';

        // Exibe/oculta aviso
        if (avisoCargas) avisoCargas.style.display = ativo ? 'block' : 'none';

        // Quando carga posterior ativada: oculta APENAS as subseções de CT-e e NF-e
        // O município de descarga ainda é obrigatório pelo schema do MDF-e
        document.querySelectorAll('.cte-section, .nfe-section').forEach(section => {
            section.style.display = ativo ? 'none' : '';
        });

        // Remove required apenas dos inputs de chave (CT-e/NF-e), nunca do xMunDescarga
        document.querySelectorAll(
            'input[name*="[chCTe]"], input[name*="[chNFe]"]'
        ).forEach(input => {
            if (ativo) {
                if (input.hasAttribute('required')) {
                    input.removeAttribute('required');
                    input.dataset.wasRequired = '1';
                }
            } else if (input.dataset.wasRequired === '1') {
                input.setAttribute('required', 'required');
                delete input.dataset.wasRequired;
            }
        });
    }

    if (selectCargaPosterior) {
        selectCargaPosterior.addEventListener('change', atualizarAvisoCargaPosterior);
        atualizarAvisoCargaPosterior();
    }
});

// Validar ao submeter o formulário
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('mdfeForm');
    form.addEventListener('submit', function(e) {
        // Verificar tipo emitente
        const tpEmit = document.getElementById('tpEmit').value;
        const usaCTe = (tpEmit === '1');
        const indCarregaPosteriorSubmit = document.querySelector('select[name="indCarregaPosterior"]')?.value || '';
        
        let qtdDocumentos = 0;
        // Quando indCarregaPosterior = 1, documentos serão inseridos posteriormente — não valida
        if (indCarregaPosteriorSubmit !== '1') {
            if (usaCTe) {
                const cteInputs = form.querySelectorAll('input[name*="[cte]"][name*="[chCTe]"]');
                cteInputs.forEach(input => { if (input.value.trim().length === 44) qtdDocumentos++; });
                if (qtdDocumentos === 0) {
                    mostrarModalErro('Tipo Emitente = 1\n\n• É necessário informar pelo menos um CT-e com chave de 44 dígitos.', null);
                    e.preventDefault();
                    return false;
                }
            } else {
                const nfeInputs = form.querySelectorAll('input[name*="[nfe]"][name*="[chNFe]"]');
                nfeInputs.forEach(input => { if (input.value.trim().length === 44) qtdDocumentos++; });
                if (qtdDocumentos === 0) {
                    mostrarModalErro('Tipo Emitente = 2 ou 3\n\n• É necessário informar pelo menos uma NF-e com chave de 44 dígitos.', null);
                    e.preventDefault();
                    return false;
                }
            }
        }
        
        // Verificar infLotacao quando há apenas 1 documento
        if (qtdDocumentos === 1) {
            const chkUsaLotacao = document.getElementById('chkUsaLotacao');
            const cepCarrega = document.getElementById('cepCarrega');
            const cepDescarrega = document.getElementById('cepDescarrega');
            
            if (!chkUsaLotacao.checked) {
                mostrarModalErro('Erro de Validação\n\n• Dados de Lotação são obrigatórios quando só existir um documento informado!\n\nVá na aba "Totais" e marque a opção "Informar dados de Lotação".', null);
                e.preventDefault();
                return false;
            }
            
            if (!cepCarrega.value.trim() && !cepDescarrega.value.trim()) {
                mostrarModalErro('Erro de Validação\n\n• Quando há apenas 1 documento, é obrigatório informar pelo menos o CEP de carregamento ou descarregamento na seção de Lotação.', null);
                e.preventDefault();
                return false;
            }
        }
        
        // Verificar CNPJ ou CPF do emitente
        const cnpjEmit = document.getElementById('cnpj_emit');
        const cpfEmit = document.getElementById('cpf_emit');
        if (!cnpjEmit.value.trim() && !cpfEmit.value.trim()) {
            mostrarModalErro('Erro de Validação\n\n• CNPJ ou CPF do Emitente é obrigatório.', null);
            e.preventDefault();
            return false;
        }
    });
});

// Máscaras e validações adicionais - formatar valores
document.addEventListener('DOMContentLoaded', function() {
    // Máscara monetária: aplica em campos já existentes
    document.querySelectorAll('[data-money]').forEach(applyMoneyMask);
});

// Máscara monetária com delegação (cobre campos dinâmicos criados após DOMContentLoaded)
document.addEventListener('input', function(e) {
    if (e.target.matches('[data-money]')) moneyMaskHandler(e.target);
});
document.addEventListener('focusout', function(e) {
    if (e.target.matches('[data-money]')) {
        // Garante que valores como "5" virem "5,00"
        const v = e.target.value.replace(/\D/g, '');
        if (v === '' || v === '0') { e.target.value = ''; return; }
        e.target.value = formatMoney(v.padStart(3, '0'));
    }
});

function moneyMaskHandler(input) {
    let raw = input.value.replace(/\D/g, '');
    if (raw === '') { input.value = ''; return; }
    input.value = formatMoney(raw);
}

function formatMoney(digits) {
    // digits = string de só números, ex: "500000" -> "5.000,00"
    let cents = parseInt(digits, 10);
    let reais = Math.floor(cents / 100);
    let dec   = cents % 100;
    let reaisStr = reais.toString().replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return reaisStr + ',' + String(dec).padStart(2, '0');
}

function applyMoneyMask(input) {
    input.addEventListener('input', function() { moneyMaskHandler(this); });
    input.addEventListener('focusout', function() {
        const v = this.value.replace(/\D/g, '');
        if (v === '' || v === '0') { this.value = ''; return; }
        this.value = formatMoney(v.padStart(3, '0'));
    });
}

// ========================================
// MODAL DE ERROS E ENVIO AJAX
// ========================================

// Criar modal de erro dinamicamente
function criarModalErro() {
    if (document.getElementById('mdfe-error-modal')) return;
    
    const modalHtml = `
        <div id="mdfe-error-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; overflow:auto;">
            <div style="background:#fff; max-width:600px; margin:10% auto; border-radius:10px; box-shadow:0 5px 20px rgba(0,0,0,0.3); overflow:hidden;">
                <div style="background:#dc3545; color:#fff; padding:15px 20px; display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0; font-size:18px;">Erro ao gerar MDF-e</h3>
                    <button onclick="fecharModalErro()" style="background:none; border:none; color:#fff; font-size:28px; cursor:pointer; line-height:1;">&times;</button>
                </div>
                <div id="mdfe-error-content" style="padding:25px; max-height:400px; overflow-y:auto;">
                    <!-- Conteúdo do erro será inserido aqui -->
                </div>
                <div style="padding:15px 20px; background:#f8f9fa; text-align:center; border-top:1px solid #dee2e6;">
                    <button onclick="fecharModalErro()" style="background:#dc3545; color:#fff; border:none; padding:12px 30px; border-radius:6px; cursor:pointer; font-weight:bold; font-size:16px;">Fechar</button>
                </div>
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

function mostrarModalErro(mensagem, detalhes) {
    criarModalErro();
    
    let html = '<div style="color:#721c24;">';
    
    // Formatar mensagem (quebrar linhas e bullets)
    const linhas = mensagem.split('\n');
    linhas.forEach(linha => {
        if (linha.startsWith('•')) {
            html += '<div style="margin-left:15px; margin-bottom:5px;">' + escapeHtml(linha) + '</div>';
        } else if (linha.trim()) {
            html += '<p style="margin:0 0 10px 0;"><strong>' + escapeHtml(linha) + '</strong></p>';
        }
    });
    
    // Adicionar detalhes se existirem (apenas em modo debug — não mostrar ao cliente)
    if (detalhes && (detalhes.file || detalhes.line)) {
        // Omitido intencionalmente para não expor caminhos internos ao cliente
    }
    
    // Adicionar detalhes completos (para erros SOAP e outros)
    if (detalhes && detalhes.detalhes) {
        html += '<div style="margin-top:15px; padding-top:15px; border-top:1px solid #f5c6cb;">';
        html += '<strong style="color:#856404;">Detalhes do Erro:</strong>';
        html += '<div style="background:#f8f9fa; padding:10px; border-radius:4px; margin-top:10px; font-size:12px; font-family:monospace; white-space:pre-wrap; max-height:150px; overflow-y:auto;">';
        html += escapeHtml(JSON.stringify(detalhes.detalhes, null, 2));
        html += '</div>';
        html += '</div>';
    }
    
    // Adicionar resposta completa do servidor se disponível
    if (detalhes && detalhes.respostaCompleta) {
        html += '<div style="margin-top:15px; padding-top:15px; border-top:1px solid #f5c6cb;">';
        html += '<strong style="color:#856404;">Resposta Completa do Servidor:</strong>';
        html += '<div style="background:#f8f9fa; padding:10px; border-radius:4px; margin-top:10px; font-size:11px; font-family:monospace; white-space:pre-wrap; max-height:200px; overflow-y:auto;">';
        html += escapeHtml(detalhes.respostaCompleta);
        html += '</div>';
        html += '</div>';
    }
    
    html += '</div>';
    
    document.getElementById('mdfe-error-content').innerHTML = html;
    document.getElementById('mdfe-error-modal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

// Criar modal de sucesso dinamicamente
function criarModalSucesso() {
    if (document.getElementById('mdfe-success-modal')) return;
    
    const modalHtml = `
        <div id="mdfe-success-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:9999; overflow:auto;">
            <div style="background:#fff; max-width:600px; margin:10% auto; border-radius:10px; box-shadow:0 5px 20px rgba(0,0,0,0.3); overflow:hidden;">
                <div style="background:#0073aa; color:#fff; padding:15px 20px; display:flex; justify-content:space-between; align-items:center;">
                    <h3 style="margin:0; font-size:18px;">Resposta da SEFAZ</h3>
                    <button onclick="fecharModalSucesso()" style="background:none; border:none; color:#fff; font-size:28px; cursor:pointer; line-height:1;">&times;</button>
                </div>
                <div id="mdfe-success-content" style="padding:25px;">
                    <!-- Conteúdo será inserido aqui -->
                </div>
                <div style="padding:15px 20px; background:#f8f9fa; text-align:center; border-top:1px solid #dee2e6;">
                    <button onclick="fecharModalSucesso()" style="background:#0073aa; color:#fff; border:none; padding:12px 30px; border-radius:6px; cursor:pointer; font-weight:bold; font-size:16px;">OK</button>
                </div>
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', modalHtml);
}

function mostrarModalSucesso(dados) {
    criarModalSucesso();
    const motivo = dados.xMotivo || dados.message || 'MDF-e processado com sucesso!';
    document.getElementById('mdfe-success-content').innerHTML =
        '<p style="text-align:center; font-size:1.05em; color:#155724; margin:0;">' + escapeHtml(motivo) + '</p>';
    document.getElementById('mdfe-success-modal').style.display = 'block';
    document.body.style.overflow = 'hidden';
}

function fecharModalSucesso() {
    const modal = document.getElementById('mdfe-success-modal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

function fecharModalErro() {
    const modal = document.getElementById('mdfe-error-modal');
    if (modal) {
        modal.style.display = 'none';
        document.body.style.overflow = '';
    }
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// Fechar modal ao clicar fora
document.addEventListener('click', function(e) {
    const modalErro = document.getElementById('mdfe-error-modal');
    if (modalErro && e.target === modalErro) {
        fecharModalErro();
    }
    const modalSucesso = document.getElementById('mdfe-success-modal');
    if (modalSucesso && e.target === modalSucesso) {
        fecharModalSucesso();
    }
});

// Fechar modal com ESC
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        fecharModalErro();
        fecharModalSucesso();
    }
});

// Envio do formulário via AJAX
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('mdfeForm');
    const submitBtn = form.querySelector('button[type="submit"]');
    
    form.addEventListener('submit', function(e) {
        e.preventDefault();
        
        // Validação antes de enviar
        const tpEmit = document.getElementById('tpEmit').value;
        const usaCTe = (tpEmit === '1');
        const indCarregaPosteriorAjax = document.querySelector('select[name="indCarregaPosterior"]')?.value || '';
        
        let qtdDocumentos = 0;
        // Quando indCarregaPosterior = 1 documentos não são inseridos agora — pular validação
        if (indCarregaPosteriorAjax !== '1') {
            if (usaCTe) {
                const cteInputs = form.querySelectorAll('input[name*="[cte]"][name*="[chCTe]"]');
                cteInputs.forEach(input => { if (input.value.trim().length === 44) qtdDocumentos++; });
                if (qtdDocumentos === 0) {
                    mostrarModalErro('Tipo Emitente = 1\n\n• É necessário informar pelo menos um CT-e com chave de 44 dígitos.', null);
                    return;
                }
            } else {
                const nfeInputs = form.querySelectorAll('input[name*="[nfe]"][name*="[chNFe]"]');
                nfeInputs.forEach(input => { if (input.value.trim().length === 44) qtdDocumentos++; });
                if (qtdDocumentos === 0) {
                    mostrarModalErro('Tipo Emitente = 2 ou 3\n\n• É necessário informar pelo menos uma NF-e com chave de 44 dígitos.', null);
                    return;
                }
            }
        }
        
        // Verificar infLotacao quando há apenas 1 documento
        if (qtdDocumentos === 1) {
            const chkUsaLotacao = document.getElementById('chkUsaLotacao');
            const cepCarrega = document.getElementById('cepCarrega');
            const cepDescarrega = document.getElementById('cepDescarrega');
            
            if (!chkUsaLotacao || !chkUsaLotacao.checked) {
                mostrarModalErro('Erro de Validação\n\n• Dados de Lotação são obrigatórios quando só existir um documento informado!\n\nVá na aba "Totais" e marque a opção "Informar dados de Lotação".', null);
                return;
            }
            
            if ((!cepCarrega || !cepCarrega.value.trim()) && (!cepDescarrega || !cepDescarrega.value.trim())) {
                mostrarModalErro('Erro de Validação\n\n• Quando há apenas 1 documento, é obrigatório informar pelo menos o CEP de carregamento ou descarregamento na seção de Lotação.', null);
                return;
            }
        }
        
        // Verificar CNPJ ou CPF
        const cnpjEmit = document.getElementById('cnpj_emit');
        const cpfEmit = document.getElementById('cpf_emit');
        if (!cnpjEmit.value.trim() && !cpfEmit.value.trim()) {
            mostrarModalErro('Erro de Validação\n\n• CNPJ ou CPF do Emitente é obrigatório.', null);
            return;
        }

        // Validar pagamentos (dados bancários x parcelas conforme indPag)
        const errosPag = [];
        document.querySelectorAll('#pagamentos-list > .dynamic-item').forEach(function(el, i) {
            const indPagEl = el.querySelector('select[name$="[indPag]"]');
            const indPag = indPagEl ? indPagEl.value : '0';
            if (indPag === '0') {
                const banco  = el.querySelector('input[name$="[codBanco]"]')?.value.trim()   || '';
                const agenc  = el.querySelector('input[name$="[codAgencia]"]')?.value.trim() || '';
                const ipef   = el.querySelector('input[name$="[CNPJIPEF]"]')?.value.trim()   || '';
                if (!banco && !agenc && !ipef) {
                    errosPag.push('Pagamento ' + (i + 1) + ': para pagamento "À Vista", informe os dados bancários (Banco + Agência) ou o CNPJ da IPEF.');
                }
            } else {
                const parcelaItems = el.querySelectorAll('.parcela-list .dynamic-item');
                let parcelaValida = false;
                parcelaItems.forEach(function(p) {
                    const dv = p.querySelector('input[name$="[dVenc]"]')?.value.trim()     || '';
                    const vp = p.querySelector('input[name$="[vParcela]"]')?.value.trim() || '';
                    if (dv && vp) parcelaValida = true;
                });
                if (!parcelaValida) {
                    errosPag.push('Pagamento ' + (i + 1) + ': para pagamento "A Prazo", informe pelo menos uma parcela com data de vencimento e valor.');
                }
            }
        });
        if (errosPag.length > 0) {
            mostrarModalErro('Erro de Validação\n\n• ' + errosPag.join('\n• '), null);
            return;
        }

        // Desabilitar botão e mostrar loading
        const btnTextoOriginal = submitBtn.innerHTML;
        submitBtn.disabled = true;
        submitBtn.innerHTML = '⏳ Processando...';
        
        // Enviar via AJAX
        const formData = new FormData(form);
        
        fetch('mdfe_processar.php', {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => {
            // Verificar se resposta é JSON
            const contentType = response.headers.get('content-type');
            if (contentType && contentType.includes('application/json')) {
                return response.json().then(data => ({ isJson: true, data: data }));
            } else {
                // Se não for JSON, pegar o texto para analisar
                return response.text().then(text => ({ isJson: false, text: text, status: response.status }));
            }
        })
        .then(result => {
            submitBtn.disabled = false;
            submitBtn.innerHTML = btnTextoOriginal;
            
            if (result.isJson) {
                if (result.data.success) {
                    // Sucesso com JSON - mostrar modal com resposta completa
                    mostrarModalSucesso(result.data);
                } else {
                    // Erro - mostrar no modal
                    mostrarModalErro(result.data.error || 'Erro desconhecido ao processar MDF-e', result.data.details || null);
                }
            } else {
                // Não é JSON — mostra o texto bruto para facilitar diagnóstico
                mostrarModalErro('Resposta inesperada do servidor (não é JSON). Verifique o console.', {
                    detalhes: result.text.substring(0, 2000)
                });
                console.error('Resposta bruta do servidor:', result.text);
            }
        })
        .catch(error => {
            submitBtn.disabled = false;
            submitBtn.innerHTML = btnTextoOriginal;
            
            // Erro de rede - mostrar no modal
            mostrarModalErro('Erro de comunicação com o servidor: ' + error.message, null);
        });
    });
});
</script>

<?php
llxFooter();
?>
