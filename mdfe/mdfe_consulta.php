<?php
/**
 * MDF-e Consulta - Modal de visualização detalhada do XML da MDF-e
 * 
 * Este arquivo é incluído via AJAX pelo mdfe_list.php e retorna:
 *   - action=consultar_xml  → JSON com todos os dados parseados do xml_mdfe
 *   - action=consultar_html → HTML pronto para renderizar na modal
 */

// Silencia avisos/notices
@ini_set('display_errors', '0');
$__lvl = error_reporting();
$__lvl &= ~(E_DEPRECATED | E_USER_DEPRECATED | E_NOTICE | E_USER_NOTICE | E_WARNING | E_USER_WARNING);
error_reporting($__lvl);

require '../../main.inc.php';

/** @var DoliDB $db */
/** @var Translate $langs */

// Somente requisições AJAX
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    exit('Acesso direto não permitido.');
}

$action = GETPOST('action', 'alpha');
$mdfeId = (int) GETPOST('id', 'int');

if ($mdfeId <= 0) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'error' => 'ID inválido'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ============================================================
// Busca o registro e parseia o XML
// ============================================================
$sql = "SELECT * FROM ".MAIN_DB_PREFIX."mdfe_emitidas WHERE id = ".$mdfeId;
$res = $db->query($sql);

