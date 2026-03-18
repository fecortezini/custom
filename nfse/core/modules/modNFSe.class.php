<?php

require_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

class modNFSe extends DolibarrModules
{
    public $numero       = 500200;
    public $rights_class = 'nfse';
    public $family       = "Nota Fiscal de Serviço Eletrônica";
    public $name         = "NFS-e";
    public $description  = "Módulo para emissão e listagem de NFS-e.";
    public $version      = '1.0';
    public $picto        = 'object_nfse@nfse';  // ← prefixo "object_" obrigatório
    public $rights       = array();
    public $menu         = array();
    public $const_name   = "MAIN_MODULE_NFSE";
    public $hooks        = array();
    public $icon         = 'object_nfse@nfse';  // ← mesmo valor do picto

    public function __construct($db)
    {
        global $langs;
        parent::__construct($db);

        $this->module_parts = array(
            'hooks' => array(
                'invoicecard',
                'productcard',
                'productedit',
                'productlist',
                'invoicelist'
            ),
        );

        $this->config_page_url = array('setup.php@nfse');

        $this->rights[] = array(500201, 'Acessar lista de NFS-e', 'read', 1);

        $i = 0;

        // 1. Menu de Topo (Principal)
        $this->menu[$i] = array(
            'fk_menu'  => '',
            'type'     => 'top',
            'titre'    => 'NFS-e',
            'mainmenu' => 'nfse',
            'url'      => '/custom/nfse/nfse_list.php',
            'langs'    => 'nfse@nfse',
            'position' => 200,
            'enabled'  => 'isModEnabled("nfse")',
            'perms'    => '1',
            'target'   => '',
            'user'     => 2,
            'id'       => 'menu_nfse_top',
            'icon'     => 'object_nfse@nfse',  // ← prefixo "object_" obrigatório
        );
        $i++;

        //2. Submenu - Lista de NFS-e
        $this->menu[$i] = array(
            'fk_menu'   => 'fk_mainmenu=nfse',
            'type'      => 'left',
            'titre'     => 'Lista de NFS-e',
            'mainmenu'  => 'nfse',
            'leftmenu'  => 'nfse_list',
            'url'       => '/custom/nfse/nfse_list.php',
            'langs'     => 'nfse@nfse',
            'position'  => 100,
            'enabled'   => 'isModEnabled("nfse")',
            'perms'     => '1',
            'target'    => '',
            'user'      => 2
        );
        $i++;
        
        //3. Submenu - Nova Emissão
        $this->menu[$i] = array(
            'fk_menu'   => 'fk_mainmenu=nfse',
            'type'      => 'left',
            'titre'     => 'Serviços e Alíquotas',
            'mainmenu'  => 'nfse',
            'leftmenu'  => 'servicos_aliquotas',
            'url'       => '/custom/nfse/servicos_aliquotas.php',
            'langs'     => 'nfse@nfse',
            'position'  => 110,
            'enabled'   => 'isModEnabled("nfse")',
            'perms'     => '1',
            'target'    => '',
            'user'      => 2
        );
        $i++;
    }
    
