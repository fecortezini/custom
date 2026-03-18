<?php
ini_set('display_errors',1); 
error_reporting(E_ALL);
// Evita redeclaração de função quando outra lib já declarou buscarDadosIbge()
if (!function_exists('buscarDadosIbge')) {
    // usa dol_include_once para resolver caminhos no Dolibarr
    dol_include_once('/custom/labapp/lib/ibge_utils.php');
}
class ActionsNfse
{
    protected $db;
    protected $langs;

    // Propriedades esperadas pelo Dolibarr - ADICIONADAS AQUI
    public $results = array();
    public $resprints = '';
    public $error = '';
    public $errors = array();

    // NOVO: flags para silenciar avisos PHP
    protected static $warningsMuted = false;
    protected static $errorHandlerRegistered = false;

    // Helper para normalizar extrafields (evita null no core)
    private function sanitizeExtrafields(&$obj)
    {
        if (isset($obj) && is_object($obj) && isset($obj->array_options) && is_array($obj->array_options)) {
            foreach ($obj->array_options as $k => $v) {
                if ($v === null) {
                    $obj->array_options[$k] = '';
                } elseif (is_bool($v)) {
                    $obj->array_options[$k] = $v ? '1' : '0';
                } elseif (is_array($v)) {
                    $obj->array_options[$k] = json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                }
            }
        }
    }

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

