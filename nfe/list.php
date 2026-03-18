<?php
require_once __DIR__ . '/../../main.inc.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
$_autoload = DOL_DOCUMENT_ROOT . '/custom/composerlib/vendor/autoload.php';
if (!file_exists($_autoload)) {
    llxHeader('', 'Erro NFe');
    print '<div class="error">Erro: A biblioteca NFePHP não foi encontrada em: ' . dol_escape_htmltag($_autoload) . '<br>Verifique se a pasta <code>custom/composerlib</code> foi enviada para o servidor.</div>';
    llxFooter();
    exit;
}
require_once $_autoload;
require_once DOL_DOCUMENT_ROOT.'/custom/nfe/lib/nfe_security.lib.php';

$mainmenu = 'nfe';
$leftmenu = 'nfe_list';

use NFePHP\NFe\Tools;
use NFePHP\Common\Certificate;
use NFePHP\NFe\Common\Standardize;
use NFePHP\NFe\Complements;

/**
 * Cria as tabelas necessárias no banco de dados do Dolibarr se elas não existirem.
 */

function inicializarBancoDeDadosNFe(DoliDB $db): void
{
    // Tabela de NF-e Emitidas
    // $sql_emitidas = "
    //     CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "nfe_emitidas (
    //         id INT AUTO_INCREMENT PRIMARY KEY,
    //         fk_facture INT,
    //         fk_nfe_origem INT NULL,
    //         chave VARCHAR(44) NULL UNIQUE,
    //         protocolo VARCHAR(255) NULL,
    //         numero_nfe INT NOT NULL,
    //         serie INT NOT NULL,
    //         status VARCHAR(20) NOT NULL,
    //         motivo_status TEXT NULL,
    //         xml_completo LONGTEXT NULL,
    //         pdf_file LONGBLOB NULL,
    //         data_emissao DATETIME NOT NULL
    //     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    // $db->query($sql_emitidas);

    // // Garantir coluna fk_nfe_origem para relacionar devoluções
    // $resCol = $db->query("SHOW COLUMNS FROM ".MAIN_DB_PREFIX."nfe_emitidas LIKE 'fk_nfe_origem'");
    // if ($resCol && $db->num_rows($resCol) == 0) {
    //     $db->query("ALTER TABLE ".MAIN_DB_PREFIX."nfe_emitidas ADD COLUMN fk_nfe_origem INT NULL AFTER fk_facture");
    // }

    // // Tabela de Eventos da NF-e
    // $sql_eventos = "
    //     CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "nfe_eventos (
    //         id INT AUTO_INCREMENT PRIMARY KEY,
    //         fk_nfe_emitida INT NOT NULL,
    //         tpEvento VARCHAR(6) NOT NULL,
    //         nSeqEvento INT NOT NULL,
    //         protocolo_evento VARCHAR(255) NOT NULL,
    //         motivo_evento TEXT NULL,
    //         data_evento DATETIME NOT NULL,
    //         xml_requisicao LONGTEXT NULL,
    //         xml_resposta LONGTEXT NULL,
    //         xml_evento_completo LONGTEXT NULL,
    //         KEY idx_fk_nfe_emitida (fk_nfe_emitida)
    //     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    // $db->query($sql_eventos);

    // // Tabela de Configurações da NF-e
    // $sql_config = "
    //     CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "nfe_config (
    //         id INT AUTO_INCREMENT PRIMARY KEY,
    //         name VARCHAR(50) NOT NULL UNIQUE,
    //         value LONGBLOB NULL
    //     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    // $db->query($sql_config);

    // $sql_inutilizadas = "
    //     CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "nfe_inutilizadas (
    //         id INT AUTO_INCREMENT PRIMARY KEY,
    //         serie INT NOT NULL,
    //         numero_inicial INT NOT NULL,
    //         numero_final INT NOT NULL,
    //         justificativa TEXT NOT NULL,
    //         protocolo VARCHAR(255) NOT NULL,
    //         data_inutilizacao DATETIME NOT NULL,
    //         xml_resposta LONGTEXT NULL,
    //         UNIQUE KEY (serie, numero_inicial, numero_final)
    //     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
    // $db->query($sql_inutilizadas);
    
    // $sql_tax_rules = " 
    // CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "custom_tax_rules (
    //     `rowid` INT AUTO_INCREMENT PRIMARY KEY,
    //     `label` varchar(255) NOT NULL,
    //     `active` tinyint(1) DEFAULT '1',
    //     `cfop` varchar(4) DEFAULT NULL,
    //     `ncm` varchar(8) DEFAULT NULL,
    //     `uf_origin` varchar(2) NOT NULL,
    //     `uf_dest` varchar(2) NOT NULL,
    //     `crt_emitter` int NOT NULL,
    //     `indiedest_recipient` int DEFAULT NULL,
    //     `product_origin` int NOT NULL DEFAULT '0',
    //     `icms_csosn` varchar(3) DEFAULT NULL,
    //     `pis_cst` varchar(2) DEFAULT NULL,
    //     `pis_aliq` decimal(5,2) DEFAULT '0.00',
    //     `cofins_cst` varchar(2) DEFAULT NULL,
    //     `cofins_aliq` decimal(5,2) DEFAULT '0.00',
    //     `ipi_cst` varchar(2) DEFAULT NULL,
    //     `ipi_aliq` decimal(5,2) DEFAULT '0.00',
    //     `ipi_cenq` varchar(3) DEFAULT '999',
    //     `icms_st_mva` decimal(7,4) DEFAULT '0.0000',
    //     `icms_st_aliq` decimal(5,2) DEFAULT '0.00',
    //     `icms_st_predbc` decimal(5,2) DEFAULT '0.00',
    //     icms_interestadual_aliq decimal(5,2) DEFAULT '0.00',
    //     icms_cred_aliq decimal(5,2) DEFAULT '0.00',
    //     aliq_interna_dest decimal(5,2) DEFAULT '0.00'
    //     ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    // $db->query($sql_tax_rules);

    // $db->query("INSERT IGNORE INTO ". MAIN_DB_PREFIX."custom_tax_rules (rowid, label, active, cfop, uf_origin, uf_dest, crt_emitter, icms_csosn, pis_cst, cofins_cst)
    // VALUES (1, 'Venda Simples Dentro do ES - Padrão', 1, '5102', 'ES', 'ES', 1, '102', '49', '49');");

    // $db->query("INSERT IGNORE INTO ". MAIN_DB_PREFIX."custom_tax_rules (rowid, label, active, cfop, ncm, uf_origin, uf_dest, crt_emitter, icms_csosn, icms_st_mva, icms_st_aliq, icms_cred_aliq, pis_cst, cofins_cst) 
    // VALUES (2, 'ST Autopeças Dentro do ES', 1, '5403', '84212300', 'ES', 'ES', 1, '202', 71.5300, 17.00, 1.25, '49', '49');");

    // $db->query("INSERT IGNORE INTO ". MAIN_DB_PREFIX."custom_tax_rules (rowid, label, active, cfop, uf_origin, uf_dest, crt_emitter, icms_csosn, pis_cst, cofins_cst)
    // VALUES (3, 'Venda Simples para SP - Padrão', 1, '6102', 'ES', 'SP', 1, '102', '49', '49');");

    // $db->query("INSERT IGNORE INTO ". MAIN_DB_PREFIX."custom_tax_rules (rowid, label, active, cfop, ncm, uf_origin, uf_dest, crt_emitter, icms_csosn, icms_st_mva, icms_st_aliq, icms_interestadual_aliq, pis_cst, cofins_cst)
    // VALUES (4, 'ST Autopeças para SP', 1, '6403', '84212300', 'ES', 'SP', 1, '202', 71.5300, 18.00, 12.00, '49', '49');");

    // $db->query("INSERT IGNORE INTO ". MAIN_DB_PREFIX."custom_tax_rules (rowid, label, active, cfop, uf_origin, uf_dest, crt_emitter, icms_csosn, pis_cst, cofins_cst, icms_interestadual_aliq, aliq_interna_dest)
    // VALUES (5, 'Venda para Consumidor Final - SP (DIFAL)', 1, '6102', 'ES', 'SP', 1, '102', '49', '49', 12.00, 18.00);");

    // $db->query("INSERT IGNORE INTO ". MAIN_DB_PREFIX."custom_tax_rules (rowid, label, active, cfop, uf_origin, uf_dest, crt_emitter, icms_csosn, pis_cst, cofins_cst, icms_interestadual_aliq, aliq_interna_dest)
    // VALUES (6, 'Revenda de Produto com ST - Dentro do ES', 1, '5405', '', 'ES', 'ES', 1, NULL, 0, '500', '49', 0.00, '49', 0.00, '', 0.00, '999', 0.0000, 0.00, 0.00, 0.00, 0.00, 0.00);");

    // // Inserir valores padrão se não existirem
    // $db->query("INSERT IGNORE INTO " . MAIN_DB_PREFIX . "nfe_config (name, value) VALUES ('cert_pfx', NULL)");
    // $db->query("INSERT IGNORE INTO " . MAIN_DB_PREFIX . "nfe_config (name, value) VALUES ('cert_pass', NULL)");
    // $db->query("INSERT IGNORE INTO " . MAIN_DB_PREFIX . "nfe_config (name, value) VALUES ('config_json', NULL)");
    // $db->query("INSERT IGNORE INTO " . MAIN_DB_PREFIX . "nfe_config (name, value) VALUES ('ambiente', '2')");
}

/**
 * Função central para gerar eventos de NF-e (Cancelamento, CC-e) no ambiente Dolibarr.
 */
