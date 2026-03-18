<?php
/**
 * Validador modular de NFS-e Nacional — baseado na lógica de emissão
 *
 * Cada regra espelha exatamente um campo/tag XML montado em gerarNfseNacional().
 * Se a emissão usa o campo, existe uma regra aqui que o valida ANTES do envio.
 *
 * ARQUITETURA DE REGRAS
 * ─────────────────────
 * Cada regra é um array associativo com:
 *   'id'       → identificador único (snake_case, prefixado pelo grupo)
 *   'grupo'    → seção do XML (emitente, tomador, servico, obra, evento, valores, tributacao)
 *   'label'    → nome amigável do campo para exibir ao usuário (ex: 'CNPJ', 'Bairro')
 *   'campo'    → caminho legível do dado de origem (ex: dadosEmitente.cnpj)
 *   'tagXml'   → tag(s) XML que ficaria(m) vazia(s) sem este campo
 *   'mensagem' → mensagem técnica detalhada (vai para o log, não para o usuário)
 *   'quando'   → callable($ctx): bool — condição para aplicar a regra (true = aplica)
 *   'validar'  → callable($ctx): bool — retorna true se válido, false se inválido
 *
 * Para adicionar uma nova validação, basta inserir um novo array em
 * nfseNacRegistrarRegras(). Sem necessidade de alterar nenhuma outra função.
 *
 * CONTEXTO ($ctx)
 * ───────────────
 * Array montado automaticamente com todos os dados da emissão + valores
 * pré-calculados reutilizados por várias regras:
 *   $ctx['db'], $ctx['dadosFatura'], $ctx['dadosEmitente'],
 *   $ctx['dadosDestinatario'], $ctx['listaServicos'],
 *   $ctx['codigoServico']   — código do 1º serviço (cTribNac)
 *   $ctx['valorTotal']      — soma de total_semtaxa de todos os serviços
 *   $ctx['cnpjEmitente']    — CNPJ limpo (só dígitos)
 *   $ctx['cnpjcpfTomador']  — CNPJ ou CPF limpo
 *   $ctx['crt']             — CRT do emitente (int)
 *   $ctx['regimeTributacao']— regime de tributação (int)
 *   $ctx['descricaoConcat'] — descrições concatenadas de todos os serviços
 *
 * @package nfse
 * @see     emissao_nfse_nacional.php  — código de emissão que este validador espelha
 */

if (!defined('DOL_DOCUMENT_ROOT')) {
    die('Access denied');
}

/* ======================================================================
 * LISTAS CENTRAIS — usadas tanto pela validação quanto pela emissão
 * ====================================================================== */

/**
 * Códigos de serviço que EXIGEM o grupo de OBRA (construção civil).
 * Mesma lista usada em gerarNfseNacional() e formObjectOptions().
 *
 * @return string[]
 */
function nfseNacGetCodigosObra()
{
    return [
        '070201', '070202', '070401', '070501', '070502',
        '070601', '070602', '070701', '070801', '071701',
        '071901', '141403', '141404',
    ];
}

/**
 * Códigos de serviço que EXIGEM o grupo de EVENTO.
 * Mesma lista usada em gerarNfseNacional() e formObjectOptions().
 *
 * @return string[]
 */
function nfseNacGetCodigosEvento()
{
    return [
        '120101', '120201', '120301', '120401', '120501',
        '120601', '120701', '120801', '120901', '120902',
        '120903', '121001', '121101', '121201', '121301',
        '121401', '121501', '121601', '121701',
    ];
}

/* ======================================================================
 * REGISTRO DE REGRAS DE VALIDAÇÃO
 *
 * ► COMO ADICIONAR UMA NOVA REGRA:
 *   1. Copie um bloco existente do mesmo grupo
 *   2. Altere 'id' (deve ser único)
 *   3. Defina 'quando' (condição) e 'validar' (lógica)
 *   4. Pronto — a regra será executada automaticamente
 * ====================================================================== */

/**
 * Retorna todas as regras de validação registradas.
 *
 * @return array[]
 */
