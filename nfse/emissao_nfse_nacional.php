<?php
/**
 * Emissão de NFS-e no Padrão Nacional
 * Adaptado para integração com Dolibarr
 */
@ini_set('display_errors', '1');
@ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('America/Sao_Paulo');
require_once DOL_DOCUMENT_ROOT . '/custom/composerlib/vendor/autoload.php';
require_once DOL_DOCUMENT_ROOT . '/custom/nfse/lib/municipios_es.php'; // Helper de municípios (mesmo do padrão municipal)
require_once DOL_DOCUMENT_ROOT . '/custom/composerlib/vendor/paseto/nfse-nacional-pdf/src/NfsePdfGenerator.php';
require_once DOL_DOCUMENT_ROOT.'/custom/nfse/lib/nfse_security.lib.php';
require_once DOL_DOCUMENT_ROOT.'/custom/nfse/lib/nfse_nacional_validator.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/files.lib.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

/**
 * Carrega certificado A1 do banco de dados (mesmo método do padrão municipal)
 */
function carregarCertificadoA1Nacional($db) {
    $certPfx = null;
    $certPass = null;

    // Tenta tabela key/value
    $tableKv = (defined('MAIN_DB_PREFIX') ? MAIN_DB_PREFIX : '') . 'nfe_config';
    $res = @$db->query("SELECT name, value FROM `".$tableKv."`");
    if ($res) {
        while ($row = $db->fetch_object($res)) {
            if ($row->name === 'cert_pfx') $certPfx = $row->value;
            if ($row->name === 'cert_pass') $certPass = $row->value;
        }
    }

    // Fallback para tabela com colunas diretas
    if (empty($certPfx)) {
        $tableDirect = MAIN_DB_PREFIX . 'nfe_config';
        $res2 = @$db->query("SELECT cert_pfx, cert_pass FROM `".$tableDirect."` LIMIT 1");
        if ($res2 && $obj = $db->fetch_object($res2)) {
            $certPfx = $obj->cert_pfx;
            $certPass = $obj->cert_pass;
        }
    }

    // Normaliza BLOB/stream
    if (is_resource($certPfx)) {
        $certPfx = stream_get_contents($certPfx);
    }
    
    if ($certPfx === null || $certPfx === '') {
        throw new Exception('Certificado PFX não encontrado no banco de dados.');
    }
    
    $certPass = (string)$certPass;
    //$criptografada = nfseEncryptPassword($certPass, $db);
    $pass = nfseDecryptPassword($certPass, $db);
    // Fallback: se descriptografia retornou vazio (senha em texto plano / sem encryption_master_key),
    // usa o valor bruto armazenado
    if ($pass === '' && $certPass !== '') {
        $pass = $certPass;
    }
    
    // Retorna certificado usando a biblioteca NFePHP
    try {
        $cert = \NFePHP\Common\Certificate::readPfx($certPfx, $pass);
        return $cert;
    } catch (Exception $e) {
        // Tenta decodificar base64 se falhar
        $certPfxDecoded = base64_decode($certPfx, true);
        if ($certPfxDecoded !== false) {
            $cert = \NFePHP\Common\Certificate::readPfx($certPfxDecoded, $certPass);
            return $cert;
        }
        throw new Exception('Erro ao ler certificado: ' . $e->getMessage());
    }
}

/**
 * Busca configuração de ambiente (produção/homologação)
 */
function getAmbienteNacional($db) {
    $ambiente = 2; // Padrão: homologação
    
    $sql = "SELECT value FROM ".MAIN_DB_PREFIX."nfe_config WHERE name = 'ambiente' LIMIT 1";
    $res = $db->query($sql);
    if ($res && $obj = $db->fetch_object($res)) {
        $ambiente = (int)$obj->value;
    }
    
    return $ambiente;
}

/**
 * Busca série configurada para o ambiente e CNPJ especificados
 */
function getSerieNacional($db, $cnpj, $ambiente) {
    $cnpjE = $db->escape($cnpj);
    $ambienteE = (int)$ambiente;
    
    // Busca a série do registro mais recente para este CNPJ e ambiente
    $sql = "SELECT serie FROM ".MAIN_DB_PREFIX."nfse_nacional_sequencias 
            WHERE cnpj = '".$cnpjE."' AND ambiente = ".$ambienteE."
            ORDER BY updated_at DESC, id DESC LIMIT 1";
    $res = $db->query($sql);
    
    if ($res && $obj = $db->fetch_object($res)) {
        return $obj->serie ?: '1';
    }
    
    return '1'; // Padrão: série 1
}

/**
 * Obtém próximos números de DPS (similar ao RPS no padrão municipal)
 */