function gerarEventoNFeDolibarr(DoliDB $db, int $idNFe, string $chave, string $protocolo, string $tpEvento, string $justificativa): array
{
    global $langs, $mysoc;

    try {
        // Carrega configurações do banco de dados
        $sql_config = "SELECT name, value FROM ".MAIN_DB_PREFIX."nfe_config";
        $res_config = $db->query($sql_config);
        $nfe_configs = array();
        if ($res_config) {
            while ($obj_config = $db->fetch_object($res_config)) {
                $nfe_configs[$obj_config->name] = $obj_config->value;
            }
        }

        // Gera config.json dinamicamente
        $arr = [
            "atualizacao" => date('Y-m-d H:i:s'),
            "tpAmb"       => (int)($nfe_configs['ambiente'] ?? 2),
            "razaosocial" => $mysoc->name ?? 'Empresa',
            "cnpj"        => preg_replace('/\D/', '', $mysoc->idprof1 ?? ''),
            "siglaUF"     => $mysoc->state_code ?? 'ES',
            "schemes"     => "PL_010_V1",
            "versao"      => '4.00',
            "tokenIBPT"   => "AAAAAAA",
            "CSC"         => $nfe_configs['csc'] ?? "GPB0JBWLUR6HWFTVEAS6RJ69GPCROFPBBB8G",
            "CSCid"       => $nfe_configs['csc_id'] ?? "000001",
            "proxyConf"   => [
                "proxyIp"   => "",
                "proxyPort" => "",
                "proxyUser" => "",
                "proxyPass" => ""
            ]
        ];
        $configJson = json_encode($arr);
        $pfxContent = $nfe_configs['cert_pfx'];
        $password = $nfe_configs['cert_pass'];
        $senhaOriginal = nfeDecryptPassword($password, $db);


        $sql = "SELECT MAX(nSeqEvento) as max_seq FROM " . MAIN_DB_PREFIX . "nfe_eventos";
        $sql .= " WHERE fk_nfe_emitida = " . $idNFe . " AND tpEvento = '" . $db->escape($tpEvento) . "'";
        
        $resql = $db->query($sql);
        $obj = $db->fetch_object($resql);
        $nSeqEvento = ($obj && $obj->max_seq) ? $obj->max_seq + 1 : 1;

        if ($tpEvento === '110111' && $nSeqEvento > 1) {
            return ['success' => false, 'message' => $langs->trans("NFe ja foi cancelada.")];
        }

        $certificate = Certificate::readPfx($pfxContent, $senhaOriginal);
        $tools = new Tools($configJson, $certificate);
        $tools->model('55');

        $responseXml = '';
        if ($tpEvento === '110111') {
            $responseXml = $tools->sefazCancela($chave, $justificativa, $protocolo);
        } elseif ($tpEvento === '110110') {
            $responseXml = $tools->sefazCCe($chave, $justificativa, $nSeqEvento);
        } else {
            return ['success' => false, 'message' => $langs->trans("UnknownEventType") . ': ' . $tpEvento];
        }

        $responseArr = (new Standardize($responseXml))->toArray();
        $cStatLote = $responseArr['cStat'] ?? null;
        $infEvento = $responseArr['retEvento']['infEvento'] ?? null;
        $cStatEvento = $infEvento['cStat'] ?? null;

        if ($cStatLote != '128') {
            return ['success' => false, 'message' => "Rejeição no Lote: {$cStatLote} - {$responseArr['xMotivo']}"];
        }

        if (in_array($cStatEvento, ['135', '136'])) {
            $db->begin();
            $xmlFinalEvento = Complements::toAuthorize($tools->lastRequest, $responseXml);
            $dataEvento = (new DateTime($infEvento['dhRegEvento']))->format('Y-m-d H:i:s');
            
            $sqlInsert = "INSERT INTO " . MAIN_DB_PREFIX . "nfe_eventos (fk_nfe_emitida, tpEvento, nSeqEvento, protocolo_evento, motivo_evento, data_evento, xml_requisicao, xml_resposta, xml_evento_completo)";
            $sqlInsert .= " VALUES (".$idNFe.", '".$db->escape($tpEvento)."', ".$nSeqEvento.", '".$db->escape($infEvento['nProt'])."', '".$db->escape($justificativa)."', '".$db->escape($dataEvento)."', '".$db->escape($tools->lastRequest)."', '".$db->escape($responseXml)."', '".$db->escape($xmlFinalEvento)."')";
            $db->query($sqlInsert);

            if ($tpEvento === '110111') {
                $sqlUpdate = "UPDATE " . MAIN_DB_PREFIX . "nfe_emitidas SET status = 'Cancelada' WHERE id = " . $idNFe;
                $db->query($sqlUpdate);
            }

            $db->commit();
            return ['success' => true, 'message' => $infEvento['xMotivo']];
        } else {
            return ['success' => false, 'message' => "Rejeição no Evento: {$cStatEvento} - {$infEvento['xMotivo']}"];
        }
    } catch (\Exception $e) {
        if (isset($db) && $db->transaction_opened) $db->rollback();
        return ['success' => false, 'message' => 'Exceção: ' . $e->getMessage()];
    }
}

$langs->load("nfe@nfe");

// if (!$user->rights->nfe->read) {
//     accessforbidden();
// }

function processarAcaoNFe(DoliDB $db, Translate $langs, string $action) {
    // Detectar se é requisição AJAX
    $isAjax = (GETPOST('ajax', 'int') == 1);
    
    if (in_array($action, ['submitcancelar', 'submitcce'])) {
        if (empty($_POST['token']) || $_POST['token'] !== $_SESSION['newtoken']) {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Token CSRF inválido']);
                exit;
            }
            return;
        }
    }

    if (!in_array($action, ['submitcancelar', 'submitcce'])) return;

    $idNFe = GETPOSTINT('id');
    if ($idNFe <= 0) return;

    $justificativa = GETPOST('justificativa', 'restricthtml');
    $tpEvento = ($action === 'submitcancelar') ? '110111' : '110110';
    $errorMsgKey = ($tpEvento === '110111') ? "ErrorJustificativaShort" : "ErrorCorrectionTextLength";

    if (mb_strlen($justificativa) < 15) {
        if ($isAjax) {
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => $langs->trans("A justificativa deve conter pelo menos 15 caracteres.")]);
            exit;
        }
        setEventMessages($langs->trans("A justificativa deve conter pelo menos 15 caracteres."), null, 'errors');
    } else {
        // ALTERADO: incluir data_emissao no SELECT para validação de 24h
        $sql = "SELECT id, chave, protocolo, data_emissao FROM " . MAIN_DB_PREFIX . "nfe_emitidas WHERE id = " . $idNFe;
        $res = $db->query($sql);

        if ($res && $db->num_rows($res) > 0) {
            $obj = $db->fetch_object($res);

            // NOVO: Regras servidoras para cancelamento
            if ($tpEvento === '110111') {
                // 1) Bloqueia cancelamento se houver devoluções autorizadas vinculadas
                $sqldev = "SELECT COUNT(*) as c FROM " . MAIN_DB_PREFIX . "nfe_emitidas 
                           WHERE fk_nfe_origem = " . ((int)$obj->id) . " 
                             AND LOWER(status) LIKE 'autoriz%'";
                $resdev = $db->query($sqldev);
                $cdev = 0;
                if ($resdev) {
                    $odev = $db->fetch_object($resdev);
                    $cdev = (int) ($odev ? $odev->c : 0);
                }
                if ($cdev > 0) {
                    $errorMsg = $langs->trans("Não é permitido cancelar a NF-e pois existem devoluções já autorizadas vinculadas a ela. Cancele/estorne a devolução primeiro.");
                    if ($isAjax) {
                        header('Content-Type: application/json');
                        echo json_encode(['success' => false, 'message' => $errorMsg]);
                        exit;
                    }
                    setEventMessages($errorMsg, null, 'errors');
                    // Redireciona imediatamente (mesmo fluxo do final)
                    $redirectUrl = preg_replace('/([?&])action=[^&]+(&|$)/', '$1', $_SERVER['REQUEST_URI']);
                    $redirectUrl = preg_replace('/([?&])id=[^&]+(&|$)/', '$1', $redirectUrl);
                    $redirectUrl = preg_replace('/([?&])justificativa=[^&]+(&|$)/', '$1', $redirectUrl);
                    $redirectUrl = rtrim($redirectUrl, '&?');
                    header("Location: ".$redirectUrl);
                    exit;
                }

                // 2) Checagem de 24 horas no servidor
                if (!empty($obj->data_emissao)) {
                    try {
                        $emissao = new DateTime($obj->data_emissao);
                        $agora = new DateTime('now');
                        $diffHours = ($agora->getTimestamp() - $emissao->getTimestamp()) / 3600;
                        if ($diffHours > 24) {
                            $errorMsg = $langs->trans("O prazo para cancelamento (24h) já expirou para esta NF-e.");
                            if ($isAjax) {
                                header('Content-Type: application/json');
                                echo json_encode(['success' => false, 'message' => $errorMsg]);
                                exit;
                            }
                            setEventMessages($errorMsg, null, 'errors');
                            $redirectUrl = preg_replace('/([?&])action=[^&]+(&|$)/', '$1', $_SERVER['REQUEST_URI']);
                            $redirectUrl = preg_replace('/([?&])id=[^&]+(&|$)/', '$1', $redirectUrl);
                            $redirectUrl = preg_replace('/([?&])justificativa=[^&]+(&|$)/', '$1', $redirectUrl);
                            $redirectUrl = rtrim($redirectUrl, '&?');
                            header("Location: ".$redirectUrl);
                            exit;
                        }
                    } catch (Exception $e) {
                        // Em caso de parsing falho, não bloqueia por tempo
                    }
                }
            }

            // Chama a função para gerar o evento
            $resultado = gerarEventoNFeDolibarr($db, $obj->id, $obj->chave, $obj->protocolo, $tpEvento, $justificativa);

            if ($isAjax) {
                header('Content-Type: application/json');
                if ($resultado['success']) {
                    $successMsg = ($tpEvento === '110111') ? "Nota cancelada!" : "Evento vinculado a NFe!";
                    echo json_encode(['success' => true, 'message' => $successMsg]);
                } else {
                    echo json_encode(['success' => false, 'message' => "Erro ao processar o evento: " . $resultado['message']]);
                }
                exit;
            }
            
            if ($resultado['success']) {
                setEventMessages($resultado['message'], null, 'mesgs');
            } else {
                setEventMessages("Erro ao processar o evento: " . $resultado['message'], null, 'errors');
            }
        } else {
            if ($isAjax) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $langs->trans("A Nota Fiscal Eletrônica não foi encontrada.")]);
                exit;
            }
            if (!$res) {
                dol_print_error($db);
            }
            setEventMessages($langs->trans("A Nota Fiscal Eletrônica não foi encontrada."), null, 'errors');
        }
    }
    
    // Session persistence
    session_write_close();
    
    $redirectUrl = preg_replace('/([?&])action=[^&]+(&|$)/', '$1', $_SERVER['REQUEST_URI']);
    $redirectUrl = preg_replace('/([?&])id=[^&]+(&|$)/', '$1', $redirectUrl);
    $redirectUrl = preg_replace('/([?&])justificativa=[^&]+(&|$)/', '$1', $redirectUrl);
    $redirectUrl = rtrim($redirectUrl, '&?');

    header("Location: ".$redirectUrl);
    exit;
}

// REMOVIDO: Funções e lógica de setup (nfeConfigUpsert, renderNfeSetupForm, interceptação de actions)

// Processa a ação se houver uma (cancelar/CC-e apenas)
if (GETPOST('action')) {
    processarAcaoNFe($db, $langs, GETPOST('action'));
}


// Parâmetros de paginação e filtro
$page = max(0, (int) GETPOST('page', 'int'));
$sortfield = GETPOST('sortfield', 'alpha') ?: 'data_emissao';
$sortorder = GETPOST('sortorder', 'alpha') ?: 'DESC';

$search_fk_facture = GETPOST('search_fk_facture', 'int');
$search_numero_nfe_start = GETPOST('search_numero_nfe_start', 'int');
$search_numero_nfe_end = GETPOST('search_numero_nfe_end', 'int');
$search_chave = GETPOST('search_chave', 'alpha');
$search_status = GETPOST('search_status', 'alpha');
$search_data_emissao_start = GETPOST('search_data_emissao_start', 'alpha');
$search_data_emissao_end = GETPOST('search_data_emissao_end', 'alpha');
$search_client_name = GETPOST('search_client_name', 'alpha');

// Limite configurável por página com persistência
$defaultLimit = ($conf->liste_limit > 0) ? (int) $conf->liste_limit : 25;
$limit = (int) GETPOST('limit', 'int');
if ($limit <= 0) { $limit = $defaultLimit; }
$limitOptions = [25, 50, 100, 200];
$offset = $limit * $page;

$form = new Form($db);

// Determinar o ambiente atual
$sql_config = "SELECT value FROM ".MAIN_DB_PREFIX."nfe_config WHERE name = 'ambiente'";
$res_config = $db->query($sql_config);
$ambiente = 'Homologação'; // Valor padrão
if ($res_config && $db->num_rows($res_config) > 0) {
    $obj_config = $db->fetch_object($res_config);
    $ambiente = ($obj_config->value == '1') ? 'Produção' : 'Homologação';
}

// Atualizar o título com o ambiente

$title = $langs->trans("Notas Fiscais Eletrônicas (NF-e)") . " (" . $ambiente . ")";
llxHeader('', 'NF-e'); // <-- chamada simplificada sem include extra
inicializarBancoDeDadosNFe($db);
print load_fiche_titre($title);


//print load_fiche_titre($title, $morehtmlright, 'nfe@nfe/img/title_generic.png');

/*print_r($search_client_name);
exit;*/

