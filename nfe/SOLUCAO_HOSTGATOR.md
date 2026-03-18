# 🔧 Solução para Erro de Conexão SOAP na Hostgator

## ❌ Problema
```
Falha ao gerar NF-e: Erro de comunicação via soap, Failed to connect to nfe.svrs.rs.gov.br 
port 443 after 1021 ms: Could not connect to server
```

## 🔍 Diagnóstico
O erro ocorre **APENAS em produção (Hostgator)**, mas funciona perfeitamente em **localhost**.

### Causas Identificadas:

1. **🔥 Firewall da Hostgator**: Bloqueio de conexões SOAP externas na porta 443
2. **🔒 Certificados SSL**: Servidor pode ter certificados CA desatualizados
3. **⏱️ Timeout**: Hospedagem compartilhada tem timeouts muito curtos
4. **🚫 Restrições**: Política de segurança da Hostgator bloqueia webservices externos

---

## ✅ SOLUÇÕES IMPLEMENTADAS NO CÓDIGO

### 1. Configurações de Timeout Aumentadas
```php
"curlOptions" => [
    CURLOPT_TIMEOUT => 60,           // Timeout de 60 segundos
    CURLOPT_CONNECTTIMEOUT => 30,    // Timeout de conexão de 30 segundos
    CURLOPT_SSL_VERIFYHOST => 2,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_MAXREDIRS => 5
]
```

### 2. Mensagens de Erro Detalhadas
Agora quando ocorrer erro de conexão, você receberá instruções claras sobre como proceder.

---

## 📞 AÇÕES NECESSÁRIAS

### OPÇÃO 1: Contatar Suporte Hostgator (RECOMENDADO)

Abra um ticket com o suporte da Hostgator e envie:

```
Assunto: Liberação de Conexões SOAP/HTTPS para SEFAZ

Prezados,

Preciso que liberem conexões SOAP/HTTPS do meu servidor para os webservices da SEFAZ (Secretaria da Fazenda), 
necessários para emissão de Nota Fiscal Eletrônica (NF-e).

DETALHES:
- Protocolo: SOAP/HTTPS
- Porta: 443
- Servidores que precisam ser liberados:
  * nfe.svrs.rs.gov.br
  * www.sefazvirtual.fazenda.gov.br
  * hom.nfe.fazenda.gov.br (homologação)

ERRO ATUAL:
"Failed to connect to nfe.svrs.rs.gov.br port 443"

Esta comunicação é obrigatória por lei para empresas que emitem NF-e.

Atenciosamente,
[Seu Nome]
```

### OPÇÃO 2: Ajustes no php.ini (Se tiver acesso)

Adicione ou verifique estas configurações no `php.ini`:

```ini
; Timeout de conexão
default_socket_timeout = 60

; Permitir funções de rede
allow_url_fopen = On

; Certificados SSL
openssl.cafile="/etc/ssl/certs/ca-certificates.crt"
curl.cainfo="/etc/ssl/certs/ca-certificates.crt"

; cURL
extension=curl
```

### OPÇÃO 3: Arquivo .htaccess (Teste)

Adicione no `.htaccess` da raiz do projeto:

```apache
# Aumentar timeout PHP
php_value max_execution_time 300
php_value default_socket_timeout 60
```

### OPÇÃO 4: Testar Desabilitar SSL Verify (APENAS PARA DEBUG)

⚠️ **NÃO USE EM PRODUÇÃO - APENAS PARA TESTE**

Temporariamente, altere em `emissao_nfe.php`:

```php
"curlOptions" => [
    CURLOPT_TIMEOUT => 60,
    CURLOPT_CONNECTTIMEOUT => 30,
    CURLOPT_SSL_VERIFYHOST => 0,     // DESABILITA verificação (INSEGURO)
    CURLOPT_SSL_VERIFYPEER => false,  // DESABILITA verificação (INSEGURO)
]
```

Se funcionar com isso, confirma que é problema de certificados SSL no servidor.

---

## 🚀 SOLUÇÃO DEFINITIVA: Migrar para VPS/Cloud

