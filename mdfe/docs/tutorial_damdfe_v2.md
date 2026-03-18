# Tutorial: Gerar, Visualizar e Baixar o DAMDFE (PDF) no Módulo MDF-e

---

## Sumário — O que vamos fazer

| # | O que fazer | Onde | Para quê |
|---|---|---|---|
| 1 | Criar o arquivo `mdfe_damdfe.php` | `custom/mdfe/` | Endpoint PHP que gera o PDF, exibe e permite download |
| 2 | Criar a classe `DamdfeCustom` | Dentro de `mdfe_damdfe.php` | Corrigir incompatibilidade da lib `sped-da` com PHP moderno |
| 3 | Buscar o XML no banco e gerar o PDF | Dentro de `mdfe_damdfe.php` | Ler o XML da MDF-e e transformar em PDF |
| 4 | Salvar o PDF no banco de dados | Dentro de `mdfe_damdfe.php` | Cache para não regenerar toda vez |
| 5 | Enviar o PDF para o navegador | Dentro de `mdfe_damdfe.php` | Exibir inline ou forçar download |
| 6 | Adicionar o CSS do dropdown | `mdfe_list.php` (~linha 816) | Estilizar o menu suspenso |
| 7 | Adicionar o HTML dos botões no dropdown | `mdfe_list.php` (~linha 1714) | Links para visualizar e baixar o PDF |
| 8 | Adicionar o JavaScript do dropdown | `mdfe_list.php` (~linha 2179) | Abrir/fechar o menu suspenso |

---

## 1. Criar o arquivo `mdfe_damdfe.php`

**O que:** criar um arquivo PHP que será o ponto de entrada (endpoint) para gerar o PDF do DAMDFE.

**Onde:** `custom/mdfe/mdfe_damdfe.php`

### Código — Cabeçalho e setup inicial

```php
<?php
/**
 * Visualização / Download do DAMDFE (PDF) de uma MDF-e emitida.
 *
 * Parâmetros GET:
 *   id     = ID na tabela mdfe_emitidas
 *   action = "view"     → exibe o PDF inline no navegador
 *            "download" → força download do arquivo PDF
 *            "save"     → gera e salva no banco, retorna JSON
 */

// Suprime warnings de funções deprecated da lib sped-da
@ini_set('display_errors', '0');
$__lvl = error_reporting();
$__lvl &= ~(E_DEPRECATED | E_USER_DEPRECATED | E_NOTICE | E_USER_NOTICE | E_WARNING | E_USER_WARNING);
error_reporting($__lvl);

// Inicializa o Dolibarr (autenticação, banco, configs globais)
require '../../main.inc.php';

/** @var DoliDB $db */
/** @var User $user */

// Carrega o autoload do Composer (lib sped-da)
if (file_exists(__DIR__ . '/../composerlib/vendor/autoload.php')) {
    require_once __DIR__ . '/../composerlib/vendor/autoload.php';
}

use NFePHP\DA\MDFe\Damdfe;
use Com\Tecnick\Barcode\Barcode;
```

### Explicação

| Linha | O que faz |
|---|---|
| `@ini_set('display_errors', '0')` | Evita que warnings de funções deprecated da lib sped-da sejam exibidos no navegador (poluiria o PDF). |
| `error_reporting($__lvl)` | Remove os níveis E_DEPRECATED, E_NOTICE e E_WARNING do report. Erros fatais continuam sendo reportados. |
| `require '../../main.inc.php'` | Inicializa o Dolibarr: sessão, banco de dados (`$db`), usuário (`$user`), constantes (`$conf`). O `../../` sai de `custom/mdfe/` até `htdocs/`. |
| `require_once __DIR__ . '/../composerlib/vendor/autoload.php'` | Carrega todas as classes do Composer (sped-da, barcode, FPDF). O `__DIR__` garante caminho absoluto correto. |
| `use NFePHP\DA\MDFe\Damdfe` | Importa a classe que gera o DAMDFE. |
| `use Com\Tecnick\Barcode\Barcode` | Importa a classe que gera o QR Code PNG. Usada no override de `qrCodeDamdfe()`. |

