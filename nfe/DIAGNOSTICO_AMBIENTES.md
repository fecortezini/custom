# 🔧 Diagnóstico: Ambiente Funciona vs Ambiente com Erro

## 🎯 Situação Atual

- ✅ **demo.labconnecta.com.br** → Emite NFe NORMALMENTE
- ❌ **dev1.labconnecta.com.br** → Erro de conexão SOAP

**Conclusão**: Não é problema da Hostgator em geral, mas sim **configuração específica do servidor dev1**.

---

## 📋 PASSO A PASSO PARA RESOLVER

### 1️⃣ Execute o Script de Comparação

Acesse nos **DOIS** servidores:

```
✅ https://demo.labconnecta.com.br/comparar_ambientes.php
❌ https://dev1.labconnecta.com.br/comparar_ambientes.php
```

O script vai mostrar:
- Versão do PHP
- Extensões instaladas (cURL, OpenSSL, SOAP)
- Configurações php.ini
- Teste de conectividade em tempo real
- Diagnóstico automático

### 2️⃣ Compare os Resultados

Procure por **diferenças** entre os dois servidores:

#### Principais Pontos a Verificar:

| Configuração | O que Verificar |
|---|---|
| **Versão PHP** | Deve ser >= 7.4 nos dois |
| **cURL** | Deve estar habilitado nos dois |
| **OpenSSL** | Deve estar habilitado nos dois |
| **SOAP** | Deve estar habilitado nos dois |
| **Porta 443** | Deve estar ABERTA nos dois |
| **allow_url_fopen** | Deve ser ON nos dois |

### 3️⃣ Causas Mais Prováveis

#### ❌ Causa 1: cURL Desabilitado no dev1
```
Sintoma: O script mostrará "❌ cURL NÃO INSTALADO"
Solução: Habilitar extensão php-curl no cPanel ou via suporte
```

#### ❌ Causa 2: OpenSSL Desatualizado/Desabilitado
```
Sintoma: Conexão HTTPS falha com erro de certificado
Solução: Atualizar OpenSSL via suporte Hostgator
```

#### ❌ Causa 3: Versão PHP Diferente
```
Sintoma: dev1 está em PHP 5.x ou 7.0-7.3
Solução: Atualizar para PHP 7.4+ no cPanel
```

#### ❌ Causa 4: Firewall Específico do Servidor dev1
```
Sintoma: Porta 443 BLOQUEADA apenas no dev1
Solução: Suporte Hostgator liberar firewall
```

#### ❌ Causa 5: Certificados CA Desatualizados
```
Sintoma: Erro "SSL certificate problem"
Solução: Suporte Hostgator atualizar certificados raiz
```

---

## 🔧 SOLUÇÕES RÁPIDAS (Você Mesmo)

### Solução 1: Atualizar Versão do PHP (cPanel)

1. Acesse o **cPanel** do dev1
2. Procure por "**Select PHP Version**" ou "**MultiPHP Manager**"
3. Selecione **PHP 7.4** ou **PHP 8.0**
4. Salve e teste novamente

### Solução 2: Habilitar Extensões PHP (cPanel)

1. No cPanel, vá em "**Select PHP Version**"
2. Clique em "**Extensions**" ou "**Módulos**"
3. Marque as seguintes extensões:
   - ✅ **curl**
   - ✅ **openssl**
   - ✅ **soap**
   - ✅ **mbstring**
   - ✅ **json**
4. Salve e teste

### Solução 3: Ajustar php.ini (se tiver acesso)

Adicione/edite no arquivo `.user.ini` ou `php.ini`:

```ini
; Timeout
default_socket_timeout = 60
max_execution_time = 120

; Conexões externas
allow_url_fopen = On

; cURL
extension=curl

; OpenSSL
extension=openssl

; SOAP
extension=soap
```

---

## 📞 SOLUÇÃO VIA SUPORTE HOSTGATOR

Se as soluções acima não funcionarem, abra um ticket:

### Template de Ticket:

```
Assunto: Diferença de configuração entre servidores - Emissão NFe

Prezados,

Tenho dois servidores na Hostgator:
- demo.labconnecta.com.br (FUNCIONA perfeitamente)
- dev1.labconnecta.com.br (NÃO FUNCIONA - erro de conexão SOAP)

Preciso que IGUALEM as configurações do dev1 com as do demo, especialmente:

1. Habilitar extensões PHP:
   - php-curl
   - php-openssl
   - php-soap

2. Liberar conexões HTTPS para:
   - nfe.svrs.rs.gov.br (porta 443)
   - www.sefazvirtual.fazenda.gov.br (porta 443)

3. Atualizar certificados SSL/CA se necessário

Erro atual no dev1:
"Failed to connect to nfe.svrs.rs.gov.br port 443"

Para referência, executei diagnóstico em:
https://dev1.labconnecta.com.br/comparar_ambientes.php

Aguardo retorno.

Atenciosamente,
[Seu Nome]
```

---

## 🧪 TESTE RÁPIDO VIA SSH (Se tiver acesso)

Se você tem acesso SSH ao dev1:

```bash
# Teste 1: Verificar versão PHP
php -v

# Teste 2: Verificar extensões
php -m | grep -E "curl|openssl|soap"

# Teste 3: Testar conectividade
curl -v https://nfe.svrs.rs.gov.br/ws/NfeAutorizacao/NFeAutorizacao4.asmx

# Teste 4: Comparar php.ini
php -i | grep -E "curl|openssl|soap|allow_url_fopen"
```

---

## ✅ CHECKLIST DE VERIFICAÇÃO

Marque conforme for testando:

### No servidor demo (FUNCIONA):
- [ ] Acessar comparar_ambientes.php
- [ ] Copiar configurações JSON
- [ ] Anotar versão PHP
- [ ] Anotar extensões habilitadas

### No servidor dev1 (NÃO FUNCIONA):
- [ ] Acessar comparar_ambientes.php
- [ ] Identificar diferenças
- [ ] Tentar habilitar extensões no cPanel
- [ ] Tentar atualizar versão PHP
- [ ] Se não resolver, abrir ticket

### Após correções:
- [ ] Testar emissão de NFe
- [ ] Verificar logs em error_log
- [ ] Confirmar que funciona normalmente

---

## 🎯 PRÓXIMOS PASSOS

1. **AGORA**: Acesse os dois scripts de comparação
2. **EM 5 MIN**: Identifique as diferenças
3. **EM 10 MIN**: Tente corrigir via cPanel
4. **SE NÃO RESOLVER**: Abra ticket com o suporte

---

## 📊 Diferenças Típicas Entre Servidores

| Item | demo (OK) | dev1 (ERRO) | Solução |
|---|---|---|---|
| PHP | 7.4 | 5.6 | Atualizar no cPanel |
| cURL | ✅ ON | ❌ OFF | Habilitar extensão |
| OpenSSL | 1.1.1 | 1.0.0 | Atualizar via suporte |
| Porta 443 | ✅ Aberta | ❌ Bloqueada | Liberar firewall |

**Última atualização**: 22/12/2025
