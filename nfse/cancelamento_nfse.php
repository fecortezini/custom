<?php
// Este arquivo é esperado ser incluído por nfse_list.php (que já carregou main.inc.php)
// Força uso do $db e outras variáveis globais do Dolibarr
global $db, $langs, $conf;

/** @var DoliDB $db */
/** @var Translate $langs */
/** @var Conf $conf */

// Garantia: carrega funções úteis de emissão/consulta
require_once DOL_DOCUMENT_ROOT . '/custom/nfse/emissao_nfse.php';

$action = GETPOST('action', 'alpha');

if ($action === 'mostrar_cancelamento') {
    $id = (int) GETPOST('id', 'int');

    error_log("[NFSe Cancelamento] Buscando NFSe ID: $id");

    if (empty($id)) {
        header('Content-Type: text/html; charset=UTF-8');
        echo '<div class="nfse-alert nfse-alert-error">ID inválido.</div>';
        exit;
    }

    // Removido `data_hora_envio` (coluna deletada)
    $sql = "SELECT id, numero_nota, status, protocolo, numero_lote, prestador_cnpj, cod_muni_prestado, 
                   prestador_im, data_hora_emissao, tomador_nome, 
                   cod_servico_prestado, valor_servicos, id_nfse_substituida
            FROM ".MAIN_DB_PREFIX."nfse_emitidas WHERE id = ".$id;
    $res = $db->query($sql);

    header('Content-Type: text/html; charset=UTF-8');

    if (!$res) {
        error_log("[NFSe Cancelamento] Erro na consulta: " . $db->lasterror());
        echo '<div class="nfse-alert nfse-alert-error">Erro ao consultar: ' . $db->lasterror() . '</div>';
        exit;
    }

    if ($res && $db->num_rows($res) > 0 && $obj = $db->fetch_object($res)) {
        error_log("[NFSe Cancelamento] Número da nota no banco: " . var_export($obj->numero_nota, true) . 
                  " | É substituta: " . ($obj->id_nfse_substituida ? 'SIM (substituiu ID '.$obj->id_nfse_substituida.')' : 'NÃO'));

        if (empty($obj->numero_nota) || !is_numeric($obj->numero_nota)) {
            echo '<div class="nfse-alert nfse-alert-error">
                <strong>Erro:</strong> Esta NFSe não possui número de nota válido no banco de dados.
                <br><br>
                <strong>Possíveis causas:</strong>
                <ul style="text-align: left; margin: 10px 0;">
                    <li>A NFSe foi emitida com sucesso mas houve falha ao salvar o número</li>
                    <li>O número da nota está vazio ou inválido (valor atual: "'.dol_escape_htmltag($obj->numero_nota ?? 'vazio').'")</li>
                    <li>Houve erro no processamento da resposta da prefeitura</li>
                </ul>
                <strong>Solução:</strong> Use a opção "Consultar" para verificar o número correto na prefeitura e, se necessário, atualize manualmente o registro no banco de dados.
                <br><br>
                <strong>Dados disponíveis:</strong><br>
                <small>
                Protocolo: '.dol_escape_htmltag($obj->protocolo ?: 'N/A').'<br>
                Lote: '.dol_escape_htmltag($obj->numero_lote ?: 'N/A').'<br>
                Status: '.dol_escape_htmltag($obj->status).'
                </small>
            </div>
            <div style="text-align:center; margin-top:20px;">
                <button class="butAction" onclick="closeNfseModal()">Fechar</button>
            </div>';
            exit;
        }

        if (empty($obj->prestador_cnpj)) {
            error_log("[NFSe Cancelamento] CNPJ do prestador vazio!");
            echo '<div class="nfse-alert nfse-alert-error">CNPJ do prestador não encontrado.</div>';
            exit;
        }

        $codigoMunicipio = !empty($obj->cod_muni_prestado) ? $obj->cod_muni_prestado : '3201209';

        // Usa somente data_hora_emissao (data_hora_envio foi removida da tabela)
        $dataEmissao = '';
        if (!empty($obj->data_hora_emissao)) {
            $dataEmissao = date('d/m/Y', strtotime($obj->data_hora_emissao));
        }

        echo '<div class="nfse-card">
            <div class="nfse-card-header">Detalhes da NFSe</div>
            <div class="nfse-card-body nfse-data-grid-3">
                <div class="nfse-data-item">
                    <div class="nfse-data-label">Número NFSe</div>
                    <div class="nfse-data-value">'.dol_escape_htmltag($obj->numero_nota).'</div>
                </div>
                <div class="nfse-data-item">
                    <div class="nfse-data-label">Lote</div>
                    <div class="nfse-data-value">'.dol_escape_htmltag($obj->numero_lote ?: 'N/A').'</div>
                </div>
                <div class="nfse-data-item">
                    <div class="nfse-data-label">Data Emissão</div>
                    <div class="nfse-data-value">'.($dataEmissao ?: 'N/A').'</div>
                </div>
                <div class="nfse-data-item">
                    <div class="nfse-data-label">Tomador</div>
                    <div class="nfse-data-value">'.dol_escape_htmltag($obj->tomador_nome).'</div>
                </div>
                <div class="nfse-data-item">
                    <div class="nfse-data-label">Código Serviço</div>
                    <div class="nfse-data-value">'.dol_escape_htmltag($obj->cod_servico_prestado ?: 'N/A').'</div>
                </div>
                <div class="nfse-data-item">
                    <div class="nfse-data-label">Valor</div>
                    <div class="nfse-data-value">R$ '.price($obj->valor_servicos).'</div>
                </div>
            </div>
        </div>

        <div class="nfse-card">
            <div class="nfse-card-header">Motivo do Cancelamento</div>
            <div class="nfse-card-body">
                <form id="formCancelamento" method="post">
                    <input type="hidden" name="id_nfse" value="'.$id.'">
                    <input type="hidden" name="numero_nota" value="'.$obj->numero_nota.'">
                    <input type="hidden" name="prestador_cnpj" value="'.$obj->prestador_cnpj.'">
                    <input type="hidden" name="codigo_municipio" value="'.$codigoMunicipio.'">
                    <input type="hidden" name="token" value="'.newToken().'">

                    <div class="nfse-data-item full-width">
                        <div class="nfse-data-label">Código do Motivo</div>
                        <div class="nfse-data-value">
                            <select name="codigo_cancelamento" class="flat" required>
                                <option value="">Selecione</option>
                                <option value="1">Erro na emissão</option>
                                <option value="2">Serviço não prestado</option>
                                <option value="3">Erro de Assinatura</option>
                                <option value="4">Duplicidade da nota</option>
                                <option value="5">Outros</option>
                            </select>
                        </div>
                    </div>

                    <div class="nfse-data-item full-width">
                        <div class="nfse-data-label">Descrição (opcional)</div>
                        <div class="nfse-data-value">
                            <textarea name="descricao_cancelamento" rows="3" class="flat" placeholder="Descreva o motivo do cancelamento..."></textarea>
                        </div>
                    </div>

                    <div style="margin-top:20px; text-align:center;">
                        <button type="button" class="butActionDelete" onclick="closeNfseModal()">FECHAR</button>
                        <button type="button" class="butAction" onclick="processarCancelamento()">Confirmar Cancelamento</button>
                    </div>
                </form>
            </div>
        </div>';
    } else {
        error_log("[NFSe Cancelamento] Nenhum registro encontrado para ID: $id");
        echo '<div class="nfse-alert nfse-alert-error">NFSe não encontrada (ID: '.$id.').</div>';
    }
    exit;
}

