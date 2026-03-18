# 🎯 FLUXO AUTOMÁTICO - CertificateConverter

## ✅ Implementação Atual (SIMPLIFICADA)

### 📌 O Que Acontece Quando o Usuário Faz Upload

```
USUÁRIO FAZ UPLOAD → CertificateConverter::ensureCompatiblePfx() → SALVA NO BANCO
                                    ↓
                        Totalmente automático e transparente
```

---

## 🔄 Fluxo Interno Detalhado

### **1. Tentativa Direta (Certificado Moderno)**
```php
openssl_pkcs12_read() // Tenta ler diretamente
✓ Sucesso → Retorna sem conversão
✗ Falha → Próximo método
```

### **2. Conversão Automática (Certificado Legado)**
```php
Detecta RC2/RC4/DES → Usa OpenSSL CLI:
   1) openssl pkcs12 -legacy -in old.pfx -out temp.pem
   2) openssl pkcs12 -export -certpbe AES-256-CBC -out new.pfx
   3) Valida novo .pfx
✓ Sucesso → Retorna certificado convertido
✗ Falha → Retorna erro amigável
```

---

## ⚙️ Características Técnicas

| Aspecto | Implementação |
|---------|---------------|
| **Altera .conf do sistema?** | ❌ NÃO - Usa flag `-legacy` do OpenSSL 3+ |
| **Requer phpseclib?** | ❌ NÃO - Apenas OpenSSL CLI (já existe em todo servidor) |
| **Funciona no XAMPP?** | ✅ SIM - Usa `C:\xampp\apache\bin\openssl.exe` |
| **Funciona no HostGator?** | ✅ SIM - Usa `/usr/bin/openssl` |
| **Requer exec()?** | ✅ SIM - Mas está habilitado na maioria dos servidores |
| **Cria arquivos permanentes?** | ❌ NÃO - Usa `tempnam()` e apaga no `finally{}` |
| **Visível para o usuário?** | ❌ NÃO - Totalmente transparente (apenas log interno) |

---

## 💻 Código de Integração

### No arquivo de upload (ex: `admin/setup.php`):

```php
// 1. No topo do arquivo:
require_once DOL_DOCUMENT_ROOT . '/custom/labapp/class/CertificateConverter.php';

// 2. Substituir este bloco:
if (!empty($cert_file['tmp_name'])) {
    if (preg_match('/\.pfx$/i', $cert_file['name'])) {
        $cert_data = file_get_contents($cert_file['tmp_name']);
        
        // ADICIONAR ESTAS LINHAS:
        $converter = new CertificateConverter();
        $result = $converter->ensureCompatiblePfx($cert_data, $cert_pass);
        
        if ($result === false) {
            // Erro
            $error++;
            setEventMessages($converter->getLastError(), null, 'errors');
        } else {
            // Sucesso - usar certificado (convertido ou não)
            $cert_data_to_save = $result['pfx_content'];
            
            // Opcional: avisar se foi convertido
            if ($result['converted']) {
                setEventMessages("Certificado convertido automaticamente", null, 'warnings');
            }
            
            // Salvar no banco
            $sql = "INSERT INTO ".MAIN_DB_PREFIX."nfe_config (name, value) 
                    VALUES ('cert_pfx', '".$db->escape($cert_data_to_save)."')
                    ON DUPLICATE KEY UPDATE value = '".$db->escape($cert_data_to_save)."'";
            $resql = $db->query($sql);
            if (!$resql) {
                $error++;
                setEventMessages($db->lasterror(), null, 'errors');
            }
        }
    }
}
```

---

## 📊 Cenários de Uso

### ✅ Cenário 1: Certificado Moderno (AES-256)
```
Upload → Leitura direta OK → Salva original → FIM
Tempo: <1 segundo
```

### ✅ Cenário 2: Certificado Legado (RC2-40-CBC)
```
Upload → Falha na leitura → Detecta RC2 → Converte via OpenSSL → Salva convertido → FIM
Tempo: 2-3 segundos
```

### ❌ Cenário 3: Senha Incorreta
```
Upload → Falha → Detecta "bad password" → Retorna erro claro → FIM
Mensagem: "Senha incorreta para o certificado"
```

---

## 🔍 Logs de Debug

O sistema gera logs automáticos (visíveis em Apache error.log):

```
[CertConverter] Algoritmo legado detectado, convertendo automaticamente...
[CertConverter] Conversão concluída com sucesso
```

Ou em caso de erro:
```
[CertConverter] Erro: OpenSSL não encontrado
```

---

## 🎉 Resultado Final

**Para o usuário:** Faz upload, clica em "Salvar" → **FUNCIONA**  
**Por trás:** Sistema detecta, converte, valida e salva automaticamente  
**Sem:** Mexer em configs, instalar bibliotecas, scripts manuais

---

## ❓ FAQ

**Q: E se o exec() estiver desabilitado no servidor?**  
A: O método vai falhar e retornar erro. Neste caso, o usuário precisará reconverter o certificado manualmente.

**Q: Por que não usar phpseclib?**  
A: phpseclib3 NÃO tem suporte para PKCS#12 (.pfx). Só suporta X509 e chaves individuais.

**Q: Vai funcionar em certificados ICP-Brasil?**  
A: SIM. Certificados ICP-Brasil modernos (pós-2020) já usam AES-256. Certificados antigos (2010-2019) usam RC2-40-CBC e serão convertidos automaticamente.

**Q: Preciso fazer upload do Composer no HostGator?**  
A: NÃO. phpseclib não é mais necessária. Apenas o OpenSSL CLI (que já existe).
