<?php
/** Handler leve para requisição AJAX de consulta (chamado por nfse_list.php?action=consultar) */
/** @var DoliDB $db */
/** @var Translate $langs */

global $db, $langs;

// ADICIONADO: Include do helper de municípios
require_once DOL_DOCUMENT_ROOT . '/custom/nfse/lib/municipios_es.php';

// Apenas execute o bloco "handler" quando o arquivo for chamado diretamente (AJAX).
if (!defined('NFSE_INCLUDE_NO_OUTPUT')) {
    header('Content-Type: text/html; charset=UTF-8');

    $id = (int) GETPOST('id', 'int');

    if ($id <= 0) {
        echo '<div class="nfse-modal-content-wrap"><h3>Consulta de NFS-e</h3><div class="nfse-alert nfse-alert-error">ID inválido.</div></div>';
        return;
    }

    // Adiciona log para debug
    error_log("[NFSe Consulta] Consultando NFSe ID: $id na prefeitura");

    $res = consultarLoteRpsPorId($db, $id);

    if (!empty($res['success'])) {
        error_log("[NFSe Consulta] Consulta bem-sucedida para ID: $id");
        echo $res['html'];
    } else {
        error_log("[NFSe Consulta] Falha na consulta para ID: $id - Erro: " . ($res['error'] ?: 'desconhecido'));
        echo '<div class="nfse-modal-content-wrap">'
           . '<h3>Consulta de NFS-e</h3>'
           . '<div class="nfse-alert nfse-alert-error">'.dol_escape_htmltag($res['error'] ?: 'Falha na consulta.').'</div>'
           . '</div>';
    }
    return;
}

/* ===================== Funções de consulta e helpers ===================== */

/**
 * Carrega certificado A1 armazenado na tabela de configuração do sistema.
 * Retorna array('private_key','public_cert','pem_path').
 */
function carregarCertificadoA1FromDB($db) {
    $triedTables = [];
    $certPfx = null;
    $certPass = null;

    // 1) Tenta tabela key/value: {MAIN_DB_PREFIX}nfe_config (mesmo que emissao_nfe.php)
    $tableKv = (defined('MAIN_DB_PREFIX') ? MAIN_DB_PREFIX : '') . 'nfe_config';
    $triedTables[] = $tableKv;
    $res = @$db->query("SELECT name, value FROM `".$tableKv."`");
    if ($res) {
        $cfg = [];
        while ($o = $db->fetch_object($res)) {
            $cfg[$o->name] = $o->value;
        }
        if (!empty($cfg['cert_pfx'])) $certPfx = $cfg['cert_pfx'];
        if (isset($cfg['cert_pass'])) $certPass = $cfg['cert_pass'];
    }

    // 2) Fallback: tabela com colunas cert_pfx / cert_pass
    if (empty($certPfx)) {
        $tableCols = (defined('MAIN_DB_PREFIX') ? MAIN_DB_PREFIX : '') . 'nfe_config';
        if (!in_array($tableCols, $triedTables)) $triedTables[] = $tableCols;
        $res2 = @$db->query("SELECT cert_pfx, cert_pass FROM `".$tableCols."` LIMIT 1");
        if ($res2 && $db->num_rows($res2) > 0) {
            $row = $db->fetch_object($res2);
            $certPfx = $row->cert_pfx ?? $certPfx;
            $certPass = $row->cert_pass ?? $certPass;
        }
    }

    // Normaliza BLOB/stream
    if (is_resource($certPfx)) {
        $certPfx = stream_get_contents($certPfx);
    }
    if ($certPfx === null || $certPfx === '') {
        $uniq = array_values(array_unique($triedTables));
        throw new Exception('Nenhum certificado encontrado nas tabelas de configuração (busca em: '.implode(', ', $uniq).').');
    }
    $certPass = (string)$certPass;

    // Tenta ler o PFX. Se falhar, tenta base64_decode e tenta novamente.
    $certStore = [];
    if (!@openssl_pkcs12_read($certPfx, $certStore, $certPass)) {
        // tentativa com base64 (algumas instalações armazenam em base64)
        $decoded = @base64_decode($certPfx, true);
        if ($decoded !== false && $decoded !== '') {
            if (!@openssl_pkcs12_read($decoded, $certStore, $certPass)) {
                throw new Exception("Não foi possível ler o certificado PFX (tentativa raw e base64) — verifique armazenamento e senha.");
            }
            $certPfx = $decoded; // usa a versão decodificada
        } else {
            throw new Exception("Não foi possível ler o certificado PFX. Verifique o conteúdo armazenado e a senha.");
        }
    }

    // Salva PEM temporário
    $pemTmp = tempnam(sys_get_temp_dir(), 'cert') . '.pem';
    $pemContent = ($certStore['cert'] ?? '') . ($certStore['pkey'] ?? '');
    file_put_contents($pemTmp, $pemContent);

    return [
        'private_key' => $certStore['pkey'] ?? '',
        'public_cert' => $certStore['cert'] ?? '',
        'pem_path'    => $pemTmp
    ];
}

