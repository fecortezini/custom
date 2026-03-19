<?php

require_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

class modNFe extends DolibarrModules
{
    public $numero       = 500100;
    public $rights_class = 'nfe';
    public $family       = "Nota Fiscal Eletronica";
    public $name         = "NFe";
    public $description  = "Módulo para emissão e listagem de NF-e.";
    public $version      = '1.0';
    public $picto        = 'nfe@nfe';
    public $rights       = array();
    public $menu         = array();
    public $const_name   = "MAIN_MODULE_NFE";
    public $hooks        = array();
    
    public function __construct($db)
    {
        global $langs;
        parent::__construct($db);
        
        // Compatibilidade com hooks antigos baseados em página
        $this->hooks = array(
            'facture' => array(
                'doActions',
                'addActionButtons'
            )
        );

        // Definição única de module_parts
        $this->module_parts = array(
            'hooks' => array(
                // Faturas (que já funcionam)
                'invoicecard',
                'facturecard'
            )
        );
        $this->config_page_url = array('setup_module.php@nfe');
        $this->rights[] = array(500101, 'Acessar lista de NF-e', 'read', 1);        
        $i = 0;

        // 1. Menu de Topo (Principal)
        $this->menu[$i] = array(
            'fk_menu'   => '',
            'type'      => 'top',
            'titre'     => 'Nota Fiscal Eletrônica',
            'mainmenu'  => 'nfe',
            'url'       => '/custom/nfe/list.php', // Aponta para a página de listagem
            'langs'     => 'nfe@nfe',
            'position'  => 100,
            'enabled'   => 'isModEnabled("nfe")',
            'perms'     => '1',
            'target'    => '',
            'user'      => 2,
            'id'        => 'menu_nfe_top'
        );
        $i++;

    
        $this->menu[$i] = array(
            'fk_menu'   => 'fk_mainmenu=nfe',
            'type'      => 'left',
            'titre'     => 'Inutilização',
            'mainmenu'  => 'nfe',
            'leftmenu'  => 'nfe_inutilizacao',
            'url'       => '/custom/nfe/inutilizacao_nfe.php',
            'langs'     => 'nfe@nfe',
            'position'  => 25,
            'enabled'   => '1',
            'perms'     => '$user->admin',
            'target'    => '',
            'user'      => 2
        );
        $i++;

        $this->menu[$i] = array(
            'fk_menu'   => 'fk_mainmenu=nfe',
            'type'      => 'left',
            'titre'     => 'Lista de Inutilizadas',
            'mainmenu'  => 'nfe',
            'leftmenu'  => 'nfe_inutilizadas',
            'url'       => '/custom/nfe/list_inutilizadas.php',
            'langs'     => 'nfe@nfe',
            'position'  => 1030,
            'enabled'   => '1',
            'perms'     => '1',
            'target'    => '',
            'user'      => 2,
            'id'        => 'menu_nfe_inutilizadas'
        );
        $i++;

        
        $this->menu[$i] = array(
            'fk_menu'   => 'fk_mainmenu=nfe',
            'type'      => 'left',
            'titre'     => 'Regras Fiscais',
            'mainmenu'  => 'nfe',
            'leftmenu'  => 'nfe_tax_rules',
            'url'       => '/custom/nfe/regras_fiscais.php',
            'langs'     => 'nfe@nfe',
            'position'  => 50,
            'enabled'   => 'isModEnabled("nfe")',
            'perms'     => '$user->admin',
            'target'    => '',
            'user'      => 2,
            'id'        => 'menu_nfe_tax_rules'
        );
        $i++;
    }