function nfseNacRegistrarRegras()
{
    return [

        // =============================================================
        //  GRUPO: EMITENTE  (infDPS > prest)
        // =============================================================

        [
            'id'       => 'emit_cnpj',
            'grupo'    => 'emitente',
            'label'    => 'CNPJ',
            'campo'    => 'dadosEmitente.cnpj',
            'tagXml'   => 'infDPS > prest > CNPJ',
            'mensagem' => '[Emitente] CNPJ não informado ou inválido (tag CNPJ). Deve ter exatamente 14 dígitos.',
            'quando'   => function ($ctx) { return true; },
            'validar'  => function ($ctx) {
                return strlen($ctx['cnpjEmitente']) === 14;
            },
        ],

        [
            'id'       => 'emit_municipio',
            'grupo'    => 'emitente',
            'label'    => 'Cidade',
            'campo'    => 'dadosEmitente.municipio',
            'tagXml'   => 'infDPS > cLocEmi',
            'mensagem' => '[Emitente] Município não informado ou não encontrado na tabela IBGE (tag cLocEmi).',
            'quando'   => function ($ctx) { return true; },
            'validar'  => function ($ctx) {
                $nome = $ctx['dadosEmitente']['municipio'] ?? $ctx['dadosEmitente']['town'] ?? '';
                return !empty(trim($nome));
            },
        ],

        [
            'id'       => 'emit_regime_simples',
            'grupo'    => 'emitente',
            'label'    => 'Regime de Tributação',
            'campo'    => 'dadosEmitente.regimeTributacao',
            'tagXml'   => 'infDPS > prest > regTrib > opSimpNac',
            'mensagem' => '[Emitente] Simples Nacional (CRT=1) sem regime 5 (MEI) ou 6 (ME/EPP).',
            'quando'   => function ($ctx) {
                return $ctx['crt'] === 1;
            },
            'validar'  => function ($ctx) {
                return in_array($ctx['regimeTributacao'], [5, 6], true);
            },
        ],

        // =============================================================
        //  GRUPO: TOMADOR  (infDPS > toma)
        // =============================================================

        [
            'id'       => 'toma_cnpjcpf',
            'grupo'    => 'tomador',
            'label'    => 'CNPJ/CPF',
            'campo'    => 'dadosDestinatario.cnpj',
            'tagXml'   => 'infDPS > toma > CNPJ / CPF',
            'mensagem' => '[Tomador] CNPJ/CPF não informado (tag CNPJ ou CPF).',
            'quando'   => function ($ctx) { return true; },
            'validar'  => function ($ctx) {
                return !empty($ctx['cnpjcpfTomador']);
            },
        ],

        [
            'id'       => 'toma_cnpjcpf_tamanho',
            'grupo'    => 'tomador',
            'label'    => 'CNPJ/CPF',
            'campo'    => 'dadosDestinatario.cnpj',
            'tagXml'   => 'infDPS > toma > CNPJ / CPF',
            'mensagem' => '[Tomador] CNPJ/CPF com tamanho inválido (11 CPF ou 14 CNPJ).',
            'quando'   => function ($ctx) {
                // Só valida tamanho se informou algum documento
                return !empty($ctx['cnpjcpfTomador']);
            },
            'validar'  => function ($ctx) {
                $len = strlen($ctx['cnpjcpfTomador']);
                return $len === 11 || $len === 14;
            },
        ],

        [
            'id'       => 'toma_nome',
            'grupo'    => 'tomador',
            'label'    => 'Nome/Razão Social',
            'campo'    => 'dadosDestinatario.nome',
            'tagXml'   => 'infDPS > toma > xNome',
            'mensagem' => '[Tomador] Nome/Razão social não informado (tag xNome).',
            'quando'   => function ($ctx) { return true; },
            'validar'  => function ($ctx) {
                return !empty(trim($ctx['dadosDestinatario']['nome'] ?? ''));
            },
        ],

        [
            'id'       => 'toma_endereco',
            'grupo'    => 'tomador',
            'label'    => 'Endereço',
            'campo'    => 'dadosDestinatario.endereco',
            'tagXml'   => 'infDPS > toma > end > xLgr',
            'mensagem' => '[Tomador] Logradouro não informado (tag xLgr).',
            'quando'   => function ($ctx) { return true; },
            'validar'  => function ($ctx) {
                return !empty(trim($ctx['dadosDestinatario']['endereco'] ?? ''));
            },
        ],

        [
            'id'       => 'toma_bairro',
            'grupo'    => 'tomador',
            'label'    => 'Bairro',
            'campo'    => 'dadosDestinatario.bairro',
            'tagXml'   => 'infDPS > toma > end > xBairro',
            'mensagem' => '[Tomador] Bairro não informado (tag xBairro).',
            'quando'   => function ($ctx) { return true; },
            'validar'  => function ($ctx) {
                return !empty(trim($ctx['dadosDestinatario']['bairro'] ?? ''));
            },
        ],

        [
            'id'       => 'toma_municipio',
            'grupo'    => 'tomador',
            'label'    => 'Município',
            'campo'    => 'dadosDestinatario.municipio',
            'tagXml'   => 'infDPS > toma > end > endNac > cMun',
            'mensagem' => '[Tomador] Município não informado (tag cMun).',
            'quando'   => function ($ctx) { return true; },
            'validar'  => function ($ctx) {
                $nome = $ctx['dadosDestinatario']['municipio'] ?? $ctx['dadosDestinatario']['town'] ?? '';
                return !empty(trim($nome));
            },
        ],

        [
            'id'       => 'toma_cep',
            'grupo'    => 'tomador',
            'label'    => 'CEP',
            'campo'    => 'dadosDestinatario.cep',
            'tagXml'   => 'infDPS > toma > end > endNac > CEP',
            'mensagem' => '[Tomador] CEP não informado ou inválido (tag CEP). Deve ter 8 dígitos.',
            'quando'   => function ($ctx) { return true; },
            'validar'  => function ($ctx) {
                $cep = preg_replace('/\D/', '', $ctx['dadosDestinatario']['cep'] ?? '');
                return strlen($cep) === 8;
            },
        ],

        // =============================================================
        //  GRUPO: SERVIÇO  (infDPS > serv)
        // =============================================================

        [
            'id'       => 'serv_codigo',
            'grupo'    => 'servico',
            'label'    => 'Código do Serviço',
            'campo'    => 'listaServicos[0].extrafields.srv_cod_itemlistaservico',
            'tagXml'   => 'infDPS > serv > cServ > cTribNac',
            'mensagem' => '[Serviço] Código de tributação nacional não informado (tag cTribNac).',
            'quando'   => function ($ctx) { return true; },
            'validar'  => function ($ctx) {
                return !empty($ctx['codigoServico']);
            },
        ],

        [
            'id'       => 'serv_descricao',
            'grupo'    => 'servico',
            'label'    => 'Descrição',
            'campo'    => 'listaServicos[*].descricao',
            'tagXml'   => 'infDPS > serv > cServ > xDescServ',
            'mensagem' => '[Serviço] Descrição do serviço não informada (tag xDescServ).',
            'quando'   => function ($ctx) { return true; },
            'validar'  => function ($ctx) {
                return !empty(trim($ctx['descricaoConcat']));
            },
        ],

        // =============================================================
        //  GRUPO: VALORES  (infDPS > valores)
        // =============================================================

        [
            'id'       => 'val_total_positivo',
            'grupo'    => 'valores',
            'label'    => 'Valor Total',
            'campo'    => 'listaServicos[*].total_semtaxa',
            'tagXml'   => 'infDPS > valores > vServPrest > vServ',
            'mensagem' => '[Valores] O valor total dos serviços deve ser maior que zero (tag vServ).',
            'quando'   => function ($ctx) { return true; },
            'validar'  => function ($ctx) {
                return $ctx['valorTotal'] > 0;
            },
        ],

        // =============================================================
        //  GRUPO: TRIBUTAÇÃO  (infDPS > valores > trib)
        // =============================================================

        [
            'id'       => 'trib_iss_retido_preenchido',
            'grupo'    => 'tributacao',
            'label'    => 'ISS Retido',
            'campo'    => 'listaServicos[0].extrafields.iss_retido',
            'tagXml'   => 'infDPS > valores > trib > tribMun > tpRetISSQN',
            'mensagem' => '[Tributação] Campo ISS Retido não preenchido (tag tpRetISSQN).',
            'quando'   => function ($ctx) {
                // Só valida se tem código de serviço e não é 990101 (não incidência)
                return !empty($ctx['codigoServico']) && $ctx['codigoServico'] !== '990101';
            },
            'validar'  => function ($ctx) {
                $issRetido = (int)($ctx['listaServicos'][0]['extrafields']['iss_retido'] ?? -1);
                return $issRetido === 1 || $issRetido === 2;
            },
        ],

        [
            'id'       => 'trib_iss_retido_aliquota',
            'grupo'    => 'tributacao',
            'label'    => 'Alíquota ISS',
            'campo'    => 'nfse_codigo_servico.aliquota_iss',
            'tagXml'   => 'infDPS > valores > trib > tribMun > pAliq',
            'mensagem' => '', // mensagem dinâmica
            'quando'   => function ($ctx) {
                if (empty($ctx['codigoServico']) || $ctx['codigoServico'] === '990101') {
                    return false;
                }
                $issRetido = (int)($ctx['listaServicos'][0]['extrafields']['iss_retido'] ?? -1);
                return $issRetido === 1; // só quando ISS retido
            },
            'validar'  => function ($ctx) {
                $codigo = $ctx['codigoServico'];
                $db = $ctx['db'];
                $sql = "SELECT aliquota_iss FROM " . MAIN_DB_PREFIX . "nfse_codigo_servico WHERE codigo = '" . $db->escape($codigo) . "'";
                $res = $db->query($sql);
                if (!$res || $db->num_rows($res) === 0) {
                    return false;
                }
                $obj = $db->fetch_object($res);
                return (float)($obj->aliquota_iss ?? 0) > 0;
            },
            '_mensagem_dinamica' => function ($ctx) {
                return '[Tributação] ISS marcado como RETIDO mas não há alíquota cadastrada para o serviço "'
                     . $ctx['codigoServico'] . '" (tag pAliq). '
                     . 'Cadastre a alíquota na tela Serviços e Alíquotas antes de emitir.';
            },
        ],

        // =============================================================
        //  GRUPO: OBRA  (infDPS > serv > obra)
        //  Obrigatório quando código de serviço está na lista de obra
        // =============================================================

        [
            'id'       => 'obra_cObra',
            'grupo'    => 'obra',
            'label'    => 'Código da Obra (CNO/CEI)',
            'campo'    => 'dadosFatura.extrafields.cObra',
            'tagXml'   => 'infDPS > serv > obra > cObra',
            'mensagem' => '', // dinâmica
            'quando'   => function ($ctx) {
                return !empty($ctx['codigoServico'])
                    && in_array($ctx['codigoServico'], nfseNacGetCodigosObra(), true);
            },
            'validar'  => function ($ctx) {
                return !empty(trim($ctx['dadosFatura']['extrafields']['cObra'] ?? ''));
            },
            '_mensagem_dinamica' => function ($ctx) {
                return '[Obra] O código de serviço "' . $ctx['codigoServico']
                     . '" é de construção civil e exige o código da obra (tag cObra). '
                     . 'Preencha o campo "cObra" nos dados adicionais da fatura.';
            },
        ],

        // =============================================================
        //  GRUPO: EVENTO  (infDPS > serv > atvevento)
        //  Obrigatório quando código de serviço está na lista de evento
        // =============================================================

        [
            'id'       => 'evento_xnome',
            'grupo'    => 'evento',
            'label'    => 'Nome do Evento',
            'campo'    => 'dadosFatura.extrafields.xnomeevento',
            'tagXml'   => 'infDPS > serv > atvevento > xNome',
            'mensagem' => '', // dinâmica
            'quando'   => function ($ctx) {
                return !empty($ctx['codigoServico'])
                    && in_array($ctx['codigoServico'], nfseNacGetCodigosEvento(), true);
            },
            'validar'  => function ($ctx) {
                return !empty(trim($ctx['dadosFatura']['extrafields']['xnomeevento'] ?? ''));
            },
            '_mensagem_dinamica' => function ($ctx) {
                return '[Evento] O código de serviço "' . $ctx['codigoServico']
                     . '" exige o nome do evento (tag xNome). '
                     . 'Preencha o campo "Nome do Evento" nos dados adicionais da fatura.';
            },
        ],

        [
            'id'       => 'evento_dtini',
            'grupo'    => 'evento',
            'label'    => 'Data Início',
            'campo'    => 'dadosFatura.extrafields.dtini',
            'tagXml'   => 'infDPS > serv > atvevento > dtIni',
            'mensagem' => '', // dinâmica
            'quando'   => function ($ctx) {
                return !empty($ctx['codigoServico'])
                    && in_array($ctx['codigoServico'], nfseNacGetCodigosEvento(), true);
            },
            'validar'  => function ($ctx) {
                return !empty(trim($ctx['dadosFatura']['extrafields']['dtini'] ?? ''));
            },
            '_mensagem_dinamica' => function ($ctx) {
                return '[Evento] O código de serviço "' . $ctx['codigoServico']
                     . '" exige a data de início do evento (tag dtIni). '
                     . 'Preencha o campo "Data Início" nos dados adicionais da fatura.';
            },
        ],

        [
            'id'       => 'evento_dtfim',
            'grupo'    => 'evento',
            'label'    => 'Data Fim',
            'campo'    => 'dadosFatura.extrafields.dtfim',
            'tagXml'   => 'infDPS > serv > atvevento > dtFim',
            'mensagem' => '', // dinâmica
            'quando'   => function ($ctx) {
                return !empty($ctx['codigoServico'])
                    && in_array($ctx['codigoServico'], nfseNacGetCodigosEvento(), true);
            },
            'validar'  => function ($ctx) {
                return !empty(trim($ctx['dadosFatura']['extrafields']['dtfim'] ?? ''));
            },
            '_mensagem_dinamica' => function ($ctx) {
                return '[Evento] O código de serviço "' . $ctx['codigoServico']
                     . '" exige a data de fim do evento (tag dtFim). '
                     . 'Preencha o campo "Data Fim" nos dados adicionais da fatura.';
            },
        ],

        // =============================================================
        //  GRUPO: EVENTO > ENDEREÇO  (infDPS > serv > atvevento > end)
        //  O XSD exige idAtvEvt OU end. Como não temos idAtvEvt,
        //  o bloco end (CEP, xLgr, nro, xBairro) é OBRIGATÓRIO.
        // =============================================================

        [
            'id'       => 'evento_cep',
            'grupo'    => 'evento',
            'label'    => 'CEP',
            'campo'    => 'dadosFatura.extrafields.cep',
            'tagXml'   => 'infDPS > serv > atvevento > end > CEP',
            'mensagem' => '', // dinâmica
            'quando'   => function ($ctx) {
                return !empty($ctx['codigoServico'])
                    && in_array($ctx['codigoServico'], nfseNacGetCodigosEvento(), true);
            },
            'validar'  => function ($ctx) {
                $cep = preg_replace('/\D/', '', $ctx['dadosFatura']['extrafields']['cep'] ?? '');
                return strlen($cep) === 8;
            },
            '_mensagem_dinamica' => function ($ctx) {
                return '[Evento] O código de serviço "' . $ctx['codigoServico']
                     . '" exige o CEP do endereço do evento (tag end > CEP). '
                     . 'Preencha o campo "CEP" nos dados adicionais da fatura.';
            },
        ],

        [
            'id'       => 'evento_xlgr',
            'grupo'    => 'evento',
            'label'    => 'Rua',
            'campo'    => 'dadosFatura.extrafields.xlgr',
            'tagXml'   => 'infDPS > serv > atvevento > end > xLgr',
            'mensagem' => '', // dinâmica
            'quando'   => function ($ctx) {
                return !empty($ctx['codigoServico'])
                    && in_array($ctx['codigoServico'], nfseNacGetCodigosEvento(), true);
            },
            'validar'  => function ($ctx) {
                return !empty(trim($ctx['dadosFatura']['extrafields']['xlgr'] ?? ''));
            },
            '_mensagem_dinamica' => function ($ctx) {
                return '[Evento] O código de serviço "' . $ctx['codigoServico']
                     . '" exige o logradouro do endereço do evento (tag end > xLgr). '
                     . 'Preencha o campo "Rua" nos dados adicionais da fatura.';
            },
        ],

        [
            'id'       => 'evento_nro',
            'grupo'    => 'evento',
            'label'    => 'Número',
            'campo'    => 'dadosFatura.extrafields.nro',
            'tagXml'   => 'infDPS > serv > atvevento > end > nro',
            'mensagem' => '', // dinâmica
            'quando'   => function ($ctx) {
                return !empty($ctx['codigoServico'])
                    && in_array($ctx['codigoServico'], nfseNacGetCodigosEvento(), true);
            },
            'validar'  => function ($ctx) {
                return !empty(trim($ctx['dadosFatura']['extrafields']['nro'] ?? ''));
            },
            '_mensagem_dinamica' => function ($ctx) {
                return '[Evento] O código de serviço "' . $ctx['codigoServico']
                     . '" exige o número do endereço do evento (tag end > nro). '
                     . 'Preencha o campo "Número" nos dados adicionais da fatura.';
            },
        ],

        [
            'id'       => 'evento_xbairro',
            'grupo'    => 'evento',
            'label'    => 'Bairro',
            'campo'    => 'dadosFatura.extrafields.xbairro',
            'tagXml'   => 'infDPS > serv > atvevento > end > xBairro',
            'mensagem' => '',
            'quando'   => function ($ctx) {
                return !empty($ctx['codigoServico'])
                    && in_array($ctx['codigoServico'], nfseNacGetCodigosEvento(), true);
            },
            'validar'  => function ($ctx) {
                return !empty(trim($ctx['dadosFatura']['extrafields']['xbairro'] ?? ''));
            },
            '_mensagem_dinamica' => function ($ctx) {
                return '[Evento] O código de serviço "' . $ctx['codigoServico']
                     . '" exige o bairro do endereço do evento (tag end > xBairro). '
                     . 'Preencha o campo "Bairro" nos dados adicionais da fatura.';
            },
        ],

    ];
}

