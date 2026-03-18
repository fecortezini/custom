<?php

require_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

class modMDFE extends DolibarrModules
{
    public $numero       = 500500;
    public $rights_class = 'mdfe';
    public $family       = "Manifesto Eletrônico de Documentos Fiscais";
    public $name         = "MDF-e";
    public $description  = "Módulo para emissão e listagem de MDF-e.";
    public $version      = '1.0';
    public $picto        = 'mdfe@mdfe';
    public $rights       = array();
    public $menu         = array();
    public $const_name   = "MAIN_MODULE_MDFE";
    public $hooks        = array();
    
    public function __construct($db)
    {
        global $langs;
        parent::__construct($db);
        
        // Registra hooks
        // NOTA: O hook 'admincompany' foi transferido para o módulo Lab Connecta (labapp)
        // Arquivo: custom/labapp/class/actions_lab.class.php
        $this->module_parts = array(
            'hooks' => array(
                'invoicecard',         // Ficha de fatura
                'productcard',         // Ficha de produto
                'productedit',         // Edição de produto
                'productlist',         // Lista de produtos
                'invoicelist'          // Lista de faturas
            ),
        );

        // Corrige URL da página de configuração
        //$this->config_page_url = array('setup.php@nfse');

        //$this->rights[] = array(500101, 'Acessar lista de NFS-e', 'read', 1);        
        $i = 0;

        //1. Menu de Topo (Principal)
        $this->menu[$i] = array(
            'fk_menu'   => 0,
            'type'      => 'top',
            'titre'     => 'MDF-e',
            'mainmenu'  => 'mdfe',
            'url'       => '/custom/mdfe/mdfe_list.php', // ATUALIZADO: aponta para lista
            'langs'     => 'mdfe@mdfe',
            'position'  => 300,
            'enabled'   => '$conf->global->MAIN_MODULE_MDFE',
            'usertype'  => 2,
            'id'        => 'menu_mdfe_top'
        );
        $i++;
        
        //2. Submenu - Lista de CT-e
        $this->menu[$i] = array(
            'fk_menu'   => 'fk_mainmenu=mdfe',
            'type'      => 'left',
            'titre'     => 'Lista de MDF-e',
            'mainmenu'  => 'mdfe',
            'leftmenu'  => 'mdfe_list',
            'url'       => '/custom/mdfe/mdfe_list.php',
            'langs'     => 'mdfe@mdfe',
            'position'  => 100,
            'enabled'   => '$conf->global->MAIN_MODULE_MDFE',
            'usertype'  => 2
        );
        $i++;
        
        //3. Submenu - Nova Emissão
        $this->menu[$i] = array(
            'fk_menu'   => 'fk_mainmenu=mdfe',
            'type'      => 'left',
            'titre'     => 'Nova Emissão',
            'mainmenu'  => 'mdfe',
            'leftmenu'  => 'mdfe_emissao',
            'url'       => '/custom/mdfe/mdfe_form.php',
            'langs'     => 'mdfe@mdfe',
            'position'  => 110,
            'enabled'   => '$conf->global->MAIN_MODULE_MDFE',
            'usertype'  => 2
        );
        $i++;
    }

