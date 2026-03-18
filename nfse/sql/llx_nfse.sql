-- Script dinâmico para criar tabelas NFSe com prefixo detectado automaticamente.

-- Detecta schema atual
SET @schema = DATABASE();

-- Tenta achar uma tabela conhecida para inferir o prefixo (ex.: llx_product)
SELECT table_name INTO @sample_table
  FROM information_schema.tables
  WHERE table_schema = @schema
    AND table_name LIKE '%product'
  LIMIT 1;

-- Monta prefixo (se não encontrado, fallback para 'llx_')
SET @prefix = IF(
    @sample_table IS NULL,
    'llx_',
    CONCAT(
        LEFT(@sample_table, CHAR_LENGTH(@sample_table) - CHAR_LENGTH('product')),
        IF(RIGHT(LEFT(@sample_table, CHAR_LENGTH(@sample_table) - CHAR_LENGTH('product')),1) = '_', '', '_')
    )
);

-- Helper para criar uma tabela via SQL dinâmico
-- 1) nfse_config
SET @sql = CONCAT(
  'CREATE TABLE IF NOT EXISTS ', @prefix, 'nfse_confqweig (',
    'id INT AUTO_INCREMENT PRIMARY KEY,',
    'name VARCHAR(50) NOT NULL UNIQUE,',
    'value LONGBLOB NULL',
  ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) nfse_emitidas
SET @sql = CONCAT(
  'CREATE TABLE IF NOT EXISTS ', @prefix, 'nfse_emitidas (',
    'id INT AUTO_INCREMENT PRIMARY KEY,',
    'id_fatura INT NOT NULL,',
    'id_nfse_substituida INT DEFAULT NULL,',
    'id_nfse_substituta INT DEFAULT NULL,',
    'status VARCHAR(50) DEFAULT NULL,',
    'rps_numero VARCHAR(50) NOT NULL,',
    'rps_serie INT DEFAULT 1,',
    'rps_tipo INT DEFAULT NULL,',
    'numero_lote VARCHAR(50) DEFAULT NULL,',
    'protocolo VARCHAR(100) DEFAULT NULL,',
    'data_hora_emissao DATETIME DEFAULT NULL,',
    'mensagem_retorno TEXT,',
    'numero_nota VARCHAR(50) DEFAULT NULL,',
    'cod_servico_prestado VARCHAR(50) DEFAULT NULL,',
    'prestador_nome VARCHAR(255) DEFAULT NULL,',
    'prestador_cnpj VARCHAR(18) DEFAULT NULL,',
    'prestador_im VARCHAR(50) DEFAULT NULL,',
    'cod_muni_prestado VARCHAR(50) DEFAULT NULL,',
    'tomador_nome VARCHAR(255) DEFAULT NULL,',
    'tomador_cnpj_cpf VARCHAR(18) DEFAULT NULL,',
    'valor_servicos DECIMAL(15,2) NOT NULL DEFAULT 0.00,',
    'xml_enviado LONGTEXT,',
    'xml_recebido LONGTEXT,',
    'xml_atualizado LONGTEXT,',
    'criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,',
    'atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,',
    'INDEX idx_fatura (id_fatura),',
    'INDEX idx_status (status),',
    'INDEX idx_numero_nota (numero_nota),',
    'INDEX idx_emissao (data_hora_emissao),',
    'INDEX idx_nfse_substituida (id_nfse_substituida),',
    'INDEX idx_nfse_substituta (id_nfse_substituta)',
  ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) nfse_sequencias
SET @sql = CONCAT(
  'CREATE TABLE IF NOT EXISTS ', @prefix, 'nfse_sequencias (',
    'id INT AUTO_INCREMENT PRIMARY KEY,',
    'cnpj VARCHAR(18) NOT NULL,',
    'im VARCHAR(50) NOT NULL,',
    'serie VARCHAR(20) NOT NULL,',
    'tipo VARCHAR(10) NOT NULL,',
    'next_numero_rps INT UNSIGNED NOT NULL DEFAULT 1,',
    'next_numero_lote INT UNSIGNED NOT NULL DEFAULT 1,',
    'updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,',
    'UNIQUE KEY uniq_cnpj_im_serie_tipo (cnpj, im, serie, tipo)',
  ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) nfse_eventos
-- Atenção: neste trecho as aspas internas foram dobradas para poderem ser usadas dentro da string SQL.
SET @sql = CONCAT(
  'CREATE TABLE IF NOT EXISTS ', @prefix, 'nfse_eventos (',
    'id INT AUTO_INCREMENT PRIMARY KEY,',
    'id_nfse_emitida INT NOT NULL,',
    'tipo_evento VARCHAR(20) NOT NULL,',
    'protocolo VARCHAR(100) DEFAULT NULL,',
    'numero_lote VARCHAR(50) DEFAULT NULL,',
    'numero_nfse VARCHAR(50) DEFAULT NULL,',
    'codigo_cancelamento VARCHAR(2) DEFAULT NULL,',
    'motivo TEXT,',
    'data_hora_pedido DATETIME NOT NULL,',
    'data_hora_resposta DATETIME DEFAULT NULL,',
    'status_evento ENUM(''PENDENTE'',''PROCESSANDO'',''APROVADO'',''REJEITADO'',''ERRO'') DEFAULT ''PENDENTE'',',
    'mensagem_retorno TEXT,',
    'xml_enviado LONGTEXT,',
    'xml_recebido LONGTEXT,',
    'id_nfse_substituta INT DEFAULT NULL,',
    'numero_nota_substituta VARCHAR(50) DEFAULT NULL,',
    'cnpj_prestador VARCHAR(18) DEFAULT NULL,',
    'codigo_municipio VARCHAR(7) DEFAULT NULL,',
    'criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,',
    'atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,',
    'INDEX idx_tipo_evento (tipo_evento),',
    'INDEX idx_status_evento (status_evento),',
    'INDEX idx_nfse_evento_nfse (id_nfse_emitida),',
    'INDEX idx_protocolo (protocolo),',
    'INDEX idx_data_pedido (data_hora_pedido),',
    'INDEX idx_codigo_cancelamento (codigo_cancelamento)',
  ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Mensagem final informativa (opcional para logs)
SELECT CONCAT('NFSe tables created (prefix: ', @prefix, ').') AS info;