---

## 2. Criar a classe `DamdfeCustom`

**O que:** criar uma subclasse de `Damdfe` que corrige dois métodos problemáticos.

**Por quê:** a lib `sped-da` usa `data://text/plain;base64,...` como caminho de imagem. O FPDF tenta abrir com `fopen()`, que precisa de `allow_url_include=On` — desabilitado por padrão no PHP 7.4+.

### Código — Classe completa

```php
class DamdfeCustom extends Damdfe
{
    /**
     * Override de adjustImage: retorna o caminho real do arquivo ao invés de
     * converter para data://text/plain;base64,... que o FPDF não consegue abrir.
     */
    protected function adjustImage($logo, $turn_bw = false)
    {
        // Se já foi definido um logo via $this->logomarca, usa ele
        if (!empty($this->logomarca)) {
            return $this->logomarca;
        }
        if (empty($logo)) {
            return null;
        }
        if (!is_file($logo)) {
            return null;
        }

        // Valida que é uma imagem real (não um arquivo corrompido)
        $info = @getimagesize($logo);
        if (!$info) {
            return null;
        }

        // FPDF só aceita JPEG (2) e PNG (3)
        if (!in_array($info[2], [2, 3], true)) {
            return null;
        }

        // JPEG sem conversão — retorna caminho direto
        if ($info[2] === 2 && !$turn_bw) {
            return $logo;
        }

        // PNG ou precisa de P&B: converte para JPEG temporário
        $image = ($info[2] === 3) ? imagecreatefrompng($logo) : imagecreatefromjpeg($logo);
        if (!$image) {
            return null;
        }
        if ($turn_bw) {
            imagefilter($image, IMG_FILTER_GRAYSCALE);
        }

        // Cria arquivo temporário e salva o JPEG
        $tmp = tempnam(sys_get_temp_dir(), 'damdfe_logo_');
        imagejpeg($image, $tmp, 100);
        imagedestroy($image);

        // Garante limpeza do temporário ao final do request
        register_shutdown_function(function () use ($tmp) {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        });

        return $tmp;
    }

    /**
     * Override de qrCodeDamdfe: salva o PNG do QR code em arquivo temporário
     * ao invés de criar um data:// URI que o FPDF não consegue abrir.
     */
    protected function qrCodeDamdfe($y = 0)
    {
        $margemInterna = $this->margemInterna;

        // Gera o QR Code como bytes PNG
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

        // Coordenadas e tamanho do QR no PDF
        $wQr = 35;
        $hQr = 35;
        $yQr = $y + $margemInterna;
        $xQr = ($this->orientacao === 'P') ? 160 : 235;

        // Salva em arquivo temporário (caminho real, não data://)
        $tmp = tempnam(sys_get_temp_dir(), 'damdfe_qr_');
        file_put_contents($tmp, $qrcode);

        // Limpeza automática ao final do request
        register_shutdown_function(function () use ($tmp) {
            if (file_exists($tmp)) {
                @unlink($tmp);
            }
        });

        // Passa caminho real ao FPDF → funciona sem allow_url_include
        $this->pdf->image($tmp, $xQr, $yQr, $wQr, $hQr, 'PNG');
    }
}
```

### Explicação

| Método | Problema original | Correção |
|---|---|---|
| `adjustImage()` | Convertia logo para `data://` URI | Retorna o caminho real do arquivo. Se for PNG, converte para JPEG temporário via GD. |
| `qrCodeDamdfe()` | Gerava QR como `data://` URI | Salva os bytes PNG em `tempnam()` e passa o caminho real para `$this->pdf->image()`. |

**Por que `tempnam()` + `register_shutdown_function()`?**

- `tempnam()` cria um arquivo único no diretório temp do sistema (ex: `C:\Users\...\AppData\Local\Temp\damdfe_qr_ab1c2d`)
- O FPDF abre esse arquivo com `fopen($file, 'rb')` — funciona sem restrições
- `register_shutdown_function()` deleta o arquivo temporário automaticamente quando o PHP terminar de processar o request, evitando acúmulo de lixo no disco