    private function createTables()
    {
        $p = defined('MAIN_DB_PREFIX');
        $sqls = [];

        // Numero de sequencias para emissão
        $sqls[] = "
        CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."mdfe_sequencias (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cnpj VARCHAR(18) NOT NULL,
            serie VARCHAR(10) NOT NULL,
            ambiente TINYINT NOT NULL DEFAULT 2 COMMENT '1=Producao, 2=Homologacao',
            ultimo_numero INT UNSIGNED NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY idx_cnpj_serie_amb (cnpj, serie, ambiente)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

        // Documentos emitidos
        $sqls[] = "
        CREATE TABLE IF NOT EXISTS ".MAIN_DB_PREFIX."mdfe_emitidas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            numero INT NOT NULL,
            serie INT NOT NULL,
            chave_acesso VARCHAR(44) NOT NULL UNIQUE,
            protocolo VARCHAR(20) DEFAULT NULL,
            cnpj_emitente VARCHAR(14) DEFAULT NULL,
            data_emissao DATETIME NOT NULL,
            data_recebimento DATETIME DEFAULT NULL,
            status VARCHAR(20) DEFAULT NULL,
            codigo_status INT DEFAULT NULL,
            motivo VARCHAR(255) DEFAULT NULL,
            uf_ini VARCHAR(2) NOT NULL,
            uf_fim VARCHAR(2) NOT NULL,
            mun_carrega VARCHAR(60) DEFAULT NULL,
            mun_descarga VARCHAR(60) DEFAULT NULL,
            modal TINYINT DEFAULT NULL COMMENT '1=Rod,2=Aer,3=Aqua,4=Ferr',
            placa VARCHAR(10) DEFAULT NULL COMMENT 'Placa do veiculo tracao',
            valor_carga DECIMAL(15,2) DEFAULT NULL,
            peso_carga DECIMAL(15,4) DEFAULT NULL,
            qtd_cte INT DEFAULT 0,
            qtd_nfe INT DEFAULT 0,
            ambiente TINYINT NOT NULL DEFAULT 2,
            xml_enviado LONGTEXT DEFAULT NULL,
            xml_resposta LONGTEXT DEFAULT NULL,
            xml_mdfe LONGTEXT DEFAULT NULL,
            data_encerramento DATETIME DEFAULT NULL,
            protocolo_encerramento VARCHAR(20) DEFAULT NULL,
            data_cancelamento DATETIME DEFAULT NULL,
            protocolo_cancelamento VARCHAR(20) DEFAULT NULL,
            motivo_cancelamento VARCHAR(255) DEFAULT NULL,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_status (status),
            INDEX idx_cnpj (cnpj_emitente),
            INDEX idx_data_emissao (data_emissao),
            INDEX idx_numero_serie (numero, serie)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

         // Eventos genéricos, todos passam por aqui primeiro e depois são categorizados (inclusao_nfe, inclusao_condutor, etc)
        $sqls[] = "
        CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "mdfe_eventos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fk_mdfe_emitida INT NOT NULL,
            tpEvento VARCHAR(6) NOT NULL,
            nSeqEvento INT NOT NULL,
            protocolo_evento VARCHAR(255) NOT NULL,
            motivo_evento TEXT NULL,
            data_evento DATETIME NOT NULL,
            xml_requisicao LONGTEXT NULL,
            xml_resposta LONGTEXT NULL,
            xml_evento_completo LONGTEXT NULL,
            KEY idx_fk_mdfe_emitida (fk_mdfe_emitida)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        $sqls[] = "
        CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "mdfe_inclusao_nfe (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fk_mdfe_emitida INT NOT NULL,
            chave_mdfe VARCHAR(44) NOT NULL,
            protocolo_mdfe VARCHAR(20) NOT NULL,
            nSeqEvento INT NOT NULL,
            cMunCarrega VARCHAR(7) NOT NULL,
            xMunCarrega VARCHAR(60) NOT NULL,
            cMunDescarga VARCHAR(7) NOT NULL,
            xMunDescarga VARCHAR(60) NOT NULL,
            chNFe VARCHAR(44) NOT NULL,
            protocolo_evento VARCHAR(20) DEFAULT NULL,
            cStat INT DEFAULT NULL,
            xMotivo VARCHAR(255) DEFAULT NULL,
            data_evento DATETIME DEFAULT NULL,
            xml_requisicao LONGTEXT NULL,
            xml_resposta LONGTEXT NULL,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_fk_mdfe (fk_mdfe_emitida),
            KEY idx_chNFe (chNFe)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        $sqls[] = "
        CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "mdfe_inclusao_condutor (
            id INT AUTO_INCREMENT PRIMARY KEY,
            fk_mdfe_emitida INT NOT NULL,
            chave_mdfe VARCHAR(44) NOT NULL,
            nSeqEvento INT NOT NULL,
            xNome VARCHAR(60) NOT NULL,
            cpf VARCHAR(11) NOT NULL,
            protocolo_evento VARCHAR(20) DEFAULT NULL,
            cStat INT DEFAULT NULL,
            xMotivo VARCHAR(255) DEFAULT NULL,
            data_evento DATETIME DEFAULT NULL,
            xml_requisicao LONGTEXT NULL,
            xml_resposta LONGTEXT NULL,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            KEY idx_fk_mdfe (fk_mdfe_emitida),
            KEY idx_cpf (cpf)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        foreach ($sqls as $s) {
            $res = $this->db->query($s);
            if (!$res) {
                syslog(LOG_ERR, "MDFe: Nao foi possivel criar as tabelas. ".$this->db->lasterror());
            }
        }
    }

    public function init($options = '')
    {
        $sql = array();
        require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
        $extrafields = new ExtraFields($this->db);

        $this->createTables();

        return $this->_init([], $options);
    }
 
    public function remove($options = '')
    {
        return $this->_remove([]);
    }
}