// Monta cláusulas WHERE para reutilizar na query principal e na contagem
$whereClauses = [];
if (!empty($search_status)) {
    $allowedStatusesWhere = array('autorizada','cancelada','rejeitada','denegada','autorizada dev');
    $rawPartsWhere = array_map('trim', explode(',', strtolower(trim($search_status))));
    $validWhere = array_filter($rawPartsWhere, function($s) use ($allowedStatusesWhere) { return in_array($s, $allowedStatusesWhere, true); });
    if (!empty($validWhere)) {
        $inVals = array_map(function($s) use ($db) { return "'".$db->escape($s)."'"; }, $validWhere);
        $whereClauses[] = "(LOWER(n.status) IN (".implode(',', $inVals)."))";
    }
}
if (!empty($search_fk_facture)) {
    $whereClauses[] = "n.fk_facture = ".(int)$search_fk_facture;
}
if (!empty($search_numero_nfe_start)) {
    $whereClauses[] = "n.numero_nfe >= ".(int)$search_numero_nfe_start;
}
if (!empty($search_numero_nfe_end)) {
    $whereClauses[] = "n.numero_nfe <= ".(int)$search_numero_nfe_end;
}
if (!empty($search_chave)) {
    $whereClauses[] = "n.chave LIKE '%".$db->escape($search_chave)."%'";

}
if (!empty($search_data_emissao_start)) {
    $whereClauses[] = "DATE(n.data_emissao) >= '".$db->escape($search_data_emissao_start)."'";
}
if (!empty($search_data_emissao_end)) {
    $whereClauses[] = "DATE(n.data_emissao) <= '".$db->escape($search_data_emissao_end)."'";
}
if (!empty($search_client_name)) {
    $searchTerm = $db->escape(strtolower(trim($search_client_name)));
    $whereClauses[] = "(LOWER(TRIM(s.nom)) LIKE '%".$searchTerm."%' OR LOWER(TRIM(s.name_alias)) LIKE '%".$searchTerm."%')";
}

$whereSQL = empty($whereClauses) ? '' : ' AND ' . implode(' AND ', $whereClauses);

// Contagem total COM filtros aplicados
$sql_count = "SELECT COUNT(*) as total 
              FROM ".MAIN_DB_PREFIX."nfe_emitidas n
              LEFT JOIN ".MAIN_DB_PREFIX."facture f ON n.fk_facture = f.rowid
              LEFT JOIN ".MAIN_DB_PREFIX."societe s ON f.fk_soc = s.rowid
              WHERE 1=1" . $whereSQL;

$res_count = $db->query($sql_count);
$total_rows = 0;
if ($res_count) {
    $objc = $db->fetch_object($res_count);
    $total_rows = $objc ? (int)$objc->total : 0;
}

// Ajusta página se offset exceder total
if ($offset >= $total_rows && $total_rows > 0) {
    $page = floor(($total_rows - 1) / $limit);
    $offset = $limit * $page;
}

