<?php
/**
 * Classe para manipulação e conversão de certificados digitais
 * Compatível com OpenSSL 3.x (HostGator e outros ambientes modernos)
 */
class NfeCertificate
{
    /** @var string Último erro ocorrido */
    public $error = '';
    
    /** @var array Informações do certificado */
    public $certInfo = array();
    
    /** @var string Diretório temporário */
    private $tmpDir;
    
    /**
     * Construtor
     */
    public function __construct()
    {
        $this->tmpDir = sys_get_temp_dir();
    }
    
    /**
     * Processa o certificado PFX e converte se necessário
     * 
     * @param string $pfxContent Conteúdo binário do arquivo PFX
     * @param string $password Senha do certificado
     * @return array|false Array com ['pfx' => conteúdo convertido, 'info' => dados do cert] ou false em caso de erro
     */
    public function processAndConvert(string $pfxContent, string $password)
    {
        $this->error = '';
        $this->certInfo = array();
        
        // Limpa erros anteriores do OpenSSL
        while (openssl_error_string() !== false) {}
        
        // Tenta ler diretamente primeiro
        $certs = array();
        if (openssl_pkcs12_read($pfxContent, $certs, $password)) {
            // Certificado já é compatível
            $this->certInfo = $this->extractCertInfo($certs['cert']);
            return array(
                'pfx' => $pfxContent,
                'info' => $this->certInfo,
                'converted' => false
            );
        }
        
        // Captura o erro
        $sslError = $this->getOpenSSLError();
        
        // Verifica se é erro de algoritmo não suportado (OpenSSL 3.x)
        if (strpos($sslError, 'unsupported') !== false || 
            strpos($sslError, '0308010C') !== false ||
            strpos($sslError, 'RC2') !== false ||
            strpos($sslError, 'legacy') !== false) {
            
            // Tenta converter usando linha de comando
            $converted = $this->convertPfxLegacy($pfxContent, $password);
            if ($converted !== false) {
                return $converted;
            }
        }
        
        // Se não conseguiu converter, retorna o erro original
        if (empty($this->error)) {
            $this->error = $sslError ?: 'Erro desconhecido ao processar certificado.';
        }
        
        return false;
    }
    
    /**
     * Converte PFX usando openssl via linha de comando (para algoritmos legados)
     * 
     * @param string $pfxContent Conteúdo do PFX original
     * @param string $password Senha do certificado
     * @return array|false
     */
    private function convertPfxLegacy(string $pfxContent, string $password)
    {
        // Gera nomes únicos para arquivos temporários
        $uid = uniqid('nfe_cert_', true);
        $pfxOriginal = $this->tmpDir . '/' . $uid . '_original.pfx';
        $pemCert = $this->tmpDir . '/' . $uid . '_cert.pem';
        $pemKey = $this->tmpDir . '/' . $uid . '_key.pem';
        $pfxNew = $this->tmpDir . '/' . $uid . '_new.pfx';
        $passFile = $this->tmpDir . '/' . $uid . '_pass.txt';
        
        $filesToClean = array($pfxOriginal, $pemCert, $pemKey, $pfxNew, $passFile);
        
        try {
            // Salva o PFX original
            if (file_put_contents($pfxOriginal, $pfxContent) === false) {
                throw new Exception('Não foi possível criar arquivo temporário.');
            }
            
            // Salva a senha em arquivo (mais seguro que passar na linha de comando)
            if (file_put_contents($passFile, $password) === false) {
                throw new Exception('Não foi possível criar arquivo de senha.');
            }
            
            // Escapa a senha para uso em shell
            $passEscaped = escapeshellarg($password);
            
            // Passo 1: Extrai certificado usando provider legacy
            $cmd1 = sprintf(
                'openssl pkcs12 -in %s -passin file:%s -clcerts -nokeys -out %s -legacy 2>&1',
                escapeshellarg($pfxOriginal),
                escapeshellarg($passFile),
                escapeshellarg($pemCert)
            );
            
            $output1 = array();
            $ret1 = 0;
            exec($cmd1, $output1, $ret1);
            
            // Se -legacy não funcionar, tenta sem (OpenSSL mais antigo)
            if ($ret1 !== 0 || !file_exists($pemCert)) {
                $cmd1Alt = sprintf(
                    'openssl pkcs12 -in %s -passin file:%s -clcerts -nokeys -out %s 2>&1',
                    escapeshellarg($pfxOriginal),
                    escapeshellarg($passFile),
                    escapeshellarg($pemCert)
                );
                exec($cmd1Alt, $output1, $ret1);
            }
            
            if ($ret1 !== 0 || !file_exists($pemCert)) {
                throw new Exception('Falha ao extrair certificado: ' . implode(' ', $output1));
            }
            
            // Passo 2: Extrai chave privada
            $cmd2 = sprintf(
                'openssl pkcs12 -in %s -passin file:%s -nocerts -nodes -out %s -legacy 2>&1',
                escapeshellarg($pfxOriginal),
                escapeshellarg($passFile),
                escapeshellarg($pemKey)
            );
            
            $output2 = array();
            $ret2 = 0;
            exec($cmd2, $output2, $ret2);
            
            // Se -legacy não funcionar, tenta sem
            if ($ret2 !== 0 || !file_exists($pemKey)) {
                $cmd2Alt = sprintf(
                    'openssl pkcs12 -in %s -passin file:%s -nocerts -nodes -out %s 2>&1',
                    escapeshellarg($pfxOriginal),
                    escapeshellarg($passFile),
                    escapeshellarg($pemKey)
                );
                exec($cmd2Alt, $output2, $ret2);
            }
            
            if ($ret2 !== 0 || !file_exists($pemKey)) {
                throw new Exception('Falha ao extrair chave privada: ' . implode(' ', $output2));
            }
            
            // Passo 3: Recria o PFX com algoritmos modernos (AES256)
            $cmd3 = sprintf(
                'openssl pkcs12 -export -in %s -inkey %s -out %s -passout file:%s -certpbe AES-256-CBC -keypbe AES-256-CBC -macalg SHA256 2>&1',
                escapeshellarg($pemCert),
                escapeshellarg($pemKey),
                escapeshellarg($pfxNew),
                escapeshellarg($passFile)
            );
            
            $output3 = array();
            $ret3 = 0;
            exec($cmd3, $output3, $ret3);
            
            if ($ret3 !== 0 || !file_exists($pfxNew)) {
                throw new Exception('Falha ao recriar PFX: ' . implode(' ', $output3));
            }
            
            // Lê o novo PFX
            $newPfxContent = file_get_contents($pfxNew);
            if ($newPfxContent === false) {
                throw new Exception('Falha ao ler PFX convertido.');
            }
            
            // Valida o novo PFX
            $certs = array();
            if (!openssl_pkcs12_read($newPfxContent, $certs, $password)) {
                throw new Exception('PFX convertido é inválido: ' . $this->getOpenSSLError());
            }
            
            $this->certInfo = $this->extractCertInfo($certs['cert']);
            
            return array(
                'pfx' => $newPfxContent,
                'info' => $this->certInfo,
                'converted' => true
            );
            
        } catch (Exception $e) {
            $this->error = $e->getMessage();
            return false;
        } finally {
            // Limpa arquivos temporários de forma segura
            foreach ($filesToClean as $file) {
                if (file_exists($file)) {
                    // Sobrescreve com zeros antes de deletar (segurança)
                    $size = filesize($file);
                    if ($size > 0) {
                        $fh = fopen($file, 'w');
                        if ($fh) {
                            fwrite($fh, str_repeat("\0", $size));
                            fclose($fh);
                        }
                    }
                    @unlink($file);
                }
            }
        }
    }
    
