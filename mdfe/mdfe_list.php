<?php
// NOVO: silencia avisos/deprecations/notices na página (sem afetar logs)
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

/** @var DoliDB $db */
/** @var Translate $langs */
/** @var Conf $conf */

// Inclui bibliotecas MDF-e (necessárias para handlers AJAX de encerramento)
if (file_exists(__DIR__ . '/../composerlib/vendor/autoload.php')) {
    require_once __DIR__ . '/../composerlib/vendor/autoload.php';
}
if (file_exists(DOL_DOCUMENT_ROOT.'/custom/labapp/lib/ibge_utils.php')) {
    require_once DOL_DOCUMENT_ROOT.'/custom/labapp/lib/ibge_utils.php';
}
if (file_exists(DOL_DOCUMENT_ROOT.'/custom/mdfe/lib/certificate_security.lib.php')) {
    require_once DOL_DOCUMENT_ROOT.'/custom/mdfe/lib/certificate_security.lib.php';
}

if (!function_exists('mdfe_carregarCertificado')) {
    function mdfe_carregarCertificado($db) {
        $certPfx = null;
        $certPass = null;
        $tableKv = MAIN_DB_PREFIX . 'nfe_config';
        $res = @$db->query("SELECT name, value FROM `".$tableKv."`");
        if ($res) {
            while ($row = $db->fetch_object($res)) {
                if ($row->name === 'cert_pfx')  $certPfx  = $row->value;
                if ($row->name === 'cert_pass') $certPass = $row->value;
            }
        }
        if (is_resource($certPfx)) $certPfx = stream_get_contents($certPfx);
        if (empty($certPfx)) throw new Exception('Certificado PFX não encontrado.');
        $pass = decryptPassword((string)$certPass, $db);
        try {
            return \NFePHP\Common\Certificate::readPfx($certPfx, $pass);
        } catch (Exception $e) {
            $dec = base64_decode($certPfx, true);
            if ($dec !== false) return \NFePHP\Common\Certificate::readPfx($dec, (string)$certPass);
            throw new Exception('Erro ao ler certificado: ' . $e->getMessage());
        }
    }
}

