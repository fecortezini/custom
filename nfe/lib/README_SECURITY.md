# 🔐 Biblioteca de Segurança para Certificados Digitais

Funções de criptografia AES-256-GCM para proteger senhas de certificados digitais (A1) usados na emissão de documentos fiscais eletrônicos.

## 📦 Arquivos criados

- `custom/nfe/lib/nfe_security.lib.php` - Para NF-e
- `custom/cte/lib/cte_security.lib.php` - Para CT-e  
- `custom/nfse/lib/nfse_security.lib.php` - Para NFS-e

## 🚀 Como usar

### 1. Incluir o arquivo no seu código

```php
// Para NF-e
require_once DOL_DOCUMENT_ROOT.'/custom/nfe/lib/nfe_security.lib.php';

// Para CT-e
require_once DOL_DOCUMENT_ROOT.'/custom/cte/lib/cte_security.lib.php';

// Para NFS-e
require_once DOL_DOCUMENT_ROOT.'/custom/nfse/lib/nfse_security.lib.php';
```

### 2. Criptografar senha antes de salvar

```php
// NF-e
$senhaCriptografada = nfeEncryptPassword($senhaTextoPlano, $db);
// Salvar $senhaCriptografada no banco

// CT-e
$senhaCriptografada = cteEncryptPassword($senhaTextoPlano, $db);

// NFS-e
$senhaCriptografada = nfseEncryptPassword($senhaTextoPlano, $db);
```

### 3. Descriptografar senha ao usar

```php
// NF-e
$senhaOriginal = nfeDecryptPassword($senhaCriptografada, $db);

// CT-e
$senhaOriginal = cteDecryptPassword($senhaCriptografada, $db);

// NFS-e
$senhaOriginal = nfseDecryptPassword($senhaCriptografada, $db);
```

## 💡 Exemplo prático

```php
require_once DOL_DOCUMENT_ROOT.'/custom/nfe/lib/nfe_security.lib.php';

// 1. Ao salvar a senha do certificado
$senhaDigitada = $_POST['cert_password'];
$senhaCriptografada = nfeEncryptPassword($senhaDigitada, $db);

$sql = "INSERT INTO ".MAIN_DB_PREFIX."nfe_config (name, value) 
        VALUES ('cert_pass', '".$db->escape($senhaCriptografada)."')";
$db->query($sql);

// 2. Ao usar o certificado para assinar NF-e
$res = $db->query("SELECT value FROM ".MAIN_DB_PREFIX."nfe_config WHERE name='cert_pass'");
$obj = $db->fetch_object($res);
$senhaCriptografada = $obj->value;

// Descriptografa antes de usar
$senhaDescriptografada = nfeDecryptPassword($senhaCriptografada, $db);

// Usa a senha descriptografada com o certificado
$certificado = new NfeCertificate();
$certificado->loadFromPfx($pfxContent, $senhaDescriptografada);

// IMPORTANTE: limpe a senha da memória após usar
unset($senhaDescriptografada);
```

## 🔒 Segurança

### Tecnologias utilizadas

- **AES-256-GCM**: Criptografia autenticada (AEAD)
- **PBKDF2**: Derivação de chave com 100.000 iterações
- **IV aleatório**: 12 bytes únicos por criptografia
- **TAG de autenticação**: 16 bytes para verificar integridade

### Proteções implementadas

✅ Criptografia forte (256 bits)  
✅ Autenticação de dados (detecta adulteração)  
✅ Chave mestra gerada automaticamente  
✅ Armazenamento seguro no banco de dados  
✅ Sem configuração manual necessária  

## ⚠️ Boas práticas

### ✅ FAÇA

```php
// Limpe variáveis sensíveis após uso
$senha = nfeDecryptPassword($enc, $db);
// ... usa a senha ...
unset($senha);

// Sempre passe o objeto $db como parâmetro
$senha = nfeDecryptPassword($senhaCriptografada, $db);
```

### ❌ NÃO FAÇA

```php
// NÃO exponha senhas em logs
error_log($senhaDescriptografada); // NUNCA!

// NÃO imprima senhas em HTML
echo $senhaDescriptografada; // NUNCA!

// NÃO armazene senhas descriptografadas
$_SESSION['senha'] = $senhaDescriptografada; // NUNCA!
```

## 📋 Requisitos

- PHP 7.2+
- OpenSSL habilitado
- Dolibarr com banco de dados configurado
- Tabela `nfe_config` criada

## 🔧 Solução de problemas

### Erro: "Call to undefined function nfeDecryptPassword"

**Causa**: Arquivo não incluído  
**Solução**: Adicione `require_once DOL_DOCUMENT_ROOT.'/custom/nfe/lib/nfe_security.lib.php';`

### Senha descriptografada retorna vazio

**Causa**: Dados corrompidos ou chave mestra alterada  
**Solução**: Re-criptografe a senha com a função `nfeEncryptPassword()`

### Primeira execução lenta

**Causa**: Geração da chave mestra (PBKDF2 com 100k iterações)  
**Solução**: Normal. Execuções seguintes serão rápidas (chave já criada)

## 📝 Notas técnicas

- A chave mestra é armazenada em `nfe_config.encryption_master_key`
- Todas as funções compartilham a mesma chave mestra
- O formato de saída é: `base64(IV[12] | TAG[16] | CIPHERTEXT)`
- Compatível com hospedagens compartilhadas (Hostgator, etc)

## 🎯 Compatibilidade

| Módulo | Arquivo | Funções |
|--------|---------|---------|
| NF-e | `nfe_security.lib.php` | `nfeEncryptPassword()`, `nfeDecryptPassword()` |
| CT-e | `cte_security.lib.php` | `cteEncryptPassword()`, `cteDecryptPassword()` |
| NFS-e | `nfse_security.lib.php` | `nfseEncryptPassword()`, `nfseDecryptPassword()` |

---

**Desenvolvido para Dolibarr ERP - Módulos fiscais brasileiros**
