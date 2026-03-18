<?php
/**
 * Página Principal - Módulo CT-e
 * Sistema de emissão de CT-e em etapas
 */

// Carregamento do ambiente Dolibarr
$res = 0;
if (!$res && file_exists("../main.inc.php")) {
    $res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
    $res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
    $res = @include "../../../main.inc.php";
}
if (!$res) {
    die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';

// Iniciar sessão para manter dados entre etapas
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Controle de acesso

// Parâmetros
$action = GETPOST('action', 'aZ09');
$etapa = GETPOST('etapa', 'int') ?: 1;
$cte_id = GETPOST('id', 'int');

// Inicializar array de dados na sessão se não existir
if (!isset($_SESSION['cte_dados'])) {
    $_SESSION['cte_dados'] = array();
}

// Carregar dados da empresa (mysoc) para usar como remetente padrão
if (empty($_SESSION['cte_dados']['rem_cnpj'])) {
    // Dados básicos da empresa
    $_SESSION['cte_dados']['rem_cnpj'] = $mysoc->idprof1 ?? '';
    $_SESSION['cte_dados']['rem_xNome'] = $mysoc->name ?? '';
    $_SESSION['cte_dados']['rem_xFant'] = getDolGlobalString('MAIN_INFO_NOME_FANTASIA', $mysoc->name);
    $_SESSION['cte_dados']['rem_ie'] = $mysoc->idprof2 ?? '';
    
    // Endereço
    $_SESSION['cte_dados']['rem_xLgr'] = getDolGlobalString('MAIN_INFO_RUA', $mysoc->address);
    $_SESSION['cte_dados']['rem_nro'] = getDolGlobalString('MAIN_INFO_NUMERO', '');
    $_SESSION['cte_dados']['rem_xBairro'] = getDolGlobalString('MAIN_INFO_BAIRRO', '');
    $_SESSION['cte_dados']['rem_xCpl'] = '';
    $_SESSION['cte_dados']['rem_cep'] = $mysoc->zip ?? '';
    $_SESSION['cte_dados']['rem_xMun'] = $mysoc->town ?? '';
    $_SESSION['cte_dados']['rem_uf'] = $mysoc->state_code ?? '';
    $_SESSION['cte_dados']['rem_cMun'] = $mysoc->town_id ?? '';
    
    // Contato
    $_SESSION['cte_dados']['rem_fone'] = $mysoc->phone ?? '';
    $_SESSION['cte_dados']['rem_email'] = $mysoc->email ?? '';
    
}

// Processar dados do formulário
if ($action == 'save_etapa' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    // Salvar todos os dados POST na sessão
    foreach ($_POST as $key => $value) {
        if ($key != 'token' && $key != 'action' && $key != 'etapa' && $key != 'id') {
            $_SESSION['cte_dados'][$key] = $value;
        }
    }
    
    // Avançar para próxima etapa
    $etapa_atual = (int)$etapa;
    if ($etapa_atual < 5) {
        $etapa = $etapa_atual + 1;
        header('Location: '.$_SERVER["PHP_SELF"].'?etapa='.$etapa);
        exit;
    } else {
        // Última etapa - redirecionar para emissão
        header('Location: cte_emissao.php');
        exit;
    }
}

// Limpar dados se solicitado
if ($action == 'clear') {
    unset($_SESSION['cte_dados']);
    header('Location: '.$_SERVER["PHP_SELF"]);
    exit;
}

// Processar upload de XML
if ($action == 'upload_xml' && $_SERVER['REQUEST_METHOD'] == 'POST') {
    error_log("[CTE XML Import] Iniciando processamento de upload");
    error_log("[CTE XML Import] FILES: " . print_r($_FILES, true));
    
    if (isset($_FILES['xml_nfe']) && $_FILES['xml_nfe']['error'] == 0) {
        error_log("[CTE XML Import] Arquivo recebido: " . $_FILES['xml_nfe']['name']);
        $xml_content = file_get_contents($_FILES['xml_nfe']['tmp_name']);
        error_log("[CTE XML Import] Tamanho do conteúdo: " . strlen($xml_content) . " bytes");
        
        try {
            $xml = simplexml_load_string($xml_content);
            
            if ($xml === false) {
                $errors = libxml_get_errors();
                error_log("[CTE XML Import] Erro ao ler XML: " . print_r($errors, true));
                setEventMessages('Erro ao ler o arquivo XML', null, 'errors');
            } else {
                error_log("[CTE XML Import] XML carregado com sucesso");
                // Registrar namespace
                $xml->registerXPathNamespace('nfe', 'http://www.portalfiscal.inf.br/nfe');
                
                // Extrair dados da NF-e
                $ide = $xml->xpath('//nfe:ide')[0];
                $emit = $xml->xpath('//nfe:emit')[0];
                $dest = $xml->xpath('//nfe:dest')[0];
                $total = $xml->xpath('//nfe:total/nfe:ICMSTot')[0];
                $transp = $xml->xpath('//nfe:transp')[0];
                $infAdic = $xml->xpath('//nfe:infAdic')[0];
                
                // Preencher dados do CT-e com base na NF-e
                
                // Dados básicos
                $_SESSION['cte_dados']['cfop'] = (string)$ide->CFOP;
                $_SESSION['cte_dados']['natOp'] = (string)$ide->natOp;
                
                // Destinatário (pega do destinatário da NF-e)
                if ($dest) {
                    $destCNPJ = isset($dest->CNPJ) ? (string)$dest->CNPJ : (string)$dest->CPF;
                    $_SESSION['cte_dados']['dest_cnpj'] = $destCNPJ;
                    $_SESSION['cte_dados']['dest_xNome'] = (string)$dest->xNome;
                    
                    $destEnder = $dest->enderDest;
                    $_SESSION['cte_dados']['dest_xLgr'] = (string)$destEnder->xLgr;
                    $_SESSION['cte_dados']['dest_nro'] = (string)$destEnder->nro;
                    $_SESSION['cte_dados']['dest_xCpl'] = (string)$destEnder->xCpl;
                    $_SESSION['cte_dados']['dest_xBairro'] = (string)$destEnder->xBairro;
                    $_SESSION['cte_dados']['dest_cMun'] = (string)$destEnder->cMun;
                    $_SESSION['cte_dados']['dest_xMun'] = (string)$destEnder->xMun;
                    $_SESSION['cte_dados']['dest_uf'] = (string)$destEnder->UF;
                    $_SESSION['cte_dados']['dest_cep'] = (string)$destEnder->CEP;
                    $_SESSION['cte_dados']['dest_fone'] = (string)$destEnder->fone;
                    $_SESSION['cte_dados']['dest_ie'] = (string)$dest->IE;
                }
                
                // Municípios de início e fim
                if ($emit) {
                    $_SESSION['cte_dados']['xMunIni'] = (string)$emit->enderEmit->xMun;
                    $_SESSION['cte_dados']['UFIni'] = (string)$emit->enderEmit->UF;
                }
                
                if ($dest) {
                    $_SESSION['cte_dados']['xMunFim'] = (string)$dest->enderDest->xMun;
                    $_SESSION['cte_dados']['UFFim'] = (string)$dest->enderDest->UF;
                }
                
                // Valores
                if ($total) {
                    $_SESSION['cte_dados']['vCarga'] = number_format((float)$total->vNF, 2, '.', '');
                    $_SESSION['cte_dados']['vNF'] = number_format((float)$total->vNF, 2, '.', '');
                }
                
                // Produto predominante (pega o primeiro produto)
                $prods = $xml->xpath('//nfe:prod');
                if (!empty($prods)) {
                    $_SESSION['cte_dados']['proPred'] = (string)$prods[0]->xProd;
                }
                
                // Quantidade e peso
                if ($transp && isset($transp->vol)) {
                    $_SESSION['cte_dados']['qCarga'] = (string)$transp->vol->qVol;
                    
                    if (isset($transp->vol->pesoB)) {
                        $_SESSION['cte_dados']['qCarga'] = number_format((float)$transp->vol->pesoB, 3, '.', '');
                        $_SESSION['cte_dados']['cUnid'] = '01'; // KG
                    }
                }
                
                // Chave da NF-e
                $chave = (string)$xml->xpath('//nfe:infNFe')[0]['Id'];
                $chave = str_replace('NFe', '', $chave);
                $_SESSION['cte_dados']['chave_nfe'] = $chave;
                
                // Número e série da NF-e
                $_SESSION['cte_dados']['nDoc'] = (string)$ide->nNF;
                $_SESSION['cte_dados']['serie_nf'] = (string)$ide->serie;
                $_SESSION['cte_dados']['dEmi_nf'] = date('Y-m-d', strtotime((string)$ide->dhEmi));
                
                // Informações adicionais
                if ($infAdic) {
                    $_SESSION['cte_dados']['infCpl'] = (string)$infAdic->infCpl;
                }
                
                error_log("[CTE XML Import] Dados extraídos e salvos na sessão");
                error_log("[CTE XML Import] Chave NF-e: " . $_SESSION['cte_dados']['chave_nfe']);
                setEventMessages('Dados importados com sucesso do XML da NF-e!', null, 'mesgs');
                
                // Marcar que os dados foram importados mas não mostrar mensagem em todas as páginas
                $_SESSION['cte_dados']['_xml_imported'] = true;
                $_SESSION['cte_dados']['_show_import_message'] = true;
                
                error_log("[CTE XML Import] Redirecionando para etapa 1");
                header('Location: '.$_SERVER["PHP_SELF"].'?etapa=1');
                exit;
            }
        } catch (Exception $e) {
            error_log("[CTE XML Import] Exception: " . $e->getMessage());
            setEventMessages('Erro ao processar XML: ' . $e->getMessage(), null, 'errors');
        }
    } else {
        $error_msg = isset($_FILES['xml_nfe']) ? 
            'Erro no upload: código ' . $_FILES['xml_nfe']['error'] : 
            'Nenhum arquivo foi enviado';
        error_log("[CTE XML Import] " . $error_msg);
        setEventMessages($error_msg, null, 'errors');
    }
}

// Inicialização de objetos
$form = new Form($db);

// Cabeçalho da página
llxHeader('', 'Módulo CT-e - Emissão', '');

// Título principal
print load_fiche_titre('Emissão de CT-e', '', 'cte@cte');

// Container principal
print '<div class="cte-wizard-container">';

// Barra de ações no topo
print '<div class="cte-action-bar">';
print '<button type="button" class="butAction" onclick="openXMLModal()">';
print '<span class="fa fa-upload"></span> Importar XML NF-e';
print '</button>';
if (!empty($_SESSION['cte_dados'])) {
    print '<button type="button" class="butActionDelete" onclick="if(confirm(\'Deseja limpar todos os dados?\')) window.location.href=\''.$_SERVER["PHP_SELF"].'?action=clear\'">';
    print '<span class="fa fa-trash"></span> Limpar Dados';
    print '</button>';
}
print '</div>';

// Modal para importação de XML
print_xml_import_modal();

// Resumo dos dados importados (se houver) - Só mostra se acabou de importar
if (!empty($_SESSION['cte_dados']['chave_nfe']) && !empty($_SESSION['cte_dados']['_show_import_message'])) {
    print_data_summary($_SESSION['cte_dados']);
    // Removes the flag so it doesn't show again
    unset($_SESSION['cte_dados']['_show_import_message']);
}

// Barra de progresso
print_progress_bar($etapa);

// Formulário
print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" name="formcte">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save_etapa">';
print '<input type="hidden" name="etapa" value="'.$etapa.'">';
if ($cte_id) {
    print '<input type="hidden" name="id" value="'.$cte_id.'">';
}

// Renderizar a etapa atual
switch ($etapa) {
    case 1:
        print_etapa_dados_cte($form, $_SESSION['cte_dados']);
        break;
    case 2:
        print_etapa_partes($form, $_SESSION['cte_dados']);
        break;
    case 3:
        print_etapa_valores($form, $_SESSION['cte_dados']);
        break;
    case 4:
        print_etapa_carga($form, $_SESSION['cte_dados']);
        break;
    case 5:
        print_etapa_complementos($form, $_SESSION['cte_dados']);
        
        // Exibir erros detalhados se houver
        if (GETPOST('showerrors', 'int') && !empty($_SESSION['cte_erros'])) {
            print_debug_errors($_SESSION['cte_erros']);
        }
        break;
}

// Botões de navegação
print_navigation_buttons($etapa);

print '</form>';

print '</div>';

// CSS customizado
print_custom_css();

// JavaScript
print_custom_js();

llxFooter();
$db->close();

/**
 * Imprime a barra de progresso
 */
function print_progress_bar($etapa_atual)
{
    $etapas = array(
        1 => 'Dados CT-e',
        2 => 'Partes',
        3 => 'Valores',
        4 => 'Carga',
        5 => 'Finalização'
    );
    
    print '<div class="cte-progress-bar">';
    foreach ($etapas as $num => $nome) {
        $class = 'step';
        if ($num < $etapa_atual) $class .= ' completed';
        if ($num == $etapa_atual) $class .= ' active';
        
        print '<div class="'.$class.'">';
        print '<div class="step-number">'.$num.'</div>';
        print '<div class="step-name">'.$nome.'</div>';
        print '</div>';
        
        if ($num < count($etapas)) {
            print '<div class="step-line '.($num < $etapa_atual ? 'completed' : '').'"></div>';
        }
    }
    print '</div>';
}

/**
 * Modal para importação de XML
 */
function print_xml_import_modal()
{
    print '<!-- Modal Importação XML -->';
    print '<div id="xmlModal" class="cte-modal">';
    print '<div class="cte-modal-content">';
    print '<div class="cte-modal-header">';
    print '<h2>📄 Importar XML da NF-e</h2>';
    print '<span class="cte-modal-close" onclick="closeXMLModal()">&times;</span>';
    print '</div>';
    print '<div class="cte-modal-body">';
    print '<form method="POST" action="'.$_SERVER["PHP_SELF"].'" enctype="multipart/form-data" id="formXMLUpload">';
    print '<input type="hidden" name="token" value="'.newToken().'">';
    print '<input type="hidden" name="action" value="upload_xml">';
    
    print '<div class="cte-upload-area" id="dropArea">';
    print '<div class="cte-upload-icon">📁</div>';
    print '<p class="cte-upload-text">Arraste o arquivo XML aqui ou clique para selecionar</p>';
    print '<input type="file" name="xml_nfe" id="xml_nfe" accept=".xml" required style="display: none;">';
    print '<button type="button" class="button" onclick="document.getElementById(\'xml_nfe\').click()">Escolher Arquivo</button>';
    print '<p class="cte-upload-hint">Arquivo XML da Nota Fiscal Eletrônica (NF-e)</p>';
    print '</div>';
    
    print '<div id="selectedFile" style="display: none; margin-top: 15px; padding: 10px; background: #e7f3ff; border-radius: 4px;">';
    print '<strong>Arquivo selecionado:</strong> <span id="fileName"></span>';
    print '</div>';
    
    print '<div id="uploadProgress" style="display: none; margin-top: 15px;">';
    print '<div style="background: #f0f0f0; border-radius: 4px; height: 30px; position: relative; overflow: hidden;">';
    print '<div id="progressBar" style="background: linear-gradient(90deg, #0050a0, #0070d0); height: 100%; width: 0%; transition: width 0.3s; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;"></div>';
    print '</div>';
    print '<p id="uploadStatus" style="text-align: center; margin-top: 8px; color: #666;">Importando XML...</p>';
    print '</div>';
    
    print '<div class="cte-modal-footer">';
    print '<button type="button" class="button" onclick="closeXMLModal()">Cancelar</button>';
    print '<button type="submit" class="butAction" id="btnImport" disabled>Importar e Preencher</button>';
    print '</div>';
    print '</form>';
    print '</div>';
    print '</div>';
    print '</div>';
}

/**
 * Resumo dos dados importados
 */
function print_data_summary($dados)
{
    print '<div class="cte-summary-card">';
    print '<div class="cte-summary-header">';
    print '<span class="cte-summary-icon">✓</span>';
    print '<strong>Dados Importados da NF-e</strong>';
    print '<button type="button" class="cte-summary-close" onclick="this.parentElement.parentElement.style.display=\'none\'">&times;</button>';
    print '</div>';
    print '<div class="cte-summary-content">';
    print '<div class="cte-summary-grid">';
    
    if (!empty($dados['chave_nfe'])) {
        print '<div class="cte-summary-item">';
        print '<span class="cte-summary-label">Chave NF-e:</span>';
        print '<span class="cte-summary-value">'.substr($dados['chave_nfe'], 0, 20).'...</span>';
        print '</div>';
    }
    
    if (!empty($dados['dest_xNome'])) {
        print '<div class="cte-summary-item">';
        print '<span class="cte-summary-label">Destinatário:</span>';
        print '<span class="cte-summary-value">'.$dados['dest_xNome'].'</span>';
        print '</div>';
    }
    
    if (!empty($dados['vCarga'])) {
        print '<div class="cte-summary-item">';
        print '<span class="cte-summary-label">Valor da Carga:</span>';
        print '<span class="cte-summary-value">R$ '.number_format($dados['vCarga'], 2, ',', '.').'</span>';
        print '</div>';
    }
    
    if (!empty($dados['xMunFim'])) {
        print '<div class="cte-summary-item">';
        print '<span class="cte-summary-label">Destino:</span>';
        print '<span class="cte-summary-value">'.$dados['xMunFim'].'/'.$dados['UFFim'].'</span>';
        print '</div>';
    }
    
    print '</div>';
    print '</div>';
    print '</div>';
}

/**
 * Etapa 1: Dados principais do CT-e
 */
function print_etapa_dados_cte($form, $dados = array())
{
    print '<div class="etapa-content">';
    print '<div class="etapa-header">';
    print '<h3 class="etapa-title">Etapa 1: Dados do CT-e</h3>';
    print '<span class="etapa-badge">1/5</span>';
    print '</div>';
    
    // Aviso se dados foram importados (apenas quando acabou de importar)
    if (!empty($dados['chave_nfe']) && !empty($dados['_show_import_message'])) {
        print '<div style="background: #d4edda; padding: 12px; border-radius: 4px; margin-bottom: 15px; border-left: 4px solid #28a745;">';
        print '<strong>✓ Dados importados do XML!</strong> Verifique e ajuste conforme necessário.';
        print '</div>';
    }
    
    print '<table class="border centpercent">';
    
    // Tipo de CT-e
    print '<tr>';
    print '<td class="fieldrequired" width="25%">Tipo de CT-e *</td>';
    print '<td>';
    print '<select name="tpCTe" class="flat minwidth200" required>';
    print '<option value="">Selecione...</option>';
    print '<option value="0"'.($dados['tpCTe'] == '0' ? ' selected' : '').'>Normal</option>';
    print '<option value="1"'.($dados['tpCTe'] == '1' ? ' selected' : '').' disabled>Complemento de valores</option>';
    print '<option value="3"'.($dados['tpCTe'] == '3' ? ' selected' : '').' disabled>Substituição</option>';
    print '</select>';
    print '</td>';
    print '</tr>';
    
    // Tipo de Serviço
    print '<tr>';
    print '<td class="fieldrequired">Tipo de Serviço *</td>';
    print '<td>';
    print '<select name="tpServ" class="flat minwidth200" required>';
    print '<option value="">Selecione...</option>';
    print '<option value="0"'.($dados['tpServ'] == '0' ? ' selected' : '').'>Normal</option>';
    print '<option value="1"'.($dados['tpServ'] == '1' ? ' selected' : '').'>Subcontratação</option>';
    print '<option value="2"'.($dados['tpServ'] == '2' ? ' selected' : '').'>Redespacho</option>';
    print '<option value="3"'.($dados['tpServ'] == '3' ? ' selected' : '').'>Redespacho Intermediário</option>';
    print '<option value="4"'.($dados['tpServ'] == '4' ? ' selected' : '').'>Vinculado a Multimodal</option>';
    print '</select>';
    print '</td>';
    print '</tr>';
    
    // Série
    print '<tr>';
    print '<td class="fieldrequired">Série *</td>';
    print '<td><input type="text" name="serie" value="'.($dados['serie'] ?? '1').'" class="flat minwidth200" required></td>';
    print '</tr>';
    
    // CFOP
    print '<tr>';
    print '<td class="fieldrequired">CFOP *</td>';
    print '<td>';
    print '<select name="cfop" class="flat minwidth200" required>';
    print '<option value="">Selecione...</option>';
    print '<option value="5351"'.($dados['cfop'] == '5351' ? ' selected' : '').'>5351 – Prestação de serviço de transporte para execução de serviço da mesma natureza</option>';
    print '<option value="5352"'.($dados['cfop'] == '5352' ? ' selected' : '').'>5352 – Prestação de serviço de transporte a estabelecimento industrial</option>';
    print '<option value="5353"'.($dados['cfop'] == '5353' ? ' selected' : '').'>5353 – Prestação de serviço de transporte a estabelecimento comercial</option>';
    print '<option value="5354"'.($dados['cfop'] == '5354' ? ' selected' : '').'>5354 – Prestação de serviço de transporte a estabelecimento de consignatário ou a não contribuinte</option>';
    print '<option value="5355"'.($dados['cfop'] == '5355' ? ' selected' : '').'>5355 – Prestação de serviço de transporte a cooperado</option>';
    print '<option value="5356"'.($dados['cfop'] == '5356' ? ' selected' : '').'>5356 – Prestação de serviço de transporte a terceiros</option>';
    print '<option value="6351"'.($dados['cfop'] == '6351' ? ' selected' : '').'>6351 – Prestação de serviço de transporte para execução de serviço da mesma natureza</option>';
    print '<option value="6352"'.($dados['cfop'] == '6352' ? ' selected' : '').'>6352 – Prestação de serviço de transporte a estabelecimento industrial</option>';
    print '<option value="6353"'.($dados['cfop'] == '6353' ? ' selected' : '').'>6353 – Prestação de serviço de transporte a estabelecimento comercial</option>';
    print '<option value="6354"'.($dados['cfop'] == '6354' ? ' selected' : '').'>6354 – Prestação de serviço de transporte a estabelecimento de consignatário ou a não contribuinte</option>';
    print '<option value="6355"'.($dados['cfop'] == '6355' ? ' selected' : '').'>6355 – Prestação de serviço de transporte a cooperado</option>';
    print '<option value="6356"'.($dados['cfop'] == '6356' ? ' selected' : '').'>6356 – Prestação de serviço de transporte a terceiros</option>';
    print '</select>';
    print '</td>';
    print '</tr>';
    
    // Natureza da Operação
    print '<tr>';
    print '<td class="fieldrequired">Natureza da Operação *</td>';
    print '<td><input type="text" name="natOp" value="'.($dados['natOp'] ?? 'Prestação de serviço de transporte').'" class="flat minwidth400" required></td>';
    print '</tr>';
    
    // Modal
    print '<tr>';
    print '<td class="fieldrequired">Modal *</td>';
    print '<td>';
    print '<select name="modal" class="flat minwidth200" required>';
    print '<option value="">Selecione...</option>';
    print '<option value="01"'.($dados['modal'] == '01' ? ' selected' : '').'>Rodoviário</option>';
    print '<option value="02"'.($dados['modal'] == '02' ? ' selected' : '').'>Aéreo</option>';
    print '<option value="03"'.($dados['modal'] == '03' ? ' selected' : '').'>Aquaviário</option>';
    print '<option value="04"'.($dados['modal'] == '04' ? ' selected' : '').'>Ferroviário</option>';
    print '<option value="05"'.($dados['modal'] == '05' ? ' selected' : '').'>Dutoviário</option>';
    print '<option value="06"'.($dados['modal'] == '06' ? ' selected' : '').'>Multimodal</option>';
    print '</select>';
    print '</td>';
    print '</tr>';
    
    // Forma de Emissão
    print '<tr>';
    print '<td class="fieldrequired">Forma de Emissão *</td>';
    print '<td>';
    print '<select name="tpEmis" class="flat minwidth200" required>';
    print '<option value="1"'.(!isset($dados['tpEmis']) || $dados['tpEmis'] == '1' ? ' selected' : '').'>Normal</option>'; 
    print '<option value="3"'.($dados['tpEmis'] == '3' ? ' selected' : '').'>Regime Especial NFF</option>';
    print '<option value="4"'.($dados['tpEmis'] == '4' ? ' selected' : '').'>EPEC pela SVC</option>';   
    print '<option value="5"'.($dados['tpEmis'] == '5' ? ' selected' : '').'>Contingência FSDA</option>';
    print '<option value="7"'.($dados['tpEmis'] == '7' ? ' selected' : '').'>Autorização SVC-RS</option>';
    print '<option value="8"'.($dados['tpEmis'] == '8' ? ' selected' : '').'>Autorização SVC-SP</option>';
    print '</select>';
    print '</td>';
    print '</tr>';
    
    // Município de ENVIO (dinâmico)
    print '<tr><td colspan="2"><hr><h4>Município de Envio (Transmissão)</h4></td></tr>';
    
    print '<tr>';
    print '<td class="fieldrequired">UF Envio *</td>';
    print '<td>';
    print '<select name="UFEnv" id="UFEnv" class="flat minwidth150" required onchange="carregarMunicipios(\'UFEnv\', \'xMunEnv\', \'cMunEnv\')">';
    print '<option value="">Carregando...</option>';
    print '</select>';
    print '<input type="hidden" name="cMunEnv" id="cMunEnv" value="'.dol_escape_htmltag($dados['cMunEnv'] ?? '').'">';
    print '</td>';
    print '</tr>';
    
    print '<tr>';
    print '<td class="fieldrequired">Município Envio *</td>';
    print '<td>';
    print '<select name="xMunEnv" id="xMunEnv" class="flat minwidth300" required onchange="setCodigoMunicipio(\'xMunEnv\', \'cMunEnv\')">';
    print '<option value="">Selecione UF primeiro</option>';
    print '</select>';
    print '</td>';
    print '</tr>';
    
    // Município Início (dinâmico)
    print '<tr><td colspan="2"><hr><h4>Município de Início da Prestação</h4></td></tr>';
    
    print '<tr>';
    print '<td class="fieldrequired">UF Início *</td>';
    print '<td>';
    print '<select name="UFIni" id="UFIni" class="flat minwidth150" required onchange="carregarMunicipios(\'UFIni\', \'xMunIni\', \'cMunIni\')">';
    print '<option value="">Carregando...</option>';
    print '</select>';
    print '<input type="hidden" name="cMunIni" id="cMunIni" value="'.dol_escape_htmltag($dados['cMunIni'] ?? '').'">';
    print '</td>';
    print '</tr>';
    
    print '<tr>';
    print '<td class="fieldrequired">Município Início *</td>';
    print '<td>';
    print '<select name="xMunIni" id="xMunIni" class="flat minwidth300" required onchange="setCodigoMunicipio(\'xMunIni\', \'cMunIni\')">';
    print '<option value="">Selecione UF primeiro</option>';
    print '</select>';
    print '</td>';
    print '</tr>';
    
    // Município Fim (dinâmico)
    print '<tr><td colspan="2"><hr><h4>Município de Fim da Prestação</h4></td></tr>';
    
    print '<tr>';
    print '<td class="fieldrequired">UF Fim *</td>';
    print '<td>';
    print '<select name="UFFim" id="UFFim" class="flat minwidth150" required onchange="carregarMunicipios(\'UFFim\', \'xMunFim\', \'cMunFim\')">';
    print '<option value="">Carregando...</option>';
    print '</select>';
    print '<input type="hidden" name="cMunFim" id="cMunFim" value="'.dol_escape_htmltag($dados['cMunFim'] ?? '').'">';
    print '</td>';
    print '</tr>';
    
    print '<tr>';
    print '<td class="fieldrequired">Município Fim *</td>';
    print '<td>';
    print '<select name="xMunFim" id="xMunFim" class="flat minwidth300" required onchange="setCodigoMunicipio(\'xMunFim\', \'cMunFim\')">';
    print '<option value="">Selecione UF primeiro</option>';
    print '</select>';
    print '</td>';
    print '</tr>';
    
    // Tipo de Tomador
    print '<tr>';
    print '<td class="fieldrequired">Tomador do Serviço *</td>';
    print '<td>';
    print '<select name="toma" class="flat minwidth200" required>';
    print '<option value="">Selecione...</option>';
    print '<option value="0"'.($dados['toma'] == '0' ? ' selected' : '').'>Remetente</option>';
    print '<option value="1"'.($dados['toma'] == '1' ? ' selected' : '').'>Expedidor</option>';
    print '<option value="2"'.($dados['toma'] == '2' ? ' selected' : '').'>Recebedor</option>';
    print '<option value="3"'.($dados['toma'] == '3' ? ' selected' : '').'>Destinatário</option>';
    print '<option value="4"'.($dados['toma'] == '4' ? ' selected' : '').'>Outros</option>';
    print '</select>';
    print '</td>';
    print '</tr>';
    
    // Retira
    print '<tr>';
    print '<td class="fieldrequired">Recebedor irá retirar? *</td>';
    print '<td>';
    print '<select name="retira" class="flat minwidth200" required>';
    print '<option value="0"'.($dados['retira'] == '0' ? ' selected' : '').'>Sim</option>';
    print '<option value="1"'.(!isset($dados['retira']) || $dados['retira'] == '1' ? ' selected' : '').'>Não</option>';
    print '</select>';
    print '</td>';
    print '</tr>';
    
    // Detalhes do Retira (Opcional)
    print '<tr>';
    print '<td>Detalhes da retirada</td>';
    print '<td><textarea name="xDetRetira" rows="2" class="flat" style="width: 50%;" placeholder="Opcional - Informações adicionais sobre a retirada">'.($dados['xDetRetira'] ?? NULL).'</textarea></td>';
    print '</tr>';
    
    // Indicador IE Tomador
    print '<tr>';
    print '<td class="fieldrequired">Indicador IE Tomador *</td>';
    print '<td>';
    print '<select name="indIEToma" class="flat minwidth200" required>';
    print '<option value="1"'.($dados['indIEToma'] == '1' ? ' selected' : '').'>Contribuinte ICMS</option>';
    print '<option value="2"'.($dados['indIEToma'] == '2' ? ' selected' : '').'>Contribuinte isento de inscrição</option>';
    print '<option value="9"'.($dados['indIEToma'] == '9' ? ' selected' : '').'>Não contribuinte</option>';
    print '</select>';
    print '</td>';
    print '</tr>';
    
    // // Indicador de Globalização (Opcional)
    // print '<tr>';
    // print '<td>CT-e Globalizado</td>';
    // print '<td>';
    // print '<select name="indGlobalizado" class="flat minwidth200">';
    // print '<option value="">Não</option>';
    // print '<option value="1"'.($dados['indGlobalizado'] == '1' ? ' selected' : '').'>Sim</option>';
    // print '</select>';
    // print '</td>';
    // print '</tr>';
    
    print '</table>';
    print '</div>';
}

/**
 * Etapa 2: Partes Envolvidas (Remetente, Destinatário, etc.)
 */
function print_etapa_partes($form, $dados = array())
{
    print '<div class="etapa-content">';
    print '<div class="etapa-header">';
    print '<h3 class="etapa-title">Etapa 2: Partes Envolvidas</h3>';
    print '<span class="etapa-badge">2/5</span>';
    print '</div>';
    
    // Aviso se dados foram importados (apenas quando acabou de importar)
    if (!empty($dados['chave_nfe']) && !empty($dados['_show_import_message'])) {
        print '<div style="background: #d4edda; padding: 12px; border-radius: 4px; margin-bottom: 15px; border-left: 4px solid #28a745;">';
        print '<strong>✓ Destinatário importado do XML!</strong> O destinatário do CT-e foi preenchido com os dados da NF-e.';
        print '</div>';
    }
    
    // Remetente (dinâmico)
    print '<fieldset class="cte-fieldset">';
    print '<legend>Remetente (Dados da Empresa)</legend>';
    print '<table class="border centpercent">';
    print '<tr><td width="25%" class="fieldrequired">CNPJ/CPF *</td><td><input type="text" name="rem_cnpj" value="'.dol_escape_htmltag($dados['rem_cnpj'] ?? '').'" class="flat minwidth200" required></td></tr>';
    print '<tr><td class="fieldrequired">Razão Social *</td><td><input type="text" name="rem_xNome" value="'.dol_escape_htmltag($dados['rem_xNome'] ?? '').'" class="flat minwidth400" required></td></tr>';
    print '<tr><td>Nome Fantasia</td><td><input type="text" name="rem_xFant" value="'.dol_escape_htmltag($dados['rem_xFant'] ?? '').'" class="flat minwidth400"></td></tr>';
    print '<tr><td>Inscrição Estadual</td><td><input type="text" name="rem_ie" value="'.dol_escape_htmltag($dados['rem_ie'] ?? '').'" class="flat minwidth200"></td></tr>';
    print '<tr><td class="fieldrequired">Logradouro *</td><td><input type="text" name="rem_xLgr" value="'.dol_escape_htmltag($dados['rem_xLgr'] ?? '').'" class="flat minwidth400" required></td></tr>';
    print '<tr><td class="fieldrequired">Número *</td><td><input type="text" name="rem_nro" value="'.dol_escape_htmltag($dados['rem_nro'] ?? '').'" class="flat minwidth100" required></td></tr>';
    print '<tr><td>Complemento</td><td><input type="text" name="rem_xCpl" value="'.dol_escape_htmltag($dados['rem_xCpl'] ?? '').'" class="flat minwidth300"></td></tr>';
    print '<tr><td class="fieldrequired">Bairro *</td><td><input type="text" name="rem_xBairro" value="'.dol_escape_htmltag($dados['rem_xBairro'] ?? '').'" class="flat minwidth200" required></td></tr>';
    print '<tr><td>CEP</td><td><input type="text" name="rem_cep" value="'.dol_escape_htmltag($dados['rem_cep'] ?? '').'" class="flat minwidth100"></td></tr>';
    print '<tr><td class="fieldrequired">UF *</td><td>';
    print '<select name="rem_uf" id="rem_uf" class="flat minwidth150" required onchange="carregarMunicipios(\'rem_uf\', \'rem_xMun\', \'rem_cMun\')">';
    print '<option value="">Carregando...</option>';
    print '</select>';
    print '<input type="hidden" name="rem_cMun" id="rem_cMun" value="'.dol_escape_htmltag($dados['rem_cMun'] ?? '').'">';
    print '</td></tr>';
    
    print '<tr><td class="fieldrequired">Município *</td>';
    print '<td>';
    print '<select name="rem_xMun" id="rem_xMun" class="flat minwidth300" required onchange="setCodigoMunicipio(\'rem_xMun\', \'rem_cMun\')">';
    print '<option value="">Selecione UF primeiro</option>';
    print '</select>';
    print '</td></tr>';
    
    // Contato
    print '<tr><td>Telefone</td><td><input type="text" name="rem_fone" value="'.dol_escape_htmltag($dados['rem_fone'] ?? '').'" class="flat minwidth150"></td></tr>';
    print '<tr><td>Email</td><td><input type="email" name="rem_email" value="'.dol_escape_htmltag($dados['rem_email'] ?? '').'" class="flat minwidth300"></td></tr>';
    print '</table>';
    print '</fieldset>';
    
    // Destinatário (dinâmico)
    print '<fieldset class="cte-fieldset">';
    print '<legend>Destinatário</legend>';
    print '<table class="border centpercent">';
    print '<tr><td width="25%" class="fieldrequired">CNPJ/CPF *</td><td><input type="text" name="dest_cnpj" value="'.dol_escape_htmltag($dados['dest_cnpj'] ?? '').'" class="flat minwidth200" required></td></tr>';
    print '<tr><td class="fieldrequired">Razão Social *</td><td><input type="text" name="dest_xNome" value="'.dol_escape_htmltag($dados['dest_xNome'] ?? '').'" class="flat minwidth400" required></td></tr>';
    print '<tr><td>Inscrição Estadual</td><td><input type="text" name="dest_ie" value="'.dol_escape_htmltag($dados['dest_ie'] ?? '').'" class="flat minwidth200"></td></tr>';
    print '<tr><td class="fieldrequired">Logradouro *</td><td><input type="text" name="dest_xLgr" value="'.dol_escape_htmltag($dados['dest_xLgr'] ?? '').'" class="flat minwidth400" required></td></tr>';
    print '<tr><td class="fieldrequired">Número *</td><td><input type="text" name="dest_nro" value="'.dol_escape_htmltag($dados['dest_nro'] ?? '').'" class="flat minwidth100" required></td></tr>';
    print '<tr><td>Complemento</td><td><input type="text" name="dest_xCpl" value="'.dol_escape_htmltag($dados['dest_xCpl'] ?? '').'" class="flat minwidth300"></td></tr>';
    print '<tr><td class="fieldrequired">Bairro *</td><td><input type="text" name="dest_xBairro" value="'.dol_escape_htmltag($dados['dest_xBairro'] ?? '').'" class="flat minwidth200" required></td></tr>';
    print '<tr><td>CEP</td><td><input type="text" name="dest_cep" value="'.dol_escape_htmltag($dados['dest_cep'] ?? '').'" class="flat minwidth100"></td></tr>';
    print '<tr><td class="fieldrequired">UF *</td><td>';
    print '<select name="dest_uf" id="dest_uf" class="flat minwidth150" required onchange="carregarMunicipios(\'dest_uf\', \'dest_xMun\', \'dest_cMun\')">';
    print '<option value="">Carregando...</option>';
    print '</select>';
    print '<input type="hidden" name="dest_cMun" id="dest_cMun" value="'.dol_escape_htmltag($dados['dest_cMun'] ?? '').'">';
    print '</td></tr>';
    
    print '<tr><td class="fieldrequired">Município *</td>';
    print '<td>';
    print '<select name="dest_xMun" id="dest_xMun" class="flat minwidth300" required onchange="setCodigoMunicipio(\'dest_xMun\', \'dest_cMun\')">';
    print '<option value="">Selecione UF primeiro</option>';
    print '</select>';
    print '</td></tr>';
    
    // Fecha corretamente o table+fieldset do Destinatário
    print '</table>';
    print '</fieldset>';

    // Tomador "Outros" (toma4) - mostrado somente se selecionado (AGORA FORA do fieldset do Destinatário)
    print '<fieldset id="toma4_section" class="cte-fieldset" style="'.((isset($dados['toma']) && $dados['toma']=='4') ? '' : 'display:none;').'">';
    print '<legend>Tomador - Outros</legend>';
    print '<table class="border centpercent">';
    print '<tr><td width="25%"><strong>CNPJ *</strong></td><td><input type="text" name="toma4_CNPJ" value="'.dol_escape_htmltag($dados['toma4_CNPJ'] ?? '').'" class="flat minwidth200"></td></tr>';
    print '<tr><td>CPF</td><td><input type="text" name="toma4_CPF" value="'.dol_escape_htmltag($dados['toma4_CPF'] ?? '').'" class="flat minwidth200"></td></tr>';
    print '<tr><td class="fieldrequired">Razão / Nome *</td><td><input type="text" name="toma4_xNome" value="'.dol_escape_htmltag($dados['toma4_xNome'] ?? '').'" class="flat minwidth400" required></td></tr>';

    // Endereço completo do Tomador 4
    print '<tr><td class="fieldrequired">Logradouro *</td><td><input type="text" name="toma4_xLgr" value="'.dol_escape_htmltag($dados['toma4_xLgr'] ?? '').'" class="flat minwidth400" required></td></tr>';
    print '<tr><td class="fieldrequired">Número *</td><td><input type="text" name="toma4_nro" value="'.dol_escape_htmltag($dados['toma4_nro'] ?? '').'" class="flat minwidth100" required></td></tr>';
    print '<tr><td>Complemento</td><td><input type="text" name="toma4_xCpl" value="'.dol_escape_htmltag($dados['toma4_xCpl'] ?? '').'" class="flat minwidth300"></td></tr>';
    print '<tr><td class="fieldrequired">Bairro *</td><td><input type="text" name="toma4_xBairro" value="'.dol_escape_htmltag($dados['toma4_xBairro'] ?? '').'" class="flat minwidth200" required></td></tr>';
    // REMOVIDO: Linha oculta duplicada de cMun
    print '<tr><td>CEP</td><td><input type="text" name="toma4_cep" value="'.dol_escape_htmltag($dados['toma4_cep'] ?? '').'" class="flat minwidth100"></td></tr>';
    print '<tr><td class="fieldrequired">UF *</td><td>';
    print '<select name="toma4_uf" id="toma4_uf" class="flat minwidth150" onchange="carregarMunicipios(\'toma4_uf\', \'toma4_xMun\', \'toma4_cMun\')" required>';
    print '<option value="">Carregando...</option>';
    print '</select>';
    // ADICIONADO: Input hidden correto para cMun
    print '<input type="hidden" name="toma4_cMun" id="toma4_cMun" value="'.dol_escape_htmltag($dados['toma4_cMun'] ?? '').'">';
    print '</td></tr>';
    print '<tr><td class="fieldrequired">Município *</td><td>';
    print '<select name="toma4_xMun" id="toma4_xMun" class="flat minwidth300" onchange="setCodigoMunicipio(\'toma4_xMun\', \'toma4_cMun\')" required>';
    print '<option value="">Selecione UF primeiro</option>';
    print '</select>';
    print '</td></tr>';

    print '<tr><td>Telefone</td><td><input type="text" name="toma4_fone" value="'.dol_escape_htmltag($dados['toma4_fone'] ?? '').'" class="flat minwidth150"></td></tr>';
    print '<tr><td>Email</td><td><input type="email" name="toma4_email" value="'.dol_escape_htmltag($dados['toma4_email'] ?? '').'" class="flat minwidth300"></td></tr>';
    print '</table>';
    print '</fieldset>';

    // Expedidor (opcional - dinâmico)
    print '<details class="cte-collapsible">';
    print '<summary class="cte-collapsible-header">+ Expedidor (Opcional - Clique para expandir)</summary>';
    print '<div class="cte-collapsible-content">';
    print '<fieldset class="cte-fieldset">';
    print '<legend>Expedidor</legend>';
    print '<table class="border centpercent">';
    print '<tr><td width="25%">CNPJ/CPF</td><td><input type="text" name="exped_cnpj" value="'.dol_escape_htmltag($dados['exped_cnpj'] ?? '').'" class="flat minwidth200"></td></tr>';
    print '<tr><td>Razão Social</td><td><input type="text" name="exped_xNome" value="'.dol_escape_htmltag($dados['exped_xNome'] ?? '').'" class="flat minwidth400"></td></tr>';
    print '<tr><td>Inscrição Estadual</td><td><input type="text" name="exped_ie" value="'.dol_escape_htmltag($dados['exped_ie'] ?? '').'" class="flat minwidth200"></td></tr>';
    print '<tr><td>Logradouro</td><td><input type="text" name="exped_xLgr" value="'.dol_escape_htmltag($dados['exped_xLgr'] ?? '').'" class="flat minwidth400"></td></tr>';
    print '<tr><td>Número</td><td><input type="text" name="exped_nro" value="'.dol_escape_htmltag($dados['exped_nro'] ?? '').'" class="flat minwidth100"></td></tr>';
    print '<tr><td>Bairro</td><td><input type="text" name="exped_xBairro" value="'.dol_escape_htmltag($dados['exped_xBairro'] ?? '').'" class="flat minwidth200"></td></tr>';
    print '<tr><td>Município</td><td><input type="text" name="exped_xMun" value="'.dol_escape_htmltag($dados['exped_xMun'] ?? '').'" class="flat minwidth200"></td></tr>';
    print '<tr><td>CEP</td><td><input type="text" name="exped_cep" value="'.dol_escape_htmltag($dados['exped_cep'] ?? '').'" class="flat minwidth100"></td></tr>';
    print '<tr><td>UF</td><td>';
    print '<select name="exped_uf" id="exped_uf" class="flat minwidth150" onchange="carregarMunicipios(\'exped_uf\', \'exped_xMun\', \'exped_cMun\')">';
    print '<option value="">Carregando...</option>';
    print '</select>';
    print '<input type="hidden" name="exped_cMun" id="exped_cMun" value="'.dol_escape_htmltag($dados['exped_cMun'] ?? '').'">';
    print '</td></tr>';
    
    print '<tr><td>Município</td><td>';
    print '<select name="exped_xMun" id="exped_xMun" class="flat minwidth300" onchange="setCodigoMunicipio(\'exped_xMun\', \'exped_cMun\')">';
    print '<option value="">Selecione UF primeiro</option>';
    print '</select>';
    print '</td></tr>';
    
    print '</table>';
    print '</fieldset>';
    print '</div>';
    print '</details>';
    
    // Recebedor (opcional - dinâmico)
    print '<details class="cte-collapsible">';
    print '<summary class="cte-collapsible-header">+ Recebedor (Opcional - Clique para expandir)</summary>';
    print '<div class="cte-collapsible-content">';
    print '<fieldset class="cte-fieldset">';
    print '<legend>Recebedor</legend>';
    print '<table class="border centpercent">';
    print '<tr><td width="25%">CNPJ/CPF</td><td><input type="text" name="receb_cnpj" value="'.dol_escape_htmltag($dados['receb_cnpj'] ?? '').'" class="flat minwidth200"></td></tr>';
    print '<tr><td>Razão Social</td><td><input type="text" name="receb_xNome" value="'.dol_escape_htmltag($dados['receb_xNome'] ?? '').'" class="flat minwidth400"></td></tr>';
    print '<tr><td>Inscrição Estadual</td><td><input type="text" name="receb_ie" value="'.dol_escape_htmltag($dados['receb_ie'] ?? '').'" class="flat minwidth200"></td></tr>';
    print '<tr><td>Logradouro</td><td><input type="text" name="receb_xLgr" value="'.dol_escape_htmltag($dados['receb_xLgr'] ?? '').'" class="flat minwidth400"></td></tr>';
    print '<tr><td>Número</td><td><input type="text" name="receb_nro" value="'.dol_escape_htmltag($dados['receb_nro'] ?? '').'" class="flat minwidth100"></td></tr>';
    print '<tr><td>Bairro</td><td><input type="text" name="receb_xBairro" value="'.dol_escape_htmltag($dados['receb_xBairro'] ?? '').'" class="flat minwidth200"></td></tr>';
    // REMOVIDO: Linha visível duplicada de cMun (já existe hidden)
    print '<tr><td>Município</td><td><input type="text" name="receb_xMun" value="'.dol_escape_htmltag($dados['receb_xMun'] ?? '').'" class="flat minwidth200"></td></tr>';
    print '<tr><td>CEP</td><td><input type="text" name="receb_cep" value="'.dol_escape_htmltag($dados['receb_cep'] ?? '').'" class="flat minwidth100"></td></tr>';
    print '<tr><td>UF</td><td>';
    print '<select name="receb_uf" id="receb_uf" class="flat minwidth150" onchange="carregarMunicipios(\'receb_uf\', \'receb_xMun\', \'receb_cMun\')">';
    print '<option value="">Carregando...</option>';
    print '</select>';
    print '<input type="hidden" name="receb_cMun" id="receb_cMun" value="'.dol_escape_htmltag($dados['receb_cMun'] ?? '').'">';
    print '</td></tr>';
    
    print '<tr><td>Município</td><td>';
    print '<select name="receb_xMun" id="receb_xMun" class="flat minwidth300" onchange="setCodigoMunicipio(\'receb_xMun\', \'receb_cMun\')">';
    print '<option value="">Selecione UF primeiro</option>';
    print '</select>';
    print '</td></tr>';
    
    print '</table>';
    print '</fieldset>';
    print '</div>';
    print '</details>';
    
    print '</div>';
}

/**
 * Etapa 3: Valores e Impostos
 */
function print_etapa_valores($form, $dados = array())
{
    print '<div class="etapa-content">';
    print '<div class="etapa-header">';
    print '<h3 class="etapa-title">Etapa 3: Valores e Impostos</h3>';
    print '<span class="etapa-badge">3/5</span>';
    print '</div>';
    
    // Aviso se dados foram importados (apenas quando acabou de importar)
    if (!empty($dados['chave_nfe']) && !empty($dados['vCarga']) && !empty($dados['_show_import_message'])) {
        print '<div style="background: #d4edda; padding: 12px; border-radius: 4px; margin-bottom: 15px; border-left: 4px solid #28a745;">';
        print '<strong>✓ Valores importados!</strong> Valor da carga preenchido com base na NF-e.';
        print '</div>';
    }
    
    // // Informações sobre ICMS
    // print '<div style="background: #e7f3ff; padding: 15px; border-radius: 4px; margin-bottom: 20px; border-left: 4px solid #007bff;">';
    // print '<h4 style="margin-top: 0;"><i class="fa fa-info-circle"></i> Informações sobre Tributação</h4>';
    // print '<p><strong>CST 00:</strong> Tributação Normal - Use para transporte com ICMS normal</p>';
    // print '<p><strong>CST 20:</strong> Tributação com Redução de BC - Use quando houver redução de base de cálculo</p>';
    // print '<p><strong>CST 40/41/51:</strong> Isento/Não Tributado/Diferido - Use para operações isentas</p>';
    // print '<p><strong>CST 60:</strong> ICMS cobrado anteriormente por ST - Use quando já houve retenção</p>';
    // print '<p><strong>CST 90:</strong> Outros - Use para situações especiais</p>';
    // print '<p><strong>CST SN:</strong> Simples Nacional - Use se sua empresa é optante pelo Simples</p>';
    // print '</div>';
    
    print '<table class="border centpercent">';
    
    print '<tr><td width="25%" class="fieldrequired">Valor Total da Prestação *</td>';
    print '<td><input type="number" step="0.01" name="vTPrest" id="vTPrest" value="'.($dados['vTPrest'] ?? '').'" class="flat minwidth150" required onchange="calcularICMS()"></td></tr>';
    
    print '<tr><td class="fieldrequired">Valor a Receber *</td>';
    print '<td><input type="number" step="0.01" name="vRec" id="vRec" value="'.($dados['vRec'] ?? '').'" class="flat minwidth150" required></td></tr>';
    
    // Componentes do Valor da Prestação
    print '<tr><td colspan="2"><hr><h4>Componentes do Valor</h4></td></tr>';
    
    print '<tr><td class="fieldrequired">Nome do Componente *</td>';
    print '<td><input type="text" name="comp_xNome" value="'.($dados['comp_xNome'] ?? 'Frete').'" class="flat minwidth300" required></td></tr>';
    
    print '<tr><td class="fieldrequired">Valor do Componente *</td>';
    print '<td><input type="number" step="0.01" name="comp_vComp" id="comp_vComp" value="'.($dados['comp_vComp'] ?? '').'" class="flat minwidth150" required onchange="syncValores()"></td></tr>';
    
    // ICMS
    print '<tr><td colspan="2"><hr><h4>Informações de ICMS</h4></td></tr>';
    
    print '<tr><td class="fieldrequired">CST ICMS *</td>';
    print '<td>';
    print '<select name="cst_icms" id="cst_icms" class="flat minwidth200" required onchange="mostrarCamposICMS()">';
    print '<option value="">Selecione...</option>';
    print '<option value="00"'.($dados['cst_icms'] == '00' ? ' selected' : '').'>Tributação normal</option>';
    print '<option value="20"'.($dados['cst_icms'] == '20' ? ' selected' : '').'>Redução BC</option>';
    print '<option value="40"'.($dados['cst_icms'] == '40' ? ' selected' : '').'>Isenta</option>';
    print '<option value="41"'.($dados['cst_icms'] == '41' ? ' selected' : '').'>Não tributada</option>';
    print '<option value="51"'.($dados['cst_icms'] == '51' ? ' selected' : '').'>Diferimento</option>';
    print '<option value="60"'.($dados['cst_icms'] == '60' ? ' selected' : '').'>ICMS cobrado anteriormente</option>';
    print '<option value="90"'.($dados['cst_icms'] == '90' ? ' selected' : '').'>Outros</option>';
    print '<option value="SN"'.($dados['cst_icms'] == 'SN' ? ' selected' : '').'>Simples Nacional</option>';
    print '</select>';
    print '</td></tr>';
    
    // Campos condicionais de ICMS
    print '<tbody id="campos_icms_00" style="display: none;">';
    print '<tr><td>Base de Cálculo ICMS</td>';
    print '<td><input type="number" step="0.01" name="vBC" id="vBC" value="'.($dados['vBC'] ?? '').'" class="flat minwidth150" onchange="calcularICMS()"></td></tr>';
    
    print '<tr><td>Alíquota ICMS (%)</td>';
    print '<td><input type="number" step="0.01" name="pICMS" id="pICMS" value="'.($dados['pICMS'] ?? '').'" class="flat minwidth100" onchange="calcularICMS()"></td></tr>';
    
    // print '<tr><td>Valor ICMS <span id="icms_calculado" style="color: #28a745; font-weight: bold;"></span></td>';
    print '<tr><td>Valor do ICMS</td>';
    print '<td><input type="number" step="0.01" name="vICMS" id="vICMS" value="'.($dados['vICMS'] ?? '').'" class="flat minwidth150" readonly></td></tr>';
    print '</tbody>';
    
    print '<tbody id="campos_icms_20" style="display: none;">';
    print '<tr><td>% Redução BC</td>';
    print '<td><input type="number" step="0.01" name="pRedBC" id="pRedBC" value="'.($dados['pRedBC'] ?? '').'" class="flat minwidth100" onchange="calcularICMS()"></td></tr>';
    
    print '<tr><td>Base de Cálculo ICMS (após redução)</td>';
    print '<td><input type="number" step="0.01" name="vBC_red" id="vBC_red" value="'.($dados['vBC'] ?? '').'" class="flat minwidth150" readonly></td></tr>';
    
    print '<tr><td>Alíquota ICMS (%)</td>';
    print '<td><input type="number" step="0.01" name="pICMS_red" id="pICMS_red" value="'.($dados['pICMS'] ?? '').'" class="flat minwidth100" onchange="calcularICMS()"></td></tr>';
    
    print '<tr><td>Valor ICMS <span id="icms_calculado_red" style="color: #28a745; font-weight: bold;"></span></td>';
    print '<td><input type="number" step="0.01" name="vICMS_red" id="vICMS_red" value="'.($dados['vICMS'] ?? '').'" class="flat minwidth150" readonly></td></tr>';
    print '</tbody>';
    
    print '<tbody id="campos_icms_60" style="display: none;">';
    print '<tr><td>BC ICMS ST Retido</td>';
    print '<td><input type="number" step="0.01" name="vBCSTRet" value="'.($dados['vBCSTRet'] ?? '').'" class="flat minwidth150"></td></tr>';
    
    print '<tr><td>Valor ICMS ST Retido</td>';
    print '<td><input type="number" step="0.01" name="vICMSSTRet" value="'.($dados['vICMSSTRet'] ?? '').'" class="flat minwidth150"></td></tr>';
    
    print '<tr><td>% ICMS ST Retido</td>';
    print '<td><input type="number" step="0.01" name="pICMSSTRet" value="'.($dados['pICMSSTRet'] ?? '').'" class="flat minwidth100"></td></tr>';
    
    print '<tr><td>Valor Crédito Presumido</td>';
    print '<td><input type="number" step="0.01" name="vCred" value="'.($dados['vCred'] ?? '').'" class="flat minwidth150"></td></tr>';
    print '</tbody>';
    
    // Campos comuns
    print '<tr><td>Valor Total Tributos (Lei da Transparência)</td>';
    print '<td><input type="number" step="0.01" name="vTotTrib" value="'.($dados['vTotTrib'] ?? '').'" class="flat minwidth150"></td></tr>';
    
    print '<tr><td>Informações Adicionais Fisco</td>';
    print '<td><textarea name="infAdFisco" rows="2" class="flat" style="width: 100%;">'.($dados['infAdFisco'] ?? '').'</textarea></td></tr>';
    
    // Valor da Carga
    print '<tr><td colspan="2"><hr><h4>Valor da Carga</h4></td></tr>';
    
    print '<tr><td class="fieldrequired">Valor da Carga *</td>';
    print '<td><input type="number" step="0.01" name="vCarga" value="'.($dados['vCarga'] ?? '').'" class="flat minwidth150"></td></tr>';
    
    print '<tr><td class="fieldrequired">Produto Predominante *</td>';
    print '<td><input type="text" name="proPred" value="'.($dados['proPred'] ?? '').'" class="flat minwidth300" required></td></tr>';
    
    print '<tr><td>Outras Características</td>';
    print '<td><input type="text" name="xOutCat" value="'.($dados['xOutCat'] ?? '').'" class="flat minwidth300"></td></tr>';
    
    print '<tr><td>Valor para Averbação</td>';
    print '<td><input type="number" step="0.01" name="vCargaAverb" value="'.($dados['vCargaAverb'] ?? '').'" class="flat minwidth150"></td></tr>';
    
    print '</table>';
    print '</div>';
}

/**
 * Etapa 4: Carga e Documentos
 */
function print_etapa_carga($form, $dados = array())
{
    print '<div class="etapa-content">';
    print '<div class="etapa-header">';
    print '<h3 class="etapa-title">Etapa 4: Informações de Carga</h3>';
    print '<span class="etapa-badge">4/5</span>';
    print '</div>';
    
    // Aviso se dados foram importados (apenas quando acabou de importar)
    if (!empty($dados['chave_nfe']) && !empty($dados['_show_import_message'])) {
        print '<div style="background: #d4edda; padding: 12px; border-radius: 4px; margin-bottom: 15px; border-left: 4px solid #28a745;">';
        print '<strong>✓ Documento originário importado!</strong> Chave da NF-e: ' . $dados['chave_nfe'];
        print '</div>';
    }
    
    print '<table class="border centpercent">';
    
    // Informações de Quantidade da Carga
    print '<tr><td colspan="2"><h4>Quantidade da Carga</h4></td></tr>';
    
    print '<tr><td width="25%" class="fieldrequired">Quantidade *</td>';
    print '<td><input type="number" step="0.0001" name="qCarga" value="'.($dados['qCarga'] ?? '').'" class="flat minwidth100" required></td></tr>';
    
    print '<tr><td class="fieldrequired">Unidade de Medida *</td>';
    print '<td>';
    print '<select name="cUnid" class="flat minwidth150" required>';
    print '<option value="">Selecione...</option>';
    print '<option value="00"'.($dados['cUnid'] == '00' ? ' selected' : '').'>M3</option>';
    print '<option value="01"'.($dados['cUnid'] == '01' ? ' selected' : '').'>KG</option>';
    print '<option value="02"'.($dados['cUnid'] == '02' ? ' selected' : '').'>TON</option>';
    print '<option value="03"'.($dados['cUnid'] == '03' ? ' selected' : '').'>UNIDADE</option>';
    print '<option value="04"'.($dados['cUnid'] == '04' ? ' selected' : '').'>LITROS</option>';
    print '<option value="05"'.($dados['cUnid'] == '05' ? ' selected' : '').'>MMBTU</option>';
    print '</select>';
    print '</td></tr>';
    
    print '<tr><td class="fieldrequired">Tipo de Medida *</td>';
    print '<td><input type="text" name="tpMed" value="'.($dados['tpMed'] ?? 'PESO BRUTO').'" class="flat minwidth200" required></td></tr>';
    
    // Modal Rodoviário
    print '<tr><td colspan="2"><hr><h4>Modal Rodoviário</h4></td></tr>';
    
    print '<tr><td class="fieldrequired">RNTRC *</td>';
    print '<td><input type="text" name="RNTRC" value="'.($dados['RNTRC'] ?? '').'" class="flat minwidth200" required></td></tr>';
    
    // Documentos Originários
    print '<tr><td colspan="2"><hr><h4>Documentos Originários</h4></td></tr>';
    
    print '<tr><td class="fieldrequired">Chave NF-e *</td>';
    print '<td><input type="text" name="chave_nfe" value="'.($dados['chave_nfe'] ?? '').'" class="flat minwidth500" placeholder="44 dígitos da chave de acesso" maxlength="44" required></td></tr>';
    
    print '<tr><td>PIN SUFRAMA</td>';
    print '<td><input type="text" name="PIN" value="'.($dados['PIN'] ?? '').'" class="flat minwidth150"></td></tr>';
    
    print '<tr><td>Data Prevista Entrega</td>';
    print '<td><input type="date" name="dPrev" value="'.($dados['dPrev'] ?? '').'" class="flat minwidth150"></td>';
    
    print '</table>';
    print '</div>';
}

/**
 * Etapa 5: Complementos
 */
function print_etapa_complementos($form, $dados = array())
{
    print '<div class="etapa-content">';
    print '<div class="etapa-header">';
    print '<h3 class="etapa-title">Etapa 5: Informações Complementares</h3>';
    print '<span class="etapa-badge">5/5</span>';
    print '</div>';
    
    print '<table class="border centpercent">';
    
    // Fluxo de Carga
    print '<tr><td colspan="2"><h4>Fluxo de Carga (Opcional)</h4></td></tr>';
    
    print '<tr><td width="25%">Sigla ou Código Interno da Origem</td>';
    print '<td><input type="text" name="fluxo_xOrig" value="'.($dados['fluxo_xOrig'] ?? '').'" class="flat minwidth200"></td></tr>';
    
    print '<tr><td>Sigla ou Código Interno do Destino</td>';
    print '<td><input type="text" name="fluxo_xDest" value="'.($dados['fluxo_xDest'] ?? '').'" class="flat minwidth200"></td></tr>';
    
    print '<tr><td>Sigla ou Código Interno da Rota</td>';
    print '<td><input type="text" name="fluxo_xRota" value="'.($dados['fluxo_xRota'] ?? '').'" class="flat minwidth200"></td></tr>';
    
    // Seguro
    print '<tr><td colspan="2"><hr><h4>Seguro da Carga</h4></td></tr>';
    
    print '<tr><td>Responsável pelo Seguro</td>';
    print '<td>';
    print '<select name="respSeg" class="flat minwidth200">';
    print '<option value="">Não informado</option>';
    print '<option value="0"'.($dados['respSeg'] == '0' ? ' selected' : '').'>Remetente</option>';
    print '<option value="1"'.($dados['respSeg'] == '1' ? ' selected' : '').'>Expedidor</option>';
    print '<option value="2"'.($dados['respSeg'] == '2' ? ' selected' : '').'>Recebedor</option>';
    print '<option value="3"'.($dados['respSeg'] == '3' ? ' selected' : '').'>Destinatário</option>';
    print '<option value="4"'.($dados['respSeg'] == '4' ? ' selected' : '').'>Emitente do CT-e</option>';
    print '<option value="5"'.($dados['respSeg'] == '5' ? ' selected' : '').'>Tomador do Serviço</option>';
    print '</select>';
    print '</td></tr>';
    
    print '<tr><td>Nome Seguradora</td>';
    print '<td><input type="text" name="xSeg" value="'.($dados['xSeg'] ?? '').'" class="flat minwidth300"></td></tr>';
    
    print '<tr><td>Número Apólice</td>';
    print '<td><input type="text" name="nApol" value="'.($dados['nApol'] ?? '').'" class="flat minwidth200"></td></tr>';
    
    print '<tr><td>Número Averbação</td>';
    print '<td><input type="text" name="nAver" value="'.($dados['nAver'] ?? '').'" class="flat minwidth200"></td></tr>';
    
    // Documentos Anteriores (para CT-e de Substituição/Complemento)
    print '<tr><td colspan="2"><hr><h4>Documentos de Transporte Anterior</h4></td></tr>';
    
    print '<tr><td>Emitente Documento Anterior - CNPJ</td>';
    print '<td><input type="text" name="emiDocAnt_CNPJ" value="'.($dados['emiDocAnt_CNPJ'] ?? '').'" class="flat minwidth200"></td></tr>';
    
    print '<tr><td>Emitente Documento Anterior - CPF</td>';
    print '<td><input type="text" name="emiDocAnt_CPF" value="'.($dados['emiDocAnt_CPF'] ?? '').'" class="flat minwidth200"></td></tr>';
    
    print '<tr><td>Emitente Documento Anterior - IE</td>';
    print '<td><input type="text" name="emiDocAnt_IE" value="'.($dados['emiDocAnt_IE'] ?? '').'" class="flat minwidth200"></td></tr>';
    
    print '<tr><td>Emitente Documento Anterior - UF</td>';
    print '<td>';
    print '<select name="emiDocAnt_UF" class="flat minwidth100">';
    print '<option value="">Selecione...</option>';
    $ufs = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
    foreach($ufs as $uf) {
        print '<option value="'.$uf.'"'.($dados['emiDocAnt_UF'] == $uf ? ' selected' : '').'>'.$uf.'</option>';
    }
    print '</select>';
    print '</td></tr>';
    
    print '<tr><td>Emitente Documento Anterior - Nome</td>';
    print '<td><input type="text" name="emiDocAnt_xNome" value="'.($dados['emiDocAnt_xNome'] ?? '').'" class="flat minwidth400"></td></tr>';
    
    print '<tr><td>Chave CT-e Anterior</td>';
    print '<td><input type="text" name="chCTeAnt" value="'.($dados['chCTeAnt'] ?? '').'" class="flat minwidth500" placeholder="44 dígitos" maxlength="44"></td></tr>';
    
    // Modal Aéreo (só exibe se modal == 02)
    print '<tr id="row_modal_aereo" style="'.(isset($dados['modal']) && $dados['modal']=='02' ? '' : 'display:none;').'"><td colspan="2"><hr><h4>Informações Modal Aéreo</h4></td></tr>';
    
    print '<tr id="row_nMinu" class="fieldrequired" style="'.(isset($dados['modal']) && $dados['modal']=='02' ? '' : 'display:none;').'"><td>Número da Minuta *</td>';
    print '<td><input type="text" name="aereo_nMinu" value="'.($dados['aereo_nMinu'] ?? '').'" class="flat minwidth200"></td></tr>';
    
    print '<tr id="row_nOCA" class="fieldrequired" style="'.(isset($dados['modal']) && $dados['modal']=='02' ? '' : 'display:none;').'"><td>Número Operacional *</td>';
    print '<td><input type="text" name="aereo_nOCA" value="'.($dados['aereo_nOCA'] ?? '').'" class="flat minwidth200"></td></tr>';
    
    print '<tr id="row_dPrevAereo" style="'.(isset($dados['modal']) && $dados['modal']=='02' ? '' : 'display:none;').'"><td>Data Prevista Entrega (Aéreo)</td>';
    print '<td><input type="date" name="aereo_dPrevAereo" value="'.($dados['aereo_dPrevAereo'] ?? '').'" class="flat minwidth150"></td></tr>';
    
    print '<tr id="row_tarifa_CL" style="'.(isset($dados['modal']) && $dados['modal']=='02' ? '' : 'display:none;').'"><td>Classe Tarifa</td>';
    print '<td><select name="aereo_tarifa_CL" class="flat minwidth150">';
    print '<option value="">Selecione...</option>';
    print '<option value="M"'.($dados['aereo_tarifa_CL'] == 'M' ? ' selected' : '').'>M - Tarifa Mínima</option>';
    print '<option value="G"'.($dados['aereo_tarifa_CL'] == 'G' ? ' selected' : '').'>G - Tarifa Geral</option>';
    print '<option value="E"'.($dados['aereo_tarifa_CL'] == 'E' ? ' selected' : '').'>E - Tarifa Específica</option>';
    print '</select></td></tr>';
    
    print '<tr id="row_tarifa_vTar" style="'.(isset($dados['modal']) && $dados['modal']=='02' ? '' : 'display:none;').'"><td>Valor da Tarifa (por kg)</td>';
    print '<td><input type="number" step="0.01" name="aereo_tarifa_vTar" value="'.($dados['aereo_tarifa_vTar'] ?? '').'" class="flat minwidth150"></td></tr>';
    
    // Modal Aquaviário (só exibe se modal == 03)
    print '<tr id="row_modal_aquav" style="'.(isset($dados['modal']) && $dados['modal']=='03' ? '' : 'display:none;').'"><td colspan="2"><hr><h4>Informações Modal Aquaviário</h4></td></tr>';
    
    print '<tr id="row_vPrest_aquav" style="'.(isset($dados['modal']) && $dados['modal']=='03' ? '' : 'display:none;').'"><td>Valor da Prestação Base Cálculo AFRMM</td>';
    print '<td><input type="number" step="0.01" name="aquav_vPrest" value="'.($dados['aquav_vPrest'] ?? '').'" class="flat minwidth150"></td></tr>';
    
    print '<tr id="row_vAFRMM" style="'.(isset($dados['modal']) && $dados['modal']=='03' ? '' : 'display:none;').'"><td>Valor AFRMM</td>';
    print '<td><input type="number" step="0.01" name="aquav_vAFRMM" value="'.($dados['aquav_vAFRMM'] ?? '').'" class="flat minwidth150"></td></tr>';
    
    print '<tr id="row_xNavio" style="'.(isset($dados['modal']) && $dados['modal']=='03' ? '' : 'display:none;').'"><td>Nome do Navio</td>';
    print '<td><input type="text" name="aquav_xNavio" value="'.($dados['aquav_xNavio'] ?? '').'" class="flat minwidth300"></td></tr>';
    
    print '<tr id="row_xBalsa" style="'.(isset($dados['modal']) && $dados['modal']=='03' ? '' : 'display:none;').'"><td>Identificação da balsa</td>';
    print '<td><input type="text" name="balsa_xBalsa" value="'.($dados['balsa_xBalsa'] ?? '').'" class="flat minwidth200"></td></tr>';

    print '<tr id="row_nViag" style="'.(isset($dados['modal']) && $dados['modal']=='03' ? '' : 'display:none;').'"><td>Número da Viagem</td>';
    print '<td><input type="text" name="aquav_nViag" value="'.($dados['aquav_nViag'] ?? '').'" class="flat minwidth200"></td></tr>';

    print '<tr id="row_irin" style="'.(isset($dados['modal']) && $dados['modal']=='03' ? '' : 'display:none;').'"><td>Irin do navio</td>';
    print '<td><input type="text" name="aquav_irin" value="'.($dados['aquav_irin'] ?? '').'" class="flat minwidth200"></td></tr>';
    
    print '<tr id="row_direc" style="'.(isset($dados['modal']) && $dados['modal']=='03' ? '' : 'display:none;').'"><td>Direção</td>';
    print '<td><select name="aquav_direc" class="flat minwidth150">';
    print '<option value="">Selecione...</option>';
    print '<option value="N"'.($dados['aquav_direc'] == 'N' ? ' selected' : '').'>Norte</option>';
    print '<option value="L"'.($dados['aquav_direc'] == 'L' ? ' selected' : '').'>Leste</option>';
    print '<option value="S"'.($dados['aquav_direc'] == 'S' ? ' selected' : '').'>Sul</option>';
    print '<option value="O"'.($dados['aquav_direc'] == 'O' ? ' selected' : '').'>Oeste</option>';
    print '</select></td></tr>';
    
    print '<tr id="row_tpNav" style="'.(isset($dados['modal']) && $dados['modal']=='03' ? '' : 'display:none;').'"><td>Tipo de Navegação</td>';
    print '<td><select name="aquav_tpNav" class="flat minwidth200">';
    print '<option value="">Selecione...</option>';
    print '<option value="0"'.($dados['aquav_tpNav'] == '0' ? ' selected' : '').'>0 - Interior</option>';
    print '<option value="1"'.($dados['aquav_tpNav'] == '1' ? ' selected' : '').'>1 - Cabotagem</option>';
    print '</select></td></tr>';
    
    // Modal Ferroviário (só exibe se modal == 04)
    print '<tr id="row_modal_ferrov" style="'.(isset($dados['modal']) && $dados['modal']=='04' ? '' : 'display:none;').'"><td colspan="2"><hr><h4>Informações Modal Ferroviário</h4></td></tr>';
    
    print '<tr id="row_tpTraf" style="'.(isset($dados['modal']) && $dados['modal']=='04' ? '' : 'display:none;').'"><td>Tipo de Tráfego</td>';
    print '<td><select name="ferrov_tpTraf" class="flat minwidth200">';
    print '<option value="">Selecione...</option>';
    print '<option value="0"'.($dados['ferrov_tpTraf'] == '0' ? ' selected' : '').'>0 - Próprio</option>';
    print '<option value="1"'.($dados['ferrov_tpTraf'] == '1' ? ' selected' : '').'>1 - Mútuo</option>';
    print '<option value="2"'.($dados['ferrov_tpTraf'] == '2' ? ' selected' : '').'>2 - Rodoferroviário</option>';
    print '<option value="3"'.($dados['ferrov_tpTraf'] == '3' ? ' selected' : '').'>3 - Rodoviário</option>';
    print '</select></td></tr>';
    
    print '<tr id="row_fluxo_ferrov" style="'.(isset($dados['modal']) && $dados['modal']=='04' ? '' : 'display:none;').'"><td>Fluxo Ferroviário</td>';
    print '<td><input type="text" name="ferrov_fluxo" value="'.($dados['ferrov_fluxo'] ?? '').'" class="flat minwidth300"></td></tr>';
    
    // Modal Dutoviário (só exibe se modal == 05)
    print '<tr id="row_modal_duto" style="'.(isset($dados['modal']) && $dados['modal']=='05' ? '' : 'display:none;').'"><td colspan="2"><hr><h4>Informações Modal Dutoviário</h4></td></tr>';
    
    print '<tr id="row_vTar_duto" style="'.(isset($dados['modal']) && $dados['modal']=='05' ? '' : 'display:none;').'"><td>Valor da Tarifa</td>';
    print '<td><input type="number" step="0.01" name="duto_vTar" value="'.($dados['duto_vTar'] ?? '').'" class="flat minwidth150"></td></tr>';
    
    print '<tr id="row_dIni_duto" style="'.(isset($dados['modal']) && $dados['modal']=='05' ? '' : 'display:none;').'"><td>Data Início Prestação</td>';
    print '<td><input type="date" name="duto_dIni" value="'.($dados['duto_dIni'] ?? '').'" class="flat minwidth150"></td></tr>';
    
    print '<tr id="row_dFim_duto" style="'.(isset($dados['modal']) && $dados['modal']=='05' ? '' : 'display:none;').'"><td>Data Fim Prestação</td>';
    print '<td><input type="date" name="duto_dFim" value="'.($dados['duto_dFim'] ?? '').'" class="flat minwidth150"></td></tr>';
    
    // Modal Multimodal (só exibe se modal == 06)
    print '<tr id="row_modal_multi" style="'.(isset($dados['modal']) && $dados['modal']=='06' ? '' : 'display:none;').'"><td colspan="2"><hr><h4>Informações Modal Multimodal</h4></td></tr>';
    
    print '<tr id="row_COTM" style="'.(isset($dados['modal']) && $dados['modal']=='06' ? '' : 'display:none;').'"><td>Código Operacional</td>';
    print '<td><input type="text" name="multi_COTM" value="'.($dados['multi_COTM'] ?? '').'" class="flat minwidth200"></td></tr>';
    
    print '<tr id="row_indNegociavel" style="'.(isset($dados['modal']) && $dados['modal']=='06' ? '' : 'display:none;').'"><td>Indicador Negociável</td>';
    print '<td><select name="multi_indNegociavel" class="flat minwidth150">';
    print '<option value="">Selecione...</option>';
    print '<option value="0"'.($dados['multi_indNegociavel'] == '0' ? ' selected' : '').'>0 - Não Negociável</option>';
    print '<option value="1"'.($dados['multi_indNegociavel'] == '1' ? ' selected' : '').'>1 - Negociável</option>';
    print '</select></td></tr>';
    
    // Observações
    print '<tr><td colspan="2"><hr><h4>Observações e Informações Adicionais</h4></td></tr>';
    
    print '<tr><td>Características Adicionais</td>';
    print '<td><textarea name="xCaracAd" rows="2" class="flat" style="width: 100%;">'.($dados['xCaracAd'] ?? '').'</textarea></td></tr>';
    
    print '<tr><td>Características do Serviço</td>';
    print '<td><textarea name="xCaracSer" rows="2" class="flat" style="width: 100%;">'.($dados['xCaracSer'] ?? '').'</textarea></td></tr>';
    
    print '<tr><td>Observações do Contribuinte</td>';
    print '<td><textarea name="xObs" rows="4" class="flat" style="width: 100%;">'.($dados['xObs'] ?? '').'</textarea></td></tr>';
 
    print '<tr><td>Informações Adicionais Fisco</td>';
    print '<td><textarea name="infAdFisco" rows="3" class="flat" style="width: 100%;">'.($dados['infAdFisco'] ?? '').'</textarea></td></tr>';
    
    print '</table>';
    print '</div>';
}

/**
 * Exibir erros de debug
 */
function print_debug_errors($erros)
{
    print '<div style="margin-top: 20px; padding: 20px; background: #fff3cd; border: 2px solid #ffc107; border-radius: 8px;">';
    print '<h3 style="color: #856404; margin-top: 0;">⚠️ Detalhes dos Erros de Validação</h3>';
    
    if (!empty($erros['mensagem'])) {
        print '<div style="margin-bottom: 15px;">';
        print '<strong>Mensagem Principal:</strong><br>';
        print '<code>' . htmlspecialchars($erros['mensagem']) . '</code>';
        print '</div>';
    }
    
    if (!empty($erros['erros_tag'])) {
        print '<div style="margin-bottom: 15px;">';
        print '<strong>Erros nas Tags:</strong>';
        print '<ul style="margin: 10px 0; padding-left: 20px;">';
        foreach ($erros['erros_tag'] as $erro) {
            print '<li><code>' . htmlspecialchars($erro) . '</code></li>';
        }
        print '</ul>';
        print '</div>';
    }
    
    if (!empty($erros['dados_enviados'])) {
        print '<details style="margin-top: 15px;">';
        print '<summary style="cursor: pointer; font-weight: bold; padding: 10px; background: #fff; border: 1px solid #ddd; border-radius: 4px;">Ver Dados Enviados (Debug)</summary>';
        print '<pre style="margin-top: 10px; padding: 15px; background: #f8f9fa; border: 1px solid #ddd; border-radius: 4px; overflow-x: auto; font-size: 12px;">';
        print htmlspecialchars(print_r($erros['dados_enviados'], true));
        print '</pre>';
        print '</details>';
    }    
    print '</div>';
}

/**
 * Botões de navegação
 */
function print_navigation_buttons($etapa)
{
    print '<div class="cte-navigation">';
    
    print '<div class="cte-nav-left">';
    // Botão Voltar
    if ($etapa > 1) {
        print '<a href="'.$_SERVER["PHP_SELF"].'?etapa='.($etapa-1).'" class="butAction">';
        print '<span class="fa fa-arrow-left"></span> Voltar';
        print '</a>';
    }
    print '</div>';
    
    print '<div class="cte-nav-right">';
    // Botão Próximo ou Finalizar
    if ($etapa < 5) {
        print '<button type="submit" class="butAction">';
        print 'Próxima <span class="fa fa-arrow-right"></span>';
        print '</button>';
    } else {
        print '<button type="submit" class="butAction cte-btn-emitir">';
        print '<span class="fa fa-check"></span> Emitir CT-e';
        print '</button>';
    }
    print '</div>';
    
    print '</div>';
}

/**
 * CSS customizado
 */
function print_custom_css()
{
    print '<style>
    .cte-wizard-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
    }
    
    /* Barra de Ações - Movida para esquerda */
    .cte-action-bar {
        display: flex;
        justify-content: flex-start;
        gap: 10px;
        margin-bottom: 20px;
        padding: 15px;
        background: #f8f9fa;
        border-radius: 8px;
        border: 1px solid #e0e0e0;
    }
    
    .cte-action-bar .butAction,
    .cte-action-bar .butActionDelete {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 20px;
        font-size: 14px;
        font-weight: 600;
        border-radius: 6px;
        transition: all 0.3s ease;
    }
    
    .cte-action-bar .fa {
        font-size: 14px;
    }
    
    .cte-action-bar .butAction {
        background: #007bff;
        color: white;
        border: none;
    }
    
    .cte-action-bar .butAction:hover {
        background: #0056b3;
    }
    
    .cte-action-bar .butActionDelete {
        background: #dc3545;
        color: white;
        border: none;
    }
    
    .cte-action-bar .butActionDelete:hover {
        background: #c82333;
    }
    
    .cte-action-bar .button {
        background: #6c757d;
        color: white;
        border: none;
    }
    
    .cte-action-bar .button:hover {
        background: #5a6268;
    }
    
    /* Container principal */
    .cte-wizard-container {
        background: #ffffff;
        padding: 30px;
        border-radius: 8px;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        margin-bottom: 30px;
    }
    
    /* Progresso */
    .cte-progress-bar {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 40px;
        padding: 20px;
        background: #f8f9fa;
        border-radius: 8px;
    }
    
    .step {
        display: flex;
        flex-direction: column;
        align-items: center;
        flex: 1;
    }
    
    .step-number {
        width: 40px;
        height: 40px;
        border-radius: 50%;
        background: #ccc;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        color: white;
        margin-bottom: 8px;
    }
    
    .step.active .step-number {
        background: #007bff;
    }
    
    .step.completed .step-number {
        background: #28a745;
    }
    
    .step-name {
        font-size: 12px;
        text-align: center;
    }
    
    .step-line {
        height: 2px;
        background: #ccc;
        flex: 1;
        margin: 0 10px;
        margin-top: -20px;
    }
    
    .step-line.completed {
        background: #28a745;
    }
    
    .etapa-content {
        background: white;
        padding: 30px;
        border-radius: 8px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        margin-bottom: 20px;
    }
    
    /* Header das Etapas */
    .etapa-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        padding-bottom: 10px;
        border-bottom: 2px solid #007bff;
    }
    
    .etapa-title {
        margin: 0;
        color: #333;
        font-size: 20px;
        font-weight: 600;
    }
    
    .etapa-badge {
        background: #007bff;
        color: white;
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: bold;
    }
    
    /* MELHORIAS VISUAIS SUTIS NOS CAMPOS */
    
    /* Inputs, Selects e Textareas */
    .etapa-content input[type="text"],
    .etapa-content input[type="number"],
    .etapa-content input[type="email"],
    .etapa-content input[type="date"],
    .etapa-content input[type="time"],
    .etapa-content select,
    .etapa-content textarea {
        padding: 8px 12px;
        border: 1.5px solid #ced4da;
        border-radius: 4px;
        font-size: 14px;
        color: #495057;
        background-color: #fff;
        transition: all 0.2s ease;
        font-family: inherit;
    }
    
    /* Focus melhorado */
    .etapa-content input:focus,
    .etapa-content select:focus,
    .etapa-content textarea:focus {
        outline: none;
        border-color: #007bff;
        box-shadow: 0 0 0 3px rgba(0, 123, 255, 0.1);
    }
    
    /* Hover sutil */
    .etapa-content input:not(:read-only):hover,
    .etapa-content select:hover,
    .etapa-content textarea:hover {
        border-color: #80bdff;
    }
    
    /* Readonly */
    .etapa-content input:read-only {
        background-color: #e9ecef;
        cursor: not-allowed;
        color: #6c757d;
    }
    
    /* Select com seta */
    .etapa-content select {
        cursor: pointer;
        appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'12\' height=\'12\' viewBox=\'0 0 12 12\'%3E%3Cpath fill=\'%23495057\' d=\'M6 9L1 4h10z\'/%3E%3C/svg%3E");
        background-repeat: no-repeat;
        background-position: right 12px center;
        padding-right: 36px;
    }
    
    /* Textarea */
    .etapa-content textarea {
        resize: vertical;
        min-height: 80px;
        line-height: 1.5;
    }
    
    /* Placeholder */
    .etapa-content input::placeholder,
    .etapa-content textarea::placeholder {
        color: #adb5bd;
        font-style: italic;
    }
    
    /* Separadores */
    .etapa-content hr {
        margin: 25px 0 20px 0;
        border: none;
        border-top: 2px solid #e9ecef;
    }
    
    .etapa-content h4 {
        color: #007bff;
        font-size: 16px;
        font-weight: 600;
        margin: 20px 0 15px 0;
        padding-bottom: 8px;
        border-bottom: 2px solid #e7f3ff;
    }
    
    /* Fieldsets */
    .cte-fieldset {
        border: 2px solid #e9ecef;
        padding: 20px;
        margin-bottom: 25px;
        border-radius: 8px;
        background: #fafbfc;
    }
    
    .cte-fieldset legend {
        font-weight: 600;
        color: #007bff;
        padding: 0 15px;
        font-size: 16px;
        background: white;
        border-radius: 4px;
        border: 1px solid #007bff;
    }
    
    /* Colapsáveis */
    .cte-collapsible {
        margin-bottom: 20px;
        border: 1px solid #ddd;
        border-radius: 4px;
        background: #f9f9f9;
    }
    
    .cte-collapsible-header {
        padding: 15px;
        cursor: pointer;
        font-weight: bold;
        color: #007bff;
        list-style: none;
        user-select: none;
        transition: background 0.3s;
    }
    
    .cte-collapsible-header:hover {
        background: #e9ecef;
    }
    
    .cte-collapsible[open] .cte-collapsible-header {
        border-bottom: 1px solid #ddd;
        background: #e7f3ff;
    }
    
    .cte-collapsible-content {
        padding: 15px;
    }
    
    .cte-collapsible summary::-webkit-details-marker,
    .cte-collapsible summary::marker {
        display: none;
    }
    
    /* Modal */
    .cte-modal {
        display: none;
        position: fixed;
        z-index: 9999;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0, 0, 0, 0.6);
        backdrop-filter: blur(5px);
    }
    
    .cte-modal-content {
        background-color: white;
        margin: 5% auto;
        width: 90%;
        max-width: 600px;
        border-radius: 12px;
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
    }
    
    .cte-modal-header {
        background: #007bff;
        padding: 20px 25px;
        border-radius: 12px 12px 0 0;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }
    
    .cte-modal-header h2 {
        margin: 0;
        color: white;
        font-size: 20px;
        font-weight: 600;
    }
    
    .cte-modal-close {
        color: white;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
        transition: transform 0.2s;
        line-height: 1;
    }
    
    .cte-modal-close:hover {
        transform: rotate(90deg);
    }
    
    .cte-modal-body {
        padding: 25px;
    }
    
    .cte-modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        margin-top: 20px;
    }
    
    /* Upload Area */
    .cte-upload-area {
        border: 3px dashed #007bff;
        border-radius: 8px;
        padding: 40px 20px;
        text-align: center;
        background: #f8f9ff;
        transition: all 0.3s ease;
        cursor: pointer;
    }
    
    .cte-upload-area:hover {
        background: #f0f2ff;
        border-color: #0056b3;
    }
    
    .cte-upload-area.dragover {
        background: #e7eaff;
        border-color: #28a745;
    }
    
    .cte-upload-icon {
        font-size: 48px;
        margin-bottom: 10px;
    }
    
    .cte-upload-text {
        font-size: 16px;
        color: #333;
        margin: 10px 0;
        font-weight: 500;
    }
    
    .cte-upload-hint {
        font-size: 12px;
        color: #999;
        margin-top: 10px;
    }
    
    /* Summary Card */
    .cte-summary-card {
        background: #d4edda;
        border: 1px solid #c3e6cb;
        border-radius: 8px;
        margin-bottom: 20px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
    }
    
    .cte-summary-header {
        background: #28a745;
        color: white;
        padding: 12px 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 15px;
    }
    
    .cte-summary-icon {
        background: white;
        color: #28a745;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-weight: bold;
        font-size: 14px;
    }
    
    .cte-summary-close {
        margin-left: auto;
        background: none;
        border: none;
        color: white;
        font-size: 20px;
        cursor: pointer;
        padding: 0;
        width: 24px;
        height: 24px;
        line-height: 1;
    }
    
    .cte-summary-close:hover {
        opacity: 0.8;
    }
    
    .cte-summary-content {
        padding: 15px 20px;
    }
    
    .cte-summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 12px;
    }

    .cte-summary-item {
        display: flex;
        flex-direction: column;
        gap: 6px;
    }

    .cte-summary-value {
        font-size: 13px;
        color: #000;
        font-weight: 500;
    }
    
    /* Navegação */
    .cte-navigation {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 30px;
        padding: 20px;
        background: #f8f9fa;
        border-radius: 8px;
        border: 1px solid #e0e0e0;
    }
    
    .cte-nav-left {
        display: flex;
        justify-content: flex-start;
        flex: 0 0 auto;
    }
    
    .cte-nav-right {
        display: flex;
        justify-content: flex-end;
        flex: 0 0 auto;
    }
    
    .cte-navigation .butAction {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 12px 24px;
        font-size: 15px;
        font-weight: 600;
        border-radius: 6px;
        transition: all 0.3s ease;
        white-space: nowrap;
        height: auto;
        line-height: 1.5;
        background: #007bff;
        color: white;
        border: none;
        text-decoration: none;
        cursor: pointer;
    }
    
    .cte-navigation .butAction:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(0, 123, 255, 0.3);
        background: #0056b3;
        color: white;
        text-decoration: none;
    }
    
    .cte-navigation .butAction.cte-btn-emitir {
        background: #28a745;
        border-color: #28a745;
    }
    
    .cte-navigation .butAction.cte-btn-emitir:hover {
        background: #218838;
        box-shadow: 0 4px 12px rgba(40, 167, 69, 0.3);
    }
    
    .cte-navigation .fa {
        font-size: 14px;
    }
    
    /* Responsivo */
    @media (max-width: 768px) {
        .cte-action-bar {
            flex-direction: column;
        }
        
        .cte-action-bar .butAction,
        .cte-action-bar .butActionDelete {
            width: 100%;
            justify-content: center;
        }
        
        .cte-navigation {
            flex-direction: column;
            gap: 15px;
        }
        
        .cte-nav-left,
        .cte-nav-right {
            width: 100%;
        }
        
        .cte-navigation .butAction {
            width: 100%;
            justify-content: center;
        }
    }
    </style>';
}

