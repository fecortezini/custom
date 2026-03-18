<?php
/* Copyright (C) 2024-2026 NFS-e Module
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

/**
 * \file    custom/nfse/lib/nfse_security.lib.php
 * \ingroup nfse
 * \brief   Funções de segurança para criptografia de senhas de certificados digitais
 */

/**
 * Obtém ou gera a chave mestra de criptografia (totalmente automático)
 * Ideal para hospedagem compartilhada (Hostgator, etc)
 * 
 * Primeira execução: gera chave aleatória forte e salva no banco
 * Execuções seguintes: reutiliza a chave salva
 * 
 * @param  DoliDB  $db  Objeto de conexão com banco de dados
 * @return string       Chave derivada de 32 bytes
 */
function getCertPasswordKey($db)
{
    // 1. Verifica se já existe chave mestra no banco
    $sql = "SELECT value FROM ".MAIN_DB_PREFIX."nfe_config WHERE name = 'encryption_master_key'";
    $resql = $db->query($sql);
    
    if ($resql && $db->num_rows($resql) > 0) {
        $obj = $db->fetch_object($resql);
        $masterKey = $obj->value;
    } else {
        // 2. Primeira vez: gera chave aleatória forte (256 bits = 32 bytes)
        $masterKey = bin2hex(openssl_random_pseudo_bytes(32));
        
        // 3. Salva no banco para reutilização futura
        $sqlInsert = "INSERT INTO ".MAIN_DB_PREFIX."nfe_config (name, value) 
                      VALUES ('encryption_master_key', '".$db->escape($masterKey)."')";
        $db->query($sqlInsert);
    }
    
    // Salt fixo específico para senhas de certificado
    $salt = 'nfe_cert_password_v1';
    
    // Deriva chave de 32 bytes usando PBKDF2 (100.000 iterações)
    return hash_pbkdf2('sha256', $masterKey, $salt, 100000, 32, true);
}

/**
 * Criptografa a senha do certificado usando AES-256-GCM (AEAD)
 * GCM fornece criptografia + autenticação em uma operação
 * 
 * @param  string  $password  Senha em texto plano
 * @param  DoliDB  $db        Objeto de conexão com banco de dados
 * @return string             Senha criptografada em base64 (formato: iv[12] | tag[16] | ciphertext)
 */
function encryptPassword($password, $db)
{
    if ($password === '') {
        return '';
    }
    
    $key = getCertPasswordKey($db);
    
    // GCM usa IV de 12 bytes (96 bits) - padrão recomendado
    $iv = openssl_random_pseudo_bytes(12);
    $tag = ''; // Será preenchido pelo openssl_encrypt
    
    // AES-256-GCM: criptografia autenticada (AEAD)
    $encrypted = openssl_encrypt(
        $password,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag, // TAG de autenticação (16 bytes)
        '',   // AAD (Additional Authenticated Data) - não usado aqui
        16    // Tamanho do TAG
    );
    
    if ($encrypted === false) {
        return '';
    }
    
    // Retorna: IV(12) | TAG(16) | CIPHERTEXT em base64
    return base64_encode($iv . $tag . $encrypted);
}

/**
 * Descriptografa a senha do certificado usando AES-256-GCM
 * Verifica automaticamente a integridade/autenticidade dos dados
 * 
 * @param  string  $encryptedPassword  Senha criptografada em base64
 * @param  DoliDB  $db                 Objeto de conexão com banco de dados
 * @return string                      Senha em texto plano (ou '' se falhar autenticação)
 */
function decryptPassword($encryptedPassword, $db)
{
    if ($encryptedPassword === '') {
        return '';
    }
    
    $data = base64_decode($encryptedPassword);
    if ($data === false || strlen($data) < 28) { // 12 (IV) + 16 (TAG) mínimo
        return '';
    }
    
    $key = getCertPasswordKey($db);
    
    // Extrai componentes: IV(12) | TAG(16) | CIPHERTEXT
    $iv = substr($data, 0, 12);
    $tag = substr($data, 12, 16);
    $ciphertext = substr($data, 28);
    
    // Descriptografa e AUTENTICA em uma operação
    $decrypted = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $iv,
        $tag
    );
    
    // Se retornar false, dados foram adulterados ou chave incorreta
    return $decrypted !== false ? $decrypted : '';
}
