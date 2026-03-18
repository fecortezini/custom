<?php

require_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

class modCTe extends DolibarrModules
{
    public $numero       = 500300;
    public $rights_class = 'cte';
    public $family       = "Conhecimento de Transporte Eletrônico";
    public $name         = "CT-e";
    public $description  = "Módulo para emissão e listagem de CT-e.";
    public $version      = '1.0';
    public $picto        = 'cte@cte';
    public $rights       = array();
    public $menu         = array();
    public $const_name   = "MAIN_MODULE_CTE";
    public $hooks        = array();
    
    public function __construct($db)
    {
        global $langs;
        parent::__construct($db);
        
        // Registra hooks para controle de visibilidade de extrafields
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
            'fk_menu'   => '',
            'type'      => 'top',
            'titre'     => 'CT-e',
            'mainmenu'  => 'cte',
            'url'       => '/custom/cte/cte_list.php', // ATUALIZADO: aponta para lista
            'langs'     => 'cte@cte',
            'position'  => 300,
            'enabled'   => 'isModEnabled("cte")',
            'perms'     => '1',
            'target'    => '',
            'user'      => 2,
            'id'        => 'menu_cte_top'
        );
        $i++;
        
        //2. Submenu - Lista de CT-e
        $this->menu[$i] = array(
            'fk_menu'   => 'fk_mainmenu=cte',
            'type'      => 'left',
            'titre'     => 'Lista de CT-e',
            'mainmenu'  => 'cte',
            'leftmenu'  => 'cte_list',
            'url'       => '/custom/cte/cte_list.php',
            'langs'     => 'cte@cte',
            'position'  => 100,
            'enabled'   => 'isModEnabled("cte")',
            'perms'     => '1',
            'target'    => '',
            'user'      => 2
        );
        $i++;
        
        //3. Submenu - Nova Emissão
        $this->menu[$i] = array(
            'fk_menu'   => 'fk_mainmenu=cte',
            'type'      => 'left',
            'titre'     => 'Nova Emissão',
            'mainmenu'  => 'cte',
            'leftmenu'  => 'cte_emitir',
            'url'       => '/custom/cte/cte_main_home.php',
            'langs'     => 'cte@cte',
            'position'  => 110,
            'enabled'   => 'isModEnabled("cte")',
            'perms'     => '1',
            'target'    => '',
            'user'      => 2
        );
        $i++;
    }

    private function createTables()
    {
        $p = defined('MAIN_DB_PREFIX') ? MAIN_DB_PREFIX : 'llx_';
        $sqls = [];

        $sqls[] = "
        CREATE TABLE IF NOT EXISTS {$p}cte_emitidos (
            rowid INT AUTO_INCREMENT PRIMARY KEY,
            chave VARCHAR(44) NOT NULL UNIQUE,
            numero VARCHAR(20) NOT NULL,
            serie VARCHAR(10) NOT NULL,
            dhemi DATETIME NOT NULL,
            protocolo VARCHAR(20),
            dest_cnpj VARCHAR(18),
            cnpj_auxiliar VARCHAR(18),
            valor DECIMAL(15,2),
            xml_enviado LONGTEXT,
            xml_recebido LONGTEXT,
            status VARCHAR(20),
            datec DATETIME NOT NULL,
            INDEX idx_chave (chave),
            INDEX idx_numero (numero),
            INDEX idx_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $sqls[] = "
        CREATE TABLE IF NOT EXISTS {$p}cte_sequencias (
            id INT AUTO_INCREMENT PRIMARY KEY,
            cnpj VARCHAR(18) NOT NULL,
            serie VARCHAR(20) NOT NULL,
            tipo VARCHAR(10) NOT NULL,
            numero_cte INT UNSIGNED NOT NULL DEFAULT 1,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        $sqls[] = "
        CREATE TABLE {$p}cte_eventos (
            rowid INT AUTO_INCREMENT PRIMARY KEY,
            fk_cte INT NOT NULL,
            tipo SMALLINT, -- 1=Cancelamento, 2=CC-e, 3=EPEC...
            protocolo VARCHAR(50),
            justificativa VARCHAR(255),
            xml_enviado LONGTEXT,
            xml_recebido LONGTEXT,            
            data_evento DATETIME
        );";
        
        foreach ($sqls as $s) {
            $res = $this->db->query($s);
            if (!$res) {
                syslog(LOG_ERR, "modCTe: Nao foi possivel criar as tabelas. ".$this->db->lasterror());
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