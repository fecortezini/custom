# Injeção de Campos Fiscais + Certificado Digital em admin/company.php via Hook

> **Módulo responsável:** Lab Connecta (`custom/labapp`)  
> **Arquivo principal:** `custom/labapp/class/actions_labapp.class.php`  
> **Classe:** `ActionsLabapp`  
> **Data de criação:** 2026-02-27  
> **Última atualização:** 2026-02-27 (adição de certificado/ambiente)  

---

## Índice

1. [Contexto e Motivação](#1-contexto-e-motivação)
2. [O Problema: Edição Manual no Core](#2-o-problema-edição-manual-no-core)
3. [A Solução: Sistema de Hooks + ob_start()](#3-a-solução-sistema-de-hooks--ob_start)
4. [Análise de Segurança Completa](#4-análise-de-segurança-completa)
5. [Fluxo de Execução (Passo a Passo)](#5-fluxo-de-execução-passo-a-passo)
6. [Código Completo com Comentários](#6-código-completo-com-comentários)
   - 6.1 [actions_labapp.class.php](#61-actions_labappclassphp)
   - 6.2 [Trecho do modLabApp.class.php (hooks)](#62-trecho-do-modlabappclassphp-hooks)
   - 6.3 [admin/company.php (estado limpo)](#63-admincompanyphp-estado-limpo)
7. [Tabela de Campos Injetados](#7-tabela-de-campos-injetados)
8. [Seção de Certificado Digital / Ambiente](#8-seção-de-certificado-digital--ambiente)
9. [Conversão de Certificado (NfeCertificate)](#9-conversão-de-certificado-nfecertificate)
10. [Análise de Melhorias na Conversão](#10-análise-de-melhorias-na-conversão)
11. [Onde os Valores São Consumidos](#11-onde-os-valores-são-consumidos)
12. [Como o Dolibarr Descobre e Executa a Classe](#12-como-o-dolibarr-descobre-e-executa-a-classe)
13. [Guia de Manutenção](#13-guia-de-manutenção)
14. [Troubleshooting](#14-troubleshooting)
15. [Diagramas](#15-diagramas)
16. [Histórico Completo](#16-histórico-completo)

---

## 1. Contexto e Motivação

A página **Início > Configuração > Empresa/Organização** (`admin/company.php`) do Dolibarr permite configurar os dados cadastrais da empresa: razão social, CNPJ, endereço, telefone, etc.

Para emissão de documentos fiscais eletrônicos brasileiros (**NFSe**, **NFe**, **MDFe**, **CTe**), são necessários campos que **não existem nativamente** no Dolibarr:

| Campo | Necessidade |
|-------|-------------|
| Nome Fantasia | Obrigatório no XML fiscal como `xFant` |
| Rua, Bairro, Número | O Dolibarr tem apenas um campo "Address" unificado |
| CNAE | Código Nacional de Atividade Econômica (obrigatório NFSe) |
| CRT | Código de Regime Tributário (obrigatório NFe/NFSe) |
| Regime de Tributação | Regime especial para NFS-e |
| Incentivo Fiscal | Indicador de incentivo (NFS-e) |

---

## 2. O Problema: Edição Manual no Core

### Como era antes (MÁ PRÁTICA):

O código foi inserido **diretamente** em `admin/company.php` em dois locais:

**Local 1 — Bloco de salvamento (~linha 134):**
```php
// CÓDIGO QUE FOI REMOVIDO do admin/company.php:
dolibarr_set_const($db, "MAIN_INFO_NOME_FANTASIA", GETPOST("MAIN_INFO_NOME_FANTASIA", 'alphanohtml'), 'chaine', 0, '', $conf->entity);
dolibarr_set_const($db, "MAIN_INFO_RUA", GETPOST("MAIN_INFO_RUA", 'alphanohtml'), 'chaine', 0, '', $conf->entity);
dolibarr_set_const($db, "MAIN_INFO_BAIRRO", GETPOST("MAIN_INFO_BAIRRO", 'alphanohtml'), 'chaine', 0, '', $conf->entity);
dolibarr_set_const($db, "MAIN_INFO_NUMERO", GETPOST("MAIN_INFO_NUMERO", 'alphanohtml'), 'chaine', 0, '', $conf->entity);
dolibarr_set_const($db, "MAIN_INFO_CNAE", GETPOST("MAIN_INFO_CNAE", 'alphanohtml'), 'chaine', 0, '', $conf->entity);
dolibarr_set_const($db, "MAIN_INFO_INCENTIVOFISCAL", GETPOST("MAIN_INFO_INCENTIVOFISCAL", 'alphanohtml'), 'chaine', 0, '', $conf->entity);
dolibarr_set_const($db, "MAIN_INFO_REGIMETRIBUTACAO", GETPOST("MAIN_INFO_REGIMETRIBUTACAO", 'alphanohtml'), 'chaine', 0, '', $conf->entity);
$crt = GETPOST("MAIN_INFO_CRT", 'int');
if (in_array($crt, [1, 2, 3])) {
    dolibarr_set_const($db, "MAIN_INFO_CRT", $crt, 'chaine', 0, '', $conf->entity);
} else {
    setEventMessages('CRT deve ser 1, 2 ou 3', null, 'errors');
    $error++;
}
```

**Local 2 — Bloco HTML (~linha 512-586):**
```php
// CÓDIGO QUE FOI REMOVIDO do admin/company.php:
// Nome Fantasia
print '<tr class="oddeven">';
print '<td width="25%">'.$langs->trans("Nome Fantasia").'</td>';
print '<td>';
print '<input type="text" class="flat minwidth300" name="MAIN_INFO_NOME_FANTASIA" value="'.($conf->global->MAIN_INFO_NOME_FANTASIA ?? '').'">';
print '</td>';
print '</tr>';

// Bairro
print '<tr class="oddeven">';
print '<td width="25%">Bairro</td>';
print '<td>';
print '<input type="text" class="flat minwidth300" name="MAIN_INFO_BAIRRO" value="'.($conf->global->MAIN_INFO_BAIRRO ?? '').'">';
print '</td>';
print '</tr>';

// Rua
print '<tr class="oddeven">';
print '<td width="25%">Rua</td>';
print '<td>';
print '<input type="text" class="flat minwidth300" name="MAIN_INFO_RUA" value="'.($conf->global->MAIN_INFO_RUA ?? '').'">';
print '</td>';
print '</tr>';

// Numero
print '<tr class="oddeven">';
print '<td width="25%">Número</td>';
print '<td>';
print '<input type="text" class="flat minwidth150" name="MAIN_INFO_NUMERO" ...>';
print '</td>';
print '</tr>';

// CRT
print '<tr class="oddeven">';
print '<td>'.$langs->trans("CRT").'</td>';
print '<td>';
print $form->selectarray('MAIN_INFO_CRT', array(
    1 => 'Simples Nacional',
    2 => 'Simples Nacional com excesso',
    3 => 'Lucro Real',
    4 => 'Lucro Presumido'
), ($conf->global->MAIN_INFO_CRT ?? 1));
print '</td>';
print '</tr>';

// REGIME ESPECIAL DE TRIBUTACAO
print '<tr class="oddeven">';
print '<td>'.$langs->trans("Regime Especial (NFS-e)").'</td>';
print '<td>';
print $form->selectarray('MAIN_INFO_REGIMETRIBUTACAO', array(
    1 => 'Microempresa Municipal',
    2 => 'Estimativa',
    3 => 'Sociedade de Profissionais',
    4 => 'Cooperativa',
    5 => 'Microempresario Individual (MEI)',
    6 => 'Microempresa ou Empresa de Pequeno Porte (ME/EPP)'
), ($conf->global->MAIN_INFO_REGIMETRIBUTACAO ?? 1));
print '</td>';
print '</tr>';

// Incentivo Fiscal
print '<tr class="oddeven">';
print '<td>'.$langs->trans("Incentivo Fiscal (NFS-e)").'</td>';
print '<td>';
print $form->selectarray('MAIN_INFO_INCENTIVOFISCAL', array(
    1 => 'Sim',
    2 => 'Não'
), ($conf->global->MAIN_INFO_INCENTIVOFISCAL ?? 2));
print '</td>';
print '</tr>';
```

### Por que isso era ruim:

| Problema | Impacto |
|----------|---------|
| Atualizações do Dolibarr sobrescrevem `admin/company.php` | Campos fiscais desaparecem após update |
| Código customizado misturado com código core | Difícil rastrear o que foi modificado |
| Sem versionamento separado | Conflitos de merge em qualquer atualização |
| Outro desenvolvedor não sabe que foi adicionado | Risco de remover sem querer |

---

## 3. A Solução: Sistema de Hooks + ob_start()

### Por que hooks?

O Dolibarr tem um sistema de "hooks" que permite que módulos customizados interceptem e estendam páginas core sem editá-las. Os hooks são pontos de extensão declarados nas páginas core.

### O problema específico do admin/company.php:

A página `admin/company.php` só declara **1 hook**: `doActions`. Diferente de `compta/facture/card.php` que oferece vários hooks (`formObjectOptions`, `addMoreActionsButtons`, `formConfirm`, etc.), a página da empresa **não tem hook para injetar HTML no formulário**.

```php
// admin/company.php, linha 75:
$hookmanager->initHooks(array('admincompany', 'globaladmin'));

// admin/company.php, linha 83:
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action);
// ↑ ESTE É O ÚNICO HOOK — executado ANTES do HTML ser renderizado
```

### A técnica: ob_start() com callback

```
TIMELINE DE EXECUÇÃO:
═══════════════════════════════════════════════════════════
    TEMPO →

    [1]              [2]              [3]              [4]
    doActions        Renderização     ob_end_flush     Navegador
    (hook)           HTML             (callback)       executa JS
    ┃                ┃                ┃                ┃
    ┃ ob_start()     ┃ print '<tr>'   ┃ Recebe HTML    ┃ DOM manipulation
    ┃ (abre buffer)  ┃ print '<td>'   ┃ Injeta <script>┃ createElement()
    ┃                ┃ print '...'    ┃ Retorna HTML   ┃ insertBefore()
    ┃ Salva dados    ┃ llxFooter()    ┃ modificado     ┃ Campos aparecem!
    ┃ (se update)    ┃                ┃                ┃
═══════════════════════════════════════════════════════════
```

---

## 4. Análise de Segurança Completa

### É seguro? **SIM.** Veja a análise ponto a ponto:

| Vetor de Ataque | Proteção Implementada | Risco |
|-|--|--|
| **SQL Injection** | `dolibarr_set_const()` usa `$db->escape()`. `nfeConfigUpsert()` usa `$db->escape()`. Não há SQL concatenado com input. | **ZERO** |
| **XSS (Cross-Site Scripting)** | Valores no JS usam `addslashes()`. Campos criados via `createElement()` (não `innerHTML` com dados). Badges usam `innerHTML` mas com strings fixas (sem input do user). | **ZERO** |
| **Acesso não autorizado** | A página `admin/company.php` já exige `$user->admin`. O hook herda essa proteção. | **ZERO** |
| **Sanitização de input** | `GETPOST()` com filtros `'alphanohtml'`, `'int'`, `'restricthtml'`. | **Adequado** |
| **CSRF (Cross-Site Request Forgery)** | Form principal: token nativo Dolibarr. Form de certificado: validação manual `$_POST['token'] !== $_SESSION['newtoken']`. | **Adequado** |
| **Output Buffer (ob_start)** | Closure PHP server-side. Buffers aninham corretamente (LIFO). | **Seguro** |
| **Senha do certificado** | Criptografada com AES-256-GCM + PBKDF2 (100k iterações). Chave mestra no banco. Nunca exibida no HTML. | **Forte** |
| **Certificado em memória** | O PFX trafega via `$_FILES` → `file_get_contents` → DB. Nenhum arquivo permanente no disco. | **Adequado** |
| **Conversão de certificado** | Arquivos temp com zero-fill antes de `unlink()`. Senha passada via arquivo (não CLI). | **Seguro** |
| **Upload malicioso** | Validação de tamanho (max 100KB). `is_uploaded_file()` verifica legitimidade. `processAndConvert()` valida o PFX. | **Adequado** |

### Comparação com a abordagem anterior (código manual):

| Aspecto | Código Manual no Core | Hook + ob_start() |
|---|---|---|
| Resistente a updates | ❌ Perde na atualização | ✅ Isolado em `custom/` |
| SQL Injection | ~ Dependia do dev | ✅ dolibarr_set_const() |
| XSS | ~ Alguns campos sem escape | ✅ addslashes() + createElement() |
| Rastreabilidade | ❌ Misturado no core | ✅ Arquivo dedicado com documentação |
| Versionamento | ❌ Conflitos de merge | ✅ Arquivo independente |

---

## 5. Fluxo de Execução (Passo a Passo)

### 5.1. Quando o usuário ABRE admin/company.php:

```
Passo 1: O PHP carrega main.inc.php
         → Inicializa $db, $conf, $user, $langs, $hookmanager

Passo 2: admin/company.php chama:
         $hookmanager->initHooks(array('admincompany', 'globaladmin'));
         → Dolibarr lê $conf->modules_parts['hooks']['labapp']
           (carregado da constante MAIN_MODULE_LABAPP_HOOKS)
         → Contexto 'admincompany' está na lista → match!
         → Carrega: /labapp/class/actions_labapp.class.php
         → Instancia: new ActionsLabapp($db)

Passo 3: admin/company.php chama:
         $hookmanager->executeHooks('doActions', $parameters, $object, $action);
         → O HookManager chama: ActionsLabapp::doActions($parameters, $object, $action, ...)
         → $action é '' (vazio, pois o usuário apenas abriu a página)
         → O método NÃO entra no bloco de salvamento (if action=update)
         → O método ABRE ob_start(callback)
         → Retorna 0

Passo 4: admin/company.php renderiza o formulário normalmente:
         print '<tr>...Phone...</tr>';
         print '<tr>...Email...</tr>';
         etc.
         → TODO este HTML vai para o buffer de saída (não diretamente para o navegador)

Passo 5: admin/company.php chama llxFooter():
         → llxFooter() internamente chama ob_end_flush()
         → O callback do ob_start recebe TODO o HTML como string
         → O callback localiza '</body>' e insere '<script>...' antes dele
         → O HTML modificado é enviado ao navegador

Passo 6: O navegador recebe o HTML e:
         → Parseia o DOM
         → Dispara o evento DOMContentLoaded
         → O JavaScript é executado:
            a. Localiza label[for="phone"] → .closest('tr') → phoneRow
            b. Para cada campo: createElement('tr') → createElement('input') → insertBefore(phoneRow)
         → Os campos ficam visíveis no formulário, DENTRO do <form>

Passo 7: O usuário vê os campos "Dados NFSe / NFe" no formulário
```

### 5.2. Quando o usuário SALVA o formulário:

```
Passo 1: O formulário faz POST com TODOS os campos (nativos + customizados)
         → Os campos customizados estão no POST porque:
            a. Foram criados dentro do <form> via DOM (insertBefore na tbodydo form)
            b. Cada input tem atributo "name" correspondente à constante Dolibarr

Passo 2: admin/company.php recebe o POST:
         $action = 'update'

Passo 3: $hookmanager->executeHooks('doActions', ...)
         → ActionsLabapp::doActions() é chamado
         → $action === 'update' → ENTRA no bloco de salvamento
         → Para cada campo texto:
              GETPOSTISSET('MAIN_INFO_NOME_FANTASIA') → true
              GETPOST('MAIN_INFO_NOME_FANTASIA', 'alphanohtml') → "Minha Empresa"
              dolibarr_set_const($db, 'MAIN_INFO_NOME_FANTASIA', "Minha Empresa", ...)
              → INSERT/UPDATE llx_const SET name='MAIN_INFO_NOME_FANTASIA', value='Minha Empresa'
         → Para CRT:
              GETPOST('MAIN_INFO_CRT', 'int') → 1
              in_array(1, [1,2,3,4]) → true
              dolibarr_set_const($db, 'MAIN_INFO_CRT', 1, ...)
         → Abre ob_start() novamente (para exibir campos atualizados)
         → Retorna 0

Passo 4: O código NATIVO de company.php TAMBÉM executa os seus dolibarr_set_const():
         → Razão social, CNPJ, endereço, etc. (campos nativos)
         (Nosso hook NÃO interfere nisso — retornamos 0)

Passo 5: Formulário é renderizado com valores atualizados
         → Callback injeta JS → Campos aparecem com novos valores
```

### 5.3. Quando o usuário SALVA o formulário de certificado:

```
Passo 1: O formulário de certificado é um <form> SEPARADO
         → enctype="multipart/form-data" (necessário para file upload)
         → action=savesetup (diferente do update principal)

Passo 2: admin/company.php recebe o POST:
         $action = 'savesetup'

Passo 3: $hookmanager->executeHooks('doActions', ...)
         → ActionsLabapp::doActions() é chamado
         → doActionsAdminCompany() PARTE 2:
            a. Valida CSRF token (newtoken vs token do POST)
            b. Salva ambiente ('1'=produção / '2'=homologação)
            c. Se senha informada → criptografa via nfeEncryptPassword() → salva
            d. Se PFX enviado:
               → fread() até 100KB
               → NfeCertificate::processAndConvert() tenta modernizar PFX
               → Se conversão OK → salva PFX convertido
               → Se falha → salva PFX original como fallback
            e. Abre ob_start() novamente
            f. Retorna 0 (não bloqueia fluxo nativo)

Passo 4: O código nativo de company.php NÃO executa nada para savesetup
         → company.php só reconhece action=update para seus campos

Passo 5: Formulário renderizado com valores atualizados
         → Callback injeta ambos os scripts (campos fiscais + certificado)
```

---

## 6. Código Completo com Comentários

### 6.1. actions_labapp.class.php

**Caminho:** `custom/labapp/class/actions_labapp.class.php`

O arquivo completo agora contém ~580 linhas com **3 partes principais**:

| Parte | Action | O que faz |
|-------|--------|-----------|
| PARTE 1 | `update` / `updateedit` | Salva campos fiscais em `llx_const` |
| PARTE 2 | `savesetup` | Salva ambiente, senha (criptografada), certificado em `llx_nfe_config` |
| PARTE 3 | _(sempre)_ | `ob_start(callback)` que injeta 2 scripts: A (campos) e B (cert form) |

E **2 helpers estáticos**:

| Método | Propósito |
|--------|-----------|
| `nfeConfigUpsert($db, $name, $value)` | INSERT/UPDATE em `llx_nfe_config` |
| `getNfeConfig($db)` | Lê ambiente + cert + senha de `llx_nfe_config` |

> **O código-fonte completo está no próprio arquivo PHP** — não duplicamos aqui para evitar dessincronia. Consulte diretamente:
> `custom/labapp/class/actions_labapp.class.php`

### 6.2. Trecho do modLabApp.class.php (hooks)

**Caminho:** `custom/labapp/core/modules/modLabApp.class.php`

```php
// Dentro do __construct():

// Registra hooks para controle de visibilidade de extrafields
// O contexto 'admincompany' ativa a classe ActionsLabapp (class/actions_labapp.class.php)
// que injeta campos fiscais brasileiros em admin/company.php
$this->module_parts = array(
    'hooks' => array(
        'invoicecard',         // Ficha de fatura
        'productcard',         // Ficha de produto
        'productedit',         // Edição de produto
        'productlist',         // Lista de produtos
        'invoicelist',         // Lista de faturas
        'admincompany',        // Campos NFSe/NFe em admin/company.php
    ),
);
```

### 6.3. admin/company.php (estado limpo)

O arquivo `admin/company.php` agora está **100% limpo** — sem nenhum código customizado:

```php
// Linha ~83: O hook que dispara nossa classe
$parameters = array();
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action);

// Linha ~935: Fim do formulário principal
print '</form>'; // MAIN FORM CLOSES HERE NOW
print '<br>';

// ══════════════════════════════════════════════════════════════════════════════
// SEÇÃO DE CERTIFICADO/AMBIENTE REMOVIDA — AGORA VIA HOOK
//
// Todo o código foi movido para:
//   custom/labapp/class/actions_labapp.class.php → doActionsAdminCompany()
// ══════════════════════════════════════════════════════════════════════════════

llxFooter();
$db->close();
```

**O que foi removido:**
- `require_once nfecertificate.class.php` (agora feito no hook)
- 4 funções globais: `getNfeCertPasswordKey()`, `nfeEncryptPassword()`, `nfeDecryptPassword()`, `nfeConfigUpsertLocal()`
- Handler da action `savesetup` (~40 linhas)
- Leitura de config de `llx_nfe_config` (~10 linhas)
- Formulário HTML completo de certificado (~55 linhas)
- JavaScript de toggle senha + confirmação ambiente (~20 linhas)

---

## 7. Tabela de Campos Injetados

| # | Constante Dolibarr | Label | Tipo HTML | Valores | Uso |
|---|---|---|---|---|---|
| 1 | `MAIN_INFO_NOME_FANTASIA` | Nome Fantasia | `<input text>` | Texto livre | NFe, NFSe |
| 2 | `MAIN_INFO_RUA` | Rua | `<input text>` | Texto livre | NFe, NFSe |
| 3 | `MAIN_INFO_BAIRRO` | Bairro | `<input text>` | Texto livre | NFe, NFSe |
| 4 | `MAIN_INFO_NUMERO` | Número | `<input text>` | Texto livre | NFe, NFSe |
| 5 | `MAIN_INFO_CNAE` | CNAE | `<input text>` | Código numérico | NFSe |
| 6 | `MAIN_INFO_CRT` | CRT | `<select>` | 1-4 (ver abaixo) | NFe, NFSe |
| 7 | `MAIN_INFO_REGIMETRIBUTACAO` | Regime Especial (NFS-e) | `<select>` | 1-6 (ver abaixo) | NFSe |
| 8 | `MAIN_INFO_INCENTIVOFISCAL` | Incentivo Fiscal (NFS-e) | `<select>` | 1=Sim, 2=Não | NFSe |

### Valores do CRT:
| Valor | Descrição |
|-------|-----------|
| 1 | Simples Nacional |
| 2 | Simples Nacional com excesso de sublimite de receita |
| 3 | Lucro Real |
| 4 | Lucro Presumido |

### Valores do Regime de Tributação:
| Valor | Descrição |
|-------|-----------|
| 1 | Microempresa Municipal |
| 2 | Estimativa |
| 3 | Sociedade de Profissionais |
| 4 | Cooperativa |
| 5 | Microempresário Individual (MEI) |
| 6 | Microempresa ou Empresa de Pequeno Porte (ME/EPP) |

---

## 8. Seção de Certificado Digital / Ambiente

### O que foi movido do company.php:

O código original em `admin/company.php` (linhas 940-1208) continha:

1. **4 funções globais** definidas inline no arquivo:
   - `getNfeCertPasswordKey()` — Gera/lê chave mestra de criptografia
   - `nfeEncryptPassword()` — Criptografa senha com AES-256-GCM
   - `nfeDecryptPassword()` — Descriptografa senha
   - `nfeConfigUpsertLocal()` — Insert/Update na tabela llx_nfe_config

2. **Handler de action `savesetup`** — Salva ambiente, senha, certificado

3. **Formulário HTML completo** — Form com enctype=multipart/form-data, radio buttons (ambiente), file upload (certificado), password input (senha)

4. **JavaScript** — Toggle senha, confirmação de produção

### O que mudou no hook:

| Aspecto | Antes (company.php) | Agora (hook) |
|---------|---------------------|--------------|
| Funções de criptografia | Definidas inline como funções globais (sem $db param) | Removidas — usa `nfe_security.lib.php` (com $db param) |
| `nfeConfigUpsertLocal()` | Função global | Método `ActionsLabapp::nfeConfigUpsert()` (private static) |
| Leitura de config | Query inline | Método `ActionsLabapp::getNfeConfig()` (private static) |
| Handler savesetup | Código solto no meio do arquivo | Dentro de `doActionsAdminCompany()` PARTE 2 |
| Formulário HTML | PHP `print` statements | JavaScript DOM manipulation via ob_start callback |
| JavaScript | `<script>` inline com jQuery | Vanilla JS no mesmo callback ob_start |

### Dados armazenados (tabela llx_nfe_config):

| name | Tipo | Descrição |
|------|------|-----------|
| `ambiente` | '1' ou '2' | 1=Produção, 2=Homologação |
| `cert_pfx` | blob/text | Conteúdo binário do certificado .pfx/.p12 |
| `cert_pass` | text | Senha criptografada com AES-256-GCM |
| `encryption_master_key` | text | Chave mestra (hex, 64 chars) para derivar chave AES |

### Fluxo de criptografia da senha:

```
Usuário digita senha "MinhaSenha123"
         │
         ▼
GETPOST('cert_pass', 'restricthtml')
         │
         ▼
nfeEncryptPassword("MinhaSenha123", $db)
         │
         ├─ getNfeCertPasswordKey($db)
         │    ├─ SELECT encryption_master_key FROM llx_nfe_config
         │    │  (se não existe, gera com openssl_random_pseudo_bytes(32))
         │    └─ hash_pbkdf2('sha256', masterKey, 'nfe_cert_password_v1', 100000, 32)
         │         → Chave AES de 256 bits
         │
         ├─ IV = openssl_random_pseudo_bytes(12)  ← 96 bits aleatórios
         │
         └─ openssl_encrypt("MinhaSenha123", 'aes-256-gcm', key, ..., &$tag)
              → Retorna base64( IV[12] | TAG[16] | CIPHERTEXT )
                 │
                 ▼
         Armazenado em llx_nfe_config.cert_pass
```

---

## 9. Conversão de Certificado (NfeCertificate)

### Classe: `custom/labapp/class/nfecertificate.class.php`

A classe `NfeCertificate` é responsável por resolver um problema comum em servidores modernos: certificados digitais A1 gerados com algoritmos legados (RC2, 3DES) que não são suportados pelo OpenSSL 3.x.

### Fluxo de processamento:

```
$certHandler->processAndConvert($pfxContent, $password)
         │
         ├─ PASSO 1: Tenta openssl_pkcs12_read() direto
         │     │
         │     ├─ SUCESSO → Retorna ['pfx' => original, 'converted' => false]
         │     │
         │     └─ FALHA → Captura erro OpenSSL
         │           │
         │           ├─ Erro contém 'unsupported' / '0308010C' / 'RC2' / 'legacy'?
         │           │    │
         │           │    └─ SIM → PASSO 2: Conversão via CLI
         │           │
         │           └─ Outro erro → Retorna false
         │
         └─ PASSO 2: convertPfxLegacy()
              │
              ├─ Salva PFX em arquivo temporário
              ├─ Salva senha em arquivo (mais seguro que linha de comando)
              │
              ├─ openssl pkcs12 -in X -clcerts -nokeys -out cert.pem -legacy
              │  (extrai certificado, tenta com -legacy, fallback sem)
              │
              ├─ openssl pkcs12 -in X -nocerts -nodes -out key.pem -legacy
              │  (extrai chave privada)
              │
              ├─ openssl pkcs12 -export -in cert.pem -inkey key.pem -out new.pfx
              │     -certpbe AES-256-CBC -keypbe AES-256-CBC -macalg SHA256
              │  (recria PFX com algoritmos modernos)
              │
              ├─ openssl_pkcs12_read(newPfx) → Valida o novo PFX
              │
              ├─ Limpa arquivos temporários (zero-fill + unlink)
              │
              └─ Retorna ['pfx' => newPfx, 'converted' => true, 'info' => certData]
```

### Segurança da conversão:

| Medida | Detalhe |
|--------|---------|
| Arquivos temp com nome único | `uniqid('nfe_cert_', true)` → colisão praticamente impossível |
| Senha em arquivo, não CLI | `file:$passFile` em vez de passar na linha de comando (evita /proc/cmdline) |
| Zero-fill antes de deletar | Sobrescreve com `\0` antes de `unlink()` (contra recuperação de dados) |
| Bloco try/finally | Limpeza garantida mesmo em caso de exceção |
| Validação do resultado | O novo PFX é testado com `openssl_pkcs12_read()` antes de retornar |
| Fallback de compatibilidade | Se `-legacy` falhar, tenta sem (para OpenSSL < 3.0) |

---

## 10. Análise de Melhorias na Conversão

### Bugs corrigidos no código original do company.php:

| Bug | Localização | Impacto | Correção |
|-----|-------------|---------|----------|
| **`processAndConvert()` COMENTADO** | Linha ~1083: `//$result = ...` | Conversão nunca executava. `$result` ficava undefined → warning PHP | **Descomentado** no hook. Agora funciona |
| **Salvava PFX original (não convertido)** | Linha ~1091: `nfeConfigUpsertLocal($db, 'cert_pfx', $pfxContent)` | Mesmo se a conversão ocorresse, o PFX antigo (incompatível) era salvo | **Corrigido**: agora salva `$result['pfx']` (o convertido) |
| **`$result` undefined sem warning** | Linhas 1087+1095 | Referência a `$result['converted']` e `$result === false` em variável não definida | **Corrigido**: lógica reescrita com `$pfxToSave` e `$wasConverted` |
| **Funções duplicadas com assinaturas diferentes** | `company.php` (sem $db) vs `nfe_security.lib.php` (com $db) | Se ambos carregados → Fatal: "Cannot redeclare" | **Corrigido**: removidas de company.php, usa só `nfe_security.lib.php` |

### Melhorias adicionadas:

| Melhoria | Detalhe |
|----------|---------|
| **Validação de tamanho do certificado** | Rejeita arquivos > 100KB (certs A1 são < 20KB) |
| **Validação de conteúdo vazio** | Verifica se `file_get_contents()` retornou conteúdo |
| **Fallback gracioso** | Se `NfeCertificate` ou `nfe_security.lib.php` não existirem, funciona sem criptografia/conversão |
| **`function_exists()` checks** | Não falha se `nfeEncryptPassword`/`nfeDecryptPassword` não estiverem disponíveis |
| **Mensagens de erro mais claras** | Mensagens em português, sem detalhes técnicos expostos ao usuário |

### O que NÃO foi alterado na classe NfeCertificate (está funcional):

A classe `nfecertificate.class.php` foi mantida intacta porque:
1. A lógica de conversão já funciona corretamente
2. A detecção de erro OpenSSL cobre os padrões conhecidos
3. O fallback `-legacy` / sem flag é adequado
4. A limpeza segura de arquivos temporários é bem implementada
5. A validação do PFX convertido garante integridade

### Sugestões para futuro (não implementadas):

| Sugestão | Motivo de não implementar agora |
|----------|--------------------------------|
| Verificar validade do certificado | Útil mas não bloqueia emissão se expirado (o webservice já rejeita) |
| Exibir data de expiração no badge | Requer descriptografar o PFX a cada page load (performance) |
| Limitar extensões aceitas no upload | Já tem `accept=".pfx,.p12"` no input HTML (client-side) |
| Backup do certificado anterior | Pode ser adicionado no futuro com versionamento |

---

## 11. Onde os Valores São Consumidos

### Campos fiscais (llx_const):

```php
// Em qualquer arquivo PHP do Dolibarr:
$nomeFantasia = getDolGlobalString('MAIN_INFO_NOME_FANTASIA');
$crt = getDolGlobalInt('MAIN_INFO_CRT');
$bairro = $conf->global->MAIN_INFO_BAIRRO;
```

### Configurações de ambiente/certificado (llx_nfe_config):

```php
// Lendo do banco diretamente:
$cfg = array();
$res = $db->query("SELECT name, value FROM ".MAIN_DB_PREFIX."nfe_config WHERE name IN ('ambiente','cert_pfx','cert_pass')");
if ($res) { while ($o = $db->fetch_object($res)) $cfg[$o->name] = $o->value; }

$ambiente = $cfg['ambiente'] ?? '2'; // '1'=Produção, '2'=Homologação

// Para descriptografar a senha:
require_once DOL_DOCUMENT_ROOT.'/custom/nfe/lib/nfe_security.lib.php';
$senhaPlana = nfeDecryptPassword($cfg['cert_pass'], $db);
```

### Arquivos consumidores:

| Arquivo | O que consome | Tabela |
|---------|---------------|--------|
| `custom/nfse/class/actions_nfse.class.php` | Campos fiscais do emitente | llx_const |
| `custom/nfse/emissao_nfse_nacional.php` | XML da DPS com dados do emitente | llx_const |
| `custom/mdfe/mdfe_emissao.php` | Dados do emitente no MDF-e | llx_const |
| `custom/nfe/list.php` | Certificado + senha para assinar XML | llx_nfe_config |
| `custom/nfe/inutilizacao_nfe.php` | Certificado + senha para inutilização | llx_nfe_config |
| `custom/nfe/setup.php` | Ambiente + certificado (página própria) | llx_nfe_config |

---

## 12. Como o Dolibarr Descobre e Executa a Classe

### 12.1. Ativação do módulo (uma vez):

```
Usuário ativa módulo Lab Connecta em:
  Início > Configuração > Módulos

Dolibarr chama: modLabApp::init()

init() lê $this->module_parts['hooks'] = ['invoicecard', ..., 'admincompany']

Dolibarr salva na tabela llx_const:
  INSERT INTO llx_const (name, value)
  VALUES ('MAIN_MODULE_LABAPP_HOOKS',
          '["invoicecard","productcard","productedit","productlist","invoicelist","admincompany"]');
```

### 12.2. A cada acesso à página:

```
1. main.inc.php carrega $conf->modules_parts['hooks'] a partir de llx_const
   → $conf->modules_parts['hooks']['labapp'] = ['invoicecard', ..., 'admincompany']

2. admin/company.php → $hookmanager->initHooks(['admincompany', 'globaladmin'])

3. HookManager percorre $conf->modules_parts['hooks']:
   → Para módulo 'labapp': isModEnabled('labapp') → true
   → Contexto 'admincompany' está em ['invoicecard',...,'admincompany'] → MATCH!

4. HookManager calcula nome do arquivo automaticamente:
   $path = '/labapp/class/'
   $actionfile = 'actions_labapp.class.php'    (= 'actions_' + $module + '.class.php')
   → dol_include_once('/labapp/class/actions_labapp.class.php')

5. HookManager calcula nome da classe:
   $controlclassname = 'ActionsLabapp'     (= 'Actions' + ucfirst($module))
   → new ActionsLabapp($db)

6. Quando executeHooks('doActions', ...) é chamado:
   → $hookObject->doActions($parameters, $object, $action, $hookmanager)
```

### 12.3. Convenção de nomenclatura (OBRIGATÓRIA):

| O que | Convenção | Exemplo para módulo `labapp` |
|-------|-----------|------------------------------|
| Diretório | `custom/{module}/` | `custom/labapp/` |
| Arquivo | `class/actions_{module}.class.php` | `class/actions_labapp.class.php` |
| Classe | `Actions{Ucfirst(module)}` | `ActionsLabapp` |

> **ATENÇÃO:** O Dolibarr **NÃO** permite customizar esses nomes. Eles são calculados
> automaticamente a partir do nome do diretório do módulo. Se o arquivo ou classe tiver
> nome diferente (ex: `actions_lab.class.php` / `ActionsLab`), o hook **não será carregado**.

### 12.4. APÓS ALTERAR HOOKS — obrigatório:

```
1. Ir em Início > Configuração > Módulos
2. DESATIVAR o módulo Lab Connecta
3. REATIVAR o módulo Lab Connecta
→ Isso faz o Dolibarr regravar MAIN_MODULE_LABAPP_HOOKS em llx_const
```

---

## 13. Guia de Manutenção

### Adicionar novo campo de texto:

**Passo 1 — Salvamento** (no método `doActionsAdminCompany`):
```php
$nfseFields = array(
    'MAIN_INFO_NOME_FANTASIA',
    // ... campos existentes ...
    'MAIN_INFO_MEU_NOVO_CAMPO',  // ← ADICIONAR AQUI
);
```

**Passo 2 — Exibição** (no bloco JavaScript):
```php
// Dentro do callback ob_start, adicionar variável PHP:
$meuNovoCampo = addslashes(getDolGlobalString('MAIN_INFO_MEU_NOVO_CAMPO'));
```

```javascript
// Dentro do DOMContentLoaded, adicionar:
tbody.insertBefore(criarLinhaInput('Meu Novo Campo', 'MAIN_INFO_MEU_NOVO_CAMPO', '{$meuNovoCampo}'), phoneRow);
```

### Adicionar novo campo select:

**Passo 1 — Salvamento:**
```php
$meuSelect = GETPOST('MAIN_INFO_MEU_SELECT', 'int');
if (GETPOSTISSET('MAIN_INFO_MEU_SELECT')) {
    if (in_array($meuSelect, array(1, 2, 3))) {
        dolibarr_set_const($db, 'MAIN_INFO_MEU_SELECT', $meuSelect, 'chaine', 0, '', $conf->entity);
    }
}
```

**Passo 2 — Exibição:**
```php
$meuSelectVal = (int) getDolGlobalInt('MAIN_INFO_MEU_SELECT');
if ($meuSelectVal < 1) $meuSelectVal = 1;
```
```javascript
tbody.insertBefore(criarLinhaSelect('Meu Select', 'MAIN_INFO_MEU_SELECT', [
    {value: 1, text: 'Opção A'},
    {value: 2, text: 'Opção B'},
    {value: 3, text: 'Opção C'}
], {$meuSelectVal}), phoneRow);
```

### Remover um campo:

1. Remova do array `$nfseFields` (ou a lógica de validação)
2. Remova a variável PHP no callback
3. Remova a chamada `criarLinhaInput()` / `criarLinhaSelect()` no JS
4. Opcional: `DELETE FROM llx_const WHERE name = 'MAIN_INFO_XXX'`

### Alterar configurações de certificado/ambiente:

**Adicionar novo campo na seção de certificado:**
1. PARTE 2 (`action == 'savesetup'`): Adicione validação + `self::nfeConfigUpsert($db, 'novo_campo', $valor);`
2. PARTE 3 (callback): Leia com `getNfeConfig()` (já retorna todas as rows da llx_nfe_config)
3. Script B (JavaScript): Adicione o HTML correspondente no formulário

**Alterar algoritmo de criptografia:**
1. Edite **APENAS** `custom/nfe/lib/nfe_security.lib.php`
2. Mantenha a interface: `nfeEncryptPassword($pass, $db)` e `nfeDecryptPassword($enc, $db)`
3. Para migração, crie script que descriptografa com método antigo e re-criptografa com novo

**Trocar a classe de conversão de certificado:**
1. A classe `NfeCertificate` é carregada em PARTE 2 com `class_exists()` guard
2. Se substituir por outra, mantenha o contrato: `processAndConvert($pfxContent, $password)` retornando `['success' => bool, 'pfx' => string, 'message' => string]`

---

## 14. Troubleshooting

| Sintoma | Causa Provável | Solução |
|---------|----------------|---------|
| Campos não aparecem | Módulo Lab Connecta desativado | Ativar em Configuração > Módulos |
| Campos não aparecem | Hooks não registrados | Desativar e reativar o módulo |
| Campos não aparecem | Campo Phone não existe | Verificar se `label[for="phone"]` existe no HTML |
| Campos não salvam | JS não renderizou os campos | Abrir Console (F12) e verificar erros JS |
| Campos não salvam | Campos fora do `<form>` | Verificar no Inspector se os inputs estão dentro do form |
| Página em branco | Erro de sintaxe no heredoc | Verificar que `JSBLOCK;` está no início da linha |
| Valores antigos após salvar | Cache do `$conf` | Limpar cache: `$conf->global->XXX` é recarregado no próximo request |
| Certificado não salva | CSRF token inválido | Recarregar a página e tentar novamente |
| Certificado não salva | Arquivo > 100KB | Verificar tamanho; PFX típico tem 3-5KB |
| Senha não criptografa | `nfe_security.lib.php` ausente | Verificar se `custom/nfe/lib/nfe_security.lib.php` existe |
| Erro OpenSSL na conversão | OpenSSL < 3.0 sem legacy | NfeCertificate tenta `-legacy` flag automaticamente; verificar versão |
| Seção de certificado não aparece | `</body>` não encontrado no HTML | Verificar se o template base tem `</body>` (necessário para callback) |

### Como verificar os hooks registrados:

```sql
-- Execute no phpMyAdmin ou outro client SQL:
SELECT name, value FROM llx_const WHERE name = 'MAIN_MODULE_LABAPP_HOOKS';

-- Resultado esperado:
-- name=MAIN_MODULE_LABAPP_HOOKS
-- value=["invoicecard","productcard","productedit","productlist","invoicelist","admincompany"]
```

### Como verificar as constantes salvas:

```sql
SELECT name, value FROM llx_const WHERE name LIKE 'MAIN_INFO_%' AND entity = 1;
```

### Como verificar as configurações de certificado:

```sql
-- Ver todas as configs de NFe:
SELECT name, 
       CASE WHEN name = 'cert_pfx' THEN CONCAT('[BLOB ', LENGTH(value), ' bytes]')
            WHEN name = 'cert_pass' THEN CONCAT('[ENCRYPTED ', LENGTH(value), ' chars]')
            ELSE value END AS valor_seguro
FROM llx_nfe_config;

-- Resultado esperado:
-- ambiente           → '1' ou '2'
-- cert_pfx           → [BLOB 3456 bytes]
-- cert_pass          → [ENCRYPTED 88 chars]
-- encryption_master_key → [hex string 64 chars]
```

---

## 15. Diagramas

### Fluxo de renderização:

```
┌──────────────────────────┐
│ Usuário abre             │
│ admin/company.php        │
└──────────┬───────────────┘
           │
           ▼
┌──────────────────────────────┐
│ initHooks(['admincompany'])  │
│ Dolibarr carrega ActionsLabapp │
└──────────┬───────────────────┘
           │
           ▼
┌────────────────────────────────────┐
│ executeHooks('doActions')          │
│ → ActionsLabapp::doActions()          │
│                                    │
│ ┌─ action == 'update'?             │
│ │  SIM → Salva campos fiscais      │
│ │  NÃO → Pula                      │
│ ├─ action == 'savesetup'?          │
│ │  SIM → Salva cert/amb/senha      │
│ │  NÃO → Pula                      │
│ └──────────────────                │
│                                    │
│ ob_start(callback)                 │
│ Retorna 0                          │
└──────────┬─────────────────────────┘
           │
           ▼
┌────────────────────────────────────┐
│ admin/company.php renderiza        │
│ APENAS o formulário principal      │
│ (campos nativos do Dolibarr)       │
│ → HTML vai para o buffer           │
└──────────┬─────────────────────────┘
           │
           ▼
┌────────────────────────────────────┐
│ llxFooter() → ob_end_flush()       │
│ CALLBACK EXECUTA:                  │
│                                    │
│ 1. Recebe TODO o HTML              │
│ 2. Lê conf fiscais + cert config   │
│ 3. Monta Script A (campos fiscais) │
│ 4. Monta Script B (form cert/amb)  │
│ 5. Insere scripts antes de </body> │
│ 6. Retorna HTML modificado         │
└──────────┬─────────────────────────┘
           │
           ▼
┌────────────────────────────────────┐
│ NAVEGADOR: DOMContentLoaded        │
│                                    │
│ Script A: Campos Fiscais           │
│ 1. querySelector('label[for=phone]')│
│ 2. insertBefore(campo, phoneRow)   │
│                                    │
│ Script B: Certificado/Ambiente     │
│ 1. querySelector('input[action=    │
│    update]').closest('form')       │
│ 2. Cria div#hook-cert-section      │
│ 3. Cria <form> multipart           │
│ 4. Cria table com radio/file/pass  │
│ 5. insertBefore no container       │
│ 6. Registra event handlers         │
│                                    │
│ RESULTADO: Campos + Cert visíveis! │
└────────────────────────────────────┘
```

### Mapa de arquivos:

```
custom/
├── labapp/                             ← MÓDULO LAB CONNECTA
│   ├── class/
│   │   ├── actions_labapp.class.php     ← HOOK (campos fiscais + certificado)
│   │   └── nfecertificate.class.php    ← Conversão de PFX (OpenSSL 3.x)
│   ├── core/
│   │   └── modules/
│   │       └── modLabApp.class.php     ← DESCRITOR (registra hooks)
│   └── DOC_HOOK_CAMPOS_COMPANY.md      ← ESTA DOCUMENTAÇÃO
│
├── nfe/                                ← MÓDULO NF-e (consumidor)
│   ├── lib/
│   │   └── nfe_security.lib.php        ← Funções de criptografia AES-256-GCM
│   ├── list.php                        ← Usa cert + senha para assinar XML
│   ├── inutilizacao_nfe.php            ← Usa cert + senha
│   └── setup.php                       ← Página própria de config NFe
│
├── nfse/                               ← MÓDULO NFS-e (consumidor)
│   ├── class/
│   │   └── actions_nfse.class.php      ← Usa getDolGlobalString('MAIN_INFO_*')
│   └── emissao_nfse_nacional.php       ← Monta XML com dados da empresa
│
└── mdfe/                               ← MÓDULO MDF-e (consumidor)
    └── mdfe_emissao.php                ← Usa dados da empresa

htdocs/
└── admin/
    └── company.php                     ← PÁGINA CORE (LIMPA — sem edições)
```

---

## 16. Histórico Completo

| Data | Evento |
|------|--------|
| (inicial) | Campos fiscais + seção de certificado inseridos manualmente no core `admin/company.php` |
| 2026-02-27 | **1ª refatoração**: Campos fiscais movidos para hook em `actions_nfse.class.php` |
| 2026-02-27 | **2ª refatoração**: Campos fiscais movidos para `custom/mdfe/core/modules/actions_lab.class.php` |
| 2026-02-27 | **3ª refatoração**: Campos fiscais movidos para `custom/labapp/class/actions_labapp.class.php` |
| 2026-02-27 | **4ª refatoração**: Seção de certificado/ambiente também movida para `actions_labapp.class.php`. Bugs corrigidos: processAndConvert descomentado, PFX convertido agora é salvo, funções duplicadas removidas. Criptografia via `nfe_security.lib.php` (com $db param). |
| 2026-02-27 | **5ª correção**: Arquivo renomeado de `actions_lab.class.php` para `actions_labapp.class.php` e classe de `ActionsLab` para `ActionsLabapp` para seguir convenção obrigatória do HookManager (`actions_{module}.class.php` / `Actions{Ucfirst(module)}`). Documentação da seção 12 corrigida: Dolibarr usa `$conf->modules_parts['hooks']` (de `llx_const`), não tabela `llx_hooks`. |