function obterProximoNumeroDPS($db, $cnpj, $serie, $ambiente, $numeroCustom = null) {
    $cnpjE = $db->escape($cnpj);
    $serieE = $db->escape($serie);
    $ambienteE = (int)$ambiente;
    
    // Tenta bloquear linha existente
    $sqlSel = "SELECT id, next_dps 
               FROM ".MAIN_DB_PREFIX."nfse_nacional_sequencias 
               WHERE cnpj = '".$cnpjE."' AND serie = '".$serieE."' AND ambiente = ".$ambienteE."
               FOR UPDATE";
    $resSel = $db->query($sqlSel);
    
    if ($resSel && $db->num_rows($resSel) > 0) {
        $obj = $db->fetch_object($resSel);
        $sequenciaId = (int)$obj->id;

        // Usa número customizado apenas para o XML — a sequência NÃO é alterada aqui.
        // A atualização acontece somente após autorização e só se o número for maior que o atual.
        $numeroDPS = ($numeroCustom !== null && $numeroCustom > 0) ? (int)$numeroCustom : (int)$obj->next_dps;

        // NOTA: não incrementamos aqui — somente após confirmação de autorização
        return [$numeroDPS, $sequenciaId];
    }
    
    // Não existe: cria nova sequência começando do histórico
    $maxDPS = 0;
    $sqlMax = "SELECT MAX(CAST(numero_dps AS UNSIGNED)) AS max_dps
               FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas
               WHERE prestador_cnpj = '".$cnpjE."' AND ambiente = ".$ambienteE;
    if ($res = $db->query($sqlMax)) {
        if ($obj = $db->fetch_object($res)) {
            $maxDPS = (int)$obj->max_dps;
        }
    }
    
    $numeroDPS = $maxDPS + 1;

    // Número customizado é ignorado aqui: o INSERT registra o próximo automático.
    // A sequência será ajustada após autorização se o número customizado for maior.
    global $mysoc;
    if (empty($mysoc->id)) {
        $mysoc->fetch(0);
    }
    $im = preg_replace('/\D/', '', $mysoc->idprof3 ?? '');
    $imE = $db->escape($im);
    
    // Ao criar nova sequência, define next_dps como o número atual (será incrementado após autorização)
    $sqlIns = "INSERT INTO ".MAIN_DB_PREFIX."nfse_nacional_sequencias 
               (cnpj, im, serie, next_dps, ambiente, updated_at)
               VALUES ('".$cnpjE."', '".$imE."', '".$serieE."', ".($numeroDPS).", ".$ambienteE.", NOW())";
    if (!$db->query($sqlIns)) {
        throw new Exception('Erro ao criar sequência de DPS: ' . $db->lasterror());
    }
    
    $sequenciaId = (int)($db->last_insert_id(MAIN_DB_PREFIX.'nfse_nacional_sequencias', 'id') ?: ($db->insert_id ?? 0));
    
    return [$numeroDPS, $sequenciaId];
}


/**
 * Incrementa a sequência apenas após confirmação de autorização
 */
function incrementarSequenciasNacional($db, $sequenciaId, $increment = 1) {
    $sqlUpd = "UPDATE ".MAIN_DB_PREFIX."nfse_nacional_sequencias 
               SET next_dps = next_dps + ".((int)$increment).", updated_at = NOW()
               WHERE id = ".((int)$sequenciaId);
    if (!$db->query($sqlUpd)) {
        throw new Exception('Erro ao incrementar sequência de DPS: ' . $db->lasterror());
    }
}

/**
 * Função principal: Gera NFS-e no padrão nacional
 * 
 * @param object $db Conexão com banco de dados
 * @param array $dadosFatura Dados da fatura do Dolibarr
 * @param array $dadosEmitente Dados da empresa emitente
 * @param array $dadosDestinatario Dados do cliente (tomador)
 * @param array $listaServicos Lista de serviços da fatura
 */