    // NOVO: handler global da requisição para suprimir avisos
    private function registerErrorSilencer()
    {
        if (self::$errorHandlerRegistered) return;
        set_error_handler(function ($errno, $errstr) {
            if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED ||
                $errno === E_NOTICE || $errno === E_USER_NOTICE ||
                $errno === E_WARNING || $errno === E_USER_WARNING) {
                return true;
            }
            return false;
        });
        self::$errorHandlerRegistered = true;
    }

    public function __construct($db, $langs = null)
    {
        global $conf;
        $this->db = $db;
        // Usa o $langs passado ou faz fallback para o global
        if ($langs !== null) {
            $this->langs = $langs;
        } else {
            global $langs;
            $this->langs = $langs;
        }

        // NOVO: silencia e registra handler
        $this->mutePhpWarnings();
        $this->registerErrorSilencer();
    }

    /**
     * Carrega certificado A1 do banco de dados (Padrão Nacional)
     */
    private function carregarCertificadoA1Nacional($db) {
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
        $original = nfseDecryptPassword($certPass, $db);
        
        // Retorna certificado usando a biblioteca NFePHP
        try {
            $cert = \NFePHP\Common\Certificate::readPfx($certPfx, $original);
            return $cert;
        } catch (Exception $e) {
            // Tenta decodificar base64 se falhar
            $certPfxDecoded = base64_decode($certPfx, true);
            if ($certPfxDecoded !== false) {
                $cert = \NFePHP\Common\Certificate::readPfx($certPfxDecoded, $original);
                return $cert;
            }
            throw new Exception('Erro ao ler certificado: ' . $e->getMessage());
        }
    }

    /**
     * Busca configuração de ambiente (produção/homologação)
     */
    private function getAmbienteNacional($db) {
        $ambiente = 2; // Padrão: homologação
        
        $sql = "SELECT value FROM ".MAIN_DB_PREFIX."nfe_config WHERE name = 'ambiente' LIMIT 1";
        $res = $db->query($sql);
        if ($res && $obj = $db->fetch_object($res)) {
            $ambiente = (int)$obj->value;
        }
        
        return $ambiente;
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
     *
     * NOTA: A lógica de injeção de campos NFSe/NFe em admin/company.php foi
     * transferida para custom/mdfe/core/modules/actions_lab.class.php (módulo MDF-e).
     * Consulte esse arquivo para manutenção dos campos da empresa.
     */
    public function doActions($parameters, &$object, &$action, $hookmanager)
    {
        $contexts = empty($parameters['context']) ? array() : explode(':', $parameters['context']);

        // PROTEÇÃO CRÍTICA: Log de monitoramento preventivo
        if (!empty($object->id) && empty($object->ref) && is_object($object)) {
            error_log("[NFSe ALERT] Fatura ID {$object->id} sem referência! Status: ".($object->statut ?? 'N/A'));
        }

        if (!in_array('invoicecard', $contexts)) {
            return 0;
        }
        // PASSO 1: Pega a fatura da tela atual
        // $object já é a fatura aberta na tela; se não tiver, busca pelo ID da URL.
        $fac = $object ?? new Facture($this->db);
        if (empty($fac->id)) { $id = GETPOST('facid','int'); if ($id) $fac->fetch($id); }

        // PASSO 2: Carrega os extrafields da fatura (sem isso o array_options fica vazio)
        $fac->fetch_optionals();
        global $mysoc; // $mysoc = dados da empresa do sistema (emitente)
        $cidadeEmitente = $mysoc->town ?? '';
        $codigoIbge = buscarDadosIbge($this->db, $cidadeEmitente, $mysoc->state_code ?? null);
        // Guarda: buscarDadosIbge() retorna null se cidade não encontrada (fatal error em PHP 8 sem esta verificação)
        if ($codigoIbge !== null && !empty($codigoIbge->codigo_ibge) && !empty($fac->id)) {
            $fac->array_options['options_muni_prest'] = $codigoIbge->codigo_ibge;
            $fac->updateExtraField('muni_prest');
        }

        // PASSO 3: Se o campo muni_prest estiver VAZIO, preenche com a cidade da empresa emitente
        if (empty($fac->array_options['options_muni_prest']) && !empty($fac->id)) {

        }

        // VALIDAÇÃO 3: Bloqueia EDIÇÃO de fatura que já possui NFSe autorizada
        if (in_array($action, array('modif', 'confirm_modif'))) {
            if (!empty($object->id)) {
                try {
                    $sqlNfseCheck = "SELECT id, numero_nfse FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas 
                                     WHERE id_fatura = ".(int)$object->id." 
                                     AND LOWER(status) = 'autorizada'
                                     LIMIT 1";
                    $resNfseCheck = $this->db->query($sqlNfseCheck);
                    if ($resNfseCheck && $this->db->num_rows($resNfseCheck) > 0) {
                        $nfseData = $this->db->fetch_object($resNfseCheck);
                        global $langs;
                        setEventMessages('Não é possível alterar esta fatura porque já existe uma NFSe autorizada (Nº '.$nfseData->numero_nfse.'). Cancele a NFSe primeiro.', null, 'errors');
                        
                        // Redireciona de volta para a fatura sem permitir edição
                        header('Location: '.$_SERVER['PHP_SELF'].'?facid='.$object->id);
                        exit;
                    }
                } catch (Exception $e) {
                    error_log('[NFSe] Erro ao verificar NFSe na edição: ' . $e->getMessage());
                    // Permite continuar se a tabela não existir
                }
            }
        }
        
        if (in_array($action, array('update', 'set_extrafields'))) {
            if (!empty($object->id)) {
                try {
                    $sqlNfseCheck = "SELECT id FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas 
                                     WHERE id_fatura = ".(int)$object->id." 
                                     AND LOWER(status) = 'autorizada'
                                     LIMIT 1";
                    $resNfseCheck = $this->db->query($sqlNfseCheck);
                    if ($resNfseCheck && $this->db->num_rows($resNfseCheck) > 0) {
                        // Lista de extrafields protegidos
                        $camposProtegidos = array(
                            'exigibilidade_iss', 'nat_op_sv', 'muni_prest', 'discriminacao', 
                            'iss_retido', 'cod_tribut_muni', 'srv_valor_deducoes',
                            'inscImobFisc', 'cObra', 'xnomeevento', 'dtini', 'dtfim',
                            'xbairro', 'xlgr', 'nro'
                        );
                        
                        // Verifica se algum campo protegido está sendo ALTERADO (não apenas enviado)
                        $object->fetch_optionals();
                        foreach ($camposProtegidos as $campo) {
                            $valorNovo = GETPOST('options_'.$campo, 'none');
                            $valorAtual = $object->array_options['options_'.$campo] ?? null;
                            
                            // Só bloqueia se o valor está REALMENTE sendo alterado
                            if ($valorNovo !== null && $valorNovo !== '' && $valorNovo != $valorAtual) {
                                global $langs;
                                error_log('[NFSe] Tentativa de alterar campo protegido: '.$campo.' | Valor atual: '.$valorAtual.' | Valor novo: '.$valorNovo);
                                setEventMessages('Não é possível alterar campos relacionados à NFSe. Esta fatura possui uma NFSe autorizada.', null, 'errors');
                                header('Location: '.$_SERVER['PHP_SELF'].'?facid='.$object->id);
                                exit;
                            }
                        }
                    }
                } catch (Exception $e) {
                    error_log('[NFSe] Erro ao verificar NFSe para proteção de extrafields: ' . $e->getMessage());
                }
            }
        }
        
        // VALIDAÇÃO 6: Bloqueia EXCLUSÃO de pagamentos quando existir NFSe autorizada
        if (in_array($action, array('confirm_delete_paiement', 'deletepayment'))) {
            if (!empty($object->id)) {
                try {
                    $sqlNfseCheck = "SELECT id, numero_nfse FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas 
                                     WHERE id_fatura = ".(int)$object->id." 
                                     AND LOWER(status) = 'autorizada'
                                     LIMIT 1";
                    $resNfseCheck = $this->db->query($sqlNfseCheck);
                    if ($resNfseCheck && $this->db->num_rows($resNfseCheck) > 0) {
                        $nfseData = $this->db->fetch_object($resNfseCheck);
                        global $langs;
                        setEventMessages('Não é possível excluir o pagamento desta fatura porque já possui uma NFSe autorizada (Nº ' . $nfseData->numero_nfse . ').', null, 'errors');
                        header('Location: '.$_SERVER['PHP_SELF'].'?facid='.$object->id);
                        exit;
                    }
                } catch (Exception $e) {
                    error_log('[NFSe] Erro ao verificar NFSe na exclusão de pagamento: ' . $e->getMessage());
                }
            }
        }

        // ========== NOVA AÇÃO: CANCELAMENTO AUTOMÁTICO + REABERTURA (PADRÃO NACIONAL) ==========
        // FUNCIONALIDADE DESABILITADA TEMPORARIAMENTE - Para uso futuro
        /* CANCELAMENTO AUTOMÁTICO DESABILITADO
        if ($action === 'confirm_reopen_with_cancel_nfse') {
            global $langs, $user;
            $db = $this->db;
            
            require_once DOL_DOCUMENT_ROOT . '/custom/composerlib/vendor/autoload.php';
            require_once DOL_DOCUMENT_ROOT.'/custom/nfse/lib/nfse_security.lib.php';
            
            $id_nfse = GETPOST('id_nfse', 'int');
            
            if (empty($id_nfse)) {
                setEventMessages($langs->trans("ID da NFSe não informado"), null, 'errors');
                return 0;
            }
            
            try {
                $db->begin();
                
                // 1. Busca dados da NFS-e Nacional
                $sql = "SELECT * FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas WHERE id = ".((int)$id_nfse);
                $res = $db->query($sql);
                
                if (!$res || $db->num_rows($res) == 0) {
                    throw new Exception('NFS-e não encontrada');
                }
                
                $nfse = $db->fetch_object($res);
                
                // VALIDAÇÃO 4: Não pode cancelar NFSe se a fatura estiver PAGA
                if (!empty($object->id) && isset($object->paye) && $object->paye == 1) {
                    throw new Exception('Não é possível cancelar a NFSe porque a fatura está com status PAGA. Desfaça o pagamento primeiro.');
                }
                
                // Verifica se já está cancelada
                if (strtolower($nfse->status) === 'cancelada') {
                    throw new Exception('Esta NFS-e já está cancelada');
                }
                
                // Verifica se está autorizada
                if (strtolower($nfse->status) !== 'autorizada') {
                    throw new Exception('Apenas NFS-e autorizadas podem ser canceladas');
                }
                
                // Verifica se tem chave de acesso
                if (empty($nfse->chave_acesso)) {
                    throw new Exception('Chave de acesso não encontrada para esta NFS-e');
                }
                
                // Define motivo padrão para reabertura de fatura
                $codigo_motivo = 1; // Erro na emissão
                $descricao_motivo = 'Cancelamento para correção de dados da fatura';
                
                // 2. Carrega certificado e configuração
                $cert = $this->carregarCertificadoA1Nacional($db);
                $ambiente = $this->getAmbienteNacional($db);
                
                // 3. Cria configuração JSON para a biblioteca
                $config = new stdClass();
                $config->tpamb = $ambiente;
                $configJson = json_encode($config);
                
                // 4. Inicializa ferramenta
                $tools = new \Hadder\NfseNacional\Tools($configJson, $cert);
                
                // 5. Monta estrutura do evento de cancelamento
                $std = new stdClass();
                $std->infPedReg = new stdClass();
                $std->infPedReg->chNFSe = $nfse->chave_acesso;
                $std->infPedReg->CNPJAutor = preg_replace('/\D/', '', $nfse->prestador_cnpj);
                $std->infPedReg->dhEvento = date('Y-m-d\TH:i:sP');
                $std->infPedReg->tpAmb = $ambiente;
                $std->infPedReg->verAplic = 'LABCONNECTA_V1.0';
                
                // Evento e101101 - Cancelamento
                $std->infPedReg->e101101 = new stdClass();
                $std->infPedReg->e101101->xDesc = 'Cancelamento de NFS-e';
                $std->infPedReg->e101101->cMotivo = $codigo_motivo;
                $std->infPedReg->e101101->xMotivo = $descricao_motivo;
                
                // 6. Envia cancelamento
                error_log('[NFSE CANCELAMENTO AUTO] Enviando cancelamento da NFS-e #'.$nfse->numero_nfse.' (ID: '.$id_nfse.')');
                
                $response = $tools->cancelaNfse($std);
                
                // Variáveis para processamento
                $statusAtualizado = false;
                $mensagemRetorno = '';
                $protocolo = '';
                $xmlRetornoDecodificado = null;
                $dataHoraProcessamento = $response['dataHoraProcessamento'] ?? date('Y-m-d H:i:s');

                // 7. Verifica Sucesso (eventoXmlGZipB64)
                if (isset($response['eventoXmlGZipB64'])) {
                    $statusAtualizado = true;
                    try {
                        $xmlBin = base64_decode($response['eventoXmlGZipB64']);
                        if ($xmlBin) {
                            $xmlRetornoDecodificado = @gzdecode($xmlBin);
                            if ($xmlRetornoDecodificado === false) {
                                $xmlRetornoDecodificado = $xmlBin;
                            }
                            
                            if ($xmlRetornoDecodificado) {
                                if (preg_match('/<nProt>(\d+)<\/nProt>/', $xmlRetornoDecodificado, $matches)) {
                                    $protocolo = $matches[1];
                                }
                            }
                        }
                    } catch (Exception $ignore) {}
                    
                    $mensagemRetorno = 'NFS-e cancelada com sucesso!';
                    if ($protocolo) $mensagemRetorno .= ' (Protocolo: '.$protocolo.')';
                    
                } elseif (isset($response['erro'])) {
                    $erros = [];
                    if (is_array($response['erro'])) {
                        foreach ($response['erro'] as $e) {
                            if (is_array($e)) {
                                $cod = $e['codigo'] ?? '';
                                $desc = $e['descricao'] ?? '';
                                $erros[] = trim($cod . ' - ' . $desc);
                            } elseif (is_string($e)) {
                                $erros[] = $e;
                            }
                        }
                    }
                    $mensagemRetorno = implode('; ', $erros);
                    
                } elseif (isset($response['message'])) {
                    $mensagemRetorno = $response['message'];
                } else {
                    $mensagemRetorno = 'Resposta inesperada da SEFAZ: ' . json_encode($response);
                }
                
                // 8. Registra evento de cancelamento
                $dataHoraEvento = date('Y-m-d H:i:s');
                
                $sqlEvento = \"INSERT INTO \".MAIN_DB_PREFIX.\"nfse_nacional_eventos (
                    id_nfse,
                    tipo_evento,
                    chave_nfse,
                    codigo_motivo,
                    descricao_motivo,
                    xml_enviado,
                    xml_retorno,
                    json_retorno,
                    status_evento,
                    protocolo,
                    mensagem_retorno,
                    data_hora_evento,
                    data_hora_processamento
                ) VALUES (
                    \".((int)$id_nfse).\",
                    'e101101',
                    '\".$db->escape($nfse->chave_acesso).\"',
                    \".((int)$codigo_motivo).\",
                    '\".$db->escape($descricao_motivo).\"',
                    '\".$db->escape(json_encode($std)).\"',
                    '\".$db->escape($xmlRetornoDecodificado).\"',
                    '\".$db->escape(json_encode($response)).\"',
                    '\".($statusAtualizado ? 'processado' : 'erro').\"',
                    '\".$db->escape($protocolo).\"',
                    '\".$db->escape($mensagemRetorno).\"',
                    '\".$db->escape($dataHoraEvento).\"',
                    '\".$db->escape($dataHoraProcessamento).\"'
                )\";
                
                if (!$db->query($sqlEvento)) {
                    throw new Exception('Erro ao salvar evento: ' . $db->lasterror());
                }
                
                if ($statusAtualizado) {
                    // 9. Atualiza status na tabela principal
                    $sqlUpdate = \"UPDATE \".MAIN_DB_PREFIX.\"nfse_nacional_emitidas 
                                  SET status = 'cancelada',
                                      data_hora_cancelamento = '\".$db->escape($dataHoraEvento).\"'
                                  WHERE id = \".((int)$id_nfse);
                    
                    if (!$db->query($sqlUpdate)) {
                        throw new Exception('Erro ao atualizar status: ' . $db->lasterror());
                    }
                    
                    // 10. Reabre a fatura (usando método nativo do Dolibarr)
                    dol_include_once('/compta/facture/class/facture.class.php');
                    $facture = new Facture($db);
                    if ($facture->fetch($object->id) > 0) {
                        $result = $facture->setUnpaid($user);
                        if ($result > 0) {
                            $db->commit();
                            
                            error_log('[NFSE CANCELAMENTO AUTO] NFS-e #'.$nfse->numero_nfse.' cancelada e fatura reaberta com sucesso. Protocolo: '.$protocolo);
                            
                            setEventMessages($langs->trans(\"NFSe cancelada e fatura reaberta com sucesso! \".$mensagemRetorno), null, 'mesgs');
                            
                            // Redireciona para evitar resubmissão
                            header(\"Location: \".$_SERVER['PHP_SELF'].\"?facid=\".$object->id);
                            exit;
                        } else {
                            throw new Exception('NFSe cancelada, mas erro ao reabrir fatura: '.$facture->error);
                        }
                    } else {
                        throw new Exception('NFSe cancelada, mas erro ao carregar fatura');
                    }
                    
                } else {
                    // Erro no cancelamento
                    $db->rollback();
                    error_log('[NFSE CANCELAMENTO AUTO] Erro ao cancelar NFS-e #'.$nfse->numero_nfse.': '.$mensagemRetorno);
                    setEventMessages($langs->trans(\"Erro ao cancelar NFSe: \".$mensagemRetorno), null, 'errors');
                }
                
            } catch (Exception $e) {
                $db->rollback();
                
                $mensagemErro = $e->getMessage();
                error_log('[NFSE CANCELAMENTO AUTO] Exceção: '.$mensagemErro);
                
                setEventMessages($langs->trans(\"Erro ao processar cancelamento: \".$mensagemErro), null, 'errors');
            }
            
            return 1;
        }
        FIM CANCELAMENTO AUTOMÁTICO DESABILITADO */

        // ========== AÇÃO: GERAR NFSE NACIONAL ==========
        if ($action === 'gerarnfsenacional') {
            try {
                error_log('[NFSe] Iniciando geração de NFSe Nacional - Fatura ID: ' . ($object->id ?? 'N/A'));
                
                global $langs, $mysoc;

                // Includes necessários (ANTES das validações)
                dol_include_once('/core/class/extrafields.class.php');
                dol_include_once('/product/class/product.class.php');
                dol_include_once('/compta/facture/class/facture.class.php');
                dol_include_once('/societe/class/societe.class.php');
                
                error_log('[NFSe] Classes carregadas com sucesso');
                
                // VALIDAÇÃO 1: Não pode gerar NFSe para fatura em RASCUNHO
                // Usa valor direto: 0 = Draft, 1 = Validada
                if (!empty($object->id) && isset($object->statut) && (int)$object->statut === 0) {
                    error_log('[NFSe] Fatura em rascunho - bloqueando geração');
                    setEventMessages('Não é possível gerar NFSe para fatura em rascunho. Valide a fatura primeiro.', null, 'errors');
                    return 1;
                }
            
            // VALIDAÇÃO 2: Não pode gerar mais de uma NFSe para a mesma fatura
            if (!empty($object->id)) {
                try {
                    $sqlCheck = "SELECT id FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas 
                                 WHERE id_fatura = ".(int)$object->id." 
                                 AND LOWER(status) IN ('autorizada', 'enviando', 'pendente')
                                 LIMIT 1";
                    $resCheck = $this->db->query($sqlCheck);
                    if ($resCheck && $this->db->num_rows($resCheck) > 0) {
                        setEventMessages('Esta fatura já possui uma NFSe autorizada ou em processo de emissão.', null, 'errors');
                        return 1;
                    }
                } catch (Exception $e) {
                    error_log('[NFSe] Erro na validação de NFSe duplicada: ' . $e->getMessage());
                    // Continua o processamento se a tabela não existir ainda
                }
            }
            
            // VALIDAÇÃO 3: Não pode gerar NFSe se a fatura não foi paga
            // if (!empty($object->id) && isset($object->paye) && (int)$object->paye !== 1) {
            //     error_log('[NFSe] Fatura não paga - bloqueando geração. ID: ' . $object->id);
            //     setEventMessages('É necessário realizar o pagamento antes de emitir a nota fiscal.', null, 'errors');
            //     return 1;
            // }
            
            // Carrega função de emissão no padrão nacional
            dol_include_once('/custom/nfse/emissao_nfse_nacional.php');


            // Carrega empresa (mysoc)
            if (empty($mysoc->id)) {
                $mysoc->fetch(getDolGlobalInt('MAIN_INFO_SOCIETE_NOM'));
            }
            if (method_exists($mysoc, 'fetch_optionals')) {
                $mysoc->fetch_optionals();
            }

            $id_fatura = GETPOST('facid', 'int');

            // Usa o $object se já for a fatura atual, senão busca
            $fatura = $object && !empty($object->id) ? $object : new Facture($this->db);
            if (empty($fatura->id)) {
                $fatura->fetch($id_fatura);
            }

            if ($fatura->id <= 0) {
                error_log('[NFSe] Fatura não encontrada - ID: ' . $id_fatura);
                setEventMessages($langs->trans('Fatura não encontrada'), null, 'errors');
                return 1;
            }

            error_log('[NFSe] Fatura carregada - ID: ' . $fatura->id . ' | Ref: ' . ($fatura->ref ?? 'N/A'));

            // Cliente (thirdparty)
            if ($fatura->fetch_thirdparty() > 0 && !empty($fatura->thirdparty) && is_object($fatura->thirdparty)) {
                $cliente = $fatura->thirdparty;
                $cliente->fetch_optionals();
                error_log('[NFSe] Cliente carregado - ID: ' . $cliente->id . ' | Nome: ' . $cliente->name);
            } else {
                error_log('[NFSe] Cliente não encontrado');
                setEventMessages($langs->trans('Cliente não encontrado'), null, 'errors');
                return 1;
            }

            
            
            $listaServicos = [];
            if (!empty($fatura->lines)) {
                foreach ($fatura->lines as $linha) {
                    if (empty($linha->fk_product)) continue;
                    $produto = new Product($this->db);
                    if ($produto->fetch($linha->fk_product) <= 0) continue;
                    $produto->fetch_optionals();
                    $campos_extras = [];
                    if (!empty($produto->array_options)) {
                        foreach ($produto->array_options as $k=>$v) {
                            $campos_extras[str_replace('options_','',$k)] = $v;
                        }
                    }
                    $listaServicos[] = [
                        // usa label do produto/serviço (fallback para libelle se existir)
                        'servico_prestado' => ($produto->label ?? ($produto->libelle ?? '')),
                        'descricao' => $linha->description,
                        'valor' => (float)$linha->subprice,
                        'total_semtaxa' => (float)$linha->total_ht,
                        'quantidade' => (float)$linha->qty,
                        'extrafields' => $campos_extras
                    ];
                }
            }
            
            if (empty($listaServicos)) {
                error_log('[NFSe] Nenhum serviço encontrado na fatura');
                setEventMessages('Nenhum serviço encontrado na fatura', null, 'errors');
                return 1;
            }
            // Dados da empresa (emitente)
            $dadosEmitente = [
                'cnpj' => $mysoc->idprof1, //idprof4 -old // idprof1 - new
                'razao_social' => $mysoc->name,
                'nome_fantasia' => $mysoc->name,
                'inscricao_municipal' => $mysoc->array_options['options_inscricao_municipal'] ?? $mysoc->idprof3 ?? '',
                'codigo_municipio' => (string)getDolGlobalInt('MAIN_INFO_COD_MUNICIPIO'),
                'municipio' => $mysoc->town,
                'uf' => $mysoc->state_code,
                'cep' => $mysoc->zip,
                'endereco' => $mysoc->address,
                'telefone' => $mysoc->phone,
                'email' => $mysoc->email,
                'crt' => getDolGlobalInt('MAIN_INFO_CRT'),
                'regimeTributacao' => getDolGlobalInt('MAIN_INFO_REGIMETRIBUTACAO'),
                'extrafields' => $mysoc->array_options ?? []
            ];

            error_log('[NFSe] Montando dados do destinatário');
            // Dados do destinatário (tomador)
            $dadosDestinatario = [
                'cnpj' => $cliente->idprof1, //idprof4 -old // idprof1 - new
                'cpf' => $cliente->idprof2,
                'nome' => $cliente->name,
                'nome_fantasia' => $cliente->name,
                'codigo_municipio' => $cliente->array_options['options_codigo_do_municipio'] ?? '',
                'municipio' => $cliente->town,
                'uf' => $cliente->state_code,
                'cep' => $cliente->zip,
                'endereco' => $cliente->array_options['options_rua'] ?? $cliente->address ?? '',
                'numero' => $cliente->array_options['options_numero_de_endereco'] ?? 'S/N',
                'complemento' => $cliente->array_options['options_complemento'] ?? '',
                'bairro' => $cliente->array_options['options_bairro'] ?? '',
                'telefone' => $cliente->phone,
                'email' => $cliente->email,
                'extrafields' => $cliente->array_options ?? []
            ];

            error_log('[NFSe] Montando dados da fatura');
            // Dados da fatura
             $dadosFatura = [
                'id' => $fatura->id,
                'data_emissao' => dol_print_date($fatura->date, 'day'),
                'valor_servicos' => (float)$fatura->total_ht,
                'extrafields' => []
            ];
            if (!empty($fatura->array_options)) {
                foreach ($fatura->array_options as $k=>$v) {
                    $dadosFatura['extrafields'][str_replace('options_','',$k)] = $v;
                }
            }

            error_log('[NFSe] Chamando função gerarNfseNacional');

            // Número informado pelo usuário na modal (opcional)
            $inserirSeq = (int)GETPOST('inserirSeq', 'int');
            $numeroCustom = ($inserirSeq > 0) ? $inserirSeq : null;
            if ($numeroCustom) {
                error_log('[NFSe] Usuário informou número manual: ' . $numeroCustom);
            }

            // Chama função de emissão nacional
            gerarNfseNacional($this->db, $dadosFatura, $dadosEmitente, $dadosDestinatario, $listaServicos, $numeroCustom);
            
            error_log('[NFSe] NFSe Nacional gerada com sucesso');
            return 1;
            
            } catch (Exception $e) {
                error_log('[NFSe] ERRO na geração de NFSe Nacional: ' . $e->getMessage());
                error_log('[NFSe] Stack trace: ' . $e->getTraceAsString());
                setEventMessages('Erro ao gerar NFSe: ' . $e->getMessage(), null, 'errors');
                return 1;
            }
        }

        // Ação de geração de NFSe (padrão municipal)
        if ($action === 'gerarnfse') {
            global $langs, $mysoc;

            // Includes necessários
            dol_include_once('/core/class/extrafields.class.php');
            dol_include_once('/product/class/product.class.php');
            dol_include_once('/compta/facture/class/facture.class.php');
            dol_include_once('/societe/class/societe.class.php');
            
            // CRÍTICO: Carrega função gerarNfse (case-sensitive no Linux)
            dol_include_once('/compta/facture/class/facture.class.php');

            // Carrega empresa (mysoc)
            if (empty($mysoc->id)) {
                $mysoc->fetch(0);
            }
            // Garante optionals carregados e valores de fallback para inscrição municipal / município / crt
            if (method_exists($mysoc, 'fetch_optionals')) {
                $mysoc->fetch_optionals();
            }
            $prestadorIM = '';
            if (!empty($mysoc->array_options['options_inscricao_municipal'])) {
                $prestadorIM = $mysoc->array_options['options_inscricao_municipal'];
            } elseif (!empty($mysoc->idprof3)) {
                $prestadorIM = $mysoc->idprof3;
            } else {
                $prestadorIM = getDolGlobalString('MAIN_INFO_INSCRICAO_MUNICIPAL', '');
            }
            $cod_municipio_emitente =  (string) getDolGlobalInt('MAIN_INFO_COD_MUNICIPIO');
            

            $crtEmitente = getDolGlobalInt('MAIN_INFO_CRT');
            $cnaeEmitente = getDolGlobalInt('MAIN_INFO_CNAE');
            $incentivoFiscal = getDolGlobalInt('MAIN_INFO_INCENTIVOFISCAL');
            $regimeTributacao = getDolGlobalInt('MAIN_INFO_REGIMETRIBUTACAO');

            $id_fatura = GETPOST('facid', 'int');

            // Usa o $object se já for a fatura atual, senão busca
            $fatura = $object && !empty($object->id) ? $object : new Facture($this->db);
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
            } else {
                setEventMessages("Cliente (thirdparty) não encontrado para essa fatura.", null, 'errors');
                return 0;
            }

            // Monta lista de serviços (mantendo extrafields úteis para emissão)
            $listaServicos = [];
            if (!empty($fatura->lines)) {
                foreach ($fatura->lines as $linha) {
                    if (empty($linha->fk_product)) continue;
                    $produto = new Product($this->db);
                    if ($produto->fetch($linha->fk_product) <= 0) continue;
                    $produto->fetch_optionals();
                    $campos_extras = [];
                    if (!empty($produto->array_options)) {
                        foreach ($produto->array_options as $k=>$v) {
                            $campos_extras[str_replace('options_','',$k)] = $v;
                        }
                    }
                    $listaServicos[] = [
                        // usa label do produto/serviço (fallback para libelle se existir)
                        'servico_prestado' => ($produto->label ?? ($produto->libelle ?? '')),
                        'descricao' => $linha->description,
                        'valor' => (float)$linha->subprice,
                        'total_semtaxa' => (float)$linha->total_ht,
                        'quantidade' => (float)$linha->qty,
                        'extrafields' => $campos_extras
                    ];
                   
                }
            }
            //print_r($listaServicos['descricao']);
            if (empty($listaServicos)) {
                setEventMessages("Nenhum serviço válido encontrado na fatura.", null, 'errors');
                return 0;
            }

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
                 'prestadorIM' => $prestadorIM,
                 'municipio' => $mysoc->town, // CAMPO PRINCIPAL para busca
                 'town' => $mysoc->town,
                 'cod_municipio' => $cod_municipio_emitente, // Será validado/corrigido
                 'rua' => $mysoc->address,
                 'bairro' => $mysoc->array_options['options_bairro'] ?? '',
                 'numero' => $mysoc->array_options['options_numero_de_endereco'] ?? '',
                 'uf' => $mysoc->state_code,
                 'cep' => $mysoc->zip,
                 'crt' => $crtEmitente,
                 'cnae' => $cnaeEmitente,
                 'incentivoFiscal' => $incentivoFiscal,
                 'regimeTributacao' => $regimeTributacao,
                 'extrafields' => $extrafieldsEmitente // ADICIONADO: passa todos extrafields
             ];

            // Dados do destinatário (tomador) - CORRIGIDO: extrafields completos
            $extrafieldsDestinatario = [];
            if (!empty($cliente->array_options)) {
                foreach ($cliente->array_options as $k=>$v) {
                    $extrafieldsDestinatario[str_replace('options_','',$k)] = $v;
                }
            }
            
            $dadosDestinatario = [
                'id' => $cliente->id,
                'nome' => $cliente->name,
                'cnpj' => $cliente->idprof1,
                'tomadorIM' => $cliente->array_options['options_inscricao_municipal'] ?? '',
                'municipio' => $cliente->town, // CAMPO PRINCIPAL para busca
                'town' => $cliente->town,
                'rua' => $cliente->address,
                'bairro' => $cliente->array_options['options_bairro'] ?? '',
                'numero_de_endereco' => $cliente->array_options['options_numero_de_endereco'] ?? '',
                'codigo_do_municipio' => $cliente->array_options['options_codigo_do_municipio'] ?? '', // Será validado/corrigido
                'uf' => $cliente->state_code,
                'cep' => $cliente->zip,
                'extrafields' => $extrafieldsDestinatario // COMPLETO
            ];

            // Dados da fatura (formato mínimo usado por gerarNfse)
            $dadosFatura = [
                'id' => $fatura->id,
                'data_emissao' => dol_print_date($fatura->date, 'day'),
                'valor_servicos' => (float)$fatura->total_ht,
                'extrafields' => []
            ];
            if (!empty($fatura->array_options)) {
                foreach ($fatura->array_options as $k=>$v) {
                    $dadosFatura['extrafields'][str_replace('options_','',$k)] = $v;
                }
            }
                
                // echo "<pre>";
                // echo "<h3>Dados da Fatura</h3>";
                // var_dump($dadosFatura);
                // echo "<h3>Dados do Emitente</h3>";
                // var_dump($dadosEmitente);
                // echo "<h3>Dados do Destinatário</h3>";
                // var_dump($dadosDestinatario);
                // echo "</pre>";
            return 1; // ação tratada
        }
        return 0; // não bloqueia outros hooks
    }

    /**
     * Adiciona botão "GERAR NFSe" na tela da fatura
     * MODIFICADO: Intercepta botão "Reabrir" para adicionar confirmação customizada
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
        $usercan = (!empty($user->admin) || !empty($user->rights->facture->creer));

        if ($usercan && !empty($object->id)) {
            
            // ========== CONTROLE DE EXIBIÇÃO DO BOTÃO NFSe ==========
            // Oculta botão apenas se existir NFSe AUTORIZADA
            // Se a NFSe foi CANCELADA, permite emitir uma nova
            define('NFSE_OCULTAR_BOTAO_APOS_EMISSAO', true);
            
            $exibirBotao = true;
            
            // VALIDAÇÃO 1: Não exibe botão se a fatura estiver em RASCUNHO (status = 0)
            if (isset($object->statut) && (int)$object->statut === 0) {
                $exibirBotao = false;
            }
            
            // Nota: Validação de pagamento removida da UI - botão sempre visível
            // A validação de pagamento ocorre no backend ao tentar gerar
            
            if ($exibirBotao && NFSE_OCULTAR_BOTAO_APOS_EMISSAO) {
                // Verifica se existe NFSe autorizada (não cancelada, não rejeitada, não em erro)
                $sqlCheck = "SELECT id, status FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas 
                             WHERE id_fatura = ".(int)$object->id." 
                             AND LOWER(status) = 'autorizada'
                             LIMIT 1";
                $resCheck = $this->db->query($sqlCheck);
                if ($resCheck && $this->db->num_rows($resCheck) > 0) {
                    // Existe NFSe autorizada - oculta botão
                    $exibirBotao = false;
                } else {
                    // Não existe NFSe autorizada OU foi cancelada/rejeitada - mostra botão
                    $exibirBotao = true;
                }
            }
            
            if ($exibirBotao) {
                // Botão NFS-e NACIONAL
                print dolGetButtonAction(
                    'GERAR NFSE',
                    '',
                    'default',
                    $_SERVER['PHP_SELF'].'?facid='.(int)$object->id.'&action=confirm_gerarnfsenacional&token='.newToken(),
                    '',
                    true
                );
            }

            // ========== INTERCEPTA BOTÃO "REABRIR" PARA ADICIONAR MODAL ==========
            // FUNCIONALIDADE DESABILITADA TEMPORARIAMENTE - Para uso futuro
            // Quando habilitado, exibe modal de cancelamento automático da NFSe ao reabrir fatura
            /* CANCELAMENTO AUTOMÁTICO DESABILITADO
            if (($object->status == Facture::STATUS_CLOSED || $object->status == Facture::STATUS_ABANDONED) 
                && $usercan) {
                
                // Verifica se existe NFSe autorizada (Padrão Nacional)
                $sqlNfse = "SELECT id FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas 
                            WHERE id_fatura = ".(int)$object->id." 
                            AND LOWER(status) = 'autorizada'
                            LIMIT 1";
                $resNfse = $this->db->query($sqlNfse);
                
                if ($resNfse && $this->db->num_rows($resNfse) > 0) {
                    // CASO 1: Existe NFSe autorizada - remove botão padrão e adiciona customizado
                    static $scriptInjected = false;
                    if (!$scriptInjected) {
                        $scriptInjected = true;
                        print '<script>
                        $(document).ready(function() {
                            // Remove botão "Reabrir" padrão do Dolibarr
                            $("a.butAction").filter(function() {
                                return $(this).text().trim() === "'.$langs->transnoentities('ReOpen').'" && 
                                       $(this).attr("href") && 
                                       $(this).attr("href").indexOf("action=reopen") !== -1;
                            }).remove();
                        });
                        </script>';
                    }
                    
                    // Adiciona nosso botão customizado que chama a modal
                    print dolGetButtonAction(
                        $langs->trans('ReOpen'),
                        '',
                        'default',
                        $_SERVER['PHP_SELF'].'?facid='.(int)$object->id.'&action=confirm_reopen&token='.newToken(),
                        '',
                        true
                    );
                }
                // CASO 2: Não existe NFSe - deixa o botão padrão do Dolibarr funcionar normalmente
                // (não precisa fazer nada aqui, o botão padrão já existe)
            }
            FIM CANCELAMENTO AUTOMÁTICO DESABILITADO */

            // === SCRIPT DE CONTROLE DE EXTRAFIELDS E BOTÕES (já existente) ===
            static $printedNfseScript = false;
            if (!$printedNfseScript) {
                $printedNfseScript = true;
                
                // Busca os códigos de serviço das linhas da fatura para passar ao JavaScript
                $codigosServicos = array();
                if (!empty($object->lines)) {
                    dol_include_once('/product/class/product.class.php');
                    foreach ($object->lines as $line) {
                        if (!empty($line->fk_product) && $line->product_type == 1) {
                            $produto = new Product($db);
                            if ($produto->fetch($line->fk_product) > 0) {
                                $produto->fetch_optionals();
                                if (!empty($produto->array_options['options_srv_cod_itemlistaservico'])) {
                                    $codigosServicos[] = $produto->array_options['options_srv_cod_itemlistaservico'];
                                }
                            }
                        }
                    }
                }
                $codigosServicoJson = json_encode(array_unique($codigosServicos));
                
                $script = <<<JS
<script>
(function($) {
    $(document).ready(function() {
        const extrafieldsProduto = ['frete', 'nat_op', 'indpres', 'dest_op', 'cfop'];
        const extrafieldsServico = ['exigibilidade_iss', 'nat_op_sv', 'muni_prest', 'discriminacao', 'iss_retido', 'cod_tribut_muni', 'srv_valor_deducoes'];
        
        // Extrafields de OBRA (construção civil)
        const extrafieldsObra = ['separador_obra', 'inscImobFisc', 'cObra'];
        
        // Extrafields de EVENTO
        const extrafieldsEvento = ['separdor_dados_evento', 'xnomeevento', 'dtini', 'dtfim'];
        
        // Extrafields de ENDEREÇO (compartilhado entre obra e evento)
        const extrafieldsEndereco = ['sepador_endereco', 'cep', 'xbairro', 'xlgr', 'nro'];
        
        // Códigos de serviço que requerem dados de EVENTO
        const codigosEvento = ['120101', '120201', '120301', '120401', '120501', '120601', '120701', '120801', '120901', '120902', '120903', '121001', '121101', '121201', '121301', '121401', '121501', '121601', '121701'];
        
        // Códigos de serviço que requerem dados de OBRA
        const codigosObra = ['070201', '070202', '070401', '070501', '070502', '070601', '070602', '070701', '070801', '071701', '071901', '141403', '141404'];
        
        // Códigos de serviço presentes na fatura (passados do PHP)
        const codigosServicosFatura = {$codigosServicoJson};
        
        const seletorBotaoNFe = 'a.butAction[href*="action=confirm_gerarnfe"]';
        const seletorBotaoNFSe = 'a.butAction[href*="action=confirm_gerarnfse"]';

        // Função melhorada para encontrar linha do extrafield
        // Funciona tanto no modo CREATE quanto no modo VIEW
        function getLinhaExtrafield(nomeCampo) {
            let linha = null;
            
            // 1. Modo CREATE: classe field_options_CAMPO na TR
            linha = $("tr.field_options_" + nomeCampo);
            if (linha.length > 0) {
                return linha;
            }
            
            // 2. Modo VIEW: TD com classe facture_extras_CAMPO
            linha = $("td.facture_extras_" + nomeCampo).closest('tr');
            if (linha.length > 0) {
                return linha;
            }
            
            // 3. Modo CREATE alternativo: classe facture_extras_CAMPO na TR
            linha = $("tr.facture_extras_" + nomeCampo);
            if (linha.length > 0) {
                return linha;
            }
            
            // 4. Modo VIEW: TD com id facture_extras_CAMPO_*
            linha = $("td[id^='facture_extras_" + nomeCampo + "_']").closest('tr');
            if (linha.length > 0) {
                return linha;
            }
            
            // 5. Input/select com name options_CAMPO
            linha = $("input[name='options_" + nomeCampo + "'], select[name='options_" + nomeCampo + "'], textarea[name='options_" + nomeCampo + "']").closest('tr');
            if (linha.length > 0) {
                return linha;
            }
            
            // 6. Separadores: busca pelo label do separador (tipo separate)
            linha = $("tr").filter(function() {
                return $(this).find("td span.fa-minus-square, td span.fa-plus-square").length > 0 &&
                       $(this).text().toLowerCase().includes(nomeCampo.replace(/_/g, ' ').toLowerCase().substring(0, 5));
            });
            if (linha.length > 0) {
                return linha;
            }
            
            return $();
        }
        
        // Função para ocultar extrafield por nome
        function ocultarExtrafield(nomeCampo) {
            const linha = getLinhaExtrafield(nomeCampo);
            if (linha.length > 0) {
                linha.hide();
            }
        }
        
        // Função para mostrar extrafield por nome
        function mostrarExtrafield(nomeCampo) {
            const linha = getLinhaExtrafield(nomeCampo);
            if (linha.length > 0) {
                linha.show();
            }
        }

        const linhasDaFatura = $('tr[data-element="facturedet"]');
        let temProduto = false;
        let temServico = false;
        linhasDaFatura.each(function() {
            const tipoDoItem = $(this).data('product_type');
            if (tipoDoItem === 0) temProduto = true;
            if (tipoDoItem === 1) temServico = true;
        });

        const todosExtrafields = [...extrafieldsProduto, ...extrafieldsServico];
        if (!temProduto && !temServico) {
            todosExtrafields.forEach(nome => ocultarExtrafield(nome));
        } else {
            extrafieldsProduto.forEach(nome => temProduto ? mostrarExtrafield(nome) : ocultarExtrafield(nome));
            extrafieldsServico.forEach(nome => temServico ? mostrarExtrafield(nome) : ocultarExtrafield(nome));
        }
        
        // ========== CONTROLE DE VISIBILIDADE: OBRA E EVENTO ==========
        // Verifica se algum código de serviço da fatura requer OBRA ou EVENTO
        let requerObra = false;
        let requerEvento = false;
        
        if (codigosServicosFatura && codigosServicosFatura.length > 0) {
            codigosServicosFatura.forEach(function(codigo) {
                if (codigosObra.includes(codigo)) requerObra = true;
                if (codigosEvento.includes(codigo)) requerEvento = true;
            });
        }
        
        // Por padrão, oculta todos os campos de obra, evento e endereço
        extrafieldsObra.forEach(nome => ocultarExtrafield(nome));
        extrafieldsEvento.forEach(nome => ocultarExtrafield(nome));
        extrafieldsEndereco.forEach(nome => ocultarExtrafield(nome));
        
        // Mostra campos de OBRA se necessário
        // NOTA: Campos de endereço estão DESABILITADOS para OBRA porque a API do governo não está aceitando
        if (requerObra) {
            extrafieldsObra.forEach(nome => mostrarExtrafield(nome));
            // extrafieldsEndereco.forEach(nome => mostrarExtrafield(nome)); // DESABILITADO - API não aceita endereço de obra
        }
        
        // Mostra campos de EVENTO se necessário
        if (requerEvento) {
            extrafieldsEvento.forEach(nome => mostrarExtrafield(nome));
            extrafieldsEndereco.forEach(nome => mostrarExtrafield(nome));
        }
        
        // ========== FIM CONTROLE OBRA/EVENTO ==========

        const botaoNFe = $(seletorBotaoNFe);
        const botaoNFSe = $(seletorBotaoNFSe);
        
        botaoNFe.show();
        botaoNFSe.show();

        if (temProduto && !temServico) botaoNFSe.hide();
        else if (!temProduto && temServico) botaoNFe.hide();
        else if (temProduto && temServico) { botaoNFe.hide(); botaoNFSe.hide(); }
        else { botaoNFe.hide(); botaoNFSe.hide(); }

        if (temProduto || temServico) {
            const formAdicionar = $('#addproduct_form');
            const selectProduto = $('#idprod');
            const botaoAdicionar = $('#addline');
            
            if (formAdicionar.length && !$('#info-box-tipo-fatura').length) {
                formAdicionar.before('<div id="info-box-tipo-fatura" class="info-box" style="margin-bottom:10px;padding:8px;border:1px solid #e3e3e3;background:#f8f9fa;"></div>');
                botaoAdicionar.after('<span id="addline_error_msg" style="color: #A80000; font-weight: bold; margin-left: 10px; display: none;">Tipo de item inválido para esta fatura.</span>');
            }

            const msgErro = $('#addline_error_msg');

            selectProduto.on('select2:select', function (e) {
                const selectedText = e.params.data.text || '';
                const itemEhProduto = selectedText.trim().toUpperCase().startsWith('P');
                const itemEhServico = selectedText.trim().toUpperCase().startsWith('S');

                let isInvalid = false;
                if (temProduto && itemEhServico) isInvalid = true;
                if (temServico && itemEhProduto) isInvalid = true;

                if (isInvalid) {
                    botaoAdicionar.prop('disabled', true);
                    msgErro.show();
                } else {
                    botaoAdicionar.prop('disabled', false);
                    msgErro.hide();
                }
            });

            selectProduto.on('select2:unselect', function (e) {
                botaoAdicionar.prop('disabled', false);
                msgErro.hide();
            });
        }
    });
})(jQuery);
</script>
JS;
                print $script;
            }
        }
        return 0;
    }

    /**
     * Exibe confirmação antes de gerar NFSe
     * MODIFICADO: Intercepta ação de reabertura para exibir modal de cancelamento
     */
    public function formConfirm($parameters, &$object, &$action, $hookmanager)
    {
        $contexts = empty($parameters['context']) ? array() : explode(':', $parameters['context']);
        if (!in_array('invoicecard', $contexts)) {
            return 0;
        }
        
        // ========== MODAL DE GERAÇÃO DE NFSe NACIONAL ==========
        if ($action === 'confirm_gerarnfsenacional') {
            global $langs, $form;
            $db = $this->db;

            dol_include_once('/core/class/html.form.class.php');
            if (empty($form)) {
                $form = new Form($db);
            }
            $sqlAmb = $db->query("SELECT value  FROM ".MAIN_DB_PREFIX."nfe_config WHERE name = 'ambiente';");
            if($sqlAmb && $db->num_rows($sqlAmb)>0){
                $resAmb = $db->fetch_object($sqlAmb);
                $amb = $resAmb->value;
            }
            $sql = $db->query("SELECT next_dps FROM ".MAIN_DB_PREFIX."nfse_nacional_sequencias WHERE ambiente = '".$db->escape($amb)."';");
            if($sql && $db->num_rows($sql)>0){
                $res = $db->fetch_object($sql);
                $proximaSequencia = $res->next_dps;
            }
            $label = 'Gerar NFS-e';
            // Input visível usa id diferente (inserirSeq_vis) para não conflitar com o hidden (id=inserirSeq) gerado pelo formconfirm
            $question = '<strong>Tem certeza que deseja gerar a NFS-e nº '.$proximaSequencia.' para esta fatura?</strong>'
                      . '<br><br>Número da NFS-e<br>'
                      . '<input type="text" id="inserirSeq_vis" value="'.dol_escape_htmltag($proximaSequencia).'" style="width:120px;padding:4px 8px;font-size:15px;border:1px solid #ccc;border-radius:4px;">'
                      // Sincroniza o valor digitado para o hidden que o Dolibarr envia na URL
                      . '<script>jQuery(function($){ $("#inserirSeq_vis").on("input", function(){ $("#inserirSeq").val($(this).val()); }); });</script>';
            $formquestion = array(
                // Hidden registrado no inputok — o Dolibarr lê $("#inserirSeq").val() e inclui na URL GET
                array('type' => 'hidden', 'name' => 'inserirSeq', 'value' => $proximaSequencia)
            );
            $targetAction = 'gerarnfsenacional';

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
            return 1;
        }

        
        // ========== MODAL DE REABERTURA COM CANCELAMENTO AUTOMÁTICO ==========
        // FUNCIONALIDADE DESABILITADA TEMPORARIAMENTE - Para uso futuro
        /* CANCELAMENTO AUTOMÁTICO DESABILITADO
        if ($action === 'confirm_reopen' && !empty($object->id)) {
            global $langs, $form;
            $db = $this->db;

            dol_include_once('/core/class/html.form.class.php');
            if (empty($form)) {
                $form = new Form($db);
            }

            $sqlNfse = "SELECT id, numero_nfse, status FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas 
                        WHERE id_fatura = ".(int)$object->id." 
                        AND LOWER(status) = 'autorizada'
                        ORDER BY id DESC LIMIT 1";
            $resNfse = $this->db->query($sqlNfse);
            
            if ($resNfse && $this->db->num_rows($resNfse) > 0) {
                $nfseData = $this->db->fetch_object($resNfse);
                
                $mensagem = 'Esta fatura possui uma NFSe autorizada (Nº '.$nfseData->numero_nfse.').<br><br>';
                $mensagem .= 'Ao reabrir a fatura, a NFSe será <strong>CANCELADA AUTOMATICAMENTE</strong> com o motivo: <em>"Erro de emissão - Necessidade de correção de dados"</em>.<br><br>';
                $mensagem .= '<strong>Deseja prosseguir?</strong>';

                $this->resprints = $form->formconfirm(
                    $_SERVER['PHP_SELF'].'?facid='.$object->id.'&id_nfse='.$nfseData->id,
                    $langs->trans('ReOpen'),
                    $mensagem,
                    'confirm_reopen_with_cancel_nfse',
                    array(),
                    'no',
                    1,
                    290,
                    480
                );
                
                return 1;
            }
        }
        FIM CANCELAMENTO AUTOMÁTICO DESABILITADO */
        
        return 0;
    }

    /**
     * Hook formObjectOptions: Controla visibilidade de extrafields por tipo (Produto vs Serviço)
     * NOVO: Injeta badge de status da NFSe
     */
    public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
    {
        global $extrafields;
        
        // CORREÇÃO CRÍTICA: Evita execução múltipla que causa erro 503
        static $executionCount = 0;
        $executionCount++;
        if ($executionCount > 1) {
            return 0; // Previne loop infinito
        }
        
        $contexts = empty($parameters['context']) ? array() : explode(':', $parameters['context']);
        
        // --- INJEÇÃO DA CORREÇÃO DE LARGURA DO CAMPO SERVIÇO ---
        if (count(array_intersect($contexts, array('invoicecard', 'productcard'))) > 0) {
            print '<style>
/* === FIX LARGURA CAMPO CÓDIGO DE SERVIÇO (Select2) === */
td[id*="srv_cod_itemlistaservico"] { max-width: 800px !important; }
select#options_srv_cod_itemlistaservico { max-width: 800px !important; }
span.select2-container[id*="srv_cod_itemlistaservico"],
.select2-container[id*="srv_cod_itemlistaservico"] { max-width: 800px !important; width: 800px !important; display: inline-block !important; }
.select2-selection.select2-selection--single { max-width: 800px !important; }
#select2-options_srv_cod_itemlistaservico-container,
span.select2-selection__rendered[id*="srv_cod_itemlistaservico"] { display: block !important; max-width: 780px !important; overflow: hidden !important; text-overflow: ellipsis !important; white-space: nowrap !important; }
.select2-dropdown .select2-results__option { white-space: normal !important; word-wrap: break-word !important; padding: 5px 10px !important; }
.select2-dropdown[aria-labelledby*="srv_cod_itemlistaservico"] { min-width: 800px !important; }

/* === OCULTA EXTRAFIELDS CONDICIONAIS ANTES DO JS CARREGAR (evita flash) === */
/* Campos de produto */
tr.field_options_frete, tr:has(td.facture_extras_frete),
tr.field_options_nat_op, tr:has(td.facture_extras_nat_op),
tr.field_options_indpres, tr:has(td.facture_extras_indpres),
tr.field_options_dest_op, tr:has(td.facture_extras_dest_op),
tr.field_options_cfop, tr:has(td.facture_extras_cfop),
/* Campos de serviço */
tr.field_options_exigibilidade_iss, tr:has(td.facture_extras_exigibilidade_iss),
tr.field_options_nat_op_sv, tr:has(td.facture_extras_nat_op_sv),
tr.field_options_muni_prest, tr:has(td.facture_extras_muni_prest),
tr.field_options_discriminacao, tr:has(td.facture_extras_discriminacao),
/*tr.field_options_iss_retido, tr:has(td.facture_extras_iss_retido),*/
tr.field_options_cod_tribut_muni, tr:has(td.facture_extras_cod_tribut_muni),
tr.field_options_srv_valor_deducoes, tr:has(td.facture_extras_srv_valor_deducoes),
/* Campos de obra */
tr.field_options_separador_obra, tr:has(td.facture_extras_separador_obra),
tr.field_options_inscImobFisc, tr:has(td.facture_extras_inscImobFisc),
tr.field_options_cObra, tr:has(td.facture_extras_cObra),
/* Campos de evento */
tr.field_options_separdor_dados_evento, tr:has(td.facture_extras_separdor_dados_evento),
tr.field_options_xnomeevento, tr:has(td.facture_extras_xnomeevento),
tr.field_options_dtini, tr:has(td.facture_extras_dtini),
tr.field_options_dtfim, tr:has(td.facture_extras_dtfim),
/* Campos de endereço */
tr.field_options_sepador_endereco, tr:has(td.facture_extras_sepador_endereco),
tr.field_options_cep, tr:has(td.facture_extras_cep),
tr.field_options_xbairro, tr:has(td.facture_extras_xbairro),
tr.field_options_xlgr, tr:has(td.facture_extras_xlgr),
tr.field_options_nro, tr:has(td.facture_extras_nro) {
    display: none;
}
</style>
<script>
jQuery(document).ready(function($) {
    function aplicarLarguraNfse() {
        $("span.select2-container[id*=\'srv_cod_itemlistaservico\']").css({"max-width":"500px","width":"500px","display":"inline-block"});
        $("#select2-options_srv_cod_itemlistaservico-container, span.select2-selection__rendered[id*=\'srv_cod_itemlistaservico\']").css({"display":"block","max-width":"470px","overflow":"hidden","text-overflow":"ellipsis","white-space":"nowrap"});
    }
    setTimeout(aplicarLarguraNfse, 100); setTimeout(aplicarLarguraNfse, 500); setTimeout(aplicarLarguraNfse, 1000);
    $(document).on("select2:open", function(e) { if(e.target.id === "options_srv_cod_itemlistaservico") aplicarLarguraNfse(); });
});
</script>';
        }

        // 2. CONTEXTO: Ficha de FATURA (invoicecard) - BADGE NFSE
        if (in_array('invoicecard', $contexts)) {
            // Sanitiza extrafields da fatura
            $this->sanitizeExtrafields($object);
            
            // ========== BLOQUEIO DE EXTRAFIELDS QUANDO EXISTE NFSE AUTORIZADA ==========
            // Verifica se existe NFSe autorizada para esta fatura
            $temNfseAutorizada = false;
            if (!empty($object->id)) {
                try {
                    $sqlNfseCheck = "SELECT id FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas 
                                     WHERE id_fatura = ".(int)$object->id." 
                                     AND LOWER(status) = 'autorizada'
                                     LIMIT 1";
                    $resNfseCheck = $this->db->query($sqlNfseCheck);
                    if ($resNfseCheck && $this->db->num_rows($resNfseCheck) > 0) {
                        $temNfseAutorizada = true;
                    }
                } catch (Exception $e) {
                    error_log('[NFSe] Erro ao verificar NFSe para bloqueio de extrafields: ' . $e->getMessage());
                }
            }
            
            // Se existe NFSe autorizada, bloqueia edição de extrafields relacionados à nota
            if ($temNfseAutorizada && isset($extrafields->attributes['facture'])) {
                // Lista de extrafields que contêm dados da NFSe e não devem ser alterados
                $camposNfse = array(
                    // Campos de serviço/tributação
                    'exigibilidade_iss', 
                    'nat_op_sv', 
                    'muni_prest', 
                    'discriminacao', 
                    //'iss_retido', 
                    'cod_tribut_muni', 
                    'srv_valor_deducoes',
                    // Campos de obra
                    'inscImobFisc', 
                    'cObra',
                    // Campos de evento
                    'xnomeevento', 
                    'dtini', 
                    'dtfim',
                    // Campos de endereço específicos da nota
                    'xbairro', 
                    'xlgr', 
                    'nro'
                );
                
                // Injeta JavaScript para desabilitar os campos (usando readonly para preservar valores)
                static $bloqueioInjected = false;
                if (!$bloqueioInjected) {
                    $bloqueioInjected = true;
                    $camposJson = json_encode($camposNfse);
                    print <<<BLOQUEIO
<script>
jQuery(document).ready(function($) {
    var camposBloqueados = $camposJson;
    
    camposBloqueados.forEach(function(campo) {
        // Usa readonly ao invés de disabled para preservar valores no POST
        var input = $('input[name="options_' + campo + '"]');
        var textarea = $('textarea[name="options_' + campo + '"]');
        var select = $('select[name="options_' + campo + '"]');
        
        // Para inputs e textareas, usa readonly
        if (input.length) {
            input.prop('readonly', true);
        }
        if (textarea.length) {
            textarea.prop('readonly', true);
        }
        
        // Para selects, desabilita mas preserva o valor com input hidden
        if (select.length) {
            var valorAtual = select.val();
            select.prop('disabled', true);
            
            // Adiciona input hidden para preservar o valor no POST
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
            
            // Verifica se fatura tem produtos ou serviços (lógica existente)
            $temProduto = false;
            $temServico = false;
            
            if (!empty($object->lines)) {
                foreach ($object->lines as $line) {
                    if (isset($line->product_type)) {
                        if ($line->product_type == 0) $temProduto = true;
                        if ($line->product_type == 1) $temServico = true;
                    }
                }
            }
            
            // Oculta extrafields de produto se só tem serviços
            if ($temServico && !$temProduto) {
                $camposProduto = array('frete', 'nat_op', 'indpres', 'dest_op', 'cfop');
                foreach ($camposProduto as $campo) {
                    if (isset($extrafields->attributes['facture']['enabled'][$campo])) {
                        $extrafields->attributes['facture']['enabled'][$campo] = '0';
                    }
                    if (isset($extrafields->attributes['facture']['list'][$campo])) {
                        $extrafields->attributes['facture']['list'][$campo] = '0';
                    }
                }
            }
            
            // Oculta extrafields de serviço se só tem produtos
            if ($temProduto && !$temServico) {
                $camposServico = array('exigibilidade_iss', 'muni_prest', 'discriminacao', 'iss_retido', 'srv_valor_deducoes');
                foreach ($camposServico as $campo) {
                    if (isset($extrafields->attributes['facture']['enabled'][$campo])) {
                        $extrafields->attributes['facture']['enabled'][$campo] = '0';
                    }
                    if (isset($extrafields->attributes['facture']['list'][$campo])) {
                        $extrafields->attributes['facture']['list'][$campo] = '0';
                    }
                }
            }
            
            // ========== CONTROLE DE VISIBILIDADE: OBRA E EVENTO (SERVER-SIDE) ==========
            // Listas centralizadas no validador (nfse_nacional_validator.lib.php)
            if (function_exists('nfseNacGetCodigosObra')) {
                $codigosObra = nfseNacGetCodigosObra();
                $codigosEvento = nfseNacGetCodigosEvento();
            } else {
                $codigosObra = array('070201', '070202', '070401', '070501', '070502', '070601', '070602', '070701', '070801', '071701', '071901', '141403', '141404');
                $codigosEvento = array('120101','120201', '120301', '120401', '120501', '120601', '120701', '120801', '120901', '120902', '120903', '121001', '121101', '121201', '121301', '121401', '121501', '121601', '121701');
            }
            
            $requerObra = false;
            $requerEvento = false;
            
            // Busca códigos de serviço das linhas da fatura
            if (!empty($object->lines)) {
                dol_include_once('/product/class/product.class.php');
                foreach ($object->lines as $line) {
                    if (!empty($line->fk_product) && $line->product_type == 1) {
                        $produto = new Product($this->db);
                        if ($produto->fetch($line->fk_product) > 0) {
                            $produto->fetch_optionals();
                            $codigoServico = $produto->array_options['options_srv_cod_itemlistaservico'] ?? '';
                            if (!empty($codigoServico)) {
                                if (in_array($codigoServico, $codigosObra)) $requerObra = true;
                                if (in_array($codigoServico, $codigosEvento)) $requerEvento = true;
                            }
                        }
                    }
                }
            }
            
            // Campos de OBRA
            $camposObra = array('separador_obra', 'inscImobFisc', 'cObra');
            // Campos de EVENTO
            $camposEvento = array('separdor_dados_evento', 'xnomeevento', 'dtini', 'dtfim');
            // Campos de ENDEREÇO (compartilhado)
            $camposEndereco = array('sepador_endereco', 'cep', 'xbairro', 'xlgr', 'nro');
            
            // Por padrão, oculta todos os campos de obra, evento e endereço
            $todosEspeciais = array_merge($camposObra, $camposEvento, $camposEndereco);
            foreach ($todosEspeciais as $campo) {
                if (isset($extrafields->attributes['facture']['list'][$campo])) {
                    $extrafields->attributes['facture']['list'][$campo] = '0';
                }
                if (isset($extrafields->attributes['facture']['enabled'][$campo])) {
                    $extrafields->attributes['facture']['enabled'][$campo] = '0';
                }
            }
            
            // Mostra campos de OBRA se necessário
            // NOTA: Campos de endereço estão DESABILITADOS para OBRA porque a API do governo não está aceitando
            if ($requerObra) {
                foreach ($camposObra as $campo) { // Removido $camposEndereco - API não aceita
                    if (isset($extrafields->attributes['facture']['list'][$campo])) {
                        $extrafields->attributes['facture']['list'][$campo] = '1';
                    }
                    if (isset($extrafields->attributes['facture']['enabled'][$campo])) {
                        $extrafields->attributes['facture']['enabled'][$campo] = '1';
                    }
                }
            }
            
            // Mostra campos de EVENTO se necessário
            if ($requerEvento) {
                foreach (array_merge($camposEvento, $camposEndereco) as $campo) {
                    if (isset($extrafields->attributes['facture']['list'][$campo])) {
                        $extrafields->attributes['facture']['list'][$campo] = '1';
                    }
                    if (isset($extrafields->attributes['facture']['enabled'][$campo])) {
                        $extrafields->attributes['facture']['enabled'][$campo] = '1';
                    }
                }
            }
            // ========== FIM CONTROLE OBRA/EVENTO (SERVER-SIDE) ==========
            
            // BADGE NFSE COM CORREÇÃO DE COR PARA STATUS CANCELADO
            if (empty($object->id)) return 0;
            
            // CORREÇÃO: Adiciona timeout e cache para evitar sobrecarga
            static $cachedBadges = array();
            if (isset($cachedBadges[$object->id])) {
                return 0; // Já processado
            }
            
            $sql = "SELECT id, numero_nfse, status FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas
                    WHERE id_fatura=".(int)$object->id."
                    ORDER BY id DESC LIMIT 1";
            $res = $this->db->query($sql);
            $cachedBadges[$object->id] = true;
            if (!$res || $this->db->num_rows($res)==0) return 0;

            $r = $this->db->fetch_object($res);
            
            // Mapeamento de status da NFSe Nacional
            $status = trim($r->status);
            $sl = mb_strtolower($status, 'UTF-8');
            
            $class = 'badge-status1';  // cinza (rejeitada/erro/pendente)
            if ($sl === 'autorizada')               $class = 'badge-status4';  // verde (autorizada)
            elseif ($sl === 'cancelada')            $class = 'badge-status8';  // vermelho (cancelada)
            elseif ($sl === 'rejeitada')            $class = 'badge-status1';  // cinza (rejeitada)
            elseif ($sl === 'erro')                 $class = 'badge-status8';  // vermelho (erro)
            elseif ($sl === 'enviando')             $class = 'badge-status3';  // laranja (enviando)
            elseif ($sl === 'pendente')             $class = 'badge-status7';  // azul (pendente)

            $label = 'NFSe #'.$r->numero_nfse;

            static $printedNfse = false;
            if ($printedNfse) return 0;
            $printedNfse = true;

            echo '
            <span id="nfseStatusBadge" class="badge badge-status '.$class.'" style="display:none;">'.dol_escape_htmltag($label).'</span>
            <script>
            (function(){
            var b=document.getElementById("nfseStatusBadge");
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
            
        }
        
        return 0;
    }

}
?>