if ($action === 'processar_cancelamento') {
    require_once DOL_DOCUMENT_ROOT . '/core/lib/json.lib.php';

    ob_clean();
    while (ob_get_level()) ob_end_clean();

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');

    $response = array('success' => false, 'error' => '');

    try {
        $id_nfse = GETPOST('id_nfse', 'int');
        $numeroNfse = GETPOST('numero_nota', 'alpha');
        $cnpjPrestador = GETPOST('prestador_cnpj', 'alpha');
        $codigoMunicipio = GETPOST('codigo_municipio', 'alpha');
        $codigoCancelamento = GETPOST('codigo_cancelamento', 'alpha');
        $descricao = GETPOST('descricao_cancelamento', 'alpha');

        error_log("[NFSe Cancelamento] Processando: id_nfse={$id_nfse} numero_nota={$numeroNfse} cnpj={$cnpjPrestador} municipio={$codigoMunicipio} codigo_cancelamento={$codigoCancelamento}");

        $camposFaltantes = [];
        if (empty($id_nfse)) $camposFaltantes[] = 'ID NFSe';
        if (empty($numeroNfse) || !is_numeric($numeroNfse)) $camposFaltantes[] = 'Número da Nota (valor: "'.var_export($numeroNfse, true).'")';
        if (empty($cnpjPrestador)) $camposFaltantes[] = 'CNPJ do Prestador';
        if (empty($codigoMunicipio)) $camposFaltantes[] = 'Código do Município';
        if (empty($codigoCancelamento)) $camposFaltantes[] = 'Código de Cancelamento';

        if (!empty($camposFaltantes)) {
            throw new Exception("Dados incompletos. Campos faltantes: " . implode(', ', $camposFaltantes));
        }

        $cnpjPrestador = preg_replace('/\D/', '', $cnpjPrestador);
        if (strlen($cnpjPrestador) !== 14) {
            throw new Exception("CNPJ do prestador inválido: deve conter 14 dígitos (atual: ".strlen($cnpjPrestador).")");
        }

        if (!preg_match('/^\d+$/', $codigoMunicipio)) {
            throw new Exception("Código de município inválido: deve conter apenas números");
        }

        // Carrega o certificado A1 a partir do banco (função em emissao_nfse.php)
        $cert = carregarCertificadoA1FromDB($db);

        // Configuração SOAP e montagem do XML de cancelamento
        $cabecalho = '<cabecalho xmlns="http://www.abrasf.org.br/nfse.xsd"><versaoDados>2.04</versaoDados></cabecalho>';
        $wsdl = 'https://notafse-backend.cachoeiro.es.gov.br/nfse/NfseWSService?wsdl';

        $xmlCancelamento = '<CancelarNfseEnvio xmlns="http://www.abrasf.org.br/nfse.xsd">' . "\n";
        $xmlCancelamento .= '  <Pedido>' . "\n";
        $xmlCancelamento .= '    <InfPedidoCancelamento Id="C' . $numeroNfse . '">' . "\n";
        $xmlCancelamento .= '      <IdentificacaoNfse>' . "\n";
        $xmlCancelamento .= '        <Numero>' . $numeroNfse . '</Numero>' . "\n";
        $xmlCancelamento .= '        <CpfCnpj>' . "\n";
        $xmlCancelamento .= '          <Cnpj>' . preg_replace('/\D/', '', $cnpjPrestador) . '</Cnpj>' . "\n";
        $xmlCancelamento .= '        </CpfCnpj>' . "\n";
        $xmlCancelamento .= '        <CodigoMunicipio>' . $codigoMunicipio . '</CodigoMunicipio>' . "\n";
        $xmlCancelamento .= '      </IdentificacaoNfse>' . "\n";
        $xmlCancelamento .= '      <CodigoCancelamento>' . $codigoCancelamento . '</CodigoCancelamento>' . "\n";
        $xmlCancelamento .= '    </InfPedidoCancelamento>' . "\n";
        $xmlCancelamento .= '  </Pedido>' . "\n";
        $xmlCancelamento .= '</CancelarNfseEnvio>';

        $envelopeCancelar = '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:nfse="http://nfse.abrasf.org.br">'.
            '<soapenv:Header/>'.
            '<soapenv:Body>'.
            '<nfse:CancelarNfse>'.
                '<nfse:CancelarNfseRequest>'.
                    '<nfseCabecMsg><![CDATA[ '.$cabecalho.' ]]></nfseCabecMsg>'.
                    '<nfseDadosMsg><![CDATA[ '.$xmlCancelamento.' ]]></nfseDadosMsg>'.
                '</nfse:CancelarNfseRequest>'.
            '</nfse:CancelarNfse>'.
            '</soapenv:Body>'.
        '</soapenv:Envelope>';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $wsdl,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: text/xml; charset=UTF-8',
                'SOAPAction: ""'
            ],
            CURLOPT_POSTFIELDS => $envelopeCancelar,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSLCERT => $cert['pem_path'],
            CURLOPT_SSLKEY  => $cert['pem_path'],
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ]);

        $respostaSoap = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (isset($cert['pem_path']) && file_exists($cert['pem_path'])) {
            unlink($cert['pem_path']);
        }

        if ($curlError) {
            throw new Exception("Erro na comunicação: " . $curlError);
        }

        if ($httpCode != 200) {
            throw new Exception("Erro HTTP: " . $httpCode);
        }

        if (empty($respostaSoap)) {
            throw new Exception("Resposta vazia do servidor");
        }

        error_log("[NFSe Cancelamento] Resposta SOAP completa: " . $respostaSoap);

        // Processamento robusto da resposta (DOM + fallback)
        $xmlProcessado = false;
        $xmlConfirmacao = false;
        $jaCancelada = false;
        try {
            $soapResponse = new DOMDocument('1.0', 'UTF-8');
            $soapResponse->loadXML($respostaSoap);

            $outputXMLNode = $soapResponse->getElementsByTagName('outputXML')->item(0);
            if ($outputXMLNode) {
                $outputXML = htmlspecialchars_decode($outputXMLNode->nodeValue);
                $xmlDocument = new DOMDocument('1.0', 'UTF-8');
                $xmlDocument->loadXML($outputXML);
                $xmlProcessado = $xmlDocument->saveXML();

                $confirmacaoNodes = $xmlDocument->getElementsByTagName('Confirmacao');
                if ($confirmacaoNodes->length > 0) {
                    $xmlConfirmacao = true;
                    $dataHoraNodes = $xmlDocument->getElementsByTagName('DataHora');
                    if ($dataHoraNodes->length > 0) {
                        $dataHoraCancelamento = $dataHoraNodes->item(0)->nodeValue;
                    }
                }
            } else {
                $confirmacaoNodes = $soapResponse->getElementsByTagName('Confirmacao');
                if ($confirmacaoNodes->length > 0) {
                    $xmlConfirmacao = true;
                    $xmlProcessado = $respostaSoap;
                    $dataHoraNodes = $soapResponse->getElementsByTagName('DataHora');
                    if ($dataHoraNodes->length > 0) {
                        $dataHoraCancelamento = $dataHoraNodes->item(0)->nodeValue;
                    }
                }
            }
        } catch (Exception $domEx) {
            error_log("[NFSe Cancelamento] Erro no processamento DOM: " . $domEx->getMessage());
        }

        if (!$xmlProcessado) {
            if (preg_match('/<outputXML>(.*?)<\/outputXML>/s', $respostaSoap, $matches)) {
                $xmlResposta = htmlspecialchars_decode(trim($matches[1]));
            } elseif (preg_match('/<nfseDadosMsg>(.*?)<\/nfseDadosMsg>/s', $respostaSoap, $matches)) {
                $xmlResposta = trim($matches[1]);
                $xmlResposta = preg_replace('/<!\[CDATA\[(.*?)\]\]>/s', '$1', $xmlResposta);
            } else {
                $xmlResposta = $respostaSoap;
            }

            $xmlConfirmacao = (
                strpos($xmlResposta, 'CancelarNfseResposta') !== false &&
                strpos($xmlResposta, 'Confirmacao') !== false &&
                strpos($xmlResposta, 'MensagemRetorno') === false
            );

            if (preg_match('/<DataHora>(.*?)<\/DataHora>/s', $xmlResposta, $dataMatches)) {
                $dataHoraCancelamento = $dataMatches[1];
            }

            $xmlProcessado = $xmlResposta;
        }

        if (strpos($xmlProcessado, '<Codigo>') !== false && preg_match('/<Codigo>(.*?)<\/Codigo>/s', $xmlProcessado, $errorMatches)) {
            preg_match('/<Mensagem>(.*?)<\/Mensagem>/s', $xmlProcessado, $msgMatches);
            $codigoErro = trim($errorMatches[1]);
            $mensagemErro = !empty($msgMatches[1]) ? trim($msgMatches[1]) : '';

            if ($codigoErro === 'EL73' || preg_match('/já encontra-se cancelada|ja encontra-se cancelada/i', $mensagemErro)) {
                $jaCancelada = true;
            } else {
                throw new Exception("Erro no cancelamento: " . $codigoErro . ($mensagemErro ? " - " . $mensagemErro : ""));
            }
        }

        if ($xmlConfirmacao) {
            $dataHoraFormatada = $dataHoraCancelamento ?? 'não informada';

            $sqlProtocolo = "SELECT protocolo FROM ".MAIN_DB_PREFIX."nfse_emitidas WHERE id = ".$id_nfse;
            $resProtocolo = $db->query($sqlProtocolo);
            $protocoloOriginal = '';
            if ($resProtocolo && $db->num_rows($resProtocolo) > 0) {
                $objProto = $db->fetch_object($resProtocolo);
                $protocoloOriginal = $objProto->protocolo;
            }
            
            $sql = "UPDATE ".MAIN_DB_PREFIX."nfse_emitidas 
                    SET status = 'CANCELADA',
                        mensagem_retorno = '".$db->escape("Cancelada por: Código $codigoCancelamento - $descricao. Data: $dataHoraFormatada")."',
                        protocolo = '".$db->escape($protocoloOriginal)."'
                    WHERE id = ".$id_nfse;

            $resultUpdate = $db->query($sql);

            if (!$resultUpdate) {
                throw new Exception("Cancelamento realizado, mas houve um erro ao atualizar o registro: " . $db->lasterror());
            }

            // Registra data/hora do pedido/resposta para preencher campo NOT NULL data_hora_pedido
            $dataHoraPedido = date('Y-m-d H:i:s');
            $dataHoraResposta = $dataHoraResposta ?? date('Y-m-d H:i:s');

            $sqlEvento = "INSERT INTO ".MAIN_DB_PREFIX."nfse_eventos 
                (id_nfse_emitida, tipo_evento, protocolo, numero_nfse, codigo_cancelamento, 
                 motivo, status_evento, mensagem_retorno, xml_enviado, xml_recebido, cnpj_prestador, codigo_municipio, data_hora_pedido, data_hora_resposta)
                VALUES (
                    ".(int)$id_nfse.",
                    'CANCELAMENTO',
                    '".$db->escape($protocoloOriginal)."',
                    '".$db->escape($numeroNfse)."',
                    '".$db->escape($codigoCancelamento)."',
                    '".$db->escape($descricao)."',
                    'APROVADO',
                    '".$db->escape('Cancelamento aprovado pela prefeitura')."',
                    '".$db->escape($xmlCancelamento)."',
                    '".$db->escape($xmlProcessado)."',
                    '".$db->escape($cnpjPrestador)."',
                    '".$db->escape($codigoMunicipio)."',
                    '".$db->escape($dataHoraPedido)."',
                    '".$db->escape($dataHoraResposta)."'
                )";
            $resEvt = $db->query($sqlEvento);
            if (!$resEvt) {
                $err = $db->lasterror();
                error_log('[NFSE CANCELAMENTO] Falha ao inserir nfse_eventos: '.$err.' | SQL: '.$sqlEvento);
                // Tenta INSERT de fallback (campos essenciais, incluindo data_hora_pedido)
                $sqlEventoFb = "INSERT INTO ".MAIN_DB_PREFIX."nfse_eventos 
                    (id_nfse_emitida, tipo_evento, protocolo, numero_nfse, codigo_cancelamento, motivo, status_evento, mensagem_retorno, cnpj_prestador, codigo_municipio, data_hora_pedido)
                    VALUES (
                        ".(int)$id_nfse.",
                        'CANCELAMENTO',
                        '".$db->escape($protocoloOriginal)."',
                        '".$db->escape($numeroNfse)."',
                        '".$db->escape($codigoCancelamento)."',
                        '".$db->escape(substr($descricao,0,250))."',
                        'APROVADO',
                        '".$db->escape('Cancelamento aprovado - evento parcial registrado (fallback)')."',
                        '".$db->escape($cnpjPrestador)."',
                        '".$db->escape($codigoMunicipio)."',
                        '".$db->escape($dataHoraPedido)."'
                    )";
                $resFb = $db->query($sqlEventoFb);
                if (!$resFb) {
                    error_log('[NFSE CANCELAMENTO] Fallback também falhou: '.$db->lasterror().' | SQL: '.$sqlEventoFb);
                    $response['warning'] = 'Falha ao registrar evento de cancelamento (ver logs)';
                } else {
                    error_log('[NFSE CANCELAMENTO] Evento de cancelamento registrado via fallback para NFSe ID: '.$id_nfse);
                }
            }

            error_log("[NFSe Cancelamento] NFSe ID: $id_nfse cancelada com sucesso. Data: $dataHoraFormatada");

            $_SESSION['nfse_flash_message'] = array(
                'type' => 'mesgs',
                'message' => 'NFSe cancelada com sucesso!'
            );

            $response['success'] = true;
            $response['message'] = "NFSe cancelada com sucesso!";
            $response['data_cancelamento'] = $dataHoraCancelamento ?? '';

            // Executa consulta "por baixo dos panos" para atualizar xml_atualizado
            try {
                $resConsulta = consultarLoteRpsPorId($db, (int)$id_nfse);
                if (empty($resConsulta['success'])) {
                    error_log('[NFSe Cancelamento] Consulta pós-cancelamento falhou para id '.$id_nfse.': '.($resConsulta['error'] ?? 'erro desconhecido'));
                    $response['warning'] = 'Cancelamento realizado, mas falha ao atualizar XML via consulta (ver logs).';
                } else {
                    error_log('[NFSe Cancelamento] Consulta pós-cancelamento executada com sucesso para id '.$id_nfse);
                }
            } catch (Exception $ex) {
                error_log('[NFSe Cancelamento] Exceção durante consulta pós-cancelamento para id '.$id_nfse.': '.$ex->getMessage());
                $response['warning'] = 'Cancelamento realizado, mas ocorreu exceção ao atualizar XML (ver logs).';
            }

        } else {
            if ($jaCancelada) {
                error_log("[NFSe Cancelamento] Prefeitura informou que a NFSe já está cancelada. Atualizando localmente. ID: $id_nfse");

                $sqlProtocolo = "SELECT protocolo FROM ".MAIN_DB_PREFIX."nfse_emitidas WHERE id = ".$id_nfse;
                $resProtocolo = $db->query($sqlProtocolo);
                $protocoloOriginal = '';
                if ($resProtocolo && $db->num_rows($resProtocolo) > 0) {
                    $objProto = $db->fetch_object($resProtocolo);
                    $protocoloOriginal = $objProto->protocolo;
                }

                $sqlUpdate = "UPDATE ".MAIN_DB_PREFIX."nfse_emitidas 
                        SET status = 'CANCELADA',
                            mensagem_retorno = '".$db->escape("Essa nota ja esta cancelada!")."',
                            xml_recebido = '".$db->escape($xmlProcessado)."',
                            protocolo = '".$db->escape($protocoloOriginal)."'
                        WHERE id = ".$id_nfse;
                $resUpdate = $db->query($sqlUpdate);
                if (!$resUpdate) {
                    throw new Exception("Resposta da prefeitura indica NFSe já cancelada, mas falha ao atualizar o registro: " . $db->lasterror());
                }

                // Insere evento indicando que a prefeitura já informou cancelamento
                $dataHoraPedido = date('Y-m-d H:i:s');
                $dataHoraResposta = date('Y-m-d H:i:s');
                $sqlEvento = "INSERT INTO ".MAIN_DB_PREFIX."nfse_eventos 
                    (id_nfse_emitida, tipo_evento, protocolo, numero_nfse, codigo_cancelamento, motivo, data_hora_pedido, data_hora_resposta, status_evento, mensagem_retorno, xml_enviado, xml_recebido, cnpj_prestador, codigo_municipio)
                    VALUES (
                        ".(int)$id_nfse.",
                        'CANCELAMENTO',
                        '".$db->escape($protocoloOriginal)."',
                        '".$db->escape($numeroNfse)."',
                        '".$db->escape($codigoCancelamento)."',
                        '".$db->escape($descricao)."',
                        '".$db->escape($dataHoraPedido)."',
                        '".$db->escape($dataHoraResposta)."',
                        'APROVADO',
                        '".$db->escape("Essa nota ja esta cancelada!")."',
                        '".$db->escape($xmlCancelamento)."',
                        '".$db->escape($xmlProcessado)."',
                        '".$db->escape($cnpjPrestador)."',
                        '".$db->escape($codigoMunicipio)."'
                    )";
                $resEvt2 = $db->query($sqlEvento);
                if (!$resEvt2) {
                    error_log('[NFSE CANCELAMENTO] Falha ao inserir nfse_eventos (já cancelada): '.$db->lasterror().' | SQL: '.$sqlEvento);
                    // tenta fallback simples incluindo data_hora_pedido
                    $sqlEventoFb2 = "INSERT INTO ".MAIN_DB_PREFIX."nfse_eventos 
                        (id_nfse_emitida, tipo_evento, protocolo, numero_nfse, codigo_cancelamento, motivo, status_evento, mensagem_retorno, cnpj_prestador, codigo_municipio, data_hora_pedido)
                        VALUES (
                            ".(int)$id_nfse.",
                            'CANCELAMENTO',
                            '".$db->escape($protocoloOriginal)."',
                            '".$db->escape($numeroNfse)."',
                            '".$db->escape($codigoCancelamento)."',
                            '".$db->escape(substr($descricao,0,250))."',
                            'APROVADO',
                            '".$db->escape("Essa nota ja esta cancelada! (fallback)")."',
                            '".$db->escape($cnpjPrestador)."',
                            '".$db->escape($codigoMunicipio)."',
                            '".$db->escape($dataHoraPedido)."'
                        )";
                    $resFb2 = $db->query($sqlEventoFb2);
                    if (!$resFb2) {
                        error_log('[NFSE CANCELAMENTO] Fallback também falhou (já cancelada): '.$db->lasterror().' | SQL: '.$sqlEventoFb2);
                        $response['warning'] = 'Falha ao registrar evento de NFSe já cancelada (ver logs)';
                    } else {
                        error_log('[NFSE CANCELAMENTO] Evento (já cancelada) registrado via fallback para NFSe ID: '.$id_nfse);
                    }
                }

                $response['success'] = false;
                $response['error'] = 'Essa nota ja esta cancelada!';
            } else {
                $response['debug'] = [
                    'resposta_processada' => htmlspecialchars(substr($xmlProcessado, 0, 500))
                ];

                throw new Exception("Não foi possível confirmar o cancelamento da NFSe. Entre em contato com o suporte.");
            }
        }
    } catch (Exception $e) {
        $_SESSION['nfse_flash_message'] = array(
            'type' => 'errors',
            'message' => $e->getMessage()
        );

        $response['success'] = false;
        $response['error'] = $e->getMessage();

        error_log("[NFSe Cancelamento] Exceção: " . $e->getMessage() . "\n" . $e->getTraceAsString());
    }

    echo json_encode($response);
    exit;
}

