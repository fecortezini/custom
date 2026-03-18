# Documentação Completa — Inclusão de DF-e (NF-e) em MDF-e Autorizada

## Índice

1. [Visão Geral](#visão-geral)
2. [Arquivos Envolvidos](#arquivos-envolvidos)
3. [Fluxo Completo](#fluxo-completo)
4. [Arquivo: mdfe_incluir_dfe.php (Backend AJAX)](#arquivo-mdfe_incluir_dfephp-backend-ajax)
5. [Arquivo: mdfe_list.php (Frontend — Modal + JS)](#arquivo-mdfe_listphp-frontend--modal--js)
6. [Banco de Dados](#banco-de-dados)
7. [Como Testar](#como-testar)
8. [Glossário](#glossário)

---

## Visão Geral

Quando um MDF-e (Manifesto Eletrônico de Documentos Fiscais) já está **autorizado** pela SEFAZ, pode ser necessário **incluir novos documentos fiscais** (NF-e) que não estavam originalmente no manifesto. Isso é feito através do **evento 110115 — Inclusão de DF-e**.

### O que o sistema faz:
1. O usuário abre a lista de MDF-e emitidas
2. No dropdown de ações de uma MDF-e autorizada, clica em **"Incluir NF-e"**
3. Uma janela (modal) aparece pedindo:
   - UF e município de carregamento (onde a mercadoria é carregada)
   - UF e município de descarga (onde a mercadoria será entregue)
   - Chave de acesso da NF-e (44 dígitos)
4. Ao confirmar, o sistema envia o evento à SEFAZ
5. Se aprovado, salva no banco de dados

---

## Arquivos Envolvidos

| Arquivo | Função |
|---------|--------|
| `mdfe_incluir_dfe.php` | Backend que processa as requisições AJAX (buscar cidades e enviar evento) |
| `mdfe_list.php` | Frontend com a modal HTML, CSS e funções JavaScript |
| `lib/ibge_utils.php` | Biblioteca auxiliar para buscar dados do IBGE (códigos de municípios) |
| `lib/certificate_security.lib.php` | Biblioteca para carregar e descriptografar o certificado digital |

---

## Fluxo Completo

```
Usuário clica "Incluir NF-e"
        │
        ▼
  Modal abre (mdfe_list.php)
        │
        ├─ Seleciona UF de Carregamento
        │   └─ AJAX para mdfe_incluir_dfe.php?action=buscar_cidades&uf=XX
        │       └─ Retorna lista de cidades → preenche o select de município
        │
        ├─ Seleciona UF de Descarga
        │   └─ AJAX para mdfe_incluir_dfe.php?action=buscar_cidades&uf=YY
        │       └─ Retorna lista de cidades → preenche o select de município
        │
        ├─ Digita a chave da NF-e (44 dígitos)
        │
        └─ Clica "Incluir NF-e"
            │
            ▼
    POST para mdfe_incluir_dfe.php?action=incluir_dfe
            │
            ├─ Valida campos
            ├─ Busca MDF-e no banco (deve estar "autorizada")
            ├─ Resolve códigos IBGE dos municípios
            ├─ Calcula próximo nSeqEvento
            ├─ Monta configuração e carrega certificado
            ├─ Chama sefazIncluiDFe() da NFePHP
            ├─ Verifica resposta (cStat === 135 = sucesso)
            ├─ Grava evento na tabela mdfe_eventos
            └─ Retorna JSON de sucesso ou erro
```

---

## Arquivo: mdfe_incluir_dfe.php (Backend AJAX)

Este arquivo é o "cérebro" da funcionalidade. Ele recebe requisições AJAX e retorna respostas em formato JSON.

### Cabeçalho e Inicialização

```php
@ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
```
- **O que faz**: Desativa a exibição de erros na tela
- **Por quê**: Se um aviso do PHP aparecesse na resposta, quebraria o formato JSON que o JavaScript espera

```php
require_once '../../main.inc.php';
```
- **O que faz**: Carrega o Dolibarr inteiro (banco de dados, sessão, configurações)
- **Por quê**: Sem isso, não temos acesso ao banco de dados (`$db`) nem às funções do Dolibarr

```php
require_once __DIR__ . '/../composerlib/vendor/autoload.php';
require_once DOL_DOCUMENT_ROOT . '/custom/labapp/lib/ibge_utils.php';
require_once DOL_DOCUMENT_ROOT . '/custom/mdfe/lib/certificate_security.lib.php';
```
- **Linha 1**: Carrega a biblioteca NFePHP (que sabe se comunicar com a SEFAZ)
- **Linha 2**: Carrega funções para buscar dados do IBGE (código de municípios)
- **Linha 3**: Carrega funções para ler o certificado digital A1

### Verificação de AJAX

```php
if (
    empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'
) {
    http_response_code(403);
    exit('Acesso negado.');
}
```
- **O que faz**: Verifica se a requisição veio do JavaScript (AJAX)
- **Por quê**: Impede que alguém acesse diretamente pelo navegador digitando a URL
- **Como funciona**: O JavaScript envia um cabeçalho especial `X-Requested-With: XMLHttpRequest`. Se esse cabeçalho não existir, retorna "Acesso negado"

### Funções Auxiliares

```php
function jsonError(string $msg, int $httpCode = 200): void
{
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}
```
- **O que faz**: Retorna uma resposta JSON de erro padronizada
- **Exemplo de saída**: `{"success": false, "error": "Chave da NF-e deve ter 44 dígitos"}`
- `JSON_UNESCAPED_UNICODE`: Mantém caracteres acentuados legíveis (não transforma "é" em "\u00e9")

```php
function jsonSuccess(array $extra = []): void
{
    echo json_encode(array_merge(['success' => true], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}
```
- **O que faz**: Retorna uma resposta JSON de sucesso
- **Exemplo de saída**: `{"success": true, "protocolo": "123456789012345", "nSeqEvento": 2}`

### Função: Carregar Certificado Digital

```php
function incDfe_carregarCertificado($db)
```
- **O que faz**: Lê o certificado digital A1 (arquivo .pfx) armazenado no banco de dados
- **Por quê**: O certificado é necessário para assinar digitalmente os eventos enviados à SEFAZ
- **Passo a passo**:
  1. Busca na tabela `nfe_config` os campos `cert_pfx` (arquivo do certificado) e `cert_pass` (senha)
  2. Se o certificado estiver como recurso de stream, converte para string
  3. Descriptografa a senha usando `decryptPassword()`
  4. Tenta ler o certificado com `Certificate::readPfx()`
  5. Se falhar, tenta decodificar de base64 primeiro e ler novamente

### ACTION: buscar_cidades

```php
if ($action === 'buscar_cidades') {
    $uf = strtoupper(trim(GETPOST('uf', 'alpha')));
```
- **Quando é chamada**: Quando o usuário seleciona uma UF no dropdown
- `GETPOST('uf', 'alpha')`: Pega o valor do parâmetro `uf` da requisição, aceitando apenas letras
- `strtoupper()`: Converte para maiúsculas (ex: "es" → "ES")
- `trim()`: Remove espaços nas pontas

```php
    $sql = "SELECT codigo_ibge, nome
            FROM " . MAIN_DB_PREFIX . "estados_municipios_ibge
            WHERE active = 1 AND uf = '" . $db->escape($uf) . "'
            ORDER BY nome ASC";
```
- **O que faz**: Busca todas as cidades ativas de uma UF na tabela do IBGE
- `MAIN_DB_PREFIX`: Prefixo das tabelas do Dolibarr (geralmente `llx_`)
- `$db->escape($uf)`: Protege contra injeção SQL (alguém tentando invadir o banco)
- `ORDER BY nome ASC`: Ordena alfabeticamente

```php
    $cidades = [];
    while ($obj = $db->fetch_object($res)) {
        $cidades[] = [
            'codigo_ibge' => $obj->codigo_ibge,
            'nome'        => $obj->nome,
        ];
    }
    jsonSuccess(['cidades' => $cidades]);
```
- **O que faz**: Monta um array com todas as cidades e retorna como JSON
- **Exemplo de resposta**:
```json
{
  "success": true,
  "cidades": [
    {"codigo_ibge": "3200102", "nome": "AFONSO CLAUDIO"},
    {"codigo_ibge": "3200136", "nome": "AGUIA BRANCA"}
  ]
}
```

### ACTION: incluir_dfe

Esta é a ação principal que envia o evento à SEFAZ.

#### Captura dos parâmetros:
```php
$mdfeId       = (int) GETPOST('id', 'int');
$ufCarrega    = strtoupper(trim(GETPOST('uf_carrega', 'alpha')));
$munCarrega   = trim(GETPOST('mun_carrega', 'alpha'));
$ufDescarga   = strtoupper(trim(GETPOST('uf_descarga', 'alpha')));
$munDescarga  = trim(GETPOST('mun_descarga', 'alpha'));
$chNFe        = trim(GETPOST('chNFe', 'alpha'));
```
- `(int)`: Converte para número inteiro (proteção contra injeção SQL)
- Cada campo é limpo e validado

#### Validações:
```php
if ($mdfeId <= 0)                jsonError('ID da MDF-e inválido.');
if (strlen($ufCarrega) !== 2)    jsonError('Selecione a UF de carregamento.');
if (strlen($chNFe) !== 44)      jsonError('A chave da NF-e deve ter 44 dígitos.');
if (!ctype_digit($chNFe))       jsonError('A chave da NF-e deve conter apenas números.');
```
- `strlen()`: Verifica o tamanho do texto
- `ctype_digit()`: Verifica se contém apenas dígitos (0-9)

#### Buscar MDF-e no banco:
```php
$sqlMdfe = "SELECT * FROM " . MAIN_DB_PREFIX . "mdfe_emitidas WHERE id = " . $mdfeId;
```
- Busca a MDF-e pela ID
- Verifica se o status é "autorizada" (só pode incluir DF-e em MDF-e autorizadas)
- Verifica se tem chave de acesso e protocolo (necessários para o evento)

#### Resolver códigos IBGE:
```php
$ibgeCarrega = buscarDadosIbge($db, $munCarrega, $ufCarrega);
```
- **O que faz**: Dado o nome do município (ex: "VITORIA") e a UF (ex: "ES"), retorna o código IBGE (ex: "3205309")
- **Por quê**: A SEFAZ exige o código numérico do IBGE, não o nome da cidade
- **Retorno**: Objeto com `codigo_ibge`, `nome`, `uf`, `codigo_uf`, `nome_estado`

#### Calcular próximo número de sequência:
```php
$sqlSeq = "SELECT COALESCE(MAX(nSeqEvento), 0) AS ultimo
           FROM " . MAIN_DB_PREFIX . "mdfe_eventos
           WHERE fk_mdfe_emitida = " . $mdfeId;
$nSeqEvento = $ultimoSeq + 1;
```
- **O que faz**: Busca o maior número de sequência de eventos desta MDF-e
- `COALESCE(MAX(...), 0)`: Se não existir nenhum evento, retorna 0 (ao invés de NULL)
- Incrementa em 1 para o próximo evento
- **Exemplo**: Se já tem eventos 1, 2 e 3 → próximo será 4

#### Montar configuração e enviar:
```php
$configMdfe = [
    'atualizacao' => date('Y-m-d H:i:s'),
    'tpAmb'       => $ambienteVal,        // 1 = Produção, 2 = Homologação
    'razaosocial' => $mysoc->name ?? '',
    'cnpj'        => $cnpj,
    'ie'          => preg_replace('/\D/', '', $mysoc->idprof3 ?? ''),
    'siglaUF'     => $mysoc->state_code ?? 'ES',
    'versao'      => '3.00',
];
```
- **O que faz**: Monta o array de configuração que a biblioteca NFePHP precisa
- `$mysoc`: Objeto global do Dolibarr com os dados da empresa logada
- `preg_replace('/\D/', '', ...)`: Remove tudo que não é dígito (pontos, traços, barras)

```php
$infDoc = [
    [
        'cMunDescarga' => $ibgeDescarga->codigo_ibge,
        'xMunDescarga' => strtoupper($ibgeDescarga->nome),
        'chNFe'        => $chNFe,
    ],
];
```
- **O que faz**: Monta o array de documentos a incluir
- **IMPORTANTE**: Deve ser um array de arrays (lista de documentos), mesmo que só tenha um
- `cMunDescarga`: Código IBGE do município de descarga
- `xMunDescarga`: Nome do município de descarga em maiúsculas
- `chNFe`: Chave de acesso da NF-e

```php
$resp = $tools->sefazIncluiDFe(
    $mdfe->chave_acesso,    // Chave da MDF-e
    $mdfe->protocolo,       // Protocolo de autorização
    $ibgeCarrega->codigo_ibge,  // Código IBGE do município de carregamento
    strtoupper($ibgeCarrega->nome),  // Nome do município de carregamento
    $infDoc,                // Array de documentos a incluir
    (string) $nSeqEvento    // Número de sequência do evento
);
```
- **O que faz**: Envia o evento "Inclusão de DF-e" para a SEFAZ
- **Retorna**: XML de resposta da SEFAZ

#### Processar resposta:
```php
$st  = new Standardize();
$std = $st->toStd($resp);
$cStat = (int) ($std->infEvento->cStat ?? 0);
```
- `Standardize`: Classe da NFePHP que converte XML para objeto PHP
- `cStat`: Código de status da resposta da SEFAZ
  - **135** = Evento registrado e vinculado com sucesso
  - Qualquer outro = Erro ou rejeição

#### Gravar no banco:
```php
$sqlInsert = "INSERT INTO " . MAIN_DB_PREFIX . "mdfe_eventos
    (fk_mdfe_emitida, tpEvento, nSeqEvento, protocolo_evento, motivo_evento,
     data_evento, xml_requisicao, xml_resposta, xml_evento_completo)
    VALUES (...)";
```
- **O que faz**: Salva o evento no banco de dados para histórico
- `tpEvento`: "110115" (código do tipo de evento — Inclusão de DF-e)
- `xml_requisicao`: XML que foi enviado à SEFAZ
- `xml_resposta`: XML que a SEFAZ respondeu

---

## Arquivo: mdfe_list.php (Frontend — Modal + JS)

### Modal HTML (CSS + HTML)

#### Estilos CSS:
```css
.mdfe-inc-overlay { position:fixed; inset:0; background:rgba(0,0,0,.45); ... }
```
- **O que faz**: Cria uma camada escura semi-transparente que cobre toda a tela
- `position:fixed`: Fica fixo na tela, mesmo ao rolar
- `inset:0`: Ocupa toda a tela (shorthand para top:0; right:0; bottom:0; left:0)
- `display:none`: Escondido por padrão

```css
.mdfe-inc-overlay.visible { display:flex; }
```
- Quando a classe `visible` é adicionada, o overlay aparece
- `display:flex`: Centraliza o conteúdo usando flexbox

```css
.mdfe-inc-box { ... max-width:520px; width:95%; ... }
```
- Caixa branca central com bordas arredondadas e sombra

#### Estrutura HTML da Modal:
- **Header**: Título "Incluir NF-e na MDF-e" + botão X para fechar
- **Body**: Formulário com os campos:
  - Seção "Município de Carregamento": UF (select) + Município (select dinâmico)
  - Seção "Município de Descarga": UF (select) + Município (select dinâmico)
  - Seção "Documento Fiscal": Input para chave NF-e + contador de caracteres
- **Footer**: Botões "Fechar" e "Incluir NF-e"

### Funções JavaScript

#### openIncluirDfeModal(id)
```javascript
function openIncluirDfeModal(id) {
    _mdfeIncluirDfeId = id;  // Guarda o ID da MDF-e
    // ... reseta todos os campos da modal ...
    overlay.classList.add("visible");  // Mostra a modal
}
```
- **Quando é chamada**: Ao clicar em "Incluir NF-e" no dropdown
- **O que faz**: Limpa os campos, guarda o ID e mostra a modal

#### closeIncluirDfeModal()
```javascript
function closeIncluirDfeModal() {
    overlay.classList.remove("visible");  // Esconde a modal
    _mdfeIncluirDfeId = null;
}
```

#### incDfeCarregarCidades(tipo)
```javascript
function incDfeCarregarCidades(tipo) {
    // tipo = "carrega" ou "descarga"
    // 1. Pega a UF selecionada
    // 2. Verifica cache (se já buscou esta UF, não busca de novo)
    // 3. Faz AJAX para buscar cidades
    // 4. Preenche o select de município
}
```
- **Quando é chamada**: Quando o usuário seleciona uma UF
- `_incDfeCidadesCache`: Objeto que guarda as cidades já buscadas. Se o usuário selecionar "ES", busca uma vez. Se trocar e voltar para "ES", usa o cache

#### incDfePreencherMunicipios(selectEl, cidades)
```javascript
function incDfePreencherMunicipios(selectEl, cidades) {
    // Cria as <option> do select com as cidades recebidas
}
```
- **O que faz**: Recebe a lista de cidades e monta as opções do select

#### confirmarIncluirDfe()
```javascript
function confirmarIncluirDfe() {
    // 1. Valida se todos os campos estão preenchidos
    // 2. Valida se a chave tem 44 dígitos numéricos
    // 3. Desabilita o botão (evita clique duplo)
    // 4. Envia POST para mdfe_incluir_dfe.php
    // 5. Se sucesso: mostra mensagem verde e recarrega a página
    // 6. Se erro: mostra mensagem vermelha e reativa o botão
}
```

### Botão no Dropdown

```php
if ($status === 'autorizada') {
    print '<a href="#" onclick="openIncluirDfeModal('.(int)$obj->id.'); return false;">Incluir NF-e</a>';
    print '<a href="#" onclick="openEncerrarModal(...)">Encerrar</a>';
    print '<a href="#" onclick="openCancelarModal(...)">Cancelar</a>';
}
```
- **O que faz**: Só mostra o botão "Incluir NF-e" quando a MDF-e está autorizada
- `(int)$obj->id`: Converte para inteiro (segurança)
- `return false`: Impede que o link navegue para outra página

---

## Banco de Dados

### Tabela: mdfe_eventos

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| `id` | INT (auto) | Identificador único do evento |
| `fk_mdfe_emitida` | INT | Referência à MDF-e na tabela `mdfe_emitidas` |
| `tpEvento` | VARCHAR(6) | Código do tipo de evento (ex: "110115" = Inclusão DF-e) |
| `nSeqEvento` | INT | Número sequencial do evento (1, 2, 3, ...) |
| `protocolo_evento` | VARCHAR(255) | Protocolo retornado pela SEFAZ |
| `motivo_evento` | TEXT | Motivo/mensagem retornada pela SEFAZ |
| `data_evento` | DATETIME | Data e hora do registro do evento |
| `xml_requisicao` | LONGTEXT | XML enviado à SEFAZ |
| `xml_resposta` | LONGTEXT | XML recebido da SEFAZ |
| `xml_evento_completo` | LONGTEXT | XML completo do evento |

### Tabela: estados_municipios_ibge

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| `codigo_ibge` | VARCHAR | Código IBGE do município (7 dígitos) |
| `nome` | VARCHAR | Nome do município |
| `uf` | CHAR(2) | Sigla da UF |
| `active` | TINYINT | 1 = ativo, 0 = inativo |

---

## Como Testar

1. Acesse a lista de MDF-e emitidas
2. Encontre uma MDF-e com status **"Autorizada"**
3. Clique no botão **"Ações"** → **"Incluir NF-e"**
4. Selecione a UF de carregamento → as cidades serão carregadas automaticamente
5. Selecione o município de carregamento
6. Selecione a UF de descarga → as cidades serão carregadas automaticamente
7. Selecione o município de descarga
8. Digite a chave da NF-e (44 dígitos)
9. Clique em **"Incluir NF-e"**
10. Se tudo estiver correto, aparecerá uma mensagem verde de sucesso

### Possíveis erros:
- **"Chave da NF-e deve ter 44 dígitos"**: A chave digitada tem tamanho incorreto
- **"Só é possível incluir DF-e em MDF-e autorizada"**: O status da MDF-e não é "autorizada"
- **"SEFAZ recusou a inclusão"**: A SEFAZ rejeitou o evento (chave inválida, MDF-e já encerrada, etc.)
- **"Certificado PFX não encontrado"**: O certificado digital não está configurado

---

## Glossário

| Termo | Significado |
|-------|-------------|
| **MDF-e** | Manifesto Eletrônico de Documentos Fiscais — documento que agrupa NF-e ou CT-e para transporte |
| **NF-e** | Nota Fiscal Eletrônica — documento fiscal de venda |
| **CT-e** | Conhecimento de Transporte Eletrônico — documento fiscal de frete |
| **DF-e** | Documento Fiscal Eletrônico — termo genérico para NF-e, CT-e, etc. |
| **SEFAZ** | Secretaria da Fazenda — órgão do governo que autoriza documentos fiscais |
| **IBGE** | Instituto Brasileiro de Geografia e Estatística — cada município tem um código IBGE |
| **tpEvento 110115** | Código do evento "Inclusão de DF-e" |
| **cStat 135** | Código de status da SEFAZ que significa "evento registrado e vinculado" |
| **nSeqEvento** | Número sequencial — cada evento de uma MDF-e recebe um número crescente |
| **Certificado A1** | Certificado digital em formato PFX usado para assinar documentos fiscais |
| **AJAX** | Técnica para enviar dados ao servidor sem recarregar a página |
| **JSON** | Formato de dados usado na comunicação entre JavaScript e PHP |
| **Modal** | Janela que aparece sobre a página, geralmente com formulário |
| **Overlay** | Camada escura semi-transparente atrás da modal |