// Handlers AJAX - devem ser processados antes de qualquer saída HTML (MDF-e)
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    // Handler AJAX: consultar MDF-e
    if (GETPOST('action', 'alpha') === 'consultar') {
        header('Content-Type: application/json; charset=UTF-8');
        $mdfeId = (int) GETPOST('id', 'int');
        if ($mdfeId > 0) {
            $sql = "SELECT * FROM ".MAIN_DB_PREFIX."mdfe_emitidas WHERE id = ".$mdfeId;
            $res = $db->query($sql);
            if ($res && $db->num_rows($res) > 0) {
                $row = $db->fetch_object($res);
                echo json_encode(['success' => true, 'data' => $row], JSON_UNESCAPED_UNICODE);
            } else {
                echo json_encode(['success' => false, 'error' => 'MDF-e não encontrada']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'ID inválido']);
        }
        exit;
    }

    // Handler AJAX: cancelar MDF-e
    if (GETPOST('action', 'alpha') === 'cancelar_mdfe') {
        header('Content-Type: application/json; charset=UTF-8');
        $mdfeId  = (int) GETPOST('id', 'int');
        $xJust   = trim(GETPOST('xJust', 'restricthtml'));
        if ($mdfeId <= 0) { echo json_encode(['success'=>false,'error'=>'ID inválido'], JSON_UNESCAPED_UNICODE); exit; }
        if (mb_strlen($xJust) < 15) { echo json_encode(['success'=>false,'error'=>'Justificativa deve ter pelo menos 15 caracteres.'], JSON_UNESCAPED_UNICODE); exit; }
        try {
            $sqlMdfe = "SELECT * FROM ".MAIN_DB_PREFIX."mdfe_emitidas WHERE id = ".$mdfeId;
            $resMdfe = $db->query($sqlMdfe);
            if (!$resMdfe || $db->num_rows($resMdfe) === 0) throw new Exception('MDF-e não encontrada.');
            $mdfe = $db->fetch_object($resMdfe);
            $statusAtual = strtolower((string)$mdfe->status);
            if ($statusAtual === 'encerrada') throw new Exception('MDF-e encerrada não pode ser cancelada.');
            if ($statusAtual === 'cancelada') throw new Exception('MDF-e já está cancelada.');
            if ($statusAtual !== 'autorizada') throw new Exception('Somente MDF-e autorizada pode ser cancelada (status atual: '.$statusAtual.').');
            // Verifica janela de 24 horas
            if (!empty($mdfe->data_emissao)) {
                $diffH = (time() - strtotime($mdfe->data_emissao)) / 3600;
                if ($diffH > 24) throw new Exception('O prazo para cancelamento (24 horas após a emissão) já foi ultrapassado.');
            }
            if (empty($mdfe->chave_acesso)) throw new Exception('Chave de acesso não encontrada.');
            if (empty($mdfe->protocolo))   throw new Exception('Protocolo de autorização não encontrado.');
            // Ambiente
            $sqlAmb = "SELECT value FROM ".MAIN_DB_PREFIX."nfe_config WHERE name = 'ambiente'";
            $resAmb = $db->query($sqlAmb);
            $ambienteVal = 2;
            if ($resAmb && $db->num_rows($resAmb) > 0) $ambienteVal = (int)$db->fetch_object($resAmb)->value;
            global $mysoc;
            if (empty($mysoc->id)) $mysoc->fetch(0);
            $cnpjEmitente = preg_replace('/\D/', '', $mysoc->idprof1 ?? '');
            $configMdfe = [
                'atualizacao' => date('Y-m-d H:i:s'),
                'tpAmb'       => $ambienteVal,
                'razaosocial' => $mysoc->name ?? '',
                'cnpj'        => $cnpjEmitente,
                'ie'          => preg_replace('/\D/', '', $mysoc->idprof3 ?? ''),
                'siglaUF'     => $mysoc->state_code ?? 'ES',
                'versao'      => '3.00'
            ];
            $cert  = mdfe_carregarCertificado($db);
            $tools = new \NFePHP\MDFe\Tools(json_encode($configMdfe), $cert);
            $resp  = $tools->sefazCancela($mdfe->chave_acesso, $xJust, $mdfe->protocolo);
            $xmlRequisicao = $tools->lastRequest ?? '';
            $st   = new \NFePHP\MDFe\Common\Standardize();
            $std  = $st->toStd($resp);
            $cStat       = (int)($std->infEvento->cStat      ?? 0);
            $xMotivo     = $std->infEvento->xMotivo          ?? 'Resposta inválida da SEFAZ';
            $nProtEvt    = $std->infEvento->nProt            ?? '';
            $dhRegEvento = $std->infEvento->dhRegEvento      ?? date('Y-m-d\TH:i:sP');
            $tpEvento    = $std->infEvento->tpEvento         ?? '110111';
            $nSeqEvento  = (int)($std->infEvento->nSeqEvento ?? 1);
            // cStat 135 = evento registrado; 155 = cancelamento homologado
            if ($cStat !== 135 && $cStat !== 155) {
                throw new Exception("SEFAZ recusou o cancelamento (cStat={$cStat}): {$xMotivo}");
            }
            $dtEvento = date('Y-m-d H:i:s', strtotime($dhRegEvento));
            $db->query("INSERT INTO ".MAIN_DB_PREFIX."mdfe_eventos
                (fk_mdfe_emitida, tpEvento, nSeqEvento, protocolo_evento, motivo_evento, data_evento, xml_requisicao, xml_resposta, xml_evento_completo)
                VALUES (
                    ".$mdfeId.",
                    '".$db->escape($tpEvento)."',
                    ".$nSeqEvento.",
                    '".$db->escape($nProtEvt)."',
                    '".$db->escape($xMotivo)."',
                    '".$db->escape($dtEvento)."',
                    '".$db->escape($xmlRequisicao)."',
                    '".$db->escape($resp)."',
                    '".$db->escape($resp)."'
                )");
            $db->query("UPDATE ".MAIN_DB_PREFIX."mdfe_emitidas SET
                status = 'cancelada',
                codigo_status = ".$cStat.",
                motivo = '".$db->escape($xMotivo)."',
                data_cancelamento = '".$db->escape($dtEvento)."',
                protocolo_cancelamento = '".$db->escape($nProtEvt)."',
                motivo_cancelamento = '".$db->escape($xJust)."',
                atualizado_em = NOW()
                WHERE id = ".$mdfeId);
            setEventMessage('MDF-e cancelada com sucesso!', 'mesgs');
            echo json_encode(['success'=>true,'protocolo'=>$nProtEvt,'xMotivo'=>$xMotivo,'cStat'=>$cStat], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            error_log('[MDF-e Cancelamento] '.$e->getMessage());
            echo json_encode(['success'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // Handler AJAX: carregar configurações (setup.php)
    if (GETPOST('action', 'alpha') === 'carregar_configuracoes') {
        if (!$user->admin) {
            header('Content-Type: text/html; charset=UTF-8');
            echo '<div class="nfse-alert nfse-alert-error">Acesso negado. Apenas administradores podem acessar as configurações.</div>';
            exit;
        }
        
        header('Content-Type: text/html; charset=UTF-8');
        
        // Carrega dados do emitente
        global $mysoc;
        if (empty($mysoc->id)) {
            $mysoc->fetch(0);
        }
        $cnpj_raw = $mysoc->idprof1 ?? '';
        $im_raw   = $mysoc->idprof3 ?? '';
        $cnpj = preg_replace('/\D/', '', $cnpj_raw);
        $im   = preg_replace('/\D/', '', $im_raw);
        $display_cnpj = $cnpj !== '' ? $cnpj : '';
        $display_im   = $im !== '' ? $im : '';

        // Carrega sequências MDF-e para AMBOS os ambientes
        $ultimo_numero_prod = 0;
        $serie_config_prod = '1';
        $ultimo_numero_homol = 0;
        $serie_config_homol = '1';
        
        if ($cnpj !== '') {
            $cnpjE = $db->escape($cnpj);
            
            // Produção (ambiente = 1)
            $sqlProd = "SELECT ultimo_numero, serie FROM ".MAIN_DB_PREFIX."mdfe_sequencias 
                       WHERE cnpj = '".$cnpjE."' AND ambiente = 1
                       ORDER BY updated_at DESC, id DESC LIMIT 1";
            $resProd = $db->query($sqlProd);
            if ($resProd && $db->num_rows($resProd) > 0) {
                $rowProd = $db->fetch_object($resProd);
                $ultimo_numero_prod = (int)$rowProd->ultimo_numero;
                $serie_config_prod = $rowProd->serie ?: '1';
            }
            
            // Homologação (ambiente = 2)
            $sqlHomol = "SELECT ultimo_numero, serie FROM ".MAIN_DB_PREFIX."mdfe_sequencias 
                       WHERE cnpj = '".$cnpjE."' AND ambiente = 2
                       ORDER BY updated_at DESC, id DESC LIMIT 1";
            $resHomol = $db->query($sqlHomol);
            if ($resHomol && $db->num_rows($resHomol) > 0) {
                $rowHomol = $db->fetch_object($resHomol);
                $ultimo_numero_homol = (int)$rowHomol->ultimo_numero;
                $serie_config_homol = $rowHomol->serie ?: '1';
            }
        }
        
        // Renderiza o formulário com as duas seções (Produção em cima, Homologação embaixo)
        echo '<div class="nfse-setup">
        <form id="formSequenciasNacional" method="post" class="nfse-setup-form">
            <input type="hidden" name="action" value="salvar_sequencias_nacional">
            <input type="hidden" name="token" value="'.newToken().'">
            
            <!-- Produção -->
            <div class="nfse-card" style="margin-bottom: 16px;">
                <div class="nfse-card-header">
                    <span class="icon" aria-hidden="true">
                    </span>
                    <span>Produção</span>
                </div>
                <div class="nfse-card-body">
                    <div class="nfse-data-grid-2">
                        <div class="nfse-data-item">
                            <div class="nfse-data-label">Último Número Emitido</div>
                            <div class="nfse-data-value">
                                <input type="number" name="ULTIMO_NUMERO_PROD" class="flat" value="'.$ultimo_numero_prod.'" min="0">
                            </div>
                        </div>
                        <div class="nfse-data-item">
                            <div class="nfse-data-label">Série</div>
                            <div class="nfse-data-value">
                                <input type="number" name="SERIE_MDFE_PROD" class="flat" value="'.dol_escape_htmltag($serie_config_prod).'" min="1" max="999">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Homologação -->
            <div class="nfse-card" style="margin-bottom: 16px;">
                <div class="nfse-card-header">
                    <span class="icon" aria-hidden="true">
                    </span>
                    <span>Homologação</span>
                </div>
                <div class="nfse-card-body">
                    <div class="nfse-data-grid-2">
                        <div class="nfse-data-item">
                            <div class="nfse-data-label">Último Número Emitido</div>
                            <div class="nfse-data-value">
                                <input type="number" name="ULTIMO_NUMERO_HOMOL" class="flat" value="'.$ultimo_numero_homol.'" min="0">
                            </div>
                        </div>
                        <div class="nfse-data-item">
                            <div class="nfse-data-label">Série</div>
                            <div class="nfse-data-value">
                                <input type="number" name="SERIE_MDFE_HOMOL" class="flat" value="'.dol_escape_htmltag($serie_config_homol).'" min="1" max="999">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>

        <div class="actions">
            <button type="button" class="butActionDelete" onclick="closeNfseModal()">Cancelar</button>
            <button type="button" class="butAction" onclick="salvarSequenciasNacional()">Salvar Configurações</button>
        </div>
    </div>';
    exit;
    }

    // Handler AJAX: encerrar MDF-e
    if (GETPOST('action', 'alpha') === 'encerrar_mdfe') {
        header('Content-Type: application/json; charset=UTF-8');
        $mdfeId = (int) GETPOST('id', 'int');
        if ($mdfeId <= 0) {
            echo json_encode(['success' => false, 'error' => 'ID inválido'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            // 1. Carregar dados da MDF-e
            $sqlMdfe = "SELECT * FROM ".MAIN_DB_PREFIX."mdfe_emitidas WHERE id = ".$mdfeId;
            $resMdfe = $db->query($sqlMdfe);
            if (!$resMdfe || $db->num_rows($resMdfe) === 0) {
                throw new Exception('MDF-e não encontrada no banco de dados.');
            }
            $mdfe = $db->fetch_object($resMdfe);
            if (strtolower((string)$mdfe->status) !== 'autorizada') {
                throw new Exception('Somente MDF-e com status "Autorizada" pode ser encerrada.');
            }
            if (empty($mdfe->chave_acesso)) {
                throw new Exception('Chave de acesso não encontrada para esta MDF-e.');
            }
            if (empty($mdfe->protocolo)) {
                throw new Exception('Protocolo de autorização não encontrado para esta MDF-e.');
            }

            // 2. Ambiente
            $sqlAmb = "SELECT value FROM ".MAIN_DB_PREFIX."nfe_config WHERE name = 'ambiente'";
            $resAmb = $db->query($sqlAmb);
            $ambienteVal = 2;
            if ($resAmb && $db->num_rows($resAmb) > 0) {
                $ambienteVal = (int)$db->fetch_object($resAmb)->value;
            }

            // 3. Dados do emitente
            global $mysoc;
            if (empty($mysoc->id)) $mysoc->fetch(0);
            $cnpjEmitente = preg_replace('/\D/', '', $mysoc->idprof1 ?? '');

            // 4. Mapa de UF → código numérico IBGE
            $ufMap = [
                'AC'=>'12','AL'=>'27','AP'=>'16','AM'=>'13','BA'=>'29','CE'=>'23','DF'=>'53',
                'ES'=>'32','GO'=>'52','MA'=>'21','MT'=>'51','MS'=>'50','MG'=>'31','PA'=>'15',
                'PB'=>'25','PR'=>'41','PE'=>'26','PI'=>'22','RJ'=>'33','RN'=>'24','RS'=>'43',
                'RO'=>'11','RR'=>'14','SC'=>'42','SP'=>'35','SE'=>'28','TO'=>'17'
            ];
            $ufIni = strtoupper(trim((string)$mdfe->uf_ini));
            $cUF = $ufMap[$ufIni] ?? ($ufMap[strtoupper($mysoc->state_code ?? '')] ?? '32');

            // 5. Código IBGE do município de carregamento
            $cMun = null;
            if (!empty($mdfe->mun_carrega) && function_exists('buscarDadosIbge')) {
                $ibgeMun = buscarDadosIbge($db, $mdfe->mun_carrega, $ufIni);
                if ($ibgeMun && !empty($ibgeMun->codigo_ibge)) {
                    $cMun = $ibgeMun->codigo_ibge;
                }
            }
            // Fallback: cidade do emitente
            if (empty($cMun) && function_exists('buscarDadosIbge')) {
                $ibgeFallback = buscarDadosIbge($db, $mysoc->town ?? '', $mysoc->state_code ?? '');
                if ($ibgeFallback && !empty($ibgeFallback->codigo_ibge)) {
                    $cMun = $ibgeFallback->codigo_ibge;
                }
            }
            if (empty($cMun)) {
                throw new Exception('Não foi possível determinar o código IBGE do município de encerramento. Verifique o campo "Município de Carregamento" da MDF-e.');
            }

            // 6. Inicializar Tools
            $configMdfe = [
                "atualizacao" => date('Y-m-d H:i:s'),
                "tpAmb"       => $ambienteVal,
                "razaosocial" => $mysoc->name ?? '',
                "cnpj"        => $cnpjEmitente,
                "ie"          => preg_replace('/\D/', '', $mysoc->idprof3 ?? ''),
                "siglaUF"     => $mysoc->state_code ?? 'ES',
                "versao"      => '3.00'
            ];
            $cert  = mdfe_carregarCertificado($db);
            $tools = new \NFePHP\MDFe\Tools(json_encode($configMdfe), $cert);

            // 7. Enviar encerramento à SEFAZ
            $resp = $tools->sefazEncerra(
                $mdfe->chave_acesso,
                $mdfe->protocolo,
                $cUF,
                $cMun
            );
            // Captura o XML de requisição enviado à SEFAZ (disponível após a chamada)
            $xmlRequisicao = $tools->lastRequest ?? '';

            $st  = new \NFePHP\MDFe\Common\Standardize();
            $std = $st->toStd($resp);

            $cStat      = (int)($std->infEvento->cStat      ?? 0);
            $xMotivo    = $std->infEvento->xMotivo          ?? 'Resposta inválida da SEFAZ';
            $nProtEvt   = $std->infEvento->nProt            ?? '';
            $dhRegEvento= $std->infEvento->dhRegEvento      ?? date('Y-m-d\TH:i:sP');
            $tpEvento   = $std->infEvento->tpEvento         ?? '110112';
            $nSeqEvento = (int)($std->infEvento->nSeqEvento ?? 1);

            // Aceita cStat 135 (sucesso) e também 573 (já encerrado - idempotente)
            if ($cStat !== 135 && $cStat !== 573) {
                throw new Exception("SEFAZ recusou o encerramento (cStat={$cStat}): {$xMotivo}");
            }

            // 8. Registrar evento
            $dtEvento = date('Y-m-d H:i:s', strtotime($dhRegEvento));
            $sqlIns = "INSERT INTO ".MAIN_DB_PREFIX."mdfe_eventos 
                (fk_mdfe_emitida, tpEvento, nSeqEvento, protocolo_evento, motivo_evento, data_evento, xml_requisicao, xml_resposta, xml_evento_completo)
                VALUES (
                    ".$mdfeId.",
                    '".$db->escape($tpEvento)."',
                    ".$nSeqEvento.",
                    '".$db->escape($nProtEvt)."',
                    '".$db->escape($xMotivo)."',
                    '".$db->escape($dtEvento)."',
                    '".$db->escape($xmlRequisicao)."',
                    '".$db->escape($resp)."',
                    '".$db->escape($resp)."'
                )";
            $db->query($sqlIns);

            // 9. Atualizar mdfe_emitidas
            $sqlUpd = "UPDATE ".MAIN_DB_PREFIX."mdfe_emitidas SET 
                status = 'encerrada',
                codigo_status = ".$cStat.",
                motivo = '".$db->escape($xMotivo)."',
                data_encerramento = '".$db->escape($dtEvento)."',
                protocolo_encerramento = '".$db->escape($nProtEvt)."',
                atualizado_em = NOW()
                WHERE id = ".$mdfeId;
            $db->query($sqlUpd);

            // 10. Flash message Dolibarr para exibir após reload
            setEventMessage('MDF-e encerrada com sucesso!', 'mesgs');

            echo json_encode([
                'success'   => true,
                'protocolo' => $nProtEvt,
                'xMotivo'   => $xMotivo,
                'cStat'     => $cStat
            ], JSON_UNESCAPED_UNICODE);

        } catch (Exception $e) {
            error_log('[MDF-e Encerramento] ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // Handler AJAX: carregar dados completos para modal de encerramento (parseia XML)
    if (GETPOST('action', 'alpha') === 'carregar_dados_encerramento') {
        header('Content-Type: application/json; charset=UTF-8');
        $mdfeId = (int) GETPOST('id', 'int');
        if ($mdfeId <= 0) {
            echo json_encode(['success' => false, 'error' => 'ID inválido'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        try {
            $sql = "SELECT * FROM ".MAIN_DB_PREFIX."mdfe_emitidas WHERE id = ".$mdfeId;
            $res = $db->query($sql);
            if (!$res || $db->num_rows($res) === 0) {
                throw new Exception('MDF-e não encontrada.');
            }
            $mdfe = $db->fetch_object($res);
            if (strtolower((string)$mdfe->status) !== 'autorizada') {
                throw new Exception('Somente MDF-e com status "Autorizada" pode ser encerrada.');
            }
            $modalLabelsAjax = [1 => 'Rodoviário', 2 => 'Aéreo', 3 => 'Aquaviário', 4 => 'Ferroviário'];
            $dados = [
                'id' => (int)$mdfe->id,
                'numero' => $mdfe->numero,
                'serie' => $mdfe->serie,
                'chave_acesso' => $mdfe->chave_acesso,
                'protocolo' => $mdfe->protocolo,
                'status' => $mdfe->status,
                'data_emissao' => !empty($mdfe->data_emissao) ? date('d/m/Y H:i', strtotime($mdfe->data_emissao)) : '-',
                'uf_ini' => $mdfe->uf_ini ?? '',
                'uf_fim' => $mdfe->uf_fim ?? '',
                'modal' => $modalLabelsAjax[(int)($mdfe->modal)] ?? (string)$mdfe->modal,
                'placa' => $mdfe->placa ?? '',
                'valor_carga' => !empty($mdfe->valor_carga) ? 'R$ '.number_format((float)$mdfe->valor_carga, 2, ',', '.') : '-',
                'peso_carga' => $mdfe->peso_carga ?? '',
                'qtd_cte' => $mdfe->qtd_cte ?? '0',
                'qtd_nfe' => $mdfe->qtd_nfe ?? '0',
                'ambiente' => (int)($mdfe->ambiente ?? 2) === 1 ? 'Produção' : 'Homologação',
                'mun_carrega' => $mdfe->mun_carrega ?? '',
                'mun_descarga' => $mdfe->mun_descarga ?? '',
            ];
            // Parse XML para dados adicionais
            $xmlStr = '';
            if (!empty($mdfe->xml_mdfe)) {
                $xmlStr = is_resource($mdfe->xml_mdfe) ? stream_get_contents($mdfe->xml_mdfe) : (string)$mdfe->xml_mdfe;
            }
            if (!empty($xmlStr)) {
                $xmlClean = preg_replace('/xmlns[^=]*="[^"]*"/', '', $xmlStr);
                $xml = @simplexml_load_string($xmlClean);
                if ($xml) {
                    $infMDFe = $xml->infMDFe ?? $xml;
                    $ide = $infMDFe->ide ?? null;
                    $emit = $infMDFe->emit ?? null;
                    $infModal = $infMDFe->infModal ?? null;
                    $tot = $infMDFe->tot ?? null;
                    $prodPred = $infMDFe->prodPred ?? null;
                    $infDoc = $infMDFe->infDoc ?? null;
                    if ($emit) {
                        $dados['emitente'] = (string)($emit->xNome ?? '');
                        $dados['cnpj'] = (string)($emit->CNPJ ?? '');
                    }
                    if ($ide) {
                        if (!empty((string)($ide->UFIni ?? ''))) $dados['uf_ini'] = (string)$ide->UFIni;
                        if (!empty((string)($ide->UFFim ?? ''))) $dados['uf_fim'] = (string)$ide->UFFim;
                        if (!empty((string)($ide->infMunCarrega->xMunCarrega ?? ''))) $dados['mun_carrega'] = (string)$ide->infMunCarrega->xMunCarrega;
                        if (!empty((string)($ide->infMunCarrega->cMunCarrega ?? ''))) $dados['cod_mun_carrega'] = (string)$ide->infMunCarrega->cMunCarrega;
                    }
                    if ($infModal && $infModal->rodo && $infModal->rodo->veicTracao) {
                        $vt = $infModal->rodo->veicTracao;
                        if (!empty((string)($vt->placa ?? ''))) $dados['placa'] = (string)$vt->placa;
                        if ($vt->condutor) {
                            $dados['condutor'] = (string)($vt->condutor->xNome ?? '');
                            $dados['condutor_cpf'] = (string)($vt->condutor->CPF ?? '');
                        }
                        if ($infModal->rodo->infANTT) {
                            $dados['rntrc'] = (string)($infModal->rodo->infANTT->RNTRC ?? '');
                        }
                    }
                    if ($prodPred) {
                        $dados['produto'] = (string)($prodPred->xProd ?? '');
                        $tpCarga = (string)($prodPred->tpCarga ?? '');
                        $tpCargaMap = ['01'=>'Granel sólido','02'=>'Granel líquido','03'=>'Frigorificada','04'=>'Conteinerizada','05'=>'Carga Geral','06'=>'Neogranel','07'=>'Perigosa (granel sólido)','08'=>'Perigosa (granel líquido)','09'=>'Perigosa (frigorificada)','10'=>'Perigosa (conteinerizada)','11'=>'Perigosa (carga geral)'];
                        $dados['tipo_carga'] = $tpCargaMap[$tpCarga] ?? $tpCarga;
                    }
                    if ($tot) {
                        if (!empty((string)($tot->vCarga ?? ''))) $dados['valor_carga'] = 'R$ '.number_format((float)(string)$tot->vCarga, 2, ',', '.');
                        $dados['peso_carga'] = (string)($tot->qCarga ?? '');
                        $cUnid = (string)($tot->cUnid ?? '');
                        $dados['unid_peso'] = ($cUnid === '01') ? 'KG' : (($cUnid === '02') ? 'TON' : $cUnid);
                        $dados['qtd_cte'] = (string)($tot->qCTe ?? '0');
                        $dados['qtd_nfe'] = (string)($tot->qNFe ?? '0');
                    }
                    if ($infDoc && $infDoc->infMunDescarga) {
                        if (!empty((string)($infDoc->infMunDescarga->xMunDescarga ?? ''))) $dados['mun_descarga'] = (string)$infDoc->infMunDescarga->xMunDescarga;
                        if (!empty((string)($infDoc->infMunDescarga->cMunDescarga ?? ''))) $dados['cod_mun_descarga'] = (string)$infDoc->infMunDescarga->cMunDescarga;
                    }
                }
            }
            echo json_encode(['success' => true, 'dados' => $dados], JSON_UNESCAPED_UNICODE);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
        }
        exit;
    }

    // Handler AJAX: salvar sequências MDF-e
    if (GETPOST('action', 'alpha') === 'salvar_sequencias_nacional') {
        if (!$user->admin) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(['success' => false, 'error' => 'Acesso negado']);
            exit;
        }
        
        header('Content-Type: application/json; charset=UTF-8');
        
        try {
            global $mysoc;
            if (empty($mysoc->id)) {
                $mysoc->fetch(0);
            }
            $cnpj_raw = $mysoc->idprof1 ?? '';
            $cnpj = preg_replace('/\D/', '', $cnpj_raw);

            if (empty($cnpj)) {
                throw new Exception('CNPJ do emitente não configurado');
            }
            
            // Valores do formulário
            $ultimo_numero_prod = (int) GETPOST('ULTIMO_NUMERO_PROD', 'int');
            $serie_mdfe_prod = (int)(GETPOST('SERIE_MDFE_PROD', 'int') ?: 1);
            $ultimo_numero_homol = (int) GETPOST('ULTIMO_NUMERO_HOMOL', 'int');
            $serie_mdfe_homol = (int)(GETPOST('SERIE_MDFE_HOMOL', 'int') ?: 1);
            
            $cnpjE = $db->escape($cnpj);
            
            // Salvar Produção (ambiente = 1)
            $sqlProd = "INSERT INTO ".MAIN_DB_PREFIX."mdfe_sequencias 
                         (cnpj, serie, ambiente, ultimo_numero, updated_at)
                         VALUES ('".$cnpjE."', ".intval($serie_mdfe_prod).", 1, ".intval($ultimo_numero_prod).", NOW())
                         ON DUPLICATE KEY UPDATE 
                         serie = ".intval($serie_mdfe_prod).",
                         ultimo_numero = ".intval($ultimo_numero_prod).",
                         updated_at = NOW()";
            if (!$db->query($sqlProd)) {
                throw new Exception('Erro ao salvar sequência de Produção: ' . $db->lasterror());
            }
            
            // Salvar Homologação (ambiente = 2)
            $sqlHomol = "INSERT INTO ".MAIN_DB_PREFIX."mdfe_sequencias 
                         (cnpj, serie, ambiente, ultimo_numero, updated_at)
                         VALUES ('".$cnpjE."', ".intval($serie_mdfe_homol).", 2, ".intval($ultimo_numero_homol).", NOW())
                         ON DUPLICATE KEY UPDATE 
                         serie = ".intval($serie_mdfe_homol).",
                         ultimo_numero = ".intval($ultimo_numero_homol).",
                         updated_at = NOW()";
            if (!$db->query($sqlHomol)) {
                throw new Exception('Erro ao salvar sequência de Homologação: ' . $db->lasterror());
            }
            
            echo json_encode(['success' => true, 'message' => 'Dados salvos com sucesso!']);
            
        } catch (Exception $e) {
            error_log('[MDF-e] Erro ao salvar sequências: ' . $e->getMessage());
            echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        }
        
        exit;
    }
}

// Inicia a página normal apenas se não for uma requisição AJAX
llxHeader('', 'MDF-e');

// Verifica se há mensagem flash para exibir
if (!empty($_SESSION['nfse_flash_message'])) {
    $flash = $_SESSION['nfse_flash_message'];
    setEventMessage($flash['message'], $flash['type']);
    unset($_SESSION['nfse_flash_message']);
}

// pegar ambiente
$sql = "SELECT value FROM ".MAIN_DB_PREFIX. "nfe_config WHERE name = 'ambiente';";
$resSql = $db->query($sql);
if($resSql && $db->num_rows($resSql) > 0){
   $res = $db->fetch_object($resSql);
   $ambiente = ($res->value == 1) ? 'Produção' : 'Homologação';   
}

print load_fiche_titre('Manifesto Eletrônico de Documentos Fiscais (MDF-e) ('.$ambiente.')');

// Adiciona legenda fixa para cores de status
print '<div class="nfse-status-legend" style="margin-bottom: 15px; padding: 10px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; display: flex; align-items: center; gap: 15px;">';
print '<strong>Legenda de Status:</strong>';
print '<div style="display: flex; align-items: center; gap: 8px;"><span class="status-circle status-circle-green"></span> <span>Autorizada/Em aberto</span></div>';
print '<div style="display: flex; align-items: center; gap: 8px;"><span class="status-circle status-circle-black"></span> <span>Encerrada</span></div>';
print '<div style="display: flex; align-items: center; gap: 8px;"><span class="status-circle status-circle-red"></span> <span>Cancelada</span></div>';
print '<div style="display: flex; align-items: center; gap: 8px;"><span class="status-circle status-circle-yellow"></span> <span>Rejeitada</span></div>';
// Move o botão para o final da linha usando margin-left:auto dentro do container flex
print '<a href="'.dol_buildpath('/custom/mdfe/mdfe_form.php', 1).'" class="butAction" style="margin-left: auto;">';
print '<i class="fa fa-plus" style="margin-right: 6px;" aria-hidden="true"></i>Emitir Nova MDF-e';
print '</a>';
print '</div>';

// Parâmetros de paginação e ordenação
$page = max(0, (int) GETPOST('page', 'int'));
$sortfield = GETPOST('sortfield', 'alpha') ?: 'id';
$sortorder = GETPOST('sortorder', 'alpha') ?: 'DESC';

// Limite configurável por página com persistência
$defaultLimit = ($conf->liste_limit > 0) ? (int) $conf->liste_limit : 25;
$limit = (int) GETPOST('limit', 'int');
if ($limit <= 0) { $limit = $defaultLimit; }
$limitOptions = [25, 50, 100, 200];
$offset = $limit * $page;

// Filtros MDF-e
$search_status = GETPOST('search_status', 'alpha');
$search_status_list = GETPOST('search_status_list', 'alpha');
$search_numero_start = GETPOST('search_numero_start', 'alpha');
$search_numero_end = GETPOST('search_numero_end', 'alpha');
$search_chave = GETPOST('search_chave', 'alpha');
$search_uf_ini = GETPOST('search_uf_ini', 'alpha');
$search_uf_fim = GETPOST('search_uf_fim', 'alpha');
$search_placa = GETPOST('search_placa', 'alpha');
$search_data_emissao_start = GETPOST('search_data_emissao_start', 'alpha');
$search_data_emissao_end = GETPOST('search_data_emissao_end', 'alpha');

// Monta cláusulas WHERE para reutilizar na query principal e na contagem
$whereClauses = [];
$allowedStatuses = array('autorizada','rejeitada','cancelada','encerrada','pendente','erro');
$selectedStatuses = array();
if (!empty($search_status_list)) {
    foreach (explode(',', (string)$search_status_list) as $st) {
        $stl = strtolower(trim($st));
        if ($stl !== '' && in_array($stl, $allowedStatuses, true)) $selectedStatuses[] = $stl;
    }
    $selectedStatuses = array_values(array_unique($selectedStatuses));
}
if (!empty($selectedStatuses)) {
    $vals = array_map(function($v) use ($db){ return "'".$db->escape($v)."'"; }, $selectedStatuses);
    $whereClauses[] = "(LOWER(m.status) IN (".implode(',', $vals)."))";
} elseif (!empty($search_status)) {
    $esc = $db->escape(strtolower(trim($search_status)));
    $whereClauses[] = "(LOWER(m.status) LIKE '%".$esc."%')";
}

// Filtro por número
if ($search_numero_start !== '') {
    $s = trim((string)$search_numero_start);
    if ($s !== '' && is_numeric($s)) {
        $whereClauses[] = "m.numero >= ".(int)$s;
    }
}
if ($search_numero_end !== '') {
    $s2 = trim((string)$search_numero_end);
    if ($s2 !== '' && is_numeric($s2)) {
        $whereClauses[] = "m.numero <= ".(int)$s2;
    }
}

// Filtro por chave de acesso
if (!empty($search_chave)) {
    $esc = $db->escape(trim($search_chave));
    $whereClauses[] = "m.chave_acesso LIKE '%".$esc."%'";
}

// Filtro por UF de início
if (!empty($search_uf_ini)) {
    $esc = $db->escape(strtoupper(trim($search_uf_ini)));
    $whereClauses[] = "m.uf_ini = '".$esc."'";
}

// Filtro por UF de fim
if (!empty($search_uf_fim)) {
    $esc = $db->escape(strtoupper(trim($search_uf_fim)));
    $whereClauses[] = "m.uf_fim = '".$esc."'";
}

// Filtro por placa
if (!empty($search_placa)) {
    $esc = $db->escape(strtoupper(trim($search_placa)));
    $whereClauses[] = "UPPER(m.placa) LIKE '%".$esc."%'";
}

// Filtro por data de emissão
if (!empty($search_data_emissao_start)) {
    $whereClauses[] = "m.data_emissao >= '".$db->escape($search_data_emissao_start)." 00:00:00'";
}
if (!empty($search_data_emissao_end)) {
    $whereClauses[] = "m.data_emissao <= '".$db->escape($search_data_emissao_end)." 23:59:59'";
}

$whereSQL = empty($whereClauses) ? '' : ' AND ' . implode(' AND ', $whereClauses);

// Contagem total COM filtros aplicados (MDF-e)
$sql_count = "SELECT COUNT(*) as total 
              FROM ".MAIN_DB_PREFIX."mdfe_emitidas m
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

// Consulta principal - MDF-e
$sql = "SELECT 
            m.id,
            m.numero,
            m.serie,
            m.chave_acesso,
            m.protocolo,
            m.cnpj_emitente,
            m.data_emissao,
            m.data_recebimento,
            m.status,
            m.codigo_status,
            m.motivo,
            m.uf_ini,
            m.uf_fim,
            m.mun_carrega,
            m.mun_descarga,
            m.modal,
            m.placa,
            m.valor_carga,
            m.peso_carga,
            m.qtd_cte,
            m.qtd_nfe,
            m.ambiente,
            m.data_encerramento,
            COALESCE((SELECT COUNT(*) FROM ".MAIN_DB_PREFIX."mdfe_inclusao_nfe inc WHERE inc.fk_mdfe_emitida = m.id), 0) as qtd_nfe_incluidas
        FROM ".MAIN_DB_PREFIX."mdfe_emitidas m
        WHERE 1=1" . $whereSQL;

// Ordenação segura (MDF-e)
$allowedSort = array('id','numero','serie','data_emissao','status','uf_ini','uf_fim','chave_acesso','placa','protocolo','modal','valor_carga');
$sortcol = in_array($sortfield, $allowedSort) ? $sortfield : 'id';
$sql .= " ORDER BY m.".$sortcol." ".($sortorder === 'ASC' ? 'ASC' : 'DESC');

// Paginação (aplica limite calculado)
$sql .= $db->plimit($limit, $offset);

$res = $db->query($sql);
if (!$res) { dol_print_error($db); llxFooter(); exit; }
$num = $db->num_rows($res);

// CSS (cores, bolinhas, linhas por status, responsividade, dropdown)
print '<style>
/* Semáforo */
.status-circle{display:inline-block;width:14px;height:14px;border-radius:50%;margin:0 auto;box-shadow:0 0 5px rgba(0,0,0,.2);vertical-align:middle;}
.status-circle-green{background:#28a745;border:2px solid #218838;}
.status-circle-black{background:#343a40;border:2px solid #23272b;}
.status-circle-red{background:#dc3545;border:2px solid #c82333;}
.status-circle-yellow{background:#ffc107;border:2px solid #e0a800;}
.status-circle-gray{background:#6c757d;border:2px solid #5a6268;}
.status-circle-denied{background:#343a40;border:2px solid #23272b;}
/* Linhas por status */
.liste tr.row-status-autorizada td{background-color:rgba(73,182,76,.18) !important;}
.liste tr.row-status-cancelada td{background-color:rgba(220,53,69,.11) !important;}
.liste tr.row-status-rejeitada td{background-color:rgba(255,193,7,.12) !important;}
.liste tr.row-status-denegada td{background-color:rgba(52,58,64,.12) !important;}
.liste tr.row-status-encerrada td{background-color: rgba(120,128,142,0.15) !important;} 
.liste tr:hover td{background-color:rgba(0,0,0,.02) !important;}
/* Tabela e responsivo */
.liste{font-size:1em;border-collapse:collapse;width:100%;}
.liste th,.liste td{padding:8px;text-align:center;vertical-align:middle;border:1px solid #ddd;}
.liste th{background:#f4f4f4;font-weight:bold;}
@media (max-width:768px){
 .liste{font-size:.85em;}
 .liste th,.liste td{padding:6px;}
 .actions-cell{display:flex;flex-direction:column;align-items:center;gap:10px;}
}
/* Dropdown */
.nfe-dropdown{position:relative;display:inline-block;}
.nfe-dropdown-menu{display:none;position:absolute;top:calc(100% + 5px);left:0;background:#fff;min-width:160px;box-shadow:0 8px 16px rgba(0,0,0,.2);z-index:1050;border-radius:4px;padding:4px 0;text-align:start;}
.nfe-dropdown-menu .nfe-dropdown-item{padding:8px 14px;text-decoration:none;display:block;color:#333;font-size:.95em;cursor:pointer;line-height:1.2em;}
.nfe-dropdown-menu .nfe-dropdown-item:hover{background:#070973ff;color:#fff;}
.nfe-dropdown-menu .nfe-dropdown-item.disabled{pointer-events:none;opacity:.55;color:#888;cursor:not-allowed;}
.nfe-dropdown-menu .nfe-dropdown-item.disabled-visual{opacity:.6;color:#999;}

/* ===== Modal de Consulta (Novo Visual) ===== */
.nfse-modal-cancelamento {
    max-width: 680px !important; width: 90% !important; /* Modal de cancelamento mantida */
}
.nfse-modal-configuracoes {
    max-width: 680px !important;
    width: 90% !important;
}
/* Cards e Grid de Dados */
.nfse-card {
    background: #fff;
    border: 1px solid #e9ecef;
    border-radius: 8px;
    margin-bottom: 16px;
}
.nfse-card-header {
    padding: 10px 15px;
    background: #f8f9fa;
    border-bottom: 1px solid #e9ecef;
    font-weight: bold;
    color: #495057;
    border-radius: 8px 8px 0 0;
}
.nfse-card-body {
    padding: 15px;
    display: grid;
    gap: 12px 16px;
}
.nfse-data-grid-1 { grid-template-columns: 1fr; }
.nfse-data-grid-2 { grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); }
.nfse-data-grid-3 { grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
.nfse-data-grid-4 { grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }

.nfse-card-parties {
    grid-template-columns: 1fr 1fr;
}
.party-column {
    display: flex;
    flex-direction: column;
    gap: 12px;
}
.party-title {
    font-size: 1.05em;
    color: #343a40;
    border-bottom: 2px solid #007bff;
    padding-bottom: 6px;
    margin-bottom: 4px;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}
@media (max-width: 600px) {
    .nfse-card-parties { grid-template-columns: 1fr; }
}

.nfse-data-item {
    display: flex;
    flex-direction: column;
    gap: 2px;
}
.nfse-data-label {
    font-size: 0.8em;
    color: #6c757d;
    font-weight: bold;
    text-transform: uppercase;
}
.nfse-data-value {
    font-size: 1em;
    color: #212529;
    word-break: break-word;
}
.nfse-data-item.full-width { grid-column: 1 / -1; }

/* Alertas e XML */
.nfse-alert { padding: 12px 15px; border-radius: 6px; margin-bottom: 16px; border: 1px solid transparent; }
.nfse-alert-warn { background-color: #fff3cd; color: #856404; border-color: #ffeeba; }
.nfse-alert-error { background-color: #f8d7da; color: #721c24; border-color: #f5c6cb; }
.nfse-alert-success { background-color: #d4edda; color: #155724; border-color: #c3e6cb; }
.nfse-xml-details summary { cursor: pointer; font-weight: bold; color: #007bff; margin-top: 16px; }
.nfse-xml-details pre {
    white-space: pre-wrap; font-family: monospace; font-size: 12px;
    background: #2b3035; color: #c0c5ce;
    border: 1px solid #3e444c; border-radius: 6px;
    padding: 12px; max-height: 350px; overflow: auto; margin-top: 8px;
}

/* JS para modal e dropdown */
.nfse-spinner {
    display: inline-block;
    width: 40px;
    height: 40px;
    border: 4px solid rgba(0, 0, 0, 0.1);
    border-radius: 50%;
    border-top-color: #3498db;
    animation: spin 1s ease-in-out infinite;
    margin: 20px auto;
    text-align: center;
}
@keyframes spin {
    to { transform: rotate(360deg); }
}
@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}
.nfse-loading-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px 30px;
    min-height: 200px;
}
.nfse-loading-container p {
    margin-top: 20px;
    font-size: 15px;
    color: #666;
    font-weight: 500;
}
.nfse-success-container {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 40px 30px;
    text-align: center;
}
.nfse-success-icon {
    width: 80px;
    height: 80px;
    border-radius: 50%;
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    display: flex;
    align-items: center;
    justify-content: center;
    margin-bottom: 20px;
    animation: successPulse 0.6s ease-out;
}
.nfse-success-icon svg {
    width: 45px;
    height: 45px;
    fill: white;
    animation: successCheck 0.8s ease-out 0.2s both;
}
.nfse-success-message {
    font-size: 18px;
    font-weight: 600;
    color: #28a745;
    margin-bottom: 10px;
}
.nfse-success-submessage {
    font-size: 14px;
    color: #666;
}
@keyframes successPulse {
    0% { transform: scale(0); opacity: 0; }
    50% { transform: scale(1.1); }
    100% { transform: scale(1); opacity: 1; }
}
@keyframes successCheck {
    0% { transform: scale(0) rotate(-45deg); opacity: 0; }
    100% { transform: scale(1) rotate(0deg); opacity: 1; }
}

/* ===== Modal de Confirmação - Usando Classes Dolibarr ===== */
.nfse-confirm-overlay {
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0, 0, 0, 0.3);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 200000;
}
.nfse-confirm-overlay.visible {
    display: flex;
}

/* Melhoria do dropdown de motivo */
.nfse-data-item select.flat {
    padding: 10px 14px; /* Aumenta o padding interno */
    border: 1px solid #ccc;
    border-radius: 6px; /* Bordas mais arredondadas */
    background: #f9f9f9; /* Fundo mais claro */
    font-size: 15px; /* Fonte maior */
    width: 100%;
    color: #333;
    max-width: 100%;
    box-sizing: border-box;
    transition: border-color 0.3s, box-shadow 0.3s; /* Transição suave */
}
.nfse-data-item select.flat:focus {
    border-color: #007bff;
    outline: none;
    box-shadow: 0 0 0 3px rgba(0,123,255,0.25); /* Destaque ao focar */
}
.nfse-data-item textarea.flat {
    padding: 10px 14px; /* Aumenta o padding interno */
    border: 1px solid #ccc;
    border-radius: 6px; /* Bordas mais arredondadas */
    font-size: 15px; /* Fonte maior */
    resize: vertical;
    width: 100%;
    max-width: 100%;
    box-sizing: border-box;
    font-family: inherit;
    background: #f9f9f9; /* Fundo mais claro */
    transition: border-color 0.3s, box-shadow 0.3s; /* Transição suave */
    
}
.nfse-data-item textarea.flat:focus {
    border-color: #007bff;
    outline: none;
    box-shadow: 0 0 0 3px rgba(0,123,255,0.25); /* Destaque ao focar */
}
.nfse-data-item {
    margin-bottom: 20px; /* Espaçamento entre os itens */
}
.nfse-data-item.full-width {
    grid-column: 1 / -1; /* Garante que ocupa toda a largura */
}

/* Tag de Status na Modal - Visual Melhorado */
.nfse-status-tag {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 0.75em;
    font-weight: 500;
    color: #fff;
    margin-right: 10px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.15);
    border: 1px solid transparent;
    opacity: 0.85;
}
.nfse-status-tag.autorizada {
    background: linear-gradient(135deg, #6dc77a, #5cb85c);
    border-color: #4cae4c;
    color: #fff;
}
.nfse-status-tag.autorizada::after {
    content: "✓";
    font-size: 1em;
    font-weight: bold;
}
.nfse-status-tag.cancelada {
    background: linear-gradient(135deg, #d9534f, #c9302c);
    border-color: #ac2925;
    color: #fff;
}
.nfse-status-tag.cancelada::after {

    font-size: 1em;
    font-weight: bold;
}
.nfse-status-tag.rejeitada {
    background: linear-gradient(135deg, #f0ad4e, #ec971f);
    color: #8a6d3b;
    border-color: #d58512;
}
.nfse-status-tag.rejeitada::after {
    content: "⚠";
    font-size: 1em;
    font-weight: bold;
}
.nfse-status-tag.processando {
    background: linear-gradient(135deg, #777, #666);
    border-color: #555;
    color: #fff;
}
.nfse-status-tag.processando::after {
    content: "⏳";
    font-size: 0.9em;
}

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

/* Normalize altura e paddings dos botões de ação para ficarem iguais */
.butAction, input.butAction, button.butAction {
    display: inline-block;
    font-size: 0.95em;
    line-height: 1.4;   /* garante altura visual consistente */
    height: auto;
    box-sizing: border-box;
    vertical-align: middle;
}

/* Específico para o botão de busca (input) */
input.search-button {
    font-size: 0.95em;
    line-height: 1.4;
    vertical-align: middle;
}

/* Pequeno ajuste caso algum estilo antigo force altura diferente */
input[type="submit"].butAction, button.butAction {
    min-height: 36px; /* garante mínimo visual coerente */
    height: auto;
}

/* ===== Fim da Normalização ===== */

/* Atualizar estilos para campos obrigatórios com erro */

.nfse-field-error {
    border: 2px solid #dc3545 !important;
    
}
.nfse-error-message {
    color: #dc3545;
    font-size: 0.85em; /* Reduz o tamanho da mensagem */
    margin-top: 2px; /* Reduz o espaçamento */
    display: block;
}

/* Ícone de engrenagem (SVG + acessibilidade) - nova paleta teal */
.nfse-config-wrap { display:inline-flex; align-items:center; margin-left:6px; }
.nfse-config-btn{
    background: transparent;
    border: 0;
    padding: 6px;
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    vertical-align: middle;
    transition: opacity .18s ease;
}
.nfse-config-btn:hover{
    opacity: 0.7;
}
.nfse-config-btn:focus{
    outline: none;
    box-shadow: 0 0 0 4px rgba(26,188,156,0.12);
}
.nfse-config-icon {
    display:inline-block;
    width:20px; height:20px;
}
.nfse-config-icon path { fill: #2c7a7b; transition: fill .18s ease; }
.nfse-config-btn:hover .nfse-config-icon path { fill: #1abc9c; }

/* Percurso (UF ini → UF fim) */
.mdfe-percurso {
    white-space: nowrap;
    font-size: 0.85em;
    letter-spacing: .02em;
    cursor: default;
}

/* Modal de Configurações - paleta teal */
.nfse-modal-configuracoes{ max-width: 880px !important; width: 96% !important; }
.nfse-modal.nfse-modal-configuracoes .nfse-modal-header{
    background: linear-gradient(135deg,#16a085,#1abc9c);
    color: #fff; border-bottom: none;
}
.nfse-modal.nfse-modal-configuracoes .nfse-modal-header strong{ color: #fff; }
.nfse-modal.nfse-modal-configuracoes .nfse-modal-body{
    background: #f6fffb;
}

/* Layout do conteúdo de setup dentro da modal (teal) */
.nfse-setup .nfse-card{
    border: 1px solid #e5f2ef; border-radius: 10px; overflow: hidden;
    box-shadow: 0 6px 16px rgba(0,0,0,0.06); background: #fff;
}
.nfse-setup .nfse-card-header{
    display:flex; align-items:center; gap:8px;
    background: #eafaf7; border-bottom: 1px solid #d8eee9; color:#2f4f4f;
    font-weight: 700;
}
.nfse-setup .nfse-card-header .icon svg{ width:22px; height:22px; fill:#16a085; }
.nfse-setup .nfse-card-body{ padding: 16px; background: #fff; }
.nfse-setup .nfse-data-grid-2{
    display:grid; grid-template-columns: repeat(auto-fit, minmax(240px,1fr)); gap:14px 18px;
}
.nfse-setup .nfse-data-item .nfse-data-label{
    font-size:.85em; color:#64748b; font-weight:600; text-transform:none; margin-bottom:4px;
}
.nfse-setup input.flat{
    width:100%; padding:10px 12px; border:1px solid #cfe6e2; border-radius:6px; background:#fff; box-sizing:border-box;
    transition: border-color .2s, box-shadow .2s;
}
.nfse-setup input.flat:focus{
    border-color:#1abc9c; box-shadow:0 0 0 3px rgba(26,188,156,.18); outline:none;
}
.nfse-setup .actions{ text-align:center; margin-top:16px; display:flex; gap:10px; justify-content:center; flex-wrap:wrap; }

/* NOVO: Popover compacto para filtro de status */
.status-filter-cell{ position: relative; }
.nfse-status-filter-toggle{
    padding: 6px 12px;         /* aumentado para melhor clique */
    border: 1px solid #cfe6e2;
    background: #fff;
    color: #2c7a7b;
    border-radius: 16px;
    cursor: pointer;
    font-size: .88em;          /* ligeiramente maior */
    line-height: 1.3;
    display: inline-flex;
    align-items: center;
    gap: 6px;                  /* aumentado */
}
.nfse-status-filter-toggle:hover{ background:#f0fbf8; border-color:#1abc9c; }
.nfse-status-filter-toggle .badge{
    display: inline-block;
    min-width: 18px;
    padding: 2px 6px;
    border-radius: 10px;
    background: #1abc9c;
    color: #fff;
    font-size: .78em;
}

/* Popover com melhor espaçamento interno */
.nfse-status-filter-popover{
    position: absolute !important;
    top: calc(100% + 6px);
    left: 0;
    right: auto;
    transform: none;
    z-index: 9999;
    background: #fff;
    border: 1px solid #e5f2ef;
    box-shadow: 0 8px 20px rgba(0,0,0,.12);
    border-radius: 8px;
    padding: 10px 12px;        /* aumentado */
    width: max-content;
    max-width: 360px;
    box-sizing: border-box;
    display: none;
}
.nfse-status-filter-popover.visible{ display:block; }

/* Opções com melhor espaçamento entre linhas */
.nfse-status-filter-popover .opt{
    display: flex;
    align-items: center;
    gap: 8px;                  /* aumentado */
    padding: 6px 8px;          /* aumentado */
    border-radius: 6px;
    cursor: pointer;
    transition: background .12s ease;
    line-height: 1.3;
}
.nfse-status-filter-popover .opt + .opt{ margin-top: 4px; } /* aumentado */
.nfse-status-filter-popover .opt:hover{ background: rgba(22,160,133,0.06); }

/* Checkbox e chip mantidos compactos */
.nfse-status-filter-popover .opt input[type="checkbox"]{
    width: 14px;
    height: 14px;
    accent-color: #16a085;
    border-radius: 3px;
    margin: 0 2px 0 0;
    flex: 0 0 auto;
}
.nfse-status-filter-popover .opt .nfse-chip{
    width: 8px;
    height: 8px;
    border-radius: 50%;
    box-shadow: inset 0 -1px 0 rgba(0,0,0,0.05);
    flex: 0 0 auto;
}
.nfse-status-filter-popover .opt input[type="checkbox"]:checked + .nfse-chip{
    box-shadow: none !important;
}

/* Foco acessível */
.nfse-status-filter-popover .opt:focus-within{
    outline: 2px solid rgba(26,188,156,0.2);
    outline-offset: 2px;
}

/* Botões da área de ações com melhor espaçamento */
.nfse-status-filter-popover .actions{
    display: flex;
    gap: 8px;                  /* aumentado */
    justify-content: flex-end;
    margin-top: 10px;          /* aumentado */
    padding-top: 8px;          /* adiciona separação visual */
    border-top: 1px solid #f0f0f0; /* linha sutil de separação */
}
.nfse-status-filter-popover .actions .butAction,
.nfse-status-filter-popover .actions .butActionDelete{
    padding: 5px 10px;         /* aumentado */
    font-size: .86em;
    line-height: 1.3;
}

/* Realce leve quando marcado */
@supports selector(label:has(input:checked)){
    .nfse-status-filter-popover .opt:has(input:checked){
        background: rgba(22,160,133,0.08);
        outline: 1px solid #bfece0;
    }
}

/* Novo estilo para botão de configurações: apenas ícone, sem fundo */
.nfse-config-container {
    display: inline-flex;
    align-items: center;
    margin-left: 8px;
    vertical-align: middle; /* Melhor alinhamento vertical */
}
.nfse-config-button {
    background: transparent;
    border: none;
    padding: 6px; /* Aumentado para melhor clique */
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    vertical-align: middle;
    transition: opacity 0.2s ease;
}
.nfse-config-button:hover {
    opacity: 0.7;
}
.nfse-config-button:focus {
    outline: none;
    box-shadow: 0 0 0 2px rgba(0, 123, 255, 0.25);
}
.nfse-config-fa-icon {
    font-size: 24px; /* Aumentado de 18px para 24px */
    color: #6c757d;
}
.nfse-config-button:hover .nfse-config-fa-icon {
    color: #495057;
}

/* ...existing styles... */

/* Estilos da Modal Principal */
.nfse-modal-overlay {
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0, 0, 0, 0.5);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 200000;
}
.nfse-modal {
    background: white;
    border-radius: 8px;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.3);
    max-width: 680px;
    width: 90%;
    max-height: 90vh;
    overflow: hidden;
    display: flex;
    flex-direction: column;
}
.nfse-modal-header {
    background: linear-gradient(135deg, #007bff, #0056b3);
    color: white;
    padding: 15px 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    border-bottom: 1px solid #dee2e6;
}
.nfse-modal-header strong {
    font-size: 1.2em;
    margin: 0;
}
.nfse-modal-close {
    background: transparent;
    border: none;
    color: white;
    font-size: 28px;
    line-height: 1;
    cursor: pointer;
    padding: 0;
    width: 30px;
    height: 30px;
    display: flex;
    align-items: center;
    justify-content: center;
    border-radius: 4px;
    transition: background 0.2s;
}
.nfse-modal-close:hover {
    background: rgba(255, 255, 255, 0.2);
}
.nfse-modal-body {
    padding: 20px;
    overflow-y: auto;
    flex: 1;
}
</style>';

// Cabeçalho tabela e filtros
print '<div class="table-responsive-wrapper">';
// NOVO: adiciona id ao form para facilitar submit via JS
print '<form method="GET" id="nfseListFilterForm" action="'.$_SERVER["PHP_SELF"].'">';
print '<table class="liste noborder centpercent">';
print '<tr class="liste_titre">';

// SUBSTITUI o título simples por HTML com tooltip e bolinhas
$status_header_html = '
<div class="status-header-wrapper" tabindex="0" aria-label="'.dol_escape_htmltag($langs->trans("Legenda de status")).'">
  <span class="status-title">'.$langs->trans("Status").'</span>
  
  </div>
</div>';

print_liste_field_titre($status_header_html, $_SERVER["PHP_SELF"], "status", "", "", 'align="center"', $sortfield, $sortorder);
print_liste_field_titre('Nº MDF-e', $_SERVER["PHP_SELF"], "numero", "", "", 'align="center"', $sortfield, $sortorder);
print_liste_field_titre('Modal', $_SERVER["PHP_SELF"], "modal", "", "", 'align="center"', $sortfield, $sortorder);
print_liste_field_titre('Placa', $_SERVER["PHP_SELF"], "placa", "", "", 'align="center"', $sortfield, $sortorder);
print_liste_field_titre('CT-e', $_SERVER["PHP_SELF"], "", "", "", 'align="center"', $sortfield, $sortorder);
print_liste_field_titre('NF-e', $_SERVER["PHP_SELF"], "", "", "", 'align="center"', $sortfield, $sortorder);
print_liste_field_titre('Emissão', $_SERVER["PHP_SELF"], "data_emissao", "", "", 'align="center"', $sortfield, $sortorder);
print_liste_field_titre('Percurso', $_SERVER["PHP_SELF"], "", "", "", 'align="center"', $sortfield, $sortorder);
print_liste_field_titre('Ações', '', '', '', '', 'align="center"');
print "</tr>";

// Linha de filtros
print '<tr class="liste_titre_filter">';

// NOVO: filtro Status com botão e popover
// prepara seleção atual para marcar checkboxes e badge
$selectedStatusesPhp = !empty($selectedStatuses) ? $selectedStatuses : array();
$selectedStatusListStr = dol_escape_htmltag(implode(',', $selectedStatusesPhp));
$badgeCount = count($selectedStatusesPhp);
print '<td class="center status-filter-cell">';
print '<input type="hidden" name="search_status_list" id="search_status_list" value="'.$selectedStatusListStr.'">';
print '<button type="button" class="nfse-status-filter-toggle" onclick="toggleStatusFilter(event)" aria-expanded="false" title="Filtrar Status">Filtrar'.($badgeCount? ' <span id="statusSelCount" class="badge">'.$badgeCount.'</span>':'').'</button>';
print '<div class="nfse-status-filter-popover" id="statusFilterPopover">';
// opções MDF-e
$opts = array(
    'autorizada' => 'Autorizada',
    'rejeitada' => 'Rejeitada',
    'cancelada' => 'Cancelada',
    'encerrada' => 'Encerrada',
);
foreach ($opts as $key => $label) {
    $checked = in_array($key, $selectedStatusesPhp, true) ? ' checked' : '';
    print '<label class="opt"><input type="checkbox" value="'.$key.'"'.$checked.'> <span class="nfse-chip '.$key.'"></span> '.$label.'</label>';
}
print '<div class="actions">';

print '<button type="button" class="butAction" onclick="applyStatusFilter()">Aplicar</button>';
print '<button type="button" class="butActionDelete" onclick="clearStatusFilter()">Resetar</button>';
print '</div></div>';
print '</td>';

// Filtros MDF-e
print '<td class="center">';
print '<input type="text" name="search_numero_start" value="'.dol_escape_htmltag($search_numero_start).'" class="flat" size="5" placeholder="De"> - ';
print '<input type="text" name="search_numero_end" value="'.dol_escape_htmltag($search_numero_end).'" class="flat" size="5" placeholder="Até">';
print '</td>';
print '<td class="center"></td>'; // Modal (sem filtro)
print '<td class="center"><input type="text" name="search_placa" value="'.dol_escape_htmltag($search_placa).'" class="flat" size="8" placeholder="Placa"></td>';
print '<td class="center"></td>'; // CT-e (sem filtro)
print '<td class="center"></td>'; // NF-e (sem filtro)
print '<td class="center">';
print '<input type="date" name="search_data_emissao_start" value="'.dol_escape_htmltag($search_data_emissao_start).'" class="flat"> - ';
print '<input type="date" name="search_data_emissao_end" value="'.dol_escape_htmltag($search_data_emissao_end).'" class="flat">';
print '</td>';
print '<td class="center"></td>'; // Percurso (sem filtro)
print '<td class="center">';
print '<input type="submit" class="butAction search-button" value="'.$langs->trans("Search").'"> ';
// Botão de download em lote
$batchUrl = dol_buildpath('/custom/mdfe/mdfe_download.php', 1).'?action=batch';
$batchUrl .= '&search_status_list='.urlencode($search_status_list);
$batchUrl .= '&search_numero_start='.urlencode($search_numero_start);
$batchUrl .= '&search_numero_end='.urlencode($search_numero_end);
$batchUrl .= '&search_chave='.urlencode($search_chave);
$batchUrl .= '&search_uf_ini='.urlencode($search_uf_ini);
$batchUrl .= '&search_uf_fim='.urlencode($search_uf_fim);
$batchUrl .= '&search_placa='.urlencode($search_placa);
$batchUrl .= '&search_data_emissao_start='.urlencode($search_data_emissao_start);
$batchUrl .= '&search_data_emissao_end='.urlencode($search_data_emissao_end);
print '<a href="'.$batchUrl.'" class="butAction" target="_blank"><i class="fa fa-download" style="margin-right:5px;"></i>'.$langs->trans("Baixar em Lote").'</a> ';
// Botão de configurações (admin apenas)
if ($user->admin) {
    print '<div class="nfse-config-container">';
    print '  <button type="button" id="nfseConfigBtn" class="nfse-config-button" aria-label="Configurações MDF-e" title="Configurações MDF-e" onclick="openNfseConfiguracoes()">';
    print '    <i class="fa fa-cog nfse-config-fa-icon" aria-hidden="true"></i>';
    print '  </button>';
    print '</div>';
}
print '</td>';
print '</tr>';

// Linhas de dados MDF-e
if($num > 0){
    $i = 0;
    while ($i < min($num, $limit)) {
        $obj = $db->fetch_object($res);

        // Determina classe e bolinha de status
        $status = strtolower((string)$obj->status);
        $statusClass = '';
        $statusCircle = '<span class="status-circle status-circle-gray" title="'.dol_escape_htmltag($status).'"></span>';

        if ($status === 'autorizada') {
            $statusClass = 'row-status-autorizada';
            $statusCircle = '<span class="status-circle status-circle-green" title="AUTORIZADA"></span>';
        } elseif ($status === 'cancelada') {
            $statusClass = 'row-status-cancelada';
            $statusCircle = '<span class="status-circle status-circle-red" title="CANCELADA"></span>';
        } elseif ($status === 'encerrada') {
            $statusClass = 'row-status-encerrada';
            $statusCircle = '<span class="status-circle status-circle-black" title="ENCERRADA"></span>';
        } elseif ($status === 'rejeitada' || $status === 'erro') {
            $statusClass = 'row-status-rejeitada';
            $statusCircle = '<span class="status-circle status-circle-yellow" title="'.strtoupper($status).'"></span>';
        } elseif ($status === 'pendente') {
            $statusCircle = '<span class="status-circle status-circle-yellow" title="PENDENTE"></span>';
        }

        // Data de emissão
        $ts = !empty($obj->data_emissao) ? strtotime($obj->data_emissao) : 0;

        // Dados para exibição
        $numero = $obj->numero ? dol_escape_htmltag($obj->numero) : '-';
        $serie = $obj->serie ? dol_escape_htmltag($obj->serie) : '-';
        $chave = $obj->chave_acesso ? dol_escape_htmltag($obj->chave_acesso) : '-';
        // Exibe chave truncada com title completo
        $chaveDisplay = (strlen($obj->chave_acesso) > 20) ? '<span title="'.$chave.'">'.substr($chave, 0, 10).'...'.substr($chave, -10).'</span>' : $chave;
        $protocolo = !empty($obj->protocolo) ? dol_escape_htmltag($obj->protocolo) : '-';
        $ufIni = !empty($obj->uf_ini) ? dol_escape_htmltag($obj->uf_ini) : '-';
        $ufFim = !empty($obj->uf_fim) ? dol_escape_htmltag($obj->uf_fim) : '-';
        // Modal: 1=Rodoviário, 2=Aéreo, 3=Aquaviário, 4=Ferroviário
        $modalLabels = [1 => 'Rodoviário', 2 => 'Aéreo', 3 => 'Aquaviário', 4 => 'Ferroviário'];
        $modalDisplay = isset($modalLabels[(int)$obj->modal]) ? $modalLabels[(int)$obj->modal] : '-';
        $placa = !empty($obj->placa) ? dol_escape_htmltag($obj->placa) : '-';
        $valorCarga = !empty($obj->valor_carga) ? 'R$ '.number_format((float)$obj->valor_carga, 2, ',', '.') : '-';

        // Percurso: UF ini → UF fim; tooltip mostra cidades
        $ufIniE = dol_escape_htmltag($obj->uf_ini ?? '');
        $ufFimE = dol_escape_htmltag($obj->uf_fim ?? '');
        $munCarregaE   = dol_escape_htmltag($obj->mun_carrega  ?? '');
        $munDescargaE  = dol_escape_htmltag($obj->mun_descarga ?? '');
        $percursoUFs   = ($ufIniE && $ufFimE) ? $ufIniE.' → '.$ufFimE : ($ufIniE ?: ($ufFimE ?: '-'));
        // Tooltip: mostra cidades quando disponíveis
        $tooltipParts = [];
        if ($munCarregaE)  $tooltipParts[] = $munCarregaE;
        if ($munDescargaE) $tooltipParts[] = $munDescargaE;
        $percursoTooltip = implode(' → ', $tooltipParts);
        $percursoHtml = $percursoTooltip
            ? '<span class="mdfe-percurso" title="'.dol_escape_htmltag($percursoTooltip).'">'.dol_escape_htmltag($percursoUFs).'</span>'
            : '<span class="mdfe-percurso">'.dol_escape_htmltag($percursoUFs).'</span>';

        // Qtd documentos
        $qtdCteDisplay = ($obj->qtd_cte !== null && $obj->qtd_cte !== '') ? (int)$obj->qtd_cte : '-';
        $qtdNfeDisplay = ($obj->qtd_nfe !== null && $obj->qtd_nfe !== '') ? (int)$obj->qtd_nfe : '-';

        print '<tr class="oddeven '.$statusClass.'" data-id="'.(int)$obj->id.'">';
        print '<td class="center">'.$statusCircle.'</td>';
        print '<td class="center">'.$numero.'</td>';
        print '<td class="center" style="font-size:0.85em;">'.$modalDisplay.'</td>';
        print '<td class="center">'.$placa.'</td>';
        print '<td class="center" style="font-size:0.85em;">'.$qtdCteDisplay.'</td>';
        print '<td class="center" style="font-size:0.85em;">'.$qtdNfeDisplay.'</td>';
        // Data de emissão direto do campo data_emissao (evita conversão de fuso de dol_print_date)
        $dataEmissaoDisplay = !empty($obj->data_emissao)
            ? date('d/m/Y H:i', strtotime($obj->data_emissao))
            : '-';
        print '<td class="center" style="white-space:nowrap;">'.$dataEmissaoDisplay.'</td>';
        print '<td class="center">'.$percursoHtml.'</td>';

        // Dropdown de ações (ações só para status válidos; rejeitada não permite operações)
        print '<td class="center actions-cell"><div class="nfe-dropdown">';
        print '<button class="butAction dropdown-toggle" type="button" onclick="toggleDropdown(event, \'mdfeDropdownMenu'.$obj->id.'\')">'.$langs->trans("Ações").'</button>';
        print '<div class="nfe-dropdown-menu" id="mdfeDropdownMenu'.$obj->id.'">';
        if (in_array($status, ['autorizada', 'encerrada', 'cancelada'])) {
            print '<a class="nfe-dropdown-item" href="#" onclick="openConsultarModal('.(int)$obj->id.'); return false;">Consultar</a>';
        }
        // Download XML: disponível para qualquer MDF-e que tenha XML salvo
        $downloadUrl = dol_buildpath('/custom/mdfe/mdfe_download.php', 1).'?action=individual&id='.(int)$obj->id;
        print '<a class="nfe-dropdown-item" href="'.dol_escape_htmltag($downloadUrl).'" target="_blank">Download XML</a>';
        //print '<a class="nfe-dropdown-item" href="'.dol_escape_htmltag($downloadUrl).'" target="_blank"><i class="fa fa-download" style="margin-right:5px;"></i>Download XML</a>';

        // DAMDFE: Visualizar PDF inline em nova aba
        $damdfeViewUrl = dol_buildpath('/custom/mdfe/mdfe_damdfe.php', 1).'?action=view&id='.(int)$obj->id;
        print '<a class="nfe-dropdown-item" href="'.dol_escape_htmltag($damdfeViewUrl).'" target="_blank">Visualizar DAMDFE</a>';

        // DAMDFE: Download PDF (força download e salva no banco)
        $damdfeDownloadUrl = dol_buildpath('/custom/mdfe/mdfe_damdfe.php', 1).'?action=download&id='.(int)$obj->id;
        print '<a class="nfe-dropdown-item" href="'.dol_escape_htmltag($damdfeDownloadUrl).'" target="_blank">Baixar DAMDFE(PDF)</a>';

        if ($status === 'autorizada') {
            // Verificar se já possui documentos (CT-e ou NF-e) originais ou incluídos via evento
            $totalDocsOriginais = ((int)($obj->qtd_cte ?? 0)) + ((int)($obj->qtd_nfe ?? 0));
            $totalDocsIncluidos = (int)($obj->qtd_nfe_incluidas ?? 0);
            $totalDocs = $totalDocsOriginais + $totalDocsIncluidos;
            
            if ($totalDocs > 0) {
                print '<span class="nfe-dropdown-item disabled" title="MDF-e já possui '.($totalDocs).' documento(s) vinculado(s). Não é possível incluir mais.">Incluir NF-e</span>';
            } else {
                print '<a class="nfe-dropdown-item" href="#" onclick="openIncluirDfeModal('.(int)$obj->id.'); return false;">Incluir NF-e</a>';
            }
            print '<a class="nfe-dropdown-item" href="#" onclick="openIncluirCondutorModal('.(int)$obj->id.'); return false;">Incluir Condutor</a>';
            print '<a class="nfe-dropdown-item" href="#" onclick="openEncerrarModal('.(int)$obj->id.'); return false;">Encerrar</a>';
            print '<a class="nfe-dropdown-item" href="#" onclick="openCancelarModal('.(int)$obj->id.', \''.$obj->data_emissao.'\'); return false;">Cancelar</a>';
        }
        if (in_array($status, ['rejeitada', 'erro', 'pendente'])) {
            print '<span class="nfe-dropdown-item disabled" title="MDF-e não autorizada. Sem ações disponíveis.">Sem ações</span>';
        }
        print '</div></div></td>';

        print '</tr>';
        $i++;
    }
} else {
    print '<tr><td colspan="9" class="opacitymedium center" style="padding: 30px;">Nenhum MDF-e emitido até o momento.</td></tr>';
}

print '</table>';
print '</form>';
print '</div>';

// Substituir o bloco antigo de contagem + print_barre_liste por paginação customizada:
// Ajusta filtros para propagarem a nova chave no buildURL
$filters = [
    'search_status_list' => $search_status_list,
    'search_numero_start' => $search_numero_start,
    'search_numero_end' => $search_numero_end,
    'search_chave' => $search_chave,
    'search_uf_ini' => $search_uf_ini,
    'search_uf_fim' => $search_uf_fim,
    'search_placa' => $search_placa,
    'search_data_emissao_start' => $search_data_emissao_start,
    'search_data_emissao_end' => $search_data_emissao_end,
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

// Renderiza paginação customizada
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
    var filters = '.$filtersJson.';
    filters.page = pageNum - 1;
    filters.limit = currentLimit;
    window.location.href = "' . $_SERVER["PHP_SELF"] . '?" + new URLSearchParams(Object.entries(filters).filter(([k,v]) => v)).toString();
}
</script>';

// Modal HTML

// Modal Principal (para configurações e outras ações)
print '<div id="nfseModal" class="nfse-modal-overlay" role="dialog" aria-modal="true" style="display: none;">';
print '  <div class="nfse-modal">';
print '    <div class="nfse-modal-header">';
print '      <strong id="nfseModalTitle">Modal</strong>';
print '      <button class="nfse-modal-close" onclick="closeNfseModal()" aria-label="Fechar">&times;</button>';
print '    </div>';
print '    <div class="nfse-modal-body" id="nfseModalBody">';
print '      <!-- Conteúdo dinâmico -->';
print '    </div>';
print '  </div>';
print '</div>';

// Modal de Encerramento MDF-e
print '<style>
.mdfe-enc-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;z-index:200000;}
.mdfe-enc-overlay.visible{display:flex;}
.mdfe-enc-box{background:#fff;border:1px solid #c8c8c8;border-radius:6px;box-shadow:0 4px 24px rgba(0,0,0,.18);max-width:580px;width:95%;display:flex;flex-direction:column;max-height:92vh;}
.mdfe-enc-header{background:#f7f7f7;border-bottom:2px solid #e0e0e0;padding:12px 18px;display:flex;align-items:baseline;justify-content:space-between;gap:8px;flex-shrink:0;}
.mdfe-enc-header strong{font-size:1em;color:#222;}
.mdfe-enc-header small{font-size:.78em;color:#999;font-weight:400;}
.mdfe-enc-close{background:none;border:none;color:#aaa;font-size:1.4em;cursor:pointer;padding:0 2px;line-height:1;transition:color .15s;}
.mdfe-enc-close:hover{color:#333;}
.mdfe-enc-body{padding:16px 18px;overflow-y:auto;flex:1;}
.mdfe-enc-loading{text-align:center;padding:36px 0;color:#aaa;font-size:.88em;}
.mdfe-enc-notice{background:#fffde7;border-left:3px solid #ffc107;border-radius:2px;padding:7px 11px;margin-bottom:14px;font-size:.81em;color:#5a4200;line-height:1.5;}
.mdfe-enc-section{margin-bottom:12px;}
.mdfe-enc-section-title{font-size:.7em;font-weight:700;color:#666;text-transform:uppercase;letter-spacing:.5px;padding:3px 0 4px 8px;margin-bottom:4px;border-bottom:1px solid #e8e8e8;border-left:3px solid #aaa;}
.mdfe-enc-table{width:100%;border-collapse:collapse;font-size:.855em;table-layout:fixed;}
.mdfe-enc-table tr:nth-child(even) td{background:#fafafa;}
.mdfe-enc-table td{padding:5px 8px;vertical-align:middle;border-bottom:1px solid #f1f1f1;overflow:hidden;text-overflow:ellipsis;}
.mdfe-enc-table .lbl{color:#888;font-weight:600;width:20%;white-space:nowrap;font-size:.82em;}
.mdfe-enc-table .val{color:#1a1a1a;width:28%;}
.mdfe-enc-table .mono{font-family:Consolas,monospace;font-size:.8em;word-break:break-all;color:#444;}
.mdfe-enc-footer{display:flex;justify-content:flex-end;gap:8px;padding:11px 18px;border-top:1px solid #e8e8e8;background:#fafafa;flex-shrink:0;}
</style>';
print '<div id="mdfeEncerrarModal" class="mdfe-enc-overlay" role="dialog" aria-modal="true">';
print '  <div class="mdfe-enc-box">';
print '    <div class="mdfe-enc-header">';
print '      <div><strong>Encerrar MDF-e</strong></div>';
print '      <button class="mdfe-enc-close" onclick="closeEncerrarModal()" aria-label="Fechar">&times;</button>';
print '    </div>';
print '    <div class="mdfe-enc-body" id="mdfeEncerrarBody">';
print '      <div class="mdfe-enc-loading" id="mdfeEncLoading">Carregando dados da MDF-e...</div>';
print '      <div id="mdfeEncContent" style="display:none;">';

print '        <div class="mdfe-enc-section">';
print '          <div class="mdfe-enc-section-title">Documento</div>';
print '          <table class="mdfe-enc-table">';
print '            <tr><td class="lbl">Número MDF-e</td><td class="val" id="encDocNumero">-</td><td class="lbl">Ambiente</td><td class="val" id="encDocAmb">-</td></tr>';
print '            <tr><td class="lbl">Data de Emissão</td><td class="val" id="encDocEmissao">-</td><td class="lbl">Protocolo</td><td class="val" id="encDocProtocolo">-</td></tr>';
print '            <tr><td class="lbl">Chave de Acesso</td><td class=" " colspan="3" id="encDocChave">-</td></tr>';
print '          </table>';
print '        </div>';
print '        <div class="mdfe-enc-section">';
print '          <div class="mdfe-enc-section-title">Percurso</div>';
print '          <table class="mdfe-enc-table">';
print '            <tr><td class="lbl">UF Início</td><td class="val" id="encPercUfIni">-</td><td class="lbl">UF Fim</td><td class="val" id="encPercUfFim">-</td></tr>';
print '            <tr><td class="lbl">Mun. Carrega</td><td class="val" id="encPercMunCarrega">-</td><td class="lbl">Mun. Descarga</td><td class="val" id="encPercMunDescarga">-</td></tr>';
print '          </table>';
print '        </div>';
print '        <div class="mdfe-enc-section">';
print '          <div class="mdfe-enc-section-title">Transporte</div>';
print '          <table class="mdfe-enc-table">';
print '            <tr><td class="lbl">Modal</td><td class="val" id="encTransModal">-</td><td class="lbl">Placa</td><td class="val" id="encTransPlaca">-</td></tr>';
print '            <tr><td class="lbl">Condutor</td><td class="val" id="encTransCondutor">-</td><td class="lbl">RNTRC</td><td class="val" id="encTransRntrc">-</td></tr>';
print '          </table>';
print '        </div>';
print '        <div class="mdfe-enc-section">';
print '          <div class="mdfe-enc-section-title">Carga</div>';
print '          <table class="mdfe-enc-table">';
print '            <tr><td class="lbl">Produto</td><td class="val" id="encCargaProduto">-</td><td class="lbl">Tipo</td><td class="val" id="encCargaTipo">-</td></tr>';
print '            <tr><td class="lbl">Valor</td><td class="val" id="encCargaValor">-</td><td class="lbl">Peso</td><td class="val" id="encCargaPeso">-</td></tr>';
print '            <tr><td class="lbl">Qtd. CT-e</td><td class="val" id="encCargaCte">-</td><td class="lbl">Qtd. NF-e</td><td class="val" id="encCargaNfe">-</td></tr>';
print '          </table>';
print '        </div>';
print '      </div>';
print '    </div>';
print '    <div class="mdfe-enc-footer" id="mdfeEncFooter" style="display:none;">';
print '      <button class="butActionDelete" onclick="closeEncerrarModal()">Cancelar</button>';
print '      <button class="butAction" id="mdfeEncerrarBtn" onclick="confirmarEncerramento()">Confirmar Encerramento</button>';
print '    </div>';
print '  </div>';
print '</div>';

// Modal de Cancelamento MDF-e
print '<style>
.mdfe-can-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;z-index:200001;}
.mdfe-can-overlay.visible{display:flex;}
.mdfe-can-box{background:#fff;border:1px solid #c8c8c8;border-radius:6px;box-shadow:0 4px 24px rgba(0,0,0,.18);max-width:480px;width:95%;display:flex;flex-direction:column;}
.mdfe-can-header{background:#f7f7f7;border-bottom:2px solid #e0e0e0;padding:12px 18px;display:flex;align-items:baseline;justify-content:space-between;gap:8px;flex-shrink:0;}
.mdfe-can-header strong{font-size:1em;color:#222;}
.mdfe-can-header small{font-size:.78em;color:#999;font-weight:400;}
.mdfe-can-close{background:none;border:none;color:#aaa;font-size:1.4em;cursor:pointer;padding:0 2px;line-height:1;transition:color .15s;}
.mdfe-can-close:hover{color:#333;}
.mdfe-can-body{padding:16px 18px;}
.mdfe-can-notice{background:#fff3cd;border-left:3px solid #ffc107;border-radius:2px;padding:7px 11px;margin-bottom:14px;font-size:.81em;color:#5a4200;line-height:1.5;}
.mdfe-can-notice.error{background:#fdf2f2;border-left-color:#dc3545;color:#721c24;}
.mdfe-can-label{font-size:.82em;font-weight:600;color:#555;margin-bottom:5px;display:block;}
.mdfe-can-label span{color:#c00;margin-left:2px;}
.mdfe-can-textarea{width:100%;box-sizing:border-box;border:1px solid #ccc;border-radius:4px;padding:8px 10px;font-size:.88em;color:#222;resize:vertical;min-height:80px;font-family:inherit;line-height:1.5;transition:border-color .15s;}
.mdfe-can-textarea:focus{border-color:#007bff;outline:none;box-shadow:0 0 0 2px rgba(0,123,255,.15);}
.mdfe-can-counter{font-size:.75em;color:#aaa;text-align:right;margin-top:3px;}
.mdfe-can-footer{display:flex;justify-content:flex-end;gap:8px;padding:11px 18px;border-top:1px solid #e8e8e8;background:#fafafa;flex-shrink:0;}
</style>';
print '<div id="mdfeCancelarModal" class="mdfe-can-overlay" role="dialog" aria-modal="true">';
print '  <div class="mdfe-can-box">';
print '    <div class="mdfe-can-header">';
print '      <div><strong>Cancelar MDF-e</strong></div>';
print '      <button class="mdfe-can-close" onclick="closeCancelarModal()" aria-label="Fechar">&times;</button>';
print '    </div>';
print '    <div class="mdfe-can-body">';
print '      <div class="mdfe-can-notice" id="mdfeCancelNotice">Atenção: o cancelamento é <strong>irreversível</strong> e só é permitido nas <strong>primeiras 24 horas</strong> após a emissão. MDF-e encerradas não podem ser canceladas.</div>';
print '      <label class="mdfe-can-label" for="mdfeCancelJust">Justificativa do cancelamento <span>*</span></label>';
print '      <textarea id="mdfeCancelJust" class="mdfe-can-textarea" maxlength="255" placeholder="Mínimo 15 caracteres..." oninput="mdfeCancelCounter(this)"></textarea>';
print '      <div class="mdfe-can-counter"><span id="mdfeCancelLen">0</span> / 255</div>';
print '    </div>';
print '    <div class="mdfe-can-footer">';
print '      <button class="butActionDelete" onclick="closeCancelarModal()">Fechar</button>';
print '      <button class="butAction" id="mdfeCancelarBtn" onclick="confirmarCancelamento()">Cancelar MDF-e</button>';
print '    </div>';
print '  </div>';
print '</div>';

// Modal de Inclusão de DF-e (NF-e) em MDF-e autorizada
print '<style>
.mdfe-inc-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;z-index:200001;}
.mdfe-inc-overlay.visible{display:flex;}
.mdfe-inc-box{background:#fff;border:1px solid #c8c8c8;border-radius:8px;box-shadow:0 4px 28px rgba(0,0,0,.2);max-width:680px;width:96%;display:flex;flex-direction:column;}
.mdfe-inc-header{background:#f7f7f7;border-bottom:2px solid #e0e0e0;padding:16px 24px;display:flex;align-items:baseline;justify-content:space-between;gap:10px;flex-shrink:0;}
.mdfe-inc-header strong{font-size:1.1em;color:#222;}
.mdfe-inc-header small{font-size:.85em;color:#999;font-weight:400;}
.mdfe-inc-close{background:none;border:none;color:#aaa;font-size:1.6em;cursor:pointer;padding:0 4px;line-height:1;transition:color .15s;}
.mdfe-inc-close:hover{color:#333;}
.mdfe-inc-body{padding:22px 28px;}
.mdfe-inc-notice{background:#d4edda;border-left:4px solid #28a745;border-radius:3px;padding:10px 14px;margin-bottom:18px;font-size:.92em;color:#155724;line-height:1.6;}
.mdfe-inc-notice.error{background:#fdf2f2;border-left-color:#dc3545;color:#721c24;}
.mdfe-inc-notice.warn{background:#fff3cd;border-left-color:#ffc107;color:#5a4200;}
.mdfe-inc-row{display:flex;gap:16px;margin-bottom:16px;}
.mdfe-inc-row .mdfe-inc-field{flex:1;}
.mdfe-inc-label{font-size:.93em;font-weight:600;color:#444;margin-bottom:6px;display:block;}
.mdfe-inc-label span{color:#c00;margin-left:2px;}
.mdfe-inc-select,.mdfe-inc-input{width:100%;box-sizing:border-box;border:1px solid #ccc;border-radius:5px;padding:10px 13px;font-size:.97em;color:#222;font-family:inherit;transition:border-color .15s;}
.mdfe-inc-select:focus,.mdfe-inc-input:focus{border-color:#007bff;outline:none;box-shadow:0 0 0 3px rgba(0,123,255,.15);}
.mdfe-inc-select:disabled{background:#f5f5f5;color:#999;}
.mdfe-inc-sep{font-size:.8em;font-weight:700;color:#777;text-transform:uppercase;letter-spacing:.6px;padding:10px 0 6px;margin-top:16px;margin-bottom:14px;border-bottom:1px solid #e4e4e4;}
.mdfe-inc-footer{display:flex;justify-content:flex-end;gap:10px;padding:14px 24px;border-top:1px solid #e8e8e8;background:#fafafa;flex-shrink:0;}
</style>';

$ufsIncDfe = ['AC','AL','AM','AP','BA','CE','DF','ES','GO','MA','MG','MS','MT','PA','PB','PE','PI','PR','RJ','RN','RO','RR','RS','SC','SE','SP','TO'];

print '<div id="mdfeIncluirDfeModal" class="mdfe-inc-overlay" role="dialog" aria-modal="true">';
print '  <div class="mdfe-inc-box">';
print '    <div class="mdfe-inc-header">';
print '      <div><strong>Incluir NF-e</strong> <small id="incDfeChaveResumo"></small></div>';
print '      <button class="mdfe-inc-close" onclick="closeIncluirDfeModal()" aria-label="Fechar">&times;</button>';
print '    </div>';
print '    <div class="mdfe-inc-body" id="incDfeBody">';
print '      <div class="mdfe-inc-notice warn" id="incDfeNotice">Informe os munic&iacute;pios de carregamento e descarga e a chave da NF-e a ser inclu&iacute;da na MDF-e.</div>';

// Carregamento
print '      <div class="mdfe-inc-sep" style="margin-top:4px;">Munic&iacute;pio de Carregamento</div>';
print '      <div class="mdfe-inc-row">';
print '        <div class="mdfe-inc-field">';
print '          <label class="mdfe-inc-label" for="incDfeUfCarrega">UF <span>*</span></label>';
print '          <select id="incDfeUfCarrega" class="mdfe-inc-select" onchange="incDfeCarregarCidades(\'carrega\')">';
print '            <option value="">Selecione...</option>';
foreach ($ufsIncDfe as $_uf) {
    print '            <option value="'.$_uf.'">'.$_uf.'</option>';
}
print '          </select>';
print '        </div>';
print '        <div class="mdfe-inc-field">';
print '          <label class="mdfe-inc-label" for="incDfeMunCarrega">Munic&iacute;pio <span>*</span></label>';
print '          <select id="incDfeMunCarrega" class="mdfe-inc-select" disabled><option value="">Selecione a UF primeiro</option></select>';
print '        </div>';
print '      </div>';
// Descarga
print '      <div class="mdfe-inc-sep">Munic&iacute;pio de Descarga</div>';
print '      <div class="mdfe-inc-row">';
print '        <div class="mdfe-inc-field">';
print '          <label class="mdfe-inc-label" for="incDfeUfDescarga">UF <span>*</span></label>';
print '          <select id="incDfeUfDescarga" class="mdfe-inc-select" onchange="incDfeCarregarCidades(\'descarga\')">';
print '            <option value="">Selecione...</option>';
foreach ($ufsIncDfe as $_uf) {
    print '            <option value="'.$_uf.'">'.$_uf.'</option>';
}
print '          </select>';
print '        </div>';
print '        <div class="mdfe-inc-field">';
print '          <label class="mdfe-inc-label" for="incDfeMunDescarga">Munic&iacute;pio <span>*</span></label>';
print '          <select id="incDfeMunDescarga" class="mdfe-inc-select" disabled><option value="">Selecione a UF primeiro</option></select>';
print '        </div>';
print '      </div>';
// Chave NF-e
print '      <div class="mdfe-inc-sep">Documento Fiscal</div>';
print '      <div style="margin-top:8px;">';
print '        <label class="mdfe-inc-label" for="incDfeChNFe">Chave NF-e (44 d&iacute;gitos) <span>*</span></label>';
print '        <input type="text" id="incDfeChNFe" class="mdfe-inc-input" maxlength="44" placeholder="00000000000000000000000000000000000000000000" style="font-family:Consolas,monospace;letter-spacing:1px;" oninput="document.getElementById(\'incDfeChLen\').textContent=this.value.length">';
print '        <div style="font-size:.75em;color:#aaa;text-align:right;margin-top:2px;"><span id="incDfeChLen">0</span> / 44</div>';
print '      </div>';
print '    </div>';
print '    <div class="mdfe-inc-footer">';
print '      <button class="butActionDelete" onclick="closeIncluirDfeModal()">Fechar</button>';
print '      <button class="butAction" id="incDfeEnviarBtn" onclick="confirmarIncluirDfe()">Incluir NF-e</button>';
print '    </div>';
print '  </div>';
print '</div>';

// Modal de Inclusão de Condutor em MDF-e autorizada
print '<style>
.mdfe-cond-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;align-items:center;justify-content:center;z-index:200001;}
.mdfe-cond-overlay.visible{display:flex;}
.mdfe-cond-box{background:#fff;border:1px solid #c8c8c8;border-radius:8px;box-shadow:0 4px 28px rgba(0,0,0,.2);max-width:580px;width:96%;display:flex;flex-direction:column;}
.mdfe-cond-header{background:#f7f7f7;border-bottom:2px solid #e0e0e0;padding:16px 24px;display:flex;align-items:baseline;justify-content:space-between;gap:10px;flex-shrink:0;}
.mdfe-cond-header strong{font-size:1.1em;color:#222;}
.mdfe-cond-header small{font-size:.85em;color:#999;font-weight:400;}
.mdfe-cond-close{background:none;border:none;color:#aaa;font-size:1.6em;cursor:pointer;padding:0 4px;line-height:1;transition:color .15s;}
.mdfe-cond-close:hover{color:#333;}
.mdfe-cond-body{padding:22px 28px;}
.mdfe-cond-notice{background:#d4edda;border-left:4px solid #28a745;border-radius:3px;padding:10px 14px;margin-bottom:18px;font-size:.92em;color:#155724;line-height:1.6;}
.mdfe-cond-notice.error{background:#fdf2f2;border-left-color:#dc3545;color:#721c24;}
.mdfe-cond-notice.warn{background:#fff3cd;border-left-color:#ffc107;color:#5a4200;}
.mdfe-cond-label{font-size:.93em;font-weight:600;color:#444;margin-bottom:6px;display:block;}
.mdfe-cond-label span{color:#c00;margin-left:2px;}
.mdfe-cond-input{width:100%;box-sizing:border-box;border:1px solid #ccc;border-radius:5px;padding:10px 13px;font-size:.97em;color:#222;font-family:inherit;transition:border-color .15s;margin-bottom:16px;}
.mdfe-cond-input:focus{border-color:#007bff;outline:none;box-shadow:0 0 0 3px rgba(0,123,255,.15);}
.mdfe-cond-footer{display:flex;justify-content:flex-end;gap:10px;padding:14px 24px;border-top:1px solid #e8e8e8;background:#fafafa;flex-shrink:0;}
</style>';

print '<div id="mdfeIncluirCondutorModal" class="mdfe-cond-overlay" role="dialog" aria-modal="true">';
print '  <div class="mdfe-cond-box">';
print '    <div class="mdfe-cond-header">';
print '      <div><strong>Incluir Condutor</strong> <small id="incCondResumo"></small></div>';
print '      <button class="mdfe-cond-close" onclick="closeIncluirCondutorModal()" aria-label="Fechar">&times;</button>';
print '    </div>';
print '    <div class="mdfe-cond-body" id="incCondBody">';
print '      <div class="mdfe-cond-notice warn" id="incCondNotice">Informe o nome e CPF do condutor a ser incluído na MDF-e.</div>';
print '      <label class="mdfe-cond-label" for="incCondNome">Nome do Condutor <span>*</span></label>';
print '      <input type="text" id="incCondNome" class="mdfe-cond-input" maxlength="60" placeholder="Nome completo do condutor">';
print '      <label class="mdfe-cond-label" for="incCondCpf">CPF <span>*</span></label>';
print '      <input type="text" id="incCondCpf" class="mdfe-cond-input" maxlength="14" placeholder="000.000.000-00" oninput="formatarCpfInput(this)" style="font-family:Consolas,monospace;letter-spacing:1px;">';
print '    </div>';
print '    <div class="mdfe-cond-footer">';
print '      <button class="butActionDelete" onclick="closeIncluirCondutorModal()">Fechar</button>';
print '      <button class="butAction" id="incCondEnviarBtn" onclick="confirmarIncluirCondutor()">Incluir Condutor</button>';
print '    </div>';
print '  </div>';
print '</div>';

// Modal de Confirmação usando padrões do Dolibarr
print '<div id="nfseConfirmModal" class="nfse-confirm-overlay" role="dialog" aria-modal="true">
  <div class="center" style="background: white; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.2); max-width: 400px; padding: 20px;">
    <div class="titre" style="font-size: 1.2em; font-weight: bold; margin-bottom: 15px;">Confirmação de Cancelamento</div>
    <div style="font-size: 0.95em; color: #333; margin-bottom: 15px;">
      Tem certeza de que deseja cancelar esta NFSe? Esta operação não poderá ser desfeita.
    </div>
    <div class="opacitymedium" style="font-size: 0.85em; color: #666; margin-bottom: 20px;">
      Confirme para prosseguir ou clique em "Fechar" para voltar.
    </div>
    <div class="center" style="display: flex; justify-content: center; gap: 10px;">
      <button class="butActionDelete" onclick="closeConfirmModal()">Fechar</button>
      <button class="butAction" onclick="confirmarCancelamento()">Confirmar</button>
    </div>
  </div>
</div>';

print '<script>
/* Funções da Modal Principal */
function openNfseModal(title) {
    var modal = document.getElementById("nfseModal");
    var titleEl = document.getElementById("nfseModalTitle");
    if (modal) {
        modal.style.display = "flex";
        if (titleEl && title) titleEl.textContent = title;
    }
}

function closeNfseModal() {
    var modal = document.getElementById("nfseModal");
    if (modal) {
        modal.style.display = "none";
        var body = document.getElementById("nfseModalBody");
        if (body) body.innerHTML = "";
    }
}

// Fechar modal ao clicar fora do conteúdo
document.addEventListener("click", function(e) {
    var modal = document.getElementById("nfseModal");
    if (modal && e.target === modal) {
        closeNfseModal();
    }
});

// Fechar modal com tecla ESC
document.addEventListener("keydown", function(e) {
    if (e.key === "Escape" || e.key === "Esc") {
        var modal = document.getElementById("nfseModal");
        if (modal && modal.style.display === "flex") {
            closeNfseModal();
        }
    }
});

/* Reativa dropdowns de Ações */
function toggleDropdown(event, menuId) {
    event.stopPropagation();
    var menu = document.getElementById(menuId);
    if (!menu) return;
    var isOpen = menu.style.display === "block";
    document.querySelectorAll(".nfe-dropdown-menu").forEach(function(m){
        m.style.display = "none";
    });
    menu.style.display = isOpen ? "none" : "block";
}

// Fecha dropdowns ao clicar fora ou pressionar ESC
document.addEventListener("click", function(e) {
    if (!e.target.closest(".nfe-dropdown")) {
        document.querySelectorAll(".nfe-dropdown-menu").forEach(function(m){
            m.style.display = "none";
        });
    }
});
document.addEventListener("keydown", function(e) {
    if (e.key === "Escape" || e.key === "Esc") {
        document.querySelectorAll(".nfe-dropdown-menu").forEach(function(m){
            m.style.display = "none";
        });
    }
});

// Função para abrir configurações
function openNfseConfiguracoes() {
  document.querySelectorAll(".nfe-dropdown-menu").forEach(m => m.style.display = "none");
  var body = document.getElementById("nfseModalBody");
  if (body) body.innerHTML = "<div class=\\"nfse-loading-container\\"><div class=\\"nfse-spinner\\"></div><p>Carregando configurações...</p></div>";
  var modal = document.getElementById("nfseModal").querySelector(".nfse-modal");
  if (modal) modal.className = "nfse-modal nfse-modal-configuracoes";
  openNfseModal("Configurações MDF-e");
  
  var url = "'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'?action=carregar_configuracoes";
  fetch(url, { 
    headers: { "X-Requested-With": "XMLHttpRequest" },
    credentials: "same-origin"
  })
    .then(function(r){ 
      if (!r.ok) {
        return r.text().then(function(text) {
          throw new Error("Erro " + r.status);
        });
      }
      return r.text(); 
    })
    .then(function(html){
      var bodyEl = document.getElementById("nfseModalBody");
      if (bodyEl) {
        bodyEl.innerHTML = html;
      }
    })
    .catch(function(err){
      var bodyEl = document.getElementById("nfseModalBody");
      if (bodyEl) bodyEl.innerHTML = "<div class=\\"nfse-alert nfse-alert-error\\">Falha ao carregar configurações: "+ (err && err.message ? err.message : "erro desconhecido") +"</div><div style=\\"text-align:center;margin-top:20px;\\"><button class=\\"butAction\\" onclick=\\"closeNfseModal()\\">Fechar</button></div>";
    });
}

function salvarSequenciasNacional() {
  var form = document.getElementById("formSequenciasNacional");
  if (!form) return;
  
  // Desabilita o botão para evitar cliques múltiplos
  var saveBtn = event.target;
  if (saveBtn) {
    saveBtn.disabled = true;
    saveBtn.style.opacity = "0.6";
  }
  
  var body = document.getElementById("nfseModalBody");
  if (body) {
    body.innerHTML = "<div class=\\"nfse-loading-container\\">"+
      "<div class=\\"nfse-spinner\\"></div>"+
      "<p style=\\"animation: fadeIn 0.5s;\\">Salvando configurações...</p>"+
      "</div>";
  }
  
  var formData = new FormData(form);
  
  fetch("'.dol_escape_htmltag($_SERVER["PHP_SELF"]).'", {
    method: "POST",
    body: formData,
    headers: { "X-Requested-With": "XMLHttpRequest" },
    credentials: "same-origin"
  })
  .then(function(r){ 
    if (!r.ok) throw new Error("Erro ao salvar (" + r.status + ")");
    return r.json();
  })
  .then(function(response){
    if (response.success) {
      var bodyEl = document.getElementById("nfseModalBody");
      if (bodyEl) {
        bodyEl.innerHTML = 
          "<div class=\\"nfse-success-container\\">"+
            "<div class=\\"nfse-success-icon\\">"+
              "<svg viewBox=\\"0 0 24 24\\">"+
                "<path d=\\"M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z\\"/>"+
              "</svg>"+
            "</div>"+
            "<div class=\\"nfse-success-message\\">" + (response.message || "Configurações salvas com sucesso!") + "</div>"+
            "<div class=\\"nfse-success-submessage\\">Recarregando página...</div>"+
          "</div>";
      }
      setTimeout(function() {
        closeNfseModal();
        window.location.reload();
      }, 1800);
    } else {
      throw new Error(response.error || "Erro desconhecido");
    }
  })
  .catch(function(err){
    var bodyEl = document.getElementById("nfseModalBody");
    if (bodyEl) {
      bodyEl.innerHTML = 
        "<div style=\\"padding: 30px; text-align: center;\\">"+
          "<div style=\\"width: 70px; height: 70px; margin: 0 auto 20px; border-radius: 50%; background: #dc3545; display: flex; align-items: center; justify-content: center;\\">"+
            "<svg viewBox=\\"0 0 24 24\\" width=\\"35\\" height=\\"35\\" style=\\"fill: white;\\">"+
              "<path d=\\"M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z\\"/>"+
            "</svg>"+
          "</div>"+
          "<div style=\\"font-size: 18px; font-weight: 600; color: #dc3545; margin-bottom: 10px;\\">Erro ao Salvar</div>"+
          "<div style=\\"color: #666; margin-bottom: 25px;\\">"+ (err && err.message ? err.message : "erro desconhecido") +"</div>"+
          "<button class=\\"butAction\\" onclick=\\"openNfseConfiguracoes()\\">Tentar Novamente</button> "+
          "<button class=\\"butActionDelete\\" onclick=\\"closeNfseModal()\\" style=\\"margin-left: 10px;\\">Fechar</button>"+
        "</div>";
    }
  });
}

// === Encerramento MDF-e ===
var _mdfeEncerrarId = null;

function formatCnpj(v) {
    if (!v || v.length !== 14) return v || "-";
    return v.replace(/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/, "$1.$2.$3/$4-$5");
}
function formatCpf(v) {
    if (!v || v.length !== 11) return v || "-";
    return v.replace(/(\d{3})(\d{3})(\d{3})(\d{2})/, "$1.$2.$3-$4");
}
function setVal(id, val) {
    var el = document.getElementById(id);
    if (el) el.textContent = val || "-";
}

function openEncerrarModal(id) {
    document.querySelectorAll(".nfe-dropdown-menu").forEach(function(m){ m.style.display = "none"; });
    _mdfeEncerrarId = id;
    var overlay  = document.getElementById("mdfeEncerrarModal");
    var loading  = document.getElementById("mdfeEncLoading");
    var content  = document.getElementById("mdfeEncContent");
    var footer   = document.getElementById("mdfeEncFooter");
    var btn      = document.getElementById("mdfeEncerrarBtn");
    // Reset estado
    if (loading) loading.style.display = "block";
    if (content) content.style.display = "none";
    if (footer)  footer.style.display  = "none";
    if (btn) { btn.disabled = false; btn.style.opacity = ""; btn.innerHTML = "Confirmar Encerramento"; }
    if (overlay) overlay.classList.add("visible");

    // Remove alerta de erro anterior, se houver
    var oldErr = document.getElementById("mdfeEncErrBox");
    if (oldErr) oldErr.parentNode.removeChild(oldErr);

    fetch("'.dol_escape_js($_SERVER['PHP_SELF']).'", {
        method: "POST",
        headers: { "X-Requested-With": "XMLHttpRequest", "Content-Type": "application/x-www-form-urlencoded" },
        credentials: "same-origin",
        body: "action=carregar_dados_encerramento&token="+encodeURIComponent("'.newToken().'")+"&id="+encodeURIComponent(id)
    })
    .then(function(r){ if (!r.ok) throw new Error("Erro HTTP " + r.status); return r.json(); })
    .then(function(data) {
        if (loading) loading.style.display = "none";
        if (!data.success) {
            var errBox = document.createElement("div");
            errBox.id = "mdfeEncErrBox";
            errBox.className = "mdfe-enc-notice";
            errBox.style.background = "#fdf2f2"; errBox.style.borderLeftColor = "#dc3545"; errBox.style.color = "#721c24";
            errBox.textContent = data.error || "Erro ao carregar dados.";
            var body = document.getElementById("mdfeEncerrarBody");
            if (body) body.insertBefore(errBox, body.firstChild);
            return;
        }
        var d = data.dados;
        // Documento
        setVal("encDocNumero", d.numero);
        setVal("encDocAmb", d.ambiente);
        setVal("encDocEmissao", d.data_emissao);
        setVal("encDocProtocolo", d.protocolo);
        // Chave — elemento usa textContent direto
        (function(){ var el = document.getElementById("encDocChave"); if (el) el.textContent = d.chave_acesso || "-"; })();
        // Percurso
        setVal("encPercUfIni", d.uf_ini);
        setVal("encPercUfFim", d.uf_fim);
        setVal("encPercMunCarrega", d.mun_carrega);
        setVal("encPercMunDescarga", d.mun_descarga);
        // Transporte
        setVal("encTransModal", d.modal);
        setVal("encTransPlaca", d.placa);
        var condutorTxt = d.condutor || "-";
        if (d.condutor && d.condutor_cpf) condutorTxt = d.condutor + " (" + formatCpf(d.condutor_cpf) + ")";
        setVal("encTransCondutor", condutorTxt);
        setVal("encTransRntrc", d.rntrc || "-");
        // Carga
        setVal("encCargaProduto", d.produto || "-");
        setVal("encCargaTipo", d.tipo_carga || "-");
        setVal("encCargaValor", d.valor_carga);
        var pesoTxt = d.peso_carga ? (d.peso_carga + " " + (d.unid_peso || "")).trim() : "-";
        setVal("encCargaPeso", pesoTxt);
        setVal("encCargaCte", d.qtd_cte || "0");
        setVal("encCargaNfe", d.qtd_nfe || "0");
        if (content) content.style.display = "block";
        if (footer)  footer.style.display  = "flex";
    })
    .catch(function(err) {
        if (loading) loading.style.display = "none";
        var errBox = document.createElement("div");
        errBox.id = "mdfeEncErrBox";
        errBox.className = "mdfe-enc-notice";
        errBox.style.background = "#fdf2f2"; errBox.style.borderLeftColor = "#dc3545"; errBox.style.color = "#721c24";
        errBox.textContent = "Erro de comunicação: " + ((err&&err.message)||"Erro desconhecido");
        var body = document.getElementById("mdfeEncerrarBody");
        if (body) body.insertBefore(errBox, body.firstChild);
    });
}

function closeEncerrarModal() {
    var overlay = document.getElementById("mdfeEncerrarModal");
    if (overlay) overlay.classList.remove("visible");
    _mdfeEncerrarId = null;
}

function confirmarEncerramento() {
    if (!_mdfeEncerrarId) return;
    var btn = document.getElementById("mdfeEncerrarBtn");
    if (btn) { btn.disabled = true; btn.style.opacity = "0.6"; btn.innerHTML = "Encerrando..."; }

    function showEncErr(msg) {
        var oldErr = document.getElementById("mdfeEncErrBox");
        if (oldErr) oldErr.parentNode.removeChild(oldErr);
        var errBox = document.createElement("div");
        errBox.id = "mdfeEncErrBox";
        errBox.className = "mdfe-enc-notice";
        errBox.style.background = "#fdf2f2"; errBox.style.borderLeftColor = "#dc3545"; errBox.style.color = "#721c24";
        errBox.textContent = msg;
        var body = document.getElementById("mdfeEncerrarBody");
        if (body) body.insertBefore(errBox, body.firstChild);
    }

    fetch("'.dol_escape_js($_SERVER['PHP_SELF']).'", {
        method: "POST",
        headers: { "X-Requested-With": "XMLHttpRequest", "Content-Type": "application/x-www-form-urlencoded" },
        credentials: "same-origin",
        body: "action=encerrar_mdfe&token="+encodeURIComponent("'.newToken().'")+"&id="+encodeURIComponent(_mdfeEncerrarId)
    })
    .then(function(r){ if (!r.ok) throw new Error("Erro HTTP " + r.status); return r.json(); })
    .then(function(data) {
        if (data.success) {
            closeEncerrarModal();
            window.location.reload();
        } else {
            showEncErr("Falha no encerramento: " + (data.error||"Erro desconhecido"));
            if (btn) { btn.disabled = false; btn.style.opacity = ""; btn.textContent = "Tentar Novamente"; }
        }
    })
    .catch(function(err) {
        showEncErr("Erro de comunicação: " + ((err&&err.message)||"Erro desconhecido"));
        if (btn) { btn.disabled = false; btn.style.opacity = ""; btn.textContent = "Tentar Novamente"; }
    });
}

// === Consulta MDF-e (modal via mdfe_consulta.php) ===
function openConsultarModal(id) {
    document.querySelectorAll(".nfe-dropdown-menu").forEach(function(m){ m.style.display = "none"; });
    var body = document.getElementById("nfseModalBody");
    if (body) body.innerHTML = "<div class=\"nfse-loading-container\"><div class=\"nfse-spinner\"></div><p>Carregando dados da MDF-e...</p></div>";
    var modal = document.getElementById("nfseModal").querySelector(".nfse-modal");
    if (modal) { modal.className = "nfse-modal"; modal.style.maxWidth = "900px"; modal.style.width = "96%"; }
    openNfseModal("Consulta MDF-e");

    fetch("'.dol_escape_js(dol_buildpath('/custom/mdfe/mdfe_consulta.php', 1)).'?action=consultar_html&id=" + encodeURIComponent(id), {
        headers: { "X-Requested-With": "XMLHttpRequest" },
        credentials: "same-origin"
    })
    .then(function(r){ if (!r.ok) throw new Error("Erro HTTP " + r.status); return r.text(); })
    .then(function(html){
        var bodyEl = document.getElementById("nfseModalBody");
        if (bodyEl) bodyEl.innerHTML = html;
    })
    .catch(function(err){
        var bodyEl = document.getElementById("nfseModalBody");
        if (bodyEl) bodyEl.innerHTML = "<div class=\"nfse-alert nfse-alert-error\">Falha ao carregar consulta: " + ((err&&err.message)||"erro desconhecido") + "</div><div style=\"text-align:center;margin-top:20px;\"><button class=\"butAction\" onclick=\"closeNfseModal()\">Fechar</button></div>";
    });
}

// Fecha modal de encerramento ao clicar no overlay ou ESC
document.addEventListener("click", function(e) {
    var overlay = document.getElementById("mdfeEncerrarModal");
    if (overlay && e.target === overlay) closeEncerrarModal();
});
document.addEventListener("keydown", function(e) {
    if ((e.key === "Escape" || e.key === "Esc") && _mdfeEncerrarId !== null) closeEncerrarModal();
});

// === Modal de Cancelamento MDF-e ===
var _mdfeCancelarId = null;

function mdfeCancelCounter(el) {
    var n = el.value.length;
    var span = document.getElementById("mdfeCancelLen");
    if (span) span.textContent = n;
}

function openCancelarModal(id, dataEmissao) {
    document.querySelectorAll(".nfe-dropdown-menu").forEach(function(m){ m.style.display = "none"; });
    _mdfeCancelarId = id;
    // Limpa estado anterior
    var notice   = document.getElementById("mdfeCancelNotice");
    var textarea = document.getElementById("mdfeCancelJust");
    var btn      = document.getElementById("mdfeCancelarBtn");
    var span     = document.getElementById("mdfeCancelLen");
    if (textarea) { textarea.value = ""; textarea.disabled = false; }
    if (span) span.textContent = "0";
    if (btn) { btn.disabled = false; btn.style.opacity = ""; btn.textContent = "Cancelar MDF-e"; }
    if (notice) {
        notice.className = "mdfe-can-notice";
        notice.innerHTML = "Aten\u00e7\u00e3o: o cancelamento \u00e9 <strong>irrevers\u00edvel</strong> e s\u00f3 \u00e9 permitido nas <strong>primeiras 24 horas</strong> ap\u00f3s a emiss\u00e3o. MDF-e encerradas n\u00e3o podem ser canceladas.";
    }
    // Verifica prazo de 24h no front (apenas alerta visual — o back-end valida de forma definitiva)
    if (dataEmissao) {
        var emissao = new Date(dataEmissao.replace(" ", "T"));
        var diffH   = (Date.now() - emissao.getTime()) / 3600000;
        if (diffH > 24) {
            if (notice) {
                notice.className = "mdfe-can-notice error";
                notice.textContent = "O prazo de 24 horas para cancelamento j\u00e1 foi ultrapassado. Esta MDF-e n\u00e3o pode ser cancelada.";
            }
            if (textarea) textarea.disabled = true;
            if (btn)      btn.disabled = true;
        }
    }
    var overlay = document.getElementById("mdfeCancelarModal");
    if (overlay) overlay.classList.add("visible");
}

function closeCancelarModal() {
    var overlay = document.getElementById("mdfeCancelarModal");
    if (overlay) overlay.classList.remove("visible");
    _mdfeCancelarId = null;
}

function confirmarCancelamento() {
    if (!_mdfeCancelarId) return;
    var textarea = document.getElementById("mdfeCancelJust");
    var notice   = document.getElementById("mdfeCancelNotice");
    var btn      = document.getElementById("mdfeCancelarBtn");
    var xJust    = textarea ? textarea.value.trim() : "";
    if (xJust.length < 15) {
        if (notice) { notice.className = "mdfe-can-notice error"; notice.textContent = "A justificativa deve ter pelo menos 15 caracteres."; }
        if (textarea) textarea.focus();
        return;
    }
    if (btn) { btn.disabled = true; btn.style.opacity = "0.6"; btn.textContent = "Cancelando..."; }

    fetch("'.dol_escape_js($_SERVER['PHP_SELF']).'", {
        method: "POST",
        headers: { "X-Requested-With": "XMLHttpRequest", "Content-Type": "application/x-www-form-urlencoded" },
        credentials: "same-origin",
        body: "action=cancelar_mdfe&token="+encodeURIComponent("'.newToken().'")
            + "&id="+encodeURIComponent(_mdfeCancelarId)
            + "&xJust="+encodeURIComponent(xJust)
    })
    .then(function(r){ if (!r.ok) throw new Error("Erro HTTP " + r.status); return r.json(); })
    .then(function(data) {
        if (data.success) {
            closeCancelarModal();
            window.location.reload();
        } else {
            if (notice) { notice.className = "mdfe-can-notice error"; notice.textContent = data.error || "Erro ao cancelar."; }
            if (btn) { btn.disabled = false; btn.style.opacity = ""; btn.textContent = "Tentar Novamente"; }
        }
    })
    .catch(function(err) {
        if (notice) { notice.className = "mdfe-can-notice error"; notice.textContent = "Erro de comunica\u00e7\u00e3o: " + ((err&&err.message)||"desconhecido"); }
        if (btn) { btn.disabled = false; btn.style.opacity = ""; btn.textContent = "Tentar Novamente"; }
    });
}

document.addEventListener("click", function(e) {
    var overlay = document.getElementById("mdfeCancelarModal");
    if (overlay && e.target === overlay) closeCancelarModal();
});
document.addEventListener("keydown", function(e) {
    if ((e.key === "Escape" || e.key === "Esc") && _mdfeCancelarId !== null) closeCancelarModal();
});

// === Inclusão de DF-e (NF-e) em MDF-e autorizada ===
var _mdfeIncluirDfeId = null;
var _incDfeCidadesCache = {};

function openIncluirDfeModal(id) {
    document.querySelectorAll(".nfe-dropdown-menu").forEach(function(m){ m.style.display = "none"; });
    _mdfeIncluirDfeId = id;
    // Reset campos
    var notice = document.getElementById("incDfeNotice");
    if (notice) { notice.className = "mdfe-inc-notice warn"; notice.innerHTML = "Selecione a cidade de carregamento, a cidade de descarga e informe a chave da NF-e a incluir."; }
    document.getElementById("incDfeUfCarrega").value = "";
    document.getElementById("incDfeUfDescarga").value = "";
    var munC = document.getElementById("incDfeMunCarrega");
    munC.innerHTML = "<option value=\\"\\">" + "Selecione a UF primeiro" + "</option>"; munC.disabled = true;
    var munD = document.getElementById("incDfeMunDescarga");
    munD.innerHTML = "<option value=\\"\\">" + "Selecione a UF primeiro" + "</option>"; munD.disabled = true;
    document.getElementById("incDfeChNFe").value = "";
    document.getElementById("incDfeChLen").textContent = "0";
    var btn = document.getElementById("incDfeEnviarBtn");
    if (btn) { btn.disabled = false; btn.style.opacity = ""; btn.textContent = "Incluir NF-e"; }
    var overlay = document.getElementById("mdfeIncluirDfeModal");
    if (overlay) overlay.classList.add("visible");
}

function closeIncluirDfeModal() {
    var overlay = document.getElementById("mdfeIncluirDfeModal");
    if (overlay) overlay.classList.remove("visible");
    _mdfeIncluirDfeId = null;
}

function incDfeCarregarCidades(tipo) {
    var sufixo = tipo === "carrega" ? "Carrega" : "Descarga";
    var ufSel = document.getElementById("incDfeUf" + sufixo);
    var munSel = document.getElementById("incDfeMun" + sufixo);
    var uf = ufSel ? ufSel.value : "";
    if (!uf) {
        munSel.innerHTML = "<option value=\\"\\">" + "Selecione a UF primeiro" + "</option>";
        munSel.disabled = true;
        return;
    }
    // Cache para evitar requisições repetidas
    if (_incDfeCidadesCache[uf]) {
        incDfePreencherMunicipios(munSel, _incDfeCidadesCache[uf]);
        return;
    }
    munSel.innerHTML = "<option value=\\"\\">" + "Carregando..." + "</option>";
    munSel.disabled = true;

    fetch("'.dol_escape_js(dol_buildpath('/custom/mdfe/mdfe_incluir_dfe.php', 1)).'?action=buscar_cidades&uf=" + encodeURIComponent(uf), {
        headers: { "X-Requested-With": "XMLHttpRequest" },
        credentials: "same-origin"
    })
    .then(function(r){ if (!r.ok) throw new Error("Erro HTTP " + r.status); return r.json(); })
    .then(function(data) {
        if (!data.success) throw new Error(data.error || "Erro");
        _incDfeCidadesCache[uf] = data.cidades;
        incDfePreencherMunicipios(munSel, data.cidades);
    })
    .catch(function(err) {
        munSel.innerHTML = "<option value=\\"\\">" + "Erro ao carregar" + "</option>";
        munSel.disabled = true;
    });
}

function incDfePreencherMunicipios(selectEl, cidades) {
    var html = "<option value=\\"\\">" + "Selecione..." + "</option>";
    for (var i = 0; i < cidades.length; i++) {
        html += "<option value=\\"" + cidades[i].nome + "\\">" + cidades[i].nome + "</option>";
    }
    selectEl.innerHTML = html;
    selectEl.disabled = false;
}

function confirmarIncluirDfe() {
    if (!_mdfeIncluirDfeId) return;
    var notice = document.getElementById("incDfeNotice");
    var btn    = document.getElementById("incDfeEnviarBtn");

    var ufCarrega   = document.getElementById("incDfeUfCarrega").value;
    var munCarrega  = document.getElementById("incDfeMunCarrega").value;
    var ufDescarga  = document.getElementById("incDfeUfDescarga").value;
    var munDescarga = document.getElementById("incDfeMunDescarga").value;
    var chNFe       = document.getElementById("incDfeChNFe").value.trim();

    // Validacao front-end
    if (!ufCarrega || !munCarrega) {
        if (notice) { notice.className = "mdfe-inc-notice error"; notice.textContent = "Selecione a UF e o municipio de carregamento."; }
        return;
    }
    if (!ufDescarga || !munDescarga) {
        if (notice) { notice.className = "mdfe-inc-notice error"; notice.textContent = "Selecione a UF e o municipio de descarga."; }
        return;
    }
    if (chNFe.length !== 44 || !/^[0-9]{44}$/.test(chNFe)) {
        if (notice) { notice.className = "mdfe-inc-notice error"; notice.textContent = "A chave da NF-e deve ter exatamente 44 digitos numericos."; }
        document.getElementById("incDfeChNFe").focus();
        return;
    }

    if (btn) { btn.disabled = true; btn.style.opacity = "0.6"; btn.textContent = "Enviando..."; }
    if (notice) { notice.className = "mdfe-inc-notice warn"; notice.textContent = "Enviando a SEFAZ, aguarde..."; }

    fetch("'.dol_escape_js(dol_buildpath('/custom/mdfe/mdfe_incluir_dfe.php', 1)).'", {
        method: "POST",
        headers: { "X-Requested-With": "XMLHttpRequest", "Content-Type": "application/x-www-form-urlencoded" },
        credentials: "same-origin",
        body: "action=incluir_dfe"
            + "&token=" + encodeURIComponent("'.newToken().'")
            + "&id=" + encodeURIComponent(_mdfeIncluirDfeId)
            + "&uf_carrega=" + encodeURIComponent(ufCarrega)
            + "&mun_carrega=" + encodeURIComponent(munCarrega)
            + "&uf_descarga=" + encodeURIComponent(ufDescarga)
            + "&mun_descarga=" + encodeURIComponent(munDescarga)
            + "&chNFe=" + encodeURIComponent(chNFe)
    })
    .then(function(r){ if (!r.ok) throw new Error("Erro HTTP " + r.status); return r.json(); })
    .then(function(data) {
        if (data.success) {
            closeIncluirDfeModal();
            window.location.reload();
        } else {
            if (notice) { notice.className = "mdfe-inc-notice error"; notice.textContent = data.error || "Erro desconhecido ao incluir NF-e."; }
            if (btn) { btn.disabled = false; btn.style.opacity = ""; btn.textContent = "Tentar Novamente"; }
        }
    })
    .catch(function(err) {
        if (notice) { notice.className = "mdfe-inc-notice error"; notice.textContent = "Erro de comunicacao: " + ((err&&err.message)||"desconhecido"); }
        if (btn) { btn.disabled = false; btn.style.opacity = ""; btn.textContent = "Tentar Novamente"; }
    });
}

// Fecha modal Incluir DF-e ao clicar fora ou ESC
document.addEventListener("click", function(e) {
    var overlay = document.getElementById("mdfeIncluirDfeModal");
    if (overlay && e.target === overlay) closeIncluirDfeModal();
});
document.addEventListener("keydown", function(e) {
    if ((e.key === "Escape" || e.key === "Esc") && _mdfeIncluirDfeId !== null) closeIncluirDfeModal();
});

// === Inclusão de Condutor em MDF-e autorizada ===
var _mdfeIncluirCondutorId = null;

function formatarCpfInput(el) {
    var v = el.value.replace(/\D/g, "");
    if (v.length > 11) v = v.substring(0, 11);
    if (v.length > 9) {
        v = v.replace(/(\d{3})(\d{3})(\d{3})(\d{1,2})/, "$1.$2.$3-$4");
    } else if (v.length > 6) {
        v = v.replace(/(\d{3})(\d{3})(\d{1,3})/, "$1.$2.$3");
    } else if (v.length > 3) {
        v = v.replace(/(\d{3})(\d{1,3})/, "$1.$2");
    }
    el.value = v;
}

function openIncluirCondutorModal(id) {
    document.querySelectorAll(".nfe-dropdown-menu").forEach(function(m){ m.style.display = "none"; });
    _mdfeIncluirCondutorId = id;
    // Reset campos
    var notice = document.getElementById("incCondNotice");
    if (notice) { notice.className = "mdfe-cond-notice warn"; notice.innerHTML = "Informe o nome e CPF do condutor a ser inclu\u00eddo na MDF-e."; }
    document.getElementById("incCondNome").value = "";
    document.getElementById("incCondCpf").value = "";
    var btn = document.getElementById("incCondEnviarBtn");
    if (btn) { btn.disabled = false; btn.style.opacity = ""; btn.textContent = "Incluir Condutor"; }
    var overlay = document.getElementById("mdfeIncluirCondutorModal");
    if (overlay) overlay.classList.add("visible");
}

function closeIncluirCondutorModal() {
    var overlay = document.getElementById("mdfeIncluirCondutorModal");
    if (overlay) overlay.classList.remove("visible");
    _mdfeIncluirCondutorId = null;
}

function confirmarIncluirCondutor() {
    if (!_mdfeIncluirCondutorId) return;
    var notice = document.getElementById("incCondNotice");
    var btn    = document.getElementById("incCondEnviarBtn");

    var xNome = (document.getElementById("incCondNome").value || "").trim();
    var cpfRaw = (document.getElementById("incCondCpf").value || "").replace(/\D/g, "");

    // Validação front-end
    if (!xNome || xNome.length < 2) {
        if (notice) { notice.className = "mdfe-cond-notice error"; notice.textContent = "Informe o nome do condutor (m\u00ednimo 2 caracteres)."; }
        document.getElementById("incCondNome").focus();
        return;
    }
    if (cpfRaw.length !== 11 || !/^[0-9]{11}$/.test(cpfRaw)) {
        if (notice) { notice.className = "mdfe-cond-notice error"; notice.textContent = "O CPF deve ter exatamente 11 d\u00edgitos num\u00e9ricos."; }
        document.getElementById("incCondCpf").focus();
        return;
    }

    if (btn) { btn.disabled = true; btn.style.opacity = "0.6"; btn.textContent = "Enviando..."; }
    if (notice) { notice.className = "mdfe-cond-notice warn"; notice.textContent = "Enviando evento \u00e0 SEFAZ, aguarde..."; }

    fetch("'.dol_escape_js(dol_buildpath('/custom/mdfe/mdfe_incluir_condutor.php', 1)).'", {
        method: "POST",
        headers: { "X-Requested-With": "XMLHttpRequest", "Content-Type": "application/x-www-form-urlencoded" },
        credentials: "same-origin",
        body: "action=incluir_condutor"
            + "&token=" + encodeURIComponent("'.newToken().'")
            + "&id=" + encodeURIComponent(_mdfeIncluirCondutorId)
            + "&xNome=" + encodeURIComponent(xNome)
            + "&cpf=" + encodeURIComponent(cpfRaw)
    })
    .then(function(r){ if (!r.ok) throw new Error("Erro HTTP " + r.status); return r.json(); })
    .then(function(data) {
        if (data.success) {
            closeIncluirCondutorModal();
            window.location.reload();
        } else {
            if (notice) { notice.className = "mdfe-cond-notice error"; notice.textContent = data.error || "Erro desconhecido ao incluir condutor."; }
            if (btn) { btn.disabled = false; btn.style.opacity = ""; btn.textContent = "Tentar Novamente"; }
        }
    })
    .catch(function(err) {
        if (notice) { notice.className = "mdfe-cond-notice error"; notice.textContent = "Erro de comunica\u00e7\u00e3o: " + ((err&&err.message)||"desconhecido"); }
        if (btn) { btn.disabled = false; btn.style.opacity = ""; btn.textContent = "Tentar Novamente"; }
    });
}

// Fecha modal Incluir Condutor ao clicar fora ou ESC
document.addEventListener("click", function(e) {
    var overlay = document.getElementById("mdfeIncluirCondutorModal");
    if (overlay && e.target === overlay) closeIncluirCondutorModal();
});
document.addEventListener("keydown", function(e) {
    if ((e.key === "Escape" || e.key === "Esc") && _mdfeIncluirCondutorId !== null) closeIncluirCondutorModal();
});

// === Filtro de Status (popover) ===
function toggleStatusFilter(e){
    e.stopPropagation();
    var pop = document.getElementById("statusFilterPopover");
    if (!pop) return;
    var open = pop.classList.contains("visible");
    document.querySelectorAll(".nfse-status-filter-popover").forEach(function(p){ p.classList.remove("visible"); });
    pop.classList.toggle("visible", !open);
}
function applyStatusFilter(){
    var pop = document.getElementById("statusFilterPopover");
    var hidden = document.getElementById("search_status_list");
    var form = document.getElementById("nfseListFilterForm");
    if (!pop || !hidden || !form) return;
    var vals = Array.from(pop.querySelectorAll("input[type=checkbox]:checked")).map(function(i){ return i.value; });
    hidden.value = vals.join(",");
    form.submit();
}
function clearStatusFilter(){
    var pop = document.getElementById("statusFilterPopover");
    var hidden = document.getElementById("search_status_list");
    if (!pop || !hidden) return;
    pop.querySelectorAll("input[type=checkbox]").forEach(function(i){ i.checked = false; });
    hidden.value = "";
    var badge = document.getElementById("statusSelCount");
    if (badge) badge.remove();
}

// Fecha popover ao clicar fora ou ESC
document.addEventListener("click", function(e){
    if (!e.target.closest(".status-filter-cell")) {
        document.querySelectorAll(".nfse-status-filter-popover").forEach(function(p){ p.classList.remove("visible"); });
    }
});
document.addEventListener("keydown", function(e){
    if (e.key === "Escape" || e.key === "Esc") {
        document.querySelectorAll(".nfse-status-filter-popover").forEach(function(p){ p.classList.remove("visible"); });
    }
});

// Atualiza badge ao abrir (opcional)
document.addEventListener("DOMContentLoaded", function(){
    var pop = document.getElementById("statusFilterPopover");
    var badge = document.getElementById("statusSelCount");
    if (pop) {
        var cnt = pop.querySelectorAll("input[type=checkbox]:checked").length;
        if (cnt > 0 && !badge) {
            var btn = document.querySelector(".nfse-status-filter-toggle");
            if (btn){
                var b = document.createElement("span");
                b.id = "statusSelCount"; b.className = "badge"; b.textContent = cnt;
                btn.appendChild(b);
            }
        }
    }
});
</script>';

// Fecha a página corretamente
llxFooter();
?>