// Se chegar aqui, action não reconhecida — nada a fazer.
return;

/**
 * Carrega certificado A1 armazenado na tabela de configuração do sistema.
 * Retorna array('private_key','public_cert','pem_path').
 */


/** Extrai IM do XML enviado (fallback caso não haja coluna específica) */
function nfse_extract_im_from_xml_enviado($xmlEnviado) {
    $xml = simplexml_load_string($xmlEnviado);
    if ($xml && isset($xml->InfNfse->Prestador->Im)) {
        return (string)$xml->InfNfse->Prestador->Im;
    }
    return '';
}

/** Extrai IM do XML recebido (dentro do SOAP -> outputXML) */
function nfse_extract_im_from_xml_recebido($xmlRecebido) {
    $xml = simplexml_load_string($xmlRecebido);
    if ($xml && isset($xml->Confirmacao->Im)) {
        return (string)$xml->Confirmacao->Im;
    }
    return '';
}

/** Monta o cabecalho conforme padrão simples (igual à emissão) */
function nfse_build_consulta_cabecalho_xml() {
    return '<cabecalho xmlns="http://www.abrasf.org.br/nfse.xsd"><versaoDados>2.04</versaoDados></cabecalho>';
}

/** Monta o XML de dados da consulta por RPS */
function nfse_build_consulta_dados_xml($cnpj, $numeroRps) {
    $cnpj = preg_replace('/\D/', '', trim($cnpj));
    $numeroRps = trim($numeroRps);
    return '<?xml version="1.0" encoding="UTF-8"?>' . '<ConsultarNfseRpsEnvio xmlns="http://www.abrasf.org.br/nfse.xsd">' . '<IdentificacaoRps>' . '<Numero>'.$numeroRps.'</Numero>' . '<Serie>1</Serie>' . '<Tipo>1</Tipo>' . '</IdentificacaoRps>' . '<Prestador>' . '<CpfCnpj><Cnpj>'.$cnpj.'</Cnpj></CpfCnpj>' . '</Prestador>' . '</ConsultarNfseRpsEnvio>';
}