Se a Hostgator não liberar ou continuar com problemas:

### Opções Recomendadas:

1. **Digital Ocean** - Droplet básico $6/mês
   - Controle total do servidor
   - Instale: Ubuntu + Apache/Nginx + PHP + MySQL
   - Configure SSL com Let's Encrypt

2. **AWS Lightsail** - A partir de $3.50/mês
   - WordPress/PHP pré-configurado
   - Firewall configurável

3. **Google Cloud Platform** - $10/mês (créditos grátis)
   - Compute Engine + LAMP Stack

4. **Locaweb VPS** - A partir de R$ 45/mês (Brasil)
   - Suporte em português
   - Já configurado para PHP

---

## 🧪 TESTES DE CONECTIVIDADE

### Teste 1: Verificar se o servidor consegue acessar a SEFAZ

Crie um arquivo `test_sefaz.php` na raiz:

```php
<?php
$url = "https://nfe.svrs.rs.gov.br/ws/NfeAutorizacao/NFeAutorizacao4.asmx";

echo "<h2>Teste de Conectividade SEFAZ</h2>";

// Teste 1: file_get_contents
echo "<h3>1. Teste com file_get_contents:</h3>";
$context = stream_context_create([
    'http' => [
        'timeout' => 30
    ]
]);
$result = @file_get_contents($url, false, $context);
echo $result ? "✅ SUCESSO" : "❌ FALHOU";

// Teste 2: cURL
echo "<h3>2. Teste com cURL:</h3>";
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2
]);
$response = curl_exec($ch);
$error = curl_error($ch);
curl_close($ch);

if ($response) {
    echo "✅ SUCESSO<br>";
} else {
    echo "❌ ERRO: " . $error;
}

// Teste 3: Informações do servidor
echo "<h3>3. Informações do Servidor:</h3>";
echo "PHP Version: " . phpversion() . "<br>";
echo "cURL Enabled: " . (function_exists('curl_version') ? '✅ SIM' : '❌ NÃO') . "<br>";
echo "OpenSSL: " . OPENSSL_VERSION_TEXT . "<br>";
echo "allow_url_fopen: " . (ini_get('allow_url_fopen') ? '✅ ON' : '❌ OFF') . "<br>";

// Teste 4: Portas abertas
echo "<h3>4. Teste de Porta 443:</h3>";
$fp = @fsockopen("nfe.svrs.rs.gov.br", 443, $errno, $errstr, 10);
if ($fp) {
    echo "✅ Porta 443 ABERTA";
    fclose($fp);
} else {
    echo "❌ Porta 443 BLOQUEADA: $errstr ($errno)";
}
?>
```

Acesse: `https://seudominio.com.br/test_sefaz.php`

---

## 📊 Resultados Esperados

### ✅ Se Funcionar:
- Você verá "✅ SUCESSO" em todos os testes
- A emissão de NF-e voltará a funcionar

### ❌ Se Continuar Falhando:
- Teste 4 mostrará "Porta 443 BLOQUEADA"
- **CONCLUSÃO**: Firewall da Hostgator está bloqueando
- **AÇÃO**: Abrir ticket com suporte OU migrar para VPS

---

## 📝 Checklist

- [ ] Implementadas melhorias no código (já feito)
- [ ] Aberto ticket no suporte Hostgator
- [ ] Executado test_sefaz.php
- [ ] Aguardado resposta do suporte (2-3 dias úteis)
- [ ] Se não resolver: avaliar migração para VPS

---

## 🆘 Suporte

Se precisar de ajuda adicional:

1. **Logs do Sistema**: Verifique em `error_log` na raiz do Dolibarr
2. **Logs da NFe**: Veja mensagens detalhadas no código
3. **Suporte Hostgator**: Chat ao vivo ou ticket

---

## 📌 Observações Importantes

- ✅ **Localhost funciona** porque não tem firewall restritivo
- ❌ **Produção falha** devido a políticas da Hostgator
- 🎯 **Solução definitiva**: VPS com controle total
- ⚡ **Solução rápida**: Suporte Hostgator liberar webservices

**Última atualização**: 22/12/2025
