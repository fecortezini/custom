<?php
class ActionsLabapp
{
    // ┌────────────────────────────────────────────────────────────────────────────────────┐
    // │ CONFIGURAÇÃO: CAMPOS A OCULTAR NA FICHA DE TERCEIROS (societe/card.php)             │
    // │                                                                                    │
    // │ Para ocultar um campo, basta adicionar o seletor CSS neste array.                  │
    // │ O script irá esconder a linha <tr> inteira que contém o elemento.                   │
    // │                                                                                    │
    // │ COMO ENCONTRAR O SELETOR:                                                          │
    // │   1. Abra societe/card.php no navegador                                            │
    // │   2. Clique com botão direito no campo → "Inspecionar"                             │
    // │   3. Procure o atributo id="" do <input>, <select> ou <textarea>                   │
    // │   4. Use '#id_do_campo' como seletor (ex: '#address', '#fax', '#url')              │
    // │                                                                                    │
    // │ SELETORES DISPONÍVEIS EM societe/card.php:                                         │
    // │   '#address'          → Campo Endereço (textarea)                                  │
    // │   '#zipcode'          → Campo CEP                                                  │
    // │   '#town'             → Campo Cidade                                               │
    // │   '#phone'            → Campo Telefone                                             │
    // │   '#phone_mobile'     → Campo Celular                                              │
    // │   '#fax'              → Campo Fax                                                  │
    // │   '#email'            → Campo E-mail                                               │
    // │   '#url'              → Campo Website/URL                                          │
    // │   '#barcode'          → Campo Código de Barras                                     │
    // │   '#status'           → Campo Status ativo/inativo                                 │
    // │                                                                                    │
    // │ NOTA: 'viewLabel' é o texto da label no modo visualização (sem formulário).        │
    // │ Deixe vazio ('') se quiser ocultar apenas no modo edição/criação.                  │
    // └────────────────────────────────────────────────────────────────────────────────────┘

    /**
     * Lista de campos a ocultar na página de terceiros (societe/card.php).
     *
     * Cada entrada é um array com:
     *   'selector'   => Seletor jQuery do input/textarea/select (modo criação/edição)
     *   'viewLabel'  => Texto exato da label na coluna <td> (modo visualização)
     *                   Se vazio, o campo só será ocultado nos modos criação/edição.
     *
     * Para ocultar mais campos, basta adicionar novas entradas aqui.
     */
    // ┌────────────────────────────────────────────────────────────────────────────────────┐
    // │ CONFIGURAÇÃO: CAMPOS NATIVOS OBRIGATÓRIOS NA FICHA DE TERCEIROS                    │
    // │                                                                                    │
    // │ Define quais campos nativos de societe/card.php são obrigatórios.                  │
    // │ A validação ocorre em PHP (server-side) + o label recebe a classe                 │
    // │ 'fieldrequired' do Dolibarr (negrito + asterisco), igual aos extrafields.          │
    // │                                                                                    │
    // │ Cada entrada:
    // │   'postKey'  → nome do campo no POST  (ex: 'zipcode', 'phone')                    │
    // │   'selector' → id do <input>/<select> (ex: '#zipcode')                            │
    // │   'label'    → texto para mensagem de erro                                        │
    // │   'labelFor' → valor do for= da <label> (geralmente igual ao id sem '#')          │
    // │                                                                                    │
    // │ NOTA: 'name' (Razão Social) já é validado pelo core do Dolibarr.                  │
    // └────────────────────────────────────────────────────────────────────────────────────┘
    private static $nativeRequiredFields = array(

        // array('postKey' => 'name',       'selector' => '#name',       'label' => 'Razão Social', 'labelFor' => 'name'),       // já obrigatório no core

        // ── ENDEREÇO 
        array('postKey' => 'zipcode',    'selector' => '#zipcode',    'label' => 'CEP',            'labelFor' => 'zipcode'),
        array('postKey' => 'town',       'selector' => '#town',       'label' => 'Cidade',         'labelFor' => 'town'),

        // ── CONTATO (descomente para tornar obrigatório) ──────────────────────────────────
        //array('postKey' => 'phone',      'selector' => '#phone',      'label' => 'Telefone',       'labelFor' => 'phone'),
        array('postKey' => 'idprof1',   'selector' => '#idprof1',      'label' => 'CNPJ',         'labelFor' => 'idprof1'),
        // array('postKey' => 'phone_mobile', 'selector' => '#phone_mobile', 'label' => 'Celular', 'labelFor' => 'phone_mobile'),

    );

    private static $fieldsToHide = array(

        //array('selector' => '#address',  'viewLabel' => 'Address'),

        // ── Exemplos prontos para ativar (descomente a linha): ──

        array('selector' => '#fax', 'viewLabel' => 'Fax'),
        array('selector' => '#url', 'viewLabel' => 'Web'),
        array('selector' => '#intra_vat', 'viewLabel' => 'ID do IVA'),
        array('input' => '#EUID', 'viewLabel' => 'EUID'),
        // array('selector' => '#barcode',       'viewLabel' => 'Gencod'),
        // array('selector' => '#phone',         'viewLabel' => 'Phone'),
        // array('selector' => '#phone_mobile',  'viewLabel' => 'PhoneMobile'),
        // array('selector' => '#email',         'viewLabel' => 'EMail'),
        // array('selector' => '#zipcode',       'viewLabel' => 'Zip'),
        // array('selector' => '#town',          'viewLabel' => 'Town'),
    );

    // ┌────────────────────────────────────────────────────────────────────────────────────┐
    // │ CONFIGURAÇÃO: CAMPOS A OCULTAR NO CADASTRO DA EMPRESA (admin/company.php)          │
    // │                                                                                    │
    // │ Para ocultar um campo, basta DESCOMENTAR a linha correspondente abaixo.            │
    // │ Para reexibir, comente a linha novamente.                                          │
    // │                                                                                    │
    // │ SELETORES DISPONÍVEIS EM admin/company.php:                                        │
    // │                                                                                    │
    // │ ── BLOCO ENDEREÇO ────────────────────────────────────────────────────────────     │
    // │   '#MAIN_INFO_SOCIETE_ADDRESS' → Endereço (textarea nativo)                        │
    // │   '#MAIN_INFO_SOCIETE_ZIP'    → CEP                                                │
    // │   '#MAIN_INFO_SOCIETE_TOWN'   → Cidade                                             │
    // │   '#state_id'                 → Estado (select)                                    │
    // │   '#MAIN_INFO_RUA'            → Rua  (campo personalizado do módulo)               │
    // │   '#MAIN_INFO_BAIRRO'         → Bairro (campo personalizado do módulo)             │
    // │   '#MAIN_INFO_NUMERO'         → Número (campo personalizado do módulo)             │
    // │                                                                                    │
    // │ ── BLOCO IDENTIFICAÇÃO ───────────────────────────────────────────────────────     │
    // │   '#name'                     → Razão Social (campo obrigatório — cuidado!)        │
    // │   '#MAIN_INFO_NOME_FANTASIA'  → Nome Fantasia (campo personalizado do módulo)      │
    // │   '#currency'                 → Moeda padrão (select)                              │
    // │   '#selectcountry_id'         → País (select)                                      │
    // │                                                                                    │
    // │ ── BLOCO CONTATO ─────────────────────────────────────────────────────────────     │
    // │   '#phone'                    → Telefone                                           │
    // │   '#phone_mobile'             → Celular                                            │
    // │   '#fax'                      → Fax                                                │
    // │   '#email'                    → E-mail                                             │
    // │   '#web'                      → Website                                            │
    // │                                                                                    │
    // │ ── BLOCO FISCAL ──────────────────────────────────────────────────────────────     │
    // │   '#MAIN_INFO_CRT'            → CRT (select — campo personalizado do módulo)       │
    // │   '#MAIN_INFO_REGIMETRIBUTACAO' → Regime Tributação (select — módulo)              │
    // │   '#MAIN_INFO_INCENTIVOFISCAL'  → Incentivo Fiscal (select — módulo)               │
    // │                                                                                    │
    // │ ── OUTROS ────────────────────────────────────────────────────────────────────     │
    // │   '#note'                     → Observações (textarea)                             │
    // │   '#barcode'                  → Código de Barras (só aparece se módulo ativo)      │
    // └────────────────────────────────────────────────────────────────────────────────────┘
    private static $fieldsToHideAdmin = array(

        // ── ENDEREÇO ─────────────────────────────────────────────────────────────────────
        //array('selector' => '#MAIN_INFO_SOCIETE_ADDRESS','viewLabel' => ''),  // Endereço (textarea nativo)
        //array('selector' => '#MAIN_INFO_SOCIETE_ZIP',    'viewLabel' => ''),  // CEP
        //array('selector' => '#MAIN_INFO_SOCIETE_TOWN',   'viewLabel' => ''),  // Cidade
        //array('selector' => '#state_id',                 'viewLabel' => ''),  // Estado
        // array('selector' => '#MAIN_INFO_RUA',          'viewLabel' => ''),  // Rua    (módulo)
        // array('selector' => '#MAIN_INFO_BAIRRO',       'viewLabel' => ''),  // Bairro (módulo)
        // array('selector' => '#MAIN_INFO_NUMERO',       'viewLabel' => ''),  // Número (módulo)

        // ── CONTATO (descomente para ocultar) ────────────────────────────────────────────
        // array('selector' => '#phone',                  'viewLabel' => ''),  // Telefone
        // array('selector' => '#phone_mobile',           'viewLabel' => ''),  // Celular
        // array('selector' => '#fax',                    'viewLabel' => ''),  // Fax
        // array('selector' => '#email',                  'viewLabel' => ''),  // E-mail
        // array('selector' => '#web',                    'viewLabel' => ''),  // Website

        // ── OUTROS (descomente para ocultar) ─────────────────────────────────────────────
        // array('selector' => '#note',                   'viewLabel' => ''),  // Observações
        // array('selector' => '#barcode',                'viewLabel' => ''),  // Cód. Barras
        // array('selector' => '#MAIN_INFO_NOME_FANTASIA','viewLabel' => ''),  // Nome Fantasia
    );

    // ┌─────────────────────────────────────────────────────────────────────────┐
    // │ CONFIGURAÇÃO: ORDEM DOS EXTRAFIELDS NA FICHA DE PRODUTO                 │
    // │                                                                          │
    // │ Define onde cada extrafield de produto/serviço aparece no formulário:   │
    // │                                                                          │
    // │   'after'  → logo ABAIXO do campo Descrição  (padrão para não listados) │
    // │   'before' → logo ACIMA  do campo Descrição                             │
    // │                                                                          │
    // │ COMO DESCOBRIR O NOME DO CAMPO:                                          │
    // │   1. Vá em Configuração > Extrafields > Produtos                         │
    // │   2. Copie o valor da coluna "Código" do campo desejado                 │
    // │   3. Adicione aqui: 'codigo_do_campo' => 'after'  (ou 'before')         │
    // │                                                                          │
    // │ Campos NÃO listados aqui aparecem ABAIXO da Descrição por padrão.       │
    // │                                                                          │
    // │ EXEMPLOS:                                                                │
    // │   'prd_ncm'  => 'after'   → NCM aparece ABAIXO da Descrição ✓           │
    // │   'prd_ncm'  => 'before'  → NCM aparece ACIMA  da Descrição             │
    // └─────────────────────────────────────────────────────────────────────────┘