function gerarNfseNacional($db, $dadosFatura, $dadosEmitente, $dadosDestinatario, $listaServicos, $numeroCustom = null) {
    global $langs;
    
    // Validações de dados (schema, campos obrigatórios, tipos)
    $errosValidacao = validarDadosNfseNacional($db, $dadosFatura, $dadosEmitente, $dadosDestinatario, $listaServicos, $numeroCustom);
    if (!empty($errosValidacao)) {
        foreach ($errosValidacao as $erroVal) {
            setEventMessages($erroVal, null, 'errors');
        }
        return;
    }

    // Validações básicas (mantidas por retrocompatibilidade)
    if (empty($dadosFatura['id'])) {
        setEventMessages('Fatura inválida para emissão de NFS-e Nacional', null, 'errors');
        return;
    }
    
    if (empty($listaServicos) || !is_array($listaServicos)) {
        setEventMessages('Nenhum serviço encontrado na fatura', null, 'errors');
        return;
    }
    
    try {
        // Carrega certificado
        $cert = carregarCertificadoA1Nacional($db);
        
        // Busca ambiente (1=Produção, 2=Homologação)
        $ambiente = getAmbienteNacional($db);
        
        // Cria configuração JSON para a biblioteca
        $config = new stdClass();
        $config->tpamb = $ambiente;
        $configJson = json_encode($config);
        
        // Inicializa ferramenta de emissão
        $tools = new \Hadder\NfseNacional\Tools($configJson, $cert);
        
        // ========== MONTA ESTRUTURA DA DPS ==========
        $std = new stdClass();
        $std->infDPS = new stdClass();
        
        // Dados gerais
        $std->infDPS->tpAmb = $ambiente;
        $std->infDPS->dhEmi = (new DateTime())->format('Y-m-d\TH:i:sP'); // Data/hora de emissão com timezone
        $std->infDPS->verAplic = '1.01';
        
        // CNPJ do emitente (necessário para buscar série)
        $cnpjEmitente = preg_replace('/\D/', '', $dadosEmitente['cnpj'] ?? '');
        
        if (empty($cnpjEmitente) || strlen($cnpjEmitente) !== 14) {
            throw new Exception('CNPJ do emitente inválido ou ausente');
        }
        
        // Busca série configurada para este ambiente
        $serie = getSerieNacional($db, $cnpjEmitente, $ambiente);
        
        // Obtém próximo número de DPS (respeita número informado manualmente pelo usuário)
        list($numeroDPS, $sequenciaId) = obterProximoNumeroDPS($db, $cnpjEmitente, $serie, $ambiente, $numeroCustom);
        
        $std->infDPS->serie = (int)$serie;
        $std->infDPS->nDPS = (int)$numeroDPS;
        
        // Data de competência (usa data da fatura ou hoje)
        $dataCompetencia = date('Y-m-d');
        $std->infDPS->dCompet = $dataCompetencia;
        
        $std->infDPS->tpEmit = 1; // 1=Prestador, 2=Tomador, 3=Intermediário
        
        // Código do município do emitente (IBGE 7 dígitos) - Busca pelo nome da cidade
        $nomeCidadeEmitente = $dadosEmitente['municipio'] ?? $dadosEmitente['town'] ?? '';
        $codigoMunicipioEmitente = nfse_buscar_codigo_municipio($nomeCidadeEmitente);
        
        if (!$codigoMunicipioEmitente) {
            // Fallback: tenta pelos extrafields ou usa padrão
            $codigoMunicipioEmitente = $dadosEmitente['codigo_municipio'] ?? $dadosEmitente['extrafields']['codigo_do_municipio'] ?? '3201209';
        }
        
        // Garante 7 dígitos e valida
        $codigoMunicipioEmitente = preg_replace('/\D/', '', $codigoMunicipioEmitente);
        $codigoMunicipioEmitente = str_pad($codigoMunicipioEmitente, 7, '0', STR_PAD_LEFT);
        $std->infDPS->cLocEmi = $codigoMunicipioEmitente;
        
        // ========== DADOS DO PRESTADOR ==========
        $std->infDPS->prest = new stdClass();
        $std->infDPS->prest->CNPJ = $cnpjEmitente;
        
        if (!empty($dadosEmitente['telefone'])) {
            $std->infDPS->prest->fone = preg_replace('/\D/', '', $dadosEmitente['telefone']);
        }
        
        if (!empty($dadosEmitente['email'])) {
            $std->infDPS->prest->email = $dadosEmitente['email'];
        }
        
        // Regime tributário
        $std->infDPS->prest->regTrib = new stdClass();
        
        // CRT: 1=Simples Nacional, 2=Simples Nacional - Excesso, 3=Regime Normal
        $crt = (int)$dadosEmitente['crt'];
        $regimeTributacao = (int)$dadosEmitente['regimeTributacao'];
        if ($crt === 1) {
            if ($regimeTributacao === 6){
                $std->infDPS->prest->regTrib->opSimpNac = 3; // 3=Optante ME/EPP
            }elseif ($regimeTributacao === 5){
                $std->infDPS->prest->regTrib->opSimpNac = 2; // 2=Optante MEI
            }
        } else {
            $std->infDPS->prest->regTrib->opSimpNac = 1; // 1=Não Optante
        }
        $std->infDPS->prest->regTrib->regApTribSN = 1;
        $std->infDPS->prest->regTrib->regEspTrib = 0; // 0=Nenhum regime especial
        
        // ========== DADOS DO TOMADOR ==========
        $std->infDPS->toma = new stdClass();
        
        // CNPJ ou CPF
        $cnpjcpfTomador = preg_replace('/\D/', '', $dadosDestinatario['cnpj'] ?? '');
        
        if (empty($cnpjcpfTomador)) {
            throw new Exception('CNPJ/CPF do tomador ausente');
        }
        
        if (strlen($cnpjcpfTomador) === 14) {
            $std->infDPS->toma->CNPJ = $cnpjcpfTomador;
        } elseif (strlen($cnpjcpfTomador) === 11) {
            $std->infDPS->toma->CPF = $cnpjcpfTomador;
        } else {
            throw new Exception('CNPJ/CPF do tomador inválido (tamanho incorreto)');
        }
        
        $std->infDPS->toma->xNome = $dadosDestinatario['nome'] ?? '';
        
        if (!empty($dadosDestinatario['telefone'])) {
            $std->infDPS->toma->fone = preg_replace('/\D/', '', $dadosDestinatario['telefone']);
        }
        
        if (!empty($dadosDestinatario['email'])) {
            $std->infDPS->toma->email = $dadosDestinatario['email'];
        }
        
        // Endereço do tomador
        $std->infDPS->toma->end = new stdClass();
        $std->infDPS->toma->end->xLgr = $dadosDestinatario['endereco'];
        $std->infDPS->toma->end->nro = $dadosDestinatario['numero'] ?? 'S/N';
        
        if (!empty($dadosDestinatario['complemento'])) {
            $std->infDPS->toma->end->xCpl = $dadosDestinatario['complemento'];
        }
        
        $std->infDPS->toma->end->xBairro = $dadosDestinatario['bairro'];
        
        // Endereço nacional
        $std->infDPS->toma->end->endNac = new stdClass();
        
        // Código do município do tomador (IBGE 7 dígitos) - Busca pelo nome da cidade
        $nomeCidadeTomador = $dadosDestinatario['municipio'] ?? $dadosDestinatario['town'] ?? '';
        $codigoMunicipioTomador = nfse_buscar_codigo_municipio($nomeCidadeTomador);
        
        if (!$codigoMunicipioTomador) {
            // Fallback: tenta pelos extrafields ou usa padrão
            $codigoMunicipioTomador = $dadosDestinatario['codigo_municipio'] ?? $dadosDestinatario['extrafields']['codigo_do_municipio'] ?? '3201209';
            error_log('[NFSE NACIONAL] Município do tomador não encontrado pelo nome ("'.$nomeCidadeTomador.'"). Usando código: '.$codigoMunicipioTomador);
        }
        
        // Garante 7 dígitos e valida
        $codigoMunicipioTomador = preg_replace('/\D/', '', $codigoMunicipioTomador);
        $codigoMunicipioTomador = str_pad($codigoMunicipioTomador, 7, '0', STR_PAD_LEFT);
        $std->infDPS->toma->end->endNac->cMun = $codigoMunicipioTomador;
        ($codigoMunicipioTomador) . ' (' . $codigoMunicipioTomador . ')';
        
        $cepTomador = preg_replace('/\D/', '', $dadosDestinatario['cep'] ?? '');
        if (empty($cepTomador) || strlen($cepTomador) !== 8) {
            $cepTomador = '00000000';
        }
        $std->infDPS->toma->end->endNac->CEP = $cepTomador;
        
        // ========== DADOS DO SERVIÇO ==========
        $std->infDPS->serv = new stdClass();
        
        // Localização da prestação (município de incidência do ISS)
        $std->infDPS->serv->locPrest = new stdClass();
        
        // Prioridade: campo muni_prest da fatura (se existir)
        $municipioIncidencia = $dadosFatura['extrafields']['muni_prest'] ?? '';
        
        // Se muni_prest estiver vazio, usa município do emitente
        if (empty($municipioIncidencia)) {
            $municipioIncidencia = $codigoMunicipioEmitente;
        } else {
            // Se foi informado, valida e garante formato correto
            if (!nfse_validar_codigo_municipio($municipioIncidencia)) {
                // Tenta buscar pelo nome caso não seja um código válido
                $codigoTemp = nfse_buscar_codigo_municipio($municipioIncidencia);
                if ($codigoTemp) {
                    $municipioIncidencia = $codigoTemp;
                } else {
                    error_log('[NFSE NACIONAL] Município de incidência inválido: "'.$municipioIncidencia.'". Usando município do emitente.');
                    $municipioIncidencia = $codigoMunicipioEmitente;
                }
            }
        }
        
        // Garante 7 dígitos
        $municipioIncidencia = preg_replace('/\D/', '', $municipioIncidencia);
        $municipioIncidencia = str_pad($municipioIncidencia, 7, '0', STR_PAD_LEFT);
        $std->infDPS->serv->locPrest->cLocPrestacao = $municipioIncidencia;
        
        // Código do serviço
        $std->infDPS->serv->cServ = new stdClass();
        
        if (!empty($listaServicos[0])) {
            $primeiroServico = $listaServicos[0];
            
            // Código de tributação nacional (extraído do código completo)
            $codigoCompleto = $primeiroServico['extrafields']['srv_cod_itemlistaservico'];
            $descricaoServico = $primeiroServico['servico_prestado'] ?? $primeiroServico['descricao'] ?? '';
        }
        
        $std->infDPS->serv->cServ->cTribNac = $codigoCompleto;

        // ========== CÓDIGOS DE SERVIÇO QUE REQUEREM OBRA OU EVENTO ==========
        // Listas centralizadas no validador (nfse_nacional_validator.lib.php)
        $codigosObra = nfseNacGetCodigosObra();
        $codigosEvento = nfseNacGetCodigosEvento();
        
        $requerObra = in_array($codigoCompleto, $codigosObra);
        $requerEvento = in_array($codigoCompleto, $codigosEvento);
        
        // ========== DADOS DE OBRA (construção civil) ==========
        if ($requerObra) {
            $inscImobFisc = $dadosFatura['extrafields']['inscImobFisc'] ?? '';
            $cObra = $dadosFatura['extrafields']['cObra'] ?? '';
            $cepObra = preg_replace('/\D/', '', $dadosFatura['extrafields']['cep'] ?? '');
            $xLgrObra = $dadosFatura['extrafields']['xlgr'] ?? '';
            $nroObra = $dadosFatura['extrafields']['nro'] ?? '';
            $xBairroObra = $dadosFatura['extrafields']['xbairro'] ?? '';
            
            // Só adiciona o grupo obra se tiver pelo menos cObra preenchido
            if (!empty($cObra)) {
                $std->infDPS->serv->obra = new stdClass();
                
                if (!empty($inscImobFisc)) {
                    $std->infDPS->serv->obra->inscImobFisc = $inscImobFisc;
                }
                
                $std->infDPS->serv->obra->cObra = $cObra;
                
                // // Endereço da obra
                // if (!empty($cepObra) && strlen($cepObra) == 8) {
                //     $std->infDPS->serv->obra->end = new stdClass();
                //     $std->infDPS->serv->obra->end->CEP = $cepObra;
                    
                //     if (!empty($xLgrObra)) {
                //         $std->infDPS->serv->obra->end->xLgr = $xLgrObra;
                //     }
                //     if (!empty($nroObra)) {
                //         $std->infDPS->serv->obra->end->nro = $nroObra;
                //     }
                //     if (!empty($xBairroObra)) {
                //         $std->infDPS->serv->obra->end->xBairro = $xBairroObra;
                //     }
                // }
                
                //error_log('[NFSE NACIONAL] Grupo OBRA adicionado - cObra: ' . $cObra);
            } else {
                error_log('[NFSE NACIONAL] Serviço requer OBRA mas cObra não foi preenchido');
            }
        }
        
        // ========== DADOS DE EVENTO ==========
        if ($requerEvento) {
            $xNomeEvento = $dadosFatura['extrafields']['xnomeevento'] ?? '';
            $dtIni = $dadosFatura['extrafields']['dtini'] ?? '';
            $dtFim = $dadosFatura['extrafields']['dtfim'] ?? '';
            $cepEvento = preg_replace('/\D/', '', $dadosFatura['extrafields']['cep'] ?? '');
            $xLgrEvento = $dadosFatura['extrafields']['xlgr'] ?? '';
            $nroEvento = $dadosFatura['extrafields']['nro'] ?? '';
            $xBairroEvento = $dadosFatura['extrafields']['xbairro'] ?? '';
            
            // Só adiciona o grupo evento se tiver pelo menos o nome e as datas preenchidas
            // NOTA: xNome é OBRIGATÓRIO no schema e deve vir ANTES de dtIni
            if (!empty($xNomeEvento) && !empty($dtIni) && !empty($dtFim)) {
                $std->infDPS->serv->atvevento = new stdClass();
                
                // xNome - Nome do evento (OBRIGATÓRIO - deve ser o primeiro elemento)
                $std->infDPS->serv->atvevento->xNome = $xNomeEvento;
                
                // Formata datas para o padrão ISO (YYYY-MM-DD)
                if (is_numeric($dtIni)) {
                    $dtIni = date('Y-m-d', $dtIni);
                } else {
                    $dtIni = date('Y-m-d', strtotime($dtIni));
                }
                
                if (is_numeric($dtFim)) {
                    $dtFim = date('Y-m-d', $dtFim);
                } else {
                    $dtFim = date('Y-m-d', strtotime($dtFim));
                }
                
                $std->infDPS->serv->atvevento->dtIni = $dtIni;
                $std->infDPS->serv->atvevento->dtFim = $dtFim;
                
                // Endereço do evento (XSD exige CEP, xLgr, nro, xBairro quando end é usado)
                if (!empty($cepEvento) && strlen($cepEvento) == 8) {
                    $std->infDPS->serv->atvevento->end = new stdClass();
                    $std->infDPS->serv->atvevento->end->CEP = $cepEvento;
                    $std->infDPS->serv->atvevento->end->xLgr = !empty($xLgrEvento) ? $xLgrEvento : 'N/I';
                    $std->infDPS->serv->atvevento->end->nro = !empty($nroEvento) ? $nroEvento : 'S/N';
                    $std->infDPS->serv->atvevento->end->xBairro = !empty($xBairroEvento) ? $xBairroEvento : 'N/I';
                }
                
                error_log('[NFSE NACIONAL] Grupo EVENTO adicionado - xNome: ' . $xNomeEvento . ', dtIni: ' . $dtIni . ', dtFim: ' . $dtFim);
            } else {
                error_log('[NFSE NACIONAL] Serviço requer EVENTO mas campos obrigatórios não preenchidos (xNome, dtIni, dtFim)');
            }
        }

        //$discriminacao = $dadosFatura['extrafields']['discriminacao'];
        $descParts = [];
        foreach($listaServicos as $servico){
            if (!empty($servico['descricao'])) {
                $descParts[] = $servico['descricao'];
            }
        }
        $desc = implode('. ', $descParts);
        //$discriminacao = $listaServicos['descricao'];
        $std->infDPS->serv->cServ->xDescServ = $desc;

        // ========== VALORES ==========
        
        // Calcula totais de todos os serviços
        $valorServicosTotal = 0;
        $valorIssTotal = 0;
        $valorPisTotal = 0;
        $valorCofinsTotal = 0;
        $valorInssTotal = 0;
        $valorIrTotal = 0;
        $valorCsllTotal = 0;
        
        foreach ($listaServicos as $index => $servico) {
            $valorLinha = (float)($servico['total_semtaxa'] ?? 0);
            $valorServicosTotal += $valorLinha;
            $valorIssTotal += (float)($servico['valor_iss'] ?? 0);
            $valorPisTotal += (float)($servico['valor_pis'] ?? 0);
            $valorCofinsTotal += (float)($servico['valor_cofins'] ?? 0);
            $valorInssTotal += (float)($servico['valor_inss'] ?? 0);
            $valorIrTotal += (float)($servico['valor_ir'] ?? 0);
            $valorCsllTotal += (float)($servico['valor_csll'] ?? 0);
        }

        $std->infDPS->valores = new stdClass();
        $std->infDPS->valores->vServPrest = new stdClass();
        $std->infDPS->valores->vServPrest->vServ = number_format($valorServicosTotal, 2, '.', '');
    
        // Tributação
        $std->infDPS->valores->trib = new stdClass();
        $std->infDPS->valores->trib->tribMun = new stdClass();
        if ($codigoCompleto == '990101') {
            $std->infDPS->valores->trib->tribMun->tribISSQN = 4; // Não Incidência
        } else {
            $std->infDPS->valores->trib->tribMun->tribISSQN = 1; // Operação tributável (OBRIGATÓRIO — sem este valor o schema rejeita com RNG6110)
        }

        $issRetido = (int)($listaServicos[0]['extrafields']['iss_retido']);
        if ($codigoCompleto !== '990101') {
            if ($issRetido == 1) {
                $std->infDPS->valores->trib->tribMun->tpRetISSQN = 2; // Retido pelo Tomador

                // Busca alíquota do banco de dados quando retido
                $sqlAliquota = "SELECT aliquota_iss FROM ".MAIN_DB_PREFIX."nfse_codigo_servico WHERE codigo = '".$db->escape($codigoCompleto)."'";
                $resAliquota = $db->query($sqlAliquota);
                if ($resAliquota && $db->num_rows($resAliquota) > 0) {
                    $objAliquota = $db->fetch_object($resAliquota);
                    if ($objAliquota && $objAliquota->aliquota_iss > 0) {
                        $std->infDPS->valores->trib->tribMun->pAliq = number_format($objAliquota->aliquota_iss, 2, '.', '');
                    }
                }
            } elseif($issRetido == 2) {
                $std->infDPS->valores->trib->tribMun->tpRetISSQN = 1; // Não Retido
            }
        }
        // Total de tributos (apenas para Simples Nacional)
        if ($crt === 1) {
            $std->infDPS->valores->trib->totTrib = new stdClass();
            // Calcula percentual aproximado de tributos sobre o total
            $percentualTributos = 0;
            if ($valorServicosTotal > 0) {
                $totalTributos = $valorIssTotal + $valorPisTotal + $valorCofinsTotal + $valorInssTotal + $valorIrTotal + $valorCsllTotal;
                $percentualTributos = ($totalTributos / $valorServicosTotal) * 100;
            }
            $std->infDPS->valores->trib->totTrib->pTotTribSN = number_format($percentualTributos, 2, '.', '');
        }
        
        $dps = new \Hadder\NfseNacional\Dps($std);
        $xmlDPS = $dps->render();
        file_put_contents('/custom/nfse/testedps'.$dadosFatura['id'].'.xml', $xmlDPS);

        // Salva DPS no banco ANTES do envio
        // Reusa registro existente se a nota já foi rejeitada/erro para evitar poluição na lista
        $dataHoraEnvio = date('Y-m-d H:i:s');
        $idDpsEmitida = 0;
        
        $sqlBuscaExistente = "SELECT id FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas
                              WHERE id_fatura = ".(int)$dadosFatura['id']."
                              AND ambiente = ".$ambiente."
                              AND status IN ('REJEITADA', 'ERRO', 'ENVIANDO')
                              ORDER BY id DESC LIMIT 1";
        $resExistente = $db->query($sqlBuscaExistente);
        if ($resExistente && $objExistente = $db->fetch_object($resExistente)) {
            $idDpsEmitida = (int)$objExistente->id;
        }
        
        if ($idDpsEmitida > 0) {
            // Reaproveitamento: atualiza o registro existente com os novos dados de tentativa
            $sqlUpsert = "UPDATE ".MAIN_DB_PREFIX."nfse_nacional_emitidas SET
                numero_dps        = '".$db->escape($numeroDPS)."',
                serie             = '".$db->escape($serie)."',
                data_emissao      = '".$db->escape($dataCompetencia)."',
                prestador_cnpj    = '".$db->escape($cnpjEmitente)."',
                prestador_nome    = '".$db->escape($dadosEmitente['razao_social'])."',
                tomador_cnpjcpf   = '".$db->escape($cnpjcpfTomador)."',
                tomador_nome      = '".$db->escape($dadosDestinatario['nome'])."',
                valor_servicos    = '".$db->escape(number_format($valorServicosTotal, 2, '.', ''))."',
                valor_iss         = '".$db->escape(number_format($valorIssTotal, 2, '.', ''))."',
                cod_servico       = '".$db->escape($codigoCompleto)."',
                descricao_servico = '".$db->escape($desc)."',
                status            = 'ENVIANDO',
                xml_enviado       = '".$db->escape($xmlDPS)."',
                mensagem_retorno  = NULL,
                xml_retorno       = NULL,
                data_hora_envio   = '".$db->escape($dataHoraEnvio)."'
                WHERE id = ".$idDpsEmitida;
            if (!$db->query($sqlUpsert)) {
                throw new Exception('Erro ao reutilizar registro de DPS: ' . $db->lasterror());
            }
            error_log('[NFSE NACIONAL] Reutilizando registro id='.$idDpsEmitida.' para fatura '.$dadosFatura['id']);
        } else {
            // Nenhum registro reutilizável: insere novo
            $sqlInsert = "INSERT INTO ".MAIN_DB_PREFIX."nfse_nacional_emitidas (
                id_fatura,
                numero_dps,
                serie,
                data_emissao,
                prestador_cnpj,
                prestador_nome,
                tomador_cnpjcpf,
                tomador_nome,
                valor_servicos,
                valor_iss,
                cod_servico,
                descricao_servico,
                ambiente,
                status,
                xml_enviado,
                data_hora_envio
            ) VALUES (
                ".(int)$dadosFatura['id'].",
                '".$db->escape($numeroDPS)."',
                '".$db->escape($serie)."',
                '".$db->escape($dataCompetencia)."',
                '".$db->escape($cnpjEmitente)."',
                '".$db->escape($dadosEmitente['razao_social'])."',
                '".$db->escape($cnpjcpfTomador)."',
                '".$db->escape($dadosDestinatario['nome'])."',
                '".$db->escape(number_format($valorServicosTotal, 2, '.', ''))."',
                '".$db->escape(number_format($valorIssTotal, 2, '.', ''))."',
                '".$db->escape($codigoCompleto)."',
                '".$db->escape($desc)."',
                ".$ambiente.",
                'ENVIANDO',
                '".$db->escape($xmlDPS)."',
                '".$db->escape($dataHoraEnvio)."'
            )";
            if (!$db->query($sqlInsert)) {
                throw new Exception('Erro ao registrar DPS no banco: ' . $db->lasterror());
            }
            $idDpsEmitida = (int)($db->last_insert_id(MAIN_DB_PREFIX.'nfse_nacional_emitidas', 'id') ?: ($db->insert_id ?? 0));
            if (!$idDpsEmitida) {
                throw new Exception('Erro ao obter ID da DPS registrada');
            }
        }
        
        // ========== ENVIA DPS PARA A SEFAZ ==========
        error_log('[NFSE NACIONAL] Enviando DPS #'.$numeroDPS.' para ambiente '.$ambiente);
        
        $response = $tools->enviaDps($xmlDPS);
        
        $dataHoraResposta = date('Y-m-d H:i:s');
        
        // ========== PROCESSA RESPOSTA ==========
        $sqlUpdate = "UPDATE ".MAIN_DB_PREFIX."nfse_nacional_emitidas SET ";
        
        if (isset($response['nfseXmlGZipB64'])) {
            // Sucesso: NFS-e autorizada
            $xmlNfse = gzdecode(base64_decode($response['nfseXmlGZipB64']));
            
            $chaveAcesso = $response['chaveAcesso'] ?? '';
            $idDps = $response['idDps'] ?? '';
            
            $sqlUpdate .= "status = 'AUTORIZADA', ";
            $sqlUpdate .= "chave_acesso = '".$db->escape($chaveAcesso)."', ";
            $sqlUpdate .= "id_dps = '".$db->escape($idDps)."', ";
            $sqlUpdate .= "xml_nfse = '".$db->escape($xmlNfse)."', ";
            $sqlUpdate .= "xml_retorno = '".$db->escape(json_encode($response))."', ";
            $sqlUpdate .= "data_hora_autorizacao = '".$db->escape($dataHoraResposta)."' ";
            
            // Extrai número da NFS-e do XML retornado (se disponível)
            // Tenta tag padrão <nNFSe> (sem hífen)
            if (preg_match('/<nNFSe>(\d+)<\/nNFSe>/', $xmlNfse, $matches)) {
                $numeroNfse = $matches[1];
                $sqlUpdate .= ", numero_nfse = '".$db->escape($numeroNfse)."' ";
            } 
            // Fallback para <nNFS-e> (com hífen, caso mude o padrão)
            elseif (preg_match('/<nNFS-e>(\d+)<\/nNFS-e>/', $xmlNfse, $matches)) {
                 $numeroNfse = $matches[1];
                 $sqlUpdate .= ", numero_nfse = '".$db->escape($numeroNfse)."' ";
            }
            
            // Atualiza sequência após autorização
            try {
                if (!empty($sequenciaId)) {
                    // Busca o next_dps atual para comparação
                    $resSeqAtual = $db->query("SELECT next_dps FROM ".MAIN_DB_PREFIX."nfse_nacional_sequencias WHERE id = ".$sequenciaId);
                    $currentNextDps = 0;
                    if ($resSeqAtual && $objSeq = $db->fetch_object($resSeqAtual)) {
                        $currentNextDps = (int)$objSeq->next_dps;
                    }
                    if ($numeroCustom !== null && $numeroCustom > 0) {
                        if ($numeroCustom >= $currentNextDps) {
                            // Número customizado igual ou maior: avança sequência para custom + 1
                            $db->query("UPDATE ".MAIN_DB_PREFIX."nfse_nacional_sequencias SET next_dps = ".($numeroCustom + 1).", updated_at = NOW() WHERE id = ".$sequenciaId);
                            error_log('[NFSE NACIONAL] Sequência definida para número customizado+1: '.($numeroCustom + 1));
                        } else {
                            // Número customizado menor que o atual: preenchendo número antigo, não atualiza sequência
                            error_log('[NFSE NACIONAL] Número customizado ('.$numeroCustom.') abaixo da sequência atual ('.$currentNextDps.'), sequência mantida.');
                        }
                    } else {
                        // Sem número customizado: incremento normal
                        incrementarSequenciasNacional($db, $sequenciaId, 1);
                        error_log('[NFSE NACIONAL] Sequência incrementada normalmente (id='.$sequenciaId.')');
                    }
                }
            } catch (Exception $e) {
                error_log('[NFSE NACIONAL] Falha ao atualizar sequência: '.$e->getMessage());
            }
            
            // ========== CONSULTA PDF OFICIAL DA DANFSE NA API E SALVA NO BANCO ==========
            $pdfSalvoComSucesso = false;
            try {
                // Verifica se campo pdf_danfse existe, se não cria
                $sqlCheck = "SHOW COLUMNS FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas LIKE 'pdf_danfse'";
                $resCheck = $db->query($sqlCheck);
                
                // Consulta o PDF oficial da DANFSe via API do governo (mesma forma que consulta_danfse.php)
                if (!empty($chaveAcesso)) {
                    error_log('[NFSE NACIONAL] Consultando PDF oficial da DANFSe via API...');
                    
                    // Usa o mesmo objeto $tools já criado anteriormente
                    set_time_limit(120); // Aumenta tempo limite para a consulta

                    // 1. PRIMEIRO busca o PDF na API
                    $pdfContent = $tools->consultarDanfse($chaveAcesso);

                    // Valida se o conteúdo retornado é realmente um PDF
                    if (!empty($pdfContent) && strlen($pdfContent) > 100) {
                        // Verifica se não é XML ou erro
                        if (strpos($pdfContent, '<?xml') === false && strpos($pdfContent, '<error') === false) {
                            // 2. Monta SQL com o conteúdo já preenchido
                            $sqlPdf = "UPDATE ".MAIN_DB_PREFIX."nfse_nacional_emitidas 
                                      SET pdf_danfse = '".$db->escape($pdfContent)."' 
                                      WHERE id = ".$idDpsEmitida;
                            
                            // 3. Salva arquivo nos documentos da fatura
                            $facture = new Facture($db);
                            $facture->fetch($dadosFatura['id']);
                            $dir = DOL_DATA_ROOT.'/facture/'.$facture->ref.'/';
                            dol_mkdir($dir);
                            $filename = 'DANFSE-'.$chaveAcesso.'.pdf';
                            $filepath = $dir.$filename;
                            file_put_contents($filepath, $pdfContent);

                            if ($db->query($sqlPdf)) {
                                error_log('[NFSE NACIONAL] PDF oficial da DANFSe salvo no banco com sucesso ('.strlen($pdfContent).' bytes)');
                                $pdfSalvoComSucesso = true;
                                
                                // Verifica se realmente foi salvo
                                $sqlVerifica = "SELECT LENGTH(pdf_danfse) as tamanho FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas WHERE id = ".$idDpsEmitida;
                                $resVerifica = $db->query($sqlVerifica);
                                if ($resVerifica && $objV = $db->fetch_object($resVerifica)) {
                                    error_log('[NFSE NACIONAL] Verificação: PDF tem '.$objV->tamanho.' bytes no banco');
                                }
                            } else {
                                error_log('[NFSE NACIONAL] ERRO ao salvar PDF no banco: '.$db->lasterror());
                            }
                        } else {
                            error_log('[NFSE NACIONAL] API retornou XML/Erro em vez de PDF: ' . substr($pdfContent, 0, 200));
                        }
                    } else {
                        error_log('[NFSE NACIONAL] PDF retornado pela API está vazio ou muito pequeno');
                    }
                } else {
                    error_log('[NFSE NACIONAL] Chave de acesso vazia, impossível consultar PDF');
                }
                
            } catch (Exception $ePdf) {
                error_log('[NFSE NACIONAL] EXCEÇÃO ao consultar/salvar PDF oficial: ' . $ePdf->getMessage());
                error_log('[NFSE NACIONAL] Stack trace: ' . $ePdf->getTraceAsString());
                // Não interrompe o fluxo, apenas loga o erro
            }

            // Mensagem de sucesso da autorização
            $mensagemSucesso = 'NFS-e gerada com sucesso!';
            setEventMessages($mensagemSucesso, null, 'mesgs');
            
            // Mensagem separada do PDF (apenas se gerado com sucesso)
            // if ($pdfSalvoComSucesso) {
            //     setEventMessages('PDF oficial da DANFSe gerado e salvo no banco de dados.', null, 'warnings');
            // }
            
        } else {
            // Erro ou rejeição
            $mensagemErro = '';
            
            if (isset($response['erros'])) {
                if (is_array($response['erros'])) {
                    // Formata array de erros de forma legível
                    $mensagemErro = json_encode($response['erros'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                } elseif (is_string($response['erros'])) {
                    // Se vier como string JSON, decodifica e recodifica com Unicode correto
                    $decoded = json_decode($response['erros'], true);
                    if ($decoded !== null) {
                        $mensagemErro = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
                    } else {
                        $mensagemErro = $response['erros'];
                    }
                } else {
                    $mensagemErro = $response['erros'];
                }
            } elseif (isset($response['mensagem'])) {
                $mensagemErro = $response['mensagem'];
            } else {
                $mensagemErro = json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
            }
            
            $sqlUpdate .= "status = 'REJEITADA', ";
            $sqlUpdate .= "mensagem_retorno = '".$db->escape($mensagemErro)."', ";
            $sqlUpdate .= "xml_retorno = '".$db->escape(json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))."' ";
            
            setEventMessages('Erro ao autorizar NFS-e Nacional: '.$mensagemErro, null, 'errors');
        }
        
        $sqlUpdate .= " WHERE id = ".$idDpsEmitida;
        
        if (!$db->query($sqlUpdate)) {
            error_log('[NFSE NACIONAL] Erro ao atualizar registro: ' . $db->lasterror());
        }
        
        // Log de debug
        error_log('[NFSE NACIONAL] Resposta: '.print_r($response, true));
        
    } catch (Exception $e) {
        $mensagemErro = 'Erro na emissão de NFS-e Nacional: ' . $e->getMessage();
        error_log('[NFSE NACIONAL] '.$mensagemErro);
        setEventMessages($mensagemErro, null, 'errors');
        
        // Atualiza status de erro se já tiver registro
        if (!empty($idDpsEmitida)) {
            $db->query("UPDATE ".MAIN_DB_PREFIX."nfse_nacional_emitidas 
                        SET status = 'ERRO', mensagem_retorno = '".$db->escape($mensagemErro)."'
                        WHERE id = ".$idDpsEmitida);
        }
    }
}
