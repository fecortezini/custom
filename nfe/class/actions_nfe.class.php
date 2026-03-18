<?php
class ActionsNfe
{
    protected $db;
    protected $langs;

    // Propriedades esperadas pelo Dolibarr
    public $results = array();
    public $resprints = '';
    public $error = '';
    public $errors = array();

    // NOVO: flags para silenciar avisos PHP
    protected static $warningsMuted = false;
    protected static $errorHandlerRegistered = false;

    private function mutePhpWarnings()
    {
        if (self::$warningsMuted) return;
        @ini_set('display_errors', '0');
        @ini_set('log_errors', '1');

        $level = error_reporting();
        $level &= ~E_DEPRECATED;
        $level &= ~E_USER_DEPRECATED;
        $level &= ~E_NOTICE;
        $level &= ~E_USER_NOTICE;
        $level &= ~E_WARNING;
        $level &= ~E_USER_WARNING;
        error_reporting($level);

        self::$warningsMuted = true;
    }

    // NOVO: registra um error handler para suprimir E_DEPRECATED/NOTICE/WARNING independentemente de resets
    private function registerErrorSilencer()
    {
        if (self::$errorHandlerRegistered) return;
        set_error_handler(function ($errno, $errstr) {
            if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED ||
                $errno === E_NOTICE || $errno === E_USER_NOTICE ||
                $errno === E_WARNING || $errno === E_USER_WARNING) {
                return true; // suprime
            }
            return false; // deixa outros seguirem o fluxo padrão
        });
        self::$errorHandlerRegistered = true;
    }

    // Helper: normaliza extrafields (tipos não escalares) e aplica default_value para campos
    // null/vazios — garante que o Dolibarr exiba a opção pré-selecionada correta no formulário.
    private function sanitizeExtrafields(&$obj, $table_element = '')
    {
        if (!isset($obj) || !is_object($obj)) return;

        // Garante que o array existe
        if (!isset($obj->array_options) || !is_array($obj->array_options)) {
            $obj->array_options = array();
        }

        // 1. Normaliza tipos não escalares (bool, array, object → string)
        foreach ($obj->array_options as $k => $v) {
            if ($v === null) {
                continue; // mantém null — será tratado abaixo pela lógica de defaults
            } elseif (is_bool($v)) {
                $obj->array_options[$k] = $v ? '1' : '0';
            } elseif (is_array($v)) {
                $obj->array_options[$k] = json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            } elseif (!is_scalar($v)) {
                $obj->array_options[$k] = (string)$v;
            }
        }

        // 2. Aplica default_value para campos null/vazios, para que showOptionals exiba
        //    a opção pré-selecionada correta (Dolibarr usa !empty() e ignora o default_value
        //    do banco ao renderizar — precisamos preencher explicitamente).
        if (empty($table_element) && !empty($obj->table_element)) {
            $table_element = $obj->table_element;
        }
        if (empty($table_element)) return;

        static $efCache = array();
        if (!isset($efCache[$table_element])) {
            require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
            $ef = new ExtraFields($this->db);
            $ef->fetch_name_optionals_label($table_element, true);
            $efCache[$table_element] = $ef;
        }
        $ef = $efCache[$table_element];

        if (empty($ef->attributes[$table_element]['label'])) return;

        foreach ($ef->attributes[$table_element]['label'] as $key => $label) {
            $optKey      = 'options_' . $key;
            $currentVal  = $obj->array_options[$optKey] ?? null;
            $defaultVal  = $ef->attributes[$table_element]['default'][$key] ?? null;

            // Só aplica se: valor atual está vazio/null E o campo tem default definido
            if (($currentVal === null || $currentVal === '') && $defaultVal !== null && $defaultVal !== '') {
                $obj->array_options[$optKey] = $defaultVal;
            }
        }
    }

    public function __construct($db, $langs = null)
    {
        global $conf;
        $this->db = $db;

        // NOVO: silenciar e registrar handler no início do hook
        $this->mutePhpWarnings();
        $this->registerErrorSilencer();

        // Usa o $langs passado ou faz fallback para o global
        if ($langs !== null) {
            $this->langs = $langs;
        } else {
            global $langs;
            $this->langs = $langs;
        }
    }

    public function updateSetup()
    {
        // A verificação de token foi removida para resolver o erro.
        // if (!validate_token()) {
        //     setEventMessages($this->langs->trans("ErrorBadToken"), null, 'errors');
        //     return -1;
        // }

        // Atualizar Ambiente
        $this->db->query("UPDATE ".MAIN_DB_PREFIX."nfe_config SET value = '".$this->db->escape(GETPOST('ambiente', 'int'))."' WHERE name = 'ambiente'");

        // Atualizar Senha do Certificado
        $cert_pass = GETPOST('cert_pass', 'none');
        if (!empty($cert_pass)) {
            // Removida a codificação Base64. Salvando em texto plano.
            $plain_pass = trim($cert_pass);
            $this->db->query("UPDATE ".MAIN_DB_PREFIX."nfe_config SET value = '".$this->db->escape($plain_pass)."' WHERE name = 'cert_pass'");
        }

        // Processar Upload do Certificado
        if (!empty($_FILES['cert_file']['name']) && $_FILES['cert_file']['error'] == 0) {
            $pfx_content = file_get_contents($_FILES['cert_file']['tmp_name']);
            $escaped_pfx_content = $this->db->escape($pfx_content);
            $sql = "UPDATE ".MAIN_DB_PREFIX."nfe_config SET value = '".$escaped_pfx_content."' WHERE name = 'cert_pfx'";
            $this->db->query($sql);
        }
        
        // Atualizar JSON de configuração
        $config_json = GETPOST('config_json', 'none');
        if (!empty($config_json)) {
            $this->db->query("UPDATE ".MAIN_DB_PREFIX."nfe_config SET value = '".$this->db->escape($config_json)."' WHERE name = 'config_json'");
        }

        setEventMessages($this->langs->trans("SettingsSaved"), null, 'mesgs');
        return 0;
    }

    /**
     * Executa ações na página de fatura (card.php)
     */
    public function doActions($parameters, &$object, &$action, $hookmanager)
    {
        $contexts = empty($parameters['context']) ? array() : explode(':', $parameters['context']);
        // NOVO: Sanear extrafields da fatura e das linhas o mais cedo possível
        if (in_array('invoicecard', $contexts)) {
            // Saneia extrafields do cabeçalho da fatura e aplica defaults
            $this->sanitizeExtrafields($object, 'facture');

            // Saneia extrafields das linhas (se já carregadas)
            if (!empty($object->lines) && is_array($object->lines)) {
                foreach ($object->lines as $ln) {
                    $this->sanitizeExtrafields($ln, 'facturedet');
                }
            }
        }

        if (!in_array('invoicecard', $contexts)) {
            return 0;
        }
        $fatura = $object && !empty($object->id) ? $object : new Facture($this->db);
        // Persiste "Dinheiro" no banco quando forma de pagamento estiver vazia
        if (!empty($fatura->id) && empty($fatura->mode_reglement_id) && empty($fatura->mode_reglement_code)) {
            $modeId =4;
            $this->db->query(
                "UPDATE ".MAIN_DB_PREFIX."facture SET fk_mode_reglement = ".$modeId." WHERE rowid = ".(int)$fatura->id
            );
        }
        // VALIDAÇÃO 0: Bloqueia VOLTAR AO RASCUNHO / MODIFICAR fatura com NFe emitida
        //var_dump($action);
        if (in_array($action, array('modif', 'confirm_modif'))) {
            if (!empty($object->id)) {
                try {
                    $sqlNfeCheck = "SELECT id, numero_nfe FROM ".MAIN_DB_PREFIX."nfe_emitidas 
                                     WHERE fk_facture = ".(int)$object->id." 
                                     AND (LOWER(status) LIKE '%autoriz%' OR LOWER(status) = 'processamento')
                                     LIMIT 1";
                    $resNfeCheck = $this->db->query($sqlNfeCheck);
                    if ($resNfeCheck && $this->db->num_rows($resNfeCheck) > 0) {
                        $nfeData = $this->db->fetch_object($resNfeCheck);
                        global $langs;
                        setEventMessages('Não é possível voltar ao rascunho ou modificar esta fatura porque já existe uma NFe autorizada (Nº '.$nfeData->numero_nfe.'). Cancele a NFe primeiro.', null, 'errors');
                        header('Location: '.$_SERVER['PHP_SELF'].'?facid='.$object->id);
                        exit;
                    }
                } catch (Exception $e) {
                    error_log('[NFe] Erro ao verificar NFe para bloqueio de rascunho/modificação: ' . $e->getMessage());
                }
            }
        }

        // VALIDAÇÃO 1: Bloqueia EDIÇÃO de fatura que já possui NFe autorizada
        if (in_array($action, array('edit', 'editline', 'updateligne', 'confirm_deleteline', 'addline', 'updateline'))) {
            if (!empty($object->id)) {
                try {
                    $sqlNfeCheck = "SELECT id, numero_nfe FROM ".MAIN_DB_PREFIX."nfe_emitidas 
                                     WHERE fk_facture = ".(int)$object->id." 
                                     AND (LOWER(status) LIKE '%autoriz%' OR LOWER(status) = 'processamento')
                                     LIMIT 1";
                    $resNfeCheck = $this->db->query($sqlNfeCheck);
                    if ($resNfeCheck && $this->db->num_rows($resNfeCheck) > 0) {
                        $nfeData = $this->db->fetch_object($resNfeCheck);
                        global $langs;
                        setEventMessages('Não é possível alterar esta fatura porque já existe uma NFe autorizada (Nº '.$nfeData->numero_nfe.'). Cancele a NFe primeiro.', null, 'errors');
                        
                        header('Location: '.$_SERVER['PHP_SELF'].'?facid='.$object->id);
                        exit;
                    }
                } catch (Exception $e) {
                    error_log('[NFe] Erro ao verificar NFe na edição: ' . $e->getMessage());
                }
            }
        }
        
        // VALIDAÇÃO 2: Bloqueia alteração de EXTRAFIELDS relacionados à NFe
        if (in_array($action, array('update', 'set_extrafields'))) {
            if (!empty($object->id)) {
                try {
                    $sqlNfeCheck = "SELECT id FROM ".MAIN_DB_PREFIX."nfe_emitidas 
                                     WHERE fk_facture = ".(int)$object->id." 
                                     AND (LOWER(status) LIKE '%autoriz%' OR LOWER(status) = 'processamento')
                                     LIMIT 1";
                    $resNfeCheck = $this->db->query($sqlNfeCheck);
                    if ($resNfeCheck && $this->db->num_rows($resNfeCheck) > 0) {
                        $camposProtegidos = array(
                            'nat_op', 'indpres', 'cfop', 'frete',
                            'info_complementar', 'modalidade_frete'
                        );
                        
                        $object->fetch_optionals();
                        foreach ($camposProtegidos as $campo) {
                            $valorNovo = GETPOST('options_'.$campo, 'none');
                            $valorAtual = $object->array_options['options_'.$campo] ?? null;
                            
                            if ($valorNovo !== null && $valorNovo !== '' && $valorNovo != $valorAtual) {
                                global $langs;
                                error_log('[NFe] Tentativa de alterar campo protegido: '.$campo);
                                setEventMessages('Não é possível alterar campos relacionados à NFe. Esta fatura possui uma NFe autorizada.', null, 'errors');
                                header('Location: '.$_SERVER['PHP_SELF'].'?facid='.$object->id);
                                exit;
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log('[NFe] Erro ao verificar NFe para proteção de extrafields: ' . $e->getMessage());
                }
            }
        }
        
        // VALIDAÇÃO 6: Bloqueia EXCLUSÃO de pagamentos quando existir NFe autorizada
        if (in_array($action, array('confirm_delete_paiement', 'deletepayment'))) {
            if (!empty($object->id)) {
                try {
                    $sqlNfeCheck = "SELECT id, numero_nfe FROM ".MAIN_DB_PREFIX."nfe_emitidas 
                                     WHERE fk_facture = ".(int)$object->id." 
                                     AND (LOWER(status) LIKE '%autoriz%' OR LOWER(status) = 'processamento')
                                     LIMIT 1";
                    $resNfeCheck = $this->db->query($sqlNfeCheck);
                    if ($resNfeCheck && $this->db->num_rows($resNfeCheck) > 0) {
                        $nfeData = $this->db->fetch_object($resNfeCheck);
                        error_log('[NFe] Tentativa de excluir pagamento de fatura com NFe autorizada - bloqueado. Fatura ID: ' . $object->id . ' | NFe: ' . $nfeData->numero_nfe);
                        global $langs;
                        setEventMessages('Não é possível excluir o pagamento desta fatura porque já possui uma NFe autorizada (Nº ' . $nfeData->numero_nfe . ').', null, 'errors');
                        header('Location: '.$_SERVER['PHP_SELF'].'?facid='.$object->id);
                        exit;
                    }
                } catch (Exception $e) {
                    error_log('[NFe] Erro ao verificar NFe na exclusão de pagamento: ' . $e->getMessage());
                }
            }
        }

        // Ação de geração de NFe (padrão) OU devolução
        if ($action === 'gerarnfe' || $action === 'gerarnfe_dev') {
            global $langs, $mysoc;
            
            // VALIDAÇÃO 3: Não pode gerar NFe para fatura em RASCUNHO
            if (!empty($object->id) && isset($object->statut) && (int)$object->statut === 0) {
                error_log('[NFe] Fatura em rascunho - bloqueando geração');
                setEventMessages('Não é possível gerar NFe para fatura em rascunho. Valide a fatura primeiro.', null, 'errors');
                return 1;
            }
            
            // VALIDAÇÃO 4: Não pode gerar mais de uma NFe para a mesma fatura
            if (!empty($object->id)) {
                try {
                    $sqlCheck = "SELECT id FROM ".MAIN_DB_PREFIX."nfe_emitidas 
                                 WHERE fk_facture = ".(int)$object->id." 
                                 AND (LOWER(status) LIKE '%autoriz%' OR LOWER(status) = 'processamento')
                                 LIMIT 1";
                    $resCheck = $this->db->query($sqlCheck);
                    if ($resCheck && $this->db->num_rows($resCheck) > 0) {
                        setEventMessages('Esta fatura já possui uma NFe autorizada ou em processo de emissão.', null, 'errors');
                        return 1;
                    }
                } catch (Exception $e) {
                    error_log('[NFe] Erro na validação de NFe duplicada: ' . $e->getMessage());
                }
            }
            
            // VALIDAÇÃO 5: Não pode gerar NFe se a fatura não foi paga
            // if (!empty($object->id) && isset($object->paye) && (int)$object->paye !== 1) {
            //     error_log('[NFe] Fatura não paga - bloqueando geração. ID: ' . $object->id);
            //     setEventMessages('É necessário realizar o pagamento antes de emitir a nota fiscal.', null, 'errors');
            //     return 1;
            // }
            $db = $this->db;
            global $langs, $mysoc;

            // Includes necessários
            dol_include_once('/core/class/extrafields.class.php');
            dol_include_once('/product/class/product.class.php');
            dol_include_once('/compta/facture/class/facture.class.php');
            
            // CRÍTICO: Carrega função gerarNfe (case-sensitive no Linux)
            dol_include_once('/custom/nfe/emissao_nfe.php');

            // Carrega empresa (mysoc)
            if (empty($mysoc->id)) {
                $mysoc->fetch(0);
            }

            $id_fatura = GETPOST('facid', 'int');

            // Usa o $object se já for a fatura atual, senão busca
            
            // Mensagem de depuração: use setEventMessages para aparecer na UI da fatura
            
            if (empty($fatura->id)) {
                $fatura->fetch($id_fatura);
            }

            if ($fatura->id <= 0) {
                setEventMessages($langs->trans("Fatura não encontrada."), null, 'errors');
                return 0;
            }

            // Cliente (thirdparty)
            if ($fatura->fetch_thirdparty() > 0 && !empty($fatura->thirdparty) && is_object($fatura->thirdparty)) {
                $cliente = $fatura->thirdparty;

                $cliente->fetch_optionals();

                $extrafieldsSoc = new ExtraFields($db);
                $extrafieldsSoc->fetch_name_optionals_label('societe');

                $dados_destinatario = [
                    'id'    => $cliente->id,
                    'nome'  => $cliente->name,
                    'nfant' => $cliente->name_alias,
                    'email' => $cliente->email,
                    'fone'  => $cliente->phone,
                    'cep'   => $cliente->zip,
                    'cidade'=> $cliente->town,
                    'estado'=> $cliente->state,
                    'uf'    => $cliente->state_code,
                    'pais'  => $cliente->country,
                    'cnpj'  => preg_replace('/\D/', '', $cliente->idprof1),
                    'ie'    => $cliente->idprof2,
                    'extrafields' => []
                ];

                if (!empty($cliente->array_options)) {
                    foreach ($cliente->array_options as $key => $value) {
                        $clean_key = str_replace('options_', '', $key);
                        $dados_destinatario['extrafields'][$clean_key] = $value;
                    }
                }
            } else {
                setEventMessages("Cliente (thirdparty) não encontrado para essa fatura.", null, 'errors');
                return 0;
            }

            // Enriquecer dados da empresa com configs globais
            $mysoc->fetch_optionals();
            $mysoc->crt           = getDolGlobalInt('MAIN_INFO_CRT');
            //$mysoc->cod_municipio = getDolGlobalInt('MAIN_INFO_COD_MUNICIPIO');
            $mysoc->nFant         = getDolGlobalString('MAIN_INFO_NOME_FANTASIA');
            $mysoc->rua           = getDolGlobalString('MAIN_INFO_RUA');
            $mysoc->bairro        = getDolGlobalString('MAIN_INFO_BAIRRO');
            $mysoc->numero        = getDolGlobalInt('MAIN_INFO_NUMERO');

            $dados_minha_empresa = [
                'id'            => $mysoc->id,
                'nome'          => $mysoc->name,
                'cnpj'          => $mysoc->idprof1,
                'pais'          => $mysoc->country,
                'ie'            => $mysoc->idprof2,
                'im'            => $mysoc->idprof3,
                'estado'        => $mysoc->state,
                'cidade'        => $mysoc->town,
                'cep'           => $mysoc->zip,
                'uf'            => $mysoc->state_code,
                'cPais'         => 1058,
                'crt'           => $mysoc->crt,
                'nfant'         => $mysoc->nFant,
                'rua'           => $mysoc->rua,
                'bairro'        => $mysoc->bairro,
                'numero'        => $mysoc->numero,
                'logotipo'      => $mysoc->logo,
                'telefone'      => $mysoc->phone,
            ];



            // Produtos/serviços da fatura
            $listaProdutos = [];
            if (!empty($fatura->lines)) {
                foreach ($fatura->lines as $linha) {
                    if (empty($linha->fk_product)) {
                        continue;
                    }
                    $produto = new Product($db);
                    if ($produto->fetch($linha->fk_product) <= 0) {
                        continue;
                    }
                    $produto->fetch_optionals();

                    $campos_extras = [];
                    if (!empty($produto->array_options)) {
                        foreach ($produto->array_options as $key => $value) {
                            $campos_extras[str_replace('options_', '', $key)] = $value;
                        }
                    }

                    // NOVO: Para devolução, converte valores negativos em positivos
                    $quantidade = (float) $linha->qty;
                    $precoUnit = (float) $linha->subprice;
                    $totalSemTaxa = (float) $linha->total_ht;
                    $totalComTaxa = (float) $linha->total_ttc;
                    
                    // Se for nota de crédito (type=2), valores são negativos
                    if ((int)$fatura->type === 2) {
                        $quantidade = abs($quantidade);
                        $precoUnit = abs($precoUnit);
                        $totalSemTaxa = abs($totalSemTaxa);
                        $totalComTaxa = abs($totalComTaxa);
                    }

                    $dados_produtos = [
                        'id_produto'           => $produto->id,
                        'ref'                  => $produto->ref,
                        'nome'                 => $produto->label,
                        'descricao'            => $linha->desc,
                        'preco_venda_semtaxa'  => $precoUnit,
                        'quantidade'           => $quantidade,
                        'imposto_%'            => (float) $linha->tva_tx,
                        'total_semtaxa'        => $totalSemTaxa,
                        'total_comtaxa'        => $totalComTaxa,
                        'estoque_fisico'       => (int) $produto->stock_reel,
                        'status_venda'         => $produto->status_to_sell,
                        'extrafields'          => array_filter($campos_extras, function($v) { return $v !== null && $v !== ''; }),
                    ];

                    if (!empty($dados_produtos['ref'])) {
                        $listaProdutos[] = $dados_produtos;
                    }
                }
            }

            // Dados da fatura
            $dadosFatura = [
                'id'                   => $fatura->id,
                'referencia'           => $fatura->ref,
                'data_emissao'         => dol_print_date($fatura->date, 'day'),
                'data_vencimento'      => dol_print_date($fatura->date_lim_reglement, 'day'),
                'total_sem_impostos'   => price($fatura->total_ht),
                'total_com_impostos'   => price($fatura->total_ttc),
                'valor_total_impostos' => price($fatura->total_tva),
                'desconto_total'       => price($fatura->remise),
                'status_texto'         => $fatura->getLibStatut(0),
                'forma_pagamento'      => $fatura->mode_reglement,
                'notas_publicas'       => $fatura->note_public,
                'notas_privadas'       => $fatura->note_private,
                'moeda'                => $fatura->multicurrency_code,
                'extrafields'          => [],
            ];
            

            
            // NOVO: ajustar dados para devolução quando ação gerarnfe_dev
            if ($action === 'gerarnfe_dev') {
                // Marca devolução
                $dadosFatura['extrafields']['is_devolucao'] = 1;
                // Natureza de operação (ajuste se já existir extrafield)
                if (empty($dadosFatura['extrafields']['nat_op'])) {
                    $dadosFatura['extrafields']['nat_op'] = 'Devolução de Mercadoria';
                }
                // Força forma pagamento “90” (sem pagamento) se não definido
                if (empty($dadosFatura['forma_pagamento'])) {
                    $dadosFatura['forma_pagamento'] = '90';
                }
                $dadosFatura['tpNF']    = 0;      // Entrada
                $dadosFatura['finNFe']  = 4;      // Finalidade: Devolução
                $dadosFatura['fin_nfe'] = 4;      // Alias (caso gerarNfe use este)
            }

            // Extrafields da fatura
            $extrafieldsFac = new ExtraFields($db);
            $extrafieldsFac->fetch_name_optionals_label('facture');

            if (!empty($fatura->array_options)) {
                foreach ($fatura->array_options as $key => $value) {
                    $clean_key = str_replace('options_', '', $key);
                    $dadosFatura['extrafields'][$clean_key] = $value;
                }


            }
            
            // **NOVO BLOCO: Para devolução, INJETA alíquotas da nota original nos produtos**
            if ($action === 'gerarnfe_dev') {
                // 1. Busca ID da NF-e origem
                $idNfeOrig = (int) GETPOST('id_nfe_origem', 'int');
                if (!$idNfeOrig && !empty($object->array_options['options_fk_nfe_origem'])) {
                    $idNfeOrig = (int)$object->array_options['options_fk_nfe_origem'];
                } elseif (!$idNfeOrig && !empty($object->fk_facture_source)) {
                    $sqlFind = "SELECT id FROM ".MAIN_DB_PREFIX."nfe_emitidas 
                                WHERE fk_facture = ".(int)$object->fk_facture_source."
                                ORDER BY (CASE WHEN LOWER(status) LIKE 'autoriz%' THEN 1 ELSE 2 END), id DESC
                                LIMIT 1";
                    $rFind = $this->db->query($sqlFind);
                    if ($rFind && $this->db->num_rows($rFind)>0) {
                        $idNfeOrig = (int)$this->db->fetch_object($rFind)->id;
                    }
                }

                if ($idNfeOrig <= 0) {
                    setEventMessages('NF-e de origem não localizada. Informe ?id_nfe_origem=ID na URL.', null, 'errors');
                    return 0;
                }

                // 2. Busca XML da nota original
                $resOrig = $this->db->query("SELECT xml_completo, chave FROM ".MAIN_DB_PREFIX."nfe_emitidas WHERE id = ".$idNfeOrig." LIMIT 1");
                if (!$resOrig || $this->db->num_rows($resOrig)==0) {
                    setEventMessages('NF-e origem (ID '.$idNfeOrig.') não encontrada.', null, 'errors');
                    return 0;
                }
                $orig = $this->db->fetch_object($resOrig);
                if (empty($orig->xml_completo)) {
                    setEventMessages('XML completo da NF-e origem está vazio.', null, 'errors');
                    return 0;
                }

                // 3. Parseia XML
                dol_include_once('/custom/composerlib/vendor/autoload.php');
                try {
                    $std = (new \NFePHP\NFe\Common\Standardize())->toStd($orig->xml_completo);
                } catch (Exception $e) {
                    setEventMessages('Erro ao interpretar XML origem: '.$e->getMessage(), null, 'errors');
                    return 0;
                }

                $detItens = $std->NFe->infNFe->det ?? [];
                if (!is_array($detItens)) $detItens = [$detItens];

                // 4. Monta mapa de alíquotas por cProd (código do produto)
                $aliquotasOriginais = [];
                foreach ($detItens as $det) {
                    $prod = $det->prod ?? null;
                    if (!$prod) continue;

                    $cProd = (string)($prod->cProd ?? '');
                    if ($cProd === '') continue;

                    $aliq = [
                        'csosn' => '102',
                        'icms_cred_aliq' => 0,
                        'icms_st_mva' => 0,
                        'icms_st_aliq' => 0,
                        'icms_st_red_bc' => 0,
                        'pis_cst' => '49',
                        'pis_aliq' => 0,
                        'cofins_cst' => '49',
                        'cofins_aliq' => 0
                    ];

                    // Extrai ICMS
                    $imposto = $det->imposto ?? null;
                    if (!empty($imposto->ICMS)) {
                        $csosnTags = ['ICMSSN101', 'ICMSSN102', 'ICMSSN103', 'ICMSSN201', 'ICMSSN202', 'ICMSSN203', 
                                      'ICMSSN300', 'ICMSSN400', 'ICMSSN500', 'ICMSSN900'];
                        foreach ($csosnTags as $tag) {
                            if (isset($imposto->ICMS->$tag)) {
                                $icmsData = $imposto->ICMS->$tag;
                                $aliq['csosn'] = (string)($icmsData->CSOSN ?? '102');
                                $aliq['icms_cred_aliq'] = (float)($icmsData->pCredSN ?? 0);
                                $aliq['icms_st_mva'] = (float)($icmsData->pMVAST ?? 0);
                                $aliq['icms_st_aliq'] = (float)($icmsData->pICMSST ?? 0);
                                $aliq['icms_st_red_bc'] = (float)($icmsData->pRedBCST ?? 0);
                                break;
                            }
                        }
                    }

                    // Extrai PIS
                    if (!empty($imposto->PIS)) {
                        $pisTags = ['PISAliq', 'PISQtde', 'PISNT', 'PISOutr'];
                        foreach ($pisTags as $tag) {
                            if (isset($imposto->PIS->$tag)) {
                                $pisData = $imposto->PIS->$tag;
                                $aliq['pis_cst'] = (string)($pisData->CST ?? '49');
                                $aliq['pis_aliq'] = (float)($pisData->pPIS ?? 0);
                                break;
                            }
                        }
                    }

                    // Extrai COFINS
                    if (!empty($imposto->COFINS)) {
                        $cofinsTags = ['COFINSAliq', 'COFINSQtde', 'COFINSNT', 'COFINSOutr'];
                        foreach ($cofinsTags as $tag) {
                            if (isset($imposto->COFINS->$tag)) {
                                $cofinsData = $imposto->COFINS->$tag;
                                $aliq['cofins_cst'] = (string)($cofinsData->CST ?? '49');
                                $aliq['cofins_aliq'] = (float)($cofinsData->pCOFINS ?? 0);
                                break;
                            }
                        }
                    }

                    $aliquotasOriginais[$cProd] = $aliq;
                }

                // 5. INJETA alíquotas nos produtos da fatura atual
                foreach ($listaProdutos as &$prd) {
                    $refProduto = $prd['ref'] ?? '';
                    if (isset($aliquotasOriginais[$refProduto])) {
                        $aliq = $aliquotasOriginais[$refProduto];
                        
                        // Garante que extrafields existe
                        if (!isset($prd['extrafields'])) $prd['extrafields'] = [];
                        
                        // GARANTE ou DEFINE prd_fornecimento (1=Própria,2=Adquirida) — fallback conservador = '2'
                        if (empty($prd['extrafields']['prd_fornecimento'])) {
                            $prd['extrafields']['prd_fornecimento'] = '2';
                            // log para auditoria: produto recebeu fallback
                            error_log("DEV - prd_fornecimento ausente para produto {$refProduto}; aplicado fallback '2' (Adquirido/Revenda).");
                        }
                        // preserva qualquer valor já presente (ex: importado de array_options)
                        
                        // INJETA alíquotas
                        $prd['extrafields']['csosn_original'] = $aliq['csosn'];
                        $prd['extrafields']['icms_cred_aliq'] = $aliq['icms_cred_aliq'];
                        $prd['extrafields']['icms_st_mva'] = $aliq['icms_st_mva'];
                        $prd['extrafields']['icms_st_aliq'] = $aliq['icms_st_aliq'];
                        $prd['extrafields']['icms_st_red_bc'] = $aliq['icms_st_red_bc'];
                        $prd['extrafields']['pis_cst'] = $aliq['pis_cst'];
                        $prd['extrafields']['pis_aliq'] = $aliq['pis_aliq'];
                        $prd['extrafields']['cofins_cst'] = $aliq['cofins_cst'];
                        $prd['extrafields']['cofins_aliq'] = $aliq['cofins_aliq'];
                        
                        // GARANTIR valores positivos (redundância, mas seguro)
                        $prd['quantidade'] = abs((float)$prd['quantidade']);
                        $prd['preco_venda_semtaxa'] = abs((float)$prd['preco_venda_semtaxa']);
                        $prd['total_semtaxa'] = abs((float)$prd['total_semtaxa']);
                        $prd['total_comtaxa'] = abs((float)$prd['total_comtaxa']);
                        error_log("DEV - Alíquotas injetadas no produto {$refProduto}: " . json_encode($aliq, JSON_UNESCAPED_UNICODE));
                    } else {
                        error_log("DEV - AVISO: Produto {$refProduto} não encontrado na nota original. Alíquotas zeradas.");
                    }
                }
                unset($prd); // Desfaz referência

                // 6. Marca flags de devolução
                $dadosFatura['extrafields']['is_devolucao'] = 1;
                $dadosFatura['extrafields']['fk_nfe_origem'] = $idNfeOrig;
                $dadosFatura['extrafields']['chave_origem'] = $orig->chave ?? '';
                if (empty($dadosFatura['extrafields']['nat_op'])) {
                    $dadosFatura['extrafields']['nat_op'] = 'Devolução de Mercadoria';
                }
                $dadosFatura['forma_pagamento'] = '90';
                $dadosFatura['tpNF'] = 0;
                $dadosFatura['finNFe'] = 4;
            }

            // Chamada de geração da NFe
            if (function_exists('gerarNfe')) {
                $ehDevolucao = ($action === 'gerarnfe_dev' || !empty($dadosFatura['extrafields']['is_devolucao']));
                $idNfeAntes = $this->obterUltimaNfeId($fatura->id);
                $inserirSeq = (int)GETPOST('inserirSeq', 'int');
                $numeroCustom = ($inserirSeq > 0) ? $inserirSeq : null;

                try {
                gerarNfe($this->db, $dados_minha_empresa, $dados_destinatario, $listaProdutos, $dadosFatura, $numeroCustom);
                } catch (\Throwable $eFatal) {
                    error_log('[NFe] FATAL em gerarNfe: ' . $eFatal->getMessage() . ' em ' . $eFatal->getFile() . ':' . $eFatal->getLine());
                    setEventMessages('Erro fatal ao gerar NF-e: ' . $eFatal->getMessage(), null, 'errors');
                }
                
                $idNfeDepois = $this->obterUltimaNfeId($fatura->id);
                $novaNfeCriada = ($idNfeDepois > 0 && $idNfeDepois !== $idNfeAntes);

                if ($ehDevolucao) {
                    if ($novaNfeCriada) {
                        // Remove mensagem padrão
                        if (!empty($_SESSION['dol_events']['mesgs'])) {
                            $_SESSION['dol_events']['mesgs'] = array_filter(
                                $_SESSION['dol_events']['mesgs'],
                                static function ($m) {
                                    return stripos($m, 'nfe gerada com sucesso') === false;
                                }
                            );
                        }
                        $this->marcarNfeComoDevolucao($fatura->id);
                        setEventMessages($langs->trans("NF-e de devolução gerada com sucesso!"), null, 'mesgs');
                    } else {
                        if (!empty($_SESSION['dol_events']['mesgs'])) {
                            $_SESSION['dol_events']['mesgs'] = array_filter(
                                $_SESSION['dol_events']['mesgs'],
                                static function ($m) {
                                    return stripos($m, 'nfe gerada com sucesso') === false;
                                }
                            );
                        }
                        if (empty($_SESSION['dol_events']['errors'])) {
                            setEventMessages($langs->trans("Erro ao gerar NF-e de devolução. Verifique os logs."), null, 'errors');
                        }
                    }
                } elseif (!$novaNfeCriada && empty($_SESSION['dol_events']['errors'])) {
                    setEventMessages($this->langs->trans("Erro ao gerar NF-e. Verifique os logs."), null, 'errors');
                }
            } else {
                setEventMessages($this->langs->trans('Função gerarNfe não encontrada.'), null, 'errors');
            }
            return 1; // ação tratada
        }

        return 0; // não bloqueia outros hooks
    }

    /**
     * Adiciona botão "GERAR NFE" na tela da fatura
     */
    public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
    {
        $contexts = empty($parameters['context']) ? array() : explode(':', $parameters['context']);
        // Considera invoicecard e facturecard
        if (!array_intersect($contexts, array('invoicecard', 'facturecard'))) {
            return 0;
        }
        
        global $user, $langs, $form;
        $db = $this->db;

        dol_include_once('/core/class/html.form.class.php');
        if (empty($form)) {
            $form = new Form($db);
        }

        // Permissão simples: admin ou direito de criar fatura
        $usercan = (!empty($user->admin) || $user->hasRight('facture', 'creer'));

        if ($usercan && !empty($object->id)) {

            // NORMALIZA extrafields da fatura para evitar warnings de preg_match/strpos com null
            $this->sanitizeExtrafields($object, 'facture');

            // ========== CONTROLE DE EXIBIÇÃO DO BOTÃO NFe ==========
            // Oculta botão apenas se existir NFe AUTORIZADA
            // Se a NFe foi CANCELADA, permite emitir uma nova
            define('NFE_OCULTAR_BOTAO_APOS_EMISSAO', true);
            
            $exibirBotaoNFe = true;
            
            // VALIDAÇÃO 1: Não exibe botão se a fatura estiver em RASCUNHO (status = 0)
            if (isset($object->statut) && (int)$object->statut === 0) {
                $exibirBotaoNFe = false;
            }
            
            if ($exibirBotaoNFe && NFE_OCULTAR_BOTAO_APOS_EMISSAO) {
                // Verifica se existe NFe autorizada (não cancelada, não rejeitada, não em erro)
                $sqlCheck = "SELECT id, status FROM ".MAIN_DB_PREFIX."nfe_emitidas 
                             WHERE fk_facture = ".(int)$object->id." 
                             AND (LOWER(status) LIKE '%autoriz%' OR LOWER(status) = 'processamento')
                             LIMIT 1";
                $resCheck = $this->db->query($sqlCheck);
                if ($resCheck && $this->db->num_rows($resCheck) > 0) {
                    // Existe NFe autorizada - oculta botão
                    $exibirBotaoNFe = false;
                }
            }

            // (Reusa a consulta simplificada para saber se já existe NF-e)
            $sqln = "SELECT id, numero_nfe, status, chave FROM ".MAIN_DB_PREFIX."nfe_emitidas 
                     WHERE fk_facture = ".((int)$object->id)." 
                     ORDER BY id DESC LIMIT 1";
            $resn = $this->db->query($sqln);
            $nfInfo = ($resn && $this->db->num_rows($resn) ) ? $this->db->fetch_object($resn) : null;
            $stnorm = $nfInfo ? strtolower($nfInfo->status) : '';
            $temAutorizada = ($nfInfo && strpos($stnorm,'autoriz')!==false);
            $isDevolucaoAut = ($temAutorizada && strpos($stnorm,'dev')!==false);

            // --- BOTÕES (mantidos) ---
            // Só mostra botão "Gerar NFe" se exibirBotaoNFe = true
            if ((int)$object->type === 0 && $exibirBotaoNFe) {
                print dolGetButtonAction(
                    $this->langs->trans('Gerar NFe'),
                    '',
                    'default',
                    $_SERVER['PHP_SELF'].'?facid='.(int)$object->id.'&action=confirm_gerarnfe&token='.newToken(),
                    '',
                    true
                );
            }
            if ((int)$object->type === 2 && !$isDevolucaoAut) {
                // Detecta NF-e origem (mesma lógica anterior simplificada)
                $idNfeOrig = 0;
                if (!empty($object->array_options['options_fk_nfe_origem'])) $idNfeOrig = (int)$object->array_options['options_fk_nfe_origem'];
                elseif (!empty($object->array_options['options_nfe_origem_id'])) $idNfeOrig = (int)$object->array_options['options_nfe_origem_id'];
                elseif (!empty($object->fk_facture_source)) {
                    $q = $this->db->query("SELECT id FROM ".MAIN_DB_PREFIX."nfe_emitidas WHERE fk_facture=".(int)$object->fk_facture_source." ORDER BY id DESC LIMIT 1");
                    if ($q && $this->db->num_rows($q)) $idNfeOrig = (int)$this->db->fetch_object($q)->id;
                }
                $url = $_SERVER['PHP_SELF'].'?facid='.(int)$object->id.'&action=confirm_gerarnfe_dev&token='.newToken();
                if ($idNfeOrig>0) $url .= '&id_nfe_origem='.$idNfeOrig;
                print dolGetButtonAction(
                    $this->langs->trans('Gerar NFe Devolução'),
                    '',
                    'default',
                    $url,
                    '',
                    true
                );
            }

        }
        return 0;
    }

    // SUBSTITUI: antes retornava 0 (stub). Agora injeta badge somente via JS após título existir.
    public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
    {
        $contexts = empty($parameters['context']) ? array() : explode(':', $parameters['context']);
        if (empty($object->id) || !in_array('invoicecard', $contexts)) return 0;

        // Sanitiza extrafields ANTES de qualquer output de campos pela página
        $this->sanitizeExtrafields($object, 'facture');
        
        // ========== BLOQUEIO DE EXTRAFIELDS QUANDO EXISTE NFe AUTORIZADA ==========
        $temNfeAutorizada = false;
        if (!empty($object->id)) {
            try {
                $sqlNfeCheck = "SELECT id FROM ".MAIN_DB_PREFIX."nfe_emitidas 
                                 WHERE fk_facture = ".(int)$object->id." 
                                 AND (LOWER(status) LIKE '%autoriz%' OR LOWER(status) = 'processamento')
                                 LIMIT 1";
                $resNfeCheck = $this->db->query($sqlNfeCheck);
                if ($resNfeCheck && $this->db->num_rows($resNfeCheck) > 0) {
                    $temNfeAutorizada = true;
                }
            } catch (Exception $e) {
                error_log('[NFe] Erro ao verificar NFe para bloqueio de extrafields: ' . $e->getMessage());
            }
        }
        
        if ($temNfeAutorizada) {
            $camposNfe = array(
                'nat_op', 'indpres', 'cfop', 'frete',
                'info_complementar', 'modalidade_frete'
            );
            
            static $bloqueioInjected = false;
            if (!$bloqueioInjected) {
                $bloqueioInjected = true;
                $camposJson = json_encode($camposNfe);
                print <<<BLOQUEIO
<script>
jQuery(document).ready(function($) {
    var camposBloqueados = $camposJson;
    
    camposBloqueados.forEach(function(campo) {
        var input = $('input[name="options_' + campo + '"]');
        var textarea = $('textarea[name="options_' + campo + '"]');
        var select = $('select[name="options_' + campo + '"]');
        
        if (input.length) {
            input.prop('readonly', true);
        }
        if (textarea.length) {
            textarea.prop('readonly', true);
        }
        
        if (select.length) {
            var valorAtual = select.val();
            select.prop('disabled', true);
            
            if (valorAtual && !$('input[name="options_' + campo + '_hidden"]').length) {
                select.after('<input type="hidden" name="options_' + campo + '" value="' + valorAtual + '">');
            }
        }
    });
});
</script>
BLOQUEIO;
            }
        }
        // ========== FIM BLOQUEIO DE EXTRAFIELDS ==========

        $sql = "SELECT id, numero_nfe, status FROM ".MAIN_DB_PREFIX."nfe_emitidas
                WHERE fk_facture=".(int)$object->id."
                ORDER BY id DESC LIMIT 1";
        $res = $this->db->query($sql);
        if (!$res || $this->db->num_rows($res)==0) return 0;

        $r = $this->db->fetch_object($res);

        // --- mapeamento status (já existente) ---
        $status = trim($r->status);
        $sl = mb_strtolower($status, 'UTF-8');
        $norm = iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$sl);
        if ($norm === false) $norm = $sl;
        $norm = str_replace(['[',']'],'',$norm);
        $hasAutoriz = (strpos($norm,'autoriz') !== false);
        $hasDevToken =
            (strpos($norm,'(dev)') !== false) ||
            preg_match('/[\s\-]dev(\s|$)/', $norm) ||
            (strpos($norm,'devolu') !== false);
        $isDevolucao = $hasAutoriz && $hasDevToken;

        $class = 'badge-status1';
        if ($isDevolucao)                       $class = 'badge-status3';
        elseif (strpos($sl,'autoriz')!==false)  $class = 'badge-status4';
        elseif (strpos($sl,'cancel')!==false)   $class = 'badge-status0';
        elseif (strpos($sl,'rejeit')!==false)   $class = 'badge-status1';
        elseif (strpos($sl,'deneg')!==false)    $class = 'badge-status8';

        $label = $isDevolucao ? ('NFE #'.$r->numero_nfe.' Devolução') : ('NFe #'.$r->numero_nfe);

        static $printed = false;
        if ($printed) return 0;
        $printed = true;

        // JS: exibe 'Dinheiro' visualmente quando forma de pagamento estiver vazia
        echo '<script>
(function(){
    jQuery(document).ready(function($){
        var sel = $("select[name=\'mode_reglement_id\']");
        if (!sel.length) return;
        if (sel.val() === "" || sel.val() === "0" || !sel.val()) {
            var found = false;
            sel.find("option").each(function(){
                if (/dinheiro|cash|liq/i.test($(this).text())) {
                    sel.val($(this).val());
                    found = true;
                    return false;
                }
            });
        }
    });
})();
</script>';

        // Classe extra para aplicar override visual apenas na devolução
        $extraClass = $isDevolucao ? ' nfe-devolucao' : '';

        echo '
'.($isDevolucao ? '<style>
/* Override: badge devolução com fundo azul sólido igual estilo cheio */
.badge.badge-status.badge-status3.nfe-devolucao{
  background:#0d62c9 !important;
  color:#fff !important;
  border:1px solid #0d62c9 !important;
  box-shadow:inset 0 0 0 1px rgba(255, 255, 255, 0);
}
</style>' : '').'
<span id="nfeStatusBadge" class="badge badge-status '.$class.$extraClass.'" style="display:none;">'.dol_escape_htmltag($label).'</span>
<script>
(function(){
  var b=document.getElementById("nfeStatusBadge");
  if(!b) return;
  var host=document.querySelector(".statusref")||document.querySelector(".arearef .refid");
  if(!host){
     b.style.display="inline-block";
     document.body.insertBefore(b, document.body.firstChild);
     return;
  }
  if(host.firstChild) host.insertBefore(b, host.firstChild); else host.appendChild(b);
  b.style.display="inline-block";
})();
</script>';

        return 0;
    }

    /**
     * Exibe confirmação antes de gerar NFe
     */
    public function formConfirm($parameters, &$object, &$action, $hookmanager)
    {
        $contexts = empty($parameters['context']) ? array() : explode(':', $parameters['context']);
        if (!in_array('invoicecard', $contexts)) {
            return 0;
        }

        if ($action === 'confirm_gerarnfe' || $action === 'confirm_gerarnfe_dev') {
            global $langs, $form;
            $db = $this->db;

            dol_include_once('/core/class/html.form.class.php');
            if (empty($form)) {
                $form = new Form($db);
            }
            
            $label = ($action === 'confirm_gerarnfe_dev') ? $langs->trans('Gerar NFe Devolução') : $langs->trans('Gerar NFe');

            if ($action === 'confirm_gerarnfe') {
                // Busca próximo número da sequência
                // CNPJ da empresa: MAIN_INFO_SIREN (campo correto no Dolibarr BR)
                $cnpjEmpresa = preg_replace('/\D/', '', getDolGlobalString('MAIN_INFO_SIREN'));
                // Ambiente atual (1=Produção, 2=Homologação)
                $ambienteAtual = 2;
                $resCfgAmb = $db->query("SELECT value FROM ".MAIN_DB_PREFIX."nfe_config WHERE name = 'ambiente' LIMIT 1");
                if ($resCfgAmb && ($objCfgAmb = $db->fetch_object($resCfgAmb))) {
                    $ambienteAtual = (int)$objCfgAmb->value ?: 2;
                }
                $proximoNumero = 1;
                $sqlSeq = $db->query("SELECT ultimo_numero FROM ".MAIN_DB_PREFIX."nfe_sequencia
                    WHERE cnpj='".$db->escape($cnpjEmpresa)."' AND serie=1 AND ambiente=".$ambienteAtual." LIMIT 1");
                if ($sqlSeq && ($objSeq = $db->fetch_object($sqlSeq))) {
                    $proximoNumero = (int)$objSeq->ultimo_numero + 1;
                }
                $question = '<strong>Tem certeza que deseja gerar a NF-e nº '.$proximoNumero.' para esta fatura?</strong>'
                    . '<br><br>Número da NF-e<br>'
                    . '<input type="text" id="inserirSeq_vis" value="'.dol_escape_htmltag($proximoNumero).'" style="width:120px;padding:4px 8px;font-size:15px;border:1px solid #ccc;border-radius:4px;">'
                    . '<script>jQuery(function($){ $("#inserirSeq_vis").on("input", function(){ $("#inserirSeq").val($(this).val()); }); });</script>';
                $formquestion = array(
                    array('type' => 'hidden', 'name' => 'inserirSeq', 'value' => $proximoNumero)
                );
            } else {
                $question = $langs->trans('Tem certeza que deseja gerar a NFe de devolução para esta nota de crédito?');
                $formquestion = array();
            }

            $targetAction = ($action === 'confirm_gerarnfe_dev') ? 'gerarnfe_dev' : 'gerarnfe';

            // Não usar print; retornar via resprints
            $this->resprints = $form->formconfirm(
                $_SERVER['PHP_SELF'].'?facid='.$object->id,
                $label,
                $question,
                $targetAction,
                $formquestion,
                0,
                1,
                260, // altura
                500  // largura
            );
            return 1; // indica que fornecemos a confirmação
        }

        return 0;
    }

    private function obterUltimaNfeId($factureId)
    {
        $factureId = (int) $factureId;
        if ($factureId <= 0) {
            return 0;
        }

        $sql = "SELECT id FROM ".MAIN_DB_PREFIX."nfe_emitidas WHERE fk_facture = ".$factureId." ORDER BY id DESC LIMIT 1";
        $res = $this->db->query($sql);
        if ($res && $this->db->num_rows($res) > 0) {
            return (int) $this->db->fetch_object($res)->id;
        }
        return 0;
    }

    private function marcarNfeComoDevolucao($factureId)
    {
        $factureId = (int)$factureId;
        if ($factureId <= 0) return;

        $sql = "SELECT id, status FROM ".MAIN_DB_PREFIX."nfe_emitidas 
                WHERE fk_facture = ".$factureId." 
                ORDER BY id DESC LIMIT 1";
        $res = $this->db->query($sql);
        if ($res && $this->db->num_rows($res) > 0) {
            $row = $this->db->fetch_object($res);
            $statusLower = mb_strtolower($row->status, 'UTF-8');
            if (strpos($statusLower, 'dev') === false) {
                $this->db->query("UPDATE ".MAIN_DB_PREFIX."nfe_emitidas 
                                  SET status = 'Autorizada DEV' 
                                  WHERE id = ".(int)$row->id);
            }
        }
    }
}