---

## 3. Buscar o XML no banco e gerar o PDF

**O que:** autenticar o usuário, ler parâmetros, buscar o XML da MDF-e e gerar o PDF.

### Código — Autenticação e parâmetros

```php
// Autenticação — só usuários logados podem acessar
if (!$user->id) {
    http_response_code(403);
    exit('Acesso negado.');
}

// Parâmetros da URL
$mdfeId = (int) GETPOST('id', 'int');
$action = GETPOST('action', 'alpha') ?: 'view';

if ($mdfeId <= 0) {
    http_response_code(400);
    exit('ID inválido.');
}
```

### Explicação

| Linha | O que faz |
|---|---|
| `$user->id` | Variável global do Dolibarr. Se 0 ou null, o usuário não está logado. |
| `GETPOST('id', 'int')` | Função do Dolibarr que lê `$_GET['id']` e sanitiza como inteiro. Previne SQL injection. |
| `GETPOST('action', 'alpha')` | Lê `$_GET['action']` e permite apenas letras. Impede injeção de caracteres especiais. |
| `?: 'view'` | Se `action` veio vazio, assume `'view'` (modo visualização). |

### Código — Migração automática do banco

```php
$p = MAIN_DB_PREFIX;
@$db->query("ALTER TABLE {$p}mdfe_emitidas ADD COLUMN pdf_damdfe LONGBLOB DEFAULT NULL");
```

### Explicação

| Detalhe | Por quê |
|---|---|
| `MAIN_DB_PREFIX` | Constante do Dolibarr com o prefixo das tabelas (geralmente `llx_`). |
| `ALTER TABLE ... ADD COLUMN` | Cria a coluna `pdf_damdfe` se não existir. Se já existir, o MySQL retorna erro silencioso (por causa do `@`). |
| `LONGBLOB` | Tipo binário que suporta até 4GB. O PDF em base64 cabe com folga. |
| `@` antes da query | Suprime o warning de "coluna já existe" em execuções posteriores. |

### Código — Busca do XML

```php
$sql = "SELECT * FROM {$p}mdfe_emitidas WHERE id = " . $mdfeId;
$res = $db->query($sql);
if (!$res || $db->num_rows($res) === 0) {
    http_response_code(404);
    exit('MDF-e não encontrada.');
}
$row = $db->fetch_object($res);

// Extrair XML
$xmlStr = '';
if (!empty($row->xml_mdfe)) {
    $xmlStr = is_resource($row->xml_mdfe)
        ? stream_get_contents($row->xml_mdfe)
        : (string) $row->xml_mdfe;
}
// Fallback: xml_enviado (XML assinado, antes de autorizar)
if (empty(trim($xmlStr)) && !empty($row->xml_enviado)) {
    $xmlStr = is_resource($row->xml_enviado)
        ? stream_get_contents($row->xml_enviado)
        : (string) $row->xml_enviado;
}

if (empty(trim($xmlStr))) {
    http_response_code(422);
    exit('XML da MDF-e não encontrado no banco de dados.');
}
```

### Explicação

| Detalhe | Por quê |
|---|---|
| `$db->query()` / `$db->fetch_object()` | API de banco do Dolibarr. Funciona com MySQL, PostgreSQL e MariaDB. |
| `is_resource($row->xml_mdfe)` | Colunas BLOB/TEXT grandes podem retornar como resource (stream) em vez de string. `stream_get_contents()` lê tudo. |
| Fallback `xml_enviado` | Se o XML completo (`xml_mdfe`) não foi salvo, tenta o XML assinado (`xml_enviado`). O Damdfe aceita ambos. |

### Código — Busca do logo