if (!$res || $db->num_rows($res) === 0) {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode(['success' => false, 'error' => 'MDF-e não encontrada no banco de dados.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$row = $db->fetch_object($res);

// Obtém o XML
$xmlStr = '';
if (!empty($row->xml_mdfe)) {
    $xmlStr = is_resource($row->xml_mdfe) ? stream_get_contents($row->xml_mdfe) : (string) $row->xml_mdfe;
}

// ============================================================
// Função auxiliar: parseia o XML da MDF-e de forma genérica
// ============================================================
function parseMdfeXml($xmlStr, $row) {
    $dados = [
        'id'              => (int) $row->id,
        'numero'          => $row->numero ?? '',
        'serie'           => $row->serie ?? '',
        'chave_acesso'    => $row->chave_acesso ?? '',
        'protocolo'       => $row->protocolo ?? '',
        'status'          => $row->status ?? '',
        'codigo_status'   => $row->codigo_status ?? '',
        'motivo'          => $row->motivo ?? '',
        'data_emissao'    => !empty($row->data_emissao) ? date('d/m/Y H:i:s', strtotime($row->data_emissao)) : '-',
        'data_recebimento'=> !empty($row->data_recebimento) ? date('d/m/Y H:i:s', strtotime($row->data_recebimento)) : '-',
        'data_encerramento' => !empty($row->data_encerramento) ? date('d/m/Y H:i:s', strtotime($row->data_encerramento)) : '',
        'protocolo_encerramento' => $row->protocolo_encerramento ?? '',
        'motivo_cancelamento' => $row->motivo_cancelamento ?? '',
        'ambiente'        => (int)($row->ambiente ?? 2) === 1 ? 'Produção' : 'Homologação',
        'uf_ini'          => $row->uf_ini ?? '',
        'uf_fim'          => $row->uf_fim ?? '',
        'modal'           => $row->modal ?? '',
        'placa'           => $row->placa ?? '',
        'valor_carga'     => $row->valor_carga ?? '',
        'peso_carga'      => $row->peso_carga ?? '',
        'qtd_cte'         => $row->qtd_cte ?? '0',
        'qtd_nfe'         => $row->qtd_nfe ?? '0',
        'mun_carrega'     => $row->mun_carrega ?? '',
        'mun_descarga'    => $row->mun_descarga ?? '',
    ];

    // Labels de modal
    $modalLabels = [1 => 'Rodoviário', 2 => 'Aéreo', 3 => 'Aquaviário', 4 => 'Ferroviário'];
    $dados['modal_desc'] = $modalLabels[(int)$dados['modal']] ?? $dados['modal'];

    // Labels de tipo de carga
    $tpCargaMap = [
        '01' => 'Granel sólido',
        '02' => 'Granel líquido',
        '03' => 'Frigorificada',
        '04' => 'Conteinerizada',
        '05' => 'Carga Geral',
        '06' => 'Neogranel',
        '07' => 'Perigosa (granel sólido)',
        '08' => 'Perigosa (granel líquido)',
        '09' => 'Perigosa (frigorificada)',
        '10' => 'Perigosa (conteinerizada)',
        '11' => 'Perigosa (carga geral)',
    ];

    // Labels de tipo de rodado
    $tpRodMap = [
        '01' => 'Truck',
        '02' => 'Toco',
        '03' => 'Cavalo Mecânico',
        '04' => 'VAN',
        '05' => 'Utilitário',
        '06' => 'Outros',
    ];

    // Labels de tipo de carroceria
    $tpCarMap = [
        '00' => 'Não aplicável',
        '01' => 'Aberta',
        '02' => 'Fechada/Baú',
        '03' => 'Graneleira',
        '04' => 'Porta Container',
        '05' => 'Sider',
    ];

    // Labels de responsável pelo seguro
    $respSegMap = [
        '1' => 'Emitente do MDF-e',
        '2' => 'Responsável pela contratação do serviço de transporte',
    ];

    // Labels de unidade de carga
    $cUnidMap = [
        '01' => 'KG',
        '02' => 'TON',
    ];

    // Labels de tipo de emitente
    $tpEmitMap = [
        '1' => 'Prestador de serviço de transporte',
        '2' => 'Transportador de Carga Própria',
        '3' => 'Prestador de serviço de transporte (CT-e globalizado)',
    ];

    // Labels de tipo de emissão
    $tpEmisMap = [
        '1' => 'Normal',
        '2' => 'Contingência',
        '3' => 'Regime Especial NFF',
    ];

    // UF → código IBGE
    $ufMap = [
        '11'=>'RO','12'=>'AC','13'=>'AM','14'=>'RR','15'=>'PA','16'=>'AP','17'=>'TO',
        '21'=>'MA','22'=>'PI','23'=>'CE','24'=>'RN','25'=>'PB','26'=>'PE','27'=>'AL',
        '28'=>'SE','29'=>'BA','31'=>'MG','32'=>'ES','33'=>'RJ','35'=>'SP',
        '41'=>'PR','42'=>'SC','43'=>'RS','50'=>'MS','51'=>'MT','52'=>'GO','53'=>'DF',
    ];

    if (empty($xmlStr)) {
        return $dados;
    }

    // Limpa namespaces para facilitar parse
    $xmlClean = preg_replace('/xmlns[^=]*="[^"]*"/', '', $xmlStr);
    $xml = @simplexml_load_string($xmlClean);

    if (!$xml) {
        $dados['xml_parse_error'] = 'Não foi possível interpretar o XML.';
        return $dados;
    }

    $infMDFe = $xml->infMDFe ?? $xml;

    // ====== IDE ======
    $ide = $infMDFe->ide ?? null;
    if ($ide) {
        $dados['cUF']       = (string)($ide->cUF ?? '');
        $dados['cUF_desc']  = $ufMap[$dados['cUF']] ?? $dados['cUF'];
        $dados['tpAmb']     = (string)($ide->tpAmb ?? '');
        $dados['tpAmb_desc']= ($dados['tpAmb'] === '1') ? 'Produção' : 'Homologação';
        $dados['tpEmit']    = (string)($ide->tpEmit ?? '');
        $dados['tpEmit_desc'] = $tpEmitMap[$dados['tpEmit']] ?? $dados['tpEmit'];
        $dados['mod']       = (string)($ide->mod ?? '');
        $dados['serie']     = (string)($ide->serie ?? $dados['serie']);
        $dados['nMDF']      = (string)($ide->nMDF ?? '');
        $dados['cMDF']      = (string)($ide->cMDF ?? '');
        $dados['cDV']       = (string)($ide->cDV ?? '');
        $dados['modal_xml'] = (string)($ide->modal ?? '');
        if (!empty($dados['modal_xml'])) {
            $dados['modal'] = $dados['modal_xml'];
            $dados['modal_desc'] = $modalLabels[(int)$dados['modal_xml']] ?? $dados['modal_xml'];
        }
        $dados['dhEmi']     = (string)($ide->dhEmi ?? '');
        if (!empty($dados['dhEmi'])) {
            $dados['data_emissao'] = date('d/m/Y H:i:s', strtotime($dados['dhEmi']));
        }
        $dados['tpEmis']    = (string)($ide->tpEmis ?? '');
        $dados['tpEmis_desc'] = $tpEmisMap[$dados['tpEmis']] ?? $dados['tpEmis'];
        $dados['procEmi']   = (string)($ide->procEmi ?? '');
        $dados['verProc']   = (string)($ide->verProc ?? '');
        $dados['UFIni']     = (string)($ide->UFIni ?? '');
        $dados['UFFim']     = (string)($ide->UFFim ?? '');
        if (!empty($dados['UFIni'])) $dados['uf_ini'] = $dados['UFIni'];
        if (!empty($dados['UFFim'])) $dados['uf_fim'] = $dados['UFFim'];

        // dhIniViagem
        $dados['dhIniViagem'] = (string)($ide->dhIniViagem ?? '');
        if (!empty($dados['dhIniViagem'])) {
            $dados['dhIniViagem_fmt'] = date('d/m/Y H:i:s', strtotime($dados['dhIniViagem']));
        }

        // indCarregaPosterior
        $dados['indCarregaPosterior'] = (string)($ide->indCarregaPosterior ?? '');

        // Municípios de carregamento (pode ser múltiplo)
        $dados['municipios_carrega'] = [];
        foreach ($ide->infMunCarrega as $mun) {
            $dados['municipios_carrega'][] = [
                'cMunCarrega' => (string)($mun->cMunCarrega ?? ''),
                'xMunCarrega' => (string)($mun->xMunCarrega ?? ''),
            ];
        }
        if (!empty($dados['municipios_carrega'][0]['xMunCarrega'])) {
            $dados['mun_carrega'] = $dados['municipios_carrega'][0]['xMunCarrega'];
        }

        // Percurso (UFs intermediárias)
        $dados['percurso'] = [];
        if (isset($ide->infPercurso)) {
            foreach ($ide->infPercurso as $perc) {
                $dados['percurso'][] = (string)($perc->UFPer ?? '');
            }
        }
    }

    // ====== EMIT ======
    $emit = $infMDFe->emit ?? null;
    if ($emit) {
        $dados['emit_CNPJ']  = (string)($emit->CNPJ ?? '');
        $dados['emit_CPF']   = (string)($emit->CPF ?? '');
        $dados['emit_IE']    = (string)($emit->IE ?? '');
        $dados['emit_xNome'] = (string)($emit->xNome ?? '');
        $dados['emit_xFant'] = (string)($emit->xFant ?? '');

        $ender = $emit->enderEmit ?? null;
        if ($ender) {
            $dados['emit_xLgr']   = (string)($ender->xLgr ?? '');
            $dados['emit_nro']    = (string)($ender->nro ?? '');
            $dados['emit_xCpl']   = (string)($ender->xCpl ?? '');
            $dados['emit_xBairro']= (string)($ender->xBairro ?? '');
            $dados['emit_cMun']   = (string)($ender->cMun ?? '');
            $dados['emit_xMun']   = (string)($ender->xMun ?? '');
            $dados['emit_CEP']    = (string)($ender->CEP ?? '');
            $dados['emit_UF']     = (string)($ender->UF ?? '');
            $dados['emit_fone']   = (string)($ender->fone ?? '');
            $dados['emit_email']  = (string)($ender->email ?? '');
        }
    }

    // ====== MODAL ======
    $infModal = $infMDFe->infModal ?? null;
    if ($infModal) {
        $dados['versaoModal'] = (string)($infModal['versaoModal'] ?? '');

        // --- Rodoviário ---
        if ($infModal->rodo) {
            $rodo = $infModal->rodo;
            $dados['tipo_modal'] = 'rodo';

            // ANTT
            if ($rodo->infANTT) {
                $dados['RNTRC'] = (string)($rodo->infANTT->RNTRC ?? '');

                // CIOT
                $dados['CIOT'] = [];
                if (isset($rodo->infANTT->infCIOT)) {
                    foreach ($rodo->infANTT->infCIOT as $ciot) {
                        $dados['CIOT'][] = [
                            'nCIOT' => (string)($ciot->nCIOT ?? ''),
                            'CNPJ'  => (string)($ciot->CNPJ ?? ''),
                            'CPF'   => (string)($ciot->CPF ?? ''),
                        ];
                    }
                }

                // Contratantes
                $dados['contratantes'] = [];
                if (isset($rodo->infANTT->infContratante)) {
                    foreach ($rodo->infANTT->infContratante as $contr) {
                        $item = [];
                        $item['CNPJ'] = (string)($contr->CNPJ ?? '');
                        $item['CPF']  = (string)($contr->CPF ?? '');
                        $item['xNome'] = (string)($contr->xNome ?? '');
                        $item['idEstrangeiro'] = (string)($contr->idEstrangeiro ?? '');
                        // infContrato
                        if (isset($contr->infContrato)) {
                            $item['NroContrato'] = (string)($contr->infContrato->NroContrato ?? '');
                            $item['vContratoGlobal'] = (string)($contr->infContrato->vContratoGlobal ?? '');
                        }
                        $dados['contratantes'][] = $item;
                    }
                }

                // Vale Pedagio
                $dados['valePedagio'] = [];
                if (isset($rodo->infANTT->valePed)) {
                    foreach ($rodo->infANTT->valePed->disp as $disp) {
                        $dados['valePedagio'][] = [
                            'CNPJForn' => (string)($disp->CNPJForn ?? ''),
                            'CNPJPg'   => (string)($disp->CNPJPg ?? ''),
                            'CPFPg'    => (string)($disp->CPFPg ?? ''),
                            'nCompra'  => (string)($disp->nCompra ?? ''),
                            'vValePed' => (string)($disp->vValePed ?? ''),
                        ];
                    }
                }

                // infPag (pagamento)
                $dados['pagamentos'] = [];
                if (isset($rodo->infANTT->infPag)) {
                    foreach ($rodo->infANTT->infPag as $pag) {
                        $pagItem = [
                            'xNome'  => (string)($pag->xNome ?? ''),
                            'CNPJ'   => (string)($pag->CNPJ ?? ''),
                            'CPF'    => (string)($pag->CPF ?? ''),
                            'idEstrangeiro' => (string)($pag->idEstrangeiro ?? ''),
                            'vContrato' => (string)($pag->vContrato ?? ''),
                            'indAltoDesemp' => (string)($pag->indAltoDesemp ?? ''),
                            'indPag' => (string)($pag->indPag ?? ''),
                            'vAdiant' => (string)($pag->vAdiant ?? ''),
                            'indAntecipaImposto' => (string)($pag->indAntecipaImposto ?? ''),
                        ];
                        // Componentes
                        $pagItem['Comp'] = [];
                        if (isset($pag->Comp)) {
                            foreach ($pag->Comp as $comp) {
                                $pagItem['Comp'][] = [
                                    'tpComp' => (string)($comp->tpComp ?? ''),
                                    'vComp'  => (string)($comp->vComp ?? ''),
                                    'xComp'  => (string)($comp->xComp ?? ''),
                                ];
                            }
                        }
                        // Banco info
                        if (isset($pag->infBanc)) {
                            $pagItem['codBanco'] = (string)($pag->infBanc->codBanco ?? '');
                            $pagItem['codAgencia'] = (string)($pag->infBanc->codAgencia ?? '');
                            $pagItem['CNPJIPEF'] = (string)($pag->infBanc->CNPJIPEF ?? '');
                            $pagItem['PIX'] = (string)($pag->infBanc->PIX ?? '');
                        }
                        $dados['pagamentos'][] = $pagItem;
                    }
                }
            }

            // Veículo de tração
            if ($rodo->veicTracao) {
                $vt = $rodo->veicTracao;
                $dados['veicTracao'] = [
                    'cInt'    => (string)($vt->cInt ?? ''),
                    'placa'   => (string)($vt->placa ?? ''),
                    'RENAVAM' => (string)($vt->RENAVAM ?? ''),
                    'tara'    => (string)($vt->tara ?? ''),
                    'capKG'   => (string)($vt->capKG ?? ''),
                    'capM3'   => (string)($vt->capM3 ?? ''),
                    'tpRod'   => (string)($vt->tpRod ?? ''),
                    'tpRod_desc' => $tpRodMap[(string)($vt->tpRod ?? '')] ?? (string)($vt->tpRod ?? ''),
                    'tpCar'   => (string)($vt->tpCar ?? ''),
                    'tpCar_desc' => $tpCarMap[(string)($vt->tpCar ?? '')] ?? (string)($vt->tpCar ?? ''),
                    'UF'      => (string)($vt->UF ?? ''),
                ];
                if (!empty($dados['veicTracao']['placa'])) {
                    $dados['placa'] = $dados['veicTracao']['placa'];
                }

                // Proprietário do veículo de tração
                if ($vt->prop) {
                    $dados['veicTracao']['prop'] = [
                        'CNPJ'   => (string)($vt->prop->CNPJ ?? ''),
                        'CPF'    => (string)($vt->prop->CPF ?? ''),
                        'RNTRC'  => (string)($vt->prop->RNTRC ?? ''),
                        'xNome'  => (string)($vt->prop->xNome ?? ''),
                        'IE'     => (string)($vt->prop->IE ?? ''),
                        'UF'     => (string)($vt->prop->UF ?? ''),
                        'tpProp' => (string)($vt->prop->tpProp ?? ''),
                    ];
                }

                // Condutores (pode ser múltiplo)
                $dados['condutores'] = [];
                if (isset($vt->condutor)) {
                    foreach ($vt->condutor as $cond) {
                        $dados['condutores'][] = [
                            'xNome' => (string)($cond->xNome ?? ''),
                            'CPF'   => (string)($cond->CPF ?? ''),
                        ];
                    }
                }
            }

            // Veículos reboque
            $dados['veicReboque'] = [];
            if (isset($rodo->veicReboque)) {
                foreach ($rodo->veicReboque as $reb) {
                    $rebItem = [
                        'cInt'    => (string)($reb->cInt ?? ''),
                        'placa'   => (string)($reb->placa ?? ''),
                        'RENAVAM' => (string)($reb->RENAVAM ?? ''),
                        'tara'    => (string)($reb->tara ?? ''),
                        'capKG'   => (string)($reb->capKG ?? ''),
                        'capM3'   => (string)($reb->capM3 ?? ''),
                        'tpCar'   => (string)($reb->tpCar ?? ''),
                        'tpCar_desc' => $tpCarMap[(string)($reb->tpCar ?? '')] ?? (string)($reb->tpCar ?? ''),
                        'UF'      => (string)($reb->UF ?? ''),
                    ];
                    if ($reb->prop) {
                        $rebItem['prop'] = [
                            'CNPJ'   => (string)($reb->prop->CNPJ ?? ''),
                            'CPF'    => (string)($reb->prop->CPF ?? ''),
                            'RNTRC'  => (string)($reb->prop->RNTRC ?? ''),
                            'xNome'  => (string)($reb->prop->xNome ?? ''),
                            'IE'     => (string)($reb->prop->IE ?? ''),
                            'UF'     => (string)($reb->prop->UF ?? ''),
                            'tpProp' => (string)($reb->prop->tpProp ?? ''),
                        ];
                    }
                    $dados['veicReboque'][] = $rebItem;
                }
            }

            // Código de agendamento no porto (lacRodo)
            if (isset($rodo->lacRodo)) {
                $dados['lacres_rodo'] = [];
                foreach ($rodo->lacRodo as $lac) {
                    $dados['lacres_rodo'][] = (string)($lac->nLacre ?? '');
                }
            }

            // codAgPorto
            if (!empty($rodo->codAgPorto)) {
                $dados['codAgPorto'] = (string)$rodo->codAgPorto;
            }
        }

        // --- Aéreo ---
        if ($infModal->aereo) {
            $aereo = $infModal->aereo;
            $dados['tipo_modal'] = 'aereo';
            $dados['aereo'] = [
                'nac'      => (string)($aereo->nac ?? ''),
                'matr'     => (string)($aereo->matr ?? ''),
                'nVoo'     => (string)($aereo->nVoo ?? ''),
                'cAerEmb'  => (string)($aereo->cAerEmb ?? ''),
                'cAerDes'  => (string)($aereo->cAerDes ?? ''),
                'dVoo'     => (string)($aereo->dVoo ?? ''),
            ];
        }

        // --- Aquaviário ---
        if ($infModal->aquav) {
            $aquav = $infModal->aquav;
            $dados['tipo_modal'] = 'aquav';
            $dados['aquav'] = [
                'irin'     => (string)($aquav->irin ?? ''),
                'tpEmb'    => (string)($aquav->tpEmb ?? ''),
                'cEmbar'   => (string)($aquav->cEmbar ?? ''),
                'xEmbar'   => (string)($aquav->xEmbar ?? ''),
                'nViag'    => (string)($aquav->nViag ?? ''),
                'cPrtEmb'  => (string)($aquav->cPrtEmb ?? ''),
                'cPrtDest' => (string)($aquav->cPrtDest ?? ''),
                'prtTrans' => (string)($aquav->prtTrans ?? ''),
                'tpNav'    => (string)($aquav->tpNav ?? ''),
            ];
            // infTermCarreg
            $dados['aquav']['terminaisCarreg'] = [];
            if (isset($aquav->infTermCarreg)) {
                foreach ($aquav->infTermCarreg as $tc) {
                    $dados['aquav']['terminaisCarreg'][] = [
                        'cTermCarreg' => (string)($tc->cTermCarreg ?? ''),
                        'xTermCarreg' => (string)($tc->xTermCarreg ?? ''),
                    ];
                }
            }
            // infTermDescarreg
            $dados['aquav']['terminaisDescarreg'] = [];
            if (isset($aquav->infTermDescarreg)) {
                foreach ($aquav->infTermDescarreg as $td) {
                    $dados['aquav']['terminaisDescarreg'][] = [
                        'cTermDescarreg' => (string)($td->cTermDescarreg ?? ''),
                        'xTermDescarreg' => (string)($td->xTermDescarreg ?? ''),
                    ];
                }
            }
            // infEmbComb
            $dados['aquav']['embComb'] = [];
            if (isset($aquav->infEmbComb)) {
                foreach ($aquav->infEmbComb as $ec) {
                    $dados['aquav']['embComb'][] = [
                        'cEmbComb' => (string)($ec->cEmbComb ?? ''),
                        'xBalsa'   => (string)($ec->xBalsa ?? ''),
                    ];
                }
            }
        }

        // --- Ferroviário ---
        if ($infModal->ferrov) {
            $ferrov = $infModal->ferrov;
            $dados['tipo_modal'] = 'ferrov';
            $dados['ferrov'] = [
                'dhTrem'  => (string)($ferrov->trem->dhTrem ?? ''),
                'xPref'   => (string)($ferrov->trem->xPref ?? ''),
                'xOri'    => (string)($ferrov->trem->xOri ?? ''),
                'xDest'   => (string)($ferrov->trem->xDest ?? ''),
                'qVag'    => (string)($ferrov->trem->qVag ?? ''),
            ];
            $dados['ferrov']['vagoes'] = [];
            if (isset($ferrov->vag)) {
                foreach ($ferrov->vag as $vag) {
                    $dados['ferrov']['vagoes'][] = [
                        'pesoBC' => (string)($vag->pesoBC ?? ''),
                        'pesoR'  => (string)($vag->pesoR ?? ''),
                        'tpVag'  => (string)($vag->tpVag ?? ''),
                        'serie'  => (string)($vag->serie ?? ''),
                        'nVag'   => (string)($vag->nVag ?? ''),
                        'nSeq'   => (string)($vag->nSeq ?? ''),
                        'TU'     => (string)($vag->TU ?? ''),
                    ];
                }
            }
        }
    }

    // ====== DOCUMENTOS (infDoc) ======
    $infDoc = $infMDFe->infDoc ?? null;
    if ($infDoc) {
        $dados['documentos'] = [];
        if (isset($infDoc->infMunDescarga)) {
            foreach ($infDoc->infMunDescarga as $mDesc) {
                $munItem = [
                    'cMunDescarga' => (string)($mDesc->cMunDescarga ?? ''),
                    'xMunDescarga' => (string)($mDesc->xMunDescarga ?? ''),
                    'chaves_cte'   => [],
                    'chaves_nfe'   => [],
                    'chaves_mdfe'  => [],
                ];
                // CT-e
                if (isset($mDesc->infCTe)) {
                    foreach ($mDesc->infCTe as $cte) {
                        $cteItem = ['chCTe' => (string)($cte->chCTe ?? '')];
                        // Pode ter infUnidTransp, peri, etc. – captura se existir
                        if (isset($cte->SegCodBarra)) $cteItem['SegCodBarra'] = (string)$cte->SegCodBarra;
                        if (isset($cte->indReentrega)) $cteItem['indReentrega'] = (string)$cte->indReentrega;
                        $munItem['chaves_cte'][] = $cteItem;
                    }
                }
                // NF-e
                if (isset($mDesc->infNFe)) {
                    foreach ($mDesc->infNFe as $nfe) {
                        $nfeItem = ['chNFe' => (string)($nfe->chNFe ?? '')];
                        if (isset($nfe->SegCodBarra)) $nfeItem['SegCodBarra'] = (string)$nfe->SegCodBarra;
                        if (isset($nfe->indReentrega)) $nfeItem['indReentrega'] = (string)$nfe->indReentrega;
                        $munItem['chaves_nfe'][] = $nfeItem;
                    }
                }
                // MDF-e aninhado
                if (isset($mDesc->infMDFeTransp)) {
                    foreach ($mDesc->infMDFeTransp as $mdfeT) {
                        $munItem['chaves_mdfe'][] = ['chMDFe' => (string)($mdfeT->chMDFe ?? '')];
                    }
                }
                $dados['documentos'][] = $munItem;
            }
        }
        // Atualiza mun_descarga com o primeiro
        if (!empty($dados['documentos'][0]['xMunDescarga'])) {
            $dados['mun_descarga'] = $dados['documentos'][0]['xMunDescarga'];
        }
    }

    // ====== SEGURO (seg) ======
    $dados['seguros'] = [];
    if (isset($infMDFe->seg)) {
        foreach ($infMDFe->seg as $seg) {
            $segItem = [
                'respSeg'  => (string)($seg->infResp->respSeg ?? ''),
                'respSeg_desc' => $respSegMap[(string)($seg->infResp->respSeg ?? '')] ?? '',
                'CNPJ_resp'=> (string)($seg->infResp->CNPJ ?? ''),
                'CPF_resp' => (string)($seg->infResp->CPF ?? ''),
            ];
            if ($seg->infSeg) {
                $segItem['xSeg']     = (string)($seg->infSeg->xSeg ?? '');
                $segItem['CNPJ_seg'] = (string)($seg->infSeg->CNPJ ?? '');
            }
            $segItem['nApol'] = (string)($seg->nApol ?? '');
            // nAver pode ser múltiplo
            $segItem['nAver'] = [];
            if (isset($seg->nAver)) {
                foreach ($seg->nAver as $aver) {
                    $segItem['nAver'][] = (string)$aver;
                }
            }
            $dados['seguros'][] = $segItem;
        }
    }

    // ====== PRODUTO PREDOMINANTE ======
    $prodPred = $infMDFe->prodPred ?? null;
    if ($prodPred) {
        $dados['prodPred'] = [
            'tpCarga'      => (string)($prodPred->tpCarga ?? ''),
            'tpCarga_desc' => $tpCargaMap[(string)($prodPred->tpCarga ?? '')] ?? (string)($prodPred->tpCarga ?? ''),
            'xProd'        => (string)($prodPred->xProd ?? ''),
            'cEAN'         => (string)($prodPred->cEAN ?? ''),
            'NCM'          => (string)($prodPred->NCM ?? ''),
        ];
        // infLotacao
        if ($prodPred->infLotacao) {
            $dados['prodPred']['infLotacao'] = [
                'infLocalCarrega'    => (string)($prodPred->infLotacao->infLocalCarrega->CEP ?? ''),
                'infLocalDescarrega' => (string)($prodPred->infLotacao->infLocalDescarrega->CEP ?? ''),
            ];
        }
    }

    // ====== TOTALIZADORES ======
    $tot = $infMDFe->tot ?? null;
    if ($tot) {
        $dados['qCTe']    = (string)($tot->qCTe ?? '');
        $dados['qNFe']    = (string)($tot->qNFe ?? '');
        $dados['qMDFe']   = (string)($tot->qMDFe ?? '');
        $dados['vCarga']  = (string)($tot->vCarga ?? '');
        $dados['cUnid']   = (string)($tot->cUnid ?? '');
        $dados['cUnid_desc'] = $cUnidMap[$dados['cUnid']] ?? $dados['cUnid'];
        $dados['qCarga']  = (string)($tot->qCarga ?? '');

        if (!empty($dados['vCarga'])) {
            $dados['valor_carga'] = $dados['vCarga'];
        }
        if (!empty($dados['qCarga'])) {
            $dados['peso_carga'] = $dados['qCarga'];
        }
        $dados['qtd_cte'] = $dados['qCTe'] ?: '0';
        $dados['qtd_nfe'] = $dados['qNFe'] ?: '0';
    }

    // ====== LACRES ======
    $dados['lacres'] = [];
    if (isset($infMDFe->lacres)) {
        foreach ($infMDFe->lacres as $lac) {
            $dados['lacres'][] = (string)($lac->nLacre ?? '');
        }
    }

    // ====== AUTORIZADOS XML ======
    $dados['autXML'] = [];
    if (isset($infMDFe->autXML)) {
        foreach ($infMDFe->autXML as $aut) {
            $dados['autXML'][] = [
                'CNPJ' => (string)($aut->CNPJ ?? ''),
                'CPF'  => (string)($aut->CPF ?? ''),
            ];
        }
    }

    // ====== INFORMAÇÕES ADICIONAIS ======
    if (isset($infMDFe->infAdic)) {
        $dados['infAdFisco'] = (string)($infMDFe->infAdic->infAdFisco ?? '');
        $dados['infCpl']     = (string)($infMDFe->infAdic->infCpl ?? '');
    }

    // Salva o XML bruto para exibição
    $dados['xml_bruto'] = $xmlStr;

    return $dados;
}

// ============================================================
// Gera HTML da modal de consulta
// ============================================================
function renderConsultaHtml($dados, $eventos = [], $nfeIncluidas = [], $condutoresIncluidos = []) {
    $html = '';

    // Helper: formata CNPJ
    $fmtCnpj = function($v) {
        $v = preg_replace('/\D/', '', $v);
        if (strlen($v) !== 14) return $v ?: '-';
        return preg_replace('/(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})/', '$1.$2.$3/$4-$5', $v);
    };
    $fmtCpf = function($v) {
        $v = preg_replace('/\D/', '', $v);
        if (strlen($v) !== 11) return $v ?: '-';
        return preg_replace('/(\d{3})(\d{3})(\d{3})(\d{2})/', '$1.$2.$3-$4', $v);
    };
    $fmtDoc = function($v) use ($fmtCnpj, $fmtCpf) {
        $v = preg_replace('/\D/', '', $v);
        if (strlen($v) === 14) return $fmtCnpj($v);
        if (strlen($v) === 11) return $fmtCpf($v);
        return $v ?: '-';
    };
    $fmtMoney = function($v) {
        if ($v === '' || $v === null) return '-';
        return 'R$ ' . number_format((float)$v, 2, ',', '.');
    };
    $fmtPeso = function($v, $unid = '') {
        if ($v === '' || $v === null) return '-';
        return number_format((float)$v, 4, ',', '.') . ($unid ? ' ' . $unid : '');
    };
    $e = function($v) { return htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8'); };
    $val = function($v) use ($e) { return ($v !== '' && $v !== null) ? $e($v) : '-'; };

    // Status badge
    $status = strtolower($dados['status'] ?? '');
    $statusClass = $status;
    $statusLabel = ucfirst($status);
    $statusBadge = '<span class="nfse-status-tag '.$e($statusClass).'">'.$e($statusLabel).'</span>';

    // ====== CARD: Identificação ======
    $html .= '<div class="nfse-card">';
    $html .= '<div class="nfse-card-header"><span>Identificação do MDF-e</span></div>';
    $html .= '<div class="nfse-card-body nfse-data-grid-3">';
    $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Número</div><div class="nfse-data-value">'.$val($dados['numero'] ?: ($dados['nMDF'] ?? '')).'</div></div>';
    $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Série</div><div class="nfse-data-value">'.$val($dados['serie']).'</div></div>';
    //$html .= '<div class="nfse-data-item"><div class="nfse-data-label">Modelo</div><div class="nfse-data-value">'.$val($dados['mod'] ?? '58').'</div></div>';
    $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Modal</div><div class="nfse-data-value">'.$val($dados['modal_desc']).'</div></div>';
    $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Ambiente</div><div class="nfse-data-value">'.$val($dados['tpAmb_desc'] ?? $dados['ambiente']).'</div></div>';
    //$html .= '<div class="nfse-data-item"><div class="nfse-data-label">Tipo Emissão</div><div class="nfse-data-value">'.$val($dados['tpEmis_desc'] ?? '').'</div></div>';
    $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Tipo Emitente</div><div class="nfse-data-value">'.$val($dados['tpEmit_desc'] ?? '').'</div></div>';
    $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Data Emissão</div><div class="nfse-data-value">'.$val($dados['data_emissao']).'</div></div>';
    $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Início Viagem</div><div class="nfse-data-value">'.$val($dados['dhIniViagem_fmt'] ?? '').'</div></div>';
    if (!empty($dados['indCarregaPosterior']) && $dados['indCarregaPosterior'] === '1') {
        $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Carga Posterior</div><div class="nfse-data-value"><span style="">Sim</span></div></div>';
    }
    $html .= '<div class="nfse-data-item full-width"><div class="nfse-data-label">Chave de Acesso</div><div class="nfse-data-value" style="font-family:Consolas,monospace;font-size:0.88em;word-break:break-all;">'.$val($dados['chave_acesso']).'</div></div>';
    $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Protocolo</div><div class="nfse-data-value" style="font-family:Consolas,monospace;">'.$val($dados['protocolo']).'</div></div>';
    //$html .= '<div class="nfse-data-item"><div class="nfse-data-label">Cód. Status</div><div class="nfse-data-value">'.$val($dados['codigo_status']).'</div></div>';
    // $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Motivo</div><div class="nfse-data-value">'.$val($dados['motivo']).'</div></div>';
    if (!empty($dados['data_encerramento'])) {
        $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Data Encerramento</div><div class="nfse-data-value">'.$val($dados['data_encerramento']).'</div></div>';
        $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Prot. Encerramento</div><div class="nfse-data-value" style="font-family:Consolas,monospace;">'.$val($dados['protocolo_encerramento']).'</div></div>';
    }
    if ($status === 'cancelada' && !empty($dados['motivo_cancelamento'])) {
        $html .= '<div class="nfse-data-item full-width"><div class="nfse-data-label">Justificativa do Cancelamento</div>';
        $html .= '<div class="nfse-data-value" style="background:#fdf2f2;color:#721c24;padding:8px 12px;border-radius:4px;border-left:3px solid #dc3545;font-style:italic;">'.$val($dados['motivo_cancelamento']).'</div></div>';
    }
    $html .= '</div></div>';

    // // ====== CARD: Emitente ======
    // if (!empty($dados['emit_xNome'])) {
    //     $html .= '<div class="nfse-card">';
    //     $html .= '<div class="nfse-card-header"><span>🏢 Emitente</span></div>';
    //     $html .= '<div class="nfse-card-body nfse-data-grid-2">';
    //     $html .= '<div class="nfse-data-item full-width"><div class="nfse-data-label">Razão Social</div><div class="nfse-data-value">'.$val($dados['emit_xNome']).'</div></div>';
    //     if (!empty($dados['emit_xFant'])) {
    //         $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Nome Fantasia</div><div class="nfse-data-value">'.$val($dados['emit_xFant']).'</div></div>';
    //     }
    //     $docEmit = !empty($dados['emit_CNPJ']) ? $fmtCnpj($dados['emit_CNPJ']) : (!empty($dados['emit_CPF']) ? $fmtCpf($dados['emit_CPF']) : '-');
    //     $html .= '<div class="nfse-data-item"><div class="nfse-data-label">CNPJ/CPF</div><div class="nfse-data-value">'.$e($docEmit).'</div></div>';
    //     $html .= '<div class="nfse-data-item"><div class="nfse-data-label">IE</div><div class="nfse-data-value">'.$val($dados['emit_IE'] ?? '').'</div></div>';
    //     // Endereço
    //     $endParts = array_filter([
    //         $dados['emit_xLgr'] ?? '', 
    //         !empty($dados['emit_nro']) ? 'nº '.$dados['emit_nro'] : '',
    //         $dados['emit_xCpl'] ?? '',
    //     ]);
    //     $endereco = implode(', ', $endParts);
    //     if ($endereco) {
    //         $html .= '<div class="nfse-data-item full-width"><div class="nfse-data-label">Endereço</div><div class="nfse-data-value">'.$e($endereco).'</div></div>';
    //     }
    //     $locParts = array_filter([
    //         $dados['emit_xBairro'] ?? '',
    //         ($dados['emit_xMun'] ?? '') . (!empty($dados['emit_UF']) ? '/'.$dados['emit_UF'] : ''),
    //     ]);
    //     if (!empty($locParts)) {
    //         $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Bairro / Cidade</div><div class="nfse-data-value">'.$e(implode(' - ', $locParts)).'</div></div>';
    //     }
    //     if (!empty($dados['emit_CEP'])) {
    //         $cep = $dados['emit_CEP'];
    //         if (strlen($cep) === 8) $cep = substr($cep,0,5).'-'.substr($cep,5);
    //         $html .= '<div class="nfse-data-item"><div class="nfse-data-label">CEP</div><div class="nfse-data-value">'.$e($cep).'</div></div>';
    //     }
    //     if (!empty($dados['emit_fone'])) {
    //         $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Telefone</div><div class="nfse-data-value">'.$val($dados['emit_fone']).'</div></div>';
    //     }
    //     if (!empty($dados['emit_email'])) {
    //         $html .= '<div class="nfse-data-item"><div class="nfse-data-label">E-mail</div><div class="nfse-data-value">'.$val($dados['emit_email']).'</div></div>';
    //     }
    //     $html .= '</div></div>';
    // }

    // ====== CARD: Percurso ======
    // Versão simples: sem ícones, sem fundos/cores extras, só dados essenciais.
    $ufIni  = trim((string)($dados['uf_ini'] ?? ''));
    $ufFim  = trim((string)($dados['uf_fim'] ?? ''));

    $ufIniShow = ($ufIni !== '' ? $e($ufIni) : '-');
    $ufFimShow = ($ufFim !== '' ? $e($ufFim) : '-');

    $munCarregaTexto = '';
    if (!empty($dados['municipios_carrega']) && is_array($dados['municipios_carrega'])) {
        $mcs = array_filter(array_map(function($mc) {
            return is_array($mc) ? trim((string)($mc['xMunCarrega'] ?? '')) : '';
        }, $dados['municipios_carrega']));
        $mcs = array_values(array_unique($mcs));
        $munCarregaTexto = !empty($mcs) ? implode(', ', $mcs) : '';
    } elseif (!empty($dados['mun_carrega'])) {
        $munCarregaTexto = (string)$dados['mun_carrega'];
    }

    $munDescargaTexto = '';
    if (!empty($dados['documentos']) && is_array($dados['documentos'])) {
        $mds = array_filter(array_map(function($d) {
            return is_array($d) ? trim((string)($d['xMunDescarga'] ?? '')) : '';
        }, $dados['documentos']));
        $mds = array_values(array_unique($mds));
        $munDescargaTexto = !empty($mds) ? implode(', ', $mds) : '';
    } elseif (!empty($dados['mun_descarga'])) {
        $munDescargaTexto = (string)$dados['mun_descarga'];
    }

    $munCarregaShow = ($munCarregaTexto !== '' ? $e($munCarregaTexto) : '-');
    $munDescargaShow = ($munDescargaTexto !== '' ? $e($munDescargaTexto) : '-');

    $html .= '<div class="nfse-card">';
    $html .= '<div class="nfse-card-header"><span>Percurso</span></div>';
    $html .= '<div class="nfse-card-body nfse-data-grid-2">';
    $html .= '<div class="nfse-data-item full-width">'
        .'<div class="nfse-data-value" style="display:flex;justify-content:center;">'
        .'<div style="display:grid;grid-template-columns:auto auto auto;align-items:center;gap:10px;">'
        .'  <div style="display:flex;flex-direction:column;gap:5px;text-align:center;">'
        .'    <div>'
        .'      <div style="font-size:0.75em;color:#999;margin-bottom:1px;">UF Início</div>'
        .'      <div style="font-size:0.95em;">'.$ufIniShow.'</div>'
        .'    </div>'
        .'    <div>'
        .'      <div style="font-size:0.75em;color:#999;margin-bottom:1px;">Mun. Carga</div>'
        .'      <div style="font-size:0.93em;">'.$munCarregaShow.'</div>'
        .'    </div>'
        .'  </div>'
        .'  <div style="font-size:26px;line-height:1;color:#aaa;padding:0 8px;align-self:center;">→</div>'
        .'  <div style="display:flex;flex-direction:column;gap:5px;text-align:center;">'
        .'    <div>'
        .'      <div style="font-size:0.75em;color:#999;margin-bottom:1px;">UF Fim</div>'
        .'      <div style="font-size:0.95em;">'.$ufFimShow.'</div>'
        .'    </div>'
        .'    <div>'
        .'      <div style="font-size:0.75em;color:#999;margin-bottom:1px;">Mun. Descarga</div>'
        .'      <div style="font-size:0.93em;">'.$munDescargaShow.'</div>'
        .'    </div>'
        .'  </div>'
        .'</div>'
        .'</div>'
        .'</div>';
    $html .= '</div></div>';

    // ====== CARD: Modal Específico ======
    $tipoModal = $dados['tipo_modal'] ?? 'rodo';

    if ($tipoModal === 'rodo') {
        // Veículo de tração
        if (!empty($dados['veicTracao'])) {
            $vt = $dados['veicTracao'];
            $html .= '<div class="nfse-card">';
            $html .= '<div class="nfse-card-header"><span>🚛 Veículo de Tração</span></div>';
            $html .= '<div class="nfse-card-body nfse-data-grid-3">';
            $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Placa</div><div class="nfse-data-value"><strong>'.$val($vt['placa']).'</strong></div></div>';
            if (!empty($vt['RENAVAM'])) $html .= '<div class="nfse-data-item"><div class="nfse-data-label">RENAVAM</div><div class="nfse-data-value">'.$val($vt['RENAVAM']).'</div></div>';
            if (!empty($vt['cInt'])) $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Cód. Interno</div><div class="nfse-data-value">'.$val($vt['cInt']).'</div></div>';
            $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Tara (kg)</div><div class="nfse-data-value">'.$val($vt['tara']).'</div></div>';
            $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Cap. KG</div><div class="nfse-data-value">'.$val($vt['capKG']).'</div></div>';
            if (!empty($vt['capM3'])) $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Cap. M³</div><div class="nfse-data-value">'.$val($vt['capM3']).'</div></div>';
            $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Tipo Rodado</div><div class="nfse-data-value">'.$val($vt['tpRod_desc']).'</div></div>';
            $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Tipo Carroceria</div><div class="nfse-data-value">'.$val($vt['tpCar_desc']).'</div></div>';
            $html .= '<div class="nfse-data-item"><div class="nfse-data-label">UF</div><div class="nfse-data-value">'.$val($vt['UF']).'</div></div>';
            // Proprietário
            if (!empty($vt['prop'])) {
                $docProp = !empty($vt['prop']['CNPJ']) ? $fmtCnpj($vt['prop']['CNPJ']) : (!empty($vt['prop']['CPF']) ? $fmtCpf($vt['prop']['CPF']) : '-');
                $html .= '<div class="nfse-data-item full-width" style="margin-top:8px;border-top:1px solid #eee;padding-top:8px;"><div class="nfse-data-label">Proprietário</div><div class="nfse-data-value">'.$e($vt['prop']['xNome'] ?? '').' ('.$e($docProp).')</div></div>';
            }
            $html .= '</div></div>';
        }

        // Condutores (originais do XML + incluídos via evento)
        if (!empty($dados['condutores']) || !empty($condutoresIncluidos)) {
            $html .= '<div class="nfse-card">';
            $html .= '<div class="nfse-card-header"><span>👤 Condutor(es)</span></div>';
            $html .= '<div class="nfse-card-body nfse-data-grid-2">';
            foreach ($dados['condutores'] as $idx => $cond) {
                if ($idx > 0) {
                    $html .= '<div class="nfse-data-item full-width" style="border-top:1px solid #f0f0f0;margin:4px 0;padding:0;"></div>';
                }
                $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Nome</div><div class="nfse-data-value">'.$val($cond['xNome']).'</div></div>';
                $html .= '<div class="nfse-data-item"><div class="nfse-data-label">CPF</div><div class="nfse-data-value">'.$e($fmtCpf($cond['CPF'])).'</div></div>';
            }
            if (!empty($condutoresIncluidos)) {
                $html .= '<div class="nfse-data-item full-width" style="border-top:1px dashed #dee2e6;margin:8px 0 4px;padding:0;"></div>';
                $html .= '<div class="nfse-data-item full-width" style="padding:0 0 4px 0;"><div class="nfse-data-label" style="color:#6c757d;font-size:0.75em;text-transform:uppercase;letter-spacing:.4px;">Incluídos após emissão</div></div>';
                foreach ($condutoresIncluidos as $idxInc => $condInc) {
                    if ($idxInc > 0) {
                        $html .= '<div class="nfse-data-item full-width" style="border-top:1px solid #f0f0f0;margin:4px 0;padding:0;"></div>';
                    }
                    $cpfIncFmt = $fmtCpf(preg_replace('/\D/', '', $condInc->cpf ?? ''));
                    $horarioCond = !empty($condInc->data_evento) ? date('d/m/Y H:i', strtotime($condInc->data_evento)) : '';
                    $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Nome</div><div class="nfse-data-value">'.$val($condInc->xNome).'</div></div>';
                    $html .= '<div class="nfse-data-item"><div class="nfse-data-label">CPF</div><div class="nfse-data-value">'.$e($cpfIncFmt).'</div></div>';
                    $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Inclu&iacute;do em</div><div class="nfse-data-value" style="color:#6c757d;">'.($horarioCond ? $e($horarioCond) : '-').'</div></div>';
                }
            }
            $html .= '</div></div>';
        }

        // RNTRC / ANTT
        if (!empty($dados['RNTRC']) || !empty($dados['contratantes']) || !empty($dados['CIOT']) || !empty($dados['codAgPorto'])) {
            $html .= '<div class="nfse-card">';
            $html .= '<div class="nfse-card-header"><span>📄 ANTT</span></div>';
            $html .= '<div class="nfse-card-body nfse-data-grid-2">';
            if (!empty($dados['RNTRC'])) {
                $html .= '<div class="nfse-data-item"><div class="nfse-data-label">RNTRC</div><div class="nfse-data-value">'.$val($dados['RNTRC']).'</div></div>';
            }
            if (!empty($dados['codAgPorto'])) {
                $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Cód. Agend. Porto</div><div class="nfse-data-value">'.$val($dados['codAgPorto']).'</div></div>';
            }
            // CIOT
            if (!empty($dados['CIOT'])) {
                foreach ($dados['CIOT'] as $idx => $ciot) {
                    $docCiot = !empty($ciot['CNPJ']) ? $fmtCnpj($ciot['CNPJ']) : (!empty($ciot['CPF']) ? $fmtCpf($ciot['CPF']) : '-');
                    $html .= '<div class="nfse-data-item"><div class="nfse-data-label">CIOT '.($idx+1).'</div><div class="nfse-data-value">'.$val($ciot['nCIOT']).' <small>('.$e($docCiot).')</small></div></div>';
                }
            }
            // Contratantes
            if (!empty($dados['contratantes'])) {
                foreach ($dados['contratantes'] as $idx => $contr) {
                    $docContr = !empty($contr['CNPJ']) ? $fmtCnpj($contr['CNPJ']) : (!empty($contr['CPF']) ? $fmtCpf($contr['CPF']) : '-');
                    $label = 'Contratante '.($idx+1);
                    $html .= '<div class="nfse-data-item"><div class="nfse-data-label">'.$label.'</div><div class="nfse-data-value">'.$e($docContr);
                    if (!empty($contr['xNome'])) $html .= ' - '.$e($contr['xNome']);
                    $html .= '</div></div>';
                }
            }
            $html .= '</div></div>';
        }

        // Pagamentos (infPag no infANTT)
        if (!empty($dados['pagamentos'])) {
            $indPagLabels = ['0' => 'À Vista', '1' => 'A Prazo'];
            $tpCompLabels = ['01' => 'Frete', '02' => 'Carga', '03' => 'Descarga', '04' => 'Pedágio', '05' => 'Outros'];
            $html .= '<div class="nfse-card">';
            $html .= '<div class="nfse-card-header"><span>💳 Pagamento(s)</span></div>';
            $html .= '<div class="nfse-card-body" style="display:block;">';
            foreach ($dados['pagamentos'] as $idx => $pag) {
                if ($idx > 0) $html .= '<hr style="border:none;border-top:1px solid #eee;margin:10px 0;">';
                $docPag = !empty($pag['CNPJ']) ? $fmtCnpj($pag['CNPJ']) : (!empty($pag['CPF']) ? $fmtCpf($pag['CPF']) : '');
                $html .= '<div class="nfse-data-grid-2" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:8px 16px;">';
                if (!empty($pag['xNome'])) $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Responsável</div><div class="nfse-data-value">'.$val($pag['xNome']).'</div></div>';
                if ($docPag) $html .= '<div class="nfse-data-item"><div class="nfse-data-label">CPF/CNPJ</div><div class="nfse-data-value">'.$e($docPag).'</div></div>';
                if (isset($pag['indPag']) && $pag['indPag'] !== '') {
                    $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Forma</div><div class="nfse-data-value">'.$e($indPagLabels[$pag['indPag']] ?? $pag['indPag']).'</div></div>';
                }
                if (!empty($pag['vContrato'])) $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Valor Contrato</div><div class="nfse-data-value"><strong>'.$e($fmtMoney($pag['vContrato'])).'</strong></div></div>';
                // Componentes
                if (!empty($pag['Comp'])) {
                    foreach ($pag['Comp'] as $comp) {
                        $tpLabel = $tpCompLabels[$comp['tpComp']] ?? $comp['tpComp'];
                        $descComp = $tpLabel . ': ' . $e($fmtMoney($comp['vComp']));
                        if (!empty($comp['xComp'])) $descComp .= ' ('.$e($comp['xComp']).')';
                        $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Componente</div><div class="nfse-data-value">'.$descComp.'</div></div>';
                    }
                }
                // Banco
                if (!empty($pag['codBanco']) || !empty($pag['codAgencia']) || !empty($pag['CNPJIPEF']) || !empty($pag['PIX'])) {
                    $html .= '<div class="nfse-data-item full-width" style="border-top:1px dashed #e0e0e0;margin-top:6px;padding-top:6px;"><div class="nfse-data-label">Dados Bancários</div></div>';
                    if (!empty($pag['codBanco']))   $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Banco</div><div class="nfse-data-value">'.$val($pag['codBanco']).'</div></div>';
                    if (!empty($pag['codAgencia'])) $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Agência</div><div class="nfse-data-value">'.$val($pag['codAgencia']).'</div></div>';
                    if (!empty($pag['CNPJIPEF']))   $html .= '<div class="nfse-data-item"><div class="nfse-data-label">CNPJ IPEF</div><div class="nfse-data-value">'.$e($fmtCnpj($pag['CNPJIPEF'])).'</div></div>';
                    if (!empty($pag['PIX']))        $html .= '<div class="nfse-data-item"><div class="nfse-data-label">PIX</div><div class="nfse-data-value">'.$val($pag['PIX']).'</div></div>';
                }
                $html .= '</div>';
            }
            $html .= '</div></div>';
        }

        // Reboques
        if (!empty($dados['veicReboque'])) {
            $html .= '<div class="nfse-card">';
            $html .= '<div class="nfse-card-header"><span>🔗 Veículo(s) Reboque</span></div>';
            $html .= '<div class="nfse-card-body nfse-data-grid-3">';
            foreach ($dados['veicReboque'] as $idx => $reb) {
                if ($idx > 0) $html .= '<div class="nfse-data-item full-width" style="border-top:1px solid #eee;margin-top:6px;padding-top:6px;"><div class="nfse-data-label">Reboque '.($idx+1).'</div></div>';
                $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Placa</div><div class="nfse-data-value"><strong>'.$val($reb['placa']).'</strong></div></div>';
                if (!empty($reb['RENAVAM'])) $html .= '<div class="nfse-data-item"><div class="nfse-data-label">RENAVAM</div><div class="nfse-data-value">'.$val($reb['RENAVAM']).'</div></div>';
                if (!empty($reb['cInt']))    $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Cód. Interno</div><div class="nfse-data-value">'.$val($reb['cInt']).'</div></div>';
                $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Tara</div><div class="nfse-data-value">'.$val($reb['tara']).'</div></div>';
                $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Cap. KG</div><div class="nfse-data-value">'.$val($reb['capKG']).'</div></div>';
                if (!empty($reb['capM3']))   $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Cap. M³</div><div class="nfse-data-value">'.$val($reb['capM3']).'</div></div>';
                $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Tipo Carroceria</div><div class="nfse-data-value">'.$val($reb['tpCar_desc']).'</div></div>';
                $html .= '<div class="nfse-data-item"><div class="nfse-data-label">UF</div><div class="nfse-data-value">'.$val($reb['UF']).'</div></div>';
            }
            $html .= '</div></div>';
        }

        // Lacres rodoviários
        if (!empty($dados['lacres_rodo'])) {
            $html .= '<div class="nfse-card">';
            $html .= '<div class="nfse-card-header"><span>🔒 Lacres Rodoviários</span></div>';
            $html .= '<div class="nfse-card-body nfse-data-grid-3">';
            foreach ($dados['lacres_rodo'] as $lac) {
                $html .= '<div class="nfse-data-item"><div class="nfse-data-value">'.$val($lac).'</div></div>';
            }
            $html .= '</div></div>';
        }
    }

    // Aéreo
    if ($tipoModal === 'aereo' && !empty($dados['aereo'])) {
        $a = $dados['aereo'];
        $html .= '<div class="nfse-card">';
        $html .= '<div class="nfse-card-header"><span>✈️ Modal Aéreo</span></div>';
        $html .= '<div class="nfse-card-body nfse-data-grid-3">';
        $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Nacionalidade</div><div class="nfse-data-value">'.$val($a['nac']).'</div></div>';
        $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Matrícula</div><div class="nfse-data-value">'.$val($a['matr']).'</div></div>';
        $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Nº Voo</div><div class="nfse-data-value">'.$val($a['nVoo']).'</div></div>';
        $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Aeródromo Embarque</div><div class="nfse-data-value">'.$val($a['cAerEmb']).'</div></div>';
        $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Aeródromo Destino</div><div class="nfse-data-value">'.$val($a['cAerDes']).'</div></div>';
        if (!empty($a['dVoo'])) $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Data Voo</div><div class="nfse-data-value">'.$val($a['dVoo']).'</div></div>';
        $html .= '</div></div>';
    }

    // Aquaviário
    if ($tipoModal === 'aquav' && !empty($dados['aquav'])) {
        $aq = $dados['aquav'];
        $html .= '<div class="nfse-card">';
        $html .= '<div class="nfse-card-header"><span>🚢 Modal Aquaviário</span></div>';
        $html .= '<div class="nfse-card-body nfse-data-grid-3">';
        if (!empty($aq['irin'])) $html .= '<div class="nfse-data-item"><div class="nfse-data-label">IRIN</div><div class="nfse-data-value">'.$val($aq['irin']).'</div></div>';
        if (!empty($aq['xEmbar'])) $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Embarcação</div><div class="nfse-data-value">'.$val($aq['xEmbar']).'</div></div>';
        if (!empty($aq['nViag'])) $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Nº Viagem</div><div class="nfse-data-value">'.$val($aq['nViag']).'</div></div>';
        if (!empty($aq['cPrtEmb'])) $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Porto Embarque</div><div class="nfse-data-value">'.$val($aq['cPrtEmb']).'</div></div>';
        if (!empty($aq['cPrtDest'])) $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Porto Destino</div><div class="nfse-data-value">'.$val($aq['cPrtDest']).'</div></div>';
        $html .= '</div></div>';
    }

    // Ferroviário
    if ($tipoModal === 'ferrov' && !empty($dados['ferrov'])) {
        $fe = $dados['ferrov'];
        $html .= '<div class="nfse-card">';
        $html .= '<div class="nfse-card-header"><span>🚂 Modal Ferroviário</span></div>';
        $html .= '<div class="nfse-card-body nfse-data-grid-3">';
        if (!empty($fe['xPref'])) $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Prefixo</div><div class="nfse-data-value">'.$val($fe['xPref']).'</div></div>';
        if (!empty($fe['xOri'])) $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Origem</div><div class="nfse-data-value">'.$val($fe['xOri']).'</div></div>';
        if (!empty($fe['xDest'])) $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Destino</div><div class="nfse-data-value">'.$val($fe['xDest']).'</div></div>';
        if (!empty($fe['qVag'])) $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Qtd. Vagões</div><div class="nfse-data-value">'.$val($fe['qVag']).'</div></div>';
        if (!empty($fe['vagoes'])) {
            $html .= '<div class="nfse-data-item full-width"><div class="nfse-data-label">Vagões</div></div>';
            foreach ($fe['vagoes'] as $vag) {
                $html .= '<div class="nfse-data-item"><div class="nfse-data-value">Vagão '.$val($vag['nVag']).': '.$val($vag['pesoR']).'kg</div></div>';
            }
        }
        $html .= '</div></div>';
    }

    // ====== CARD: Documentos Vinculados ======
    // Extrai chaves das NF-es incluídas via evento (vindas do banco, parâmetro $nfeIncluidas)
    $chNfeIncl = array_map(function($n) { return $n->chNFe; }, $nfeIncluidas);
    $chNfeIncl = array_unique($chNfeIncl);

    if (!empty($dados['documentos']) || !empty($chNfeIncl)) {
        // Verifica se há ao menos um município com documentos
        $totalDocs = 0;
        if (!empty($dados['documentos'])) {
            foreach ($dados['documentos'] as $doc) {
                $totalDocs += count($doc['chaves_cte']) + count($doc['chaves_nfe']) + count($doc['chaves_mdfe']);
            }
        }
        $totalDocs += count($chNfeIncl);

        $html .= '<div class="nfse-card">';
        $html .= '<div class="nfse-card-header"><span>&#128230; Documentos Vinculados</span></div>';
        $html .= '<div class="nfse-card-body" style="display:block;">';

        if ($totalDocs === 0) {
            // Nenhum documento — carga posterior ou pendente de vínculo
            $isPost = !empty($dados['indCarregaPosterior']) && $dados['indCarregaPosterior'] === '1';
            $html .= '<div style="padding:12px;background:'.($isPost ? '#fff3cd' : '#f8f9fa').';border-radius:6px;color:'.($isPost ? '#856404' : '#6c757d').';font-size:0.92em;">';
            if ($isPost) {
                $html .= 'Os documentos fiscais ser&atilde;o vinculados a este MDF-e ap&oacute;s a emiss&atilde;o.';
            } else {
                $html .= 'Nenhum documento fiscal vinculado.';
            }
            $html .= '</div>';
        } else {
            // Lista plana de documentos, sem cabeçalho de cidade
            $allCte = []; $allNfe = []; $allMdfe = [];
            if (!empty($dados['documentos'])) {
                foreach ($dados['documentos'] as $doc) {
                    foreach ($doc['chaves_cte']  as $c) $allCte[]  = $c['chCTe'];
                    foreach ($doc['chaves_nfe']  as $n) $allNfe[]  = $n['chNFe'];
                    foreach ($doc['chaves_mdfe'] as $m) $allMdfe[] = $m['chMDFe'];
                }
            }
            // Adiciona NF-e incluídas via evento (que não estejam já na lista original)
            foreach ($chNfeIncl as $chInc) {
                if (!in_array($chInc, $allNfe)) {
                    $allNfe[] = $chInc;
                }
            }
            if (!empty($allCte)) {
                $html .= '<div style="margin-bottom:10px;">';
                $html .= '<div class="nfse-data-label" style="margin-bottom:4px;">CT-e ('.count($allCte).')</div>';
                foreach ($allCte as $chave) {
                    $html .= '<div style="font-family:Consolas,monospace;font-size:0.92em;color:#555;margin-bottom:2px;word-break:break-all;">'.$e($chave).'</div>';
                }
                $html .= '</div>';
            }
            if (!empty($allNfe)) {
                // Mapa chNFe → data_evento para exibir o horário da inclusão
                $nfeIncMap = [];
                foreach ($nfeIncluidas as $nfeInc) {
                    $nfeIncMap[$nfeInc->chNFe] = $nfeInc->data_evento;
                }
                foreach ($allNfe as $idxNfe => $chave) {
                    $isIncluida = in_array($chave, $chNfeIncl);
                    $borderTop = ($idxNfe > 0) ? 'border-top:1px solid #f0f0f0;margin-top:6px;padding-top:6px;' : '';
                    $html .= '<div style="margin-bottom:4px;'.$borderTop.'">';
                    $html .= '<div class="nfse-data-label" style="margin-bottom:2px;">NF-e</div>';
                    $html .= '<div style="font-family:Consolas,monospace;font-size:1em;color:#555;word-break:break-all;">'.$e($chave).'</div>';
                    if ($isIncluida) {
                        $horarioInc = (!empty($nfeIncMap[$chave])) ? date('d/m/Y H:i', strtotime($nfeIncMap[$chave])) : '';
                        $txtInc = 'inclu&iacute;da durante percurso' . ($horarioInc ? ' em ' . $horarioInc : '');
                        $html .= '<div style="font-size:0.9em;color:#155724;margin-top:1px;">'.$txtInc.'</div>';
                    }
                    $html .= '</div>';
                }
            }
            if (!empty($allMdfe)) {
                $html .= '<div>';
                $html .= '<div class="nfse-data-label" style="margin-bottom:4px;">MDF-e ('.count($allMdfe).')</div>';
                foreach ($allMdfe as $chave) {
                    $html .= '<div style="font-family:Consolas,monospace;font-size:0.82em;color:#555;margin-bottom:2px;word-break:break-all;">'.$e($chave).'</div>';
                }
                $html .= '</div>';
            }
        }

        $html .= '</div></div>';
    }

    // ====== CARD: Seguro ======
    if (!empty($dados['seguros'])) {
        $html .= '<div class="nfse-card">';
        $html .= '<div class="nfse-card-header"><span>🛡️ Seguro</span></div>';
        $html .= '<div class="nfse-card-body" style="display:block;">';
        foreach ($dados['seguros'] as $idx => $seg) {
            if ($idx > 0) $html .= '<hr style="border:none;border-top:1px solid #eee;margin:10px 0;">';
            $html .= '<div class="nfse-data-grid-2" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:8px 16px;">';
            if (!empty($seg['respSeg_desc'])) {
                $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Responsável</div><div class="nfse-data-value">'.$val($seg['respSeg_desc']).'</div></div>';
            }
            if (!empty($seg['xSeg'])) {
                $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Seguradora</div><div class="nfse-data-value">'.$val($seg['xSeg']).'</div></div>';
            }
            if (!empty($seg['CNPJ_seg'])) {
                $html .= '<div class="nfse-data-item"><div class="nfse-data-label">CNPJ Seguradora</div><div class="nfse-data-value">'.$e($fmtCnpj($seg['CNPJ_seg'])).'</div></div>';
            }
            if (!empty($seg['nApol'])) {
                $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Nº Apólice</div><div class="nfse-data-value">'.$val($seg['nApol']).'</div></div>';
            }
            if (!empty($seg['nAver'])) {
                $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Averbação</div><div class="nfse-data-value">'.$e(implode(', ', $seg['nAver'])).'</div></div>';
            }
            $html .= '</div>';
        }
        $html .= '</div></div>';
    }

    // ====== CARD: Produto Predominante ======
    if (!empty($dados['prodPred'])) {
        $pp = $dados['prodPred'];
        $html .= '<div class="nfse-card">';
        $html .= '<div class="nfse-card-header"><span>📦 Produto Predominante</span></div>';
        $html .= '<div class="nfse-card-body nfse-data-grid-3">';
        $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Produto</div><div class="nfse-data-value">'.$val($pp['xProd']).'</div></div>';
        $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Tipo Carga</div><div class="nfse-data-value">'.$val($pp['tpCarga_desc']).'</div></div>';
        if (!empty($pp['cEAN'])) $html .= '<div class="nfse-data-item"><div class="nfse-data-label">EAN</div><div class="nfse-data-value">'.$val($pp['cEAN']).'</div></div>';
        if (!empty($pp['NCM'])) $html .= '<div class="nfse-data-item"><div class="nfse-data-label">NCM</div><div class="nfse-data-value">'.$val($pp['NCM']).'</div></div>';
        if (!empty($pp['infLotacao'])) {
            $cepCarga = $pp['infLotacao']['infLocalCarrega'] ?? '';
            $cepDesc  = $pp['infLotacao']['infLocalDescarrega'] ?? '';
            if ($cepCarga || $cepDesc) {
                $html .= '<div class="nfse-data-item full-width" style="border-top:1px dashed #e0e0e0;margin-top:6px;padding-top:6px;"><div class="nfse-data-label">Lotação</div></div>';
                if ($cepCarga) {
                    $cepCargaFmt = strlen(preg_replace('/\D/','',$cepCarga)) === 8 ? substr(preg_replace('/\D/','',$cepCarga),0,5).'-'.substr(preg_replace('/\D/','',$cepCarga),5) : $cepCarga;
                    $html .= '<div class="nfse-data-item"><div class="nfse-data-label">CEP Carregamento</div><div class="nfse-data-value">'.$e($cepCargaFmt).'</div></div>';
                }
                if ($cepDesc) {
                    $cepDescFmt = strlen(preg_replace('/\D/','',$cepDesc)) === 8 ? substr(preg_replace('/\D/','',$cepDesc),0,5).'-'.substr(preg_replace('/\D/','',$cepDesc),5) : $cepDesc;
                    $html .= '<div class="nfse-data-item"><div class="nfse-data-label">CEP Descarregamento</div><div class="nfse-data-value">'.$e($cepDescFmt).'</div></div>';
                }
            }
        }
        $html .= '</div></div>';
    }

    // ====== CARD: Totalizadores ======
    $html .= '<div class="nfse-card">';
    $html .= '<div class="nfse-card-header"><span>📊 Totalizadores</span></div>';
    $html .= '<div class="nfse-card-body nfse-data-grid-3">';
    $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Valor da Carga</div><div class="nfse-data-value"><strong>'.$e($fmtMoney($dados['vCarga'] ?? $dados['valor_carga'])).'</strong></div></div>';
    $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Peso da Carga</div><div class="nfse-data-value">'.$e($fmtPeso($dados['qCarga'] ?? $dados['peso_carga'], $dados['cUnid_desc'] ?? '')).'</div></div>';
    if (!empty($dados['qCTe'])) $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Qtd. CT-e</div><div class="nfse-data-value">'.$val($dados['qCTe']).'</div></div>';
    if (!empty($dados['qNFe'])) $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Qtd. NF-e</div><div class="nfse-data-value">'.$val($dados['qNFe']).'</div></div>';
    if (!empty($dados['qMDFe'])) $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Qtd. MDF-e</div><div class="nfse-data-value">'.$val($dados['qMDFe']).'</div></div>';
    $html .= '</div></div>';

    // ====== CARD: Lacres ======
    if (!empty($dados['lacres'])) {
        $html .= '<div class="nfse-card">';
        $html .= '<div class="nfse-card-header"><span>🔒 Lacres</span></div>';
        $html .= '<div class="nfse-card-body nfse-data-grid-3">';
        foreach ($dados['lacres'] as $lac) {
            $html .= '<div class="nfse-data-item"><div class="nfse-data-value">'.$val($lac).'</div></div>';
        }
        $html .= '</div></div>';
    }

    // ====== CARD: Informações Adicionais ======
    if (!empty($dados['infCpl']) || !empty($dados['infAdFisco'])) {
        $html .= '<div class="nfse-card">';
        $html .= '<div class="nfse-card-header"><span>ℹ️ Informações Adicionais</span></div>';
        $html .= '<div class="nfse-card-body" style="display:block;">';
        if (!empty($dados['infAdFisco'])) {
            $html .= '<div class="nfse-data-item" style="margin-bottom:10px;"><div class="nfse-data-label">Informações ao Fisco</div><div class="nfse-data-value" style="white-space:pre-wrap;">'.$val($dados['infAdFisco']).'</div></div>';
        }
        if (!empty($dados['infCpl'])) {
            $html .= '<div class="nfse-data-item"><div class="nfse-data-label">Informações Complementares</div><div class="nfse-data-value" style="white-space:pre-wrap;">'.$val($dados['infCpl']).'</div></div>';
        }
        $html .= '</div></div>';
    }

    // // ====== XML Bruto ======
    // if (!empty($dados['xml_bruto'])) {
    //     $html .= '<details class="nfse-xml-details">';
    //     $html .= '<summary>Ver XML Completo</summary>';
    //     // Formata o XML para exibição
    //     $dom = new DOMDocument('1.0', 'UTF-8');
    //     $dom->preserveWhiteSpace = false;
    //     $dom->formatOutput = true;
    //     if (@$dom->loadXML($dados['xml_bruto'])) {
    //         $xmlFormatted = $dom->saveXML();
    //     } else {
    //         $xmlFormatted = $dados['xml_bruto'];
    //     }
    //     $html .= '<pre>'.htmlspecialchars($xmlFormatted, ENT_QUOTES, 'UTF-8').'</pre>';
    //     $html .= '</details>';
    // }

    // ====== CARD: Histórico de Eventos ======
    if (!empty($eventos)) {
        $tpEventoLabels = [
            '110111' => 'Cancelamento',
            '110112' => 'Encerramento',
            '110114' => 'Inclusão de Condutor',
            '110115' => 'Inclusão de NF-e',
            '110116' => 'Pagamento de Transporte',
        ];
        $tpEventoColors = [
            '110111' => '#c0392b',
            '110112' => '#555',
            '110114' => '#1a7fa0',
            '110115' => '#217a45',
            '110116' => '#c47000',
        ];

        // Mapa de protocolo → condutor incluído (para exibir no evento 110114)
        $condMapByProt = [];
        foreach ($condutoresIncluidos as $condInc) {
            $condMapByProt[$condInc->protocolo_evento] = $condInc;
        }

        $html .= '<div class="nfse-card">';
        $html .= '<div class="nfse-card-header"><span>Histórico de Eventos</span></div>';
        $html .= '<div class="nfse-card-body" style="display:block;padding:0;">';
        
        foreach ($eventos as $idx => $evt) {
            $tpLabel  = $tpEventoLabels[$evt->tpEvento] ?? ('Evento ' . htmlspecialchars($evt->tpEvento, ENT_QUOTES, 'UTF-8'));
            $tpColor  = $tpEventoColors[$evt->tpEvento] ?? '#555';
            $dtEvento = !empty($evt->data_evento) ? date('d/m/Y H:i:s', strtotime($evt->data_evento)) : '-';
            $borderTop = ($idx > 0) ? 'border-top:1px solid #f0f0f0;' : '';

            $html .= '<div style="'.$borderTop.'padding:11px 16px;">';

            // Linha 1: tipo (seq) + data
            $tpLabelSeq = $tpLabel . ' (' . htmlspecialchars($evt->nSeqEvento, ENT_QUOTES, 'UTF-8') . ')';
            $html .= '<div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:3px;">';
            $html .= '<span style="font-weight:600;font-size:0.98em;color:'.$tpColor.';">'.$tpLabelSeq.'</span>';
            $html .= '<span style="font-size:0.96em;color:#aaa;">'.htmlspecialchars($dtEvento, ENT_QUOTES, 'UTF-8').'</span>';
            $html .= '</div>';

            // Linha 2: Protocolo
            if (!empty($evt->protocolo_evento)) {
                $html .= '<div style="font-size:0.96em;color:#999;">Protocolo: <span style="font-family:Consolas,monospace;letter-spacing:.02em;">'.htmlspecialchars($evt->protocolo_evento, ENT_QUOTES, 'UTF-8').'</span></div>';
            }

            // CANCELAMENTO: justificativa do operador
            if ($evt->tpEvento === '110111' && !empty($dados['motivo_cancelamento'])) {
                $html .= '<div style="font-size:0.81em;color:#721c24;margin-top:5px;padding-left:8px;border-left:2px solid #e0a0a0;">';
                $html .= 'Justificativa: '.htmlspecialchars($dados['motivo_cancelamento'], ENT_QUOTES, 'UTF-8');
                $html .= '</div>';
            }

            // INCLUSÃO DE NF-e: chave(s) via tabela específica
            if ($evt->tpEvento === '110115') {
                $nfeDesteEvento = [];
                foreach ($nfeIncluidas as $nfeInc) {
                    if ($nfeInc->protocolo_evento === $evt->protocolo_evento) {
                        $nfeDesteEvento[] = $nfeInc->chNFe;
                    }
                }
                // Fallback: regex no XML de resposta
                if (empty($nfeDesteEvento) && !empty($evt->xml_resposta)) {
                    $xmlEvtStr = is_resource($evt->xml_resposta) ? stream_get_contents($evt->xml_resposta) : (string)$evt->xml_resposta;
                    if (preg_match_all('/<chNFe>(\d{44})<\/chNFe>/', $xmlEvtStr, $matchesEvt)) {
                        $nfeDesteEvento = $matchesEvt[1];
                    }
                }
                foreach ($nfeDesteEvento as $chInc) {
                    $html .= '<div style="font-family:Consolas,monospace;font-size:0.96em;color:#444;margin-top:4px;word-break:break-all;">NF-e: '.htmlspecialchars($chInc, ENT_QUOTES, 'UTF-8').'</div>';
                }
            }

            // INCLUSÃO DE CONDUTOR: nome e CPF via tabela específica
            if ($evt->tpEvento === '110114') {
                $condDesteEvento = $condMapByProt[$evt->protocolo_evento] ?? null;
                if ($condDesteEvento) {
                    $cpfFmtEvt = $fmtCpf(preg_replace('/\D/', '', $condDesteEvento->cpf ?? ''));
                    $html .= '<div style="font-size:0.81em;color:#444;margin-top:4px;">';
                    $html .= 'Condutor: '.htmlspecialchars($condDesteEvento->xNome, ENT_QUOTES, 'UTF-8');
                    $html .= ' &middot; CPF: '.htmlspecialchars($cpfFmtEvt, ENT_QUOTES, 'UTF-8');
                    $html .= '</div>';
                }
            }

            $html .= '</div>'; // bloco do evento
        }

        $html .= '</div></div>';
    }

    // Botão fechar
    $html .= '<div style="text-align:center;margin-top:16px;">';
    $html .= '<button class="butActionDelete" onclick="closeNfseModal()">Fechar</button>';
    $html .= '</div>';

    return $html;
}


// ============================================================
// Despacha a ação
// ============================================================
if ($action === 'consultar_xml') {
    // Retorna JSON puro com todos os dados parseados
    header('Content-Type: application/json; charset=UTF-8');
    $dados = parseMdfeXml($xmlStr, $row);
    echo json_encode(['success' => true, 'dados' => $dados], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'consultar_html') {
    // Retorna HTML pronto para renderizar na modal
    header('Content-Type: text/html; charset=UTF-8');
    $dados = parseMdfeXml($xmlStr, $row);

    // Busca eventos gerais desta MDF-e
    $sqlEvt = "SELECT id, tpEvento, nSeqEvento, protocolo_evento, motivo_evento, data_evento,
                      xml_requisicao, xml_resposta
               FROM " . MAIN_DB_PREFIX . "mdfe_eventos
               WHERE fk_mdfe_emitida = " . $mdfeId . "
               ORDER BY data_evento ASC, id ASC";
    $resEvt = $db->query($sqlEvt);
    $eventos = [];
    if ($resEvt) {
        while ($evtRow = $db->fetch_object($resEvt)) {
            $eventos[] = $evtRow;
        }
    }

    // Busca NF-es incluídas via evento (tabela específica)
    $nfeIncluidas = [];
    $sqlNfeInc = "SELECT chNFe, protocolo_evento, cStat, xMotivo, data_evento
                  FROM " . MAIN_DB_PREFIX . "mdfe_inclusao_nfe
                  WHERE fk_mdfe_emitida = " . $mdfeId . "
                  ORDER BY data_evento ASC";
    $resNfeInc = $db->query($sqlNfeInc);
    if ($resNfeInc) {
        while ($r = $db->fetch_object($resNfeInc)) {
            $nfeIncluidas[] = $r;
        }
    }

    // Busca condutores incluídos via evento (tabela específica)
    $condutoresIncluidos = [];
    $sqlCondInc = "SELECT xNome, cpf, protocolo_evento, cStat, xMotivo, data_evento
                   FROM " . MAIN_DB_PREFIX . "mdfe_inclusao_condutor
                   WHERE fk_mdfe_emitida = " . $mdfeId . "
                   ORDER BY data_evento ASC";
    $resCondInc = $db->query($sqlCondInc);
    if ($resCondInc) {
        while ($r = $db->fetch_object($resCondInc)) {
            $condutoresIncluidos[] = $r;
        }
    }

    if (!empty($dados['xml_parse_error']) && empty($xmlStr)) {
        echo '<div class="nfse-alert nfse-alert-error">'
            . htmlspecialchars($dados['xml_parse_error'], ENT_QUOTES, 'UTF-8')
            . '</div>'
            . '<div style="text-align:center;margin-top:16px;"><button class="butActionDelete" onclick="closeNfseModal()">Fechar</button></div>';
        exit;
    }

    echo renderConsultaHtml($dados, $eventos, $nfeIncluidas, $condutoresIncluidos);
    exit;
}

// Ação não reconhecida
header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['success' => false, 'error' => 'Ação não reconhecida.'], JSON_UNESCAPED_UNICODE);
exit;
