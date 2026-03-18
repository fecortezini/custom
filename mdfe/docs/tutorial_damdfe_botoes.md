# Tutorial Completo: Adicionando Botões "Visualizar DAMDFE" e "Download PDF" no MDF-e

## Índice
1. [Visão Geral](#1-visão-geral)
2. [Entendendo a Arquitetura Existente](#2-entendendo-a-arquitetura-existente)
3. [Passo 1 — Criar a Coluna `pdf_damdfe` no Banco de Dados](#3-passo-1--criar-a-coluna-pdf_damdfe-no-banco-de-dados)
4. [Passo 2 — Criar o Arquivo `mdfe_damdfe.php`](#4-passo-2--criar-o-arquivo-mdfe_damdfephp)
5. [Passo 3 — Adicionar os Botões no Dropdown da Lista](#5-passo-3--adicionar-os-botões-no-dropdown-da-lista)
6. [Como Funciona — Fluxo Completo](#6-como-funciona--fluxo-completo)
7. [Referência da Biblioteca sped-da](#7-referência-da-biblioteca-sped-da)
8. [Testes e Troubleshooting](#8-testes-e-troubleshooting)

---

## 1. Visão Geral

Este tutorial ensina como adicionar **dois novos botões** no dropdown de ações da lista de MDF-e emitidas (`mdfe_list.php`):

| Botão | O que faz |
|---|---|
| **Visualizar DAMDFE** | Abre o PDF do DAMDFE (Documento Auxiliar) em uma **nova aba** do navegador para visualização |
| **Download PDF** | Força o **download** do arquivo PDF do DAMDFE e **salva automaticamente** o PDF na tabela `mdfe_emitidas` |

Ambos usam a biblioteca `NFePHP\DA\MDFe\Damdfe` (pacote **sped-da**) para gerar o PDF a partir do XML salvo no banco.

### Pré-requisitos
- Módulo MDF-e instalado em `custom/mdfe/`
- Biblioteca `sped-da` instalada via Composer em `custom/composerlib/` (classe `NFePHP\DA\MDFe\Damdfe`)
- Tabela `llx_mdfe_emitidas` com a coluna `xml_mdfe` preenchida (XML assinado/processado)

---

## 2. Entendendo a Arquitetura Existente

### Estrutura de arquivos relevante

```
custom/mdfe/
├── mdfe_list.php          ← Lista de MDF-e emitidas (contém dropdown de ações)
├── mdfe_processar.php     ← Processa emissão e salva no banco
├── mdfe_download.php      ← Download do XML em ZIP
├── mdfe_damdfe.php        ← 🆕 NOVO! Geração do DAMDFE (PDF)
├── core/modules/
│   └── modMDFe.class.php  ← Definição das tabelas do banco
└── ...

custom/composerlib/vendor/
└── nfephp-org/sped-da/src/MDFe/
    └── Damdfe.php          ← Classe que gera o PDF
```

### A tabela `llx_mdfe_emitidas`

A tabela já possui estas colunas relevantes:

| Coluna | Tipo | Uso |
|---|---|---|
| `id` | INT (PK) | Identificador único |
| `numero` | INT | Número do MDF-e |
| `chave_acesso` | VARCHAR(44) | Chave de 44 dígitos |
| `status` | VARCHAR(20) | autorizada, encerrada, cancelada, etc. |
| `xml_mdfe` | LONGTEXT | XML completo do MDF-e |
| `xml_enviado` | LONGTEXT | XML assinado (fallback) |
| **`pdf_damdfe`** | **LONGBLOB** | **🆕 NOVO! Cache do PDF gerado** |

### O dropdown existente

No arquivo `mdfe_list.php`, cada linha da tabela tem um dropdown de ações (`<div class="nfe-dropdown">`) que é controlado pela função JavaScript `toggleDropdown()`. Os itens do dropdown são links `<a class="nfe-dropdown-item">`.

**Antes da alteração**, o dropdown tinha:
- Consultar
- Download XML
- Incluir NF-e (se autorizada)
- Incluir Condutor (se autorizada)
- Encerrar (se autorizada)
- Cancelar (se autorizada)

**Depois da alteração**, adicionamos entre "Download XML" e "Incluir NF-e":
- **Visualizar DAMDFE** ← novo
- **Download PDF** ← novo

---

## 3. Passo 1 — Criar a Coluna `pdf_damdfe` no Banco de Dados

A coluna `pdf_damdfe` armazena o PDF gerado em formato **base64** dentro de um campo `LONGBLOB`. Isso evita gerar o PDF repetidamente — funciona como um cache.

> **Nota:** O arquivo `mdfe_damdfe.php` executa automaticamente o `ALTER TABLE` na primeira chamada, então você **não precisa rodar SQL manualmente**. Mas se quiser criar a coluna antes, execute:

```sql
ALTER TABLE llx_mdfe_emitidas 
ADD COLUMN pdf_damdfe LONGBLOB DEFAULT NULL 
COMMENT 'Cache do DAMDFE em PDF' 
AFTER xml_mdfe;
```

### Por que LONGBLOB e não LONGTEXT?

O PDF é um binário. Armazenamos em base64 (texto), mas `LONGBLOB` é mais adequado para dados binários. A conversão base64→blob é transparente na leitura.

### Por que salvar no banco?

- **Performance**: gerar o PDF toda vez consome CPU. Salvando, a segunda visualização pode ser instantânea (se implementar leitura do cache futuramente).
- **Rastreabilidade**: você tem o PDF exato gerado disponível para auditoria.
- **Portabilidade**: não depende de diretórios no filesystem.

---

## 4. Passo 2 — Criar o Arquivo `mdfe_damdfe.php`

Crie o arquivo `custom/mdfe/mdfe_damdfe.php`. Este é o **coração** da funcionalidade — ele:

1. Recebe o `id` da MDF-e e a `action` (view/download/save) via GET
2. Busca o XML no banco de dados
3. Gera o PDF usando a classe `Damdfe`
4. Salva o PDF na coluna `pdf_damdfe`
5. Retorna o PDF como resposta HTTP

### Código Completo Comentado

```php
<?php
/**
 * Visualização / Download do DAMDFE (PDF) de uma MDF-e emitida.
 *
 * Parâmetros GET:
 *   id     = ID na tabela mdfe_emitidas
 *   action = "view"     → exibe o PDF inline no navegador
 *            "download" → força download do arquivo PDF
 *            "save"     → gera o PDF e salva na coluna pdf_damdfe, retorna JSON
 */

// ──────────────────────────────────────────────────────
// 1) Silenciar avisos (padrão do módulo)
// ──────────────────────────────────────────────────────
@ini_set('display_errors', '0');
$__lvl = error_reporting();
$__lvl &= ~(E_DEPRECATED | E_USER_DEPRECATED | E_NOTICE | E_USER_NOTICE | E_WARNING | E_USER_WARNING);
error_reporting($__lvl);

// ──────────────────────────────────────────────────────
// 2) Carregar Dolibarr (dá acesso a $db, $user, GETPOST, etc.)
// ──────────────────────────────────────────────────────
require '../../main.inc.php';

/** @var DoliDB $db */
/** @var User   $user */

// ──────────────────────────────────────────────────────
// 3) Carregar autoload do Composer (sped-da)
// ──────────────────────────────────────────────────────
if (file_exists(__DIR__ . '/../composerlib/vendor/autoload.php')) {
    require_once __DIR__ . '/../composerlib/vendor/autoload.php';
}

// Esta é a classe da lib que gera o DAMDFE
use NFePHP\DA\MDFe\Damdfe;

// ──────────────────────────────────────────────────────
// 4) Verificar autenticação
// ──────────────────────────────────────────────────────
if (!$user->id) {
    http_response_code(403);
    exit('Acesso negado.');
}

// ──────────────────────────────────────────────────────
// 5) Receber parâmetros da URL
// ──────────────────────────────────────────────────────
$mdfeId = (int) GETPOST('id', 'int');     // ID do registro
$action = GETPOST('action', 'alpha') ?: 'view';  // Ação padrão = visualizar

if ($mdfeId <= 0) {
    http_response_code(400);
    exit('ID inválido.');
}

// ──────────────────────────────────────────────────────
// 6) Garantir que a coluna pdf_damdfe existe
//    O @ suprime erro se a coluna já existir
// ──────────────────────────────────────────────────────
$p = MAIN_DB_PREFIX;   // Normalmente "llx_"
@$db->query("ALTER TABLE {$p}mdfe_emitidas 
    ADD COLUMN pdf_damdfe LONGBLOB DEFAULT NULL 
    COMMENT 'Cache do DAMDFE em PDF' 
    AFTER xml_mdfe");

// ──────────────────────────────────────────────────────
// 7) Buscar o registro da MDF-e no banco
// ──────────────────────────────────────────────────────
$sql = "SELECT * FROM {$p}mdfe_emitidas WHERE id = " . $mdfeId;
$res = $db->query($sql);

if (!$res || $db->num_rows($res) === 0) {
    http_response_code(404);
    exit('MDF-e não encontrada.');
}

$row = $db->fetch_object($res);

// ──────────────────────────────────────────────────────
// 8) Extrair o XML da MDF-e
//    Prioridade: xml_mdfe → xml_enviado (fallback)
// ──────────────────────────────────────────────────────
$xmlStr = '';

// Primeiro tenta xml_mdfe (XML completo com protocolo)
if (!empty($row->xml_mdfe)) {
    $xmlStr = is_resource($row->xml_mdfe) 
        ? stream_get_contents($row->xml_mdfe) 
        : (string) $row->xml_mdfe;
}

// Fallback: xml_enviado (XML assinado, sem protocolo)
if (empty(trim($xmlStr)) && !empty($row->xml_enviado)) {
    $xmlStr = is_resource($row->xml_enviado) 
        ? stream_get_contents($row->xml_enviado) 
        : (string) $row->xml_enviado;
}

if (empty(trim($xmlStr))) {
    http_response_code(422);
    exit('XML da MDF-e não encontrado no banco de dados.');
}

// ──────────────────────────────────────────────────────
// 9) Tentar encontrar logo da empresa
//    Procura em DOL_DATA_ROOT/mycompany/logos/
// ──────────────────────────────────────────────────────
$logo = null;
$possibleLogoPaths = [
    DOL_DATA_ROOT . '/mycompany/logos/thumbs/',
    DOL_DATA_ROOT . '/mycompany/logos/',
];

foreach ($possibleLogoPaths as $logoDir) {
    if (is_dir($logoDir)) {
        $files = glob($logoDir . '*.{png,jpg,jpeg,gif}', GLOB_BRACE);
        if (!empty($files)) {
            // Converte a imagem para data URI (base64)
            $logo = 'data://text/plain;base64,' 
                  . base64_encode(file_get_contents($files[0]));
            break;
        }
    }
}

// ──────────────────────────────────────────────────────
// 10) Gerar o PDF usando a classe Damdfe
// ──────────────────────────────────────────────────────
try {
    // Instancia passando o XML como string
    $damdfe = new Damdfe($xmlStr);
    
    // debugMode(false) = sem mensagens de debug
    $damdfe->debugMode(false);
    
    // 'P' = retrato, 'L' = paisagem
    $damdfe->printParameters('P');
    
    // render() retorna o conteúdo binário do PDF
    // $logo pode ser null (nesse caso gera sem logo)
    $pdfContent = $damdfe->render($logo);
    
} catch (Exception $e) {
    http_response_code(500);
    exit('Erro ao gerar DAMDFE: ' . $e->getMessage());
}

// ──────────────────────────────────────────────────────
// 11) Salvar PDF no banco (coluna pdf_damdfe)
//     Salva em base64 para compatibilidade com LONGBLOB
// ──────────────────────────────────────────────────────
$pdfBase64 = base64_encode($pdfContent);
$db->query("UPDATE {$p}mdfe_emitidas 
    SET pdf_damdfe = '" . $db->escape($pdfBase64) . "' 
    WHERE id = " . $mdfeId);

// ──────────────────────────────────────────────────────
// 12) Definir nome do arquivo PDF
// ──────────────────────────────────────────────────────
$chave = !empty($row->chave_acesso) 
    ? $row->chave_acesso 
    : 'MDFe_' . $row->numero;
$filename = 'DAMDFE_' . $chave . '.pdf';

// ──────────────────────────────────────────────────────
// 13) Retornar o PDF conforme a ação solicitada
// ──────────────────────────────────────────────────────

// ▶ action=save → retorna JSON (para chamadas AJAX)
if ($action === 'save') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success'  => true,
        'message'  => 'PDF do DAMDFE salvo com sucesso.',
        'id'       => $mdfeId,
        'filename' => $filename,
        'size'     => strlen($pdfContent),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ▶ action=view → exibe inline no navegador
if ($action === 'view') {
    header('Content-Type: application/pdf');
    // "inline" = o navegador exibe dentro da aba
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfContent));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdfContent;
    exit;
}

// ▶ action=download → força download
if ($action === 'download') {
    header('Content-Type: application/pdf');
    // "attachment" = o navegador abre diálogo "Salvar como..."
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfContent));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdfContent;
    exit;
}
```

### Explicação detalhada de cada seção

#### Seções 1-3: Inicialização
- Silenciamos avisos PHP para não poluir a saída (que será binária — PDF)
- Carregamos o `main.inc.php` do Dolibarr, que nos dá acesso a `$db`, `$user`, `GETPOST()`, `MAIN_DB_PREFIX`, etc.
- Carregamos o autoloader do Composer para ter acesso à classe `Damdfe`

#### Seção 4: Autenticação
- Verificamos se `$user->id` existe. Se não, o usuário não está logado → 403

#### Seção 5: Parâmetros
- `id` = identificador do registro na tabela `mdfe_emitidas`
- `action` = controla o comportamento:
  - `view` → exibe PDF na aba do navegador
  - `download` → força o download do arquivo
  - `save` → apenas salva no banco e retorna JSON

#### Seção 6: Migração automática
- O `ALTER TABLE` com `@` na frente suprime erros se a coluna já existir
- Isso garante que o código funciona mesmo sem rodar migração manual

#### Seções 7-8: Busca do XML
- Busca o registro pelo ID
- Tenta primeiro `xml_mdfe` (XML completo com `<mdfeProc>` e protocolo)
- Se estiver vazio, tenta `xml_enviado` (XML apenas assinado)
- O `is_resource()` trata caso o MySQL retorne um stream (campos BLOB)

#### Seção 9: Logo
- Procura automaticamente o logo da empresa em `DOL_DATA_ROOT/mycompany/logos/`
- Converte para `data:` URI em base64 (formato que a lib aceita)
- Se não encontrar, `$logo = null` e o DAMDFE é gerado sem logo

#### Seção 10: Geração do PDF
- `new Damdfe($xmlStr)` — instancia passando o XML completo
- `debugMode(false)` — desativa debug
- `printParameters('P')` — orientação retrato
- `render($logo)` — gera e retorna os bytes do PDF

#### Seção 11: Persistência
- Converte o PDF para base64 e salva na coluna `pdf_damdfe`
- Isso acontece **sempre** (em qualquer action), garantindo que o PDF fica salvo

#### Seção 13: Resposta HTTP
- **`view`**: `Content-Disposition: inline` faz o navegador exibir o PDF
- **`download`**: `Content-Disposition: attachment` força o diálogo de download
- **`save`**: retorna JSON para consumo via AJAX

---

## 5. Passo 3 — Adicionar os Botões no Dropdown da Lista

### Localizar o código

Abra o arquivo `mdfe_list.php` e procure por este bloco (aproximadamente linha 1710):

```php
// Download XML: disponível para qualquer MDF-e que tenha XML salvo
$downloadUrl = dol_buildpath('/custom/mdfe/mdfe_download.php', 1).'?action=individual&id='.(int)$obj->id;
print '<a class="nfe-dropdown-item" href="'.dol_escape_htmltag($downloadUrl).'" target="_blank">Download XML</a>';
if ($status === 'autorizada') {
```

### Adicionar os novos botões

**Entre** a linha do "Download XML" e o `if ($status === 'autorizada')`, insira:

```php
// DAMDFE: Visualizar PDF inline em nova aba
$damdfeViewUrl = dol_buildpath('/custom/mdfe/mdfe_damdfe.php', 1).'?action=view&id='.(int)$obj->id;
print '<a class="nfe-dropdown-item" href="'.dol_escape_htmltag($damdfeViewUrl).'" target="_blank">Visualizar DAMDFE</a>';

// DAMDFE: Download PDF (força download e salva no banco)
$damdfeDownloadUrl = dol_buildpath('/custom/mdfe/mdfe_damdfe.php', 1).'?action=download&id='.(int)$obj->id;
print '<a class="nfe-dropdown-item" href="'.dol_escape_htmltag($damdfeDownloadUrl).'" target="_blank">Download PDF</a>';
```

### Resultado final do bloco de dropdown

Depois da alteração, o código completo do dropdown fica assim:

```php
// Dropdown de ações
print '<td class="center actions-cell"><div class="nfe-dropdown">';
print '<button class="butAction dropdown-toggle" type="button" onclick="toggleDropdown(event, \'mdfeDropdownMenu'.$obj->id.'\')">'.$langs->trans("Ações").'</button>';
print '<div class="nfe-dropdown-menu" id="mdfeDropdownMenu'.$obj->id.'">';

// 1) Consultar (autorizada, encerrada, cancelada)
if (in_array($status, ['autorizada', 'encerrada', 'cancelada'])) {
    print '<a class="nfe-dropdown-item" href="#" onclick="openConsultarModal('.(int)$obj->id.'); return false;">Consultar</a>';
}

// 2) Download XML (sempre disponível)
$downloadUrl = dol_buildpath('/custom/mdfe/mdfe_download.php', 1).'?action=individual&id='.(int)$obj->id;
print '<a class="nfe-dropdown-item" href="'.dol_escape_htmltag($downloadUrl).'" target="_blank">Download XML</a>';

// 3) 🆕 Visualizar DAMDFE (abre PDF em nova aba)
$damdfeViewUrl = dol_buildpath('/custom/mdfe/mdfe_damdfe.php', 1).'?action=view&id='.(int)$obj->id;
print '<a class="nfe-dropdown-item" href="'.dol_escape_htmltag($damdfeViewUrl).'" target="_blank">Visualizar DAMDFE</a>';

// 4) 🆕 Download PDF (força download)
$damdfeDownloadUrl = dol_buildpath('/custom/mdfe/mdfe_damdfe.php', 1).'?action=download&id='.(int)$obj->id;
print '<a class="nfe-dropdown-item" href="'.dol_escape_htmltag($damdfeDownloadUrl).'" target="_blank">Download PDF</a>';

// 5) Ações de MDF-e autorizada
if ($status === 'autorizada') {
    // ... Incluir NF-e, Incluir Condutor, Encerrar, Cancelar
}
```

### Explicação de cada atributo HTML

| Atributo | Finalidade |
|---|---|
| `class="nfe-dropdown-item"` | Aplica o estilo padrão do dropdown (padding, hover roxo, etc.) |
| `href="..."` | URL completa para o `mdfe_damdfe.php` com os parâmetros |
| `target="_blank"` | Abre em **nova aba** (essencial para PDF não substituir a lista) |
| `dol_escape_htmltag()` | Função do Dolibarr para escapar caracteres especiais na URL |
| `dol_buildpath(..., 1)` | Gera a URL absoluta correta considerando o `DOL_URL_ROOT` |

### Por que `target="_blank"`?

- Para **Visualizar**, o PDF abre direto na aba nova (o navegador tem viewer embutido)
- Para **Download**, o navegador abre uma aba momentânea que dispara o download e fecha sozinha
- Em ambos os casos, o usuário **não perde** a lista de MDF-e

---

## 6. Como Funciona — Fluxo Completo

### Fluxo "Visualizar DAMDFE"

```
Usuário clica "Visualizar DAMDFE"
    │
    ▼
GET /custom/mdfe/mdfe_damdfe.php?action=view&id=42
    │
    ▼
PHP: Busca xml_mdfe do registro 42
    │
    ▼
PHP: new Damdfe($xml) → render($logo) → $pdfContent
    │
    ▼
PHP: Salva $pdfContent em base64 na coluna pdf_damdfe
    │
    ▼
PHP: Retorna headers Content-Type: application/pdf
     Content-Disposition: inline
    │
    ▼
Navegador: Exibe o PDF no viewer embutido (nova aba)
```

### Fluxo "Download PDF"

```
Usuário clica "Download PDF"
    │
    ▼
GET /custom/mdfe/mdfe_damdfe.php?action=download&id=42
    │
    ▼
PHP: (mesmo processamento acima)
    │
    ▼
PHP: Retorna headers Content-Type: application/pdf
     Content-Disposition: attachment
    │
    ▼
Navegador: Abre diálogo "Salvar arquivo" → DAMDFE_31220608...pdf
```

### O que fica salvo no banco

Após qualquer uma das ações, a tabela `mdfe_emitidas` terá:

```sql
SELECT id, numero, chave_acesso, 
       LENGTH(pdf_damdfe) as tamanho_pdf_base64
FROM llx_mdfe_emitidas 
WHERE id = 42;

-- Resultado:
-- id=42, numero=1, chave=312206..., tamanho_pdf_base64=85432
```

---

## 7. Referência da Biblioteca sped-da

### Classe `NFePHP\DA\MDFe\Damdfe`

Essa classe faz parte do pacote **nfephp-org/sped-da** e é responsável por gerar o Documento Auxiliar do MDF-e.

### Métodos principais

```php
// Construtor — recebe o XML do MDF-e (string)
$damdfe = new Damdfe($xmlString);

// Ativar/desativar modo debug (mostra erros no PDF)
$damdfe->debugMode(true|false);

// Texto de créditos no rodapé (nome do integrador)
$damdfe->creditsIntegratorFooter('Minha Empresa - http://...');

// Orientação: 'P' = retrato, 'L' = paisagem
$damdfe->printParameters('P');

// Gera e retorna o PDF como string binária
// $logo = null | string(caminho) | string(data:URI base64)
$pdfBinario = $damdfe->render($logo);
```

### Formatos de logo aceitos

```php
// Opção 1: Caminho absoluto do arquivo
$logo = '/var/www/images/logo.png';

// Opção 2: Data URI em base64 (recomendado)
$logo = 'data://text/plain;base64,' . base64_encode(file_get_contents('logo.png'));

// Opção 3: Sem logo
$logo = null;
```

### XML aceitos

A classe aceita dois formatos:

1. **`<mdfeProc>`** — XML completo com envelope de protocolo (ideal)
2. **`<MDFe>`** — Apenas o MDF-e assinado (sem protocolo)

O primeiro formato é preferido pois inclui o protocolo de autorização no DAMDFE.

---

## 8. Testes e Troubleshooting

### Teste rápido

1. Acesse a lista de MDF-e: `http://localhost/dolibarr/htdocs/custom/mdfe/mdfe_list.php`
2. Encontre uma MDF-e com status **autorizada**
3. Clique no dropdown **"Ações"**
4. Você deve ver os novos botões:
   - **Visualizar DAMDFE** — clique e o PDF abre em nova aba
   - **Download PDF** — clique e o PDF é baixado

### Verificar se o PDF foi salvo no banco

```sql
SELECT id, numero, 
       CASE WHEN pdf_damdfe IS NOT NULL THEN 'SIM' ELSE 'NÃO' END as pdf_salvo,
       LENGTH(pdf_damdfe) as tamanho_bytes
FROM llx_mdfe_emitidas 
WHERE id = <SEU_ID>;
```

### Problemas comuns

| Problema | Causa | Solução |
|---|---|---|
| "XML da MDF-e não encontrado" | A coluna `xml_mdfe` está vazia | Verifique se o `mdfe_processar.php` salvou o XML corretamente |
| "Erro ao gerar DAMDFE" | XML inválido ou incompleto | Verifique o conteúdo do `xml_mdfe` — deve ser XML válido |
| Página em branco ao visualizar | O navegador não tem viewer de PDF | Tente com Chrome ou Firefox |
| "Class Damdfe not found" | sped-da não está instalada | Execute `composer require nfephp-org/sped-da` em `custom/composerlib/` |
| Logo não aparece | Nenhum arquivo de imagem em `DOL_DATA_ROOT/mycompany/logos/` | Faça upload do logo em Configurações > Empresa |
| Coluna pdf_damdfe não foi criada | Permissão no MySQL | Execute o ALTER TABLE manualmente (seção 3) |

### Testar via URL direta

```
# Visualizar
http://localhost/dolibarr/htdocs/custom/mdfe/mdfe_damdfe.php?action=view&id=1

# Download
http://localhost/dolibarr/htdocs/custom/mdfe/mdfe_damdfe.php?action=download&id=1

# Apenas salvar no banco (retorna JSON)
http://localhost/dolibarr/htdocs/custom/mdfe/mdfe_damdfe.php?action=save&id=1
```

---

## Resumo das Alterações

| Arquivo | Ação | O que foi feito |
|---|---|---|
| `custom/mdfe/mdfe_damdfe.php` | **CRIADO** | Novo arquivo que gera o DAMDFE em PDF usando sped-da |
| `custom/mdfe/mdfe_list.php` | **MODIFICADO** | Adicionados 2 links no dropdown de ações (~linha 1714) |
| Banco de dados | **AUTO-MIGRADO** | Coluna `pdf_damdfe LONGBLOB` adicionada automaticamente |

Total: **1 arquivo novo**, **1 arquivo modificado**, **0 SQL manual necessário**.
