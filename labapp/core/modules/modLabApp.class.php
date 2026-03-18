<?php

require_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';
/** @var DoliDB $db */

class modLabApp extends DolibarrModules
{
    public $hooks;
    
    public function __construct($db)
    {
        global $langs;

        parent::__construct($db);

        $this->numero       = 500400;
        $this->rights_class = 'labapp';
        $this->family       = "Lab Connecta";
        $this->name         = "labapp";
        $this->description  = "Utilitários Lab Connecta.";
        $this->version      = '1.0';
        $this->picto        = 'labapp@labapp';
        $this->rights       = array();
        $this->menu         = array();
        $this->const_name   = "MAIN_MODULE_LABAPP";
        $this->hooks        = array();

        $this->const = array(
            0 => array('MAIN_HIDE_POWERED_BY', 'chaine', '1', '', 0, 'current', 1),
            1 => array('MAIN_APPLICATION_TITLE', 'chaine', 'Lab Connecta', '', 0, 'current', 1),
            2 => array('MAIN_DISABLE_PDF_THUMBS', 'chaine', '1', '', 0, 'current', 1),
            3 => array('MAIN_REPLACE_TRANS_pt_BR', 'chaine', 'Dolibarr:Lab Connecta;Dolibarr ERP CRM:Lab Connecta;dolibarr:labconnecta', '', 0, 'current', 1),
        );
        $this->module_parts = array(
            'hooks' => array(
                'productcard',         // Ficha de produto
                'productedit',         // Edição de produto
                'productlist',         // Lista de produtos
                'invoicelist',         // Lista de faturas
                'admincompany',        // Campos NFSe/NFe em admin/company.php
                'thirdpartycard',      // Ficha de terceiro (societe/card.php) — ocultar campos
                'formmail',            // Formulário de envio de e-mail — anexar DANFSe automaticamente
            ),
        );

        // Corrige URL da página de configuração
        //$this->config_page_url = array('setup.php@nfse');
        //$this->rights[] = array(500101, 'Acessar lista de NFS-e', 'read', 1);
        $i = 0;

    }
    public function createExtrafields(){
        require_once DOL_DOCUMENT_ROOT . '/core/class/extrafields.class.php';
        $extrafields = new ExtraFields($this->db);
        // Extrafields para Sociedades (Terceiros)
        $extrafields->addExtraField('bairro', 'Bairro', 'varchar', 100, 255, 'societe', 0, 1, '', '', 1, '', 1);
        $extrafields->addExtraField('numero_de_endereco', 'Número de Endereço', 'int', 100, 8, 'societe', 0, 1, '', '', 1, '', 1);
        //$extrafields->addExtraField('rua', 'Rua', 'varchar', 100, 255, 'societe', 0, 0, '', '', 1, '', 1);
        $extrafields->addExtraField('regime_tributario', 'Regime Tributário', 'select', 100, '', 'societe', 0, 1, '', serialize(['options' => [1 => 'Simples Nacional', 2 => 'Simples Nacional c/ excesso', 3 => 'Lucro Presumido', 4 => 'Lucro Real']]), 1, [], 1, '', '', '', 1, '', 1, 0, 1, []);
    }
    public function init($options = '')
    {
        // Executar importação de estados e cidades
        $this->importarEstadosCidades();
        $this->createExtrafields();
        return $this->_init([], $options);
    }

    /**
     * Importa estados e cidades do arquivo JSON para o banco de dados
     *
     * @return int 1 se OK, -1 se erro
     */
    private function importarEstadosCidades()
    {
        global $db;

        $jsonFilePath = dol_buildpath('/labapp/estados-cidades2.json', 0);

        if (!file_exists($jsonFilePath)) {
            dol_syslog("LabApp: Arquivo estados-cidades2.json não encontrado: " . $jsonFilePath, LOG_WARNING);
            return -1;
        }
        
        // Criar tabela se não existir
        $sql = "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "estados_municipios_ibge (
            codigo_ibge VARCHAR(7) PRIMARY KEY,
            nome VARCHAR(100) NOT NULL,
            uf CHAR(2) NOT NULL,
            codigo_uf VARCHAR(2) NOT NULL,
            nome_estado VARCHAR(50) NOT NULL,
            active TINYINT DEFAULT 1,
            INDEX idx_nome (nome),
            INDEX idx_uf (uf),
            INDEX idx_codigo_uf (codigo_uf)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $result = $this->db->query($sql);
        if (!$result) {
            dol_syslog("LabApp: Erro ao criar tabela: " . $this->db->lasterror(), LOG_ERR);
            return -1;
        }

        // Verificar se já foi importado (evitar reimportação)
        $sql = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . "estados_municipios_ibge";
        $resql = $this->db->query($sql);
        if ($resql) {
            $obj = $this->db->fetch_object($resql);
            if ($obj->total > 0) {
                dol_syslog("LabApp: Municípios já importados, pulando importação", LOG_INFO);
                return 1;
            }
        }

        // Ler arquivo JSON
        $jsonContent = file_get_contents($jsonFilePath);
        $data = json_decode($jsonContent, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            dol_syslog("LabApp: Erro ao decodificar JSON: " . json_last_error_msg(), LOG_ERR);
            return -1;
        }

        $estados = $data['states'];
        $cidades = $data['cities'];

        // Mapear códigos UF para siglas
        $codigoParaSigla = [
            '11' => 'RO', '12' => 'AC', '13' => 'AM', '14' => 'RR', '15' => 'PA',
            '16' => 'AP', '17' => 'TO', '21' => 'MA', '22' => 'PI', '23' => 'CE',
            '24' => 'RN', '25' => 'PB', '26' => 'PE', '27' => 'AL', '28' => 'SE',
            '29' => 'BA', '31' => 'MG', '32' => 'ES', '33' => 'RJ', '35' => 'SP',
            '41' => 'PR', '42' => 'SC', '43' => 'RS', '50' => 'MS', '51' => 'MT',
            '52' => 'GO', '53' => 'DF'
        ];

        // Inserir dados
        $this->db->begin();
        $contador = 0;

        foreach ($cidades as $cidade) {
            $codigoIbge = $cidade['id'];
            $nomeCidade = $this->db->escape($cidade['name']);
            $codigoUf = $cidade['state_id'];
            $siglaUf = $codigoParaSigla[$codigoUf] ?? 'XX';
            $nomeEstado = $this->db->escape($estados[$codigoUf] ?? 'Desconhecido');

            $sql = "INSERT INTO " . MAIN_DB_PREFIX . "estados_municipios_ibge
                    (codigo_ibge, nome, uf, codigo_uf, nome_estado, active)
                    VALUES (
                        '$codigoIbge',
                        '$nomeCidade',
                        '$siglaUf',
                        '$codigoUf',
                        '$nomeEstado',
                        1
                    )";

            if ($this->db->query($sql)) {
                $contador++;
            }
        }

        $this->db->commit();
        return 1;
    }
 
    public function remove($options = '')
    {
        return $this->_remove([]);
    }
}