/**
 * JavaScript customizado
 */
function print_custom_js()
{
    global $_SESSION;
    $dadosJson = json_encode($_SESSION['cte_dados'] ?? []);
    
    print '<script>
    console.log("[CTE] Script iniciado");
    // Dados salvos na sessão
    var dadosSessao = '.$dadosJson.';
    var estadosCache = null;
    var municipiosCache = {};
    // Referência ao formulário principal
    var mainForm = document.forms["formcte"] || document.querySelector(\'form[name="formcte"]\');
    
    // Funções globais para cálculo de ICMS (MANTIDAS)
    function mostrarCamposICMS() {
        var cst = document.getElementById("cst_icms") ? document.getElementById("cst_icms").value : "";
        var campos00 = document.getElementById("campos_icms_00");
        var campos20 = document.getElementById("campos_icms_20");
        var campos60 = document.getElementById("campos_icms_60");
        if (campos00) campos00.style.display = "none";
        if (campos20) campos20.style.display = "none";
        if (campos60) campos60.style.display = "none";
        if (cst == "00") {
            if (campos00) campos00.style.display = "";
            var vTPrestElem = document.getElementById("vTPrest");
            var vBCElem = document.getElementById("vBC");
            if (vTPrestElem && vBCElem && vBCElem.value == "") {
                var vTPrest = parseFloat(vTPrestElem.value) || 0;
                if (vTPrest > 0) vBCElem.value = vTPrest.toFixed(2);
            }
            calcularICMS();
        } else if (cst == "20") {
            if (campos20) campos20.style.display = "";
            calcularICMS();
        } else if (cst == "60") {
            if (campos60) campos60.style.display = "";
        }
    }
    
    function calcularICMS() {
        var cstElem = document.getElementById("cst_icms");
        if (!cstElem) return;
        var cst = cstElem.value;
        if (cst == "00") {
            var vBC = parseFloat(document.getElementById("vBC").value) || 0;
            var pICMS = parseFloat(document.getElementById("pICMS").value) || 0;
            if (vBC > 0 && pICMS > 0) {
                var vICMS = vBC * (pICMS / 100);
                document.getElementById("vICMS").value = vICMS.toFixed(2);
                var labelElem = document.getElementById("icms_calculado");
                if (labelElem) labelElem.innerText = "(Calculado: R$ " + vICMS.toFixed(2) + ")";
            }
        } else if (cst == "20") {
            var vTPrestElem = document.getElementById("vTPrest");
            var pRedBCElem = document.getElementById("pRedBC");
            var pICMSRedElem = document.getElementById("pICMS_red");
            if (!vTPrestElem || !pRedBCElem || !pICMSRedElem) return;
            var vTPrest = parseFloat(vTPrestElem.value) || 0;
            var pRedBC = parseFloat(pRedBCElem.value) || 0;
            var pICMS = parseFloat(pICMSRedElem.value) || 0;
            if (vTPrest > 0 && pICMS > 0) {
                var vBC_red = vTPrest * (1 - (pRedBC / 100));
                var vBCRedElem = document.getElementById("vBC_red");
                if (vBCRedElem) vBCRedElem.value = vBC_red.toFixed(2);
                var vICMS = vBC_red * (pICMS / 100);
                var vICMSRedElem = document.getElementById("vICMS_red");
                if (vICMSRedElem) vICMSRedElem.value = vICMS.toFixed(2);
                var labelElem = document.getElementById("icms_calculado_red");
                if (labelElem) labelElem.innerText = "(Calculado: R$ " + vICMS.toFixed(2) + ")";
            }
        }
    }
    
    function syncValores() {
        var compElem = document.getElementById("comp_vComp");
        if (!compElem) return;
        var vComp = parseFloat(compElem.value) || 0;
        var vTPrestElem = document.getElementById("vTPrest");
        var vRecElem = document.getElementById("vRec");
        if (vTPrestElem) vTPrestElem.value = vComp.toFixed(2);
        if (vRecElem) vRecElem.value = vComp.toFixed(2);
        calcularICMS();
    }

    // NOVAS FUNÇÕES para carregamento dinâmico de municípios
    function carregarEstados() {
        if (estadosCache) {
            preencherTodosEstados();
            return;
        }
        fetch("'.DOL_URL_ROOT.'/custom/cte/ajax_municipios.php?action=getEstados")
            .then(r => r.json())
            .then(data => {
                estadosCache = data;
                preencherTodosEstados();
            })
            .catch(e => console.error("Erro ao carregar estados:", e));
    }

    function preencherTodosEstados() {
        var selects = ["UFEnv", "UFIni", "UFFim", "rem_uf", "dest_uf", "exped_uf", "receb_uf", "toma4_uf"];
        selects.forEach(function(id) {
            var sel = document.getElementById(id);
            if (!sel) return;
            var valorAtual = dadosSessao[id] || "";
            sel.innerHTML = "<option value=\'\'>Selecione...</option>";
            estadosCache.forEach(function(uf) {
                var opt = document.createElement("option");
                opt.value = uf.sigla;
                opt.text = uf.sigla + " - " + uf.nome;
                if (uf.sigla === valorAtual) opt.selected = true;
                sel.appendChild(opt);
            });
            if (valorAtual) {
                var munId = id.replace("UF", "xMun").replace("_uf", "_xMun");
                var codId = id.replace("UF", "cMun").replace("_uf", "_cMun");
                carregarMunicipios(id, munId, codId);
            }
        });
    }

    function carregarMunicipios(ufSelectId, munSelectId, codInputId) {
        var ufSel = document.getElementById(ufSelectId);
        var munSel = document.getElementById(munSelectId);
        var codInput = document.getElementById(codInputId);
        if (!ufSel || !munSel) return;
        var uf = ufSel.value;
        if (!uf) {
            munSel.innerHTML = "<option value=\'\'>Selecione UF primeiro</option>";
            if (codInput) codInput.value = "";
            return;
        }
        munSel.innerHTML = "<option value=\'\'>Carregando...</option>";
        var cacheKey = uf;
        if (municipiosCache[cacheKey]) {
            preencherMunicipios(munSel, municipiosCache[cacheKey], munSelectId, codInput);
            return;
        }
        fetch("'.DOL_URL_ROOT.'/custom/cte/ajax_municipios.php?action=getMunicipios&uf="+uf)
            .then(r => r.json())
            .then(data => {
                municipiosCache[cacheKey] = data;
                preencherMunicipios(munSel, data, munSelectId, codInput);
            })
            .catch(e => {
                console.error("Erro ao carregar municípios:", e);
                munSel.innerHTML = "<option value=\'\'>Erro ao carregar</option>";
            });
    }

    function preencherMunicipios(sel, data, munSelectId, codInput) {
        var valorAtual = dadosSessao[munSelectId] || "";
        sel.innerHTML = "<option value=\'\'>Selecione...</option>";
        data.forEach(function(m) {
            var opt = document.createElement("option");
            opt.value = m.nome;
            opt.setAttribute("data-codigo", m.codigo);
            opt.text = m.nome;
            if (m.nome === valorAtual) opt.selected = true;
            sel.appendChild(opt);
        });
        if (valorAtual && codInput) {
            var optSel = sel.options[sel.selectedIndex];
            if (optSel && optSel.getAttribute("data-codigo")) {
                codInput.value = optSel.getAttribute("data-codigo");
            }
        }
    }

    function setCodigoMunicipio(munSelectId, codInputId) {
        var munSel = document.getElementById(munSelectId);
        var codInput = document.getElementById(codInputId);
        if (!munSel || !codInput) return;
        var optSel = munSel.options[munSel.selectedIndex];
        if (optSel && optSel.getAttribute("data-codigo")) {
            codInput.value = optSel.getAttribute("data-codigo");
        } else {
            codInput.value = "";
        }
    }

    // Modal XML
    function openXMLModal() {
        console.log("[CTE] Abrindo modal XML");
        var modal = document.getElementById("xmlModal");
        if (modal) {
            modal.style.display = "block";
            console.log("[CTE] Modal aberto com sucesso");
        } else {
            console.error("[CTE] Elemento xmlModal não encontrado!");
        }
    }
    
    function closeXMLModal() {
        console.log("[CTE] Fechando modal XML");
        var modal = document.getElementById("xmlModal");
        var fileInput = document.getElementById("xml_nfe");
        var selectedFile = document.getElementById("selectedFile");
        var btnImport = document.getElementById("btnImport");
        var uploadProgress = document.getElementById("uploadProgress");
        
        if (modal) modal.style.display = "none";
        if (fileInput) fileInput.value = "";
        if (selectedFile) selectedFile.style.display = "none";
        if (btnImport) btnImport.disabled = true;
        if (uploadProgress) uploadProgress.style.display = "none";
    }
    
    window.onclick = function(event) {
        const modal = document.getElementById("xmlModal");
        if (event.target == modal) {
            closeXMLModal();
        }
    }
    
    document.addEventListener("DOMContentLoaded", function() {
        console.log("[CTE] DOM carregado, iniciando configurações");
        
        // Carregar estados ao iniciar
        carregarEstados();

        // Configurar upload de XML
        var fileInput = document.getElementById("xml_nfe");
        var selectedFile = document.getElementById("selectedFile");
        var fileName = document.getElementById("fileName");
        var btnImport = document.getElementById("btnImport");
        var dropArea = document.getElementById("dropArea");
        
        if (fileInput && selectedFile && fileName && btnImport) {
            console.log("[CTE] Elementos do modal encontrados");
            
            // Quando selecionar arquivo
            fileInput.addEventListener("change", function(e) {
                console.log("[CTE] Arquivo selecionado:", e.target.files);
                if (e.target.files && e.target.files.length > 0) {
                    var file = e.target.files[0];
                    console.log("[CTE] Nome do arquivo:", file.name);
                    fileName.textContent = file.name;
                    selectedFile.style.display = "block";
                    btnImport.disabled = false;
                } else {
                    selectedFile.style.display = "none";
                    btnImport.disabled = true;
                }
            });
            
            // Drag and drop
            dropArea.addEventListener("click", function(e) {
                if (e.target === dropArea || e.target.className.includes("cte-upload")) {
                    fileInput.click();
                }
            });
            
            dropArea.addEventListener("dragover", function(e) {
                e.preventDefault();
                e.stopPropagation();
                dropArea.classList.add("dragover");
            });
            
            dropArea.addEventListener("dragleave", function(e) {
                e.preventDefault();
                e.stopPropagation();
                dropArea.classList.remove("dragover");
            });
            
            dropArea.addEventListener("drop", function(e) {
                e.preventDefault();
                e.stopPropagation();
                dropArea.classList.remove("dragover");
                
                if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                    fileInput.files = e.dataTransfer.files;
                    var event = new Event("change", { bubbles: true });
                    fileInput.dispatchEvent(event);
                }
            });
        } else {
            console.error("[CTE] Elementos do modal não encontrados:", {
                fileInput: !!fileInput,
                selectedFile: !!selectedFile,
                fileName: !!fileName,
                btnImport: !!btnImport,
                dropArea: !!dropArea
            });
        }

        // Handler do formulário de upload XML com indicador de progresso
        var formXMLUpload = document.getElementById("formXMLUpload");
        if (formXMLUpload) {
            console.log("[CTE] Formulário de upload encontrado");
            formXMLUpload.addEventListener("submit", function(e) {
                console.log("[CTE] Submetendo formulário de XML");
                var uploadProgress = document.getElementById("uploadProgress");
                var progressBar = document.getElementById("progressBar");
                var uploadStatus = document.getElementById("uploadStatus");
                var btnImport = document.getElementById("btnImport");
                
                if (uploadProgress) {
                    uploadProgress.style.display = "block";
                    if (btnImport) {
                        btnImport.disabled = true;
                        btnImport.textContent = "Importando...";
                    }
                    
                    // Simula progresso
                    var progress = 0;
                    var interval = setInterval(function() {
                        progress += 10;
                        if (progress <= 90) {
                            if (progressBar) {
                                progressBar.style.width = progress + "%";
                                progressBar.textContent = progress + "%";
                            }
                        } else {
                            clearInterval(interval);
                            if (uploadStatus) uploadStatus.textContent = "Processando XML...";
                        }
                    }, 200);
                }
            });
        } else {
            console.error("[CTE] Formulário formXMLUpload não encontrado");
        }

        // Remover required de campos invisíveis
        function removeRequiredFromHiddenElements() {
            if (!mainForm) return;
            var elems = mainForm.querySelectorAll("[required]");
            elems.forEach(function(el) {
                var isVisible = (el.offsetParent !== null);
                var parentDetails = el.closest ? el.closest("details") : null;
                if (parentDetails && !parentDetails.open) isVisible = false;
                var cur = el.parentElement;
                while (cur) {
                    var cs = window.getComputedStyle(cur);
                    if (cs && cs.display === "none") { isVisible = false; break; }
                    cur = cur.parentElement;
                }
                if (!isVisible) {
                    el.setAttribute("data-was-required", "1");
                    el.removeAttribute("required");
                }
            });
        }

        if (mainForm) {
            var submitButtons = mainForm.querySelectorAll(\'button[type="submit"], input[type="submit"]\');
            submitButtons.forEach(function(btn) {
                btn.addEventListener("click", function() {
                    removeRequiredFromHiddenElements();
                }, { passive: true });
            });
            
            mainForm.addEventListener("submit", function(e) {
                removeRequiredFromHiddenElements();
            }, true);
        }

        // Mostrar/ocultar seção toma4 quando selecionar o tomador
        var tomaSel = document.querySelector("select[name=\\"toma\\"]");
        function toggleToma4() {
            var sec = document.getElementById("toma4_section");
            if (!sec || !tomaSel) return;
            
            var isOutros = (tomaSel.value === "4");
            sec.style.display = isOutros ? "" : "none";
            
            // Toggle required attributes for inputs inside toma4 section
            // We target specific fields that are mandatory when this section is visible
            var requiredFields = [
                "toma4_xNome", "toma4_xLgr", "toma4_nro", "toma4_xBairro", "toma4_uf", "toma4_xMun"
            ];
            
            requiredFields.forEach(function(name) {
                var el = sec.querySelector("[name=" + name + "]");
                if (el) {
                    if (isOutros) {
                        el.setAttribute("required", "required");
                    } else {
                        el.removeAttribute("required");
                    }
                }
            });
        }

        if (tomaSel) {
            tomaSel.addEventListener("change", toggleToma4);
            // Run on init
            toggleToma4();
        }

        // Previsão de entrega: mostrar/ocultar campos de data conforme tpPer
        var tpPerSel = document.getElementById("tpPer");
        function atualizarCamposData() {
            var val = tpPerSel ? tpPerSel.value : "";
            // elementos
            var rowProg = document.getElementById("row_dProg");
            var rowIni = document.getElementById("row_dIni");
            var rowFim = document.getElementById("row_dFim");
            var dProg = document.getElementById("dProg");
            var dIni = document.getElementById("dIni");
            var dFim = document.getElementById("dFim");

            // reset required
            if (dProg) dProg.removeAttribute("required");
            if (dIni) dIni.removeAttribute("required");
            if (dFim) dFim.removeAttribute("required");

            // hide all
            if (rowProg) rowProg.style.display = "none";
            if (rowIni) rowIni.style.display = "none";
            if (rowFim) rowFim.style.display = "none";

            if (val === "0") {
                // Sem data - nenhum campo
            } else if (val === "1" || val === "2" || val === "3") {
                if (rowProg) rowProg.style.display = "";
                if (dProg) dProg.setAttribute("required", "required");
            } else if (val === "4") {
                if (rowIni) rowIni.style.display = "";
                if (rowFim) rowFim.style.display = "";
                if (dIni) dIni.setAttribute("required", "required");
                if (dFim) dFim.setAttribute("required", "required");
            }
        }
        if (tpPerSel) {
            tpPerSel.addEventListener("change", function() {
                // Proibir sem-data para modal aéreo (02)
                var modalSel = document.querySelector("select[name=\\"modal\\"]");
                if (this.value === "0" && modalSel && modalSel.value === "02") {
                    alert("Opção \"Sem data definida\" é proibida para modal aéreo.");
                    this.value = "1"; // fallback
                }
                atualizarCamposData();
            });
            atualizarCamposData();
        }

        // Horário: mostrar/ocultar campos de hora conforme tpHor
        var tpHorSel = document.getElementById("tpHor");
        function atualizarCamposHora() {
            var val = tpHorSel ? tpHorSel.value : "";
            var rowProg = document.getElementById("row_hProg");
            var rowIni = document.getElementById("row_hIni");
            var rowFim = document.getElementById("row_hFim");
            var hProg = document.getElementById("hProg");
            var hIni = document.getElementById("hIni");
            var hFim = document.getElementById("hFim");

            if (hProg) hProg.removeAttribute("required");
            if (hIni) hIni.removeAttribute("required");
            if (hFim) hFim.removeAttribute("required");

            if (rowProg) rowProg.style.display = "none";
            if (rowIni) rowIni.style.display = "none";
            if (rowFim) rowFim.style.display = "none";

            if (val === "0" || val === "") {
                // sem hora definida
            } else if (val === "1" || val === "2" || val === "3") {
                if (rowProg) rowProg.style.display = "";
                if (hProg) hProg.setAttribute("required", "required");
            } else if (val === "4") {
                if (rowIni) rowIni.style.display = "";
                if (rowFim) rowFim.style.display = "";
                if (hIni) hIni.setAttribute("required", "required");
                if (hFim) hFim.setAttribute("required", "required");
            }
        }
        if (tpHorSel) {
            tpHorSel.addEventListener("change", function() { atualizarCamposHora(); });
            atualizarCamposHora();
        }

        // Também garantir validação condicional antes do submit
        if (mainForm) {
            mainForm.addEventListener("submit", function(e) {
                console.log("[CTE] Submit iniciado - Etapa atual");
                
                // Debug: verifica campos inválidos
                var invalidFields = mainForm.querySelectorAll(":invalid");
                if (invalidFields.length > 0) {
                    console.log("[CTE] Campos inválidos encontrados:", invalidFields);
                    invalidFields.forEach(function(field) {
                        console.log("  - Campo:", field.name, "Tipo:", field.type, "Visível:", field.offsetParent !== null);
                    });
                }
                
                // O validador existente já verifica [required] visíveis. Mantemos.
                // Adicional: proibir sem data para modal aéreo no submit também
                var tpPerVal = tpPerSel ? tpPerSel.value : "";
                var modalSel = document.querySelector("select[name=\"modal\"]");
                if (tpPerVal === "0" && modalSel && modalSel.value === "02") {
                    e.preventDefault();
                    alert("Opção \"Sem data definida\" é proibida para modal aéreo. Selecione outra opção.");
                    return false;
                }
            });
        }
        
        // Máscaras (MANTIDAS)
        const cnpjInputs = document.querySelectorAll("input[name$=_cnpj]");
        cnpjInputs.forEach(function(input) {
            input.addEventListener("input", function(e) {
                let value = e.target.value.replace(/\D/g, "");
                if (value.length <= 11) {
                    value = value.replace(/(\d{3})(\d)/, "$1.$2");
                    value = value.replace(/(\d{3})(\d)/, "$1.$2");
                    value = value.replace(/(\d{3})(\d{1,2})$/, "$1-$2");
                } else {
                    value = value.replace(/^(\d{2})(\d)/, "$1.$2");
                    value = value.replace(/^(\d{2})\.(\d{3})(\d)/, "$1.$2.$3");
                    value = value.replace(/\.(\d{3})(\d)/, ".$1/$2");
                    value = value.replace(/(\d{4})(\d)/, "$1-$2");
                }
                e.target.value = value;
            });
        });
        
        const cepInputs = document.querySelectorAll("input[name$=_cep]");
        cepInputs.forEach(function(input) {
            input.addEventListener("input", function(e) {
                let value = e.target.value.replace(/\D/g, "");
                value = value.replace(/^(\d{5})(\d)/, "$1-$2");
                e.target.value = value;
            });
        });
    });
    </script>';
}