    /**
     * Ordem dos extrafields na ficha de produto/serviço.
     *
     * Chave   = código do extrafield (exatamente como cadastrado no Dolibarr).
     * Valor   = 'after'  → abaixo da Descrição (padrão)
     *         = 'before' → acima  da Descrição
     *
     * Campos NÃO listados aqui vão 'after' automaticamente.
     */
    private static $productExtrafieldsOrder = array(

        // ── Campos de PRODUTO (exemplo: prefixo prd_) ───────────────────────
        // 'prd_ncm'          => 'after',   // Código NCM
        // 'prd_cest'         => 'after',   // Código CEST
        // 'prd_csosn'        => 'after',   // CSOSN
        // 'prd_fornecimento' => 'after',   // Origem/Fornecimento

        // ── Campos de SERVIÇO (exemplo: prefixo srv_) ───────────────────────
        // 'srv_cod_itemlistaservico' => 'after',  // Código de Serviço LC116

        // ── Para colocar um campo ACIMA da Descrição, use 'before': ─────────
        // 'prd_ncm' => 'before',

    );

    /** @var DoliDB Instância do banco de dados do Dolibarr */
    protected $db;

    /** @var Translate Instância do gerenciador de idiomas/traduções */
    protected $langs;

    /** @var array Resultados a serem retornados ao HookManager (exigido pelo core) */
    public $results = array();

    /** @var string Se preenchido com HTML, substitui a saída padrão da página (exigido pelo core) */
    public $resprints = '';

    /** @var string Mensagem de erro único (exigido pelo core) */
    public $error = '';

    /** @var array Mensagens de erro múltiplos (exigido pelo core) */
    public $errors = array();



    /**
     * Construtor — chamado automaticamente pelo HookManager do Dolibarr
     *
     * @param DoliDB         $db     Instância do banco de dados
     * @param Translate|null $langs  Instância de idiomas (se null, usa o global)
     */
    public function __construct($db, $langs = null)
    {
        // Armazena a referência ao banco de dados
        $this->db = $db;

        // Se $langs foi passado explicitamente, usa ele.
        // Senão, faz fallback para a variável global $langs do Dolibarr.
        if ($langs !== null) {
            $this->langs = $langs;
        } else {
            global $langs;
            $this->langs = $langs;
        }
    }