    private function deleteDefaultServices()
    {
        $this->db->begin();

        try {
            // Remove apenas produtos criados pelo módulo (por padrão, os que têm ref numérica)
            $this->db->query("DELETE e FROM ".MAIN_DB_PREFIX."product_extrafields e 
                              INNER JOIN ".MAIN_DB_PREFIX."product p ON e.fk_object = p.rowid
                              WHERE p.ref REGEXP '^[0-9]+$';");

            $this->db->query("DELETE FROM ".MAIN_DB_PREFIX."product WHERE ref REGEXP '^[0-9]+$';");

            $this->db->commit();
        } catch (Exception $e) {
            $this->db->rollback();
            dol_syslog("Erro ao remover serviços NFS-e: " . $e->getMessage(), LOG_ERR);
        }
    }


    private function deleteExtraFields()
    {
        require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
        $extrafields = new ExtraFields($this->db);
        $fields = [
            'facture' => ['separador_obra', 'separdor_dados_evento', 'inscImobFisc', 'cObra', 'sepador_endereco', 'cep', 'xbairro', 'xlgr', 'nro', 'xnomeevento', 'dtini', 'dtfim', 'muni_prest', 'separador_obra'],
            'product' => ['iss_retido']
        ];

        foreach ($fields as $elementtype => $list) {
            foreach ($list as $field) {
                $extrafields->delete($field, $elementtype);
            }
        }
    }
    private function createExtraFields()
    { 
        // $attrname,
        // $label,
        // $type,
        // $pos,
        // $size,
        // $elementtype,
        // $unique = 0,
        // $required = 0,
        // $default_value = '',
        // $param = '',
        // $alwayseditable = 0,
        // $perms = '',
        // $list = '-1',
        // $help = '',
        // $computed = '',
        // $entity = '',
        // $langfile = '',
        // $enabled = '1',
        // $totalizable = 0,
        // $printable = 0,
        // $moreparams = array()
        require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
        $extrafields = new ExtraFields($this->db);
        // Terceiros
        $extrafields->addExtraField('bairro', 'Bairro', 'varchar', 100, 255, 'societe', 0, 0);
        $extrafields->addExtraField('numero_de_endereco', 'Número de Endereço', 'varchar', 100, 8, 'societe', 0, 0, '', '', 1, '', 1);
        $extrafields->addExtraField('rua', 'Rua', 'varchar', 100, 255, 'societe', 0, 0);

        $servicosOptions = array();
        $sqlSrv = "SELECT codigo, descricao FROM ".MAIN_DB_PREFIX."nfse_codigo_servico ORDER BY CAST(codigo AS UNSIGNED) ASC";
        $resSrv = $this->db->query($sqlSrv);
        if ($resSrv) {
            while ($objSrv = $this->db->fetch_object($resSrv)) {
                $servicosOptions[$objSrv->codigo] = $objSrv->codigo . ' - ' . $objSrv->descricao;
            }
        }
        
        $extrafields->addExtraField(
            'srv_cod_itemlistaservico', 
            'Código do Serviço', 
            'select', 
            100, 
            '', 
            'product',
            0,
            1,
            '',
            serialize(['options' => $servicosOptions]),
            0,
            '',
            '$objectoffield->type == 1 ? 3 : 0'
        );
        $extrafields->addExtraField(
            'iss_retido', 
            'ISS Retido', 
            'select', 
            100, 
            '', 
            'product',
            0,
            1,
            '',
            serialize(['options' => [1 => 'Sim', 2 => 'Não']]),
            1,
            '',
            '$objectoffield->type == 1 ? 3 : 0'
        );
        // Ensure existing DB record gets the correct visibility and alwayseditable
        // $extrafields->updateExtraField(
        //     'iss_retido',
        //     'ISS Retido',
        //     'select',
        //     100,
        //     '',
        //     'product',
        //     0,
        //     0,
        //     '',
        //     serialize(['options' => [1 => 'Sim', 2 => 'Não']]),
        //     1,
        //     '',
        //     '$object->type == 1 ? 3 : 0'
        // );
        
        // Fatura
        //$extrafields->addExtraField('discriminacao', 'Discriminação', 'text', 100, 2000, 'facture', 0, 0, '', '', 1, [], 4, '', '', '', 1, '', 1, 0, 1, []);
        //$extrafields->addExtraField('iss_retido', 'ISS Retido', 'select', 100, '', 'product', 0, 0, '2', serialize(['options' => [1 => 'Sim', 2 => 'Não']]), 1, [], 4, '', '', '', 1, '', 1, 0, 1, []);

        // $attrname,
        // $label,
        // $type,
        // $pos,
        // $size,
        // $elementtype,
        // $unique = 0,
        // $required = 0,
        // $default_value = '',
        // Município de prestação (select com códigos)
        $options = [
            '3200300' => 'Alfredo Chaves',
            '3200359' => 'Alto Rio Novo',
            '3200409' => 'Anchieta',
            '3200508' => 'Apiacá',
            '3200607' => 'Aracruz',
            '3200706' => 'Atílio Vivácqua',
            '3200805' => 'Baixo Guandu',
            '3200904' => 'Barra de São Francisco',
            '3201001' => 'Boa Esperança',
            '3201100' => 'Bom Jesus do Norte',
            '3201159' => 'Brejetuba',
            '3201209' => 'Cachoeiro de Itapemirim',
            '3201308' => 'Cariacica',
            '3201407' => 'Castelo',
            '3201506' => 'Colatina',
            '3201605' => 'Conceição da Barra',
            '3201704' => 'Conceição do Castelo',
            '3201803' => 'Divino de São Lourenço',
            '3201902' => 'Domingos Martins',
            '3202009' => 'Dores do Rio Preto',
            '3202108' => 'Ecoporanga',
            '3202207' => 'Fundão',
            '3202256' => 'Governador Lindenberg',
            '3202306' => 'Guaçuí',
            '3202405' => 'Guarapari',
            '3202454' => 'Ibatiba',
            '3202504' => 'Ibiraçu',
            '3202553' => 'Ibitirama',
            '3202603' => 'Iconha',
            '3202652' => 'Irupi',
            '3202702' => 'Itaguaçu',
            '3202801' => 'Itapemirim',
            '3202900' => 'Itarana',
            '3203007' => 'Iúna',
            '3203056' => 'Jaguaré',
            '3203106' => 'Jerônimo Monteiro',
            '3203130' => 'João Neiva',
            '3203163' => 'Laranja da Terra',
            '3203205' => 'Linhares',
            '3203304' => 'Mantenópolis',
            '3203320' => 'Marataízes',
            '3203346' => 'Marechal Floriano',
            '3203353' => 'Marilândia',
            '3203403' => 'Mimoso do Sul',
            '3203502' => 'Montanha',
            '3203601' => 'Mucurici',
            '3203700' => 'Muniz Freire',
            '3203809' => 'Muqui',
            '3203908' => 'Nova Venécia',
            '3204005' => 'Pancas',
            '3204054' => 'Pedro Canário',
            '3204104' => 'Pinheiros',
            '3204203' => 'Piúma',
            '3204252' => 'Ponto Belo',
            '3204302' => 'Presidente Kennedy',
            '3204351' => 'Rio Bananal',
            '3204401' => 'Rio Novo do Sul',
            '3204500' => 'Santa Leopoldina',
            '3204559' => 'Santa Maria de Jetibá',
            '3204609' => 'Santa Teresa',
            '3204658' => 'São Domingos do Norte',
            '3204708' => 'São Gabriel da Palha',
            '3204807' => 'São José do Calçado',
            '3204906' => 'São Mateus',
            '3204955' => 'São Roque do Canaã',
            '3205002' => 'Serra',
            '3205010' => 'Sooretama',
            '3205036' => 'Vargem Alta',
            '3205069' => 'Venda Nova do Imigrante',
            '3205101' => 'Viana',
            '3205150' => 'Vila Pavão',
            '3205176' => 'Vila Valério',
            '3205200' => 'Vila Velha',
            '3205309' => 'Vitória'
        ];
        $extrafields->addExtraField('muni_prest', 'Município de Prestação', 'select', 100, '', 'facture', 0, 0, '', serialize(['options' => $options]), 1, [], 4, '', '', '', 1, '', 1, 0, 1, []);
        /// inicio dos novos extrafields
        $extrafields->addExtraField('separador_obra', 'Obra', 'separate', 100, '', 'facture', 0, 0, '', 'a:1:{s:7:"options";a:1:{i:1;s:7:"/custom";}}', 1, '', 4, '', '', '', 1, '', 1, 0, 1);
        $extrafields->addExtraField('inscImobFisc', 'Inscrição imobiliária fiscal ', 'varchar', 100, 30, 'facture', 0, 0, '', '', 1, '', 1);
        $extrafields->addExtraField('cObra', 'Número de identificação da obra(CNO ou CEI)', 'varchar', 100, 30, 'facture', 0, 0, '', '', 1, '', 1);
        $extrafields->addExtraField('separdor_dados_evento', 'Evento', 'separate', 100, '', 'facture', 0, 0, '', 'a:1:{s:7:"options";a:1:{i:1;s:7:"/custom";}}', 1, '', 4, '', '', '', 1, '', 1, 0, 1);
        $extrafields->addExtraField('xnomeevento', 'Nome do Evento', 'varchar', 100, 255, 'facture', 0, 0, '', '', 1, '', 1, '', '', '', 1, '', 1, 0, 1);
        $extrafields->addExtraField('dtini', 'Data Inicio', 'date', 100, '', 'facture', 0, 0, '', '', 1, '', 4, '', '', '', 1, '', 1, 0, 1);
        $extrafields->addExtraField('dtfim', 'Data Fim', 'date', 100, '', 'facture', 0, 0, '', '', 1, '', 4, '', '', '', 1, '', 1, 0, 1);
        $extrafields->addExtraField('sepador_endereco', 'Endereço do serviço prestado','separate', 100, '', 'facture', 0, 0, '', 'a:1:{s:7:"options";a:1:{i:1;s:7:"/custom";}}', 1, '', 4, '', '', '', 1, '', 1, 0, 1);
        $extrafields->addExtraField('cep', 'CEP', 'varchar', 100, 8, 'facture', 0, 0, '', '', 1, '', 1);
        $extrafields->addExtraField('xbairro', 'Bairro', 'varchar', 100, 100, 'facture', 0, 0, '', '', 1, '', 1);
        $extrafields->addExtraField('xlgr', 'Rua', 'varchar', 100, 255, 'facture', 0, 0, '', '', 1, '', 1);
        $extrafields->addExtraField('nro', 'Número de Endereço', 'varchar', 100, 8, 'facture', 0, 0, '', '', 1, '', 1);
        /// fim dos novos extrafields
        
     }
    /**
     * Cria as tabelas necessárias no banco usando MAIN_DB_PREFIX.
     * Executa CREATE TABLE IF NOT EXISTS para evitar duplicidade.
     */
    private function createTables()
    {
        $p = defined('MAIN_DB_PREFIX') ? MAIN_DB_PREFIX : 'llx_';
        $sqls = [];

        $sqls[] = "
        CREATE TABLE IF NOT EXISTS {$p}nfe_config (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(50) NOT NULL UNIQUE,
            value LONGBLOB NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

         $sqls[] = "
        CREATE TABLE IF NOT EXISTS {$p}nfse_nacional_sequencias (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cnpj VARCHAR(18) NOT NULL,
            im VARCHAR(50) NOT NULL,
            serie VARCHAR(20) NOT NULL DEFAULT '1',
            next_dps INT UNSIGNED NOT NULL DEFAULT 1,
            ambiente TINYINT(1) NOT NULL DEFAULT 2 COMMENT '1=Producao, 2=Homologacao',
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_cnpj_ambiente (cnpj, ambiente)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
         
        $sqls[] = "
        CREATE TABLE IF NOT EXISTS {$p}nfse_nacional_emitidas (
            id int(11) NOT NULL AUTO_INCREMENT,
            id_fatura int(11) NOT NULL,
            numero_dps varchar(15) DEFAULT NULL,
            serie varchar(5) DEFAULT '1',
            numero_nfse varchar(20) DEFAULT NULL,
            chave_acesso varchar(50) DEFAULT NULL,
            id_dps varchar(100) DEFAULT NULL,
            data_emissao date DEFAULT NULL,
            data_hora_envio datetime DEFAULT NULL,
            data_hora_autorizacao datetime DEFAULT NULL,
            prestador_cnpj varchar(18) DEFAULT NULL,
            prestador_nome varchar(255) DEFAULT NULL,
            tomador_cnpjcpf varchar(18) DEFAULT NULL,
            tomador_nome varchar(255) DEFAULT NULL,
            valor_servicos decimal(15,2) DEFAULT 0.00,
            valor_iss decimal(15,2) DEFAULT 0.00,
            cod_servico varchar(20) DEFAULT NULL,
            descricao_servico text DEFAULT NULL,
            ambiente tinyint(1) DEFAULT 2,
            status varchar(50) DEFAULT 'PENDENTE',
            data_hora_cancelamento datetime DEFAULT NULL,
            mensagem_retorno text DEFAULT NULL,
            xml_enviado longtext DEFAULT NULL ,
            xml_retorno longtext DEFAULT NULL,
            xml_nfse longtext DEFAULT NULL,
            created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            pdf_danfse longblob,
            PRIMARY KEY (id),
            KEY idx_id_fatura (id_fatura),
            KEY idx_numero_dps (numero_dps),
            KEY idx_chave_acesso (chave_acesso),
            KEY idx_status (status),
            KEY idx_prestador_cnpj (prestador_cnpj)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Registro de NFS-e emitidas no padrão nacional';";
        $sqls[] = "
            CREATE TABLE IF NOT EXISTS {$p}nfse_codigo_servico (
                rowid INT AUTO_INCREMENT PRIMARY KEY,
                codigo VARCHAR(10) NOT NULL,
                descricao VARCHAR(255) NOT NULL,
                aliquota_iss DOUBLE(5,2) NOT NULL DEFAULT 0.00,
                UNIQUE KEY codigo (codigo)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        $sqls[]= "
            CREATE TABLE IF NOT EXISTS {$p}nfse_nacional_eventos (
            id int NOT NULL AUTO_INCREMENT PRIMARY KEY,
            id_nfse int NOT NULL,
            tipo_evento varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
            chave_nfse varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
            codigo_motivo int DEFAULT NULL COMMENT 'Código do motivo do evento',
            descricao_motivo text COLLATE utf8mb4_unicode_ci COMMENT 'Descrição do motivo',
            chave_substituta varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Chave da NFS-e substituta (se aplicável)',
            xml_enviado longtext COLLATE utf8mb4_unicode_ci COMMENT 'XML do evento enviado',
            xml_retorno longtext COLLATE utf8mb4_unicode_ci COMMENT 'XML decodificado do evento (se disponivel)',
            json_retorno longtext COLLATE utf8mb4_unicode_ci,
            status_evento varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT 'pendente' COMMENT 'pendente, processado, erro',
            protocolo varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Número do protocolo de processamento',
            data_hora_evento datetime NOT NULL COMMENT 'Data/hora do evento',
            data_hora_processamento datetime DEFAULT NULL COMMENT 'Data/hora do processamento pela SEFAZ',
            mensagem_retorno text COLLATE utf8mb4_unicode_ci COMMENT 'Mensagem de retorno da SEFAZ',
            status_conciliacao ENUM('OK', 'DIVERGENTE', 'NAO_CONFIRMADO', 'INEXISTENTE_NO_GOV') DEFAULT 'NAO_CONFIRMADO',
            ultima_sincronizacao DATETIME NULL,
            created_at timestamp NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        $servicos = [
            ['010101', 'Análise e desenvolvimento de sistemas', 0],
            ['010201', 'Programação', 0],
            ['010301', 'Processamento de dados, textos, imagens, vídeos, páginas eletrônicas, aplicativos e sistemas de informação, entre outros formatos, e congêneres.', 0],
            ['010302', 'Armazenamento ou hospedagem de dados, textos, imagens, vídeos, páginas eletrônicas, aplicativos e sistemas de informação, entre outros formatos, e congêneres.', 0],
            ['010401', 'Elaboração de programas de computadores, inclusive de jogos eletrônicos, independentemente da arquitetura construtiva da máquina em que o programa será executado, incluindo tablets, smartphones e congêneres.', 0],
            ['010501','Licenciamento ou cessão de direito de uso de programas de computação.', 0],
            ['010601', 'Assessoria e consultoria em informática', 0],
            ['010701', 'Suporte técnico em informática, inclusive instalação, configuração e manutenção de programas de computação e bancos de dados.', 0],
            ['010801', 'Planejamento, confecção, manutenção e atualização de páginas eletrônicas.', 0],
            ['010901', 'Disponibilização, sem cessão definitiva, de conteúdos de áudio por meio da internet (exceto a distribuição de conteúdos pelas prestadoras de Serviço de Acesso Condicionado, de que trata a Lei nº 12.485, de 12 de setembro de 2011, sujeita ao ICMS).', 0],
            ['010902', 'Disponibilização, sem cessão definitiva, de conteúdos de vídeo, imagem e texto por meio da internet, respeitada a imunidade de livros, jornais e periódicos (exceto a distribuição de conteúdos pelas prestadoras de Serviço de Acesso Condicionado, de que trata a Lei nº 12.485, de 12 de setembro de 2011, sujeita ao ICMS).', 0],
            ['020101', 'Serviços de pesquisas e desenvolvimento de qualquer natureza', 0],
            ['030201', 'Cessão de direito de uso de marcas e de sinais de propaganda', 0],
            ['030301', 'Exploração de salões de festas, centro de convenções, stands e congêneres, para realização de eventos ou negócios de qualquer natureza', 0],
            ['030302', 'Exploração de escritórios virtuais e congêneres, para realização de eventos ou negócios de qualquer natureza', 0],
            ['030303', 'Exploração de quadras esportivas, estádios, ginásios, canchas e congêneres, para realização de eventos ou negócios de qualquer natureza', 0],
            ['030304', 'Exploração de auditórios, casas de espetáculos e congêneres, para realização de eventos ou negócios de qualquer natureza', 0],
            ['030305', 'Exploração de parques de diversões e congêneres, para realização de eventos ou negócios de qualquer natureza', 0],
            ['030401', 'Locação, sublocação, arrendamento, direito de passagem ou permissão de uso, compartilhado ou não, de ferrovia', 0],
            ['030402', 'Locação, sublocação, arrendamento, direito de passagem ou permissão de uso, compartilhado ou não, de rodovia', 0],
            ['030403', 'Locação, sublocação, arrendamento, direito de passagem ou permissão de uso, compartilhado ou não, de postes, cabos, dutos e condutos de qualquer natureza', 0],
            ['030501', 'Cessão de andaimes, palcos, coberturas e outras estruturas de uso temporário', 0],
            ['040101', 'Medicina', 0],
            ['040102', 'Biomedicina', 0],
            ['040201', 'Análises clínicas e congêneres', 0],
            ['040202', 'Patologia e congêneres', 0],
            ['040203', 'Eletricidade médica (eletroestimulação de nervos e músculos, cardioversão, etc) e congêneres', 0],
            ['040204', 'Radioterapia, quimioterapia e congêneres', 0],
            ['040205', 'Ultrassonografia, ressonância magnética, radiologia, tomografia e congêneres', 0],
            ['040301', 'Hospitais e congêneres', 0],
            ['040302', 'Laboratórios e congêneres', 0],
            ['040303', 'Clínicas, sanatórios, manicômios, casas de saúde, prontos-socorros, ambulatórios e congêneres', 0],
            ['040401', 'Instrumentação cirúrgica', 0],
            ['040501', 'Acupuntura', 0],
            ['040601', 'Enfermagem, inclusive serviços auxiliares', 0],
            ['040701', 'Serviços farmacêuticos', 0],
            ['040801', 'Terapia ocupacional', 0],
            ['040802', 'Fisioterapia', 0],
            ['040803', 'Fonoaudiologia', 0],
            ['040901', 'Terapias de qualquer espécie destinadas ao tratamento físico, orgânico e mental', 0],
            ['041001', 'Nutrição', 0],
            ['041101', 'Obstetrícia', 0],
            ['041201', 'Odontologia', 0],
            ['041301', 'Ortóptica', 0],
            ['041401', 'Próteses sob encomenda', 0],
            ['041501', 'Psicanálise', 0],
            ['041601', 'Psicologia', 0],
            ['041701', 'Casas de repouso e congêneres', 0],
            ['041702', 'Casas de recuperação e congêneres', 0],
            ['041703', 'Creches e congêneres', 0],
            ['041704', 'Asilos e congêneres', 0],
            ['041801', 'Inseminação artificial, fertilização in vitro e congêneres', 0],
            ['041901', 'Bancos de sangue, leite, pele, olhos, óvulos, sêmen e congêneres', 0],
            ['042001', 'Coleta de sangue, leite, tecidos, sêmen, órgãos e materiais biológicos de qualquer espécie', 0],
            ['042101', 'Unidade de atendimento, assistência ou tratamento móvel e congêneres', 0],
            ['042201', 'Planos de medicina de grupo ou individual e convênios para prestação de assistência médica, hospitalar, odontológica e congêneres', 0],
            ['042301', 'Outros planos de saúde que se cumpram através de serviços de terceiros contratados, credenciados, cooperados ou apenas pagos pelo operador do plano mediante indicação do beneficiário', 0],
            ['050101', 'Medicina veterinária', 0],
            ['050102', 'Zootecnia', 0],
            ['050201', 'Hospitais e congêneres, na área veterinária', 0],
            ['050202', 'Clínicas, ambulatórios, prontos-socorros e congêneres, na área veterinária', 0],
            ['050301', 'Laboratórios de análise na área veterinária', 0],
            ['050401', 'Inseminação artificial, fertilização in vitro e congêneres', 0],
            ['050501', 'Bancos de sangue e de órgãos e congêneres', 0],
            ['050601', 'Coleta de sangue, leite, tecidos, sêmen, órgãos e materiais biológicos de qualquer espécie', 0],
            ['050701', 'Unidade de atendimento, assistência ou tratamento móvel e congêneres', 0],
            ['050801', 'Guarda, tratamento, amestramento, embelezamento, alojamento e congêneres', 0],
            ['050901', 'Planos de atendimento e assistência médico-veterinária', 0],
            ['060101', 'Barbearia, cabeleireiros, manicuros, pedicuros e congêneres', 0],
            ['060201', 'Esteticistas, tratamento de pele, depilação e congêneres', 0],
            ['060301', 'Banhos, duchas, sauna, massagens e congêneres', 0],
            ['060401', 'Ginástica, dança, esportes, natação, artes marciais e demais atividades físicas', 0],
            ['060501', 'Centros de emagrecimento, spa e congêneres', 0],
            ['060601', 'Aplicação de tatuagens, piercings e congêneres', 0],
            ['070101', 'Engenharia e congêneres', 0],
            ['070102', 'Agronomia e congêneres', 0],
            ['070103', 'Agrimensura e congêneres', 0],
            ['070104', 'Arquitetura, urbanismo e congêneres', 0],
            ['070105', 'Geologia e congêneres', 0],
            ['070106', 'Paisagismo e congêneres', 0],
            ['070201', 'Execução, por administração, de obras de construção civil, hidráulica ou elétrica e de outras obras semelhantes, inclusive sondagem, perfuração de poços, escavação, drenagem e irrigação, terraplanagem, pavimentação, concretagem e a instalação e montagem de produtos, peças e equipamentos (exceto o fornecimento de mercadorias produzidas pelo prestador de serviços fora do local da prestação dos serviços, que fica sujeito ao ICMS)', 0],
            ['070202', 'Execução, por empreitada ou subempreitada, de obras de construção civil, hidráulica ou elétrica e de outras obras semelhantes, inclusive sondagem, perfuração de poços, escavação, drenagem e irrigação, terraplanagem, pavimentação, concretagem e a instalação e montagem de produtos, peças e equipamentos (exceto o fornecimento de mercadorias produzidas pelo prestador de serviços fora do local da prestação dos serviços, que fica sujeito ao ICMS)', 0],
            ['070301', 'Elaboração de planos diretores, estudos de viabilidade, estudos organizacionais e outros, relacionados com obras e serviços de engenharia', 0],
            ['070302', 'Elaboração de anteprojetos, projetos básicos e projetos executivos para trabalhos de engenharia', 0],
            ['070401', 'Demolição', 0],
            ['070501', 'Reparação, conservação e reforma de edifícios e congêneres (exceto o fornecimento de mercadorias produzidas pelo prestador dos serviços, fora do local da prestação dos serviços, que fica sujeito ao ICMS)', 0],
            ['070502', 'Reparação, conservação e reforma de estradas, pontes, portos e congêneres (exceto o fornecimento de mercadorias produzidas pelo prestador dos serviços, fora do local da prestação dos serviços, que fica sujeito ao ICMS)', 0],
            ['070601', 'Colocação e instalação de tapetes, carpetes, cortinas e congêneres, com material fornecido pelo tomador do serviço', 0],
            ['070602', 'Colocação e instalação de assoalhos, revestimentos de parede, vidros, divisórias, placas de gesso e congêneres, com material fornecido pelo tomador do serviço', 0],
            ['070701', 'Recuperação, raspagem, polimento e lustração de pisos e congêneres', 0],
            ['070801', 'Calafetação', 0],
            ['070901', 'Varrição, coleta e remoção de lixo, rejeitos e outros resíduos quaisquer', 0],
            ['070902', 'Incineração, tratamento, reciclagem, separação e destinação final de lixo, rejeitos e outros resíduos quaisquer', 0],
            ['071001', 'Limpeza, manutenção e conservação de vias e logradouros públicos, parques, jardins e congêneres', 0],
            ['071002', 'Limpeza, manutenção e conservação de imóveis, chaminés, piscinas e congêneres', 0],
            ['071101', 'Decoração', 0],
            ['071102', 'Jardinagem, inclusive corte e poda de árvores', 0],
            ['071201', 'Controle e tratamento de efluentes de qualquer natureza e de agentes físicos, químicos e biológicos', 0],
            ['071301', 'Dedetização, desinfecção, desinsetização, imunização, higienização, desratização, pulverização e congêneres', 0],
            ['071601', 'Florestamento, reflorestamento, semeadura, adubação, reparação de solo, plantio, silagem, colheita, corte e descascamento de árvores, silvicultura, exploração florestal e dos serviços congêneres indissociáveis da formação, manutenção e colheita de florestas, para quaisquer fins e por quaisquer meios', 0],
            ['071701', 'Escoramento, contenção de encostas e serviços congêneres', 0],
            ['071801', 'Limpeza e dragagem de rios, portos, canais, baías, lagos, lagoas, represas, açudes e congêneres', 0],
            ['071901', 'Acompanhamento e fiscalização da execução de obras de engenharia, arquitetura e urbanismo', 0],
            ['072001', 'Aerofotogrametria (inclusive interpretação), cartografia, mapeamento e congêneres', 0],
            ['072002', 'Levantamentos batimétricos, geográficos, geodésicos, geológicos, geofísicos e congêneres', 0],
            ['072003', 'Levantamentos topográficos e congêneres', 0],
            ['072101', 'Pesquisa, perfuração, cimentação, mergulho, perfilagem, concretação, testemunhagem, pescaria, estimulação e outros serviços relacionados com a exploração e explotação de petróleo, gás natural e de outros recursos minerais', 0],
            ['072201', 'Nucleação e bombardeamento de nuvens e congêneres', 0],
            ['080101', 'Ensino regular pré-escolar, fundamental e médio', 0],
            ['080102', 'Ensino regular superior', 0],
            ['080201', 'Instrução, treinamento, orientação pedagógica e educacional, avaliação de conhecimentos de qualquer natureza', 0],
            ['090101', 'Hospedagem em hotéis, hotelaria marítima e congêneres (o valor da alimentação e gorjeta, quando incluído no preço da diária, fica sujeito ao Imposto Sobre Serviços)', 0],
            ['090102', 'Hospedagem em pensões, albergues, pousadas, hospedarias, ocupação por temporada com fornecimento de serviços e congêneres (o valor da alimentação e gorjeta, quando incluído no preço da diária, fica sujeito ao Imposto Sobre Serviços)', 0],
            ['090103', 'Hospedagem em motéis e congêneres (o valor da alimentação e gorjeta, quando incluído no preço da diária, fica sujeito ao Imposto Sobre Serviços)', 0],
            ['090104', 'Hospedagem em apart-service condominiais, flat, apart-hotéis, hotéis residência, residence-service, suite service e congêneres (o valor da alimentação e gorjeta, quando incluído no preço da diária, fica sujeito ao Imposto Sobre Serviços)', 0],
            ['090201', 'Agenciamento e intermediação de programas de turismo, passeios, viagens, excursões, hospedagens e congêneres', 0],
            ['090202', 'Organização, promoção e execução de programas de turismo, passeios, viagens, excursões, hospedagens e congêneres', 0],
            ['090301', 'Guias de turismo', 0],
            ['100101', 'Agenciamento, corretagem ou intermediação de câmbio', 0],
            ['100102', 'Agenciamento, corretagem ou intermediação de seguros', 0],
            ['100103', 'Agenciamento, corretagem ou intermediação de cartões de crédito', 0],
            ['100104', 'Agenciamento, corretagem ou intermediação de planos de saúde', 0],
            ['100105', 'Agenciamento, corretagem ou intermediação de planos de previdência privada', 0],
            ['100201', 'Agenciamento, corretagem ou intermediação de títulos em geral e valores mobiliários', 0],
            ['100202', 'Agenciamento, corretagem ou intermediação de contratos quaisquer', 0],
            ['100301', 'Agenciamento, corretagem ou intermediação de direitos de propriedade industrial, artística ou literária', 0],
            ['100401', 'Agenciamento, corretagem ou intermediação de contratos de arrendamento mercantil (leasing)', 0],
            ['100402', 'Agenciamento, corretagem ou intermediação de contratos de franquia (franchising)', 0],
            ['100403', 'Agenciamento, corretagem ou intermediação de faturização (factoring)', 0],
            ['100501', 'Agenciamento, corretagem ou intermediação de bens móveis ou imóveis, não abrangidos em outros itens ou subitens, por quaisquer meios', 0],
            ['100502', 'Agenciamento, corretagem ou intermediação de bens móveis ou imóveis realizados no âmbito de Bolsas de Mercadorias e Futuros, por quaisquer meios', 0],
            ['100601', 'Agenciamento marítimo', 0],
            ['100701', 'Agenciamento de notícias', 0],
            ['100801', 'Agenciamento de publicidade e propaganda, inclusive o agenciamento de veiculação por quaisquer meios', 0],
            ['100901', 'Representação de qualquer natureza, inclusive comercial', 0],
            ['101001', 'Distribuição de bens de terceiros', 0],
            ['110101', 'Guarda e estacionamento de veículos terrestres automotores', 0],
            ['110102', 'Guarda e estacionamento de aeronaves e de embarcações', 0],
            ['110201', 'Vigilância, segurança ou monitoramento de bens, pessoas e semoventes', 0],
            ['110301', 'Escolta, inclusive de veículos e cargas', 0],
            ['110401', 'Armazenamento, depósito, guarda de bens de qualquer espécie', 0],
            ['110402', 'Carga, descarga, arrumação de bens de qualquer espécie', 0],
            ['120101', 'Espetáculos teatrais', 0],
            ['120201', 'Exibições cinematográficas', 0],
            ['120301', 'Espetáculos circenses', 0],
            ['120401', 'Programas de auditório', 0],
            ['120501', 'Parques de diversões, centros de lazer e congêneres', 0],
            ['120601', 'Boates, taxi-dancing e congêneres', 0],
            ['120701', 'Shows, ballet, danças, desfiles, bailes, óperas, concertos, recitais, festivais e congêneres', 0],
            ['120801', 'Feiras, exposições, congressos e congêneres', 0],
            ['120901', 'Bilhares', 0],
            ['120902', 'Boliches', 0],
            ['120903', 'Diversões eletrônicas ou não', 0],
            ['121001', 'Corridas e competições de animais', 0],
            ['121101', 'Competições esportivas ou de destreza física ou intelectual, com ou sem a participação do espectador', 0],
            ['121201', 'Execução de música', 0],
            ['121301', 'Produção, mediante ou sem encomenda prévia, de eventos, espetáculos, entrevistas, shows, ballet, danças, desfiles, bailes, teatros, óperas, concertos, recitais, festivais e congêneres', 0],
            ['121401', 'Fornecimento de música para ambientes fechados ou não, mediante transmissão por qualquer processo', 0],
            ['121501', 'Desfiles de blocos carnavalescos ou folclóricos, trios elétricos e congêneres', 0],
            ['121601', 'Exibição de filmes, entrevistas, musicais, espetáculos, shows, concertos, desfiles, óperas, competições esportivas, de destreza intelectual ou congêneres', 0],
            ['121701', 'Recreação e animação, inclusive em festas e eventos de qualquer natureza', 0],
            ['130201', 'Fonografia ou gravação de sons, inclusive trucagem, dublagem, mixagem e congêneres', 0],
            ['130301', 'Fotografia e cinematografia, inclusive revelação, ampliação, cópia, reprodução, trucagem e congêneres', 0],
            ['130401', 'Reprografia, microfilmagem e digitalização', 0],
            ['130501', 'Composição gráfica, inclusive confecção de impressos gráficos, fotocomposição, clicheria, zincografia, litografia e fotolitografia, exceto se destinados a posterior operação de comercialização ou industrialização, ainda que incorporados, de qualquer forma, a outra mercadoria que deva ser objeto de posterior circulação, tais como bulas, rótulos, etiquetas, caixas, cartuchos, embalagens e manuais técnicos e de instrução, quando ficarão sujeitos ao ICMS', 0],
            ['140101', 'Lubrificação, limpeza, lustração, revisão, carga e recarga, conserto, restauração, blindagem, manutenção e conservação de máquinas, veículos, aparelhos, equipamentos, motores, elevadores ou de qualquer objeto (exceto peças e partes empregadas, que ficam sujeitas ao ICMS)', 0],
            ['140201', 'Assistência técnica', 0],
            ['140301', 'Recondicionamento de motores (exceto peças e partes empregadas, que ficam sujeitas ao ICMS)', 0],
            ['140401', 'Recauchutagem ou regeneração de pneus', 0],
            ['140501', 'Restauração, recondicionamento, acondicionamento, pintura, beneficiamento, lavagem, secagem, tingimento, galvanoplastia, anodização, corte, recorte, plastificação, costura, acabamento, polimento e congêneres de objetos quaisquer', 0],
            ['140601', 'Instalação e montagem de aparelhos, máquinas e equipamentos, inclusive montagem industrial, prestados ao usuário final, exclusivamente com material por ele fornecido', 0],
            ['140701', 'Colocação de molduras e congêneres', 0],
            ['140801', 'Encadernação, gravação e douração de livros, revistas e congêneres', 0],
            ['140901', 'Alfaiataria e costura, quando o material for fornecido pelo usuário final, exceto aviamento', 0],
            ['141001', 'Tinturaria e lavanderia', 0],
            ['141101', 'Tapeçaria e reforma de estofamentos em geral', 0],
            ['141201', 'Funilaria e lanternagem', 0],
            ['141301', 'Carpintaria', 0],
            ['141302', 'Serralheria', 0],
            ['141401', 'Guincho intramunicipal', 0],
            ['141402', 'Guindaste e içamento', 0],
            ['150101', 'Administração de fundos quaisquer e congêneres', 0],
            ['150102', 'Administração de consórcio e congêneres', 0],
            ['150103', 'Administração de cartão de crédito ou débito e congêneres', 0],
            ['150104', 'Administração de carteira de clientes e congêneres', 0],
            ['150105', 'Administração de cheques pré-datados e congêneres', 0],
            ['150201', 'Abertura de conta-corrente no País, bem como a manutenção da referida conta ativa e inativa', 0],
            ['150202', 'Abertura de conta-corrente no exterior, bem como a manutenção da referida conta ativa e inativa', 0],
            ['150203', 'Abertura de conta de investimentos e aplicação no País, bem como a manutenção da referida conta ativa e inativa', 0],
            ['150204', 'Abertura de conta de investimentos e aplicação no exterior, bem como a manutenção da referida conta ativa e inativa', 0],
            ['150205', 'Abertura de caderneta de poupança no País, bem como a manutenção da referida conta ativa e inativa', 0],
            ['150206', 'Abertura de caderneta de poupança no exterior, bem como a manutenção da referida conta ativa e inativa', 0],
            ['150207', 'Abertura de contas em geral no País, não abrangida em outro subitem, bem como a manutenção das referidas contas ativas e inativas', 0],
            ['150208', 'Abertura de contas em geral no exterior, não abrangida em outro subitem, bem como a manutenção das referidas contas ativas e inativas', 0],
            ['150301', 'Locação de cofres particulares', 0],
            ['150302', 'Manutenção de cofres particulares', 0],
            ['150303', 'Locação de terminais eletrônicos', 0],
            ['150304', 'Manutenção de terminais eletrônicos', 0],
            ['150305', 'Locação de terminais de atendimento', 0],
            ['150306', 'Manutenção de terminais de atendimento', 0],
            ['150307', 'Locação de bens e equipamentos em geral', 0],
            ['150308', 'Manutenção de bens e equipamentos em geral', 0],
            ['150401', 'Fornecimento ou emissão de atestados em geral, inclusive atestado de idoneidade, atestado de capacidade financeira e congêneres', 0],
            ['150501', 'Cadastro, elaboração de ficha cadastral, renovação cadastral e congêneres', 0],
            ['150502', 'Inclusão no Cadastro de Emitentes de Cheques sem Fundos - CCF', 0],
            ['150503', 'Exclusão no Cadastro de Emitentes de Cheques sem Fundos - CCF', 0],
            ['150504', 'Inclusão em quaisquer outros bancos cadastrais', 0],
            ['150505', 'Exclusão em quaisquer outros bancos cadastrais', 0],
            ['150601', 'Emissão, reemissão e fornecimento de avisos, comprovantes e documentos em geral', 0],
            ['150602', 'Abono de firmas', 0],
            ['150603', 'Coleta e entrega de documentos, bens e valores', 0],
            ['150604', 'Comunicação com outra agência ou com a administração central', 0],
            ['150605', 'Licenciamento eletrônico de veículos', 0],
            ['150606', 'Transferência de veículos', 0],
            ['150607', 'Agenciamento fiduciário ou depositário', 0],
            ['150608', 'Devolução de bens em custódia', 0],
            ['150701', 'Acesso, movimentação, atendimento e consulta a contas em geral, por qualquer meio ou processo, inclusive por telefone, fac-símile, internet e telex', 0],
            ['150702', 'Acesso a terminais de atendimento, inclusive vinte e quatro horas', 0],
            ['150703', 'Acesso a outro banco e à rede compartilhada', 0],
            ['150704', 'Fornecimento de saldo, extrato e demais informações relativas a contas em geral, por qualquer meio ou processo', 0],
            ['150801', 'Emissão, reemissão, alteração, cessão, substituição, cancelamento e registro de contrato de crédito', 0],
            ['150802', 'Estudo, análise e avaliação de operações de crédito', 0],
            ['150803', 'Emissão, concessão, alteração ou contratação de aval, fiança, anuência e congêneres', 0],
            ['150804', 'Serviços relativos à abertura de crédito, para quaisquer fins', 0],
            ['150901', 'Arrendamento mercantil (leasing) de quaisquer bens, inclusive cessão de direitos e obrigações, substituição de garantia, alteração, cancelamento e registro de contrato, e demais serviços relacionados ao arrendamento mercantil (leasing)', 0],
            ['151001', 'Serviços relacionados a cobranças em geral, de títulos quaisquer, de contas ou carnês, de câmbio, de tributos e por conta de terceiros, inclusive os efetuados por meio eletrônico, automático ou por máquinas de atendimento', 0],
            ['151002', 'Serviços relacionados a recebimentos em geral, de títulos quaisquer, de contas ou carnês, de câmbio, de tributos e por conta de terceiros, inclusive os efetuados por meio eletrônico, automático ou por máquinas de atendimento', 0],
            ['151003', 'Serviços relacionados a pagamentos em geral, de títulos quaisquer, de contas ou carnês, de câmbio, de tributos e por conta de terceiros, inclusive os efetuados por meio eletrônico, automático ou por máquinas de atendimento', 0],
            ['151004', 'Serviços relacionados a fornecimento de posição de cobrança, recebimento ou pagamento', 0],
            ['151005', 'Serviços relacionados a emissão de carnês, fichas de compensação, impressos e documentos em geral', 0],
            ['151101', 'Devolução de títulos, protesto de títulos, sustação de protesto, manutenção de títulos, reapresentação de títulos, e demais serviços a eles relacionados', 0],
            ['151201', 'Custódia em geral, inclusive de títulos e valores mobiliários', 0],
            ['151301', 'Serviços relacionados a operações de câmbio em geral, edição, alteração, prorrogação, cancelamento e baixa de contrato de câmbio', 0],
            ['151302', 'Serviços relacionados a emissão de registro de exportação ou de crédito', 0],
            ['151303', 'Serviços relacionados a cobrança ou depósito no exterior', 0],
            ['151304', 'Serviços relacionados a emissão, fornecimento e cancelamento de cheques de viagem', 0],
            ['151305', 'Serviços relacionados a fornecimento, transferência, cancelamento e demais serviços relativos a carta de crédito de importação, exportação e garantias recebidas', 0],
            ['151306', 'Serviços relacionados a envio e recebimento de mensagens em geral relacionadas a operações de câmbio', 0],
            ['151401', 'Fornecimento, emissão, reemissão de cartão magnético, cartão de crédito, cartão de débito, cartão salário e congêneres', 0],
            ['151402', 'Renovação de cartão magnético, cartão de crédito, cartão de débito, cartão salário e congêneres', 0],
            ['151403', 'Manutenção de cartão magnético, cartão de crédito, cartão de débito, cartão salário e congêneres', 0],
            ['151501', 'Compensação de cheques e títulos quaisquer', 0],
            ['151502', 'Serviços relacionados a depósito, inclusive depósito identificado, a saque de contas quaisquer, por qualquer meio ou processo, inclusive em terminais eletrônicos e de atendimento', 0],
            ['151601', 'Emissão, reemissão, liquidação, alteração, cancelamento e baixa de ordens de pagamento, ordens de crédito e similares, por qualquer meio ou processo', 0],
            ['151602', 'Serviços relacionados à transferência de valores, dados, fundos, pagamentos e similares, inclusive entre contas em geral', 0],
            ['151701', 'Emissão e fornecimento de cheques quaisquer, avulso ou por talão', 0],
            ['151702', 'Devolução de cheques quaisquer, avulso ou por talão', 0],
            ['151703', 'Sustação, cancelamento e oposição de cheques quaisquer, avulso ou por talão', 0],
            ['151801', 'Serviços relacionados a crédito imobiliário, de avaliação e vistoria de imóvel ou obra', 0],
            ['151802', 'Serviços relacionados a crédito imobiliário, de análise técnica e jurídica', 0],
            ['151803', 'Serviços relacionados a crédito imobiliário, de emissão, reemissão, alteração, transferência e renegociação de contrato', 0],
            ['151804', 'Serviços relacionados a crédito imobiliário, de emissão e reemissão do termo de quitação', 0],
            ['151805', 'Demais serviços relacionados a crédito imobiliário', 0],
            ['160101', 'Serviços de transporte coletivo municipal rodoviário de passageiros', 0],
            ['160102', 'Serviços de transporte coletivo municipal metroviário de passageiros', 0],
            ['160103', 'Serviços de transporte coletivo municipal ferroviário de passageiros', 0],
            ['160104', 'Serviços de transporte coletivo municipal aquaviário de passageiros', 0],
            ['160201', 'Outros serviços de transporte de natureza municipal', 0],
            ['170101', 'Assessoria ou consultoria de qualquer natureza, não contida em outros itens desta lista', 0],
            ['170102', 'Análise, exame, pesquisa, coleta, compilação e fornecimento de dados e informações de qualquer natureza, inclusive cadastro e similares', 0],
            ['170201', 'Datilografia, digitação, estenografia e congêneres', 0],
            ['170202', 'Expediente, secretaria em geral, apoio e infra-estrutura administrativa e congêneres', 0],
            ['170203', 'Resposta audível e congêneres', 0],
            ['170204', 'Redação, edição, revisão e congêneres', 0],
            ['170205', 'Interpretação, tradução e congêneres', 0],
            ['170301', 'Planejamento, coordenação, programação ou organização técnica', 0],
            ['170302', 'Planejamento, coordenação, programação ou organização financeira', 0],
            ['170303', 'Planejamento, coordenação, programação ou organização administrativa', 0],
            ['170401', 'Recrutamento, agenciamento, seleção e colocação de mão-de-obra', 0],
            ['170501', 'Fornecimento de mão-de-obra, mesmo em caráter temporário, inclusive de empregados ou trabalhadores, avulsos ou temporários, contratados pelo prestador de serviço', 0],
            ['170601', 'Propaganda e publicidade, inclusive promoção de vendas, planejamento de campanhas ou sistemas de publicidade, elaboração de desenhos, textos e demais materiais publicitários', 0],
            ['170801', 'Franquia (franchising)', 0],
            ['170901', 'Perícias, laudos, exames técnicos e análises técnicas', 0],
            ['171001', 'Planejamento, organização e administração de feiras, exposições e congêneres', 0],
            ['171002', 'Planejamento, organização e administração de congressos e congêneres', 0],
            ['171101', 'Organização de festas e recepções', 0],
            ['171102', 'Bufê (exceto o fornecimento de alimentação e bebidas, que fica sujeito ao ICMS)', 0],
            ['171201', 'Administração em geral, inclusive de bens e negócios de terceiros', 0],
            ['171301', 'Leilão e congêneres', 0],
            ['171401', 'Advocacia', 0],
            ['171501', 'Arbitragem de qualquer espécie, inclusive jurídica', 0],
            ['171601', 'Auditoria', 0],
            ['171701', 'Análise de Organização e Métodos', 0],
            ['171801', 'Atuária e cálculos técnicos de qualquer natureza', 0],
            ['171901', 'Contabilidade, inclusive serviços técnicos e auxiliares', 0],
            ['172001', 'Consultoria e assessoria econômica ou financeira', 0],
            ['172101', 'Estatística', 0],
            ['172201', 'Cobrança em geral', 0],
            ['172301', 'Assessoria, análise, avaliação, atendimento, consulta, cadastro, seleção, gerenciamento de informações, administração de contas a receber ou a pagar e em geral, relacionados a operações de faturização (factoring)', 0],
            ['172401', 'Apresentação de palestras, conferências, seminários e congêneres', 0],
            ['172501', 'Inserção de textos, desenhos e outros materiais de propaganda e publicidade, em qualquer meio (exceto em livros, jornais, periódicos e nas modalidades de serviços de radiodifusão sonora e de sons e imagens de recepção livre e gratuita)', 0],
            ['180101', 'Serviços de regulação de sinistros vinculados a contratos de seguros e congêneres', 0],
            ['180102', 'Serviços de inspeção e avaliação de riscos para cobertura de contratos de seguros e congêneres', 0],
            ['180103', 'Serviços de prevenção e gerência de riscos seguráveis e congêneres', 0],
            ['190101', 'Serviços de distribuição e venda de bilhetes e demais produtos de loteria, cartões, pules ou cupons de apostas, sorteios, prêmios, inclusive os decorrentes de títulos de capitalização e congêneres', 0],
            ['190102', 'Serviços de distribuição e venda de bingos e congêneres', 0],
            ['200101', 'Serviços portuários, ferroportuários, utilização de porto, movimentação de passageiros, reboque de embarcações, rebocador escoteiro, atracação, desatracação, serviços de praticagem, capatazia, armazenagem de qualquer natureza, serviços acessórios, movimentação de mercadorias, serviços de apoio marítimo, de movimentação ao largo, serviços de armadores, estiva, conferência, logística e congêneres (prestado em terra)', 0],
            ['200102', 'Serviços portuários, ferroportuários, utilização de porto, movimentação de passageiros, reboque de embarcações, rebocador escoteiro, atracação, desatracação, serviços de praticagem, capatazia, armazenagem de qualquer natureza, serviços acessórios, movimentação de mercadorias, serviços de apoio marítimo, de movimentação ao largo, serviços de armadores, estiva, conferência, logística e congêneres (prestado em águas marinhas)', 0],
            ['200201', 'Serviços aeroportuários, utilização de aeroporto, movimentação de passageiros, armazenagem de qualquer natureza, capatazia, movimentação de aeronaves, serviços de apoio aeroportuários, serviços acessórios, movimentação de mercadorias, logística e congêneres', 0],
            ['200301', 'Serviços de terminais rodoviários, ferroviários, metroviários, movimentação de passageiros, mercadorias, inclusive suas operações, logística e congêneres', 0],
            ['210101', 'Serviços de registros públicos, cartorários e notariais', 0],
            ['220101', 'Serviços de exploração de rodovia mediante cobrança de preço ou pedágio dos usuários, envolvendo execução de serviços de conservação, manutenção, melhoramentos para adequação de capacidade e segurança de trânsito, operação, monitoração, assistência aos usuários e outros serviços definidos em contratos, atos de concessão ou de permissão ou em normas oficiais', 0],
            ['230101', 'Serviços de programação e comunicação visual e congêneres', 0],
            ['230102', 'Serviços de desenho industrial e congêneres', 0],
            ['240101', 'Serviços de chaveiros, confecção de carimbos e congêneres', 0],
            ['240102', 'Serviços de placas, sinalização visual, banners, adesivos e congêneres', 0],
            ['250101', 'Funerais, inclusive fornecimento de caixão, urna ou esquifes; aluguel de capela; transporte do corpo cadavérico; fornecimento de flores, coroas e outros paramentos; desembaraço de certidão de óbito; fornecimento de véu, essa e outros adornos; embalsamento, embelezamento, conservação ou restauração de cadáveres', 0],
            ['250201', 'Translado intramunicipal de corpos e partes de corpos cadavéricos', 0],
            ['250202', 'Cremação de corpos e partes de corpos cadavéricos', 0],
            ['250301', 'Planos ou convênio funerários', 0],
            ['250401', 'Manutenção e conservação de jazigos e cemitérios', 0],
            ['250501', 'Cessão de uso de espaços em cemitérios para sepultamento', 0],
            ['260101', 'Serviços de coleta, remessa ou entrega de correspondências, documentos, objetos, bens ou valores, inclusive pelos correios e suas agências franqueadas', 0],
            ['260102', 'Serviços de courrier e congêneres', 0],
            ['270101', 'Serviços de assistência social', 0],
            ['280101', 'Serviços de avaliação de bens e serviços de qualquer natureza', 0],
            ['290101', 'Serviços de biblioteconomia', 0],
            ['300101', 'Serviços de biologia e biotecnologia', 0],
            ['300102', 'Serviços de química', 0],
            ['310101', 'Serviços técnicos em edificações e congêneres', 0],
            ['310102', 'Serviços técnicos em eletrônica, eletrotécnica e congêneres', 0],
            ['310103', 'Serviços técnicos em mecânica e congêneres', 0],
            ['310104', 'Serviços técnicos em telecomunicações e congêneres', 0],
            ['320101', 'Serviços de desenhos técnicos', 0],
            ['330101', 'Serviços de desembaraço aduaneiro, comissários, despachantes e congêneres', 0],
            ['340101', 'Serviços de investigações particulares, detetives e congêneres', 0],
            ['350101', 'Serviços de reportagem e jornalismo', 0],
            ['350102', 'Serviços de assessoria de imprensa', 0],
            ['350103', 'Serviços de relações públicas', 0],
            ['360101', 'Serviços de meteorologia', 0],
            ['370101', 'Serviços de artistas, atletas, modelos e manequins', 0],
            ['380101', 'Serviços de museologia', 0],
            ['390101', 'Serviços de ourivesaria e lapidação (quando o material for fornecido pelo tomador do serviço)', 0],
            ['400101', 'Obras de arte sob encomenda', 0],
            ['990101', 'Serviços sem a incidência de ISSQN e ICMS', 0],
        ];


        foreach ($servicos as $s) {
            $codigo = $this->db->escape($s[0]);
            $descricao = $this->db->escape($s[1]);
            $aliquota = floatval($s[2]);
            $sqls[] = "INSERT IGNORE INTO {$p}nfse_codigo_servico (codigo, descricao, aliquota_iss)
                    VALUES ('{$codigo}', '{$descricao}', '{$aliquota}')";
        }
        foreach ($sqls as $s) {
            $res = $this->db->query($s);
            if (!$res) {
                dol_syslog("modNFSe::createTables() ERRO ao executar SQL: ".$this->db->lasterror()."\nSQL: ".substr($s, 0, 200), LOG_ERR);
            }
        }
    }

    public function init($options = '')
    {
        $sql = array();
        require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
        $extrafields = new ExtraFields($this->db);
        
        // Cria tabelas do módulo automaticamente ao ativar (usa MAIN_DB_PREFIX)
        $this->createTables();

        // Corrige tabela de eventos caso já exista sem AUTO_INCREMENT no campo id
        // (necessário para instalações feitas antes desta correção)
        $p = defined('MAIN_DB_PREFIX') ? MAIN_DB_PREFIX : 'llx_';
        $this->db->query("ALTER TABLE {$p}nfse_nacional_eventos MODIFY COLUMN id INT NOT NULL AUTO_INCREMENT PRIMARY KEY");
 
        // Cria extrafields e serviços
        $this->createExtraFields();
        //$this->createDefaultServices();
 
        return $this->_init([], $options);
    }
 
    public function remove($options = '')
    {
        // Deleta serviços e extrafields criados
        //$this->deleteDefaultServices();
        $this->deleteExtraFields();

        return $this->_remove([], $options);
    }
}