/** Extrai IM do XML enviado (fallback caso não haja coluna específica) */
function nfse_extract_im_from_xml_enviado($xmlEnviado) {
    if (empty($xmlEnviado)) return null;
    $xml = html_entity_decode($xmlEnviado, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $dom = new DOMDocument();
    if (@$dom->loadXML($xml)) {
        $nodes = $dom->getElementsByTagName('InscricaoMunicipal');
        if ($nodes && $nodes->length) {
            $im = trim($nodes->item(0)->nodeValue);
            return $im ?: null;
        }
    }
    return null;
}

/** Extrai IM do XML recebido (dentro do SOAP -> outputXML) */
function nfse_extract_im_from_xml_recebido($xmlRecebido) {
    if (empty($xmlRecebido)) return null;
    $soap = new DOMDocument();
    if (!@$soap->loadXML($xmlRecebido)) return null;
    $node = $soap->getElementsByTagName('outputXML')->item(0);
    if (!$node) return null;
    $outputXML = htmlspecialchars_decode($node->nodeValue);
    $dom = new DOMDocument();
    if (!@$dom->loadXML($outputXML)) return null;
    $tags = $dom->getElementsByTagName('InscricaoMunicipal');
    if ($tags && $tags->length) {
        $im = trim($tags->item(0)->nodeValue);
        return $im ?: null;
    }
    return null;
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
    $data = ['situacao'=>null,'numero'=>null,'codigo_verificacao'=>null,'data_emissao'=>null,
             'valores'=>[],'prestador'=>[],'tomador'=>[],'rps'=>[],'mensagens'=>[],'cancelamento'=>null];

    $dom = new DOMDocument('1.0', 'UTF-8');
    if (!@$dom->loadXML($outputXML)) {
        $data['mensagens'][] = 'XML de resposta inválido.';
        return $data;
    }

    $getVal = function($parent, $tag) {
        $n = $parent->getElementsByTagName($tag);
        return ($n && $n->length) ? trim($n->item(0)->nodeValue) : null;
    };

    $nfseCancelamento = $dom->getElementsByTagName('NfseCancelamento')->item(0);
    if ($nfseCancelamento) {
        $confirmacao = $nfseCancelamento->getElementsByTagName('Confirmacao')->item(0);
        if ($confirmacao) {
            $dataHoraCancelamento = $getVal($confirmacao, 'DataHora');
            $infPedidoCancelamento = $confirmacao->getElementsByTagName('InfPedidoCancelamento')->item(0);
            $codigoCancelamento = $infPedidoCancelamento ? $getVal($infPedidoCancelamento, 'CodigoCancelamento') : null;
            $data['cancelamento'] = [
                'data_hora' => $dataHoraCancelamento,
                'codigo' => $codigoCancelamento
            ];
        }
    }

    $infNfse = $dom->getElementsByTagName('InfNfse')->item(0);
    if ($infNfse) {
        $data['numero'] = $getVal($infNfse, 'Numero');
        $data['codigo_verificacao'] = $getVal($infNfse, 'CodigoVerificacao');
        $data['data_emissao'] = $getVal($infNfse, 'DataEmissao');
        $statusNfse = $getVal($infNfse, 'Status');
        $data['status_nfse'] = $statusNfse;

        $valNfse = $infNfse->getElementsByTagName('ValoresNfse')->item(0);
        if ($valNfse) {
            $data['valores'] = [
                'base_calculo' => $getVal($valNfse, 'BaseCalculo'),
                'aliquota' => $getVal($valNfse, 'Aliquota'),
                'valor_iss' => $getVal($valNfse, 'ValorIss'),
                'valor_liquido' => $getVal($valNfse, 'ValorLiquidoNfse'),
            ];
        }

        $prest = $infNfse->getElementsByTagName('PrestadorServico')->item(0);
        if ($prest) {
            $data['prestador'] = [
                'razao' => $getVal($prest, 'RazaoSocial'),
                'nome_fantasia' => $getVal($prest, 'NomeFantasia'),
                'uf' => $getVal($prest, 'Uf'),
                'codigo_municipio' => $getVal($prest, 'CodigoMunicipio'),
                'cep' => $getVal($prest, 'Cep'),
            ];
        }

        $decl = $infNfse->getElementsByTagName('DeclaracaoPrestacaoServico')->item(0);
        if ($decl) {
            $infDecl = $decl->getElementsByTagName('InfDeclaracaoPrestacaoServico')->item(0);
            if ($infDecl) {
                $rpsNode = $infDecl->getElementsByTagName('Rps')->item(0);
                if ($rpsNode) {
                    $identRps = $rpsNode->getElementsByTagName('IdentificacaoRps')->item(0);
                    if ($identRps) {
                        $data['rps']['numero_rps'] = $getVal($identRps, 'Numero');
                        $data['rps']['serie_rps'] = $getVal($identRps, 'Serie');
                        $data['rps']['tipo_rps'] = $getVal($identRps, 'Tipo');
                    }
                    $data['rps']['data_emissao_rps'] = $getVal($rpsNode, 'DataEmissao');
                    $data['rps']['status_rps'] = $getVal($rpsNode, 'Status');
                }
                $servico = $infDecl->getElementsByTagName('Servico')->item(0);
                $data['rps']['competencia'] = $getVal($infDecl, 'Competencia');
                $data['rps']['optante_simples'] = $getVal($infDecl, 'OptanteSimplesNacional');
                $data['rps']['incentivo_fiscal'] = $getVal($infDecl, 'IncentivoFiscal');
                if ($servico) {
                    $valores = $servico->getElementsByTagName('Valores')->item(0);
                    if ($valores) {
                        $data['rps']['valor_servicos'] = $getVal($valores, 'ValorServicos');
                        $data['rps']['valor_deducoes'] = $getVal($valores, 'ValorDeducoes');
                        $data['rps']['valor_pis'] = $getVal($valores, 'ValorPis');
                        $data['rps']['valor_cofins'] = $getVal($valores, 'ValorCofins');
                        $data['rps']['valor_inss'] = $getVal($valores, 'ValorInss');
                        $data['rps']['valor_ir'] = $getVal($valores, 'ValorIr');
                        $data['rps']['valor_csll'] = $getVal($valores, 'ValorCsll');
                        $data['rps']['outras_retencoes'] = $getVal($valores, 'OutrasRetencoes');
                        $data['rps']['valor_iss'] = $getVal($valores, 'ValorIss');
                        $data['rps']['aliquota'] = $getVal($valores, 'Aliquota');
                    }
                    $data['rps']['iss_retido'] = $getVal($servico, 'IssRetido');
                    $data['rps']['item_lista_servico'] = $getVal($servico, 'ItemListaServico');
                    $data['rps']['codigo_cnae'] = $getVal($servico, 'CodigoCnae');
                    $data['rps']['codigo_tributacao_municipio'] = $getVal($servico, 'CodigoTributacaoMunicipio');
                    $data['rps']['discriminacao'] = $getVal($servico, 'Discriminacao');
                    $data['rps']['codigo_municipio'] = $getVal($servico, 'CodigoMunicipio');
                    $data['rps']['exigibilidade_iss'] = $getVal($servico, 'ExigibilidadeISS');
                    $data['rps']['municipio_incidencia'] = $getVal($servico, 'MunicipioIncidencia');
                }
                $tom = $infDecl->getElementsByTagName('TomadorServico')->item(0);
                if ($tom) {
                    $cpfCnpjNode = $tom->getElementsByTagName('CpfCnpj')->item(0);
                    $cpf = $cpfCnpjNode ? $cpfCnpjNode->getElementsByTagName('Cpf')->item(0) : null;
                    $cnpj = $cpfCnpjNode ? $cpfCnpjNode->getElementsByTagName('Cnpj')->item(0) : null;
                    $enderecoTom = $tom->getElementsByTagName('Endereco')->item(0);
                    $data['tomador'] = [
                        'razao' => $getVal($tom, 'RazaoSocial'),
                        'cpf_cnpj' => $cpf ? trim($cpf->nodeValue) : ($cnpj ? trim($cnpj->nodeValue) : null),
                    ];
                    if ($enderecoTom) {
                        $data['tomador']['endereco'] = $getVal($enderecoTom, 'Endereco');
                        $data['tomador']['numero'] = $getVal($enderecoTom, 'Numero');
                        $data['tomador']['bairro'] = $getVal($enderecoTom, 'Bairro');
                        $data['tomador']['codigo_municipio'] = $getVal($enderecoTom, 'CodigoMunicipio');
                        $data['tomador']['uf'] = $getVal($enderecoTom, 'Uf');
                        $data['tomador']['cep'] = $getVal($enderecoTom, 'Cep');
                    }
                }
            }
        }
    }

    $msgs = $dom->getElementsByTagName('MensagemRetorno');
    foreach ($msgs as $m) {
        $c = $m->getElementsByTagName('Codigo')->item(0);
        $t = $m->getElementsByTagName('Mensagem')->item(0);
        if ($c || $t) $data['mensagens'][] = '['.($c?trim($c->nodeValue):'').'] '.($t?trim($t->nodeValue):'');
    }

    return $data;
}

/** Renderiza HTML LEGADO (tabela simples, original) */
function nfse_render_consulta_html_legado($numeroRps, $dados) {
    $h = function($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
    $format_date = function($dateStr) {
        if (empty($dateStr)) return '';
        try { return (new DateTime($dateStr))->format('d/m/Y'); } catch (Exception $e) { return $dateStr; }
    };
    $format_currency = function($value) {
        if (!is_numeric($value)) return '';
        return 'R$ ' . number_format((float)$value, 2, ',', '.');
    };
    
    $html = '<div class="nfse-consulta-legada">';
    $html .= '<h3>Consulta de NFSe - Modo Legado</h3>';
    
    if (!empty($dados['mensagens'])) {
        $html .= '<div style="background:#fff3cd;padding:10px;margin:10px 0;border-left:4px solid #ffc107;">';
        $html .= $h(implode(' | ', array_unique($dados['mensagens'])));
        $html .= '</div>';
    }
    
    $html .= '<table class="border" width="100%">';
    $html .= '<tr><td width="30%"><b>Número da nota:</b></td><td>'.$h($dados['numero'] ?? '-').'</td></tr>';
    $html .= '<tr><td><b>Número RPS:</b></td><td>'.$h($dados['numero_rps'] ?? $numeroRps).'</td></tr>';
    $html .= '<tr><td><b>Lote RPS:</b></td><td>'.$h($dados['numero_lote'] ?? '-').'</td></tr>';
    $html .= '<tr><td><b>Código Verificação:</b></td><td>'.$h($dados['codigo_verificacao'] ?? '-').'</td></tr>';
    $html .= '<tr><td><b>Data Emissão:</b></td><td>'.$format_date($dados['data_emissao']).'</td></tr>';
    
    if (!empty($dados['valores'])) {
        $v = $dados['valores'];
        $html .= '<tr><td colspan="2"><hr><b>Valores</b></td></tr>';
        $html .= '<tr><td><b>Base Cálculo:</b></td><td>'.$format_currency($v['base_calculo']).'</td></tr>';
        $html .= '<tr><td><b>Alíquota ISS:</b></td><td>'.number_format((float)($v['aliquota'] ?? 0), 2, ',', '.').'%</td></tr>';
        $html .= '<tr><td><b>Valor ISS:</b></td><td>'.$format_currency($v['valor_iss']).'</td></tr>';
        $html .= '<tr><td><b>Valor Líquido:</b></td><td>'.$format_currency($v['valor_liquido']).'</td></tr>';
    }
    
    if (!empty($dados['rps'])) {
        $r = $dados['rps'];
        if (!empty($r['servico_nome']) || !empty($r['discriminacao'])) {
            $html .= '<tr><td colspan="2"><hr><b>Serviço</b></td></tr>';
            if (!empty($r['servico_nome'])) $html .= '<tr><td><b>Serviço:</b></td><td>'.$h($r['servico_nome']).'</td></tr>';
            if (!empty($r['discriminacao'])) $html .= '<tr><td><b>Discriminação:</b></td><td>'.$h($r['discriminacao']).'</td></tr>';
        }
    }
    
    $html .= '</table>';
    $html .= '</div>';
    return $html;
}

/** Monta HTML MODERNO e completo para exibir na modal */
function nfse_render_consulta_html($numeroRps, $dados, $db) {
    $h = function($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
    
    // CORRIGIDO: Suporta ISO 8601 com timezone (ex: 2025-11-04T17:05:29.365-03:00)
    $format_date = function($dateStr) {
        if (empty($dateStr)) return '-';
        try {
            // Tenta primeiro formato ISO 8601 completo (com T e timezone)
            $dt = DateTime::createFromFormat(DateTime::ATOM, $dateStr);
            if (!$dt) $dt = DateTime::createFromFormat('Y-m-d\TH:i:s.uP', $dateStr);
            if (!$dt) $dt = DateTime::createFromFormat('Y-m-d\TH:i:sP', $dateStr);
            if (!$dt) $dt = new DateTime($dateStr); // fallback genérico
            return $dt->format('d/m/Y H:i:s');
        } catch (Exception $e) {
            return $dateStr;
        }
    };
    // NOVO: apenas data (sem horário)
    $format_date_only = function($dateStr) {
        if (empty($dateStr)) return '-';
        try {
            $dt = DateTime::createFromFormat(DateTime::ATOM, $dateStr);
            if (!$dt) $dt = DateTime::createFromFormat('Y-m-d\TH:i:s.uP', $dateStr);
            if (!$dt) $dt = DateTime::createFromFormat('Y-m-d\TH:i:sP', $dateStr);
            if (!$dt) $dt = new DateTime($dateStr); // fallback genérico
            return $dt->format('d/m/Y');
        } catch (Exception $e) {
            return $dateStr;
        }
    };

    $format_currency = function($value) {
        if (!is_numeric($value)) return 'R$ 0,00';
        return 'R$ ' . number_format((float)$value, 2, ',', '.');
    };

    $rpsStatusMap = ['1' => 'Normal', '2' => 'Cancelado'];
    $nfseStatusMap = ['1' => 'Normal', '2' => 'Cancelada'];

    // Começa HTML moderno
    $html = '<div class="nfse-consulta-modal">';

    // Mensagens de retorno
    if (!empty($dados['mensagens'])) {
        $html .= '<div class="nfse-alert alert-warning"><i class="fa fa-exclamation-triangle"></i> ';
        $html .= $h(implode(' | ', array_unique($dados['mensagens'])));
        $html .= '</div>';
    }

    // Grid principal
    $html .= '<div class="nfse-grid-container">';

    // Card 1: Identificação (ocupa largura total)
    $statusNfseVal = $dados['status_nfse'] ?? '';
    $statusLabel = $nfseStatusMap[$statusNfseVal] ?? ($statusNfseVal ? 'Status '.$statusNfseVal : '-');
    $statusColor = '#666';
    if ($statusNfseVal == '1') $statusColor = '#27ae60';
    elseif ($statusNfseVal == '2') $statusColor = '#e74c3c';
    
    $html .= '<div class="nfse-card nfse-card-ident full-width"><div class="nfse-card-title"><i class="fa fa-info-circle"></i> Identificação</div><div class="nfse-card-body nfse-2col">';
    $html .= '<div class="nfse-row"><label>Número:</label><span>'.$h($dados['numero'] ?? '-').'</span></div>';
    $html .= '<div class="nfse-row"><label>Número RPS:</label><span>'.$h($dados['numero_rps'] ?? $numeroRps).'</span></div>';
    $html .= '<div class="nfse-row"><label>Lote RPS:</label><span>'.$h($dados['numero_lote'] ?? '-').'</span></div>';
    $html .= '<div class="nfse-row"><label>Cód. Verificação:</label><span>'.$h($dados['codigo_verificacao'] ?? '-').'</span></div>';
    $html .= '<div class="nfse-row"><label>Data Emissão:</label><span>'.$format_date_only($dados['data_emissao']).'</span></div>';
    $html .= '<div class="nfse-row"><label>Status da nota:</label><span style="font-weight:500; font-size:1.1em; color:'.$statusColor.';">'.$h($statusLabel).'</span></div>';

    if (!empty($dados['rps'])) {
        $r = $dados['rps'];
        $html .= '<div class="nfse-row"><label>Tipo RPS:</label><span>'.$h($r['tipo_rps'] ?? '-').'</span></div>';
        $html .= '<div class="nfse-row"><label>Série RPS:</label><span>'.$h($r['serie_rps'] ?? '-').'</span></div>';
        $html .= '<div class="nfse-row"><label>Optante Simples:</label><span>'.(($r['optante_simples'] ?? '') == '1' ? 'Sim' : 'Não').'</span></div>';
        $html .= '<div class="nfse-row"><label>Incentivo Fiscal:</label><span>'.(($r['incentivo_fiscal'] ?? '') == '1' ? 'Sim' : 'Não').'</span></div>';
    }
    $html .= '</div></div>';

    // Card 2: Valores (com TODOS os valores, incluindo retenções, em 2 colunas)
    if (!empty($dados['valores']) || !empty($dados['rps'])) {
        $v = $dados['valores'];
        $r = $dados['rps'];
        $html .= '<div class="nfse-card nfse-card-valores full-width"><div class="nfse-card-title"><i class="fa fa-usd"></i> Valores</div><div class="nfse-card-body nfse-values-grid">';
        $html .= '<div class="nfse-row"><label>Valor do Serviço:</label><span class="currency">'.$format_currency($r['valor_servicos'] ?? $v['base_calculo'] ?? 0).'</span></div>';
        $html .= '<div class="nfse-row"><label>Deduções:</label><span class="currency">'.$format_currency($r['valor_deducoes'] ?? 0).'</span></div>';
        $html .= '<div class="nfse-row"><label>Base de Cálculo:</label><span class="currency">'.$format_currency($v['base_calculo'] ?? 0).'</span></div>';
        $html .= '<div class="nfse-row col-left"><label>Alíquota ISS:</label><span>'.number_format((float)($r['aliquota'] ?? $v['aliquota'] ?? 0), 2, ',', '.').'%</span></div>';
        $html .= '<div class="nfse-row"><label>INSS:</label><span class="currency">'.$format_currency($r['valor_inss'] ?? 0).'</span></div>';
        $html .= '<div class="nfse-row"><label>CSLL:</label><span class="currency">'.$format_currency($r['valor_csll'] ?? 0).'</span></div>';
        $html .= '<div class="nfse-row"><label>Valor Líquido:</label><span class="currency">'.$format_currency($v['valor_liquido'] ?? 0).'</span></div>';
        // NOVO: Retenções integradas
        $html .= '<div class="nfse-row"><label>PIS:</label><span class="currency">'.$format_currency($r['valor_pis'] ?? '').'</span></div>';
        $html .= '<div class="nfse-row col-right"><label>COFINS:</label><span class="currency">'.$format_currency($r['valor_cofins'] ?? '').'</span></div>';
        $html .= '<div class="nfse-row"><label>Valor ISS:</label><span class="currency">'.$format_currency($r['valor_iss'] ?? $v['valor_iss'] ?? '').'</span></div>';
        $html .= '<div class="nfse-row col-right"><label>IR:</label><span class="currency">'.$format_currency($r['valor_ir'] ?? '').'</span></div>';
        $html .= '<div class="nfse-row col-left"><label>ISS Retido:</label><span>'.(($r['iss_retido'] ?? '') == '1' ? 'Sim' : 'Não').'</span></div>';
        $html .= '<div class="nfse-row"><label>Outras Retenções:</label><span class="currency">'.$format_currency($r['outras_retencoes'] ?? '').'</span></div>';
        $html .= '</div></div>';
    }

    // Card 3: Retenções Federais REMOVIDO (valores agora no card Valores)

    // Card 4: Serviço Prestado
    if (!empty($dados['rps'])) {
        $r = $dados['rps'];
        $html .= '<div class="nfse-card full-width"><div class="nfse-card-title"><i class="fa fa-briefcase"></i> Detalhes do Serviço</div><div class="nfse-card-body">';
        
        // CORRIGIDO: Exibe servico_prestado (do banco) em vez de servico_nome
        $servicoPrestado = $r['servico_prestado'] ?? $r['servico_nome'] ?? '-';
        $html .= '<div class="nfse-row"><label>Serviço:</label><span>'.$h($servicoPrestado).'</span></div>';
        
        // CORRIGIDO: Exibe código + descrição do serviço (busca descrição do catálogo)
        $codServico = $r['item_lista_servico'] ?? '';
        $descServico = '';
        if (!empty($codServico) && $db !== null) {
            $sqlDesc = "SELECT descricao FROM ".MAIN_DB_PREFIX."nfse_servicos_padrao WHERE codigo = '".$db->escape($codServico)."' LIMIT 1";
            $resDesc = $db->query($sqlDesc);
            if ($resDesc && $db->num_rows($resDesc) > 0) {
                $objDesc = $db->fetch_object($resDesc);
                $descServico = $objDesc->descricao;
            }
        }
        $labelCodigoServico = $codServico;
        if (!empty($descServico)) {
            $labelCodigoServico .= ' - ' . $descServico;
        }
        $html .= '<div class="nfse-row"><label>Código Serviço:</label><span>'.$h($labelCodigoServico ?: '-').'</span></div>';
        
        // REMOVIDO: linha do Código CNAE
        
        $html .= '<div class="nfse-row"><label>Competência:</label><span>'.$format_date_only($r['competencia']).'</span></div>';
        $municipioInc = $r['municipio_incidencia'] ?? '';
        if (!empty($municipioInc)) {
            $nomeMun = nfse_get_nome_municipio($municipioInc);
            $municipioInc = $nomeMun ? ($nomeMun . ' ('.$municipioInc.')') : $municipioInc;
        }
        $html .= '<div class="nfse-row"><label>Município Incidência:</label><span>'.$h($municipioInc).'</span></div>';
        $html .= '<div class="nfse-row"><label>Exigibilidade ISS:</label><span>'.$h($r['exigibilidade_iss'] == 1 ? 'Exigível' : 'Não Exigível').'</span></div>';
        if (!empty($r['discriminacao'])) {
            $html .= '<div class="nfse-row full-width"><label>Discriminação:</label><div class="nfse-textarea">'.$h($r['discriminacao']).'</div></div>';
        }
        $html .= '</div></div>';
    }

    // Card 6: Cancelamento
    if (!empty($dados['cancelamento'])) {
        $c = $dados['cancelamento'];
        $html .= '<div class="nfse-card full-width nfse-card-cancelamento"><div class="nfse-card-title"><i class="fa fa-ban"></i> Cancelamento</div><div class="nfse-card-body">';
        $html .= '<div class="nfse-row"><label>Data/Hora:</label><span>'.$format_date($c['data_hora'] ?? '-').'</span></div>';
        $html .= '<div class="nfse-row"><label>Código:</label><span>'.$h($c['codigo'] ?? '-').'</span></div>';
        $html .= '</div></div>';
    }

    // Card 7: Prestador (apenas Nome Fantasia/Razão Social)
    if (!empty($dados['prestador'])) {
        $p = $dados['prestador'];
        $html .= '<div class="nfse-card"><div class="nfse-card-title"><i class="fa fa-building"></i> Prestador</div><div class="nfse-card-body">';
        $nomePrestador = !empty($p['nome_fantasia']) ? $p['nome_fantasia'] : ($p['razao'] ?? '-');
        $html .= '<div class="nfse-row"><label>Nome:</label><span>'.$h($nomePrestador).'</span></div>';
        $html .= '</div></div>';
    }

    // Card 8: Tomador (apenas Nome Fantasia/Razão Social)
    if (!empty($dados['tomador'])) {
        $t = $dados['tomador'];
        $html .= '<div class="nfse-card"><div class="nfse-card-title"><i class="fa fa-user"></i> Tomador</div><div class="nfse-card-body">';
        // Tomador não tem nome_fantasia no padrão XML, usa apenas razao
        $nomeTomador = $t['nome_fantasia'] ?? ($t['razao'] ?? '-');
        $html .= '<div class="nfse-row"><label>Nome:</label><span>'.$h($nomeTomador).'</span></div>';
        $html .= '</div></div>';
    }

    $html .= '</div>'; // fim grid-container

    // CSS Inline escopado
    $html .= '<style>
/* Paleta e tipografia base */
.nfse-consulta-modal {
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
	color: #2c3e50;
	font-size: 14px;
	line-height: 1.45;
}

/* Alerts */
.nfse-consulta-modal .nfse-alert { padding: 10px 14px; margin: 12px 0; border-radius: 3px; border-left: 4px solid #f39c12; background: transparent; color: inherit; }

/* Grid */
.nfse-consulta-modal .nfse-grid-container {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
	gap: 20px;
	padding: 14px 0;
	background: transparent;
}

/* Cards: leve separação visual */
.nfse-consulta-modal .nfse-card {
	background: #ffffff;
	border: 1px solid #e6ebf1;
	border-radius: 6px;
	box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
	overflow: hidden;
	transition: box-shadow 0.2s ease;
}
.nfse-consulta-modal .nfse-card:hover {
	box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}
.nfse-consulta-modal .nfse-card.full-width { grid-column: 1 / -1; }

/* Título do card */
.nfse-consulta-modal .nfse-card-title {
	padding: 12px 16px;
	background: #f9fbfd;
	border-bottom: 1px solid #e6ebf1;
	color: #34495e;
	font-weight: 600;
	font-size: 13px;
	text-transform: uppercase;
	letter-spacing: 0.3px;
}

/* Corpo do card */
.nfse-consulta-modal .nfse-card-body {
	padding: 16px;
}

/* Linha: INLINE sem espaçamento extra */
.nfse-consulta-modal .nfse-row {
	padding: 8px 0;
	border-bottom: 1px solid #f1f5f8;
}
.nfse-consulta-modal .nfse-row:last-child {
	border-bottom: none;
}

/* Label (tag): inline, logo antes do valor */
.nfse-consulta-modal .nfse-row label {
	display: inline;
	color: #6c7a89;
	font-weight: 500;
	font-size: 13px;
	margin-right: 8px;
}

/* Valor: inline, logo após a label */
.nfse-consulta-modal .nfse-row span {
	display: inline;
	color: #2c3e50;
	font-weight: 600;
	font-size: 14px;
}

/* Valores monetários */
.nfse-consulta-modal .currency {
	color: #1e8449;
	font-weight: 700;
	font-size: 14px;
	font-variant-numeric: tabular-nums;
}

/* Status com cores */
.nfse-consulta-modal .highlight {
	font-weight: 700;
	color: #2980b9;
}

/* Linha especial para discriminação (mais espaçada) */
.nfse-consulta-modal .nfse-row.full-width {
	display: flex;
	flex-direction: column;
	align-items: stretch;
	padding: 14px 0 8px 0; /* mais espaço acima, menos abaixo */
	row-gap: 10px;         /* espaçamento consistente entre label e textarea */
}

.nfse-consulta-modal .nfse-row.full-width label {
	margin: 0;             /* label fica imediatamente acima, mas separada pelo row-gap */
	font-weight: 600;
	color: #5b6b73;
}

/* Textarea com largura controlada e espaço adequado relativo à label */
.nfse-consulta-modal .nfse-textarea {
	max-width: 92%;         /* evita ultrapassar a área visível em telas estreitas */
	width: 100%;
	background: #fff;
	padding: 12px 14px;
	border-radius: 6px;
	white-space: pre-wrap;
	word-wrap: break-word;
	border: 1px solid #e6eaee;
	overflow-x: auto;
	box-sizing: border-box;
	margin-top: 2px;        /* leve separação adicional da label quando necessário */
	line-height: 1.5;
}

/* Identificação e Valores em 2 colunas */
.nfse-consulta-modal .nfse-card-body.nfse-2col,
.nfse-consulta-modal .nfse-card-body.nfse-values-grid {
	display: grid;
	grid-template-columns: repeat(2, minmax(220px, 1fr));
	gap: 8px 16px;
	/* permite preenchimento mais flexível quando alguns itens forem forçados a coluna 2 */
	grid-auto-flow: dense;
}

/* Força posicionamento por coluna dentro do grid de valores */
.nfse-consulta-modal .nfse-card-body.nfse-values-grid .nfse-row.col-right {
	grid-column: 2;
}
.nfse-consulta-modal .nfse-card-body.nfse-values-grid .nfse-row.col-left {
	grid-column: 1;
}

/* Mobile: ajusta layout */
@media (max-width: 768px) {
	.nfse-consulta-modal .nfse-card-body.nfse-2col,
	.nfse-consulta-modal .nfse-card-body.nfse-values-grid {
		grid-template-columns: 1fr;
	}
	.nfse-consulta-modal .nfse-row label {
		display: block;
		margin-bottom: 4px;
	}
	.nfse-consulta-modal .nfse-row span {
		display: block;
	}
	.nfse-consulta-modal .nfse-textarea {
		width: 100%;
	}
}
</style>';

    $html .= '</div>'; // fim nfse-consulta-modal
    return $html;
}

/* ---------------------------
   Helper: procura nome do produto
   --------------------------- */
function nfse_lookup_product_name_by_ref($db, $ref) {
	$ref = trim((string)$ref);
	if ($ref === '') return null;
	$refE = $db->escape($ref);

	// 1) busca exata por ref
	$sql = "SELECT label FROM ".MAIN_DB_PREFIX."product WHERE ref = '".$refE."' LIMIT 1";
	$res = $db->query($sql);
	if ($res && $db->num_rows($res) > 0) {
		$o = $db->fetch_object($res);
		if (!empty($o->label)) return $o->label;
	}

	// 2) fallback: busca aproximada no ref ou label (case insensitive)
	$sql2 = "SELECT label, ref FROM ".MAIN_DB_PREFIX."product 
	         WHERE ref LIKE '%".$refE."%' OR label LIKE '%".$refE."%' LIMIT 1";
	$res2 = $db->query($sql2);
	if ($res2 && $db->num_rows($res2) > 0) {
		$o2 = $db->fetch_object($res2);
		if (!empty($o2->label)) return $o2->label;
	}

	// debug mínimo para logs (não expõe ao usuário)
	error_log("[NFSe Lookup] produto ref='{$ref}' não encontrado em ".MAIN_DB_PREFIX."product");
	return null;
}

/**
 * Consulta a NFSe por RPS
 */
function consultarLoteRpsPorId($db, $idNfse) {
    $out = ['success'=>false,'html'=>'','error'=>''];
    $id = (int)$idNfse;
    if ($id <= 0) { $out['error'] = 'ID inválido.'; return $out; }

    // Seleciona campos necessários, incluindo relações de substituição
    $sql = "SELECT id, numero_nota, status, protocolo, numero_lote, prestador_cnpj, rps_numero, tomador_nome, cod_servico_prestado, servico_prestado, xml_enviado, xml_recebido, id_nfse_substituida, id_nfse_substituta, mensagem_retorno
            FROM ".MAIN_DB_PREFIX."nfse_emitidas WHERE id = ".$id." LIMIT 1";
    $res = $db->query($sql);
    if (!$res || $db->num_rows($res) === 0) { $out['error'] = 'Registro não encontrado.'; return $out; }
    $row = $db->fetch_object($res);

    $numeroRps = trim((string)$row->rps_numero);
    if ($numeroRps === '' || !is_numeric($numeroRps)) {
        $out['error'] = 'Registro sem número de RPS válido para consulta.'; return $out;
    }
    $cnpj = preg_replace('/\D/', '', trim((string)$row->prestador_cnpj));
    if (empty($cnpj)) { $out['error'] = 'CNPJ do prestador não encontrado.'; return $out; }

    // NOVO: desabilitar consultas para notas rejeitadas -> retorna mensagem_retorno
    $statusLower = strtolower(trim((string)$row->status));
    if (strpos($statusLower, 'rejeit') !== false) {
        $msg = isset($row->mensagem_retorno) ? trim((string)$row->mensagem_retorno) : '';
        if ($msg === '') $msg = 'Nota rejeitada. Sem detalhes de retorno.';
        error_log('[NFSe Consulta] Nota rejeitada (id='.$id.'). Retornando mensagem_retorno local.');
        $html = '<div class="nfse-modal-content-wrap"><div class="nfse-alert nfse-alert-warn">'.dol_escape_htmltag($msg).'</div></div>';
        $out['success'] = true;
        $out['html'] = $html;
        return $out;
    }

    // Regra fixa: somente NFSe que foram SUBSTITUÍDAS (ou seja, registros que têm id_nfse_substituta)
    // serão consultadas localmente usando seu xml_recebido. Todas as outras seguem via prefeitura.
    if (!empty($row->id_nfse_substituta)) {
        // É a NFSe ORIGINAL que foi substituída -> consultar LOCALmente
        if (empty($row->xml_recebido) || trim($row->xml_recebido) === '') {
            $out['error'] = 'Registro substituído sem XML recebido armazenado para consulta local.'; return $out;
        }
        error_log('[NFSe Consulta] Registro é NFSe substituída (id='.$id.') — consultando localmente via xml_recebido.');
        $outputXML = $row->xml_recebido;

        // grava xml_atualizado (mesma prática do fluxo SOAP)
        try {
            $sqlUpdXml = "UPDATE ".MAIN_DB_PREFIX."nfse_emitidas SET xml_atualizado = '".$db->escape($outputXML)."' WHERE id = ".(int)$id;
            $resUpdXml = $db->query($sqlUpdXml);
            if (!$resUpdXml) {
                error_log('[NFSe Consulta] Falha ao gravar xml_atualizado (local) para id '.$id.': '.$db->lasterror().' | SQL: '.$sqlUpdXml);
            } else {
                error_log('[NFSe Consulta] xml_atualizado (local) salvo para NFSe ID: '.$id);
            }
        } catch (Exception $ex) {
            error_log('[NFSe Consulta] Exceção ao salvar xml_atualizado (local): '.$ex->getMessage());
        }

        // parseia e renderiza
        $dadosParsed = nfse_parse_consulta_output_xml($outputXML);

        // PRIORIDADE MÁXIMA: servico_prestado do banco (já buscado no SELECT principal)
        if (!empty($row->servico_prestado)) {
            $dadosParsed['rps']['servico_prestado'] = $row->servico_prestado;
            error_log('[NFSe Consulta] servico_prestado definido do banco: '.$row->servico_prestado);
        }

        // tenta preencher código e nome do serviço a partir do produto (ref = item_lista_servico ou coluna do banco)
        try {
            $codServico = $dadosParsed['rps']['item_lista_servico'] ?? ($row->cod_servico_prestado ?? null);
            if (!empty($codServico)) {
                // garante que o código apareça na renderização
                $dadosParsed['rps']['item_lista_servico'] = $codServico;
                // FALLBACK: só usa lookup se servico_prestado não foi preenchido do banco
                if (empty($dadosParsed['rps']['servico_prestado']) && empty($dadosParsed['rps']['servico_nome'])) {
                    $prodName = nfse_lookup_product_name_by_ref($db, $codServico);
                    if (!empty($prodName)) {
                        $dadosParsed['rps']['servico_nome'] = $prodName;
                        error_log('[NFSe Consulta] servico_nome definido do catálogo (fallback): '.$prodName);
                    }
                }
            }
        } catch (Exception $ex) {
            error_log('[NFSe Consulta] Erro ao buscar nome do serviço no catálogo: '.$ex->getMessage());
        }

        try {
            $dbStatus = strtolower(trim((string)$row->status));
            if ($dbStatus === 'cancelada') {
                $dadosParsed['status_nfse'] = '2';
                if (!isset($dadosParsed['mensagens']) || !is_array($dadosParsed['mensagens'])) {
                    $dadosParsed['mensagens'] = [];
                }
            }
        } catch (Exception $ex) {
            error_log("[NFSe Consulta] Erro ao aplicar override de status (local): " . $ex->getMessage());
        }

        $dadosParsed['numero_rps'] = $numeroRps;
        $dadosParsed['numero_lote'] = $row->numero_lote ?? null;

        // NOVO: Escolhe modo de renderização conforme configuração
        $modoConsulta = getDolGlobalString('NFSE_CONSULTA_MODO', '2');
        if ($modoConsulta == '1') {
            $html = nfse_render_consulta_html_legado($numeroRps, $dadosParsed);
        } else {
            $html = nfse_render_consulta_html($numeroRps, $dadosParsed, $db);
        }

        $out['success'] = true;
        $out['html'] = $html;
        return $out;
    }

    // Para todos os demais casos (incluindo a nota SUBSTITUTA e notas normais) -> consulta via prefeitura (SOAP)
    $cabecalho = nfse_build_consulta_cabecalho_xml();
    $dados = nfse_build_consulta_dados_xml($cnpj, $numeroRps);
    $soapEnvelope =
        '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:nfse="http://nfse.abrasf.org.br">' .
        '<soapenv:Header/>' .
        '<soapenv:Body>' .
            '<nfse:ConsultarNfsePorRps>' .
                '<nfse:ConsultarNfsePorRpsRequest>' .
                    '<nfseCabecMsg><![CDATA['.$cabecalho.']]></nfseCabecMsg>' .
                    '<nfseDadosMsg><![CDATA['.$dados.']]></nfseDadosMsg>' .
                '</nfse:ConsultarNfsePorRpsRequest>' .
            '</nfse:ConsultarNfsePorRps>' .
        '</soapenv:Body>' .
    '</soapenv:Envelope>';
    // Novo helper: faz POST SOAP com retry/backoff e mensagens amigáveis de erro
function nfse_curl_post_with_retry($url, $postBody, $certPemPath, $maxRetries = 2, $connectTimeout = 10, $timeout = 30) {
	$attempt = 0;
	$lastErr = '';
	while ($attempt <= $maxRetries) {
		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL => $url,
			CURLOPT_POST => true,
			CURLOPT_HTTPHEADER => ['Content-Type: text/xml; charset=UTF-8', 'SOAPAction: ""'],
			CURLOPT_POSTFIELDS => $postBody,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_SSLCERT => $certPemPath,
			CURLOPT_SSLKEY  => $certPemPath,
			CURLOPT_CONNECTTIMEOUT => $connectTimeout,
			CURLOPT_TIMEOUT => $timeout,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => false
		]);
		$response = @curl_exec($ch);
		$errno = curl_errno($ch);
		$errmsg = $errno ? curl_error($ch) : '';
		curl_close($ch);

		if ($errno === 0 && $response !== false) {
			return ['success' => true, 'response' => $response];
		}

		$lastErr = $errmsg ?: ('cURL errno ' . $errno);
		error_log('[NFSe cURL] tentativa '.($attempt+1).' falhou: '.$lastErr);
		if ($attempt === $maxRetries) break;
		$attempt++;
		sleep(pow(2, $attempt - 1)); // backoff: 1s,2s,...
	}

	// Mensagem amigável para falha de conexão típica
	if (stripos($lastErr, 'Failed to connect') !== false || stripos($lastErr, "Couldn't connect") !== false) {
		$friendly = 'Erro de conexão com a prefeitura: verifique rede/DNS/firewall e se o endpoint está acessível (' . $url . '). Detalhe: ' . $lastErr;
	} else {
		$friendly = 'Erro cURL: ' . $lastErr;
	}
	return ['success' => false, 'error' => $friendly];
}

    try {
        $cert = carregarCertificadoA1FromDB($db);
    } catch (Exception $e) {
        $out['error'] = 'Certificado: '.$e->getMessage();
        return $out;
    }

    $wsdl = 'https://notafse-backend.cachoeiro.es.gov.br/nfse/NfseWSService?wsdl';
    $curlRes = nfse_curl_post_with_retry($wsdl, $soapEnvelope, $cert['pem_path'], 2, 10, 30);
    if (!$curlRes['success']) {
        $out['error'] = $curlRes['error'];
        if (!empty($cert['pem_path']) && file_exists($cert['pem_path'])) @unlink($cert['pem_path']);
        return $out;
    }
    $response = $curlRes['response'];

    $soapDom = new DOMDocument('1.0', 'UTF-8');
    if (!@$soapDom->loadXML($response)) {
        $out['error'] = 'Resposta SOAP inválida.'; return $out;
    }
    $outputNode = $soapDom->getElementsByTagName('outputXML')->item(0);
    if (!$outputNode) { $out['error'] = 'outputXML não encontrado na resposta.'; return $out; }
    $outputXML = htmlspecialchars_decode($outputNode->nodeValue);
    $respDom = new DOMDocument('1.0', 'UTF-8');
    @$respDom->loadXML($outputXML);
    // Armazena o XML recebido pela prefeitura no campo xml_atualizado da nfse_emitidas
    try {
        $sqlUpdXml = "UPDATE ".MAIN_DB_PREFIX."nfse_emitidas SET xml_atualizado = '".$db->escape($outputXML)."' WHERE id = ".(int)$id;
        $resUpdXml = $db->query($sqlUpdXml);
        if (!$resUpdXml) {
            error_log('[NFSe Consulta] Falha ao gravar xml_atualizado para id '.$id.': '.$db->lasterror().' | SQL: '.$sqlUpdXml);
        } else {
            error_log('[NFSe Consulta] xml_atualizado salvo para NFSe ID: '.$id);
        }
    } catch (Exception $ex) {
        error_log('[NFSe Consulta] Exceção ao salvar xml_atualizado: '.$ex->getMessage());
    }
    $dadosParsed = nfse_parse_consulta_output_xml($outputXML);

    // PRIORIDADE MÁXIMA: servico_prestado do banco (já buscado no SELECT principal)
    if (!empty($row->servico_prestado)) {
        $dadosParsed['rps']['servico_prestado'] = $row->servico_prestado;
        error_log('[NFSe Consulta] servico_prestado definido do banco: '.$row->servico_prestado);
    }

    // Preenche código e nome do serviço a partir do catálogo ou do campo cod_servico_prestado do registro
    try {
        $codServico = $dadosParsed['rps']['item_lista_servico'] ?? ($row->cod_servico_prestado ?? null);
        if (!empty($codServico)) {
            // garante que o código apareça na renderização
            $dadosParsed['rps']['item_lista_servico'] = $codServico;
            // FALLBACK: só usa lookup se servico_prestado não foi preenchido do banco
            if (empty($dadosParsed['rps']['servico_prestado']) && empty($dadosParsed['rps']['servico_nome'])) {
                $prodName = nfse_lookup_product_name_by_ref($db, $codServico);
                if (!empty($prodName)) {
                    $dadosParsed['rps']['servico_nome'] = $prodName;
                    error_log('[NFSe Consulta] servico_nome definido do catálogo (fallback): '.$prodName);
                }
            }
        }
    } catch (Exception $ex) {
        error_log('[NFSe Consulta] Erro ao buscar nome do serviço no catálogo: '.$ex->getMessage());
    }

    try {
        $dbStatus = strtolower(trim((string)$row->status));
        if ($dbStatus === 'cancelada') {
            $dadosParsed['status_nfse'] = '2';
            if (!isset($dadosParsed['mensagens']) || !is_array($dadosParsed['mensagens'])) {
                $dadosParsed['mensagens'] = [];
            }
        }
    } catch (Exception $ex) {
        error_log("[NFSe Consulta] Erro ao aplicar override de status: " . $ex->getMessage());
    }

    // Adiciona numero_rps e numero_lote aos dados para exibição
    $dadosParsed['numero_rps'] = $numeroRps;
    $dadosParsed['numero_lote'] = $row->numero_lote;

   $html = nfse_render_consulta_html($numeroRps, $dadosParsed, $db);
    $out['success'] = true;
    $out['html'] = $html;
    return $out;
}
?>
