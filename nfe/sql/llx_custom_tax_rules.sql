CREATE TABLE IF NOT EXISTS llx_custom_tax_rules2 (
    -- Identificação
    `rowid` INT AUTO_INCREMENT PRIMARY KEY,
    `label` VARCHAR(255) NOT NULL COMMENT 'Descrição da regra (ex: ST Bebidas ES->RJ)',
    `active` TINYINT(1) DEFAULT '1' COMMENT 'Regra ativa',
    `date_start` DATE DEFAULT NULL COMMENT 'Data início vigência',
    `date_end` DATE DEFAULT NULL COMMENT 'Data fim vigência',
    
    -- Critérios de Busca ESSENCIAIS (apenas UF + CFOP + NCM)
    `uf_origin` VARCHAR(2) NOT NULL COMMENT 'UF origem (emitente)',
    `uf_dest` VARCHAR(2) NOT NULL COMMENT 'UF destino (destinatário)',
    `cfop` VARCHAR(4) NOT NULL COMMENT 'CFOP da operação',
    `ncm` VARCHAR(8) DEFAULT NULL COMMENT 'NCM específico (NULL = genérico)',
    
    -- ICMS Próprio
    `icms_aliq_interna` DECIMAL(5,2) DEFAULT '0.00' COMMENT 'Alíquota interna UF destino',
    `icms_aliq_interestadual` DECIMAL(5,2) DEFAULT '0.00' COMMENT 'Alíquota interestadual',
    `icms_cred_aliq` DECIMAL(5,2) DEFAULT '0.00' COMMENT 'Crédito SN (CSOSN 101/201)',
    
    -- ICMS ST (Substituição Tributária)
    `icms_st_mva` DECIMAL(7,4) DEFAULT '0.0000' COMMENT 'MVA ST (%)',
    `icms_st_aliq` DECIMAL(5,2) DEFAULT '0.00' COMMENT 'Alíquota ICMS ST',
    `icms_st_red_bc` DECIMAL(5,2) DEFAULT '0.00' COMMENT '% Redução BC ST',
    
    -- DIFAL (Obrigatório para alguns estados)
    `difal_aliq_fcp` DECIMAL(5,2) DEFAULT '0.00' COMMENT 'Alíquota FCP (Fundo Pobreza)',
    
    -- PIS/COFINS
    `pis_cst` VARCHAR(2) DEFAULT '49' COMMENT 'CST PIS',
    `pis_aliq` DECIMAL(5,2) DEFAULT '0.00' COMMENT 'Alíquota PIS (%)',
    `cofins_cst` VARCHAR(2) DEFAULT '49' COMMENT 'CST COFINS',
    `cofins_aliq` DECIMAL(5,2) DEFAULT '0.00' COMMENT 'Alíquota COFINS (%)',
    
    -- IPI (para indústrias)
    `ipi_cst` VARCHAR(2) DEFAULT NULL COMMENT 'CST IPI',
    `ipi_aliq` DECIMAL(5,2) DEFAULT '0.00' COMMENT 'Alíquota IPI (%)',
    `ipi_cenq` VARCHAR(3) DEFAULT '999' COMMENT 'Código enquadramento IPI',
    
    -- Auditoria
    `fk_user_create` INT DEFAULT NULL COMMENT 'Usuário que criou',
    `fk_user_modify` INT DEFAULT NULL COMMENT 'Último usuário que alterou',
    `date_creation` DATETIME DEFAULT CURRENT_TIMESTAMP,
    `date_modification` DATETIME DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    
    -- Índices otimizados
    KEY `idx_busca_principal` (`uf_origin`, `uf_dest`, `cfop`, `ncm`, `active`),
    KEY `idx_vigencia` (`date_start`, `date_end`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Regras fiscais para emissão de NF-e';
