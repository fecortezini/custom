# Tutorial Completo: DAMDFE no Módulo MDF-e do Dolibarr

## Geração, Visualização e Download do Documento Auxiliar do MDF-e em PDF

---

## Índice

1. [O que é o DAMDFE?](#1-o-que-é-o-damdfe)
2. [Pré-requisitos e Dependências](#2-pré-requisitos-e-dependências)
3. [Contexto: Por que a lib `sped-da` falha no Windows/XAMPP](#3-contexto-por-que-a-lib-sped-da-falha-no-windowsxampp)
4. [Solução: A classe `DamdfeCustom`](#4-solução-a-classe-damdfe-custom)
5. [O arquivo completo `mdfe_damdfe.php` — linha por linha](#5-o-arquivo-completo-mdfe_damdfephp--linha-por-linha)
   - [5.1 Cabeçalho e inicialização](#51-cabeçalho-e-inicialização)
   - [5.2 Classe `DamdfeCustom`](#52-classe-damdfe-custom)
   - [5.3 `adjustImage()` — correção do logo](#53-adjustimage--correção-do-logo)
   - [5.4 `qrCodeDamdfe()` — correção do QR Code](#54-qrcodedamdfe--correção-do-qr-code)
   - [5.5 Autenticação e parâmetros](#55-autenticação-e-parâmetros)
   - [5.6 Migração automática do banco](#56-migração-automática-do-banco)
   - [5.7 Busca e extração do XML](#57-busca-e-extração-do-xml)
   - [5.8 Logo da empresa](#58-logo-da-empresa)
   - [5.9 Geração do PDF](#59-geração-do-pdf)
   - [5.10 Persistência no banco](#510-persistência-no-banco)
   - [5.11 Respostas HTTP](#511-respostas-http)
6. [Os botões no dropdown da lista](#6-os-botões-no-dropdown-da-lista)
7. [Fluxo completo ponta a ponta](#7-fluxo-completo-ponta-a-ponta)
8. [Testando e diagnosticando erros](#8-testando-e-diagnosticando-erros)

---

## 1. O que é o DAMDFE?

O **DAMDFE** (Documento Auxiliar do Manifesto Eletrônico de Documentos Fiscais) é a representação impressa em PDF do MDF-e. Ele é obrigatório para acompanhar o transporte e contém:

- Dados do emitente
- UF de início e fim do percurso
- Lista de municípios de descarga
- Dados do veículo e condutor
- QR Code para consulta
- Protocolo de autorização da SEFAZ

A geração é feita pela biblioteca PHP **`nfephp-org/sped-da`** (pacote Composer), usando o XML autorizado como entrada.

---

## 2. Pré-requisitos e Dependências

### Bibliotecas necessárias (já instaladas no módulo)

| Pacote | Localização | Função |
|---|---|---|
| `nfephp-org/sped-da` | `custom/composerlib/vendor/` | Gera o PDF do DAMDFE |
| `tecnickcom/tc-lib-barcode` | `custom/composerlib/vendor/` | Gera o QR Code interno |
| `nfephp-org/sped-da` → `Legacy\FPDF\Fpdf` | interno da lib | Motor PDF subjacente |

### Extensões PHP necessárias

```ini
extension=gd        ; para manipular imagens (logo)
extension=zlib      ; para compressão PNG no PDF
```

### Estrutura de banco de dados

A tabela `llx_mdfe_emitidas` precisa ter:

| Coluna | Tipo | Descrição |
|---|---|---|
| `id` | INT PK | Identificador |
| `xml_mdfe` | LONGTEXT | XML completo do MDF-e (salvo pelo processador) |
| `xml_enviado` | LONGTEXT | XML assinado (fallback) |
| `chave_acesso` | VARCHAR(44) | Chave de 44 dígitos |
| `pdf_damdfe` | **LONGBLOB** | **PDF gerado em base64 (criado automaticamente)** |

---

## 3. Contexto: Por que a lib `sped-da` falha no Windows/XAMPP

Este é o ponto mais importante para entender o que foi implementado.

### O problema original

A classe `Damdfe` da lib `sped-da` possui dois comportamentos internos que causam falha:

**1. No método `qrCodeDamdfe()` (dentro de `Damdfe.php`):**
```php
// Código ORIGINAL da lib — problemático
$pic = 'data://text/plain;base64,' . base64_encode($qrcode);
$this->pdf->image($pic, $xQr, $yQr, $wQr, $hQr, 'PNG');
```
O QR code gerado como PNG binário é convertido para um `data://` URI e passado ao FPDF.

**2. No método `adjustImage()` (dentro de `DaCommon.php`):**
```php
// Código ORIGINAL da lib — problemático
$logo = 'data://text/plain;base64,' . base64_encode(file_get_contents($logo));
```
O logo também é convertido para `data://` URI antes de ser passado ao FPDF.

### Por que `data://` URI falha?

O FPDF (motor PDF da lib) tenta abrir a imagem com `fopen($file, 'rb')`. O schema `data://` é um **wrapper de stream PHP** que exige a diretiva:

```ini
allow_url_include = On
```

Essa diretiva está **desabilitada por padrão desde PHP 7.4** e é considerada uma vulnerabilidade de segurança. No XAMPP para Windows ela fica `Off`.

### A mesma solução já existia no módulo CT-e

O módulo CT-e (`custom/cte/dacte_pdf.php`) já resolve isso usando `DacteCustom extends Dacte` — uma subclasse que sobrescreve os métodos problemáticos para usar arquivos temporários no disco em vez de `data://` URIs. Aplicamos **exatamente a mesma técnica** para o MDF-e.

---

## 4. Solução: A classe `DamdfeCustom`

Em vez de modificar arquivos da lib em `vendor/` (que seriam sobrescritos num `composer update`), criamos uma subclasse diretamente no arquivo `mdfe_damdfe.php`:

```
NFePHP\DA\MDFe\Damdfe       ← classe original da lib (não modificada)
         ↑
    DamdfeCustom             ← nossa subclasse (em mdfe_damdfe.php)
    - adjustImage()          ← sobrescreve: usa caminho real ao invés de data:URI
    - qrCodeDamdfe()         ← sobrescreve: usa tempnam() ao invés de data:URI
```

---

## 5. O arquivo completo `mdfe_damdfe.php` — linha por linha

### 5.1 Cabeçalho e inicialização

```php
<?php
/**
 * Parâmetros GET:
 *   id     = ID na tabela mdfe_emitidas
 *   action = "view"     → exibe o PDF inline no navegador
 *            "download" → força download do arquivo PDF
 *            "save"     → gera o PDF e salva na coluna pdf_damdfe, retorna JSON
 */

@ini_set('display_errors', '0');
$__lvl = error_reporting();
$__lvl &= ~(E_DEPRECATED | E_USER_DEPRECATED | E_NOTICE | E_USER_NOTICE | E_WARNING | E_USER_WARNING);
error_reporting($__lvl);
```

**Por que silenciar erros?**
A resposta deste arquivo é **binária** (PDF) ou JSON. Qualquer texto de warning/notice emitido antes dos headers HTTP corromperia o PDF. O `@ini_set('display_errors', '0')` garante que nenhum aviso PHP vaze para a saída.

```php
require '../../main.inc.php';
```

Carrega o ambiente do Dolibarr. A partir daqui temos acesso a:
- `$db` — conexão com o banco de dados
- `$user` — usuário logado
- `GETPOST()` — função segura para ler parâmetros GET/POST
- `MAIN_DB_PREFIX` — prefixo das tabelas (normalmente `llx_`)
- `DOL_DATA_ROOT` — caminho dos dados (logos, documentos etc.)

```php
if (file_exists(__DIR__ . '/../composerlib/vendor/autoload.php')) {
    require_once __DIR__ . '/../composerlib/vendor/autoload.php';
}
```

Carrega o autoloader do Composer. O `if` evita fatal error se o vendor não foi instalado. O `__DIR__` garante que o caminho é relativo ao arquivo atual (`custom/mdfe/`), independente de onde o PHP foi chamado.

```php
use NFePHP\DA\MDFe\Damdfe;
use Com\Tecnick\Barcode\Barcode;
```

Importa as duas classes que usaremos:
- `Damdfe` — classe base que nossa subclasse estende
- `Barcode` — usada no override do QR Code para gerar o bitmap PNG

---

### 5.2 Classe `DamdfeCustom`

```php
class DamdfeCustom extends Damdfe
{
```

Criamos uma subclasse de `Damdfe`. Assim **não tocamos nos arquivos do vendor** e ainda podemos sobrescrever o comportamento problemático. Se o `composer update` atualizar a lib, nossa classe continua funcionando (ela depende apenas dos métodos e propriedades públicas/protegidas da classe pai).

---

### 5.3 `adjustImage()` — correção do logo

```php
protected function adjustImage($logo, $turn_bw = false)
{
    if (!empty($this->logomarca)) {
        return $this->logomarca;
    }
```

Se já existe um logo processado (`$this->logomarca`), retorna ele direto. Evita processar a imagem duas vezes (a lib original faz o mesmo).

```php
    if (empty($logo)) {
        return null;
    }
    if (!is_file($logo)) {
        return null;
    }
```

Se não foi passado logo ou o arquivo não existe no disco, retorna `null` silenciosamente. O DAMDFE será gerado sem logo. Isso é mais seguro do que lançar uma exceção.

```php
    $info = @getimagesize($logo);
    if (!$info) {
        return null;
    }
    if (!in_array($info[2], [2, 3], true)) {
        return null;
    }
```

`getimagesize()` lê metadados da imagem sem carregar tudo na memória. O `@` suprime avisos se o arquivo não for uma imagem válida.

`$info[2]` é a constante de tipo de imagem:
- `2` = JPEG/JPG
- `3` = PNG

Outros formatos (GIF=1, BMP=6, WebP=18 etc.) retornam `null` — o FPDF não os suporta.

```php
    if ($info[2] === 2 && !$turn_bw) {
        return $logo;
    }
```

**Caminho feliz**: se o logo já é JPEG e não precisa de conversão para preto e branco, retornamos o caminho real do arquivo diretamente. O FPDF consegue abrir arquivos locais com `fopen($path, 'rb')` sem nenhum problema.

```php
    $image = ($info[2] === 3) ? imagecreatefrompng($logo) : imagecreatefromjpeg($logo);
    if (!$image) {
        return null;
    }
    if ($turn_bw) {
        imagefilter($image, IMG_FILTER_GRAYSCALE);
    }
```

Para PNG (ou JPEG quando precisa de P&B):
- `imagecreatefrompng()` / `imagecreatefromjpeg()` — carrega a imagem na memória GD
- `IMG_FILTER_GRAYSCALE` — converte para escala de cinza se solicitado

```php
    $tmp = tempnam(sys_get_temp_dir(), 'damdfe_logo_');
    imagejpeg($image, $tmp, 100);
    imagedestroy($image);
```

Salva a imagem como JPEG em um arquivo temporário:
- `tempnam()` cria um arquivo único em `sys_get_temp_dir()` (ex: `C:\Windows\Temp\damdfe_logo_abc.tmp`)
- `imagejpeg($image, $tmp, 100)` salva com qualidade máxima
- `imagedestroy($image)` libera a memória GD

```php
    register_shutdown_function(function () use ($tmp) {
        if (file_exists($tmp)) {
            @unlink($tmp);
        }
    });
    return $tmp;
}
```

`register_shutdown_function()` registra uma função que roda **ao final da requisição PHP** (depois do `exit` ou ao terminar o script). Isso garante que o arquivo temporário seja deletado mesmo que ocorra um erro mais tarde. O `use ($tmp)` captura o caminho do arquivo por valor para a closure.

---

### 5.4 `qrCodeDamdfe()` — correção do QR Code

Este método é o que **causava o erro principal** — o QR code era gerado internamente pela lib como `data://` URI.

```php
protected function qrCodeDamdfe($y = 0)
{
    $margemInterna = $this->margemInterna;
    $barcode = new Barcode();
    $bobj = $barcode->getBarcodeObj(
        'QRCODE,M',
        $this->qrCodMDFe,
        -4,
        -4,
        'black',
        [-2, -2, -2, -2]
    )->setBackgroundColor('white');
    $qrcode = $bobj->getPngData();
```

Reproduzimos exatamente a lógica da lib original para gerar o QR code:
- `'QRCODE,M'` — tipo QR Code com correção de erro nível M
- `$this->qrCodMDFe` — URL do QR code da MDF-e (campo `qrCodMDFe` do XML)
- `-4, -4` — largura e altura em pixels (negativo = auto-escala)
- `'black'` — cor do QR
- `[-2, -2, -2, -2]` — margem interna de 2px em cada lado
- `->setBackgroundColor('white')` — fundo branco
- `->getPngData()` — retorna os **bytes binários** do arquivo PNG

```php
    $wQr = 35;
    $hQr = 35;
    $yQr = $y + $margemInterna;
    $xQr = ($this->orientacao === 'P') ? 160 : 235;
```

Posicionamento do QR no PDF em milímetros:
- `35x35mm` — tamanho padrão
- X varia conforme a orientação: `160mm` para retrato, `235mm` para paisagem (essas são as mesmas coordenadas da lib original)

```php
    $tmp = tempnam(sys_get_temp_dir(), 'damdfe_qr_');
    file_put_contents($tmp, $qrcode);
    register_shutdown_function(function () use ($tmp) {
        if (file_exists($tmp)) {
            @unlink($tmp);
        }
    });
    $this->pdf->image($tmp, $xQr, $yQr, $wQr, $hQr, 'PNG');
}
```

**A diferença crucial em relação à lib original:**

| Lib original | Nossa versão |
|---|---|
| `$pic = 'data://text/plain;base64,' . base64_encode($qrcode);` | `$tmp = tempnam(...); file_put_contents($tmp, $qrcode);` |
| `$this->pdf->image($pic, ...)` — falha com `allow_url_include=Off` | `$this->pdf->image($tmp, ...)` — funciona sempre |

O `file_put_contents($tmp, $qrcode)` escreve os bytes PNG no disco. O FPDF abre o arquivo com `fopen($tmp, 'rb')` — **isso funciona em qualquer ambiente** pois é um caminho de arquivo local.

---

### 5.5 Autenticação e parâmetros

```php
if (!$user->id) {
    http_response_code(403);
    exit('Acesso negado.');
}
```

Verifica se há usuário logado. `$user->id` é `0` ou `null` quando a sessão não está autenticada. `http_response_code(403)` informa ao cliente que o acesso foi negado antes de encerrar.

```php
$mdfeId = (int) GETPOST('id', 'int');
$action = GETPOST('action', 'alpha') ?: 'view';
```

- `GETPOST('id', 'int')` — lê `$_GET['id']` ou `$_POST['id']` aceitando apenas inteiros
- `(int)` — cast adicional de segurança
- `GETPOST('action', 'alpha')` — aceita apenas letras (sem caracteres especiais)
- `?: 'view'` — valor padrão se `action` não for informado

```php
if ($mdfeId <= 0) {
    http_response_code(400);
    exit('ID inválido.');
}
```

ID zero ou negativo não faz sentido. Retorna `400 Bad Request`.

---

### 5.6 Migração automática do banco

```php
$p = MAIN_DB_PREFIX;
@$db->query("ALTER TABLE {$p}mdfe_emitidas ADD COLUMN pdf_damdfe LONGBLOB DEFAULT NULL COMMENT 'Cache do DAMDFE em PDF' AFTER xml_mdfe");
```

Cria a coluna `pdf_damdfe` se ela não existir. Detalhes:
- `$p = MAIN_DB_PREFIX` — normalmente `llx_`, mas pode variar por instalação
- `@` — suprime o erro do MySQL quando a coluna já existe (em vez de checar antes)
- `LONGBLOB` — suporta até 4GB; adequado para PDFs que podem ter alguns MB
- `DEFAULT NULL` — MDF-e antigas não terão PDF até serem acessadas pela primeira vez
- `AFTER xml_mdfe` — posiciona a coluna logicamente após o XML correspondente

---

### 5.7 Busca e extração do XML

```php
$sql = "SELECT * FROM {$p}mdfe_emitidas WHERE id = " . $mdfeId;
$res = $db->query($sql);
if (!$res || $db->num_rows($res) === 0) {
    http_response_code(404);
    exit('MDF-e não encontrada.');
}
$row = $db->fetch_object($res);
```

Busca o registro completo. `fetch_object()` retorna um `stdClass` onde cada coluna vira uma propriedade (`$row->xml_mdfe`, `$row->chave_acesso`, etc.).

```php
$xmlStr = '';
if (!empty($row->xml_mdfe)) {
    $xmlStr = is_resource($row->xml_mdfe)
        ? stream_get_contents($row->xml_mdfe)
        : (string) $row->xml_mdfe;
}
if (empty(trim($xmlStr)) && !empty($row->xml_enviado)) {
    $xmlStr = is_resource($row->xml_enviado)
        ? stream_get_contents($row->xml_enviado)
        : (string) $row->xml_enviado;
}
```

**Por que o `is_resource()`?**

Colunas `LONGTEXT`/`LONGBLOB` no MySQL podem ser retornadas como:
- `string` — comportamento padrão do PDO/MySQLi
- `resource` (stream) — dependendo do driver e versão

`is_resource()` detecta o stream; `stream_get_contents()` lê seu conteúdo inteiro para uma string.

**Por que dois campos (`xml_mdfe` e `xml_enviado`)?**

- `xml_mdfe` contém o XML **original gerado** (antes da assinatura)
- `xml_enviado` contém o **XML assinado** enviado à SEFAZ

A lib `Damdfe` aceita ambos. O `xml_mdfe` é preferido pois pode conter o envelope `<mdfeProc>` com o protocolo da SEFAZ, que aparece no DAMDFE com validade fiscal.

```php
if (empty(trim($xmlStr))) {
    http_response_code(422);
    exit('XML da MDF-e não encontrado no banco de dados.');
}
```

`422 Unprocessable Entity` — o registro existe mas os dados são insuficientes para processar.

---

### 5.8 Logo da empresa

```php
$logo = null;
$possibleLogoPaths = [
    DOL_DATA_ROOT . '/mycompany/logos/thumbs/',
    DOL_DATA_ROOT . '/mycompany/logos/',
];
$mimeAceitos = ['image/png', 'image/jpeg'];
```

- `DOL_DATA_ROOT` aponta para o diretório de dados do Dolibarr (ex: `C:\xampp\htdocs\dolibarr\documents`)
- `thumbs/` é verificado primeiro pois contém versões redimensionadas (mais leves e adequadas para PDF)
- Lista `$mimeAceitos` define os tipos aceitos pelo FPDF

```php
foreach ($possibleLogoPaths as $logoDir) {
    if (!is_dir($logoDir)) {
        continue;
    }
    $files = glob($logoDir . '*.{png,jpg,jpeg,PNG,JPG,JPEG}', GLOB_BRACE);
    if (empty($files)) {
        continue;
    }
    foreach ($files as $logoFile) {
        $imgInfo = @getimagesize($logoFile);
        if ($imgInfo !== false && in_array($imgInfo['mime'], $mimeAceitos, true)) {
            $logo = realpath($logoFile);
            break 2;
        }
    }
}
```

- `glob(..., GLOB_BRACE)` — busca arquivos com extensões PNG/JPG em maiúsculas e minúsculas (necessário no Windows que tem case-insensitive mas o glob pode variar)
- `@getimagesize()` — valida pelo conteúdo real do arquivo, não pela extensão (um GIF renomeado para `.png` retornaria `image/gif` e seria ignorado)
- `realpath()` — resolve o caminho absoluto canônico (remove `..`, symlinks etc.)
- `break 2` — sai dos dois `foreach` assim que encontra um logo válido

Se nenhum logo for encontrado, `$logo` permanece `null` e o DAMDFE é gerado sem logo — sem erro.

---

### 5.9 Geração do PDF

```php
try {
    $damdfe = new DamdfeCustom($xmlStr);
    $damdfe->debugMode(false);
    $damdfe->printParameters('P');
    $pdfContent = $damdfe->render($logo);
} catch (Exception $e) {
    http_response_code(500);
    exit('Erro ao gerar DAMDFE: ' . $e->getMessage());
}
```

- `new DamdfeCustom($xmlStr)` — instancia nossa subclasse passando o XML como string. No construtor da classe pai, o XML é parseado e todos os campos (emitente, percurso, modal, etc.) são extraídos via DOM.
- `debugMode(false)` — em modo debug, erros são escritos no PDF como texto. Em produção (`false`), exceções são lançadas.
- `printParameters('P')` — orientação: `'P'` = retrato (Portrait), `'L'` = paisagem (Landscape).
- `render($logo)` — executa todo o fluxo de geração: monta as seções do PDF (cabeçalho, dados do emitente, percurso, modal rodoviário, documentos, QR code, rodapé) e retorna os **bytes binários do PDF**.

O `try/catch` captura qualquer falha (XML inválido, campo obrigatório ausente etc.) e retorna `500` com a mensagem de erro.

---

### 5.10 Persistência no banco

```php
$pdfBase64 = base64_encode($pdfContent);
$db->query("UPDATE {$p}mdfe_emitidas SET pdf_damdfe = '"
    . $db->escape($pdfBase64)
    . "' WHERE id = " . $mdfeId);
```

O PDF binário é codificado em **base64** antes de salvar:
- `base64_encode()` — converte bytes arbitrários para caracteres ASCII seguros, aumentando o tamanho em ~33%
- `$db->escape()` — escapa caracteres especiais para prevenir SQL injection (embora base64 já seja seguro, é boa prática)

Isso acontece **em todo acesso** (view, download ou save), mantendo o banco sempre com a versão mais recente do PDF. Se futuramente você quiser servir o PDF direto do banco sem regenerar, basta fazer:
```php
$pdf = base64_decode($row->pdf_damdfe);
```

---

### 5.11 Respostas HTTP

```php
$chave  = !empty($row->chave_acesso) ? $row->chave_acesso : 'MDFe_' . $row->numero;
$filename = 'DAMDFE_' . $chave . '.pdf';
```

Nome do arquivo: usa a chave de 44 dígitos quando disponível (`DAMDFE_31220608583629000113580010000000011902682173.pdf`), ou o número como fallback.

#### `action=save` — resposta JSON

```php
if ($action === 'save') {
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
        'success'  => true,
        'message'  => 'PDF do DAMDFE salvo com sucesso no banco de dados.',
        'id'       => $mdfeId,
        'filename' => $filename,
        'size'     => strlen($pdfContent),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
```

Retorna JSON para chamadas AJAX. `strlen($pdfContent)` informa o tamanho em bytes do PDF gerado (útil para diagnóstico).

#### `action=view` — visualização inline

```php
if ($action === 'view') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfContent));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdfContent;
    exit;
}
```

- `Content-Type: application/pdf` — informa ao navegador que o corpo da resposta é um PDF
- `Content-Disposition: inline` — instrui o navegador a **exibir** o arquivo (usando o viewer embutido do Chrome/Firefox/Edge) em vez de baixar
- `Content-Length` — tamanho exato em bytes; permite ao navegador mostrar barra de progresso
- `Cache-Control: private, max-age=0` — não cachear (o PDF pode variar se regenerado)
- `echo $pdfContent` — escreve os bytes binários do PDF na saída HTTP

#### `action=download` — forçar download

```php
if ($action === 'download') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfContent));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdfContent;
    exit;
}
```

A única diferença de `view` é `Content-Disposition: attachment` — isso instrui o navegador a abrir o diálogo **"Salvar arquivo como..."** em vez de exibir inline.

---

## 6. Os botões no dropdown da lista

Esta seção explica toda a cadeia: o CSS do dropdown, a estrutura HTML gerada pelo PHP, a função JavaScript que o controla, e o comportamento exato após o clique em cada botão.

---

### 6.1 Arquivo e localização

Todas as alterações desta seção estão em:
```
custom/mdfe/mdfe_list.php
```

O arquivo tem ~2875 linhas. As partes relevantes estão em três lugares:

| O quê | Onde no arquivo | Descrição |
|---|---|---|
| CSS do dropdown | ~linha 816 | Estilos da `<div>` flutuante |
| PHP de geração HTML | ~linha 1700 | Loop que renderiza cada linha da tabela |
| JavaScript | ~linha 2179 | Funções `toggleDropdown()` e fechar ao clicar fora |

---

### 6.2 O CSS do dropdown

```css
/* Wrapper: posicionamento relativo para ancorar o menu */
.nfe-dropdown {
    position: relative;
    display: inline-block;
}

/* O menu flutuante — começa oculto */
.nfe-dropdown-menu {
    display: none;                          /* oculto por padrão */
    position: absolute;                     /* flutua sobre o conteúdo */
    top: calc(100% + 5px);                  /* 5px abaixo do botão */
    left: 0;
    background: #fff;
    min-width: 160px;
    box-shadow: 0 8px 16px rgba(0,0,0,.2);  /* sombra para destacar do fundo */
    z-index: 1050;                          /* acima de outros elementos */
    border-radius: 4px;
    padding: 4px 0;
}

/* Cada item do menu */
.nfe-dropdown-menu .nfe-dropdown-item {
    padding: 8px 14px;
    text-decoration: none;
    display: block;                         /* ocupa toda a largura */
    color: #333;
    font-size: .95em;
    cursor: pointer;
    line-height: 1.2em;
}

/* Hover: fundo azul-escuro, texto branco */
.nfe-dropdown-menu .nfe-dropdown-item:hover {
    background: #070973ff;
    color: #fff;
}

/* Item desabilitado: não clicável, visual acinzentado */
.nfe-dropdown-menu .nfe-dropdown-item.disabled {
    pointer-events: none;   /* desativa cliques */
    opacity: .55;
    color: #888;
    cursor: not-allowed;
}
```

**Ponto-chave:** o menu começa com `display: none`. Ele só aparece quando o JavaScript muda para `display: block`. Isso é controlado pelo `toggleDropdown()`.

---

### 6.3 A estrutura HTML gerada pelo PHP (por linha da tabela)

Para cada MDF-e na tabela, o PHP gera o seguinte HTML:

```html
<td class="center actions-cell">
  <div class="nfe-dropdown">

    <!-- Botão que abre/fecha o menu -->
    <button class="butAction dropdown-toggle"
            type="button"
            onclick="toggleDropdown(event, 'mdfeDropdownMenu42')">
      Ações
    </button>

    <!-- Menu flutuante (ID único por linha) -->
    <div class="nfe-dropdown-menu" id="mdfeDropdownMenu42">

      <!-- Item: Consultar (só para autorizada/encerrada/cancelada) -->
      <a class="nfe-dropdown-item" href="#"
         onclick="openConsultarModal(42); return false;">Consultar</a>

      <!-- Item: Download XML (sempre visível) -->
      <a class="nfe-dropdown-item"
         href="/dolibarr/htdocs/custom/mdfe/mdfe_download.php?action=individual&id=42"
         target="_blank">Download XML</a>

      <!-- 🆕 Item: Visualizar DAMDFE (sempre visível) -->
      <a class="nfe-dropdown-item"
         href="/dolibarr/htdocs/custom/mdfe/mdfe_damdfe.php?action=view&id=42"
         target="_blank">Visualizar DAMDFE</a>

      <!-- 🆕 Item: Download PDF (sempre visível) -->
      <a class="nfe-dropdown-item"
         href="/dolibarr/htdocs/custom/mdfe/mdfe_damdfe.php?action=download&id=42"
         target="_blank">Download PDF</a>

      <!-- Outros itens condicionais (encerrar, cancelar etc.) -->
    </div>

  </div>
</td>
```

**Detalhe crítico:** o `id="mdfeDropdownMenu42"` usa o `$obj->id` do banco. Como cada linha tem um ID único, cada menu tem um ID único, permitindo que o JavaScript abra/feche menus individualmente.

---

### 6.4 O código PHP que gera o dropdown

Este é o trecho do loop dentro de `mdfe_list.php`. Localize a linha ~1701 e você verá:

```php
// ─── Célula de ações ───────────────────────────────────────────────────────

// Wrapper do dropdown
print '<td class="center actions-cell"><div class="nfe-dropdown">';

// Botão que dispara o toggleDropdown()
// O ID do menu é único por linha: 'mdfeDropdownMenu' + $obj->id
print '<button class="butAction dropdown-toggle" type="button"
    onclick="toggleDropdown(event, \'mdfeDropdownMenu' . $obj->id . '\')">'
    . $langs->trans("Ações") . '</button>';

// Abre o <div> do menu (inicialmente oculto via CSS: display:none)
print '<div class="nfe-dropdown-menu" id="mdfeDropdownMenu' . $obj->id . '">';

// ── Item 1: Consultar (apenas para status com protocolo da SEFAZ) ──────────
if (in_array($status, ['autorizada', 'encerrada', 'cancelada'])) {
    print '<a class="nfe-dropdown-item" href="#"
        onclick="openConsultarModal(' . (int)$obj->id . '); return false;">Consultar</a>';
}
// "return false" cancela a navegação do href="#" — apenas executa o onclick

// ── Item 2: Download XML ───────────────────────────────────────────────────
$downloadUrl = dol_buildpath('/custom/mdfe/mdfe_download.php', 1)
    . '?action=individual&id=' . (int)$obj->id;
print '<a class="nfe-dropdown-item"
    href="' . dol_escape_htmltag($downloadUrl) . '"
    target="_blank">Download XML</a>';

// ── Item 3: 🆕 Visualizar DAMDFE ────────────────────────────────────────────
// Monta a URL para mdfe_damdfe.php com action=view
$damdfeViewUrl = dol_buildpath('/custom/mdfe/mdfe_damdfe.php', 1)
    . '?action=view&id=' . (int)$obj->id;
print '<a class="nfe-dropdown-item"
    href="' . dol_escape_htmltag($damdfeViewUrl) . '"
    target="_blank">Visualizar DAMDFE</a>';

// ── Item 4: 🆕 Download PDF ──────────────────────────────────────────────────
// Monta a URL para mdfe_damdfe.php com action=download
$damdfeDownloadUrl = dol_buildpath('/custom/mdfe/mdfe_damdfe.php', 1)
    . '?action=download&id=' . (int)$obj->id;
print '<a class="nfe-dropdown-item"
    href="' . dol_escape_htmltag($damdfeDownloadUrl) . '"
    target="_blank">Download PDF</a>';

// ── Itens condicionais (só para MDF-e autorizada) ─────────────────────────
if ($status === 'autorizada') {
    // ... Incluir NF-e, Incluir Condutor, Encerrar, Cancelar
}

// Fecha o menu e o wrapper
print '</div></div></td>';
```

### Por que cada detalhe importa

| Código | Por que está assim |
|---|---|
| `dol_buildpath('/custom/mdfe/mdfe_damdfe.php', 1)` | Gera a URL correta considerando o `DOL_URL_ROOT` do Dolibarr. Se instalado em `/dolibarr/`, gera `/dolibarr/htdocs/custom/...`. O parâmetro `1` = URL pública (relativa ao webroot). |
| `(int)$obj->id` | Cast para inteiro previne SQL/JS injection. Um atacante não pode passar `id=1; DROP TABLE`. |
| `dol_escape_htmltag($url)` | Escapa `<`, `>`, `"`, `'`, `&` dentro do atributo `href`. Sem isso, uma URL com `&` não-escapado quebraria o HTML. |
| `target="_blank"` | Abre em nova aba. **Sem isso**, o navegador navegaria para o PDF/download na mesma janela — o usuário perderia a lista de MDF-e e teria que volcar com o botão voltar. |
| `class="nfe-dropdown-item"` | Aplica automaticamente o CSS já definido: padding, hover azul-escuro, `display:block` para ocupar toda a largura. |

---

### 6.5 O JavaScript do dropdown

```javascript
/* ── toggleDropdown ─────────────────────────────────────────────────────── */
function toggleDropdown(event, menuId) {
    // Impede que o clique no botão se propague para o document
    // (senão o handler de "fechar ao clicar fora" fecharia imediatamente)
    event.stopPropagation();

    var menu = document.getElementById(menuId);
    if (!menu) return;  // segurança: menu não encontrado no DOM

    // Guarda o estado atual antes de fechar todos os menus
    var isOpen = menu.style.display === "block";

    // Fecha TODOS os menus abertos (comportamento de menu exclusivo)
    document.querySelectorAll(".nfe-dropdown-menu").forEach(function(m) {
        m.style.display = "none";
    });

    // Se este menu estava fechado, abre; se estava aberto, mantém fechado
    menu.style.display = isOpen ? "none" : "block";
}

/* ── Fechar ao clicar fora do dropdown ─────────────────────────────────── */
document.addEventListener("click", function(e) {
    // .closest() verifica se o elemento clicado está dentro de um .nfe-dropdown
    if (!e.target.closest(".nfe-dropdown")) {
        document.querySelectorAll(".nfe-dropdown-menu").forEach(function(m) {
            m.style.display = "none";
        });
    }
});

/* ── Fechar ao pressionar ESC ───────────────────────────────────────────── */
document.addEventListener("keydown", function(e) {
    if (e.key === "Escape" || e.key === "Esc") {
        document.querySelectorAll(".nfe-dropdown-menu").forEach(function(m) {
            m.style.display = "none";
        });
    }
});
```

**Fluxo de interação completo quando o usuário clica em "Ações":**

```
Usuário clica no botão "Ações" da linha 42
    │
    ▼
onclick="toggleDropdown(event, 'mdfeDropdownMenu42')"
    │
    ├─ event.stopPropagation()
    │     └─ impede que o clique chegue ao document listener
    │
    ├─ Fecha todos os .nfe-dropdown-menu abertos
    │
    └─ Abre #mdfeDropdownMenu42 (display: none → display: block)
          └─ O menu CSS com position:absolute aparece sobre a tabela
```

---

### 6.6 O que acontece quando o usuário clica em cada botão

#### "Visualizar DAMDFE"

```
Clique em <a href="/...mdfe_damdfe.php?action=view&id=42" target="_blank">
    │
    ▼
Navegador abre nova aba para a URL
    │
    ▼
PHP mdfe_damdfe.php executa action=view
    │
    ├─ Gera PDF (DamdfeCustom)
    ├─ Salva na coluna pdf_damdfe
    └─ Retorna: header('Content-Disposition: inline')
                echo $pdfContent (bytes do PDF)
    │
    ▼
Navegador recebe Content-Type: application/pdf
    └─ Exibe o PDF dentro do viewer embutido na nova aba
       (Chrome, Firefox e Edge têm viewer embutido)
```

**Não há JavaScript extra aqui.** Um simples `<a href>` com `target="_blank"` é suficiente. O navegador cuida de tudo — abrir a aba, enviar a requisição GET, receber o PDF e exibir.

#### "Download PDF"

```
Clique em <a href="/...mdfe_damdfe.php?action=download&id=42" target="_blank">
    │
    ▼
Navegador abre nova aba temporária para a URL
    │
    ▼
PHP mdfe_damdfe.php executa action=download
    │
    ├─ Gera PDF (DamdfeCustom)
    ├─ Salva na coluna pdf_damdfe
    └─ Retorna: header('Content-Disposition: attachment; filename="DAMDFE_312...pdf"')
                echo $pdfContent
    │
    ▼
Navegador detecta Content-Disposition: attachment
    ├─ Abre diálogo "Salvar como..." (ou salva direto em Downloads)
    └─ A aba temporária fecha automaticamente após o download iniciar
```

**A diferença entre `view` e `download` é literalmente uma palavra no header HTTP:**

| Botão | Header enviado | Comportamento do navegador |
|---|---|---|
| Visualizar DAMDFE | `Content-Disposition: inline` | Exibe dentro da aba |
| Download PDF | `Content-Disposition: attachment` | Abre diálogo de download |

---

### 6.7 Onde inserir o código no `mdfe_list.php`

Se você precisar adicionar os botões manualmente em uma instalação existente, localize este trecho (~linha 1710):

```php
$downloadUrl = dol_buildpath('/custom/mdfe/mdfe_download.php', 1).'?action=individual&id='.(int)$obj->id;
print '<a class="nfe-dropdown-item" href="'.dol_escape_htmltag($downloadUrl).'" target="_blank">Download XML</a>';
// ← INSERIR AQUI os dois novos botões
if ($status === 'autorizada') {
```

Insira **entre** o "Download XML" e o `if ($status === 'autorizada')`:

```php
// DAMDFE: Visualizar PDF inline em nova aba
$damdfeViewUrl = dol_buildpath('/custom/mdfe/mdfe_damdfe.php', 1).'?action=view&id='.(int)$obj->id;
print '<a class="nfe-dropdown-item" href="'.dol_escape_htmltag($damdfeViewUrl).'" target="_blank">Visualizar DAMDFE</a>';

// DAMDFE: Download PDF (força download e salva no banco)
$damdfeDownloadUrl = dol_buildpath('/custom/mdfe/mdfe_damdfe.php', 1).'?action=download&id='.(int)$obj->id;
print '<a class="nfe-dropdown-item" href="'.dol_escape_htmltag($damdfeDownloadUrl).'" target="_blank">Download PDF</a>';
```

Nenhuma alteração no JavaScript é necessária — os novos botões usam `<a href>` comum e o `toggleDropdown()` existente já fecha o menu automaticamente quando qualquer clique ocorre fora do `div.nfe-dropdown`.

---

## 7. Fluxo completo ponta a ponta

### Clique em "Visualizar DAMDFE"

```
Usuário clica no botão
       │
       ▼
GET mdfe_damdfe.php?action=view&id=42
       │
       ▼
Verifica autenticação ($user->id)
       │
       ▼
Garante coluna pdf_damdfe (ALTER TABLE silencioso)
       │
       ▼
SELECT xml_mdfe FROM llx_mdfe_emitidas WHERE id=42
       │
       ▼
new DamdfeCustom($xmlStr)
  ├─ parseXML: lê emitente, percurso, modal, documentos, QR URL
  └─ constructor OK
       │
       ▼
render($logo)
  ├─ adjustImage($logo)      → retorna path real do arquivo JPEG/PNG
  ├─ mountPDF()              → constrói seções do documento com FPDF
  └─ qrCodeDamdfe()
       ├─ gera PNG do QR com Barcode()
       ├─ tempnam() → salva em /tmp/damdfe_qr_xxx
       ├─ $this->pdf->image('/tmp/damdfe_qr_xxx', ...) → FPDF abre com fopen()
       └─ register_shutdown_function → deleta /tmp/damdfe_qr_xxx ao final
       │
       ▼
$pdfContent = bytes binários do PDF
       │
       ▼
UPDATE llx_mdfe_emitidas SET pdf_damdfe = base64($pdfContent)
       │
       ▼
header('Content-Disposition: inline')
echo $pdfContent
       │
       ▼
Navegador exibe o PDF na nova aba
```

### Diferença para "Download PDF"

Idêntico ao fluxo acima, exceto o último passo:
```
header('Content-Disposition: attachment')
echo $pdfContent
       │
       ▼
Navegador abre diálogo "Salvar como DAMDFE_31220608...pdf"
```

---

## 8. Testando e diagnosticando erros

### Testar via URL direta

```
# Visualizar (abre PDF no navegador)
http://localhost/dolibarr/htdocs/custom/mdfe/mdfe_damdfe.php?action=view&id=1

# Forçar download
http://localhost/dolibarr/htdocs/custom/mdfe/mdfe_damdfe.php?action=download&id=1

# Só salvar no banco (retorna JSON)
http://localhost/dolibarr/htdocs/custom/mdfe/mdfe_damdfe.php?action=save&id=1
```

### Verificar PDF salvo no banco

```sql
SELECT
    id,
    numero,
    chave_acesso,
    CASE WHEN pdf_damdfe IS NOT NULL THEN 'SIM' ELSE 'NÃO' END AS pdf_salvo,
    ROUND(LENGTH(pdf_damdfe) / 1024) AS tamanho_kb
FROM llx_mdfe_emitidas
WHERE id = 1;
```

### Diagnóstico de erros comuns

| Erro | Causa | Solução |
|---|---|---|
| `XML da MDF-e não encontrado` | Colunas `xml_mdfe` e `xml_enviado` estão vazias | Verifique se o `mdfe_processar.php` salvou após a autorização da SEFAZ |
| `Erro ao gerar DAMDFE: ...` com stack trace | XML malformado ou campo obrigatório ausente | Ative `debugMode(true)` temporariamente para ver detalhes |
| PDF em branco ou página em branco | Navegador sem viewer de PDF embutido | Use Chrome, Firefox ou Edge; ou use `action=download` |
| `Class 'DamdfeCustom' not found` | Autoload do Composer não encontrado | Verifique se `custom/composerlib/vendor/autoload.php` existe |
| `Call to undefined method getPngData()` | Versão antiga da lib `sped-da` | Execute `composer update` em `custom/composerlib/` |
| Botão não aparece no dropdown | Erro de PHP silenciado na geração da lista | Ative temporariamente `display_errors=On` e verifique o console do navegador |

### Ativar debug temporariamente

Se precisar diagnosticar um erro na geração do PDF, mude a linha:
```php
$damdfe->debugMode(false);
```
para:
```php
$damdfe->debugMode(true);
```
Isso fará com que eventuais erros internos da lib apareçam como texto vermelho **dentro do PDF** — muito útil para identificar campos faltantes no XML.