/** Parseia o outputXML e retorna dados estruturados para exibição */
function nfse_parse_consulta_output_xml($outputXML) {
    $xml = simplexml_load_string($outputXML);
    if ($xml && isset($xml->InfNfse)) {
        $dados = $xml->InfNfse;
        return array(
            'numero' => (string)$dados->Numero,
            'serie' => (string)$dados->Serie,
            'tipo' => (string)$dados->Tipo,
            'dataEmissao' => (string)$dados->DataEmissao,
            'status' => (string)$dados->Status,
            'motivo' => (string)$dados->Motivo,
            'valorServicos' => (string)$dados->ValorServicos,
            'valorDeducoes' => (string)$dados->ValorDeducoes,
            'valorPis' => (string)$dados->ValorPis,
            'valorCofins' => (string)$dados->ValorCofins,
            'valorInss' => (string)$dados->ValorInss,
            'valorIr' => (string)$dados->ValorIr,
            'valorCsll' => (string)$dados->ValorCsll,
            'outrasRetencoes' => (string)$dados->OutrasRetencoes,
            'valorLiquido' => (string)$dados->ValorLiquido,
            'numeroNfseSubstituida' => (string)$dados->NumeroNfseSubstituida,
            'codigoVerificacao' => (string)$dados->CodigoVerificacao
        );
    }
    return array();
}