```php
$logo = null;
$possibleLogoPaths = [
    DOL_DATA_ROOT . '/mycompany/logos/thumbs/',
    DOL_DATA_ROOT . '/mycompany/logos/',
];

$mimeAceitos = ['image/png', 'image/jpeg'];

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

### Explicação

| Detalhe | Por quê |
|---|---|
| `DOL_DATA_ROOT` | Diretório de dados do Dolibarr (ex: `C:\xampp\htdocs\documents`). É onde ficam os logos. |
| `glob(..., GLOB_BRACE)` | Busca arquivos com extensão PNG, JPG ou JPEG (maiúsculo e minúsculo). |
| `getimagesize()` | Valida que o arquivo é uma imagem real (verifica magic bytes), não apenas pela extensão. |
| `break 2` | Sai dos dois loops (`foreach` + `foreach`) assim que encontra o primeiro logo válido. |
| `realpath()` | Converte para caminho absoluto canônico, resolvendo `..\`, symlinks etc. |

---

## 4. Gerar o PDF e salvar no banco

**O que:** usar a classe `DamdfeCustom` para renderizar o PDF e salvar como cache.

### Código

```php
try {
    $damdfe = new DamdfeCustom($xmlStr);
    $damdfe->debugMode(false);
    $damdfe->printParameters('P');  // P = Retrato
    $pdfContent = $damdfe->render($logo);

} catch (Exception $e) {
    http_response_code(500);
    exit('Erro ao gerar DAMDFE: ' . $e->getMessage());
}

// Salvar no banco (cache)
$pdfBase64 = base64_encode($pdfContent);
$db->query("UPDATE {$p}mdfe_emitidas SET pdf_damdfe = '"
    . $db->escape($pdfBase64) . "' WHERE id = " . $mdfeId);
```

### Explicação

| Linha | O que faz |
|---|---|
| `new DamdfeCustom($xmlStr)` | Instancia a subclasse, passando o XML. O construtor parse o XML e extrai os dados. |
| `debugMode(false)` | Desativa modo debug da lib (não imprime logs internos). |
| `printParameters('P')` | Configura orientação Retrato. Use `'L'` para Paisagem. |
| `render($logo)` | Gera o PDF completo como string binária. O `$logo` é o caminho do arquivo (ou `null`). |
| `base64_encode()` | Converte binário para texto base64, seguro para armazenar em coluna LONGBLOB. |
| `$db->escape()` | Escapa caracteres especiais do base64 para prevenir SQL injection. |

---

## 5. Enviar o PDF para o navegador

**O que:** retornar o PDF com os headers HTTP corretos, dependendo da ação.

### Código

```php
$chave  = !empty($row->chave_acesso) ? $row->chave_acesso : 'MDFe_' . $row->numero;
$filename = 'DAMDFE_' . $chave . '.pdf';

// AÇÃO: save (chamada via AJAX)
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

// AÇÃO: view (exibir no navegador)
if ($action === 'view') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfContent));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdfContent;
    exit;
}

// AÇÃO: download (forçar download)
if ($action === 'download') {
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdfContent));
    header('Cache-Control: private, max-age=0, must-revalidate');
    echo $pdfContent;
    exit;
}
```

### Explicação

| Header | Efeito no navegador |
|---|---|
| `Content-Disposition: inline` | O navegador exibe o PDF dentro da aba (Chrome, Firefox e Edge têm viewer embutido). |
| `Content-Disposition: attachment` | O navegador abre o diálogo "Salvar como..." e baixa o arquivo. |
| `Content-Length` | Informa o tamanho exato, permitindo barra de progresso no download. |
| `Cache-Control: private, max-age=0, must-revalidate` | Força o navegador a sempre buscar a versão mais recente do PDF. |

**A diferença entre `view` e `download` é uma única palavra:** `inline` vs `attachment`.

---

## 6. Adicionar o CSS do dropdown

**O que:** definir os estilos do menu suspenso que contém os botões de ação.

**Onde:** `mdfe_list.php`, dentro do bloco `<style>` existente (~linha 816).

### Código

```css
/* Container — posicionamento relativo para ancorar o menu */
.nfe-dropdown {
    position: relative;
    display: inline-block;
}