    public function createTables($db){
        $p = defined('MAIN_DB_PREFIX') ? MAIN_DB_PREFIX : 'llx_';
        $sqls = [];
        $sqls[] = "CREATE TABLE IF NOT EXISTS " . $p . "nfe_inutilizadas (
            id INT NOT NULL AUTO_INCREMENT,
            serie INT NOT NULL,
            numero_inicial INT NOT NULL,
            numero_final INT NOT NULL,
            justificativa TEXT NOT NULL,
            protocolo varchar(255) NOT NULL,
            data_inutilizacao DATETIME DEFAULT CURRENT_TIMESTAMP,
            xml_resposta LONGTEXT NULL,
            PRIMARY KEY (id)
            )";
        $sqls[] ="CREATE TABLE IF NOT EXISTS " . $p . "custom_tax_rules2 (
            rowid INT AUTO_INCREMENT PRIMARY KEY,
            label VARCHAR(255) NOT NULL,
            active TINYINT(1) DEFAULT '1',
            date_start DATE DEFAULT NULL,
            date_end DATE DEFAULT NULL,
            uf_origin VARCHAR(2) NOT NULL,
            uf_dest VARCHAR(2) NOT NULL,
            cfop VARCHAR(4) NOT NULL,
            ncm VARCHAR(8) DEFAULT NULL,
            icms_aliq_interna DECIMAL(5,2) DEFAULT '0.00',
            icms_aliq_interestadual DECIMAL(5,2) DEFAULT '0.00',
            icms_cred_aliq DECIMAL(5,2) DEFAULT '0.00',            
            icms_st_mva DECIMAL(7,4) DEFAULT '0.0000',
            icms_st_aliq DECIMAL(5,2) DEFAULT '0.00',
            icms_st_red_bc DECIMAL(5,2) DEFAULT '0.00',            
            difal_aliq_fcp DECIMAL(5,2) DEFAULT '0.00',            
            pis_cst VARCHAR(2) DEFAULT '49',
            pis_aliq DECIMAL(5,2) DEFAULT '0.00',
            cofins_cst VARCHAR(2) DEFAULT '49',
            cofins_aliq DECIMAL(5,2) DEFAULT '0.00',            
            ipi_cst VARCHAR(2) DEFAULT NULL,
            ipi_aliq DECIMAL(5,2) DEFAULT '0.00',
            ipi_cenq VARCHAR(3) DEFAULT '999',  
            fk_user_create INT DEFAULT NULL,
            fk_user_modify INT DEFAULT NULL,
            date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
            date_modification DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP, 
            KEY idx_busca_principal (`uf_origin`, `uf_dest`, `cfop`, `ncm`, `active`),
            KEY idx_vigencia (`date_start`, `date_end`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $sqls[] = "CREATE TABLE IF NOT EXISTS " . $p . "custom_tax_rules (
            `rowid` INT AUTO_INCREMENT PRIMARY KEY,
            `label` varchar(255) NOT NULL,
            `active` tinyint(1) DEFAULT '1',
            `cfop` varchar(4) DEFAULT NULL,
            `ncm` varchar(8) DEFAULT NULL,
            `uf_origin` varchar(2) NOT NULL,
            `uf_dest` varchar(2) NOT NULL,
            `crt_emitter` int NOT NULL,
            `indiedest_recipient` int DEFAULT NULL,
            `product_origin` int NOT NULL DEFAULT '0',
            `icms_csosn` varchar(3) DEFAULT NULL,
            `pis_cst` varchar(2) DEFAULT NULL,
            `pis_aliq` decimal(5,2) DEFAULT '0.00',
            `cofins_cst` varchar(2) DEFAULT NULL,
            `cofins_aliq` decimal(5,2) DEFAULT '0.00',
            `ipi_cst` varchar(2) DEFAULT NULL,
            `ipi_aliq` decimal(5,2) DEFAULT '0.00',
            `ipi_cenq` varchar(3) DEFAULT '999',
            `icms_st_mva` decimal(7,4) DEFAULT '0.0000',
            `icms_st_aliq` decimal(5,2) DEFAULT '0.00',
            `icms_st_predbc` decimal(5,2) DEFAULT '0.00',
            icms_interestadual_aliq decimal(5,2) DEFAULT '0.00',
            icms_cred_aliq decimal(5,2) DEFAULT '0.00',
            aliq_interna_dest decimal(5,2) DEFAULT '0.00'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $sqls[] = "CREATE TABLE IF NOT EXISTS " . $p . "nfe_config (
            rowid INT(11) NOT NULL AUTO_INCREMENT,
            name VARCHAR(100) NOT NULL,
            value LONGBLOB NOT NULL,
            PRIMARY KEY (rowid),
            UNIQUE KEY name_unique (name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $sqls[] = "CREATE TABLE IF NOT EXISTS " . $p . "nfe_perm_credito (
            rowid INT(11) NOT NULL AUTO_INCREMENT,
            aliq_cred_perm decimal(5,2),
            mes_referencia VARCHAR(7) NOT NULL COMMENT 'Formato YYYY-MM',
            fk_user_create INT DEFAULT NULL,
            fk_user_modify INT DEFAULT NULL,
            date_creation DATETIME DEFAULT CURRENT_TIMESTAMP,
            date_modification DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (rowid),
            UNIQUE KEY idx_mes_referencia (mes_referencia)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
        $sqls[] = "CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."nfe_sequencia (
            cnpj VARCHAR(20) NOT NULL,
            serie INT NOT NULL,
            ambiente TINYINT(1) NOT NULL DEFAULT 2 COMMENT '1=Producao, 2=Homologacao',
            ultimo_numero INT NOT NULL DEFAULT 65,
            PRIMARY KEY (cnpj, serie, ambiente)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $sqls[] = "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "nfe_emitidas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fk_facture INT,
            fk_nfe_origem INT NULL,
            chave VARCHAR(44) NULL,
            protocolo VARCHAR(255) NULL,
            numero_nfe INT NOT NULL,
            serie INT NOT NULL,
            status VARCHAR(20) NOT NULL,
            motivo_status TEXT NULL,
            xml_completo LONGTEXT NULL,
            pdf_file LONGBLOB NULL,
            data_emissao DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $sqls[] = "
        CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "nfe_eventos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fk_nfe_emitida INT NOT NULL,
            tpEvento VARCHAR(6) NOT NULL,
            nSeqEvento INT NOT NULL,
            protocolo_evento VARCHAR(255) NOT NULL,
            motivo_evento TEXT NULL,
            data_evento DATETIME NOT NULL,
            xml_requisicao LONGTEXT NULL,
            xml_resposta LONGTEXT NULL,
            xml_evento_completo LONGTEXT NULL,
            KEY idx_fk_nfe_emitida (fk_nfe_emitida)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        foreach ($sqls as $sql) {
            $resql = $db->query($sql);
            if (!$resql) {
                dol_print_error($db);
                return -1;
            }
        }
        
    }

    public function init($options = '')
    {
        $sql = array();
        require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
        $extrafields = new ExtraFields($this->db);
        // Extrafields para Sociedades (Terceiros)
        // $extrafields->addExtraField('bairro', 'Bairro', 'varchar', 100, 255, 'societe', 0, 0);
        // $extrafields->addExtraField('numero_de_endereco', 'Número de Endereço', 'int', 100, 8, 'societe', 0, 0);
        // $extrafields->addExtraField('rua', 'Rua', 'varchar', 100, 255, 'societe', 0, 0);
		// $extrafields->addExtraField('codigo_do_municipio', 'Codigo do Municipio', 'int', 100, 10, 'societe', 0, 0, '3201209');
        // $extrafields->addExtraField('regime_tributario', 'Regime Tributário', 'select', 100, '', 'societe', 0, 0, '', serialize(['options' => [1 => 'Simples Nacional', 2 => 'Simples Nacional c/ excesso', 3 => 'Lucro Presumido', 4 => 'Lucro Real']]), 1, [], 1, '', '', '', 1, '', 1, 0, 1, []);
        // Extrafields para Produtos E Serviços
        // $extrafields->addExtraField('prd_origem', 'Origem da Mercadoria', 'select', 100, '', 'product', 0, 0, '', serialize(['options' => [1 => '0 - Nacional, exceto as indicadas nos códigos 3, 4, 5 e 8', 2 => '1 - Estrangeira - Importação direta, exceto a indicada no código 6.', 3 => '2 - Estrangeira - Adquirida no mercado interno, exceto a indicada no código 7.', 4 => '3 - Nacional, mercadoria ou bem com conteúdo de importação superior a 40% e inferior ou igual a 70%.', 5 => '4 - Nacional, cuja produção tenha sido feita em conformidade com os processos produtivos básicos (PPB).', 6 => '5 - Nacional, mercadoria ou bem com conteúdo de importação inferior ou igual a 40%.', 7 => '6 - Estrangeira - Importação direta, sem similar nacional, constante em lista de Resolução CAMEX e gás natural.', 8 => '7 - Estrangeira - Adquirida no mercado interno, sem similar nacional, constante em lista de Resolução CAMEX e gás natural.', 9 => '8 - Nacional, mercadoria ou bem com conteúdo de importação superior a 70%.']]), 0, '', '$object->type == 0 ? 3 : 0', 0, '');
        $extrafields->addExtraField(
            'prd_origem',
            'Origem da Mercadoria',
            'select',
            100,
            '',
            'product',
            0,
            0,
            '',
            serialize(['options' => [
                '0' => '0 - Nacional, exceto as indicadas nos códigos 3, 4, 5 e 8',
                '1' => '1 - Estrangeira - Importação direta, exceto a indicada no código 6.',
                '2' => '2 - Estrangeira - Adquirida no mercado interno, exceto a indicada no código 7.',
                '3' => '3 - Nacional, mercadoria ou bem com conteúdo de importação superior a 40% e inferior ou igual a 70%.',
                '4' => '4 - Nacional, cuja produção tenha sido feita em conformidade com os processos produtivos básicos (PPB).',
                '5' => '5 - Nacional, mercadoria ou bem com conteúdo de importação inferior ou igual a 40%.',
                '6' => '6 - Estrangeira - Importação direta, sem similar nacional, constante em lista de Resolução CAMEX e gás natural.',
                '7' => '7 - Estrangeira - Adquirida no mercado interno, sem similar nacional, constante em lista de Resolução CAMEX e gás natural.',
                '8' => '8 - Nacional, mercadoria ou bem com conteúdo de importação superior a 70%.'
            ]]),
            0,
            '',
            '$objectoffield->type == 0 ? 3 : 0'
        );
        $extrafields->addExtraField('prd_fornecimento', 'Natureza do fornecimento', 'select', 100, '', 'product', 0, 1, '', serialize(['options' => [1 => 'Produção Própria', 2 => 'Adquirido/Revenda']]), 1, '', '$objectoffield->type == 0 ? 3 : 0',);
        $extrafields->addExtraField('prd_ncm', 'NCM', 'varchar', 100, 255, 'product', 0, 0, '', '', 1, '', '$objectoffield->type == 0 ? 3 : 0');
		//$extrafields->addExtraField('prd_cest', 'CEST', 'varchar', 100, 255, 'product', 0, 0, '', '', 1, '', '$objectoffield->type == 0 ? 3 : 0');
        $extrafields->addExtraField('prd_regime_icms', 'Regime de Tributação (ICMS)', 'select', 100, '', 'product', 0, 0, '', serialize(['options' => [1 => 'Tributado Normalmente', 2 => 'Substituto Tributário (Responsável pelo recolhimento do ST)', 3 => 'Substituído Tributário (ST recolhido anteriormente na cadeia)', 4 => 'Isento de ICMS', 5 => 'Não Tributado pelo ICMS', 6 => 'Suspensão do ICMS']]), 1, '', '$objectoffield->type == 0 ? 3 : 0');

        // Extrafields para Faturas
        $extrafields->addExtraField('indpres', 'Indicador de presença', 'select', 100, '', 'facture', 0, 0, '1', serialize(['options' => ['1' => 'Operação Presencial', '2' => 'Não presencial pela Internet', '3' => 'Não presencial', '4' => 'Outros']]), 1, '', 4);
        // $extrafields->addExtraField('indpres', 'Indicador de presença', 'select', 100, '', 'facture', 0, 0, '', 'a:1:{s:7:"options";a:4:{i:1;s:22:"Operação Presencial";i:2;s:30:" Não presencial pela Internet";i:3;s:16:" Não presencial";i:4;s:7:" Outros";}}', 1, '', 4, '', '', 1, 'pt_BR', 1, 0, 1, []);
        //$extrafields->addExtraField('nat_op_sv', 'Natureza da Operação', 'select', 100, '', 'facture', 0, 0, '', 'a:1:{s:7:"options";a:7:{i:1;s:12:" Tributável";i:2;s:17:" Não incidência";i:3;s:10:" Isenção";i:4;s:6:" Imune";i:5;s:45:" Exigibilidade Suspensa por Decisão Judicial";i:6;s:48:" Exigibilidade Suspensa por Proc. Administrativo";i:7;s:26:" Exportação de Serviços";}}', 1, '', 1, '', '', 1, 'pt_BR', 1, 0, 1, []);
        //$extrafields->addExtraField('dest_op', 'Destino da Operação', 'select', 100, '', 'facture', 0, 0, '1', 'a:1:{s:7:"options";a:2:{i:1;s:19:" Operação Interna";i:2;s:25:" Operação Interestadual";}}', 1, '', 4, '', '', 1, 'pt_BR', 1, 1, 0, []);
        // $extrafields->addExtraField('nat_op', 'Natureza da Operação', 'select', 100, '', 'facture', 0, 0, '1', 'a:1:{s:7:"options";a:2:{s:19:"Venda de mercadoria";s:20:" Venda de mercadoria";s:20:"Compra de mercadoria";s:21:" Compra de mercadoria";}}', 1, '', 4, '', '', 1, 'pt_BR', 1, 0, 1, []);
        $extrafields->addExtraField('nat_op', 'Natureza da Operação', 'select', 100, '', 'facture', 0, 0, '1', serialize(['options' => ['1' => 'Venda de mercadoria', '2' => 'Compra de mercadoria']]), 1, '', 4);
        $extrafields->addExtraField('frete', 'Frete', 'select', 100, '', 'facture', 0, 0, '9', serialize(['options' => ['1' => 'Por conta do emitente', '2' => 'Por conta do destinatário/remetente', '3' => 'Por conta de terceiros', '9' => 'Sem frete']]), 1, '', 4);
        $extrafields->addExtraField('indiedest', 'Indicador IE Destinatário', 'select', 100, '', 'societe', 0, 1, '', serialize(['options' => ['1' => 'Contribuinte ICMS', '2' => 'Contribuinte Isento', '3' => 'Não Contribuinte']]), 1, '', 1);
        // $extrafields->addExtraField('frete', 'Frete', 'select', 100, '', 'facture', 0, 0, '9', 'a:1:{s:7:"options";a:4:{i:0;s:21:"Por conta do emitente";i:1;s:36:"Por conta do destinatário/remetente";i:2;s:22:"Por conta de terceiros";i:9;s:9:"Sem frete";}}', 1, '', 1, '', '', 1, '', 1, 0, 1, []);
        // $extrafields->addExtraField('prd_regime_icms', '', 'select', 100, '', 'facture', 0, 0, '', serialize(['options' => [1 => 'Exigivel', 2 => 'Nao incidencia', 3 => 'Isencao', 4 => 'Exportacao', 5 => 'Imunidade', 6 => 'Exigibilidade suspensa por Decisao Judicial', 7 => 'Exigibilidade suspensa por Processo Administrativo']]));
        // Remove dest_op if it still exists from a previous install
        $extrafields->delete('dest_op', 'facture');
        $this->createTables($this->db);
        return $this->_init($sql, $options);
    }

    public function deleteExtrafields(){
        require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
        $extrafields = new ExtraFields($this->db);
        $fields = ['indpres', 'nat_op', 'frete', 'dest_op'];
        foreach($fields as $field){
            $extrafields->delete($field, 'facture');
        }
    }
    public function remove($options = '')
    {
        $sql = array();
        $this->deleteExtrafields();
        return $this->_remove($sql, $options);
    }

}
