<?php
/**
 * EXEMPLO DE INTEGRAÇÃO DO CertificateConverter
 * 
 * Este arquivo mostra como modificar o código de upload do certificado
 * para usar automaticamente a conversão quando necessário.
 */

// ============================================================
// ANTES - Código original (SEM conversão automática)
// ============================================================
/*
if (!empty($cert_file['tmp_name'])) {
    if (preg_match('/\.pfx$/i', $cert_file['name'])) {
        $cert_data = file_get_contents($cert_file['tmp_name']);
        $sql = "INSERT INTO ".MAIN_DB_PREFIX."nfe_config (name, value) VALUES ('cert_pfx', '".$db->escape($cert_data)."')
                ON DUPLICATE KEY UPDATE value = '".$db->escape($cert_data)."'";
        $resql = $db->query($sql);
        if (!$resql) {
            $error++;
            setEventMessages($db->lasterror(), null, 'errors');
        }
    } else {
        $error++;
        setEventMessages($langs->trans("Formato de arquivo inválido para o certificado"), null, 'errors');
    }
}
*/

// ============================================================
// DEPOIS - Código modificado (COM conversão automática)
// ============================================================

// 1. No topo do arquivo, adicionar o require:
require_once DOL_DOCUMENT_ROOT . '/custom/labapp/class/CertificateConverter.php';

// 2. Modificar a seção de salvamento do certificado:
if (!empty($cert_file['tmp_name'])) {
    if (preg_match('/\.pfx$/i', $cert_file['name'])) {
        $cert_data = file_get_contents($cert_file['tmp_name']);
        
        // NOVO: Validar e converter certificado se necessário
        $converter = new CertificateConverter();
        $result = $converter->ensureCompatiblePfx($cert_data, $cert_pass);
        
        if ($result === false) {
            // Certificado inválido ou não pôde ser convertido
            $error++;
            setEventMessages($converter->getLastError(), null, 'errors');
        } else {
            // Certificado OK - usar o conteúdo convertido (se foi convertido)
            $cert_data_to_save = $result['pfx_content'];
            
            // Informar ao usuário se houve conversão
            if ($result['converted']) {
                setEventMessages(
                    "Certificado convertido automaticamente de algoritmo legado para AES-256 (método: {$result['method']})", 
                    null, 
                    'warnings'
                );
            }
            
            // Salvar no banco
            $sql = "INSERT INTO ".MAIN_DB_PREFIX."nfe_config (name, value) VALUES ('cert_pfx', '".$db->escape($cert_data_to_save)."')
                    ON DUPLICATE KEY UPDATE value = '".$db->escape($cert_data_to_save)."'";
            $resql = $db->query($sql);
            if (!$resql) {
                $error++;
                setEventMessages($db->lasterror(), null, 'errors');
            } else {
                setEventMessages("Certificado salvo com sucesso", null, 'mesgs');
            }
        }
    } else {
        $error++;
        setEventMessages($langs->trans("Formato de arquivo inválido para o certificado"), null, 'errors');
    }
}

// ============================================================
// EXPLICAÇÃO DO FLUXO:
// ============================================================
/*
1. O usuário faz upload do arquivo .pfx
2. Lemos o conteúdo com file_get_contents()
3. Chamamos CertificateConverter::ensureCompatiblePfx()
   
   A classe tenta 3 métodos automaticamente:
   
   a) Leitura direta: Se o certificado já é compatível, retorna sem conversão
   
   b) Legacy Config: Cria temporariamente um openssl.cnf com provider legacy,
      lê o certificado antigo, e recria com AES-256-CBC
   
   c) OpenSSL CLI: Como fallback final, usa o comando openssl via exec()
      (útil em servidores onde a extensão PHP não tem legacy mas o CLI tem)

4. Se sucesso:
   - $result['pfx_content'] = Certificado compatível (original ou convertido)
   - $result['converted'] = true/false (informa se houve conversão)
   - $result['method'] = 'native'|'legacy-config'|'openssl-cli'
   
5. Se falha:
   - Retorna false
   - getLastError() contém mensagem amigável para o usuário

6. Salvamos o certificado compatível no banco normalmente

BENEFÍCIOS:
- Usuário não precisa saber a versão do certificado
- Funciona em XAMPP local E HostGator produção
- Fallback automático se um método falha
- Mensagens claras de erro/sucesso
*/

// ============================================================
// CÓDIGO COMPLETO PRONTO PARA USO
// ============================================================

// Coloque este trecho no início do seu arquivo setup.php:
/*
require_once DOL_DOCUMENT_ROOT . '/custom/labapp/class/CertificateConverter.php';
*/

// E substitua todo o bloco "Salvar o certificado" por:
/*
// Salvar o certificado
if (!empty($cert_file['tmp_name'])) {
    if (preg_match('/\.pfx$/i', $cert_file['name'])) {
        $cert_data = file_get_contents($cert_file['tmp_name']);
        
        // Validar e converter certificado se necessário
        $converter = new CertificateConverter();
        $result = $converter->ensureCompatiblePfx($cert_data, $cert_pass);
        
        if ($result === false) {
            $error++;
            setEventMessages($converter->getLastError(), null, 'errors');
        } else {
            $cert_data_to_save = $result['pfx_content'];
            
            if ($result['converted']) {
                setEventMessages(
                    "Certificado convertido automaticamente de algoritmo legado para AES-256", 
                    null, 
                    'warnings'
                );
            }
            
            $sql = "INSERT INTO ".MAIN_DB_PREFIX."nfe_config (name, value) VALUES ('cert_pfx', '".$db->escape($cert_data_to_save)."')
                    ON DUPLICATE KEY UPDATE value = '".$db->escape($cert_data_to_save)."'";
            $resql = $db->query($sql);
            if (!$resql) {
                $error++;
                setEventMessages($db->lasterror(), null, 'errors');
            } else {
                setEventMessages("Certificado salvo com sucesso", null, 'mesgs');
            }
        }
    } else {
        $error++;
        setEventMessages($langs->trans("Formato de arquivo inválido para o certificado"), null, 'errors');
    }
}
*/