/* Menu flutuante — invisível por padrão */
.nfe-dropdown-menu {
    display: none;                          /* oculto por padrão */
    position: absolute;                     /* flutua sobre o conteúdo */
    top: calc(100% + 5px);                  /* 5px abaixo do botão */
    left: 0;
    background: #fff;
    min-width: 160px;
    box-shadow: 0 8px 16px rgba(0,0,0,.2);  /* sombra para destacar */
    z-index: 1050;                          /* acima de outros elementos */
    border-radius: 4px;
    padding: 4px 0;
}

/* Cada item clicável */
.nfe-dropdown-menu .nfe-dropdown-item {
    padding: 8px 14px;
    text-decoration: none;
    display: block;                         /* ocupa toda a largura */
    color: #333;
    font-size: .95em;
    cursor: pointer;
}

/* Hover — fundo escuro, texto branco */
.nfe-dropdown-menu .nfe-dropdown-item:hover {
    background: #070973ff;
    color: #fff;
}

/* Item desabilitado — não clicável */
.nfe-dropdown-menu .nfe-dropdown-item.disabled {
    pointer-events: none;
    opacity: .55;
    color: #888;
    cursor: not-allowed;
}
```

### Explicação

| Regra CSS | Por quê |
|---|---|
| `position: relative` no container | Ancora o `position: absolute` do menu. Sem isso, o menu flutuaria relativo à página inteira. |
| `display: none` no menu | Começa invisível. O JavaScript muda para `display: block` quando o botão é clicado. |
| `z-index: 1050` | Garante que o menu fica acima de todos os outros elementos da tabela (o Bootstrap usa z-index 1000 para modals). |
| `pointer-events: none` no `.disabled` | Desativa qualquer clique no item, mesmo com href. |

---

## 7. Adicionar os botões no dropdown

**O que:** inserir dois links (`<a>`) no menu suspenso de cada linha da tabela.

**Onde:** `mdfe_list.php`, dentro do loop que gera cada linha (~linha 1714).

### Código — Estrutura HTML completa do dropdown

```php
// ── Container do dropdown ──────────────────────────────────────────────────
print '<td class="center actions-cell"><div class="nfe-dropdown">';

// Botão toggle — cada linha tem um ID único para o menu
print '<button class="butAction dropdown-toggle" type="button"
    onclick="toggleDropdown(event, \'mdfeDropdownMenu' . $obj->id . '\')">'
    . $langs->trans("Ações") . '</button>';

// Menu (oculto por padrão — display:none via CSS)
print '<div class="nfe-dropdown-menu" id="mdfeDropdownMenu' . $obj->id . '">';
```

### Código — Os dois novos botões DAMDFE

Inserir **entre** o item "Download XML" e o `if ($status === 'autorizada')`:

```php
// Botão 1: Visualizar DAMDFE (abre PDF na aba)
$damdfeViewUrl = dol_buildpath('/custom/mdfe/mdfe_damdfe.php', 1)
    . '?action=view&id=' . (int)$obj->id;
print '<a class="nfe-dropdown-item"
    href="' . dol_escape_htmltag($damdfeViewUrl) . '"
    target="_blank">Visualizar DAMDFE</a>';

// Botão 2: Download PDF (força download)
$damdfeDownloadUrl = dol_buildpath('/custom/mdfe/mdfe_damdfe.php', 1)
    . '?action=download&id=' . (int)$obj->id;
print '<a class="nfe-dropdown-item"
    href="' . dol_escape_htmltag($damdfeDownloadUrl) . '"
    target="_blank">Download PDF</a>';
```

### Explicação

| Código | Por quê |
|---|---|
| `dol_buildpath('/custom/mdfe/mdfe_damdfe.php', 1)` | Gera URL correta considerando o `DOL_URL_ROOT`. O `1` = URL pública relativa ao webroot. |
| `(int)$obj->id` | Cast para inteiro. Previne injection. |
| `dol_escape_htmltag($url)` | Escapa `<`, `>`, `"`, `'`, `&` para uso seguro no atributo `href`. |
| `target="_blank"` | Abre em nova aba. Sem isso, o PDF substituiria a lista na mesma janela. |
| `class="nfe-dropdown-item"` | Aplica o CSS definido no passo 6 (padding, hover azul, display:block). |

---

## 8. Adicionar o JavaScript do dropdown