    /**
     * Hook doActions — Ponto de entrada principal
     *
     * Chamado automaticamente pelo HookManager do Dolibarr.
     * Verifica o contexto da página e delega para o handler correto.
     *
     * VALORES DE RETORNO (convenção do Dolibarr):
     *   0  = Ação processada com sucesso, continua processamento padrão
     *   >0 = Ação processada, SUBSTITUI o processamento padrão do Dolibarr
     *   <0 = Erro ocorreu
     *
     * @param  array       $parameters  Parâmetros do hook. $parameters['context'] contém
     *                                  os contextos separados por ':' (ex: 'admincompany:globaladmin')
     * @param  object      $object      Objeto da página (geralmente vazio/dummy em admin)
     * @param  string      $action      Ação corrente do formulário ('update', 'updateedit', '', etc.)
     * @param  HookManager $hookmanager Instância do gerenciador de hooks
     * @return int                      Código de retorno (0 = OK)
     */
    public function doActions($parameters, &$object, &$action, $hookmanager)
    {
        // ── Extrai os contextos da página atual ──
        // O Dolibarr passa os contextos como string separada por ':'
        // Exemplo: 'admincompany:globaladmin'
        // Convertemos para array para facilitar a verificação
        $contexts = empty($parameters['context']) ? array() : explode(':', $parameters['context']);

        // ── Verifica se estamos na página de configuração da empresa ──
        // O contexto 'admincompany' é registrado pela página admin/company.php
        // na linha: $hookmanager->initHooks(array('admincompany', 'globaladmin'));
        if (in_array('admincompany', $contexts)) {
            return $this->doActionsAdminCompany($action);
        }

        // ── Verifica se estamos na ficha de terceiros ──
        // O contexto 'thirdpartycard' é registrado pela página societe/card.php
        // na linha: $hookmanager->initHooks(array('thirdpartycard', 'globalcard'));
        if (in_array('thirdpartycard', $contexts)) {
            return $this->doActionsThirdPartyCard($action); // $action é &ref em doActions, a assinatura do método aceita &$action
        }

        // Se não for o contexto esperado, retorna 0 (não faz nada, não bloqueia)
        return 0;
    }
    /**
     * Handler específico para admin/company.php
     *
     * @param  string $action Ação corrente do formulário ('update', 'updateedit', 'savesetup', etc.)
     * @return int    0 = processamento normal (Dolibarr continua sua lógica)
     */
    private function doActionsAdminCompany($action)
    {
        // Acessa as variáveis globais do Dolibarr
        // $conf → Configurações do sistema (contém $conf->global->NOME_CONSTANTE)
        // $db   → Instância do banco de dados
        global $conf, $db;

        // Se não há campos para ocultar, não faz nada
        if (empty(self::$fieldsToHideAdmin)) {
            return 0;
        }

        // Converte a lista PHP para JSON (será usada no JavaScript)
        $fieldsJson = json_encode(self::$fieldsToHideAdmin, JSON_HEX_APOS | JSON_HEX_QUOT);

        ob_start(function ($html) use ($fieldsJson) {

            $script = <<<JSHIDE
<script>
/**
 * HOOK LabApp — Ocultar campos na ficha de terceiros
 *
 * Percorre a lista de campos configurados em ActionsLabapp::\$fieldsToHideAdmin
 * e esconde a linha <tr> que contém cada um.
 *
 * Funciona em 3 modos:
 * - Criação (action=create): campos com id no <input>/<textarea>/<select>
 * - Edição (action=edit): idem
 * - Visualização (sem action): campos identificados pelo texto da label <td>
 */
(function() {
    'use strict';
    document.addEventListener('DOMContentLoaded', function() {

        var fieldsToHide = {$fieldsJson};

        for (var i = 0; i < fieldsToHide.length; i++) {
            var field    = fieldsToHide[i];
            var selector = field.selector  || '';
            var label    = field.viewLabel || '';

            // ── MODO EDIÇÃO / CRIAÇÃO ──────────────────────────────────
            // Busca o campo pelo seletor (#id) e esconde a <tr> ancestral
            if (selector) {
                var el = document.querySelector(selector);
                if (el) {
                    var tr = el.closest('tr');
                    if (tr) {
                        tr.style.display = 'none';
                    }
                }

                // Busca também pela label[for="xxx"]
                // (alguns campos têm a label em outra <td> da mesma <tr>)
                var forAttr = selector.replace('#', '');
                var lbl = document.querySelector('label[for="' + forAttr + '"]');
                if (lbl) {
                    var trLabel = lbl.closest('tr');
                    if (trLabel) {
                        trLabel.style.display = 'none';
                    }
                }
            }

            // ── MODO VISUALIZAÇÃO ──────────────────────────────────────
            // No modo view, os campos não têm id — são apenas texto em <td>.
            // Percorremos todas as <td> procurando pelo texto da label.
            if (label) {
                var allTds = document.querySelectorAll('td.titlefield, td.titlefieldmiddle, td.tdtop, table.border td:first-child');
                for (var j = 0; j < allTds.length; j++) {
                    var td = allTds[j];
                    // Compara o texto limpo da <td> com a label configurada
                    var text = (td.textContent || td.innerText || '').trim();
                    if (text === label) {
                        var trView = td.closest('tr');
                        if (trView) {
                            trView.style.display = 'none';
                        }
                    }
                }
            }
        }
    });
})();
</script>
JSHIDE;

            $pos = strripos($html, '</body>');
            if ($pos !== false) {
                $html = substr($html, 0, $pos) . $script . substr($html, $pos);
            } else {
                $html .= $script;
            }

            return $html;
        });

        if (in_array($action, array('update', 'updateedit'))) {

            // Lista de campos de TEXTO — salvos via dolibarr_set_const
            $nfseFields = array(
                'MAIN_INFO_NOME_FANTASIA',    // Nome fantasia da empresa (xFant no XML)
                'MAIN_INFO_RUA',              // Logradouro (separado do "Address" nativo)
                'MAIN_INFO_BAIRRO',           // Bairro (não existe nativamente)
                'MAIN_INFO_NUMERO',           // Número do endereço
                'MAIN_INFO_INCENTIVOFISCAL',  // Incentivo fiscal (1=Sim, 2=Não)
                'MAIN_INFO_REGIMETRIBUTACAO', // Regime especial de tributação (1-6)
            );

            foreach ($nfseFields as $key) {
                if (GETPOSTISSET($key)) {
                    dolibarr_set_const($db, $key, GETPOST($key, 'alphanohtml'), 'chaine', 0, '', $conf->entity);
                }
            }

            // CRT — campo select com validação de valores permitidos
            $crt = GETPOST('MAIN_INFO_CRT', 'int');
            if (GETPOSTISSET('MAIN_INFO_CRT')) {
                if (in_array($crt, array(1, 2, 3, 4))) {
                    dolibarr_set_const($db, 'MAIN_INFO_CRT', $crt, 'chaine', 0, '', $conf->entity);
                } else {
                    setEventMessages('CRT deve ser 1, 2, 3 ou 4', null, 'errors');
                }
            }
        }

        

        if ($action === 'savesetup' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            // ── Validação CSRF ──
            // O formulário de certificado é independente do form principal,
            // então fazemos a validação de token manualmente.
            // $_SESSION['newtoken'] é gerado pelo Dolibarr a cada request.
            if (empty($_POST['token']) || $_POST['token'] !== $_SESSION['newtoken']) {
                setEventMessages('Token de segurança inválido. Recarregue a página.', null, 'errors');
            } else {
                // ── Carrega as bibliotecas necessárias ──
                // nfse_security.lib.php: funções nfseEncryptPassword() e nfseDecryptPassword()
                //   → Criptografia AES-256-GCM com chave mestra no banco
                // nfecertificate.class.php: classe NfeCertificate
                //   → Processa e converte PFX para compatibilidade com OpenSSL 3.x
                $securityLib = DOL_DOCUMENT_ROOT.'/custom/nfse/lib/nfse_security.lib.php';
                $certClass   = DOL_DOCUMENT_ROOT.'/custom/labapp/class/nfecertificate.class.php';

                if (file_exists($securityLib)) {
                    require_once $securityLib;
                }
                if (file_exists($certClass)) {
                    require_once $certClass;
                }

                // Garante que encryption_master_key existe ANTES da transação principal.
                // Se estiver dentro de $db->begin()/$db->rollback(), a chave seria perdida.
                // Chamando aqui (fora da transação), a chave é criada/confirmada no banco
                // com autocommit, garantindo que estará disponível para sempre.
                if (function_exists('getNfseCertPasswordKey')) {
                    getNfseCertPasswordKey($db);
                }

                $this->db->begin();
                try {
                    // ── 2.1: Salva o ambiente (Produção=1, Homologação=2) ──
                    $amb = GETPOST('ambiente', 'int');
                    if (!in_array($amb, array(1, 2))) {
                        $amb = 2; // Fallback seguro: Homologação
                    }
                    self::nfeConfigUpsert($this->db, 'ambiente', $amb);

                    // ── 2.2: Salva a senha do certificado (criptografada) ──
                    // Se o campo veio vazio, NÃO sobrescreve (mantém a anterior)
                    $pass = GETPOST('cert_pass', 'restricthtml');
                    if ($pass !== '') {
                        if (function_exists('nfseEncryptPassword')) {
                            // Criptografa com AES-256-GCM antes de gravar
                            // A chave mestra é gerada/lida automaticamente de llx_nfe_config
                            $encryptedPass = nfseEncryptPassword($pass, $this->db);
                        } else {
                            // Fallback: se a lib de segurança não está disponível,
                            // armazena em texto (não ideal, mas funcional)
                            $encryptedPass = $pass;
                        }
                        self::nfeConfigUpsert($this->db, 'cert_pass', $encryptedPass);
                    }

                    // ── 2.3: Processa o upload do certificado digital ──
                    if (!empty($_FILES['cert_pfx_file'])
                        && !empty($_FILES['cert_pfx_file']['tmp_name'])
                        && is_uploaded_file($_FILES['cert_pfx_file']['tmp_name'])
                    ) {
                        // Lê o conteúdo binário do arquivo enviado
                        $pfxContent = file_get_contents($_FILES['cert_pfx_file']['tmp_name']);

                        if ($pfxContent === false || strlen($pfxContent) === 0) {
                            throw new Exception('Arquivo do certificado está vazio ou não pôde ser lido.');
                        }

                        // Validação de tamanho (certificados A1 geralmente têm < 20KB)
                        if (strlen($pfxContent) > 100 * 1024) {
                            throw new Exception('Arquivo do certificado excede 100KB. Verifique se é um certificado A1 válido.');
                        }

                        // Obtém a senha para processar o certificado:
                        // - Se o usuário informou nova senha neste request, usa ela
                        // - Senão, descriptografa a senha armazenada no banco
                        if ($pass !== '') {
                            $certPassword = $pass;
                        } else {
                            $cfg = self::getNfeConfig($this->db);
                            $storedPass = $cfg['cert_pass'] ?? '';
                            if (function_exists('nfseDecryptPassword') && $storedPass !== '') {
                                $certPassword = nfseDecryptPassword($storedPass, $this->db);
                            } else {
                                $certPassword = $storedPass;
                            }
                        }

                        // ── CONVERSÃO DO CERTIFICADO (melhoria principal) ──
                        // A classe NfeCertificate::processAndConvert() faz:
                        //   1. Tenta ler o PFX diretamente com openssl_pkcs12_read()
                        //   2. Se falhar por algoritmo legado (RC2, 3DES em OpenSSL 3.x):
                        //      a. Extrai cert e key via CLI com flag -legacy
                        //      b. Recombina em novo PFX com AES-256-CBC + SHA256
                        //      c. Valida o novo PFX com openssl_pkcs12_read()
                        //   3. Retorna ['pfx' => conteúdo, 'info' => dados, 'converted' => bool]
                        //
                        // NOTA: O código anterior em company.php tinha o processAndConvert()
                        // COMENTADO (linha com //) e salvava $pfxContent (original) em vez de
                        // $result['pfx'] (convertido). Isso foi CORRIGIDO aqui.
                        $pfxToSave = $pfxContent; // Padrão: salva o original
                        $wasConverted = false;

                        if (class_exists('NfeCertificate')) {
                            $certHandler = new NfeCertificate();
                            $result = $certHandler->processAndConvert($pfxContent, $certPassword);

                            if ($result === false) {
                                throw new Exception('Erro ao processar certificado: ' . $certHandler->error);
                            }

                            // CORREÇÃO: Salva o PFX CONVERTIDO (não o original)
                            // Se não houve conversão, $result['pfx'] === $pfxContent
                            $pfxToSave = $result['pfx'];
                            $wasConverted = $result['converted'];
                        }

                        // Grava o certificado na tabela llx_nfe_config
                        self::nfeConfigUpsert($this->db, 'cert_pfx', $pfxToSave);

                        if ($wasConverted) {
                            setEventMessages(
                                'Certificado convertido automaticamente para compatibilidade com OpenSSL 3.x (AES-256-CBC + SHA256).',
                                null,
                                'warnings'
                            );
                        }
                    }

                    $this->db->commit();
                    setEventMessages('Configurações de ambiente salvas com sucesso.', null, 'mesgs');

                } catch (\Throwable $e) {
                    $this->db->rollback();
                    setEventMessages($e->getMessage(), null, 'errors');
                }
            }
        }

        if ($action === 'savesequencias' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            if (empty($_POST['token']) || $_POST['token'] !== $_SESSION['newtoken']) {
                setEventMessages('Token de segurança inválido. Recarregue a página.', null, 'errors');
            } else {
                $cnpjVerif = preg_replace('/\D/', '', getDolGlobalString('MAIN_INFO_SIREN'));
                if (empty($cnpjVerif)) {
                    setEventMessages('Informe o CNPJ da empresa nos dados cadastrais antes de salvar as sequências.', null, 'errors');
                } else {
                $this->db->begin();
                try {
                    // ── NFS-e: UPSERT serie + next_dps (UPDATE se row existe, INSERT se foi deletada) ──
                    $cnpjNfse    = $cnpjVerif;
                    $imNfse      = getDolGlobalString('MAIN_INFO_SIRET');
                    $nfseIds     = isset($_POST['nfse_seq_id'])   ? (array)$_POST['nfse_seq_id']   : array();
                    $nfseSeries  = isset($_POST['nfse_serie'])    ? (array)$_POST['nfse_serie']    : array();
                    $nfseNexts   = isset($_POST['nfse_next_dps']) ? (array)$_POST['nfse_next_dps'] : array();
                    $nfseAmbs    = isset($_POST['nfse_ambiente']) ? (array)$_POST['nfse_ambiente'] : array();

                    dol_syslog('LabApp savesequencias NFS-e: cnpj=['.$cnpjNfse.'] ids='.json_encode($nfseIds).' ambs='.json_encode($nfseAmbs), LOG_DEBUG);

                    foreach ($nfseIds as $i => $seqId) {
                        $seqId   = (int)$seqId;
                        $serie   = isset($nfseSeries[$i])  ? $this->db->escape(trim($nfseSeries[$i]))  : '1';
                        $nextDps = isset($nfseNexts[$i])   ? (int)$nfseNexts[$i] + 1             : 1;
                        $amb     = isset($nfseAmbs[$i])    ? (int)$nfseAmbs[$i]                  : 0;

                        if ($serie === '' || !in_array($amb, array(1, 2))) continue;

                        if ($seqId > 0) {
                            // Row existe — UPDATE: também atualiza cnpj e im caso tenham mudado
                            $cnpjEscNfse = $this->db->escape($cnpjNfse);
                            $imEscNfse   = $this->db->escape($imNfse);
                            $sql = "UPDATE ".MAIN_DB_PREFIX."nfse_nacional_sequencias"
                                ." SET cnpj = '".$cnpjEscNfse."', im = '".$imEscNfse."', serie = '".$serie."', next_dps = ".$nextDps.", updated_at = NOW()"
                                ." WHERE id = ".$seqId;
                        } else {
                            // Row foi deletada — INSERT
                            $cnpjEscNfse = $this->db->escape($cnpjNfse);
                            $imEscNfse   = $this->db->escape($imNfse);
                            $sql = "INSERT INTO ".MAIN_DB_PREFIX."nfse_nacional_sequencias (cnpj, im, serie, next_dps, ambiente, updated_at)"
                                ." VALUES ('".$cnpjEscNfse."', '".$imEscNfse."', '".$serie."', ".$nextDps.", ".$amb.", NOW())";
                        }

                        if (!$this->db->query($sql)) {
                            dol_syslog('LabApp NFS-e error: '.$this->db->lasterror().' SQL='.$sql, LOG_ERR);
                            setEventMessages('Erro ao salvar NFS-e (ambiente '.$amb.'): '.$this->db->lasterror(), null, 'errors');
                        }
                    }
                    // Limpa registros órfãos de CNPJs antigos (evita duplicatas após troca de empresa)
                    if ($cnpjNfse !== '') {
                        $cnpjNfseClean = $this->db->escape($cnpjNfse);
                        $this->db->query("DELETE FROM ".MAIN_DB_PREFIX."nfse_nacional_sequencias WHERE cnpj <> '".$cnpjNfseClean."'");
                    }

                    // ── NF-e: atualiza serie + ultimo_numero (espelhando NFS-e) ──
                    // Usa CNPJ de MAIN_INFO_SIREN (campo CNPJ no Dolibarr BR)
                    $cnpjEmpresa = preg_replace('/\D/', '', getDolGlobalString('MAIN_INFO_SIREN'));
                    $nfeAmbientes = isset($_POST['nfe_ambiente'])      ? (array)$_POST['nfe_ambiente']      : array();
                    $nfeSeries    = isset($_POST['nfe_serie'])          ? (array)$_POST['nfe_serie']          : array();
                    $nfeUltimos   = isset($_POST['nfe_ultimo_numero'])  ? (array)$_POST['nfe_ultimo_numero']  : array();

                    dol_syslog('LabApp savesequencias NF-e: cnpj=['.$cnpjEmpresa.'] ambientes='.json_encode($nfeAmbientes).' series='.json_encode($nfeSeries).' ultimos='.json_encode($nfeUltimos), LOG_DEBUG);

                    if (empty($cnpjEmpresa)) {
                        setEventMessages('NF-e: Configure o CNPJ da empresa antes de salvar as sequências.', null, 'warnings');
                    } elseif (empty($nfeAmbientes)) {
                        dol_syslog('LabApp savesequencias NF-e: nfe_ambiente[] vazio no POST', LOG_WARNING);
                    } else {
                        $cnpjEsc = $this->db->escape($cnpjEmpresa);
                        // Remove registros do CNPJ antigo (caso o CNPJ tenha sido alterado)
                        $nfeCnpjsForm = isset($_POST['nfe_cnpj']) ? (array)$_POST['nfe_cnpj'] : array();
                        foreach (array_unique($nfeCnpjsForm) as $oldCnpj) {
                            $oldCnpj = preg_replace('/\D/', '', trim((string)$oldCnpj));
                            if ($oldCnpj !== '' && $oldCnpj !== $cnpjEmpresa) {
                                $this->db->query("DELETE FROM ".MAIN_DB_PREFIX."nfe_sequencia WHERE cnpj = '".$this->db->escape($oldCnpj)."'");
                                dol_syslog('LabApp NF-e: removidos registros do CNPJ antigo ['.$oldCnpj.']', LOG_DEBUG);
                            }
                        }
                        $this->db->query("DELETE FROM ".MAIN_DB_PREFIX."nfe_sequencia WHERE cnpj = '".$cnpjEsc."'");
                        foreach ($nfeAmbientes as $i => $ambVal) {
                            $amb    = (int)$ambVal;
                            $serie  = isset($nfeSeries[$i])  ? max(1, (int)$nfeSeries[$i])  : 1;
                            $ultimo = isset($nfeUltimos[$i]) ? max(0, (int)$nfeUltimos[$i]) : 0;
                            if (in_array($amb, array(1, 2))) {
                                $sql = "INSERT INTO ".MAIN_DB_PREFIX."nfe_sequencia (cnpj, serie, ambiente, ultimo_numero)"
                                    ." VALUES ('".$cnpjEsc."', ".$serie.", ".$amb.", ".$ultimo.")";
                                if (!$this->db->query($sql)) {
                                    dol_syslog('LabApp NF-e INSERT error: '.$this->db->lasterror().' SQL='.$sql, LOG_ERR);
                                    setEventMessages('Erro ao salvar NF-e (ambiente '.$amb.'): '.$this->db->lasterror(), null, 'errors');
                                }
                            }
                        }
                    }

                    $this->db->commit();
                    setEventMessages('Sequências de emissão atualizadas com sucesso.', null, 'mesgs');
                } catch (\Throwable $e) {
                    $this->db->rollback();
                    setEventMessages($e->getMessage(), null, 'errors');
                }
                } // fim else cnpjVerif
            }
        }

        // Pré-carrega dados de certificado/ambiente ANTES do ob_start
        // porque o callback roda após $this->db->close() em company.php
        $nfeCfg = self::getNfeConfig($this->db);

        // CNPJ da empresa — usa MAIN_INFO_SIREN (campo CNPJ no Dolibarr BR)
        // (declarado aqui pois é usado tanto pelo auto-seed NFS-e como pelo NF-e)
        $cnpjEmpresa = preg_replace('/\D/', '', getDolGlobalString('MAIN_INFO_SIREN'));

        // Pré-carrega sequências NFS-e e NF-e — filtra pelo CNPJ ATUAL para evitar
        // que registros de CNPJ antigo apareçam no formulário após troca de empresa.
        $nfseSeqs = array();
        $cnpjEmpresaEsc = $this->db->escape($cnpjEmpresa);
        $resNfse = $this->db->query("SELECT id, cnpj, im, serie, next_dps, ambiente FROM ".MAIN_DB_PREFIX."nfse_nacional_sequencias WHERE cnpj = '".$cnpjEmpresaEsc."' ORDER BY id");
        if ($resNfse) {
            while ($o = $this->db->fetch_object($resNfse)) {
                $nfseSeqs[] = array(
                    'id'       => (int)$o->id,
                    'cnpj'     => (string)$o->cnpj,
                    'im'       => (string)$o->im,
                    'serie'    => (string)$o->serie,
                    'next_dps' => (int)$o->next_dps,
                    'ambiente' => (string)$o->ambiente,
                );
            }
        }
        // Auto-seed NFS-e: garante registros para ambos os ambientes quando NÃO existem
        // registros para o CNPJ atual (pode haver registros de CNPJ antigo orphaned)
        if (empty($nfseSeqs) && $cnpjEmpresa !== '') {
            $cnpjEscNfse = $this->db->escape($cnpjEmpresa);
            $imEscNfse   = $this->db->escape(getDolGlobalString('MAIN_INFO_SIRET'));
            $this->db->query("INSERT INTO ".MAIN_DB_PREFIX."nfse_nacional_sequencias (cnpj, im, serie, next_dps, ambiente, updated_at) VALUES ('".$cnpjEscNfse."', '".$imEscNfse."', '1', 1, 2, NOW())");
            $this->db->query("INSERT INTO ".MAIN_DB_PREFIX."nfse_nacional_sequencias (cnpj, im, serie, next_dps, ambiente, updated_at) VALUES ('".$cnpjEscNfse."', '".$imEscNfse."', '1', 1, 1, NOW())");
            // Recarrega após seed (filtrado pelo CNPJ atual)
            $resNfse2 = $this->db->query("SELECT id, cnpj, im, serie, next_dps, ambiente FROM ".MAIN_DB_PREFIX."nfse_nacional_sequencias WHERE cnpj = '".$cnpjEmpresaEsc."' ORDER BY id");
            if ($resNfse2) {
                while ($o = $this->db->fetch_object($resNfse2)) {
                    $nfseSeqs[] = array(
                        'id'       => (int)$o->id,
                        'cnpj'     => (string)$o->cnpj,
                        'im'       => (string)$o->im,
                        'serie'    => (string)$o->serie,
                        'next_dps' => (int)$o->next_dps,
                        'ambiente' => (string)$o->ambiente,
                    );
                }
            }
        }
        $nfeSeqs = array();
        $resNfe = $this->db->query("SELECT cnpj, serie, ambiente, ultimo_numero FROM ".MAIN_DB_PREFIX."nfe_sequencia WHERE cnpj = '".$cnpjEmpresaEsc."' ORDER BY serie, ambiente");
        if ($resNfe) {
            while ($o = $this->db->fetch_object($resNfe)) {
                $nfeSeqs[] = array(
                    'cnpj'          => (string)$o->cnpj,
                    'serie'         => (int)$o->serie,
                    'ambiente'      => (int)$o->ambiente,
                    'ultimo_numero' => (int)$o->ultimo_numero,
                );
            }
        }
        // CNPJ da empresa — usa MAIN_INFO_SIREN (campo CNPJ no Dolibarr BR)
        // Auto-seed: garante que existam registros de NF-e para ambos os ambientes
        // (espelhando o comportamento da NFS-e, que já possui registros pré-populados)
        if (empty($nfeSeqs) && $cnpjEmpresa !== '') {
            $cnpjEsc = $this->db->escape($cnpjEmpresa);
            $this->db->query("INSERT IGNORE INTO ".MAIN_DB_PREFIX."nfe_sequencia (cnpj, serie, ambiente, ultimo_numero) VALUES ('".$cnpjEsc."', 1, 2, 0)");
            $this->db->query("INSERT IGNORE INTO ".MAIN_DB_PREFIX."nfe_sequencia (cnpj, serie, ambiente, ultimo_numero) VALUES ('".$cnpjEsc."', 1, 1, 0)");
            // Recarrega após seed (filtrado pelo CNPJ atual)
            $resNfe2 = $this->db->query("SELECT cnpj, serie, ambiente, ultimo_numero FROM ".MAIN_DB_PREFIX."nfe_sequencia WHERE cnpj = '".$cnpjEmpresaEsc."' ORDER BY serie, ambiente");
            if ($resNfe2) {
                while ($o = $this->db->fetch_object($resNfe2)) {
                    $nfeSeqs[] = array(
                        'cnpj'          => (string)$o->cnpj,
                        'serie'         => (int)$o->serie,
                        'ambiente'      => (int)$o->ambiente,
                        'ultimo_numero' => (int)$o->ultimo_numero,
                    );
                }
            }
        }

        ob_start(function ($html) use ($conf, $nfeCfg, $nfseSeqs, $nfeSeqs, $cnpjEmpresa) {
            try {

            // ================================================================
            // 3A: DADOS PARA OS CAMPOS FISCAIS
            // ================================================================
            $nomeFantasia     = addslashes(getDolGlobalString('MAIN_INFO_NOME_FANTASIA'));
            $rua              = addslashes(getDolGlobalString('MAIN_INFO_RUA'));
            $bairro           = addslashes(getDolGlobalString('MAIN_INFO_BAIRRO'));
            $numero           = addslashes(getDolGlobalString('MAIN_INFO_NUMERO'));
            $crt              = (int) getDolGlobalInt('MAIN_INFO_CRT');
            $regimeTributacao = (int) getDolGlobalInt('MAIN_INFO_REGIMETRIBUTACAO');
            $incentivoFiscal  = (int) getDolGlobalInt('MAIN_INFO_INCENTIVOFISCAL');

            if ($crt < 1) $crt = 1;
            if ($regimeTributacao < 1) $regimeTributacao = 1;
            if ($incentivoFiscal < 1) $incentivoFiscal = 2;

            // ================================================================
            // 3B: DADOS PARA A SEÇÃO DE CERTIFICADO/AMBIENTE
            // ================================================================
            // Usa dados pré-carregados ANTES do ob_start (via $nfeCfg)
            // porque $this->db já foi fechado quando o callback executa

            // Ambiente: '1' = Produção, '2' = Homologação (padrão)
            $ambiente   = addslashes($nfeCfg['ambiente'] ?? '2');
            // Flags booleanas para BADGES informativos (não expomos os dados)
            $hasPfxJs   = !empty($nfeCfg['cert_pfx']) ? 'true' : 'false';
            $hasPassJs  = !empty($nfeCfg['cert_pass']) ? 'true' : 'false';
            // Token CSRF para o formulário de certificado
            $csrfToken  = addslashes(isset($_SESSION['newtoken']) ? $_SESSION['newtoken'] : '');

            // ================================================================
            // SCRIPT A: CAMPOS FISCAIS (inseridos no formulário principal)
            // ================================================================
            $jsFiscalFields = <<<JSBLOCK
<script>
/**
 * SCRIPT A — Campos Fiscais (NFSe/NFe)
 * Insere campos no formulário principal da empresa, antes do campo Phone.
 */
(function() {
    'use strict';
    // Desativa restauração automática de scroll do browser após redirects.
    if (window.history && window.history.scrollRestoration) {
        window.history.scrollRestoration = 'manual';
    }
    document.addEventListener('DOMContentLoaded', function() {
        var phoneLabel = document.querySelector('label[for="phone"]');
        if (!phoneLabel) return;
        var phoneRow = phoneLabel.closest('tr');
        if (!phoneRow) return;
        var tbody = phoneRow.parentNode;

        function criarLinhaInput(label, name, value, cssClass) {
            var tr = document.createElement('tr');
            tr.className = 'oddeven';
            var td1 = document.createElement('td');
            td1.style.width = '25%';
            td1.textContent = label;
            var td2 = document.createElement('td');
            var input = document.createElement('input');
            input.type = 'text';
            input.className = 'flat ' + (cssClass || 'minwidth300');
            input.name = name;
            input.value = value || '';
            td2.appendChild(input);
            tr.appendChild(td1);
            tr.appendChild(td2);
            return tr;
        }

        function criarLinhaSelect(label, name, options, selectedValue) {
            var tr = document.createElement('tr');
            tr.className = 'oddeven';
            var td1 = document.createElement('td');
            td1.style.width = '25%';
            td1.textContent = label;
            var td2 = document.createElement('td');
            var select = document.createElement('select');
            select.name = name;
            select.className = 'flat minwidth200';
            for (var i = 0; i < options.length; i++) {
                var opt = document.createElement('option');
                opt.value = options[i].value;
                opt.textContent = options[i].text;
                if (parseInt(options[i].value) === parseInt(selectedValue)) {
                    opt.selected = true;
                }
                select.appendChild(opt);
            }
            td2.appendChild(select);
            tr.appendChild(td1);
            tr.appendChild(td2);
            return tr;
        }

        

        tbody.insertBefore(criarLinhaInput('Nome Fantasia', 'MAIN_INFO_NOME_FANTASIA', '{$nomeFantasia}'), phoneRow);
        tbody.insertBefore(criarLinhaInput('Rua', 'MAIN_INFO_RUA', '{$rua}'), phoneRow);
        tbody.insertBefore(criarLinhaInput('Bairro', 'MAIN_INFO_BAIRRO', '{$bairro}'), phoneRow);
        tbody.insertBefore(criarLinhaInput('Número', 'MAIN_INFO_NUMERO', '{$numero}', 'minwidth150'), phoneRow);

        tbody.insertBefore(criarLinhaSelect('Regime Tributário', 'MAIN_INFO_CRT', [
            {value: 1, text: 'Simples Nacional'},
            {value: 2, text: 'Simples Nacional com excesso'},
            {value: 3, text: 'Lucro Real'},
            {value: 4, text: 'Lucro Presumido'}
        ], {$crt}), phoneRow);

        tbody.insertBefore(criarLinhaSelect('Regime Especial (NFS-e)', 'MAIN_INFO_REGIMETRIBUTACAO', [
            {value: 1, text: 'Microempresa Municipal'},
            {value: 2, text: 'Estimativa'},
            {value: 3, text: 'Sociedade de Profissionais'},
            {value: 4, text: 'Cooperativa'},
            {value: 5, text: 'Microempresário Individual (MEI)'},
            {value: 6, text: 'Microempresa ou Empresa de Pequeno Porte (ME/EPP)'}
        ], {$regimeTributacao}), phoneRow);

        tbody.insertBefore(criarLinhaSelect('Incentivo Fiscal (NFS-e)', 'MAIN_INFO_INCENTIVOFISCAL', [
            {value: 1, text: 'Sim'},
            {value: 2, text: 'Não'}
        ], {$incentivoFiscal}), phoneRow);

        // Após um redirect de salvar, o Dolibarr adiciona page_y= na URL
        // e rola a página até a posição salva (via $(document).ready() do lib_foot.js.php).
        // Como nosso DOMContentLoaded dispara DEPOIS do ready() do jQuery, forçamos
        // scroll ao topo aqui para que o formulário de sequências não apareça em tela cheia.
        if (window.location.search.indexOf('page_y=') !== -1) {
            window.scrollTo(0, 0);
        }
    });
})();
</script>
JSBLOCK;

            // ================================================================
            // SCRIPT B: SEÇÃO DE CERTIFICADO/AMBIENTE
            // ================================================================
            // Este script cria um FORMULÁRIO COMPLETO independente após
            // o formulário principal da empresa. Inclui:
            //   - Título "Configuração do ambiente de emissão"
            //   - Form com enctype=multipart/form-data (para upload de .pfx)
            //   - Campos: Ambiente (radio), Certificado (file), Senha (password)
            //   - Badges informativos (certificado/senha já armazenados)
            //   - Toggle de visibilidade da senha
            //   - Confirmação ao mudar para Produção
            $jsCertForm = <<<CERTBLOCK
<script>
/**
 * SCRIPT B — Seção de Certificado Digital / Ambiente
 * Cria um formulário completo após o form principal da empresa.
 */
(function() {
    'use strict';
    document.addEventListener('DOMContentLoaded', function() {

        // ─────────────────────────────────────────────────────────────────
        // PASSO 1: Localizar o formulário principal da empresa
        // ─────────────────────────────────────────────────────────────────
        // Procura pelo input hidden com action="update" que identifica
        // o form principal do Dolibarr em admin/company.php
         var actionInput = document.querySelector('input[type="hidden"][name="action"][value="update"]')
            || document.querySelector('input[type="hidden"][name="action"][value="updateedit"]');
        var mainForm = actionInput ? actionInput.closest('form') : null;
        
        if (!mainForm) return;

        // Container pai onde vamos inserir a nova seção
        var parentContainer = mainForm.parentNode;

        // Ponto de inserção: depois do <br> que segue o form principal
        // Percorre os irmãos após o form até encontrar onde inserir
        var insertRef = mainForm.nextSibling;
        // Pula nós de texto e <br> para posicionar depois deles
        while (insertRef && (insertRef.nodeType === 3 || (insertRef.nodeName && insertRef.nodeName === 'BR'))) {
            insertRef = insertRef.nextSibling;
        }

        // ─────────────────────────────────────────────────────────────────
        // PASSO 2: Variáveis do PHP interpoladas
        // ─────────────────────────────────────────────────────────────────
        var ambiente  = '{$ambiente}';
        var hasPfx    = {$hasPfxJs};
        var hasPass   = {$hasPassJs};
        var csrfToken = '{$csrfToken}';

        // ─────────────────────────────────────────────────────────────────
        // PASSO 3: Construir o HTML da seção de certificado
        // ─────────────────────────────────────────────────────────────────

        // Wrapper principal
        var wrapper = document.createElement('div');
        wrapper.id = 'hook-cert-section';
        wrapper.style.marginTop = '15px';

        // Título da seção (simula load_fiche_titre do Dolibarr)
        var titleDiv = document.createElement('div');
        titleDiv.className = 'titre inline-block';
        titleDiv.style.marginBottom = '12px';
        titleDiv.style.fontSize = '1.1em';
        titleDiv.style.fontWeight = 'bold';
        titleDiv.innerHTML = '<span class="fas fa-cog pictofixedwidth" style="color:#888"></span> Configuração do ambiente de emissão.';
        wrapper.appendChild(titleDiv);

        // Formulário com enctype para upload de arquivo
        var certForm = document.createElement('form');
        certForm.method = 'post';
        certForm.enctype = 'multipart/form-data';
        certForm.action = window.location.pathname;

        // Inputs hidden: CSRF token + action
        var hiddenToken = document.createElement('input');
        hiddenToken.type = 'hidden';
        hiddenToken.name = 'token';
        hiddenToken.value = csrfToken;
        certForm.appendChild(hiddenToken);

        var hiddenAction = document.createElement('input');
        hiddenAction.type = 'hidden';
        hiddenAction.name = 'action';
        hiddenAction.value = 'savesetup';
        certForm.appendChild(hiddenAction);

        // Container responsivo para a tabela
        var tableWrapper = document.createElement('div');
        tableWrapper.className = 'div-table-responsive-no-min';

        var table = document.createElement('table');
        table.className = 'noborder centpercent';

        // ─── SEÇÃO: Ambiente de Emissão ───
        var ambHeader = document.createElement('tr');
        ambHeader.className = 'liste_titre';
        var ambHeaderTd = document.createElement('td');
        ambHeaderTd.colSpan = 2;
        ambHeaderTd.textContent = 'Ambiente de Emissão';
        ambHeader.appendChild(ambHeaderTd);
        table.appendChild(ambHeader);

        var ambRow = document.createElement('tr');
        var ambLabelTd = document.createElement('td');
        ambLabelTd.className = 'titlefield';
        ambLabelTd.textContent = 'Ambiente';
        var ambValueTd = document.createElement('td');

        // Radio: Homologação (valor=2)
        var radioHomolog = document.createElement('input');
        radioHomolog.type = 'radio';
        radioHomolog.name = 'ambiente';
        radioHomolog.id = 'hook_amb_homolog';
        radioHomolog.value = '2';
        if (ambiente === '2') radioHomolog.checked = true;

        var labelHomolog = document.createElement('label');
        labelHomolog.htmlFor = 'hook_amb_homolog';
        labelHomolog.textContent = ' Homologação';

        // Radio: Produção (valor=1)
        var radioProd = document.createElement('input');
        radioProd.type = 'radio';
        radioProd.name = 'ambiente';
        radioProd.id = 'hook_amb_prod';
        radioProd.value = '1';
        if (ambiente === '1') radioProd.checked = true;

        var labelProd = document.createElement('label');
        labelProd.htmlFor = 'hook_amb_prod';
        labelProd.textContent = ' Produção';

        var spacer = document.createTextNode('  \u00a0\u00a0 ');

        ambValueTd.appendChild(radioHomolog);
        ambValueTd.appendChild(labelHomolog);
        ambValueTd.appendChild(spacer);
        ambValueTd.appendChild(radioProd);
        ambValueTd.appendChild(labelProd);

        ambRow.appendChild(ambLabelTd);
        ambRow.appendChild(ambValueTd);
        table.appendChild(ambRow);

        // ─── SEÇÃO: Certificado Digital ───
        var certHeader = document.createElement('tr');
        certHeader.className = 'liste_titre';
        var certHeaderTd = document.createElement('td');
        certHeaderTd.colSpan = 2;
        certHeaderTd.textContent = 'Certificado Digital';
        certHeader.appendChild(certHeaderTd);
        table.appendChild(certHeader);

        // Linha: Upload de arquivo .pfx/.p12
        var fileRow = document.createElement('tr');
        var fileLabelTd = document.createElement('td');
        fileLabelTd.className = 'titlefield';
        fileLabelTd.textContent = 'Arquivo do Certificado (.pfx/.p12)';
        var fileValueTd = document.createElement('td');

        var fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.name = 'cert_pfx_file';
        fileInput.accept = '.pfx,.p12';
        fileValueTd.appendChild(fileInput);

        // Badge: certificado já armazenado
        if (hasPfx) {
            var certBadge = document.createElement('div');
            certBadge.style.marginTop = '8px';
            certBadge.innerHTML = '<span class="badge badge-status4" title="Um certificado está presente no sistema"><i class="fa fa-shield"></i> Certificado armazenado.</span>';
            fileValueTd.appendChild(certBadge);
        }

        fileRow.appendChild(fileLabelTd);
        fileRow.appendChild(fileValueTd);
        table.appendChild(fileRow);

        // Linha: Senha do certificado
        var passRow = document.createElement('tr');
        var passLabelTd = document.createElement('td');
        passLabelTd.textContent = 'Senha do Certificado';
        var passValueTd = document.createElement('td');

        var passInput = document.createElement('input');
        passInput.type = 'password';
        passInput.name = 'cert_pass';
        passInput.id = 'hook_cert_pass';
        passInput.value = '';
        passInput.autocomplete = 'new-password';
        passInput.className = 'minwidth200';

        var toggleLink = document.createElement('a');
        toggleLink.href = '#';
        toggleLink.id = 'hook_togglePass';
        toggleLink.style.marginLeft = '8px';
        toggleLink.style.textDecoration = 'none';
        toggleLink.style.color = '#666';
        toggleLink.title = 'Visualizar Senha';
        toggleLink.innerHTML = '<i class="fa fa-eye"></i>';

        passValueTd.appendChild(passInput);
        passValueTd.appendChild(toggleLink);

        // Informações adicionais sobre a senha
        var passInfoDiv = document.createElement('div');
        passInfoDiv.style.marginTop = '8px';

        if (hasPass) {
            var passBadge = document.createElement('div');
            passBadge.innerHTML = '<span class="badge badge-status4" title="Uma senha do certificado já está armazenada"><i class="fa fa-key"></i> Senha armazenada</span>';
            passInfoDiv.appendChild(passBadge);
        }

        var passHint = document.createElement('div');
        passHint.className = 'opacitymedium';
        passHint.style.marginTop = '6px';
        passHint.style.fontSize = '0.85em';
        passHint.textContent = 'Deixe em branco para manter a senha atual.';
        passInfoDiv.appendChild(passHint);

        passValueTd.appendChild(passInfoDiv);
        passRow.appendChild(passLabelTd);
        passRow.appendChild(passValueTd);
        table.appendChild(passRow);

        // Monta a estrutura
        tableWrapper.appendChild(table);
        certForm.appendChild(tableWrapper);

        // Botão de submit
        var submitDiv = document.createElement('div');
        submitDiv.className = 'center';
        submitDiv.style.marginTop = '12px';
        submitDiv.style.marginBottom = '15px';
        var submitBtn = document.createElement('input');
        submitBtn.type = 'submit';
        submitBtn.className = 'button';
        submitBtn.value = 'Salvar Configurações';
        submitDiv.appendChild(submitBtn);
        certForm.appendChild(submitDiv);

        wrapper.appendChild(certForm);

        // ─────────────────────────────────────────────────────────────────
        // PASSO 4: Inserir no DOM
        // ─────────────────────────────────────────────────────────────────
        if (insertRef) {
            parentContainer.insertBefore(wrapper, insertRef);
        } else {
            parentContainer.appendChild(wrapper);
        }

        // ─────────────────────────────────────────────────────────────────
        // PASSO 5: Event handlers
        // ─────────────────────────────────────────────────────────────────

        // Toggle visibilidade da senha
        document.getElementById('hook_togglePass').addEventListener('click', function(e) {
            e.preventDefault();
            var input = document.getElementById('hook_cert_pass');
            var icon = this.querySelector('i');
            if (input.type === 'password') {
                input.type = 'text';
                icon.className = 'fa fa-eye-slash';
            } else {
                input.type = 'password';
                icon.className = 'fa fa-eye';
            }
        });

        // Confirmação ao mudar para Produção
        var ambRadios = document.querySelectorAll('input[name="ambiente"]');
        for (var i = 0; i < ambRadios.length; i++) {
            ambRadios[i].addEventListener('change', function() {
                if (this.value === '1') {
                    if (!confirm('Tem certeza que deseja alterar o ambiente para Produção?')) {
                        document.getElementById('hook_amb_homolog').checked = true;
                    }
                }
            });
        }
    });
})();
</script>
CERTBLOCK;

            $nfseSeqsJson = addslashes(json_encode($nfseSeqs, JSON_UNESCAPED_UNICODE));
            $nfeSeqsJson  = addslashes(json_encode($nfeSeqs,  JSON_UNESCAPED_UNICODE));

            $jsSequencias = <<<SEQBLOCK
<script>
/**
 * SCRIPT C — Sequências de Emissão (NFS-e / NF-e) — layout em cards grandes
 */
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        var nfseSeqs    = JSON.parse('{$nfseSeqsJson}');
        var nfeSeqs     = JSON.parse('{$nfeSeqsJson}');
        var csrfToken   = '{$csrfToken}';
        var empresaCnpj = '{$cnpjEmpresa}';

        var certSection = document.getElementById('hook-cert-section');
        var parentContainer = certSection ? certSection.parentNode : document.body;
        var insertRef = certSection ? certSection.nextSibling : null;

        // ─────────────────────────────────────────────────────────────
        // utilitários
        // ─────────────────────────────────────────────────────────────
        function makeHidden(name, value) {
            var i = document.createElement('input');
            i.type = 'hidden';
            i.name = name;
            i.value = value !== undefined ? value : '';
            return i;
        }

        function makeNumberInput(name, value, min) {
            var inp = document.createElement('input');
            inp.type = 'number';
            inp.name = name;
            inp.value = value !== undefined ? value : '';
            if (min !== undefined) inp.min = String(min);
            inp.className = 'flat';
            inp.style.cssText =
                'width:100%;max-width:280px;box-sizing:border-box;' +
                'padding:10px 12px;font-size:14px;border-radius:4px;';
            return inp;
        }

        function makeTextInput(name, value) {
            var inp = document.createElement('input');
            inp.type = 'text';
            inp.name = name;
            inp.value = value !== undefined ? value : '';
            inp.className = 'flat';
            inp.style.cssText =
                'width:100%;max-width:280px;box-sizing:border-box;' +
                'padding:10px 12px;font-size:14px;border-radius:4px;';
            return inp;
        }

        function makeEl(tag, style, text) {
            var el = document.createElement(tag);
            if (style) el.style.cssText = style;
            if (text !== undefined) el.textContent = text;
            return el;
        }

        function toInt(v) {
            var n = parseInt(v, 10);
            return isNaN(n) ? 0 : n;
        }

        // ─────────────────────────────────────────────────────────────
        // estilos principais
        // ─────────────────────────────────────────────────────────────
        function makePageCard() {
            return makeEl(
                'div',
                'border:1px solid #d9dfe5;border-radius:8px;background:#fff;' +
                'overflow:hidden;margin-bottom:18px;box-shadow:0 1px 2px rgba(0,0,0,0.03);'
            );
        }

        function makeCardHeader(title) {
            var head = makeEl(
                'div',
                'padding:16px 20px;background:#f6f7f9;border-bottom:1px solid #e3e7eb;' +
                'font-size:16px;font-weight:600;color:#2b2e38;'
            );
            head.textContent = title;
            return head;
        }

        function makeCardBody() {
            return makeEl('div', 'padding:18px 20px 20px 20px;');
        }

        function makeSubCard(title, badgeColor, noteText) {
            var card = makeEl(
                'div',
                'flex:1;min-width:280px;border:1px solid #d9dfe5;border-radius:8px;' +
                'background:#fff;padding:16px 18px;box-sizing:border-box;'
            );

            var badge = makeEl(
                'div',
                'display:inline-block;padding:7px 12px;border-radius:6px;font-size:13px;' +
                'font-weight:600;margin-bottom:18px;color:#2c3340;background:' + badgeColor + ';'
            );
            badge.textContent = title;
            card.appendChild(badge);

            card._body = card;
            card._noteText = noteText || '';
            return card;
        }

        function addField(container, label, inputEl, helperText) {
            var wrap = makeEl('div', 'margin-bottom:18px;');
            var lbl = makeEl(
                'label',
                'display:block;font-size:15px;font-weight:600;color:#2b2e38;margin-bottom:8px;'
            );
            lbl.textContent = label;
            wrap.appendChild(lbl);
            wrap.appendChild(inputEl);

            if (helperText) {
                var helper = makeEl(
                    'div',
                    'margin-top:8px;font-size:13px;color:#667085;line-height:1.4;'
                );
                helper.textContent = helperText;
                wrap.appendChild(helper);
            }

            container.appendChild(wrap);
            return wrap;
        }

        function addNote(container, text) {
            var note = makeEl(
                'div',
                'margin-top:4px;font-size:13px;color:#667085;line-height:1.4;'
            );
            note.textContent = text;
            container.appendChild(note);
            return note;
        }

        // ─────────────────────────────────────────────────────────────
        // wrapper principal
        // ─────────────────────────────────────────────────────────────
        var wrapper = document.createElement('div');
        wrapper.id = 'hook-seq-section';
        wrapper.style.marginTop = '15px';

        var titleDiv = document.createElement('div');
        titleDiv.className = 'titre inline-block';
        titleDiv.style.cssText = 'margin-bottom:8px;font-size:1.1em;font-weight:bold;';
        titleDiv.innerHTML = '<span class="fas fa-list-ol pictofixedwidth" style="color:#888"></span> Sequências de Emissão';
        wrapper.appendChild(titleDiv);

        var subtitle = makeEl(
            'div',
            'margin:0 0 16px 0;color:#667085;font-size:14px;line-height:1.5;',
            'Defina a numeração e a série utilizadas na próxima emissão de cada documento.'
        );
        wrapper.appendChild(subtitle);

        var seqForm = document.createElement('form');
        seqForm.method = 'post';
        seqForm.action = window.location.pathname;
        seqForm.setAttribute('novalidate', '');
        seqForm.appendChild(makeHidden('token', csrfToken));
        seqForm.appendChild(makeHidden('action', 'savesequencias'));

        // ─────────────────────────────────────────────────────────────
        // NFS-e
        // ─────────────────────────────────────────────────────────────
        var seqHomolog = null, seqProd = null;
        nfseSeqs.forEach(function(s) {
            if (String(s.ambiente) === '1') {
                seqProd = s;
            } else {
                seqHomolog = s;
            }
        });

        var nfseCard = makePageCard();
        nfseCard.appendChild(makeCardHeader('Configuração da NFS-e'));

        var nfseBody = makeCardBody();
        nfseBody.appendChild(makeEl(
            'div',
            'margin-bottom:18px;color:#4b5565;font-size:14px;line-height:1.5;',
            'Defina as numerações da NFS-e para cada ambiente.'
        ));

        var nfseGrid = makeEl(
            'div',
            'display:flex;gap:18px;flex-wrap:wrap;align-items:stretch;'
        );

        // Homologação NFS-e
        var nfseHomologCard = makeSubCard('Homologação', '#dbe7f6', 'Usado apenas para testes.');
        nfseHomologCard.appendChild(makeHidden('nfse_seq_id[]', seqHomolog ? seqHomolog.id : ''));
        nfseHomologCard.appendChild(makeHidden('nfse_ambiente[]', '2'));

        var nfseHomologNext = makeNumberInput(
            'nfse_next_dps[]',
            seqHomolog ? seqHomolog.next_dps - 1 : 0,
            0
        );
        addField(
            nfseHomologCard,
            'Último número emitido',
            nfseHomologNext,
            'O próximo documento será emitido com o número seguinte.'
        );

        var nfseHomologSerie = makeTextInput(
            'nfse_serie[]',
            seqHomolog ? seqHomolog.serie : ''
        );
        addField(nfseHomologCard, 'Série', nfseHomologSerie);
        addNote(nfseHomologCard, 'Usado apenas para testes.');

        nfseGrid.appendChild(nfseHomologCard);

        // Produção NFS-e
        var nfseProdCard = makeSubCard('Produção', '#dff3e2', 'Usado em emissões oficiais.');
        nfseProdCard.appendChild(makeHidden('nfse_seq_id[]', seqProd ? seqProd.id : ''));
        nfseProdCard.appendChild(makeHidden('nfse_ambiente[]', '1'));

        var nfseProdNext = makeNumberInput(
            'nfse_next_dps[]',
            seqProd ? seqProd.next_dps - 1 : 0,
            0
        );
        addField(
            nfseProdCard,
            'Último número emitido',
            nfseProdNext,
            'O próximo documento será emitido com o número seguinte.'
        );

        var nfseProdSerie = makeTextInput(
            'nfse_serie[]',
            seqProd ? seqProd.serie : ''
        );
        addField(nfseProdCard, 'Série', nfseProdSerie);
        addNote(nfseProdCard, 'Usado em emissões oficiais.');

        nfseGrid.appendChild(nfseProdCard);

        nfseBody.appendChild(nfseGrid);
        nfseCard.appendChild(nfseBody);
        seqForm.appendChild(nfseCard);

        // ─────────────────────────────────────────────────────────────
        // NF-e
        // ─────────────────────────────────────────────────────────────
        var nfeHomologData = null, nfeProdData = null;
        nfeSeqs.forEach(function(s) {
            if (parseInt(s.ambiente) === 2) nfeHomologData = s;
            if (parseInt(s.ambiente) === 1) nfeProdData    = s;
        });

        var nfeCard = makePageCard();
        nfeCard.appendChild(makeCardHeader('Configuração da NF-e'));

        var nfeBody = makeCardBody();
        nfeBody.appendChild(makeEl(
            'div',
            'margin-bottom:18px;color:#4b5565;font-size:14px;line-height:1.5;',
            'Defina as numerações da NF-e para cada ambiente.'
        ));

        var nfeGrid = makeEl(
            'div',
            'display:flex;gap:18px;flex-wrap:wrap;align-items:stretch;'
        );

        // Homologação NF-e
        var nfeHomologCard = makeSubCard('Homologação', '#dbe7f6', 'Usado apenas para testes.');
        nfeHomologCard.appendChild(makeHidden('nfe_cnpj[]',     nfeHomologData ? nfeHomologData.cnpj : empresaCnpj));
        nfeHomologCard.appendChild(makeHidden('nfe_ambiente[]', '2'));

        var nfeHomologUltimo = makeNumberInput(
            'nfe_ultimo_numero[]',
            nfeHomologData ? nfeHomologData.ultimo_numero : 1,
            1
        );
        addField(
            nfeHomologCard,
            'Último número emitido',
            nfeHomologUltimo,
            'O próximo documento será gerado com o número seguinte.'
        );
        addField(nfeHomologCard, 'Série', makeTextInput('nfe_serie[]', nfeHomologData ? nfeHomologData.serie : '1'));
        addNote(nfeHomologCard, 'Usado apenas para testes.');
        nfeGrid.appendChild(nfeHomologCard);

        // Produção NF-e
        var nfeProdCard = makeSubCard('Produção', '#dff3e2', 'Usado em emissões oficiais.');
        nfeProdCard.appendChild(makeHidden('nfe_cnpj[]',     nfeProdData ? nfeProdData.cnpj : empresaCnpj));
        nfeProdCard.appendChild(makeHidden('nfe_ambiente[]', '1'));

        var nfeProdUltimo = makeNumberInput(
            'nfe_ultimo_numero[]',
            nfeProdData ? nfeProdData.ultimo_numero : 1,
            1
        );
        addField(
            nfeProdCard,
            'Último número emitido',
            nfeProdUltimo,
            'O próximo documento será gerado com o número seguinte.'
        );
        addField(nfeProdCard, 'Série', makeTextInput('nfe_serie[]', nfeProdData ? nfeProdData.serie : '1'));
        addNote(nfeProdCard, 'Usado em emissões oficiais.');
        nfeGrid.appendChild(nfeProdCard);

        nfeBody.appendChild(nfeGrid);
        nfeCard.appendChild(nfeBody);
        seqForm.appendChild(nfeCard);

        // ─────────────────────────────────────────────────────────────
        // rodapé / ações
        // ─────────────────────────────────────────────────────────────
        var actions = makeEl(
            'div',
            'text-align:center;margin-top:12px;margin-bottom:15px;'
        );

        var submitBtn = document.createElement('input');
        submitBtn.type = 'submit';
        submitBtn.className = 'button button-save';
        submitBtn.value = 'Salvar Sequências';

        actions.appendChild(submitBtn);
        seqForm.appendChild(actions);

        // Mensagem de erro inline para CNPJ não preenchido
        var cnpjErrBox = makeEl(
            'div',
            'display:none;background:#fff3cd;color:#856404;border:1px solid #ffc107;' +
            'border-radius:6px;padding:12px 16px;margin-bottom:14px;font-size:14px;line-height:1.5;'
        );
        cnpjErrBox.textContent = '⚠️ Informe o CNPJ da empresa nos dados cadastrais (aba Informações) antes de salvar as sequências.';
        wrapper.appendChild(cnpjErrBox);

        // Validação CNPJ antes de enviar o formulário
        seqForm.addEventListener('submit', function(e) {
            var cnpjLimpo = empresaCnpj ? empresaCnpj.replace(/\D/g, '') : '';
            if (cnpjLimpo.length === 0) {
                e.preventDefault();
                cnpjErrBox.style.display = 'block';
                cnpjErrBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return false;
            }
            cnpjErrBox.style.display = 'none';
        });

        wrapper.appendChild(seqForm);

        if (insertRef) {
            parentContainer.insertBefore(wrapper, insertRef);
        } else {
            parentContainer.appendChild(wrapper);
        }
    });
})();
</script>
SEQBLOCK;
            // ================================================================
            // INJEÇÃO FINAL: Concatena ambos os scripts e insere antes de </body>
            // ================================================================
            $allJs = $jsFiscalFields . "\n" . $jsCertForm . "\n" . $jsSequencias;