/* ======================================================================
 * MOTOR DE VALIDAÇÃO
 * ====================================================================== */

/**
 * Valida os dados de emissão da NFS-e Nacional executando todas as regras registradas.
 *
 * Retorna array de strings com mensagens de erro. Array vazio = tudo OK.
 *
 * ASSINATURA IDÊNTICA À VERSÃO ANTERIOR — substituição direta sem
 * alterar nenhum ponto de chamada.
 *
 * @param  object      $db
 * @param  array       $dadosFatura
 * @param  array       $dadosEmitente
 * @param  array       $dadosDestinatario
 * @param  array       $listaServicos
 * @param  int|null    $numeroCustom
 * @return string[]    Lista de erros (vazia = válido)
 */
function validarDadosNfseNacional($db, $dadosFatura, $dadosEmitente, $dadosDestinatario, $listaServicos, $numeroCustom = null)
{
    $erros = [];

    // ------------------------------------------------------------------
    // Validação estrutural mínima (sem serviços não há o que validar)
    // ------------------------------------------------------------------
    if (empty($listaServicos) || !is_array($listaServicos)) {
        $erros[] = 'Nenhum serviço encontrado na fatura.';
        return $erros;
    }

    // ------------------------------------------------------------------
    // Monta o CONTEXTO compartilhado entre todas as regras
    // Valores pré-calculados evitam repetição de lógica nas regras
    // ------------------------------------------------------------------
    $cnpjEmitente    = preg_replace('/\D/', '', $dadosEmitente['cnpj'] ?? '');
    $cnpjcpfTomador  = preg_replace('/\D/', '', $dadosDestinatario['cnpj'] ?? '');
    $codigoServico   = trim($listaServicos[0]['extrafields']['srv_cod_itemlistaservico'] ?? '');

    $valorTotal = 0.0;
    $descParts  = [];
    foreach ($listaServicos as $svc) {
        $valorTotal += (float)($svc['total_semtaxa'] ?? 0);
        if (!empty($svc['descricao'])) {
            $descParts[] = $svc['descricao'];
        }
    }

    $ctx = [
        'db'                  => $db,
        'dadosFatura'         => $dadosFatura,
        'dadosEmitente'       => $dadosEmitente,
        'dadosDestinatario'   => $dadosDestinatario,
        'listaServicos'       => $listaServicos,
        'numeroCustom'        => $numeroCustom,
        // Pré-calculados
        'cnpjEmitente'        => $cnpjEmitente,
        'cnpjcpfTomador'      => $cnpjcpfTomador,
        'codigoServico'       => $codigoServico,
        'valorTotal'          => $valorTotal,
        'descricaoConcat'     => implode('. ', $descParts),
        'crt'                 => (int)($dadosEmitente['crt'] ?? 0),
        'regimeTributacao'    => (int)($dadosEmitente['regimeTributacao'] ?? 0),
    ];

    // ------------------------------------------------------------------
    // Executa cada regra e agrupa falhas por grupo
    // ------------------------------------------------------------------
    $regras = nfseNacRegistrarRegras();

    // Mapa de grupo → template de mensagem amigável para o usuário
    // %s = lista de campos, %c = "o campo"/"os campos" (singular/plural)
    $grupoTemplates = [
        'emitente'   => 'Preencha %c %s nos dados da empresa.',
        'tomador'    => 'Preencha %c %s no cadastro do cliente.',
        'servico'    => 'Preencha %c %s no cadastro do produto/serviço.',
        'valores'    => 'Preencha %c %s nos itens da fatura.',
        'tributacao' => 'Preencha %c %s no cadastro do serviço.',
        'obra'       => 'Preencha %c %s nos dados adicionais da fatura.',
        'evento'     => 'Preencha %c %s nos dados adicionais da fatura.',
    ];

    // Coleta labels que falharam por grupo + mensagens técnicas para log
    $falhasPorGrupo = [];   // grupo => [label, label, ...]
    $logDetalhado   = [];   // mensagens técnicas para error_log

    foreach ($regras as $regra) {
        $quando = $regra['quando'];
        if (!$quando($ctx)) {
            continue;
        }
        $validar = $regra['validar'];
        if (!$validar($ctx)) {
            $grupo = $regra['grupo'];
            $label = $regra['label'] ?? $regra['campo'];

            // Mensagem técnica para log (dinâmica ou estática)
            if (!empty($regra['_mensagem_dinamica']) && is_callable($regra['_mensagem_dinamica'])) {
                $msgTecnica = ($regra['_mensagem_dinamica'])($ctx);
            } else {
                $msgTecnica = $regra['mensagem'];
            }
            $logDetalhado[] = $msgTecnica;

            // Agrupa label no grupo (evita duplicatas de mesmo rótulo)
            if (!isset($falhasPorGrupo[$grupo])) {
                $falhasPorGrupo[$grupo] = [];
            }
            if (!in_array($label, $falhasPorGrupo[$grupo])) {
                $falhasPorGrupo[$grupo][] = $label;
            }
        }
    }

    // ------------------------------------------------------------------
    // Monta mensagens amigáveis consolidadas por grupo
    // ------------------------------------------------------------------
    foreach ($falhasPorGrupo as $grupo => $labels) {
        $template = $grupoTemplates[$grupo] ?? 'Verifique %c %s.';
        $plural   = count($labels) > 1;
        $prefixo  = $plural ? 'os campos' : 'o campo';

        if (count($labels) === 1) {
            $camposStr = $labels[0];
        } else {
            $ultimo    = array_pop($labels);
            $camposStr = implode(', ', $labels) . ' e ' . $ultimo;
        }

        $erros[] = str_replace(['%c', '%s'], [$prefixo, $camposStr], $template);
    }

    // ------------------------------------------------------------------
    // Log detalhado para debug (mensagens técnicas completas)
    // ------------------------------------------------------------------
    if (!empty($logDetalhado)) {
        error_log('[NFSE VALIDADOR] ' . count($logDetalhado) . ' erro(s) na fatura ' . (int)($dadosFatura['id'] ?? 0) . ':');
        foreach ($logDetalhado as $e) {
            error_log('[NFSE VALIDADOR]   → ' . $e);
        }
    }

    return $erros;
}