**O que:** criar a função que abre/fecha o menu e os listeners para fechar ao clicar fora.

**Onde:** `mdfe_list.php`, na seção `<script>` do final da página (~linha 2179).

### Código

```javascript
function toggleDropdown(event, menuId) {
    // Impede que o clique se propague para o document
    // (senão o listener de "fechar ao clicar fora" fecharia imediatamente)
    event.stopPropagation();

    var menu = document.getElementById(menuId);
    if (!menu) return;

    // Guarda o estado antes de fechar todos os outros menus
    var isOpen = menu.style.display === "block";

    // Fecha TODOS os menus (comportamento de menu exclusivo)
    document.querySelectorAll(".nfe-dropdown-menu").forEach(function(m) {
        m.style.display = "none";
    });

    // Se estava fechado, abre; se estava aberto, mantém fechado
    menu.style.display = isOpen ? "none" : "block";
}

// Fechar ao clicar fora do dropdown
document.addEventListener("click", function(e) {
    if (!e.target.closest(".nfe-dropdown")) {
        document.querySelectorAll(".nfe-dropdown-menu").forEach(function(m) {
            m.style.display = "none";
        });
    }
});

// Fechar ao pressionar ESC
document.addEventListener("keydown", function(e) {
    if (e.key === "Escape" || e.key === "Esc") {
        document.querySelectorAll(".nfe-dropdown-menu").forEach(function(m) {
            m.style.display = "none";
        });
    }
});
```

### Explicação

| Código | O que faz |
|---|---|
| `event.stopPropagation()` | O clique no botão **não** se propaga para o `document`. Sem isso, o listener do passo seguinte fecharia o menu imediatamente. |
| Fecha todos antes de abrir | Se o usuário clicou no botão da linha 5, o menu da linha 3 (se estiver aberto) fecha. Apenas um menu aberto por vez. |
| `isOpen ? "none" : "block"` | Toggle: se clicar no mesmo botão duas vezes, fecha. |
| `e.target.closest(".nfe-dropdown")` | Verifica se o clique foi dentro de qualquer dropdown. Se não, fecha todos. |
| `e.key === "Escape"` | Listener de teclado para fechar com ESC. |

---

## Fluxo completo — O que acontece quando o usuário clica

### "Visualizar DAMDFE"

```
1. Usuário clica → <a href="mdfe_damdfe.php?action=view&id=42" target="_blank">
2. Navegador abre nova aba
3. PHP executa mdfe_damdfe.php
4. Busca XML no banco → gera PDF com DamdfeCustom → salva cache no banco
5. Responde com header: Content-Disposition: inline
6. Navegador exibe o PDF no viewer embutido
```

### "Download PDF"

```
1. Usuário clica → <a href="mdfe_damdfe.php?action=download&id=42" target="_blank">
2. Navegador abre nova aba temporária
3. PHP executa mdfe_damdfe.php
4. Busca XML no banco → gera PDF com DamdfeCustom → salva cache no banco
5. Responde com header: Content-Disposition: attachment
6. Navegador abre diálogo "Salvar como..." e fecha a aba temporária
```

**A única diferença** entre os dois é uma palavra no header HTTP: `inline` vs `attachment`.

---

## Checklist de testes

| Teste | Como verificar |
|---|---|
| PDF exibe corretamente | Clique em "Visualizar DAMDFE" — deve abrir PDF na aba |
| Download funciona | Clique em "Download PDF" — deve iniciar download |
| Logo aparece no PDF | Verifique se há PNG/JPG em `documents/mycompany/logos/` |
| QR Code aparece | O QR é gerado automaticamente se o XML tiver campo `qrCodMDFe` |
| Coluna criada no banco | Execute: `DESCRIBE llx_mdfe_emitidas;` — deve ter `pdf_damdfe` |
| Cache no banco | Após gerar, consulte: `SELECT LENGTH(pdf_damdfe) FROM llx_mdfe_emitidas WHERE id=X` |
| Erro de XML vazio | Acesse com `id` sem XML salvo — deve retornar HTTP 422 |