// Consulta principal
$sql = "SELECT 
            n.id,
            n.fk_facture,
            n.chave,
            n.protocolo,
            n.numero_nfe,
            n.serie,
            n.status,
            n.motivo_status,
            n.pdf_file,
            n.data_emissao,
            n.fk_nfe_origem,
            (SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."nfe_eventos ev WHERE ev.fk_nfe_emitida = n.id AND ev.tpEvento = '110110') as cce_count,
            (SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."nfe_emitidas d WHERE d.fk_nfe_origem = n.id AND LOWER(d.status) LIKE 'autoriz%') as devolucoes_count,
            f.rowid as facture_id,
            f.ref as facture_ref,
            s.nom as company_name,
            s.name_alias as company_name_alias
        FROM ".MAIN_DB_PREFIX."nfe_emitidas n
        LEFT JOIN ".MAIN_DB_PREFIX."facture f ON n.fk_facture = f.rowid
        LEFT JOIN ".MAIN_DB_PREFIX."societe s ON f.fk_soc = s.rowid
        WHERE 1=1" . $whereSQL;

// Ordenação segura
$allowedSort = array('id','fk_facture','numero_nfe','company_name','data_emissao','serie');
$sortcol = in_array($sortfield, $allowedSort) ? $sortfield : 'data_emissao';
if ($sortfield === 'company_name') {
    $sql .= " ORDER BY s.nom ".($sortorder === 'ASC' ? 'ASC' : 'DESC');
} else {
    $sql .= " ORDER BY n.".$sortcol." ".($sortorder === 'ASC' ? 'ASC' : 'DESC');
}

// Paginação
$sql .= $db->plimit($limit, $offset);

$resql = $db->query($sql);
if (!$resql) { dol_print_error($db); llxFooter(); exit; }
$num = $db->num_rows($resql);

// Adicionar CSS completo da paginação (copiado do nfse_list.php)
print '<style>
/* ===== Paginação Customizada ===== */
.nfse-pagination-wrapper {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 15px 10px;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    margin: 15px 0;
    flex-wrap: wrap;
    gap: 15px;
}
.nfse-pagination-info {
    font-size: 0.95em;
    color: #495057;
    font-weight: 500;
}
.nfse-pagination-controls {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.nfse-page-size-selector {
    display: flex;
    align-items: center;
    gap: 8px;
}
.nfse-page-size-selector label {
    font-size: 0.9em;
    color: #6c757d;
    white-space: nowrap;
}
.nfse-page-size-selector select {
    padding: 6px 12px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    background: white;
    font-size: 0.9em;
    cursor: pointer;
}
.nfse-page-nav {
    display: flex;
    gap: 5px;
}
.nfse-page-btn {
    padding: 6px 12px;
    border: 1px solid #ced4da;
    background: white;
    color: #495057;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.9em;
    text-decoration: none;
    transition: all 0.2s;
}
.nfse-page-btn:hover:not(.disabled) {
    background: #007bff;
    color: white;
    border-color: #007bff;
}
.nfse-page-btn.active {
    background: #007bff;
    color: white;
    border-color: #007bff;
    font-weight: bold;
}
.nfse-page-btn.disabled {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
}
.nfse-page-jump {
    display: flex;
    align-items: center;
    gap: 5px;
}
.nfse-page-jump input {
    width: 60px;
    padding: 6px 8px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    text-align: center;
    font-size: 0.9em;
}
.nfse-page-jump button {
    padding: 6px 12px;
    border: 1px solid #007bff;
    background: #007bff;
    color: white;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.9em;
}
@media (max-width: 768px) {
    .nfse-pagination-wrapper {
        flex-direction: column;
        align-items: stretch;
    }
    .nfse-pagination-controls {
        flex-direction: column;
    }
}
</style>';

print '<style type="text/css">
.butAction-std {
    min-width: 80px; /* Define uma largura mínima para os botões */
    text-align: center;
    display: inline-block;
}
</style>';

print '<style>
/* Bolinha verde */
.status-circle-green {
    display: inline-block;
    width: 11px;
    height: 11px;
    background-color:  #11b611ff;
    border-radius: 50%;
    margin-right: 5px;
    vertical-align: middle;
}

/* Bolinha azul para "Autorizada (Dev)" */
.status-circle-blue {
    display: inline-block;
    width: 11px;
    height: 11px;
    background-color: #007bff;
    border-radius: 50%;
    margin-right: 5px;
    vertical-align: middle;
}

/* Bolinha vermelha */
.status-circle-red {
    display: inline-block;
    width: 11px;
    height: 11px;
    background-color: red;
    border-radius: 50%;
    margin-right: 5px;
    vertical-align: middle;
}

/* Bolinha amarela */
.status-circle-yellow {
    display: inline-block;
    width: 11px;
    height: 11px;
    background-color: #ffc107;
}
.nfse-pagination-controls {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}
.nfse-page-size-selector {
    display: flex;
    align-items: center;
    gap: 8px;
}
.nfse-page-size-selector label {
    font-size: 0.9em;
    color: #6c757d;
    white-space: nowrap;
}
.nfse-page-size-selector select {
    padding: 6px 12px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    background: white;
    font-size: 0.9em;
    cursor: pointer;
}
.nfse-page-nav {
    display: flex;
    gap: 5px;
}
.nfse-page-btn {
    padding: 6px 12px;
    border: 1px solid #ced4da;
    background: white;
    color: #495057;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.9em;
    text-decoration: none;
    transition: all 0.2s;
}
.nfse-page-btn:hover:not(.disabled) {
    background: #007bff;
    color: white;
    border-color: #007bff;
}
.nfse-page-btn.active {
    background: #007bff;
    color: white;
    border-color: #007bff;
    font-weight: bold;
}
.nfse-page-btn.disabled {
    opacity: 0.5;
    cursor: not-allowed;
    pointer-events: none;
}
.nfse-page-jump {
    display: flex;
    align-items: center;
    gap: 5px;
}
.nfse-page-jump input {
    width: 60px;
    padding: 6px 8px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    text-align: center;
    font-size: 0.9em;
}
.nfse-page-jump button {
    padding: 6px 12px;
    border: 1px solid #007bff;
    background: #007bff;
    color: white;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.9em;
}
@media (max-width: 768px) {
    .nfse-pagination-wrapper {
        flex-direction: column;
        align-items: stretch;
    }
    .nfse-pagination-controls {
        flex-direction: column;
    }
}
</style>';

print '<style type="text/css">
.butAction-std {
    min-width: 80px; /* Define uma largura mínima para os botões */
    text-align: center;
    display: inline-block;
}
</style>';

print '<style>
/* Bolinha verde */
.status-circle-green {
    display: inline-block;
    width: 11px;
    height: 11px;
    background-color:  #11b611ff;
    border-radius: 50%;
    margin-right: 5px;
    vertical-align: middle;
}

/* Bolinha azul para "Autorizada (Dev)" */
.status-circle-blue {
    display: inline-block;
    width: 11px;
    height: 11px;
    background-color: #007bff;
    border-radius: 50%;
    margin-right: 5px;
    vertical-align: middle;
}

/* Bolinha vermelha */
.status-circle-red {
    display: inline-block;
    width: 11px;
    height: 11px;
    background-color: red;
    border-radius: 50%;
    margin-right: 5px;
    vertical-align: middle;
}

/* Bolinha amarela */
.status-circle-yellow {
    display: inline-block;
    width: 11px;
    height: 11px;
    background-color: #ffc107;
    border-radius: 50%;
    margin-right: 5px;
    vertical-align: middle;
}

/* Bolinha preta para "Denegada" */
.status-circle-denied {
    display: inline-block;
    width: 11px;
    height: 11px;
    background-color: #343a40;
    border-radius: 50%;
    margin-right: 5px;
    vertical-align: middle;
}

/* Cinza para status desconhecido */
.status-circle-gray {
    display: inline-block;
    width: 11px;
    height: 11px;
    background-color: #6c757d;
    border-radius: 50%;
    margin-right: 5px;
    vertical-align: middle;
}

/* Estilo para a legenda de status (ícone de informação) */
.status-legend-icon {
    cursor: help;
    color: #6c757d;
    margin-left: 4px;
}
</style>';

/* Responsividade para telas menores */
echo '<style>
@media (max-width: 768px) {
    body {
        font-size: 0.9em; /* Reduz o tamanho geral da fonte */
    }

    .liste {
        font-size: 0.85em; /* Reduz o tamanho da fonte na tabela */
    }

    .liste_titre, .liste_titre_filter {
        font-size: 0.8em; /* Reduz o tamanho dos títulos */
    }

    .butAction, .butAction-std {
        font-size: 0.75em; /* Reduz o tamanho dos botões */
        padding: 4px; /* Ajusta o espaçamento interno */
    }

    .table-responsive-wrapper {
        overflow-x: auto; /* Permite rolagem horizontal */
    }

    input.flat {
        font-size: 0.8em; /* Reduz o tamanho dos campos de entrada */
    }

    select {
        font-size: 0.8em; /* Reduz o tamanho dos campos de seleção */
    }

    textarea {
        font-size: 0.8em; /* Reduz o tamanho do texto em áreas de texto */
    }

    th, td {
        padding: 5px; /* Ajusta o espaçamento interno das células */
    }
}
</style>';

/* Ajuste geral para botões e fontes */
echo '<style>
.butAction, .butAction-std {
    font-size: 0.9em; /* Tamanho consistente da fonte */    
    min-width: 100px; /* Largura mínima consistente */
    height: 40px; /* Altura fixa para todos os botões */
    box-sizing: border-box; /* Garante que padding e bordas não alterem o tamanho */
    text-align: center; /* Centraliza o texto */
    display: inline-flex; /* Garante alinhamento interno */
    justify-content: center; /* Centraliza horizontalmente */
    align-items: center; /* Centraliza verticalmente */
}

.butAction:hover, .butAction-std:hover {
    background-color: #0056b3; /* Cor de fundo ao passar o mouse */
    color: #fff; /* Cor do texto ao passar o mouse */
}

/* Ajuste para tabelas */
.liste {
    font-size: 1em; /* Tamanho da fonte consistente */
    border-collapse: collapse; /* Remove espaçamento entre bordas */
    width: 100%; /* Garante que a tabela ocupe toda a largura */
}

.liste th, .liste td {
    padding: 8px; /* Espaçamento interno das células */
    text-align: center; /* Centraliza o texto */
    vertical-align: middle; /* Centraliza verticalmente */
    border: 1px solid #ddd; /* Borda das células */
}

.liste th {
    background-color: #f4f4f4; /* Cor de fundo do cabeçalho */
    font-weight: bold; /* Negrito para o cabeçalho */
}

/* Ajuste para responsividade */
@media (max-width: 768px) {
    body {
        font-size: 0.9em; /* Reduz o tamanho geral da fonte */
    }

    .liste {
        font-size: 0.85em; /* Reduz o tamanho da fonte na tabela */
    }

    .liste th, .liste td {
        padding: 6px; /* Ajusta o espaçamento interno das células */
    }

    .butAction, .butAction-std {
        font-size: 0.8em; /* Reduz o tamanho da fonte dos botões */
        padding: 6px 10px; /* Ajusta o espaçamento interno */
    }

    input.flat, select, textarea {
        font-size: 0.85em; /* Reduz o tamanho da fonte dos campos de entrada */
    }
}
</style>';

/* Estilos adicionais para linhas por status */
print '<style>
.liste tr.row-status-autorizada td {
    background-color: rgba(73, 182, 76, 0.12); /* verde bem fraco */
}
.liste tr.row-status-autorizada-dev td {
    background-color: rgba(0, 123, 255, 0.1); /* azul bem fraco */
}
.liste tr.row-status-cancelada td {
    background-color: rgba(220, 53, 69, 0.11); /* vermelho bem fraco */
}
.liste tr.row-status-rejeitada td {
    background-color: rgba(255, 193, 7, 0.12); /* amarelo bem fraco */
}
.liste tr.row-status-denegada td {
    background-color: rgba(52, 58, 64, 0.12); /* cinza/preto bem fraco */
}

/* Mantém contraste ao passar o mouse (hover) */
.liste tr:hover td {
    background-color: rgba(0,0,0,0.02);
}
</style>';

/* Tabela de Filtros e Listagem */
print '<div class="table-responsive-wrapper">';
print '<form method="GET" action="'.$_SERVER["PHP_SELF"].'">';
print '<table class="liste noborder centpercent">';
print '<tr class="liste_titre">';

$legend_title = "Verde: Autorizada\nVermelho: Cancelada\nAmarelo: Rejeitada\nPreto: Denegada";
// tooltip gráfico com pré-visualização (bolinhas reais) e legenda — usa classes exclusivas para preview
$status_header_html = '
    <div class="status-header-wrapper" tabindex="0" aria-label="'.dol_escape_htmltag($langs->trans("Legenda de status")).'">
    <span class="status-title">'. $langs->trans("Status") .'</span>
    <span class="fa fa-info-circle status-legend-icon" aria-hidden="true"></span>
    <div class="status-tooltip" role="tooltip" aria-hidden="true">
   
 <div class="status-tooltip-items">
 <div class="tooltip-item"><span class="status-preview-circle status-preview-green"></span><span class="tooltip-label">'. $langs->trans("Autorizada") .'</span></div>
 <div class="tooltip-item"><span class="status-preview-circle status-preview-blue"></span><span class="tooltip-label">'. $langs->trans("Devolvida") .'</span></div>
<div class="tooltip-item"><span class="status-preview-circle status-preview-red"></span><span class="tooltip-label">'. $langs->trans("Cancelada") .'</span></div>
 <div class="tooltip-item"><span class="status-preview-circle status-preview-yellow"></span><span class="tooltip-label">'. $langs->trans("Rejeitada") .'</span></div>
 <div class="tooltip-item"><span class="status-preview-circle status-preview-denied"></span><span class="tooltip-label">'. $langs->trans("Denegada") .'</span></div>
 </div>
 </div>
</div>';
print_liste_field_titre($status_header_html, $_SERVER["PHP_SELF"], "status", "", "", 'align="center"', $sortfield, $sortorder);
// REMOVIDO: coluna Tipo
print_liste_field_titre($langs->trans("Fatura"), $_SERVER["PHP_SELF"], "fk_facture", "", "", 'align="center"', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("Número NFe"), $_SERVER["PHP_SELF"], "numero_nfe", "", "", 'align="center"', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("Razão Social"), $_SERVER["PHP_SELF"], "company_name", "", "", 'align="center"');
print_liste_field_titre($langs->trans("Data de Emissao"), $_SERVER["PHP_SELF"], "data_emissao", "", "", 'align="center"', $sortfield, $sortorder);
print_liste_field_titre($langs->trans("Ações"), '', '', '', '', 'align="center"');
print "</tr>";

print '<tr class="liste_titre_filter">';


// SUBSTITUIR o filtro de status por popover igual ao CT-e
$search_status_normalized = strtolower(trim($search_status));
$selectedNfeStatuses = array();
if ($search_status_normalized !== '') {
    $allowedNfeStatuses = array('autorizada','cancelada','rejeitada','denegada','autorizada dev');
    $rawParts = array_map('trim', explode(',', $search_status_normalized));
    foreach ($rawParts as $part) {
        if (in_array($part, $allowedNfeStatuses, true)) {
            $selectedNfeStatuses[] = $part;
        }
    }
}
$selectedNfeStatusesUnique = array_values(array_unique($selectedNfeStatuses));
$badgeCountNfe = count($selectedNfeStatusesUnique);
$selectedStatusListStrNfe = dol_escape_htmltag(implode(',', $selectedNfeStatusesUnique));

print '<td class="center status-filter-cell">';
print '<input type="hidden" name="search_status" id="search_status_hidden" value="'.$selectedStatusListStrNfe.'">';
print '<button type="button" class="nfe-status-filter-toggle" onclick="toggleNfeStatusFilter(event)">Filtrar'.($badgeCountNfe? ' <span id="nfeStatusSelCount" class="badge">'.$badgeCountNfe.'</span>':'').'</button>';
print '<div class="nfe-status-filter-popover" id="nfeStatusFilterPopover">';
$optsNfe = array(
    'autorizada'    => 'Autorizada',
    'autorizada dev' => 'Devolvida',
    'cancelada'     => 'Cancelada',
    'rejeitada'     => 'Rejeitada',
    'denegada'      => 'Denegada',
);
foreach ($optsNfe as $key => $label) {
    $checked   = in_array($key, $selectedNfeStatusesUnique, true) ? ' checked' : '';
    $chipClass = ($key === 'autorizada dev') ? 'devolvida' : $key;
    print '<label class="opt"><input type="checkbox" value="'.dol_escape_htmltag($key).'"'.$checked.'> <span class="nfe-chip '.$chipClass.'"></span> '.$label.'</label>';
}
print '<div class="actions">';
print '<button type="button" class="butActionDelete" onclick="clearNfeStatusFilter()">Limpar</button>';
print '<button type="button" class="butAction" onclick="applyNfeStatusFilter()">Aplicar</button>';
print '</div></div>';
print '</td>';

print '<td class="center"><input type="text" name="search_fk_facture" value="'.dol_escape_htmltag($search_fk_facture).'" class="flat" size="6"></td>';
print '<td class="center">';
print '<input type="text" name="search_numero_nfe_start" value="'.dol_escape_htmltag($search_numero_nfe_start).'" class="flat" size="6" placeholder="De"> - ';
print '<input type="text" name="search_numero_nfe_end" value="'.dol_escape_htmltag($search_numero_nfe_end).'" class="flat" size="6" placeholder="Até">';
print '</td>';
print '<td class="center"><input type="text" name="search_client_name" value="'.dol_escape_htmltag($search_client_name).'" class="flat" size="20"></td>';
print '<td class="center">';
print '<input type="date" name="search_data_emissao_start" value="'.dol_escape_htmltag($search_data_emissao_start).'" class="flat"> - ';
print '<input type="date" name="search_data_emissao_end" value="'.dol_escape_htmltag($search_data_emissao_end).'" class="flat">';
print '</td>';
print '<td class="center" colspan="2">';
print '<input type="submit" class="butAction search-button" value="PROCURAR" title="Procurar">';
print '<button type="submit" formaction="download_batch_pdf.php" class="butAction">'.$langs->trans("Baixar em Lote").'</button>';
print '</td>';
print '</tr>';

// Adicionar campos ocultos para passar os parâmetros de número inicial, final e data de emissão
print '<input type="hidden" name="numero_nfe_start" value="'.dol_escape_htmltag($search_numero_nfe_start).'">';
print '<input type="hidden" name="numero_nfe_end" value="'.dol_escape_htmltag($search_numero_nfe_end).'">';
print '<input type="hidden" name="data_emissao_start" value="'.dol_escape_htmltag($search_data_emissao_start).'">';
print '<input type="hidden" name="data_emissao_end" value="'.dol_escape_htmltag($search_data_emissao_end).'">';

$i = 0;

// Antes do loop, css para badge
print '<style>
.badge-dev {background:#007bff;color:#fff;padding:2px 6px;border-radius:4px;font-size:0.72em;font-weight:600;}
.badge-orig {background:#6c757d;color:#fff;padding:2px 6px;border-radius:4px,font-size:0.72em;}

/* Estilos para filtro de status igual ao CT-e */
.status-filter-cell{ position: relative; }
.nfe-status-filter-toggle{
    padding: 6px 12px;
    border: 1px solid #cfe6e2;
    background: #fff;
    color: #2c7a7b;
    border-radius: 16px;
    cursor: pointer;
    font-size: .88em;
    line-height: 1.3;
    display: inline-flex;
    align-items: center;
    gap: 6px;
}
.nfe-status-filter-toggle:hover{ background:#f0fbf8; border-color:#1abc9c; }
.nfe-status-filter-toggle .badge{
    display: inline-block;
    min-width: 18px;
    padding: 2px 6px;
    border-radius: 10px;
    background: #1abc9c;
    color: #fff;
    font-size: .78em;
}

.nfe-status-filter-popover{
    position: absolute !important;
    top: calc(100% + 6px);
    left: 0;
    z-index: 9999;
    background: #fff;
    border: 1px solid #e5f2ef;
    box-shadow: 0 8px 20px rgba(0,0,0,.12);
    border-radius: 8px;
    padding: 10px 12px;
    width: max-content;
    max-width: 360px;
    display: none;
}
.nfe-status-filter-popover.visible{ display:block; }

.nfe-status-filter-popover .opt{
    display: flex;
    align-items: center;
    gap: 8px;
    padding: 6px 8px;
    border-radius: 6px;
    cursor: pointer;
    transition: background .12s ease;
}
.nfe-status-filter-popover .opt + .opt{ margin-top: 4px; }
.nfe-status-filter-popover .opt:hover{ background: rgba(22,160,133,0.06); }

.nfe-status-filter-popover .opt input[type="checkbox"]{
    width: 14px;
    height: 14px;
    accent-color: #16a085;
    border-radius: 3px;
    margin: 0 2px 0 0;
}
.nfe-status-filter-popover .opt .nfe-chip{
    width: 8px;
    height: 8px;
    border-radius: 50%;
    box-shadow: inset 0 -1px 0 rgba(0,0,0,0.05);
}
.nfe-chip.autorizada{ background: #28a745; }
.nfe-chip.devolvida{ background: #007bff; }
.nfe-chip.cancelada{ background: #dc3545; }
.nfe-chip.rejeitada{ background: #ffc107; }
.nfe-chip.denegada{ background: #343a40; }

.nfe-status-filter-popover .actions{
    display: flex;
    gap: 8px;
    justify-content: flex-end;
    margin-top: 10px;
    padding-top: 8px;
    border-top: 1px solid #f0f0f0;
}

/* Mensagem quando não há registros */
.nfe-empty-message {
    padding: 40px 20px;
    text-align: center;
    color: #6c757d;
    font-size: 1.1em;
}
.nfe-empty-message i {
    font-size: 3em;
    color: #dee2e6;
    margin-bottom: 15px;
    display: block;
}
</style>';
if($num > 0){
    while ($i < min($num, $limit)) {
    $obj = $db->fetch_object($resql);
    $url_facture = DOL_URL_ROOT.'/compta/facture/card.php?id='.$obj->fk_facture;
    $url_pdf = DOL_URL_ROOT.'/custom/nfe/download_pdf.php?id='.$obj->id;

    $status_normalized = strtolower(trim((string)$obj->status));
    $isDevolucaoAut = (strpos($status_normalized, 'autoriz') !== false && strpos($status_normalized, 'dev') !== false);

    if ($isDevolucaoAut) {
        $statusClass = 'row-status-autorizada-dev';
    } elseif (strpos($status_normalized, 'autoriz') !== false) {
        $statusClass = 'row-status-autorizada';
    } elseif (strpos($status_normalized, 'cancel') !== false) {
        $statusClass = 'row-status-cancelada';
    } elseif (strpos($status_normalized, 'rejeit') !== false) {
        $statusClass = 'row-status-rejeitada';
    } elseif (strpos($status_normalized, 'denegad') !== false) {
        $statusClass = 'row-status-denegada';
    }

    if ($isDevolucaoAut) {
        $status_display = '<span class="status-circle status-circle-blue" title="Autorizada (Devolução)"></span>';
    } elseif (strpos($status_normalized, 'autoriz') !== false) {
        $status_display = '<span class="status-circle status-circle-green" title="Autorizada"></span>';
    } elseif (strpos($status_normalized, 'cancel') !== false) {
        $status_display = '<span class="status-circle status-circle-red" title="Cancelada"></span>';
    } elseif (strpos($status_normalized, 'rejeit') !== false) {
        $status_display = '<span class="status-circle status-circle-yellow" title="Rejeitada"></span>';
    } elseif (strpos($status_normalized, 'denegad') !== false) {
        $status_display = '<span class="status-circle status-circle-denied" title="Denegada"></span>';
    } else {
        $status_display = '<span class="status-circle status-circle-gray" title="'.dol_escape_htmltag($obj->status).'"></span>';
    }

    print '<tr class="oddeven '.$statusClass.'">';
    print '<td class="center" title="'.dol_escape_htmltag($obj->motivo_status).'">'.$status_display.'</td>';

    // REMOVIDO bloco "Coluna Tipo" e badges

    print '<td class="center"><a href="'.$url_facture.'">#'.$obj->fk_facture.'</a></td>';
    print '<td class="center">'.$obj->numero_nfe.'</td>';
    print '<td class="center">'.(empty($obj->company_name_alias)?dol_escape_htmltag($obj->company_name):dol_escape_htmltag($obj->company_name_alias)).'</td>';
    print '<td class="center">'.dol_print_date(dol_stringtotime($obj->data_emissao), 'standard').'</td>';

    print '<td class="center actions-cell"><div class="nfe-dropdown">';
    print '<button class="butAction dropdown-toggle" type="button" onclick="toggleDropdown(event, \'nfeDropdownMenu'.$obj->id.'\')">'.$langs->trans("Ações").'</button>';
    print '<div class="nfe-dropdown-menu" id="nfeDropdownMenu'.$obj->id.'">';

    // Substituir Visualizar: abrir em nova aba usando viewer_pdf.php
    $url_viewer = DOL_URL_ROOT.'/custom/nfe/viewer_pdf.php?id=' . (int)$obj->id;
    if (!empty($obj->pdf_file)) {
        print '<a class="nfe-dropdown-item" href="'.$url_viewer.'" target="_blank" rel="noopener noreferrer">'.$langs->trans("Visualizar").'</a>';
    } else {
        print '<a class="nfe-dropdown-item disabled" href="#">'.$langs->trans("Visualizar").'</a>';
    }

    // Download PDF
    if (!empty($obj->pdf_file)) {
        print '<a class="nfe-dropdown-item" href="'.$url_pdf.'" target="_blank">'.$langs->trans("Download").'</a>';
    } else {
        print '<a class="nfe-dropdown-item disabled" href="#">'.$langs->trans("Download").'</a>';
    }

    // Novo: Documentos relacionados (original + devoluções)
    print '<a class="nfe-dropdown-item" href="#" onclick="openDocs('.(int)$obj->id.');return false;">'.$langs->trans("Documentos").'</a>';

    // ALTERADO: desabilitar Cancelar se houver devoluções
    $hasDevolucoes = !empty($obj->devolucoes_count);
    if (!$isDevolucaoAut && strpos($status_normalized, 'autoriz') !== false) {
        if ($hasDevolucoes) {
            print '<a class="nfe-dropdown-item disabled" href="#" title="'.$langs->trans("Existem devoluções vinculadas a esta NF-e. Cancele/estorne a devolução primeiro.").'">'.$langs->trans("Cancelar").'</a>';
        } else {
            print '<a class="nfe-dropdown-item cancelar-btn" href="#" data-id="'.$obj->id.'" data-emissao="'.dol_escape_htmltag($obj->data_emissao).'">'.$langs->trans("Cancelar").'</a>';
        }
        if ($user->admin) {
            print '<a class="nfe-dropdown-item corrigir-btn" href="#" data-id="'.$obj->id.'" data-emissao="'.dol_escape_htmltag($obj->data_emissao).'">'.$langs->trans("CC-E").'</a>';
        }
        // $url_devolucao = DOL_URL_ROOT.'/custom/nfe/devolucao_nfe.php?id_nfe_origem='.$obj->id;
        // if ($hasDevolucoes) {
        //     print '<a class="nfe-dropdown-item disabled" href="#" title="'.$langs->trans("Já existe devolução autorizada para esta NF-e.").'">'.$langs->trans("Devolução").'</a>';
        // } else {
        //     print '<a class="nfe-dropdown-item" href="'.$url_devolucao.'">'.$langs->trans("Devolução").'</a>';
        // }
    } elseif ($isDevolucaoAut) {
        print '<a class="nfe-dropdown-item disabled" href="#">'.$langs->trans("Cancelar").'</a>';
        if ($user->admin) print '<a class="nfe-dropdown-item disabled" href="#">'.$langs->trans("CC-E").'</a>';
        print '<a class="nfe-dropdown-item disabled" href="#">'.$langs->trans("Devolução").'</a>';
    } else {
        print '<a class="nfe-dropdown-item disabled" href="#">'.$langs->trans("Cancelar").'</a>';
        if ($user->admin) print '<a class="nfe-dropdown-item disabled" href="#">'.$langs->trans("CC-E").'</a>';
    }

    if ($user->admin) {
        $url_historico = DOL_URL_ROOT.'/custom/nfe/historico_nfe.php?id='.$obj->id;
        print '<a class="nfe-dropdown-item" href="'.$url_historico.'">'.$langs->trans("Histórico").'</a>';
    }

    print '</div></div></td></tr>';
    $i++;
} }else{
    print '<tr><td colspan="8" class="opacitymedium center">Nenhuma regra fiscal encontrada</td></tr>';
}

print '</table>';
print '</form>';
print '</div>'; // <-- fix: fecha corretamente o wrapper


$res_count = $db->query($sql_count);
$total_rows = 0;
if ($res_count) {
    $obj_count = $db->fetch_object($res_count);
    if ($obj_count) {
        $total_rows = $obj_count->total;
    }
}

// Ajusta página se offset exceder total
if ($offset >= $total_rows && $total_rows > 0) {
    $page = floor(($total_rows - 1) / $limit);
    $offset = $limit * $page;
}

// --- PAGINAÇÃO CUSTOMIZADA (idêntica ao nfse_list.php) ---
$filters = [
    'search_status' => $search_status,
    'search_fk_facture' => $search_fk_facture,
    'search_numero_nfe_start' => $search_numero_nfe_start,
    'search_numero_nfe_end' => $search_numero_nfe_end,
    'search_chave' => $search_chave,
    'search_data_emissao_start' => $search_data_emissao_start,
    'search_data_emissao_end' => $search_data_emissao_end,
    'search_client_name' => $search_client_name,
    'sortfield' => $sortfield,
    'sortorder' => $sortorder
];

if (!function_exists('nfse_buildURL')) {
    function nfse_buildURL($page, $limitVal, $filtersArray) {
        $params = array_merge(array_filter($filtersArray), [
            'page' => $page,
            'limit' => $limitVal
        ]);
        return $_SERVER["PHP_SELF"] . '?' . http_build_query($params);
    }
}

$totalPages = ($total_rows > 0) ? ceil($total_rows / $limit) : 1;
$currentPage = $page + 1;
$startRecord = ($total_rows > 0) ? ($offset + 1) : 0;
$endRecord = min($offset + $limit, $total_rows);

print '<div class="nfse-pagination-wrapper">';

// Info de registros
print '<div class="nfse-pagination-info">';
print 'Mostrando <strong>'.$startRecord.'</strong> a <strong>'.$endRecord.'</strong> de <strong>'.$total_rows.'</strong> registros';
print '</div>';

print '<div class="nfse-pagination-controls">';

// Seletor de tamanho de página
print '<div class="nfse-page-size-selector">';
print '<label>Por página:</label>';
print '<select onchange="window.location.href=this.value">';
foreach ($limitOptions as $opt) {
    $selected = ($opt == $limit) ? ' selected' : '';
    $url = nfse_buildURL(0, $opt, $filters);
    print '<option value="'.$url.'"'.$selected.'>'.$opt.'</option>';
}
print '</select>';
print '</div>';

// Navegação de páginas
print '<div class="nfse-page-nav">';

// Botão Anterior
$prevPage = max(0, $page - 1);
$prevDisabled = ($page == 0) ? 'disabled' : '';
$prevUrl = nfse_buildURL($prevPage, $limit, $filters);
print '<a href="'.$prevUrl.'" class="nfse-page-btn '.$prevDisabled.'">‹ Anterior</a>';

// Páginas numeradas (mostra até 5 ao redor da atual)
$startPage = max(0, $page - 2);
$endPage = min($totalPages - 1, $page + 2);

if ($startPage > 0) {
    $firstUrl = nfse_buildURL(0, $limit, $filters);
    print '<a href="'.$firstUrl.'" class="nfse-page-btn">1</a>';
    if ($startPage > 1) print '<span class="nfse-page-btn disabled">...</span>';
}

for ($p = $startPage; $p <= $endPage; $p++) {
    $pageUrl = nfse_buildURL($p, $limit, $filters);
    $activeClass = ($p == $page) ? 'active' : '';
    print '<a href="'.$pageUrl.'" class="nfse-page-btn '.$activeClass.'">'.($p + 1).'</a>';
}

if ($endPage < $totalPages - 1) {
    if ($endPage < $totalPages - 2) print '<span class="nfse-page-btn disabled">...</span>';
    $lastUrl = nfse_buildURL($totalPages - 1, $limit, $filters);
    print '<a href="'.$lastUrl.'" class="nfse-page-btn">'.$totalPages.'</a>';
}

// Botão Próximo
$nextPage = min($totalPages - 1, $page + 1);
$nextDisabled = ($page >= $totalPages - 1) ? 'disabled' : '';
$nextUrl = nfse_buildURL($nextPage, $limit, $filters);
print '<a href="'.$nextUrl.'" class="nfse-page-btn '.$nextDisabled.'">Próximo ›</a>';

print '</div>'; // .nfse-page-nav

// Salto direto para página
if ($totalPages > 5) {
    print '<div class="nfse-page-jump">';
    print '<span>Ir para:</span>';
    print '<input type="number" id="nfseJumpPage" min="1" max="'.$totalPages.'" value="'.$currentPage.'">';
    print '<button onclick="jumpToPageNfse('.$totalPages.', '.$limit.')">Ir</button>';
    print '</div>';
}

print '</div>'; // .nfse-pagination-controls
print '</div>'; // .nfse-pagination-wrapper

// JS para controle de paginação
$filtersJson = json_encode($filters);
print '<script>
function jumpToPageNfse(totalPages, currentLimit) {
    var input = document.getElementById("nfseJumpPage");
    var pageNum = parseInt(input.value);
    if (!pageNum || pageNum < 1 || pageNum > totalPages) {
        alert("Página inválida. Digite um número entre 1 e " + totalPages);
        return;
    }
    var filters = '. $filtersJson .';
    filters.page = pageNum - 1;
    filters.limit = currentLimit;
    window.location.href = "' . $_SERVER["PHP_SELF"] . '?" + new URLSearchParams(Object.entries(filters).filter(([k,v]) => v)).toString();
}

// Funções para filtro de status igual ao CT-e
function toggleNfeStatusFilter(e){
    e.stopPropagation();
    var pop = document.getElementById("nfeStatusFilterPopover");
    if (!pop) return;
    var open = pop.classList.contains("visible");
    pop.classList.toggle("visible", !open);
}

function applyNfeStatusFilter(){
    var pop = document.getElementById("nfeStatusFilterPopover");
    var hidden = document.getElementById("search_status_hidden");
    var form = document.querySelector("form[method=GET]");
    if (!pop || !hidden || !form) return;
    var vals = Array.from(pop.querySelectorAll("input[type=checkbox]:checked")).map(function(i){ return i.value; });
    var joined = vals.join(",");
    hidden.value = joined;
    form.submit();
}

function clearNfeStatusFilter(){
    var pop = document.getElementById("nfeStatusFilterPopover");
    var hidden = document.getElementById("search_status_hidden");
    if (!pop || !hidden) return;
    pop.querySelectorAll("input[type=checkbox]").forEach(function(i){ i.checked = false; });
    hidden.value = "";
    var badge = document.getElementById("nfeStatusSelCount");
    if (badge) badge.remove();
}

document.addEventListener("click", function(e){
    if (!e.target.closest(".status-filter-cell")) {
        document.querySelectorAll(".nfe-status-filter-popover").forEach(function(p){ p.classList.remove("visible"); });
    }
});
</script>';

// Pop-ups e modais (Cancelamento, CC-e, Setup)
?>

<!-- Pop-up de Cancelamento -->
<div id="dialog-cancel-nfe" title="<?php echo $langs->trans("Cancelar NFe"); ?>" style="display: none;">
    <p><?php echo $langs->trans("Selecione o motivo do cancelamento:"); ?></p>
    <form id="form-cancel-nfe" onsubmit="return false;">
        <select name="justificativa_select" id="justificativa_cancelamento_select" style="width: 98%; font-size: 1.1em; padding: 5px;">
            <option value="Erro de digitação nos dados da nota fiscal emitida.">Erro de digitação na NF-e</option>
            <option value="Desistência do comprador antes da retirada da mercadoria.">Desistência do comprador</option>
            <option value="Mercadoria não foi entregue ou não foi recebida pelo destinatário.">Mercadoria não entregue</option>
            <option value="Cancelamento por desacordo comercial com o cliente.">Desacordo comercial</option>
            <option value="_OUTROS_"><?php echo $langs->trans("Outras Justificativas"); ?></option>
        </select>
        <div id="outros_justificativa_wrapper" style="display:none; margin-top:10px;">
            <label for="justificativa_outros_text"><?php echo $langs->trans("Justificativa"); ?> (mín. 15 caracteres):</label>
            <textarea id="justificativa_outros_text" style="width: 98%;" rows="3"></textarea>
        </div>
        <p id="cancel-expired-msg" class="error-message" style="display: none;"></p>
    </form>
</div>

<!-- Pop-up para Carta de Correção (CC-e) - HTML Simplificado -->
<div id="dialog-cce-nfe" title="<?php echo $langs->trans("Carta de Correção Eletrônica"); ?>" style="display: none;">
    <p><?php echo $langs->trans("Digite o texto da correção abaixo. Lembre-se que não é permitido alterar valores ou dados que alterem o imposto."); ?></p>
    <label for="cce_text"><?php echo $langs->trans("Correção"); ?> (mín. 15 caracteres):</label>
    <textarea id="cce_text" name="justificativa" style="width: 98%;" rows="5"></textarea>
    <p id="cce-expired-msg" class="error-message" style="display: none;"></p>
</div>

<!-- Modal para Configurações -->
<div id="dialog-setup-nfe" title="<?php echo $langs->trans("Configuração de ambiente"); ?>" style="display: none;">
	<div id="setup-content">
		<!-- O conteúdo de setup.php será carregado aqui -->
		<div class="center"><i class="fa fa-spinner fa-spin fa-3x"></i></div>
	</div>
</div>

<!-- Modal de confirmação para Produção -->
<div id="dialog-confirm-producao" title="<?php echo $langs->trans("Confirmar Produção"); ?>" style="display:none;">
	<p><?php echo $langs->trans("Tem certeza que deseja alterar o ambiente para Produção?"); ?></p>
</div>

<!-- Modal Documentos Relacionados -->
<div id="dialog-docs-nfe" title="Documentos Relacionados" style="display:none;">
    <div id="docs-nfe-content" class="center" style="padding:18px;">
        <i class="fa fa-spinner fa-spin fa-2x"></i>
    </div>
</div>
<style>
#dialog-docs-nfe .tbl-docs-nfe a { text-decoration:none; }
#dialog-docs-nfe .tbl-docs-nfe a:hover { text-decoration:underline; }
</style>
<script>
function openDocs(id) {
    var $dlg = jQuery("#dialog-docs-nfe");
    if(!$dlg.data("uiDialog")) {
        $dlg.dialog({
            modal:true,
            width: Math.min(jQuery(window).width()-40, 820),
            resizable:false,
            buttons: {
                "<?php echo dol_escape_js($langs->trans("Close")); ?>": function(){ jQuery(this).dialog("close"); }
            },
            open: function(){
                jQuery("#docs-nfe-content").html('<div class="center" style="padding:18px;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>');
            }
        });
    } else {
        $dlg.dialog("open");
        jQuery("#docs-nfe-content").html('<div class="center" style="padding:18px;"><i class="fa fa-spinner fa-spin fa-2x"></i></div>');
    }
    jQuery.get("<?php echo DOL_URL_ROOT; ?>/custom/nfe/documentos_nfe.php?id="+id)
        .done(function(html){
            jQuery("#docs-nfe-content").html(html);
        })
        .fail(function(xhr){
            jQuery("#docs-nfe-content").html("Erro ao carregar: "+xhr.status+" "+xhr.statusText);
        });
}
</script>

<script>
$(document).ready(function() {
	// Configuração do modal
	var dialogSetup = $("#dialog-setup-nfe").dialog({
		autoOpen: false,
		modal: true,
		width: 700, // largura menor
		maxWidth: 700,
		resizable: false,
		position: { my: "center", at: "center", of: window }, // centraliza na tela
		open: function() {
			// Ajuste responsivo: limita à largura da janela com margem e centraliza
			var w = Math.min($(window).width() - 40, 700);
			$(this).dialog("option", "width", w);

			$(this).dialog("option", "position", { my: "center", at: "center", of: window });
		},
		buttons: {
			"<?php echo dol_escape_js($langs->trans("Save")); ?>": function() {
				// Submete o formulário dentro do modal
				$(this).find('form').trigger('submit');
			},
			"<?php echo dol_escape_js($langs->trans("Close")); ?>": function() {
				$(this).dialog("close");
			}
		}
	});

    // Listener para abrir a modal de configuração
    $("#open-setup-modal").on("click", function(e) {
        e.preventDefault();
        // Carrega o conteúdo via AJAX antes de abrir
        $("#setup-content").load("setup.php", function(response, status, xhr) {
            if (status == "error") {
                $("#setup-content").html("Erro ao carregar configuração: " + xhr.status + " " + xhr.statusText);
            }
            dialogSetup.dialog("open");
        });

    });

	// DEFINE dialogInutil para evitar ReferenceError no resize
    // REMOVIDO: var dialogInutil = $("#dialog-inutilizar");

	// Mantém centralizado ao redimensionar a janela
	$(window).on('resize', function() {
		if (dialogSetup.dialog("isOpen")) {
			var w = Math.min($(window).width() - 40, 700);
			dialogSetup.dialog("option", "width", w);
			dialogSetup.dialog("option", "position", { my: "center", at: "center", of: window });
		}
		// REMOVIDO: código do dialogInutil
		if ($("#dialog-docs-nfe").length && $("#dialog-docs-nfe").hasClass("ui-dialog-content") && $("#dialog-docs-nfe").dialog("isOpen")) {
			$("#dialog-docs-nfe").dialog("option", "position", { my: "center", at: "center", of: window });
		}
	});

	// Modal simples de confirmação para Produção
	var pendingProdRadio = null;
	var dialogConfirmProd = $("#dialog-confirm-producao").dialog({
		autoOpen: false,
		modal: true,
		width: 450,
		resizable: false,
		buttons: {
			"<?php echo dol_escape_js($langs->trans("Sim")); ?>": function() {
				// Mantém Produção selecionado
				pendingProdRadio = null;
				$(this).dialog("close");
			},
			"<?php echo dol_escape_js($langs->trans("Não")); ?>": function() {
				// Reverte para Homologação se o usuário cancelar
				if (pendingProdRadio) {
					pendingProdRadio.prop('checked', false);
					$('input[name="ambiente"][value="2"]').prop('checked', true);
					pendingProdRadio = null;
				}
				$(this).dialog("close");
			}
		}
	});

	// Evento de mudança no rádio de ambiente
	$(document).on('change', 'input[name="ambiente"]', function() {
		if (this.checked && this.value === '1') { // Produção
			pendingProdRadio = $(this);
			dialogConfirmProd.dialog('open');
		}
 }
	);

	// Intercepta o clique no menu lateral "Configurações"
	$(document).on("click", 'a[href*="/custom/nfe/admin/setup.php"]', function(e) {
		e.preventDefault();

		var url = "<?php echo dol_escape_js($_SERVER['PHP_SELF']); ?>?action=getsetupform";

		$("#setup-content").html('<div class="center"><i class="fa fa-spinner fa-spin fa-3x"></i></div>');
		dialogSetup.dialog("open");
		dialogSetup.dialog("option", "position", { my: "center", at: "center", of: window });

		$.get(url)
			.done(function(html)
			{
				if (!html || !html.trim().length) {
					$("#setup-content").html('<div class="center"><?php echo dol_escape_js($langs->trans("Error")); ?>: Conteúdo vazio.</div>');
				} else {
					$("#setup-content").html(html);
				}
				// Reposiciona após inserir o conteúdo
				dialogSetup.dialog("option", "position", { my: "center", at: "center", of: window });
			})
			.fail(function(xhr) {
				$("#setup-content").html("Erro ao carregar o conteúdo: " + xhr.status + " " + xhr.statusText);
			});
	});

	// Abrir modal de Inutilização ao clicar no menu lateral
	$(document).on("click", 'a[href*="/custom/nfe/inutilizacao.php"]', function(e) {
		e.preventDefault();
		// Simplesmente dispara o clique no botão que já abre a modal correta.
		// Isso garante que ambos (botão e link do menu) tenham exatamente o mesmo comportamento.
		$('#open-inutilizacao-modal').trigger('click');
	});
    
	// Abre a modal de inutilização ao clicar no botão
    $('#open-inutilizacao-modal').on('click', function(e) {
        e.preventDefault();
        $("#dialog-inutilizar").dialog({
            modal: true,
            width: 500,
            resizable: false,
            buttons: {
                "<?php echo dol_escape_js($langs->trans("Close")); ?>": function() {
                    $(this).dialog("close");
                }
            }
        });
    });

    // Submissão do formulário de inutilização: validação cliente + submit normal (POST)
    $('#inutilizar-confirm-btn').on('click', function(e) {
        e.preventDefault(); // Previne o envio padrão do formulário
        var form = $('#form-inutilizar');
        var btn = $(this);

        // Validações simples no cliente
        var nnf_ini = parseInt($('#inutil-nnf-ini').val() || '0', 10);
        var nnf_fin = parseInt($('#inutil-nnf-fin').val() || '0', 10);
        var justificativa = $('#inutil-justificativa').val() ? $('#inutil-justificativa').val().trim() : '';

        if (!nnf_ini || !nnf_fin) {
            alert('<?php echo dol_escape_js($langs->trans("Preencha número inicial e final corretamente.")); ?>');
            return;
        }
        if (nnf_ini > nnf_fin) {
            alert('<?php echo dol_escape_js($langs->trans("Número inicial não pode ser maior que número final.")); ?>');
            return;
        }
        if (justificativa.length < 15) {
            alert('<?php echo dol_escape_js($langs->trans("A justificativa precisa de pelo menos 15 caracteres.")); ?>');
            return;
        }

        // Desabilita o botão e mostra o estado de carregamento
        btn.prop('disabled', true);
        btn.html('<i class="fa fa-spinner fa-spin"></i> <?php echo dol_escape_js($langs->trans("Processando...")); ?>');

        // Submete o formulário
        form.submit();
    });
});
</script>

<script>
$(document).ready(function() {
    // --- Lógica do Pop-up de Cancelamento (Mantido como estava) ---
    var dialogCancel = $("#dialog-cancel-nfe").dialog({
        autoOpen: false, modal: true, width: 550,
        buttons: {
            "<?php echo dol_escape_js($langs->trans("Emitir Cancelamento")); ?>": function() {
                var dialog = $(this);
                var button = dialog.closest('.ui-dialog').find('.ui-dialog-buttonpane button:first');
                button.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> <?php echo dol_escape_js($langs->trans("Processando...")); ?>');

                var justificativa;
                var selected_option = $("#justificativa_cancelamento_select").val();
                var nfe_id = $(this).data('nfe_id');
                if (selected_option === '_OUTROS_') {
                    justificativa = $("#justificativa_outros_text").val().trim();
                    if (justificativa.length < 15) {
                        alert("<?php echo dol_escape_js($langs->trans("A justificativa precisa de pelo menos 15 caracteres.")); ?>");
                        button.prop('disabled', false).html("<?php echo dol_escape_js($langs->trans("Emitir Cancelamento")); ?>");
                        return;
                    }
                } else {
                    justificativa = selected_option;
                }
                // Desabilita o botão e exibe processando
                button.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> <?php echo dol_escape_js($langs->trans("Processando...")); ?>');
                
                // Requisição AJAX
                $.ajax({
                    url: "<?php echo $_SERVER['PHP_SELF']; ?>",
                    type: "POST",
                    data: {
                        action: "submitcancelar",
                        id: nfe_id,
                        justificativa: justificativa,
                        ajax: 1,
                        token: "<?php echo dol_escape_js($_SESSION['newtoken']); ?>"
                    },
                    dataType: "json",
                    success: function(response) {
                        console.log("Resposta do servidor:", response);
                        button.prop('disabled', false).html('<?php echo dol_escape_js($langs->trans("Cancelar NFe")); ?>');
                        
                        if (response.success) {
                            $.jnotify(response.message, "ok", true);
                            dialog.dialog("close");
                            setTimeout(function() { location.reload(); }, 2000);
                        } else {
                            $.jnotify(response.message || "Erro desconhecido", "error", true);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("Erro AJAX:", status, error);
                        console.error("Resposta do servidor:", xhr.responseText);
                        button.prop('disabled', false).html('<?php echo dol_escape_js($langs->trans("Cancelar NFe")); ?>');
                        
                        var errorMsg = "Erro de comunicação com o servidor";
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.message) errorMsg = resp.message;
                        } catch(e) {
                            if (xhr.responseText) {
                                errorMsg += ": " + xhr.responseText.substring(0, 200);
                            }
                        }
                        $.jnotify(errorMsg, "error", true);
                    }
                });
            },
            "<?php echo dol_escape_js($langs->trans("Close")); ?>": function() { $(this).dialog("close"); }
        }
    });

    $(".cancelar-btn").on("click", function(e) {
        e.preventDefault();
        var nfe_id = $(this).data("id");
        var dataEmissao = $(this).data("emissao");
        var emissaoDate = new Date(dataEmissao.replace(/-/g, '/')); // Corrigir formato para compatibilidade
        var hoje = new Date();
        var diffTime = hoje.getTime() - emissaoDate.getTime();
        var diffHours = diffTime / (1000 * 60 * 60);

        var dialog = $("#dialog-cancel-nfe");
        var cancelButton = dialog.closest('.ui-dialog').find('.ui-dialog-buttonpane button:first');
        
        if (diffHours > 24) {
            dialog.find("#cancel-expired-msg").text("O prazo para cancelamento desta NF-e expirou. A legislação permite o cancelamento em até 24 horas após a autorização.").show();
            cancelButton.prop('disabled', true).addClass('ui-state-disabled');
            dialog.find("#form-cancel-nfe select, #form-cancel-nfe textarea").prop('disabled', true);
        } else {
            dialog.find("#cancel-expired-msg").hide();
            cancelButton.prop('disabled', false).removeClass('ui-state-disabled');
            dialog.find("#form-cancel-nfe select, #form-cancel-nfe textarea").prop('disabled', false);
        }

        dialog.data('nfe_id', nfe_id).dialog("open");
    });

    $('#justificativa_cancelamento_select').on('change', function() {
        if ($(this).val() === '_OUTROS_') {
            $("#outros_justificativa_wrapper").slideDown();
        } else {
            $("#outros_justificativa_wrapper").slideUp();
        }
    });

    // --- Lógica para o Pop-up da CC-e (JAVASCRIPT CORRIGIDO COM TOKEN CSRF) ---
    var dialogCce = $("#dialog-cce-nfe").dialog({
        autoOpen: false, modal: true, width: 550,
        buttons: {
            "<?php echo dol_escape_js($langs->trans("Enviar Correção")); ?>": function() {
                var dialog = $(this);
                var button = dialog.closest('.ui-dialog').find('.ui-dialog-buttonpane button:first');

                var correcao = $("#cce_text").val().trim();
                var nfe_id = $(this).data('nfe_id'); // Pega o ID armazenado no diálogo

                if (correcao.length < 15) {
                    alert("<?php echo dol_escape_js($langs->trans("O texto de correção precisa de pelomenos 15 caracteres.")); ?>");
                    return;
                }
                
                button.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> <?php echo dol_escape_js($langs->trans("Processando...")); ?>');

                // Requisição AJAX
                $.ajax({
                    url: "<?php echo $_SERVER['PHP_SELF']; ?>",
                    type: "POST",
                    data: {
                        action: "submitcce",
                        id: nfe_id,
                        justificativa: correcao,
                        ajax: 1,
                        token: "<?php echo dol_escape_js($_SESSION['newtoken']); ?>"
                    },
                    dataType: "json",
                    success: function(response) {
                        console.log("Resposta do servidor:", response);
                        button.prop('disabled', false).html('<?php echo dol_escape_js($langs->trans("Enviar Correção")); ?>');
                        
                        if (response.success) {
                            $.jnotify(response.message, "ok", true);
                            dialog.dialog("close");
                            setTimeout(function() { location.reload(); }, 2000);
                        } else {
                            $.jnotify(response.message || "Erro desconhecido", "error", true);
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error("Erro AJAX:", status, error);
                        console.error("Resposta do servidor:", xhr.responseText);
                        button.prop('disabled', false).html('<?php echo dol_escape_js($langs->trans("Enviar Correção")); ?>');
                        
                        var errorMsg = "Erro de comunicação com o servidor";
                        try {
                            var resp = JSON.parse(xhr.responseText);
                            if (resp.message) errorMsg = resp.message;
                        } catch(e) {
                            if (xhr.responseText) {
                                errorMsg += ": " + xhr.responseText.substring(0, 200);
                            }
                        }
                        $.jnotify(errorMsg, "error", true);
                    }
                });
            },
            "<?php echo dol_escape_js($langs->trans("Close")); ?>": function() { $(this).dialog("close"); }
        },
        open: function() {
            // Limpa o campo de texto ao abrir
            $("#cce_text").val('');
        }
    });

    $(".corrigir-btn").on("click", function(e) {
        e.preventDefault();
        var nfe_id = $(this).data("id");
        var dataEmissao = $(this).data("emissao");
        var emissaoDate = new Date(dataEmissao.replace(/-/g, '/')); // Corrigir formato para compatibilidade
        var hoje = new Date();
        var diffTime = hoje.getTime() - emissaoDate.getTime();
        var diffHours = diffTime / (1000 * 60 * 60);

        var dialog = $("#dialog-cce-nfe");
        var cceButton = dialog.closest('.ui-dialog').find('.ui-dialog-buttonpane button:first');

        if (diffHours > 720) { // 30 dias
            dialog.find("#cce-expired-msg").text("O prazo para emissão de Carta de Correção (CC-e) expirou. A legislação permite a correção em até 30 dias (720 horas) após a autorização.").show();
            cceButton.prop('disabled', true).addClass('ui-state-disabled');
            dialog.find("#cce_text").prop('disabled', true);
        } else {
            dialog.find("#cce-expired-msg").hide();
            cceButton.prop('disabled', false).removeClass('ui-state-disabled');
            dialog.find("#cce_text").prop('disabled', false);
        }

        // Armazena o ID no próprio diálogo e o abre
        dialog.data('nfe_id', nfe_id).dialog("open");
    });
});
</script>
<script>
document.addEventListener("DOMContentLoaded", function() {
    document.querySelectorAll(".status-header-wrapper").forEach(function(wrapper) {
        var floating = null;
        var showTooltip = function() {
            var source = wrapper.querySelector(".status-tooltip");
            if (!source) return;
            // cria elemento flutuante
            floating = document.createElement("div");
            floating.className = "floating-status-tooltip";
            // copia conteúdo da tooltip (apenas innerHTML)
            floating.innerHTML = source.innerHTML;
            // substitui as classes internas de preview para as específicas do flutuante
            floating.querySelectorAll(".status-preview-circle").forEach(function(el){
                if (el.classList.contains("status-preview-green")) el.classList.add("floating-status-green");
                if (el.classList.contains("status-preview-blue")) el.classList.add("floating-status-blue");
                if (el.classList.contains("status-preview-red")) el.classList.add("floating-status-red");
                if (el.classList.contains("status-preview-yellow")) el.classList.add("floating-status-yellow");
                if (el.classList.contains("status-preview-denied")) el.classList.add("floating-status-denied");
                // garante que a bolinha tenha a classe genérica usada no CSS do flutuante
                el.classList.add("status-preview-circle");
            });
            document.body.appendChild(floating);
            // posiciona centralizado sobre o wrapper, ajusta se passar da tela
            var rect = wrapper.getBoundingClientRect();
            // calcula left/top após anexar (para obter offsetWidth/Height)
            var left = rect.left + (rect.width - floating.offsetWidth) / 2;
            var top = rect.top - floating.offsetHeight - 8;
            if (top < 8) top = rect.bottom + 8;
            if (left < 8) left = 8;
            if (left + floating.offsetWidth > window.innerWidth - 8) left = window.innerWidth - floating.offsetWidth - 8;
            floating.style.left = Math.round(left) + "px";
            floating.style.top = Math.round(top) + "px";
        };
        var hideTooltip = function() {
            if (floating) { floating.remove(); floating = null; }
        };
        wrapper.addEventListener("mouseenter", showTooltip);
        wrapper.addEventListener("mouseleave", hideTooltip);
        wrapper.addEventListener("focus", showTooltip);
        wrapper.addEventListener("blur", hideTooltip);
        // Em dispositivos touch: abre no toque e fecha no toque fora (melhora compatibilidade)
        wrapper.addEventListener("touchstart", function(e){
            e.stopPropagation();
            if (floating) { hideTooltip(); } else { showTooltip(); }
        });
        document.addEventListener("touchstart", function(e){
            if (floating && !wrapper.contains(e.target)) hideTooltip();
        });
    });
});
</script>
<style>
/* Tooltip flutuante (apendado ao body) */
.floating-status-tooltip {
    position: fixed;
    background: #222;
    color: #fff;
    padding: 10px;
    border-radius: 8px;
    max-width: 320px;
    box-shadow: 0 6px 18px rgba(0,0,0,0.35);
    font-size: 13px;
    z-index: 99999;
    text-align: left;
}
.floating-status-tooltip .tooltip-item { display:flex; align-items:center; gap:8px; margin:4px 0; }
.floating-status-tooltip .status-preview-circle { width:14px; height:14px; border-radius:50%; display:inline-block; flex-shrink:0; }
.floating-status-green{ background:#28a745; border:2px solid #218838; }
.floating-status-blue{ background:#007bff; border:2px solid #0069d9; }
.floating-status-red{ background:#dc3545; border:2px solid #c82333; }
.floating-status-yellow{ background:#ffc107; border:2px solid #e0a800; }
.floating-status-denied{ background:#343a40; border:2px solid #23272b; }
</style>
<style>
/* tooltip oculto por padrão */
.status-tooltip { display:none; position:absolute; bottom:125%; left:50%; transform:translateX(-50%); background:#222; color:#fff; padding:10px; border-radius:8px; width:240px; box-shadow:0 6px 18px rgba(0,0,0,0.3); font-size:13px; z-index:50; text-align:left; }
.status-tooltip::after { content:""; position:absolute; top:100%; left:50%; transform:translateX(-50%); border-width:6px; border-style:solid; border-color:#222 transparent transparent transparent; }

/* As bolinhas de PREVIEW são classes exclusivas: ocultas por padrão */
.status-preview-circle { display:none; width:16px; height:16px; border-radius:50%; box-shadow:0 0 4px rgba(0,0,0,0.2); margin-right:8px; flex-shrink:0; }

/* Tornar as preview-bolinhas visíveis apenas DENTRO do tooltip */
.status-tooltip .status-preview-circle { display:inline-block; }

/* Cores das preview-bolinhas (classes exclusivas) */
.status-preview-green { background:#28a745; border:2px solid #218838; }
.status-preview-blue { background:#007bff; border:2px solid #0069d9; }
.status-preview-red { background:#dc3545; border:2px solid #c82333; }
.status-preview-yellow { background:#ffc107; border:2px solid #e0a800; }
.status-preview-denied { background:#343a40; border:2px solid #23272b; }

/* lista de itens com rótulo - escopada ao tooltip */
.status-tooltip .status-tooltip-preview { display:flex; gap:8px; justify-content:center; margin-bottom:8px; }
.status-tooltip .status-tooltip-items { display:flex; flex-direction:column; gap:6px; }
.status-tooltip .tooltip-item { display:flex; align-items:center; gap:8px; color:#fff; }

/* mostra tooltip no hover ou focus do wrapper */
.status-header-wrapper:hover .status-tooltip,
.status-header-wrapper:focus .status-tooltip { display:block; }
</style>
<style>
/* Estilo para a mensagem de erro nos pop-ups */
.error-message {
    color: #D8000C; /* Vermelho forte */
    margin-top: 15px;
    font-weight: bold;
    text-align: center;
}

/* Centralizar conteúdo da tabela */
.liste th, .liste td {
    text-align: center; /* Centraliza o texto */
    vertical-align: middle; /* Centraliza verticalmente */
}

/* Ajustar botões para ficarem na vertical apenas em telas pequenas */
@media (max-width: 768px) {
    .actions-cell {
        display: flex;
        flex-direction: column; /* Organiza os botões na vertical */
        align-items: center; /* Centraliza os botões horizontalmente */
        gap: 10px; /* Espaçamento vertical entre os botões */
    }
}
</style>

<style>
/* Remover estilos customizados para o botão de busca */
.search-button {
    border: none; /* Remove borda customizada */
    cursor: pointer;
}
</style>

<style>
/* Estilo para o semáforo de status */
.status-circle {
    display: inline-block;
    width: 14px;
    height: 14px;
    border-radius: 50%;
    margin: 0 auto;
    box-shadow: 0 0 5px rgba(0, 0, 0, 0.2);
    vertical-align: middle;
}

.status-legend-icon {
    cursor: help;
    color: #6c757d;
    margin-left: 4px;
}

/* Verde para "Autorizada" */
.status-circle-green {
    background-color: #28a745;
    border: 2px solid #218838;
}

/* Azul para "Autorizada (Dev)" */
.status-circle-blue {
    background-color: #007bff;
    border: 2px solid #0069d9;
}

/* Vermelho para "Cancelada" */
.status-circle-red {
    background-color: #dc3545;
    border: 2px solid #c82333;
}

/* Amarelo para "Rejeitada" */
.status-circle-yellow {
    background-color: #ffc107;
    border: 2px solid #e0a800;
}

/* Preto para "Denegada" */
.status-circle-denied {
    background-color: #343a40;
    border: 2px solid #23272b;
}

/* Cinza para status desconhecido */
.status-circle-gray {
    background-color: #6c757d;
    border: 2px solid #5a6268;
}
</style>
<style>
    /* Estilos para a modal de inutilização */
.inutilizar-form-container {
    display: flex;
    flex-direction: column;
    gap: 18px; /* Espaçamento entre os grupos de formulário */
    padding: 20px;
}

.inutilizar-form-container .form-group,
.inutilizar-form_container .form-group-row {
    display: flex;
    flex-direction: column;
}

.inutilizar-form-container .form-group-row {
    flex-direction: row;
    gap: 15px;
}

.inutilizar-form-container .form-group {
    flex: 1; /* Faz com que os campos ocupem o espaço disponível igualmente */
}

.inutilizar-form-container label {
    margin-bottom: 6px;
    font-weight: 600;
    color: #444;
    font-size: 0.95em;
}

.inutilizar-form-container input[type="number"],
.inutilizar-form-container textarea {
    width: 100%;
    padding: 10px;
    border: 1px solid #ccc;
    border-radius: 4px;
    font-size: 1em;
    box-sizing: border-box;
    transition: border-color 0.2s, box-shadow 0.2s;
}

.inutilizar-form-container input[type="number"]:focus,
.inutilizar-form-container textarea:focus {
    border-color: #007bff;
    box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.25);
    outline: none;
}

.inutilizar-form-container textarea {
    resize: vertical;
    min-height: 80px;
}

.inutilizar-form-container .form-actions {
    text-align: right;
    margin-top: 15px;
}

.inutilizar-form-container #inutilizar-confirm-btn {
    background-color: #dc3545; /* Vermelho para ação destrutiva */
    border-color: #dc3545;
    color: white;
    font-weight: bold;
    padding: 10px 20px;
    font-size: 1em;
    border-radius: 5px;
}

.inutilizar-form-container #inutilizar-confirm-btn:hover {
    background-color: #c82333;
    border-color: #bd2130;
}

/* Estilo para o ícone de spinner no botão */
.inutilizar-form-container #inutilizar-confirm-btn .fa-spinner {
    margin-right: 8px;
}
</style>
<style>
/* Escopo exclusivo do módulo NFe - dropdown aprimorado (triângulo CSS) */
.nfe-dropdown { position: relative; display: inline-block; }
.nfe-dropdown-menu {
  display: none;
  position: absolute;
  top: calc(100% + 6px);
  left: 0;
  background-color: #fff;
  min-width: 180px;
  box-shadow: 0 8px 18px rgba(0,0,0,0.18);
  z-index: 1050;
  border-radius: 6px;
  padding: 6px 0;
  text-align: left;
  max-width: calc(100vw - 24px);
  overflow: hidden;
}

/* Itens: seta à esquerda usando bordas CSS, texto mais próximo da borda */
.nfe-dropdown-menu .nfe-dropdown-item {
  position: relative;
  padding: 8px 12px 8px 28px; /* menos espaço para aproximar texto */
  color: #333;
  font-size: .95em;
  cursor: pointer;
  display: block;
  line-height: 1.25em;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

/* seta triangular (mais confiável que unicode) */
.nfe-dropdown-menu .nfe-dropdown-item::before {
  content: "";
  position: absolute;
  left: 10px;
  top: 50%;
  transform: translateY(-50%);
  width: 0;
  height: 0;
  border-top: 5px solid transparent;
  border-bottom: 5px solid transparent;
  border-left: 7px solid #6c757d;
  transition: border-color 120ms ease, transform 120ms ease;
}

/* hover: cor de fundo e seta em branco, leve deslocamento */
.nfe-dropdown-menu .nfe-dropdown-item:hover {
    text-decoration: none;
  background: #0056b3;
  color: #fff;
}
.nfe-dropdown-menu .nfe-dropdown-item:hover::before {
  border-left-color: #fff;
  transform: translateY(-50%) translateX(2px);
}

/* estado disabled */
.nfe-dropdown-menu .nfe-dropdown-item.disabled {
  pointer-events: none;
  opacity: .6;
  color: #888;
}
.nfe-dropdown-menu .nfe-dropdown-item.disabled::before {
  border-left-color: #cfcfcf;
  opacity: .95;
}

/* regras responsivas: garantir que não quebre em telas pequenas */
@media (max-width:480px){
  .nfe-dropdown-menu { min-width: 140px; right: 0; left: auto; }
  .nfe-dropdown-menu .nfe-dropdown-item { padding-left: 28px; font-size: .9em; }
}
</style>

<script>
function toggleDropdown(event, menuId) {
    event.stopPropagation();
    const menu = document.getElementById(menuId);
    if (!menu) return;
    const isOpen = menu.style.display === 'block';

    // Fecha apenas menus do módulo
    document.querySelectorAll('.nfe-dropdown-menu').forEach(m => m.style.display = 'none');

    // Alterna atual
    menu.style.display = isOpen ? 'none' : 'block';
}

// Fecha somente menus NFe (não interfere no menu de usuário do Dolibarr)
document.addEventListener('click', function(e) {
    // Se clique fora de um container .nfe-dropdown, fecha
    if (!e.target.closest('.nfe-dropdown')) {
        document.querySelectorAll('.nfe-dropdown-menu').forEach(m => m.style.display = 'none');
    }
});
</script>