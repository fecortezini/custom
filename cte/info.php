<?php
/**
 * Helper para garantir compatibilidade de certificados .pfx
 * Converte automaticamente certificados com algoritmos legados
 */

class CertificateConverter
{
    private $lastError = '';
    
    /**
     * Tenta ler e converter certificado .pfx para formato compatível
     * @param string $pfxContent Conteúdo binário do arquivo .pfx
     * @param string $password Senha do certificado
     * @return array|false Array com 'cert', 'pkey', 'pfx_content' ou false se falhar
     */
    public function ensureCompatiblePfx($pfxContent, $password)
    {
        // Tentativa 1: Leitura direta com openssl_pkcs12_read
        $certs = [];
        if (@openssl_pkcs12_read($pfxContent, $certs, $password)) {
            return [
                'cert' => $certs['cert'],
                'pkey' => $certs['pkey'],
                'pfx_content' => $pfxContent,
                'converted' => false
            ];
        }
        
        $opensslError = openssl_error_string();
        
        // Se erro contém "unsupported" ou "digital envelope", certificado usa algoritmo legado
        if (stripos($opensslError, 'unsupported') !== false || 
            stripos($opensslError, 'digital envelope') !== false) {
            
            // Tentativa 2: Usar phpseclib (se disponível)
            if (class_exists('\phpseclib3\File\X509')) {
                try {
                    return $this->convertWithPhpseclib($pfxContent, $password);
                } catch (Exception $e) {
                    $this->lastError = "Phpseclib falhou: " . $e->getMessage();
                }
            }
            
            // Tentativa 3: Conversão via exec (se permitido no servidor)
            if (function_exists('exec')) {
                try {
                    return $this->convertWithExec($pfxContent, $password);
                } catch (Exception $e) {
                    $this->lastError = "Exec falhou: " . $e->getMessage();
                }
            }
            
            $this->lastError = "Certificado usa algoritmo não suportado (RC2/RC4/DES). " .
                              "Instale phpseclib ou reconverta o certificado com algoritmos modernos.";
            return false;
        }
        
        // Outro erro
        $this->lastError = "Erro ao ler certificado: " . $opensslError;
        return false;
    }
    
    /**
     * Converte usando phpseclib (biblioteca PHP pura)
     */
    private function convertWithPhpseclib($pfxContent, $password)
    {
        // phpseclib3 suporta PKCS12 com algoritmos legados
        $pkcs12 = new \phpseclib3\File\PKCS12();
        $result = $pkcs12->load($pfxContent, $password);
        
        if (!$result) {
            throw new Exception("phpseclib não conseguiu carregar o PKCS12");
        }
        
        // Extrair certificado e chave
        $cert = $pkcs12->getCertificate();
        $pkey = $pkcs12->getPrivateKey();
        
        // Recriar PKCS12 com algoritmos modernos
        $newPkcs12 = new \phpseclib3\File\PKCS12();
        $newPkcs12->setCertificate($cert);
        $newPkcs12->setPrivateKey($pkey);
        $newPkcs12->setPassword($password);
        
        // Usar AES-256-CBC (moderno)
        $newPfxContent = $newPkcs12->save(\phpseclib3\File\PKCS12::PKCS12_AES256_SHA256);
        
        // Converter para formato OpenSSL
        $certs = [];
        if (!openssl_pkcs12_read($newPfxContent, $certs, $password)) {
            throw new Exception("Conversão phpseclib OK mas OpenSSL ainda rejeita");
        }
        
        return [
            'cert' => $certs['cert'],
            'pkey' => $certs['pkey'],
            'pfx_content' => $newPfxContent,
            'converted' => true,
            'method' => 'phpseclib'
        ];
    }
    
    /**
     * Converte usando openssl CLI (requer exec habilitado)
     */
    private function convertWithExec($pfxContent, $password)
    {
        // Salvar temporariamente
        $tempPfx = tempnam(sys_get_temp_dir(), 'pfx_');
        $tempPem = tempnam(sys_get_temp_dir(), 'pem_');
        $tempOut = tempnam(sys_get_temp_dir(), 'out_');
        
        try {
            file_put_contents($tempPfx, $pfxContent);
            
            // Tentar encontrar openssl
            $openssl = $this->findOpenSSL();
            if (!$openssl) {
                throw new Exception("openssl não encontrado no PATH");
            }
            
            // Extrair para PEM
            $cmd = sprintf(
                '%s pkcs12 -in %s -out %s -nodes -passin pass:%s 2>&1',
                escapeshellarg($openssl),
                escapeshellarg($tempPfx),
                escapeshellarg($tempPem),
                escapeshellarg($password)
            );
            
            exec($cmd, $output, $returnCode);
            
            if ($returnCode !== 0 || !file_exists($tempPem)) {
                throw new Exception("Falha ao extrair PEM: " . implode("\n", $output));
            }
            
            // Recriar PKCS12 com algoritmos modernos
            $cmd = sprintf(
                '%s pkcs12 -export -in %s -out %s -certpbe AES-256-CBC -keypbe AES-256-CBC -macalg sha256 -passout pass:%s 2>&1',
                escapeshellarg($openssl),
                escapeshellarg($tempPem),
                escapeshellarg($tempOut),
                escapeshellarg($password)
            );
            
            exec($cmd, $output2, $returnCode2);
            
            if ($returnCode2 !== 0 || !file_exists($tempOut)) {
                throw new Exception("Falha ao recriar PKCS12: " . implode("\n", $output2));
            }
            
            $newPfxContent = file_get_contents($tempOut);
            
            // Validar
            $certs = [];
            if (!openssl_pkcs12_read($newPfxContent, $certs, $password)) {
                throw new Exception("Conversão OK mas OpenSSL ainda rejeita");
            }
            
            return [
                'cert' => $certs['cert'],
                'pkey' => $certs['pkey'],
                'pfx_content' => $newPfxContent,
                'converted' => true,
                'method' => 'openssl-cli'
            ];
            
        } finally {
            @unlink($tempPfx);
            @unlink($tempPem);
            @unlink($tempOut);
        }
    }
    
    /**
     * Busca binário openssl no sistema
     */
    private function findOpenSSL()
    {
        $paths = [
            '/usr/bin/openssl',
            '/usr/local/bin/openssl',
            '/opt/bin/openssl',
            'C:\\xampp\\apache\\bin\\openssl.exe',
            'C:\\Program Files\\OpenSSL-Win64\\bin\\openssl.exe'
        ];
        
        foreach ($paths as $path) {
            if (file_exists($path)) return $path;
        }
        
        // Tentar via which/where
        $which = stripos(PHP_OS, 'WIN') === 0 ? 'where' : 'which';
        $result = @shell_exec("$which openssl");
        if ($result) {
            return trim(explode("\n", $result)[0]);
        }
        
        return null;
    }
    
    public function getLastError()
    {
        return $this->lastError;
    }
}