            $pos = strripos($html, '</body>');
            if ($pos !== false) {
                $html = substr($html, 0, $pos) . $allJs . substr($html, $pos);
            } else {
                $html .= $allJs;
            }

            return $html;

            } catch (\Throwable $e) {
                dol_syslog('LabApp: ob_start callback error: ' . $e->getMessage(), LOG_ERR);
                return $html;
            }
        });

        return 0;
    }


    /**
     * Handler para ocultar campos na ficha de terceiros (societe/card.php)
     *
     * @param  string $action Ação corrente do formulário
     * @return int    0 = processamento normal
     */
    /**
     * Por que &$action?
     * O Dolibarr passa $action por referência desde card.php → HookManager → doActions.
     * Se a validação falhar, revertemos $action para 'create'/'edit' aqui, e essa
     * mudança propaga de volta para card.php, que então re-exibe o formulário em vez
     * de executar a criação/atualização.
     */
    private function doActionsThirdPartyCard(&$action)
    {
        // ── VALIDAÇÃO PHP SERVER-SIDE ────────────────────────────────────────────
        // Só valida em POST ao criar (add) ou salvar edição (update)
        if (in_array($action, array('add', 'update')) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $missingLabels = array();
            foreach (self::$nativeRequiredFields as $field) {
                $val = GETPOST($field['postKey'], 'alphanohtml');
                if (trim((string)$val) === '') {
                    $missingLabels[] = $field['label'];
                }
            }
            if (!empty($missingLabels)) {
                setEventMessages(
                    'Campos obrigatórios não preenchidos: ' . implode(', ', $missingLabels),
                    null,
                    'errors'
                );
                // Reverte $action para re-exibir o formulário sem criar/alterar o registro
                $action = ($action === 'add') ? 'create' : 'edit';
                return 0;
            }
        }

        // Converte a lista de campos a ocultar para JSON
        $fieldsJson = json_encode(self::$fieldsToHide, JSON_HEX_APOS | JSON_HEX_QUOT);

        // JSON dos campos nativos obrigatórios (para o JS marcar os labels)
        $requiredFieldsJson = json_encode(self::$nativeRequiredFields, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

        // ── Grupos de extrafields a reposicionar, cada um com sua própria âncora ──
        // 'anchor'  → campo nativo de referência (após ele os campos são inseridos)
        // 'fields'  → lista de extrafields a mover, na ordem desejada
        $reorderJson = json_encode(array(
            array(
                'anchor' => array('selector' => '#town',     'viewLabel' => 'Município'),
                'fields' => array(
                    array('selector' => '#options_bairro',             'viewLabel' => 'Bairro'),
                    array('selector' => '#options_numero_de_endereco', 'viewLabel' => 'Número de Endereço'),
                ),
            ),
            array(
                'anchor' => array('selector' => '#idprof1',  'viewLabel' => 'CNPJ'),
                'fields' => array(
                    array('selector' => '#options_regime_tributario', 'viewLabel' => 'Regime Tributário'),
                    // indiedest é sempre posicionado aqui pelo labapp;
                    // a obrigatoriedade/validação é gerenciada pelo módulo NFe.
                    array('selector' => '#options_indiedest',         'viewLabel' => 'Indicador IE Destinatário'),
                ),
            ),
        ), JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);

        ob_start(function ($html) use ($fieldsJson, $reorderJson, $requiredFieldsJson) {

            $script = <<<JSHIDE
<script>
/**
 * HOOK LabApp — Ocultar campos na ficha de terceiros
 *
 * Percorre a lista de campos configurados em ActionsLabapp::\$fieldsToHide
 * e esconde a linha <tr> que contém cada um.
 *
 * Funciona em 3 modos:
 * - Criação (action=create): campos com id no <input>/<textarea>/<select>
 * - Edição (action=edit): idem
 * - Visualização (sem action): campos identificados pelo texto da label <td>
 */
(function() {
    'use strict';
    document.addEventListener('DOMContentLoaded', function() {

        var fieldsToHide = {$fieldsJson};

        for (var i = 0; i < fieldsToHide.length; i++) {
            var field    = fieldsToHide[i];
            var selector = field.selector  || '';
            var label    = field.viewLabel || '';

            // ── MODO EDIÇÃO / CRIAÇÃO ──────────────────────────────────
            // Busca o campo pelo seletor (#id) e esconde a <tr> ancestral
            if (selector) {
                var el = document.querySelector(selector);
                if (el) {
                    var tr = el.closest('tr');
                    if (tr) {
                        tr.style.display = 'none';
                    }
                }

                // Busca também pela label[for="xxx"]
                // (alguns campos têm a label em outra <td> da mesma <tr>)
                var forAttr = selector.replace('#', '');
                var lbl = document.querySelector('label[for="' + forAttr + '"]');
                if (lbl) {
                    var trLabel = lbl.closest('tr');
                    if (trLabel) {
                        trLabel.style.display = 'none';
                    }
                }
            }

            // ── MODO VISUALIZAÇÃO ──────────────────────────────────────
            // No modo view, os campos não têm id — são apenas texto em <td>.
            // Percorremos todas as <td> procurando pelo texto da label.
            if (label) {
                var allTds = document.querySelectorAll('td.titlefield, td.titlefieldmiddle, td.tdtop, table.border td:first-child');
                for (var j = 0; j < allTds.length; j++) {
                    var td = allTds[j];
                    // Compara o texto limpo da <td> com a label configurada
                    var text = (td.textContent || td.innerText || '').trim();
                    if (text === label) {
                        var trView = td.closest('tr');
                        if (trView) {
                            trView.style.display = 'none';
                        }
                    }
                }
            }
        }
    });
})();
</script>
JSHIDE;

            $reorderScript = <<<JSREORDER
<script>
/**
 * HOOK LabApp — Reposicionar extrafields na ficha de terceiros
 *
 * Cada grupo define sua própria âncora e a lista de campos a inserir após ela.
 * Grupos:
 *   1. Bairro + Número → logo abaixo de Cidade
 *   2. Regime Tributário + Ind. IE Dest. → logo abaixo de CNPJ
 */
(function() {
    'use strict';
    document.addEventListener('DOMContentLoaded', function() {

        var groups = {$reorderJson};

        function findRow(selectorOrLabel) {
            // Tenta pelo seletor CSS primeiro
            if (selectorOrLabel.selector) {
                var el = document.querySelector(selectorOrLabel.selector);
                if (el) return el.closest('tr');
            }
            // Fallback: busca pelo texto da label em <td>
            if (selectorOrLabel.viewLabel) {
                var tds = document.querySelectorAll('td');
                for (var k = 0; k < tds.length; k++) {
                    if ((tds[k].textContent || '').trim() === selectorOrLabel.viewLabel) {
                        return tds[k].closest('tr');
                    }
                }
            }
            return null;
        }

        for (var g = 0; g < groups.length; g++) {
            var group = groups[g];
            var anchorRow = findRow(group.anchor);
            if (!anchorRow) continue;

            var insertAfter = anchorRow;

            for (var i = 0; i < group.fields.length; i++) {
                var targetRow = findRow(group.fields[i]);
                if (targetRow && targetRow !== insertAfter) {
                    var next = insertAfter.nextSibling;
                    if (next) {
                        insertAfter.parentNode.insertBefore(targetRow, next);
                    } else {
                        insertAfter.parentNode.appendChild(targetRow);
                    }
                    insertAfter = targetRow;
                }
            }
        }
    });
})();
</script>
JSREORDER;

            $requiredScript = <<<JSREQUIRED
<script>
/**
 * HOOK LabApp — Marcar campos nativos obrigatórios na ficha de terceiros
 *
 * Campos nativos do Dolibarr usam <td class="titlefield"> como célula de label,
 * não <label for="...">. Por isso buscamos o <tr> do input e estilizamos o
 * primeiro <td> da linha, adicionando a classe fieldrequired (negrito nativo
 * do Dolibarr, idêntico ao visual dos extrafields obrigatórios).
 *
 * Também adiciona o atributo HTML "required" no input para validação do browser.
 * Só atua nos modos criação e edição.
 */
(function() {
    'use strict';
    document.addEventListener('DOMContentLoaded', function() {

        // Só age se há formulário editável na página
        var form = document.querySelector('form');
        if (!form) return;
        if (!form.querySelector('input[type="text"], input[type="number"], textarea, select')) return;

        var requiredFields = {$requiredFieldsJson};

        for (var i = 0; i < requiredFields.length; i++) {
            var field = requiredFields[i];
            if (!field.selector) continue;

            var el = document.querySelector(field.selector);
            if (!el) continue;

            // Marca o input como required (validação nativa do browser)
            el.setAttribute('required', 'required');

            // Encontra a <tr> que contém o input
            var tr = el.closest('tr');
            if (!tr) continue;

            // Tenta primeiro label[for] (extrafields e alguns campos nativos usam isso)
            var labeled = null;
            if (field.labelFor) {
                labeled = tr.querySelector('label[for="' + field.labelFor + '"]');
            }

            if (labeled) {
                // Caso normal: existe <label for="..."> — adiciona classe diretamente
                labeled.classList.add('fieldrequired');
            } else {
                // Campos nativos do Dolibarr: o "label" é um <td class="titlefield">
                // Adiciona fieldrequired no <td> para ficar em negrito igual extrafields
                var labelTd = tr.querySelector('td.titlefield, td.titlefieldmiddle, td:first-child');
                if (labelTd) {
                    labelTd.classList.add('fieldrequired');
                }
            }
        }
    });
})();
</script>
JSREQUIRED;

            $allScripts = $script . "\n" . $reorderScript . "\n" . $requiredScript;

            $pos = strripos($html, '</body>');
            if ($pos !== false) {
                $html = substr($html, 0, $pos) . $allScripts . substr($html, $pos);
            } else {
                $html .= $allScripts;
            }

            return $html;
        });

        return 0;
    }
    private function doActionsAdminCard($action)
    {
        // Se não há campos para ocultar, não faz nada
        if (empty(self::$fieldsToHideAdmin)) {
            return 0;
        }

        // Converte a lista PHP para JSON (será usada no JavaScript)
        $fieldsJson = json_encode(self::$fieldsToHideAdmin, JSON_HEX_APOS | JSON_HEX_QUOT);

        ob_start(function ($html) use ($fieldsJson) {

            $script = <<<JSHIDE
<script>
/**
 * HOOK LabApp — Ocultar campos na ficha de terceiros
 *
 * Percorre a lista de campos configurados em ActionsLabapp::\$fieldsToHideAdmin
 * e esconde a linha <tr> que contém cada um.
 *
 * Funciona em 3 modos:
 * - Criação (action=create): campos com id no <input>/<textarea>/<select>
 * - Edição (action=edit): idem
 * - Visualização (sem action): campos identificados pelo texto da label <td>
 */
(function() {
    'use strict';
    document.addEventListener('DOMContentLoaded', function() {

        var fieldsToHide = {$fieldsJson};

        for (var i = 0; i < fieldsToHide.length; i++) {
            var field    = fieldsToHide[i];
            var selector = field.selector  || '';
            var label    = field.viewLabel || '';

            // ── MODO EDIÇÃO / CRIAÇÃO ──────────────────────────────────
            // Busca o campo pelo seletor (#id) e esconde a <tr> ancestral
            if (selector) {
                var el = document.querySelector(selector);
                if (el) {
                    var tr = el.closest('tr');
                    if (tr) {
                        tr.style.display = 'none';
                    }
                }

                // Busca também pela label[for="xxx"]
                // (alguns campos têm a label em outra <td> da mesma <tr>)
                var forAttr = selector.replace('#', '');
                var lbl = document.querySelector('label[for="' + forAttr + '"]');
                if (lbl) {
                    var trLabel = lbl.closest('tr');
                    if (trLabel) {
                        trLabel.style.display = 'none';
                    }
                }
            }

            // ── MODO VISUALIZAÇÃO ──────────────────────────────────────
            // No modo view, os campos não têm id — são apenas texto em <td>.
            // Percorremos todas as <td> procurando pelo texto da label.
            if (label) {
                var allTds = document.querySelectorAll('td.titlefield, td.titlefieldmiddle, td.tdtop, table.border td:first-child');
                for (var j = 0; j < allTds.length; j++) {
                    var td = allTds[j];
                    // Compara o texto limpo da <td> com a label configurada
                    var text = (td.textContent || td.innerText || '').trim();
                    if (text === label) {
                        var trView = td.closest('tr');
                        if (trView) {
                            trView.style.display = 'none';
                        }
                    }
                }
            }
        }
    });
})();
</script>
JSHIDE;

            $pos = strripos($html, '</body>');
            if ($pos !== false) {
                $html = substr($html, 0, $pos) . $script . substr($html, $pos);
            } else {
                $html .= $script;
            }

            return $html;
        });

        return 0;
    }


    /**
     * Insere ou atualiza um registro na tabela llx_nfe_config
     *
     * @param  DoliDB      $this->db    Instância do banco de dados
     * @param  string      $name  Nome da configuração
     * @param  string|null $value Valor a armazenar (null para limpar)
     * @return void
     */
    private static function nfeConfigUpsert($db, $name, $value)
    {
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."nfe_config (name, value) VALUES ('"
            .$db->escape($name)."', "
            .($value === null ? "NULL" : "'".$db->escape($value)."'")
            .") ON DUPLICATE KEY UPDATE value = VALUES(value)";
        $db->query($sql);
    }
    /**
     * Lê configurações relevantes da tabela llx_nfe_config
     *
     * @param  DoliDB $db Instância do banco de dados
     * @return array  Array associativo [nome => valor]
     */
    private static function getNfeConfig($db)
    {
        $cfg = array();
        $res = $db->query(
            "SELECT name, value FROM ".MAIN_DB_PREFIX."nfe_config WHERE name IN ('ambiente','cert_pfx','cert_pass')"
        );
        if ($res) {
            while ($o = $db->fetch_object($res)) {
                $cfg[$o->name] = $o->value;
            }
        }
        return $cfg;
    }



    /**
     * Hook getFormMail — Pré-anexa a DANFSe no formulário de envio de e-mail.
     *
     * Chamado automaticamente pelo HookManager quando FormMail::get_form() é
     * executado no contexto 'formmail'. O objeto $object é a instância FormMail.
     *
     * @param  array       $parameters  Parâmetros do hook (inclui 'trackid')
     * @param  FormMail    $object      Instância do FormMail (pass-by-reference)
     * @param  string      $action      Ação corrente
     * @param  HookManager $hookmanager Instância do gerenciador de hooks
     * @return int                      0 = não substitui, apenas acrescenta
     */


    /**
     * Hook formObjectOptions — Reposiciona extrafields de produto em relação ao campo Descrição.
     *
     * Lê a configuração em $productExtrafieldsOrder e separa os campos em dois grupos:
     *   'before' → inseridos ACIMA  da linha de Descrição
     *   'after'  → inseridos ABAIXO da linha de Descrição (padrão para não configurados)
     *
     * Por que JS e não PHP?
     *   showOptionals() é chamado DEPOIS que todos os campos nativos já foram impressos.
     *   Não há como reinserir HTML já enviado via PHP. A solução sem tocar no core
     *   é reorganizar o DOM no navegador, após o carregamento completo da página.
     *
     * @param  array       $parameters  Parâmetros do hook
     * @param  object      $object      Objeto da página (Product)
     * @param  string      $action      Ação corrente ('create', 'edit', etc.)
     * @param  HookManager $hookmanager Instância do gerenciador de hooks
     * @return int                      0 = não substitui o comportamento padrão
     */
    public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
    {
        $contexts = empty($parameters['context']) ? array() : explode(':', $parameters['context']);

        // Só age na ficha de produto/serviço (criação ou edição)
        if (!array_intersect($contexts, array('productcard', 'productedit'))) {
            return 0;
        }

        // Só age nos modos de formulário editável
        if (!in_array($action, array('create', 'edit', ''))) {
            return 0;
        }

        // Serializa a configuração de ordem para o JavaScript
        $orderJson = json_encode(self::$productExtrafieldsOrder, JSON_UNESCAPED_UNICODE | JSON_FORCE_OBJECT);

        print <<<JS
<script>
/**
 * LabApp — Reposiciona extrafields de produto/serviço em relação ao campo Descrição.
 *
 * Configuração vinda do PHP (self::\$productExtrafieldsOrder):
 *   'after'  = aparece ABAIXO da Descrição (padrão para campos não listados)
 *   'before' = aparece ACIMA  da Descrição
 *
 * Para adicionar ou mover um campo, edite \$productExtrafieldsOrder
 * no topo de actions_labapp.class.php — sem mexer aqui.
 */
(function () {
    'use strict';

    // Mapa campo → posição, gerado pelo PHP
    var fieldOrder = $orderJson;

    document.addEventListener('DOMContentLoaded', function () {

        // ────────────────────────────────────────────────────────────────────
        // 1. Localiza a linha de Descrição (âncora de referência)
        // ────────────────────────────────────────────────────────────────────
        // Tenta pelo textarea (modo sem CKEditor)
        var descEditor = document.querySelector('textarea[name="desc"]');

        // Fallback: CKEditor substitui o textarea por um div
        if (!descEditor) {
            descEditor = document.querySelector('div.cke[id*="desc"]')
                      || document.querySelector('div[id*="cke_desc"]');
        }

        var descRow = descEditor ? descEditor.closest('tr') : null;

        // Segundo fallback: busca pelo texto da label (multi-idioma)
        if (!descRow) {
            var tds = document.querySelectorAll('td.tdtop');
            for (var t = 0; t < tds.length; t++) {
                var txt = (tds[t].textContent || '').trim();
                if (txt === 'Description' || txt === 'Descrição' || txt === 'Descripción') {
                    descRow = tds[t].closest('tr');
                    break;
                }
            }
        }

        if (!descRow) return; // campo Descrição não encontrado — sai sem fazer nada

        // ────────────────────────────────────────────────────────────────────
        // 2. Coleta todos os extrafields e os separa em 'before' e 'after'
        // ────────────────────────────────────────────────────────────────────
        var allRows = document.querySelectorAll('tr[class*="field_options_"]');
        if (!allRows.length) return; // sem extrafields, nada a fazer

        var beforeRows = [];
        var afterRows  = [];

        for (var i = 0; i < allRows.length; i++) {
            var row = allRows[i];

            // Extrai o nome do campo da classe CSS
            // Exemplo: class="oddeven field_options_prd_ncm" → fieldName = "prd_ncm"
            var match = /field_options_([\w-]+)/.exec(row.className);
            var fieldName = match ? match[1] : null;

            // Posição: usa a configuração do PHP; padrão = 'after'
            var pos = (fieldName && fieldOrder[fieldName]) ? fieldOrder[fieldName] : 'after';

            if (pos === 'before') {
                beforeRows.push(row);
            } else {
                afterRows.push(row);
            }
        }

        // ────────────────────────────────────────────────────────────────────
        // 3. Insere os grupos na posição correta
        // ────────────────────────────────────────────────────────────────────

        // Grupo 'before': inserido ACIMA da linha de Descrição (na ordem configurada)
        for (var b = 0; b < beforeRows.length; b++) {
            descRow.parentNode.insertBefore(beforeRows[b], descRow);
        }

        // Grupo 'after': inserido ABAIXO da linha de Descrição (na ordem configurada)
        var anchor = descRow;
        for (var a = 0; a < afterRows.length; a++) {
            anchor.parentNode.insertBefore(afterRows[a], anchor.nextSibling);
            anchor = afterRows[a]; // avança a âncora para preservar ordem
        }
    });
})();
</script>
JS;

        return 0;
    }


    public function getFormMail($parameters, &$object, &$action, $hookmanager)
    {
        $ehNfse = false;
        $ehNfe = false;

        // Só age se o trackid indicar uma fatura: 'inv{id}'
        $trackid = isset($object->trackid) ? $object->trackid : '';
        if (!preg_match('/^inv(\d+)$/', $trackid, $m)) {
            return 0;
        }
        $factureId = (int)$m[1];

        $sqlBuscaNfse = "SELECT * FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas WHERE id_fatura = ".$factureId.";";
        $sqlBuscaNfe = "SELECT * FROM ".MAIN_DB_PREFIX."nfe_emitidas WHERE fk_facture = ".$factureId.";";

        $resBuscaNfse = $this->db->query($sqlBuscaNfse);
        $resBuscaNfe = $this->db->query($sqlBuscaNfe);

        if ($resBuscaNfse && $this->db->num_rows($resBuscaNfse) > 0) {
            $sqlNfse = "SELECT id, numero_nfse, numero_dps, chave_acesso, pdf_danfse
                FROM ".MAIN_DB_PREFIX."nfse_nacional_emitidas
                WHERE id_fatura = ".$factureId."
                  AND pdf_danfse IS NOT NULL
                  AND LENGTH(pdf_danfse) > 0
                ORDER BY id DESC
                LIMIT 1";
            $res = $this->db->query($sqlNfse);
            $row = $this->db->fetch_object($res);
            $pdfContentNfse = $row->pdf_danfse;
        }
        if ($resBuscaNfe && $this->db->num_rows($resBuscaNfe) > 0) {
            $sqlNfe = "SELECT id, chave, numero_nfe, pdf_file
                FROM ".MAIN_DB_PREFIX."nfe_emitidas
                WHERE fk_facture = ".$factureId."
                  AND pdf_file IS NOT NULL
                  AND LENGTH(pdf_file) > 0
                ORDER BY id DESC
                LIMIT 1";
            $resNfe = $this->db->query($sqlNfe);
            $row2 = $this->db->fetch_object($resNfe);
            $pdfContentNfe = $row2->pdf_file;
        }
        

        if (is_resource($pdfContentNfse)) {
            $pdfContentNfse = stream_get_contents($pdfContentNfse);
        }
        if (is_resource($pdfContentNfe)) {
            $pdfContentNfe = stream_get_contents($pdfContentNfe);
        }

        if (empty($pdfContentNfe) && empty($pdfContentNfse)) {
            return 0;
        }

        $chaveNfe = !empty($row2->chave) ? $row2->chave : $row2->chave;
        $chaveNfse = !empty($row->chave_acesso) ? $row->chave_acesso : $row->chave_acesso;
        $filename = $chaveNfse ? 'DANFSE-'. $chaveNfse . '.pdf': 'DANFE-'.$chaveNfe . '.pdf';

        global $conf, $user;
        $vardir    = $conf->user->dir_output . '/' . $user->id;
        $uploadDir = $vardir . '/temp/';
        if (!is_dir($uploadDir)) {
            dol_mkdir($uploadDir);
        }

        $filepath = $uploadDir . $filename;
        file_put_contents($filepath, $pdfContentNfe ? $pdfContentNfe : $pdfContentNfse);

        $object->add_attached_files($filepath, $filename, 'application/pdf');

        return 0;
    }
}