    /**
     * Extrai informações do certificado
     * 
     * @param string $certPem Certificado em formato PEM
     * @return array
     */
    private function extractCertInfo(string $certPem): array
    {
        $info = array(
            'subject_cn' => 'N/A',
            'issuer_cn' => 'N/A',
            'valid_from' => null,
            'valid_to' => null,
            'valid_from_ts' => null,
            'valid_to_ts' => null,
            'algorithm' => 'N/A',
            'serial' => 'N/A'
        );
        
        $data = openssl_x509_parse($certPem);
        if ($data) {
            $info['subject_cn'] = $data['subject']['CN'] ?? 'N/A';
            $info['issuer_cn'] = $data['issuer']['CN'] ?? 'N/A';
            $info['valid_from_ts'] = $data['validFrom_time_t'] ?? null;
            $info['valid_to_ts'] = $data['validTo_time_t'] ?? null;
            $info['valid_from'] = $info['valid_from_ts'] ? date('d/m/Y', $info['valid_from_ts']) : 'N/A';
            $info['valid_to'] = $info['valid_to_ts'] ? date('d/m/Y', $info['valid_to_ts']) : 'N/A';
            $info['algorithm'] = $data['signatureTypeSN'] ?? 'N/A';
            $info['serial'] = $data['serialNumberHex'] ?? 'N/A';
        }
        
        return $info;
    }
    
    /**
     * Captura erro do OpenSSL de forma limpa
     * 
     * @return string
     */
    private function getOpenSSLError(): string
    {
        $errors = array();
        while (($msg = openssl_error_string()) !== false) {
            $errors[] = trim($msg);
        }
        $errors = array_values(array_unique($errors));
        return implode('; ', $errors);
    }
    
    /**
     * Verifica se o ambiente suporta conversão via CLI
     * 
     * @return bool
     */
    public static function canUseShellConversion(): bool
    {
        // Verifica se exec está disponível
        if (!function_exists('exec')) {
            return false;
        }
        
        // Verifica se está desabilitado
        $disabled = explode(',', ini_get('disable_functions'));
        $disabled = array_map('trim', $disabled);
        if (in_array('exec', $disabled)) {
            return false;
        }
        
        // Verifica se openssl está no PATH
        $output = array();
        $ret = 0;
        exec('openssl version 2>&1', $output, $ret);
        
        return ($ret === 0 && !empty($output));
    }
    
    /**
     * Retorna a versão do OpenSSL instalada
     * 
     * @return string
     */
    public static function getOpenSSLVersion(): string
    {
        // Versão da extensão PHP
        $phpVersion = defined('OPENSSL_VERSION_TEXT') ? OPENSSL_VERSION_TEXT : 'Desconhecida';
        
        // Versão do CLI (se disponível)
        $cliVersion = '';
        if (self::canUseShellConversion()) {
            $output = array();
            exec('openssl version 2>&1', $output);
            $cliVersion = !empty($output) ? $output[0] : '';
        }
        
        return "PHP: {$phpVersion}" . ($cliVersion ? " | CLI: {$cliVersion}" : '');
    }
}