/** Monta HTML elegante para exibir na modal da lista */
function nfse_render_consulta_html($numeroRps, $dados) {
    $html = '<div class="nfse-consulta-resultado">';
    $html .= '<div class="nfse-consulta-cabecalho">Consulta NFSe por RPS: '.$numeroRps.'</div>';
    $html .= '<div class="nfse-consulta-dados">';

    foreach ($dados as $chave => $valor) {
        $html .= '<div class="nfse-consulta-item">';
        $html .= '<div class="nfse-consulta-label">'.ucfirst($chave).':</div>';
        $html .= '<div class="nfse-consulta-value">'.dol_escape_htmltag($valor).'</div>';
        $html .= '</div>';
    }

    $html .= '</div>';
    $html .= '</div>';

    return $html;
}

/**
 * Consulta a NFSe por RPS
 */
function consultarLoteRpsPorId($db, $idNfse) {
    $sql = "SELECT id, numero_nota, status, protocolo, numero_lote, prestador_cnpj, cod_muni_prestado, 
                   prestador_im, data_hora_emissao, tomador_nome, 
                   cod_servico_prestado, valor_servicos, id_nfse_substituida
            FROM ".MAIN_DB_PREFIX."nfse_emitidas WHERE id = ".$idNfse;
    $res = $db->query($sql);

    if ($res && $db->num_rows($res) > 0) {
        $obj = $db->fetch_object($res);
        return array(
            'success' => true,
            'data' => array(
                'id' => $obj->id,
                'numero_nota' => $obj->numero_nota,
                'status' => $obj->status,
                'protocolo' => $obj->protocolo,
                'numero_lote' => $obj->numero_lote,
                'prestador_cnpj' => $obj->prestador_cnpj,
                'cod_muni_prestado' => $obj->cod_muni_prestado,
                'prestador_im' => $obj->prestador_im,
                'data_hora_emissao' => $obj->data_hora_emissao,
                'tomador_nome' => $obj->tomador_nome,
                'cod_servico_prestado' => $obj->cod_servico_prestado,
                'valor_servicos' => $obj->valor_servicos,
                'id_nfse_substituida' => $obj->id_nfse_substituida
            )
        );
    }

    return array('success' => false, 'error' => 'NFSe não encontrada');
}
?>
