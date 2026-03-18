# 🎓 Curso Completo: Desenvolvimento no Dolibarr com PHP

> **Do Zero ao Avançado — Criando Telas, Acessando Dados, Hooks e Módulos**  
> **Nível:** Do iniciante absoluto ao avançado  
> **Pré-requisitos:** PHP básico (variáveis, funções, arrays, classes), SQL básico, HTML/CSS mínimo

---

## Sumário

### Parte 1 — Fundamentos
- [Capítulo 1: O que é o Dolibarr e Como Ele Funciona](#capítulo-1-o-que-é-o-dolibarr-e-como-ele-funciona)
- [Capítulo 2: Arquitetura de Arquivos e Diretórios](#capítulo-2-arquitetura-de-arquivos-e-diretórios)
- [Capítulo 3: O Ciclo de Vida de Uma Página](#capítulo-3-o-ciclo-de-vida-de-uma-página)
- [Capítulo 4: Acessando o Banco de Dados (`$db`)](#capítulo-4-acessando-o-banco-de-dados-db)

### Parte 2 — Criando Telas
- [Capítulo 5: Sua Primeira Página no Dolibarr](#capítulo-5-sua-primeira-página-no-dolibarr)
- [Capítulo 6: Tabelas, Filtros e Paginação](#capítulo-6-tabelas-filtros-e-paginação)
- [Capítulo 7: Formulários e Ações (CRUD)](#capítulo-7-formulários-e-ações-crud)
- [Capítulo 8: Trabalhando com AJAX](#capítulo-8-trabalhando-com-ajax)

### Parte 3 — Módulos
- [Capítulo 9: Criando um Módulo Custom do Zero](#capítulo-9-criando-um-módulo-custom-do-zero)
- [Capítulo 10: Classes de Dados (Modelo)](#capítulo-10-classes-de-dados-modelo)
- [Capítulo 11: Hooks — Interceptando Páginas Sem Alterar o Core](#capítulo-11-hooks--interceptando-páginas-sem-alterar-o-core)
- [Capítulo 12: Triggers — Reagindo a Eventos de Negócio](#capítulo-12-triggers--reagindo-a-eventos-de-negócio)

### Parte 4 — Exercícios
- [Exercícios Nível Básico (1-5)](#exercícios-nível-básico)
- [Exercícios Nível Intermediário (6-10)](#exercícios-nível-intermediário)
- [Exercícios Nível Avançado (11-15)](#exercícios-nível-avançado)
- [Respostas Completas com Arquivos Funcionais](#respostas-completas)

---

# PARTE 1 — FUNDAMENTOS

---

## Capítulo 1: O que é o Dolibarr e Como Ele Funciona

### 1.1 Visão Geral

O Dolibarr é um ERP/CRM open-source escrito em PHP. Ele gerencia:
- Clientes/Fornecedores (terceiros)
- Produtos/Serviços
- Pedidos, Faturas, Propostas
- Estoque, Expedições
- RH, Projetos, Tickets
- E muito mais via módulos

**Conceito fundamental:** Tudo no Dolibarr é um **módulo** que pode ser ativado ou desativado. Até funcionalidades core (como Faturas) são módulos.

### 1.2 Objetos Globais — Os 5 Pilares

Quando você carrega `main.inc.php`, o Dolibarr cria estes objetos globais:

```php
require '../main.inc.php';

// Agora você tem acesso a:
/** @var DoliDB  $db   */  // Conexão com banco de dados
/** @var Conf    $conf */  // Todas as configurações do sistema
/** @var User    $user */  // Usuário logado
/** @var Translate $langs */  // Sistema de tradução
/** @var HookManager $hookmanager */  // Gerenciador de hooks
```

| Objeto | Para que serve | Exemplo de uso |
|---|---|---|
| `$db` | Executar SQL | `$db->query("SELECT * FROM llx_societe")` |
| `$conf` | Acessar configurações | `$conf->global->MAIN_LANG_DEFAULT` |
| `$user` | Saber quem está logado | `$user->login`, `$user->admin` |
| `$langs` | Traduzir textos | `$langs->trans("Save")` retorna "Salvar" |
| `$hookmanager` | Executar hooks | `$hookmanager->executeHooks(...)` |

### 1.3 A Variável `$conf` em Detalhe

```php
// Informações do Dolibarr
$conf->global->MAIN_VERSION_LAST_INSTALL;  // Versão instalada

// Caminhos
DOL_DOCUMENT_ROOT    // Ex: C:/xampp/htdocs/dolibarr/htdocs
DOL_URL_ROOT         // Ex: /dolibarr/htdocs
DOL_DATA_ROOT        // Ex: C:/xampp/htdocs/dolibarr/documents

// Banco de dados  
MAIN_DB_PREFIX       // Ex: "llx_" — prefixo de TODAS as tabelas

// Verificar se um módulo está ativo
isModEnabled('facture')    // true se módulo de faturas está ativo
$conf->societe->enabled    // true se terceiros está ativo

// Constantes customizadas (set via admin > const.php)
$conf->global->MEU_CONFIG_QUALQUER;
```

### 1.4 A Função `GETPOST()` — Nunca Use `$_GET/$_POST` Direto

```php
// ❌ ERRADO (vulnerável a XSS, SQL Injection, etc.)
$nome = $_POST['nome'];

// ✅ CORRETO — GETPOST sanitiza automaticamente
$nome = GETPOST('nome', 'alpha');       // Apenas letras, números, espaços
$id   = GETPOST('id', 'int');           // Apenas inteiros
$html = GETPOST('descricao', 'restricthtml');  // HTML seguro
$data = GETPOST('data_ini', 'alpha');   // Para datas como texto
$num  = GETPOSTINT('quantidade');       // Atalho para (int) GETPOST(..., 'int')
```

**Tipos de sanitização disponíveis:**

| Tipo | Permite | Exemplo |
|---|---|---|
| `'int'` | Apenas dígitos | ID, quantidade |
| `'alpha'` | Letras, números, poucos especiais | Nomes, códigos |
| `'alphanohtml'` | Como alpha, sem HTML | Campos texto comuns |
| `'restricthtml'` | HTML parcial (sem script) | Descrições ricas |
| `'aZ09'` | a-z, A-Z, 0-9 | Identificadores |
| `'none'` | Sem sanitização (CUIDADO!) | Apenas em casos especiais |

---

## Capítulo 2: Arquitetura de Arquivos e Diretórios

### 2.1 Estrutura de Diretórios Principal

```
htdocs/
├── conf/
│   └── conf.php                ← Configuração de banco, caminhos, etc.
│
├── core/
│   ├── class/                  ← Classes utilitárias (Form, HTML, etc.)
│   ├── modules/                ← Descritores de módulos core
│   ├── triggers/               ← Triggers do sistema
│   └── lib/                    ← Bibliotecas de funções
│
├── custom/                     ← ⭐ SEUS MÓDULOS VÃO AQUI
│   └── meuprojeto/
│       ├── core/
│       │   ├── modules/        ← modMeuProjeto.class.php
│       │   └── triggers/       ← Triggers do seu módulo
│       ├── class/              ← Classes de dados + hooks
│       ├── sql/                ← Scripts SQL de instalação
│       ├── langs/              ← Traduções
│       ├── lib/                ← Funções auxiliares
│       ├── css/                ← Estilos customizados
│       ├── js/                 ← JavaScript
│       └── tpl/                ← Templates
│
├── societe/                    ← Páginas do módulo Terceiros
├── compta/                     ← Páginas do módulo Contabilidade
├── commande/                   ← Páginas do módulo Pedidos
└── ...
```

### 2.2 A Pasta `custom/` — Seu Espaço Sagrado

**REGRA DE OURO:** Nunca altere arquivos fora de `custom/`. Ao atualizar o Dolibarr, os arquivos core são sobrescritos. Tudo no `custom/` permanece intacto.

```
custom/
└── meumodulo/
    ├── core/
    │   └── modules/
    │       └── modMeuModulo.class.php   ← Descritor (obrigatório)
    ├── class/
    │   ├── meuobjeto.class.php          ← Modelo de dados
    │   └── actions_meumodulo.class.php  ← Hooks
    ├── sql/
    │   ├── llx_meumodulo_tabela.sql     ← CREATE TABLE
    │   └── llx_meumodulo_tabela.key.sql ← CREATE INDEX
    ├── langs/
    │   ├── pt_BR/
    │   │   └── meumodulo.lang           ← Traduções PT-BR
    │   └── en_US/
    │       └── meumodulo.lang           ← Traduções EN
    ├── index.php                        ← Página inicial
    ├── lista.php                        ← Página de listagem
    └── card.php                         ← Ficha do registro
```

### 2.3 O Arquivo `conf.php`

Localizado em `htdocs/conf/conf.php`, é gerado pelo instalador:

```php
<?php
$dolibarr_main_url_root = 'http://localhost/dolibarr/htdocs';
$dolibarr_main_document_root = 'C:/xampp/htdocs/dolibarr/htdocs';
$dolibarr_main_url_root_alt = '/custom';    // ← Caminho para custom/
$dolibarr_main_document_root_alt = 'C:/xampp/htdocs/dolibarr/htdocs/custom';
$dolibarr_main_data_root = 'C:/xampp/htdocs/dolibarr/documents';

// Banco de dados
$dolibarr_main_db_host = 'localhost';
$dolibarr_main_db_port = '3306';
$dolibarr_main_db_name = 'dolibarr';
$dolibarr_main_db_prefix = 'llx_';          // ← MAIN_DB_PREFIX vem daqui
$dolibarr_main_db_user = 'root';
$dolibarr_main_db_pass = '';
$dolibarr_main_db_type = 'mysqli';
```

---

## Capítulo 3: O Ciclo de Vida de Uma Página

**TODA** página Dolibarr segue este ciclo:

```php
<?php
// ═══════════ FASE 1: INICIALIZAÇÃO ═══════════
require '../main.inc.php';                    // Carrega framework
require_once DOL_DOCUMENT_ROOT.'/meumodulo/class/meuobjeto.class.php';

$langs->loadLangs(array('meumodulo'));        // Carrega traduções
$id = GETPOSTINT('id');                       // Lê parâmetros
$action = GETPOST('action', 'alpha');

$object = new MeuObjeto($db);                // Instancia objeto
if ($id > 0) $object->fetch($id);            // Carrega do banco

// Verifica se usuário tem permissão
$permissiontoread = $user->hasRight('meumodulo', 'read');
if (!$permissiontoread) accessforbidden();

// ═══════════ FASE 2: PROCESSAR AÇÕES (POST) ═══════════
if ($action == 'create' && $user->hasRight('meumodulo', 'write')) {
    $object->nome = GETPOST('nome', 'alpha');
    $result = $object->create($user);
    if ($result > 0) {
        header("Location: card.php?id=".$object->id);
        exit;
    } else {
        setEventMessages($object->error, $object->errors, 'errors');
    }
}

// ═══════════ FASE 3: RENDERIZAR HTML ═══════════
llxHeader('', 'Título');                      // Abre HTML + menus

print load_fiche_titre('Minha Página');       // Título grande

// ... conteúdo da página ...

llxFooter();                                  // Fecha HTML
$db->close();                                 // Fecha conexão
```

### 3.1 Diagrama Visual

```
┌────────────────────────────────────────────┐
│         Requisição HTTP (GET ou POST)       │
└────────────────────┬───────────────────────┘
                     │
                     ▼
┌────────────────────────────────────────────┐
│  require 'main.inc.php'                    │
│  → Lê conf.php                             │
│  → Conecta banco de dados → $db            │
│  → Identifica usuário → $user              │
│  → Carrega configurações → $conf           │
│  → Carrega traduções → $langs              │
│  → Inicializa $hookmanager                 │
└────────────────────┬───────────────────────┘
                     │
                     ▼
┌────────────────────────────────────────────┐
│  Lê parâmetros: GETPOST('action')          │
│  Carrega objeto do banco se necessário     │
│  Verifica permissões                       │
└────────────────────┬───────────────────────┘
                     │
          ┌──────────┴──────────┐
          │ action != '' ?      │
          └──────────┬──────────┘
         SIM         │         NÃO
          │          │          │
          ▼          │          │
┌─────────────────┐  │          │
│ Processa ação:  │  │          │
│ create/update/  │  │          │
│ delete...       │  │          │
│ redirect se OK  │  │          │
└─────────┬───────┘  │          │
          │          │          │
          └──────────┴──────────┘
                     │
                     ▼
┌────────────────────────────────────────────┐
│  llxHeader()   → <html>, <head>, menus     │
│  conteúdo      → tabelas, formulários      │
│  llxFooter()   → fecha tags, JS            │
└────────────────────────────────────────────┘
```

---

## Capítulo 4: Acessando o Banco de Dados (`$db`)

### 4.1 Consultas Básicas (SELECT)

```php
// 1. Montar SQL — SEMPRE use MAIN_DB_PREFIX
$sql = "SELECT rowid, nom, email 
        FROM " . MAIN_DB_PREFIX . "societe 
        WHERE status = 1 
        ORDER BY nom ASC";

// 2. Executar
$resql = $db->query($sql);

// 3. Verificar erro
if (!$resql) {
    dol_print_error($db);  // Mostra erro bonito do Dolibarr
    exit;
}

// 4. Iterar resultados
$num = $db->num_rows($resql);  // Quantas linhas retornaram
$i = 0;
while ($i < $num) {
    $obj = $db->fetch_object($resql);  // Próxima linha como objeto
    
    echo $obj->rowid;   // 1
    echo $obj->nom;     // "Empresa XYZ"
    echo $obj->email;   // "contato@xyz.com"
    
    $i++;
}

// 5. Liberar memória (opcional mas bom)
$db->free($resql);
```

### 4.2 Inserções (INSERT)

```php
// SEMPRE escape strings para prevenir SQL Injection!
$nome = $db->escape('O\'Brien & Cia Ltda');  // Escapa aspas e '&'
$email = $db->escape('obrien@email.com');

$sql = "INSERT INTO " . MAIN_DB_PREFIX . "minha_tabela 
        (nome, email, created_at) 
        VALUES 
        ('" . $nome . "', '" . $email . "', NOW())";

$result = $db->query($sql);
if ($result) {
    $newId = $db->last_insert_id(MAIN_DB_PREFIX . "minha_tabela");
    echo "Inserido com ID: " . $newId;
} else {
    echo "Erro: " . $db->lasterror();
}
```

### 4.3 Atualizações e Exclusões

```php
// UPDATE
$sql = "UPDATE " . MAIN_DB_PREFIX . "minha_tabela 
        SET nome = '" . $db->escape($novoNome) . "',
            atualizado_em = NOW()
        WHERE rowid = " . (int) $id;
$db->query($sql);

// DELETE
$sql = "DELETE FROM " . MAIN_DB_PREFIX . "minha_tabela 
        WHERE rowid = " . (int) $id;
$db->query($sql);
```

### 4.4 Transações

```php
$db->begin();  // Inicia transação

try {
    $db->query("INSERT INTO ...");
    $db->query("UPDATE ...");
    $db->query("INSERT INTO ...");
    
    $db->commit();   // Confirma TUDO
} catch (Exception $e) {
    $db->rollback(); // Desfaz TUDO
}
```

### 4.5 Métodos Úteis do `$db`

| Método | O que faz |
|---|---|
| `$db->query($sql)` | Executa SQL |
| `$db->fetch_object($res)` | Próxima linha como objeto |
| `$db->fetch_array($res)` | Próxima linha como array |
| `$db->num_rows($res)` | Quantidade de linhas |
| `$db->last_insert_id($table)` | ID do último INSERT |
| `$db->escape($str)` | Escapa string para SQL |
| `$db->lasterror()` | Último erro SQL |
| `$db->begin()` | Inicia transação |
| `$db->commit()` | Confirma transação |
| `$db->rollback()` | Desfaz transação |
| `$db->idate($timestamp)` | Converte timestamp para formato SQL |
| `$db->jdate($sqldate)` | Converte data SQL para timestamp |
| `$db->plimit($limit, $offset)` | Gera LIMIT/OFFSET |

---

# PARTE 2 — CRIANDO TELAS

---

## Capítulo 5: Sua Primeira Página no Dolibarr

### 5.1 Página Mínima Funcional

Crie o arquivo `custom/meuprojeto/index.php`:

```php
<?php
// Carrega o framework Dolibarr
// O caminho '../../main.inc.php' parte de custom/meuprojeto/ até htdocs/
require '../../main.inc.php';

// Verifica se o usuário está logado (main.inc.php já faz isso,
// mas podemos adicionar checagem extra de permissão)
if (!$user->id) {
    accessforbidden();
}

// Inicia a página HTML (inclui menus laterais, CSS do Dolibarr, etc.)
llxHeader('', 'Meu Projeto');

// Título grande da página
print load_fiche_titre('Bem-vindo ao Meu Projeto', '', 'object_generic');

// Conteúdo HTML livre
print '<div class="opacitymedium">';
print 'Esta é a minha primeira página no Dolibarr!<br>';
print 'Usuário logado: <strong>' . dol_escape_htmltag($user->login) . '</strong><br>';
print 'Data atual: <strong>' . dol_print_date(dol_now(), 'dayhour') . '</strong>';
print '</div>';

// Fecha a página HTML
llxFooter();
$db->close();
```

**Para acessar:** `http://localhost/dolibarr/htdocs/custom/meuprojeto/index.php`

### 5.2 Funções de Exibição Essenciais

```php
// Título grande
print load_fiche_titre('Título', 'Texto à direita', 'nome-do-icone');

// Escapar texto para HTML (previne XSS)
dol_escape_htmltag($texto);

// Formatar data
dol_print_date($timestamp, 'day');       // 25/02/2026
dol_print_date($timestamp, 'dayhour');   // 25/02/2026 14:30
dol_print_date($timestamp, '%Y-%m-%d');  // 2026-02-25

// Formatar preço
price($valor);                  // 1.234,56 (usa configuração regional)
price($valor, 0, $langs, 1, -1, -1, 'BRL');  // R$ 1.234,56

// Timestamp atual
dol_now();  // Equivale a time() mas respeitando timezone

// Construir URL para módulo custom
dol_buildpath('/custom/meuprojeto/card.php', 1);
// Retorna: /dolibarr/htdocs/custom/meuprojeto/card.php

// Alertas/Notificações
setEventMessages('Operação realizada!', null, 'mesgs');     // Sucesso (verde)
setEventMessages('Algo deu errado', null, 'errors');        // Erro (vermelho)
setEventMessages('Atenção!', null, 'warnings');             // Aviso (amarelo)
```

### 5.3 Formulários com Classes Dolibarr

O objeto `Form` oferece métodos para criar campos de formulário padronizados:

```php
$form = new Form($db);

// Select de terceiro (com autocomplete)
print $form->select_company($selectedId, 'fk_soc', '', 'SelectThirdParty', 0, 0, null, 0, 'minwidth300');

// Select de produto
print $form->select_produits($selectedId, 'fk_product', '', 0, 0, -1, 2, '', 0, array(), 0, '1', 0, 'minwidth300');

// Select de usuário
print $form->select_dolusers($selectedId, 'fk_user', 1, null, 0, '', '', 0, 0, 0, 'minwidth200');

// Selector de data
print $form->selectDate($timestamp, 'dataini', 0, 0, 0, '', 1, 1);

// Select genérico
print $form->selectarray('meu_campo', array('op1'=>'Opção 1', 'op2'=>'Opção 2'), $selectedValue);
```

---

## Capítulo 6: Tabelas, Filtros e Paginação

### 6.1 Montando uma Tabela no Padrão Dolibarr

```php
<?php
require '../../main.inc.php';

llxHeader('', 'Lista de Registros');
print load_fiche_titre('Meus Registros');

// Parâmetros de paginação
$page = max(0, GETPOSTINT('page'));
$sortfield = GETPOST('sortfield', 'alpha') ?: 'rowid';
$sortorder = GETPOST('sortorder', 'alpha') ?: 'DESC';
$limit = $conf->liste_limit ?: 25;
$offset = $limit * $page;

// Filtros
$search_nome = GETPOST('search_nome', 'alpha');

// Montar SQL
$sql = "SELECT rowid, nome, email, date_creation 
        FROM " . MAIN_DB_PREFIX . "minha_tabela 
        WHERE 1=1";

// Aplicar filtro
if (!empty($search_nome)) {
    $sql .= " AND nome LIKE '%" . $db->escape($search_nome) . "%'";
}

// Contagem total (para paginação)
$sqlCount = preg_replace('/^SELECT .* FROM/', 'SELECT COUNT(*) as total FROM', $sql);
$resCount = $db->query($sqlCount);
$totalRows = $resCount ? (int) $db->fetch_object($resCount)->total : 0;

// Ordenação + Paginação
$sql .= " ORDER BY " . $sortfield . " " . ($sortorder === 'ASC' ? 'ASC' : 'DESC');
$sql .= $db->plimit($limit, $offset);

$resql = $db->query($sql);
if (!$resql) { dol_print_error($db); exit; }
$num = $db->num_rows($resql);

// Barra de paginação nativa do Dolibarr
print_barre_liste(
    'Meus Registros',           // Título
    $page,                      // Página atual
    $_SERVER["PHP_SELF"],       // URL
    '',                         // Params extras
    $sortfield,                 // Campo de ordenação
    $sortorder,                 // Direção
    '',                         // Texto à esquerda
    $num,                       // Linhas nesta página
    $totalRows,                 // Total de linhas
    'object_generic',           // Ícone
    0,                          // Seleção em lote?
    '',                         // Botão novo
    '',                         // Hide barre
    $limit                      // Registros por página
);

// Formulário com filtros
print '<form method="GET" action="' . $_SERVER["PHP_SELF"] . '">';
print '<table class="liste noborder centpercent">';

// Cabeçalho — usa print_liste_field_titre para ordenação automática
print '<tr class="liste_titre">';
print_liste_field_titre('ID', $_SERVER["PHP_SELF"], "rowid", "", "", '', $sortfield, $sortorder);
print_liste_field_titre('Nome', $_SERVER["PHP_SELF"], "nome", "", "", '', $sortfield, $sortorder);
print_liste_field_titre('Email', $_SERVER["PHP_SELF"], "email", "", "", '', $sortfield, $sortorder);
print_liste_field_titre('Data', $_SERVER["PHP_SELF"], "date_creation", "", "", 'align="center"', $sortfield, $sortorder);
print "</tr>";

// Linha de filtros
print '<tr class="liste_titre_filter">';
print '<td></td>';  // ID sem filtro
print '<td><input type="text" name="search_nome" value="' . dol_escape_htmltag($search_nome) . '" class="flat" size="20"></td>';
print '<td></td>';  // Email sem filtro
print '<td class="center"><input type="submit" class="butAction" value="Buscar"></td>';
print '</tr>';

// Linhas de dados
$i = 0;
while ($i < $num) {
    $obj = $db->fetch_object($resql);
    
    print '<tr class="oddeven">';
    print '<td>' . (int) $obj->rowid . '</td>';
    print '<td>' . dol_escape_htmltag($obj->nome) . '</td>';
    print '<td>' . dol_escape_htmltag($obj->email) . '</td>';
    print '<td class="center">' . dol_print_date($db->jdate($obj->date_creation), 'day') . '</td>';
    print '</tr>';
    
    $i++;
}

if ($num == 0) {
    print '<tr><td colspan="4" class="opacitymedium center">Nenhum registro encontrado.</td></tr>';
}

print '</table>';
print '</form>';

llxFooter();
$db->close();
```

### 6.2 Explicação dos Componentes

**`print_liste_field_titre()`** — Cria cabeçalho clicável para ordenação:
```php
print_liste_field_titre(
    'Título',              // Texto exibido
    $_SERVER["PHP_SELF"],  // URL para redirecionar
    "campo_sql",           // Campo para ORDER BY
    "",                    // Params extras na URL
    "",                    // Mais params
    'align="center"',      // Atributos HTML do <th>
    $sortfield,            // Campo atual de ordenação
    $sortorder             // Direção atual (ASC/DESC)
);
```
Resultado: Ao clicar no cabeçalho, a página recarrega com `?sortfield=campo_sql&sortorder=ASC`.

**`print_barre_liste()`** — Barra completa com info de paginação e botões:
- Mostra "Registros 1-25 de 150"
- Setas para próxima/anterior página
- Título com ícone

**Classes CSS nativas:**
- `liste` — Estilo base da tabela
- `noborder` — Sem bordas duplas
- `centpercent` — Largura 100%
- `liste_titre` — Linha de cabeçalho
- `liste_titre_filter` — Linha de filtros
- `oddeven` — Alterna cores das linhas (zebrado)
- `opacitymedium` — Texto mais claro (para "sem resultados")

---

## Capítulo 7: Formulários e Ações (CRUD)

### 7.1 Padrão Completo: Ficha (Card) com Create/Edit/View/Delete

```php
<?php
require '../../main.inc.php';

$langs->load('meuprojeto');

$id = GETPOSTINT('id');
$action = GETPOST('action', 'alpha');

// ═══════════════ AÇÕES ═══════════════
// Criar
if ($action == 'add') {
    $nome = GETPOST('nome', 'alpha');
    $email = GETPOST('email', 'alpha');
    
    if (empty($nome)) {
        setEventMessages('O nome é obrigatório', null, 'errors');
    } else {
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "minha_tabela 
                (nome, email, date_creation) VALUES 
                ('" . $db->escape($nome) . "', '" . $db->escape($email) . "', NOW())";
        if ($db->query($sql)) {
            $newId = $db->last_insert_id(MAIN_DB_PREFIX . "minha_tabela");
            setEventMessages('Registro criado!', null, 'mesgs');
            header("Location: " . $_SERVER['PHP_SELF'] . "?id=" . $newId);
            exit;
        } else {
            setEventMessages('Erro: ' . $db->lasterror(), null, 'errors');
        }
    }
}

// Atualizar
if ($action == 'update' && $id > 0) {
    $nome = GETPOST('nome', 'alpha');
    $email = GETPOST('email', 'alpha');
    
    $sql = "UPDATE " . MAIN_DB_PREFIX . "minha_tabela SET 
            nome = '" . $db->escape($nome) . "',
            email = '" . $db->escape($email) . "'
            WHERE rowid = " . (int) $id;
    
    if ($db->query($sql)) {
        setEventMessages('Registro atualizado!', null, 'mesgs');
        header("Location: " . $_SERVER['PHP_SELF'] . "?id=" . $id);
        exit;
    }
}

// Excluir
if ($action == 'confirm_delete' && $id > 0) {
    $sql = "DELETE FROM " . MAIN_DB_PREFIX . "minha_tabela WHERE rowid = " . (int) $id;
    if ($db->query($sql)) {
        setEventMessages('Registro excluído!', null, 'mesgs');
        header("Location: lista.php");
        exit;
    }
}

// Carregar dados do registro (se editando/visualizando)
$obj = null;
if ($id > 0) {
    $sql = "SELECT * FROM " . MAIN_DB_PREFIX . "minha_tabela WHERE rowid = " . (int) $id;
    $resql = $db->query($sql);
    if ($resql && $db->num_rows($resql) > 0) {
        $obj = $db->fetch_object($resql);
    }
}

// ═══════════════ EXIBIÇÃO ═══════════════
llxHeader('', 'Ficha do Registro');

$form = new Form($db);

// ── Modo CRIAÇÃO ──
if ($action == 'create') {
    print load_fiche_titre('Novo Registro');
    
    print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
    print '<input type="hidden" name="action" value="add">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    
    print '<table class="border centpercent tableforfieldcreate">';
    print '<tr>';
    print '<td class="fieldrequired titlefieldcreate">Nome</td>';
    print '<td><input type="text" name="nome" class="flat minwidth300" value="' . 
          dol_escape_htmltag(GETPOST('nome', 'alpha')) . '"></td>';
    print '</tr>';
    print '<tr>';
    print '<td>Email</td>';
    print '<td><input type="email" name="email" class="flat minwidth300" value="' . 
          dol_escape_htmltag(GETPOST('email', 'alpha')) . '"></td>';
    print '</tr>';
    print '</table>';
    
    print '<div class="center">';
    print '<input type="submit" class="butAction" value="Criar">';
    print ' <a class="butActionDelete" href="lista.php">Cancelar</a>';
    print '</div>';
    print '</form>';
}

// ── Modo EDIÇÃO ──
elseif ($action == 'edit' && $obj) {
    print load_fiche_titre('Editar Registro #' . $obj->rowid);
    
    print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
    print '<input type="hidden" name="action" value="update">';
    print '<input type="hidden" name="id" value="' . $obj->rowid . '">';
    print '<input type="hidden" name="token" value="' . newToken() . '">';
    
    print '<table class="border centpercent">';
    print '<tr><td class="fieldrequired">Nome</td>';
    print '<td><input type="text" name="nome" class="flat minwidth300" value="' . 
          dol_escape_htmltag($obj->nome) . '"></td></tr>';
    print '<tr><td>Email</td>';
    print '<td><input type="email" name="email" class="flat minwidth300" value="' . 
          dol_escape_htmltag($obj->email) . '"></td></tr>';
    print '</table>';
    
    print '<div class="center">';
    print '<input type="submit" class="butAction" value="Salvar">';
    print ' <a class="butActionDelete" href="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '">Cancelar</a>';
    print '</div>';
    print '</form>';
}

// ── Modo VISUALIZAÇÃO ──
elseif ($obj) {
    print load_fiche_titre('Registro #' . $obj->rowid);
    
    // Confirmação de exclusão
    if ($action == 'delete') {
        print $form->formconfirm(
            $_SERVER["PHP_SELF"] . '?id=' . $obj->rowid,
            'Confirmar Exclusão',
            'Deseja realmente excluir este registro?',
            'confirm_delete',
            '',
            0,
            1
        );
    }
    
    print '<table class="border centpercent">';
    print '<tr><td class="titlefield">ID</td><td>' . $obj->rowid . '</td></tr>';
    print '<tr><td>Nome</td><td>' . dol_escape_htmltag($obj->nome) . '</td></tr>';
    print '<tr><td>Email</td><td>' . dol_escape_htmltag($obj->email) . '</td></tr>';
    print '<tr><td>Data Criação</td><td>' . dol_print_date($db->jdate($obj->date_creation), 'dayhour') . '</td></tr>';
    print '</table>';
    
    // Botões de ação
    print '<div class="tabsAction">';
    print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '&action=edit">Editar</a>';
    print '<a class="butActionDelete" href="' . $_SERVER['PHP_SELF'] . '?id=' . $id . '&action=delete">Excluir</a>';
    print '</div>';
}

// ── Sem registro e sem ação ──
else {
    print '<a class="butAction" href="' . $_SERVER['PHP_SELF'] . '?action=create">Novo Registro</a>';
}

llxFooter();
$db->close();
```

### 7.2 Classes CSS para Formulários

| Classe | Onde usar | Efeito |
|---|---|---|
| `border` | `<table>` | Bordas na tabela de campos |
| `centpercent` | `<table>` | Largura 100% |
| `titlefield` | `<td>` do label | Largura padrão para labels |
| `fieldrequired` | `<td>` do label | Negrito (indica obrigatório) |
| `flat` | `<input>` | Estilo padrão Dolibarr |
| `minwidth300` | `<input>` | Largura mínima 300px |
| `butAction` | `<a>` ou `<button>` | Botão azul (ação principal) |
| `butActionDelete` | `<a>` ou `<button>` | Botão vermelho/cinza |
| `tabsAction` | `<div>` | Container de botões de ação |
| `oddeven` | `<tr>` | Zebrado em tabelas |

---

## Capítulo 8: Trabalhando com AJAX

### 8.1 Padrão Completo de AJAX no Dolibarr

**Página PHP (endpoint AJAX):** `custom/meuprojeto/ajax_handler.php`

```php
<?php
require '../../main.inc.php';

// Verifica se é AJAX
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) 
    || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    exit('Acesso negado.');
}

header('Content-Type: application/json; charset=UTF-8');

$action = GETPOST('action', 'alpha');

if ($action === 'buscar_dados') {
    $termo = GETPOST('termo', 'alpha');
    
    $sql = "SELECT rowid, nome FROM " . MAIN_DB_PREFIX . "minha_tabela 
            WHERE nome LIKE '%" . $db->escape($termo) . "%' 
            LIMIT 20";
    $res = $db->query($sql);
    
    $resultados = [];
    while ($obj = $db->fetch_object($res)) {
        $resultados[] = [
            'id' => (int) $obj->rowid,
            'nome' => $obj->nome,
        ];
    }
    
    echo json_encode(['success' => true, 'data' => $resultados], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'salvar') {
    $id = GETPOSTINT('id');
    $nome = GETPOST('nome', 'alpha');
    
    if (empty($nome)) {
        echo json_encode(['success' => false, 'error' => 'Nome obrigatório']);
        exit;
    }
    
    $sql = "UPDATE " . MAIN_DB_PREFIX . "minha_tabela 
            SET nome = '" . $db->escape($nome) . "' 
            WHERE rowid = " . (int) $id;
    
    if ($db->query($sql)) {
        echo json_encode(['success' => true, 'message' => 'Salvo!']);
    } else {
        echo json_encode(['success' => false, 'error' => $db->lasterror()]);
    }
    exit;
}

echo json_encode(['success' => false, 'error' => 'Ação não reconhecida']);
```

**JavaScript na página:**

```javascript
// GET simples
function buscarDados(termo) {
    fetch("ajax_handler.php?action=buscar_dados&termo=" + encodeURIComponent(termo), {
        headers: { "X-Requested-With": "XMLHttpRequest" },
        credentials: "same-origin"
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            console.log(data.data);  // Array de resultados
        } else {
            alert("Erro: " + data.error);
        }
    })
    .catch(function(err) {
        alert("Erro de comunicação: " + err.message);
    });
}

// POST com dados
function salvarDados(id, nome) {
    fetch("ajax_handler.php", {
        method: "POST",
        headers: {
            "X-Requested-With": "XMLHttpRequest",
            "Content-Type": "application/x-www-form-urlencoded"
        },
        credentials: "same-origin",
        body: "action=salvar&id=" + encodeURIComponent(id) 
            + "&nome=" + encodeURIComponent(nome)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            alert(data.message);
            window.location.reload();
        } else {
            alert("Erro: " + data.error);
        }
    });
}
```

### 8.2 AJAX Inline (Handler no Mesmo Arquivo)

Você pode processar AJAX no mesmo arquivo da listagem, como é feito no `mdfe_list.php`:

```php
<?php
require '../../main.inc.php';

// ═══ AJAX HANDLERS (antes de qualquer HTML) ═══
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) 
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
    
    header('Content-Type: application/json; charset=UTF-8');
    
    if (GETPOST('action', 'alpha') === 'minha_acao') {
        // ... processar e retornar JSON ...
        echo json_encode(['success' => true]);
        exit;  // ← IMPORTANTE: sai antes do HTML
    }
}

// ═══ PÁGINA NORMAL (só chega aqui se NÃO for AJAX) ═══
llxHeader('', 'Página');
// ... HTML ...
llxFooter();
```

---

# PARTE 3 — MÓDULOS

---

## Capítulo 9: Criando um Módulo Custom do Zero

### 9.1 Estrutura Mínima de Um Módulo

```
custom/
└── meutarefas/
    ├── core/
    │   └── modules/
    │       └── modMeuTarefas.class.php   ← OBRIGATÓRIO
    ├── class/
    │   └── tarefa.class.php              ← Classe de dados
    ├── sql/
    │   ├── llx_meutarefas_tarefa.sql     ← CREATE TABLE
    │   └── llx_meutarefas_tarefa.key.sql ← Índices
    ├── langs/
    │   └── pt_BR/
    │       └── meutarefas.lang           ← Traduções
    ├── index.php                         ← Página inicial
    ├── tarefa_list.php                   ← Lista de tarefas
    └── tarefa_card.php                   ← Ficha da tarefa
```

### 9.2 O Descritor do Módulo

Arquivo: `custom/meutarefas/core/modules/modMeuTarefas.class.php`

```php
<?php
include_once DOL_DOCUMENT_ROOT . '/core/modules/DolibarrModules.class.php';

class modMeuTarefas extends DolibarrModules
{
    public function __construct($db)
    {
        $this->db = $db;
        
        // ID único (use > 100000 para módulos custom)
        $this->numero = 500001;
        
        // Metadados
        $this->rights_class = 'meutarefas';
        $this->family = "other";
        $this->module_position = '90';
        $this->name = preg_replace('/^mod/i', '', get_class($this));
        $this->description = "Gerenciador de Tarefas Custom";
        $this->descriptionlong = "Módulo para gerenciar tarefas com status e prioridade";
        $this->version = '1.0.0';
        $this->const_name = 'MAIN_MODULE_MEUTAREFAS';
        $this->picto = 'object_list';
        
        // Dependências (módulos que devem estar ativos)
        $this->depends = array();
        $this->requiredby = array();
        
        // Diretórios de dados
        $this->dirs = array('/meutarefas');
        
        // Configuração de hooks (se usar)
        $this->module_parts = array(
            'triggers' => 0,
            'hooks' => array(),
        );
        
        // Traduções
        $this->langfiles = array('meutarefas@meutarefas');
        
        // Tabelas SQL
        $this->tables = array('meutarefas_tarefa');
        
        // Constantes globais
        $this->const = array();
        
        // Permissões
        $this->rights = array();
        $r = 0;
        
        $r++;
        $this->rights[$r][0] = $this->numero + 1;  // 500002
        $this->rights[$r][1] = 'Ler tarefas';
        $this->rights[$r][3] = 1;  // Ativa por padrão
        $this->rights[$r][4] = 'read';
        
        $r++;
        $this->rights[$r][0] = $this->numero + 2;  // 500003
        $this->rights[$r][1] = 'Criar/editar tarefas';
        $this->rights[$r][3] = 0;  // Não ativa por padrão
        $this->rights[$r][4] = 'write';
        
        $r++;
        $this->rights[$r][0] = $this->numero + 3;  // 500004
        $this->rights[$r][1] = 'Excluir tarefas';
        $this->rights[$r][3] = 0;
        $this->rights[$r][4] = 'delete';
        
        // Menus
        $this->menu = array();
        $r = 0;
        
        // Menu principal (top)
        $this->menu[$r++] = array(
            'fk_menu'  => '',
            'type'     => 'top',
            'titre'    => 'MeuTarefas',
            'mainmenu' => 'meutarefas',
            'leftmenu' => '',
            'url'      => '/meutarefas/index.php',
            'langs'    => 'meutarefas@meutarefas',
            'position' => 1000 + $r,
            'enabled'  => 'isModEnabled("meutarefas")',
            'perms'    => '$user->hasRight("meutarefas", "read")',
            'target'   => '',
            'user'     => 2,  // 0=interno, 1=externo, 2=ambos
        );
        
        // Submenu: Lista
        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=meutarefas',
            'type'     => 'left',
            'titre'    => 'Lista de Tarefas',
            'mainmenu' => 'meutarefas',
            'leftmenu' => 'meutarefas_list',
            'url'      => '/meutarefas/tarefa_list.php',
            'langs'    => 'meutarefas@meutarefas',
            'position' => 1000 + $r,
            'enabled'  => 'isModEnabled("meutarefas")',
            'perms'    => '$user->hasRight("meutarefas", "read")',
            'target'   => '',
            'user'     => 2,
        );
        
        // Submenu: Nova Tarefa
        $this->menu[$r++] = array(
            'fk_menu'  => 'fk_mainmenu=meutarefas,fk_leftmenu=meutarefas_list',
            'type'     => 'left',
            'titre'    => 'Nova Tarefa',
            'mainmenu' => 'meutarefas',
            'leftmenu' => 'meutarefas_new',
            'url'      => '/meutarefas/tarefa_card.php?action=create',
            'langs'    => 'meutarefas@meutarefas',
            'position' => 1000 + $r,
            'enabled'  => 'isModEnabled("meutarefas")',
            'perms'    => '$user->hasRight("meutarefas", "write")',
            'target'   => '',
            'user'     => 2,
        );
    }
    
    /**
     * Executado ao ativar o módulo — cria as tabelas no banco
     */
    public function init($options = '')
    {
        $result = $this->_load_tables('/meutarefas/sql/');
        return $this->_init(array(), $options);
    }
    
    /**
     * Executado ao desativar o módulo
     */
    public function remove($options = '')
    {
        return $this->_remove(array(), $options);
    }
}
```

### 9.3 SQL de Instalação

Arquivo: `custom/meutarefas/sql/llx_meutarefas_tarefa.sql`

```sql
CREATE TABLE llx_meutarefas_tarefa (
    rowid        INTEGER AUTO_INCREMENT PRIMARY KEY,
    ref          VARCHAR(128) NOT NULL,
    label        VARCHAR(255) NOT NULL DEFAULT '',
    description  TEXT,
    fk_user      INTEGER DEFAULT NULL,
    priority     INTEGER DEFAULT 0,
    status       INTEGER DEFAULT 0,
    date_creation DATETIME,
    tms          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    fk_user_creat INTEGER,
    fk_user_modif INTEGER,
    entity       INTEGER DEFAULT 1
) ENGINE=InnoDB;
```

Arquivo: `custom/meutarefas/sql/llx_meutarefas_tarefa.key.sql`

```sql
ALTER TABLE llx_meutarefas_tarefa ADD INDEX idx_tarefa_ref (ref);
ALTER TABLE llx_meutarefas_tarefa ADD INDEX idx_tarefa_status (status);
ALTER TABLE llx_meutarefas_tarefa ADD INDEX idx_tarefa_entity (entity);
```

### 9.4 Ativando o Módulo

1. Vá em **Início → Configuração → Módulos/Aplicações**
2. Procure "MeuTarefas" na lista
3. Clique no botão de ativação

Ou via URL direta: `http://localhost/dolibarr/htdocs/admin/modules.php`

---

## Capítulo 10: Classes de Dados (Modelo)

### 10.1 Classe Usando Padrão Moderno ($fields)

Arquivo: `custom/meutarefas/class/tarefa.class.php`

```php
<?php
require_once DOL_DOCUMENT_ROOT . '/core/class/commonobject.class.php';

class Tarefa extends CommonObject
{
    public $module = 'meutarefas';
    public $element = 'tarefa';
    public $table_element = 'meutarefas_tarefa';
    public $picto = 'object_list';
    
    // Constantes de status
    const STATUS_DRAFT = 0;
    const STATUS_ACTIVE = 1;
    const STATUS_DONE = 2;
    const STATUS_CANCELED = 9;
    
    // Definição declarativa de TODOS os campos
    public $fields = array(
        'rowid' => array(
            'type' => 'integer',
            'label' => 'TechnicalID',
            'enabled' => 1,
            'position' => 1,
            'notnull' => 1,
            'visible' => 0,     // Não exibido em formulários
            'index' => 1,
        ),
        'ref' => array(
            'type' => 'varchar(128)',
            'label' => 'Ref',
            'enabled' => 1,
            'position' => 10,
            'notnull' => 1,
            'visible' => 1,
            'index' => 1,
            'searchall' => 1,
            'showoncombobox' => 1,
        ),
        'label' => array(
            'type' => 'varchar(255)',
            'label' => 'Label',
            'enabled' => 1,
            'position' => 20,
            'notnull' => 1,
            'visible' => 1,
            'searchall' => 1,
            'css' => 'minwidth300',
        ),
        'description' => array(
            'type' => 'text',
            'label' => 'Description',
            'enabled' => 1,
            'position' => 30,
            'notnull' => 0,
            'visible' => 3,     // Visível apenas na ficha
        ),
        'priority' => array(
            'type' => 'integer',
            'label' => 'Priority',
            'enabled' => 1,
            'position' => 40,
            'notnull' => 0,
            'visible' => 1,
            'arrayofkeyval' => array(0 => 'Baixa', 1 => 'Normal', 2 => 'Alta', 3 => 'Urgente'),
        ),
        'status' => array(
            'type' => 'integer',
            'label' => 'Status',
            'enabled' => 1,
            'position' => 50,
            'notnull' => 1,
            'visible' => 1,
            'default' => '0',
            'arrayofkeyval' => array(
                0 => 'Rascunho',
                1 => 'Ativa',
                2 => 'Concluída',
                9 => 'Cancelada',
            ),
        ),
        'fk_user' => array(
            'type' => 'integer:User:user/class/user.class.php',
            'label' => 'AssignedTo',
            'enabled' => 1,
            'position' => 60,
            'notnull' => 0,
            'visible' => 1,
        ),
        'date_creation' => array(
            'type' => 'datetime',
            'label' => 'DateCreation',
            'enabled' => 1,
            'position' => 500,
            'notnull' => 1,
            'visible' => -2,    // Visível na lista mas não no form
        ),
        'fk_user_creat' => array(
            'type' => 'integer:User:user/class/user.class.php',
            'label' => 'UserCreator',
            'enabled' => 1,
            'position' => 510,
            'notnull' => 1,
            'visible' => -2,
        ),
    );
    
    // Propriedades do objeto (mapeiam os campos)
    public $rowid;
    public $ref;
    public $label;
    public $description;
    public $priority;
    public $status;
    public $fk_user;
    public $date_creation;
    public $fk_user_creat;
    
    /**
     * Construtor
     */
    public function __construct(DoliDB $db)
    {
        global $conf, $langs;
        
        $this->db = $db;
        $this->ismultientitymanaged = 1;
        $this->isextrafieldmanaged = 1;
    }
    
    /**
     * Criar registro no banco
     */
    public function create(User $user, $notrigger = 0)
    {
        // Gera referência automática se vazia
        if (empty($this->ref)) {
            $this->ref = $this->getNextNumRef();
        }
        
        // createCommon lê $fields e gera INSERT automaticamente
        return $this->createCommon($user, $notrigger);
    }
    
    /**
     * Buscar registro do banco pelo ID
     */
    public function fetch($id, $ref = null)
    {
        return $this->fetchCommon($id, $ref);
    }
    
    /**
     * Atualizar registro
     */
    public function update(User $user, $notrigger = 0)
    {
        return $this->updateCommon($user, $notrigger);
    }
    
    /**
     * Excluir registro
     */
    public function delete(User $user, $notrigger = 0)
    {
        return $this->deleteCommon($user, $notrigger);
    }
    
    /**
     * Gera próxima referência (T-0001, T-0002, etc.)
     */
    public function getNextNumRef()
    {
        $sql = "SELECT MAX(CAST(SUBSTRING(ref, 3) AS UNSIGNED)) as maxref 
                FROM " . MAIN_DB_PREFIX . $this->table_element . " 
                WHERE ref LIKE 'T-%'";
        $res = $this->db->query($sql);
        $num = 0;
        if ($res) {
            $obj = $this->db->fetch_object($res);
            $num = (int) ($obj->maxref ?? 0);
        }
        return 'T-' . str_pad($num + 1, 4, '0', STR_PAD_LEFT);
    }
    
    /**
     * Retorna label do status
     */
    public function getLibStatut($mode = 0)
    {
        $statusLabels = array(
            0 => array('label' => 'Rascunho', 'badgetype' => 'status0'),
            1 => array('label' => 'Ativa', 'badgetype' => 'status4'),
            2 => array('label' => 'Concluída', 'badgetype' => 'status6'),
            9 => array('label' => 'Cancelada', 'badgetype' => 'status8'),
        );
        $s = $statusLabels[$this->status] ?? array('label' => 'Desconhecido', 'badgetype' => 'status0');
        
        if ($mode == 0) return $s['label'];
        
        return dolGetBadge($s['label'], '', $s['badgetype']);
    }
}
```

### 10.2 Como os Métodos `*Common()` Funcionam

Os métodos herdados de `CommonObject` leem o array `$fields` para gerar SQL:

```php
// $this->createCommon($user) internamente faz:
// 1. Lê $this->fields para saber quais colunas existem
// 2. Pega os valores de $this->nome_campo para cada campo
// 3. Gera: INSERT INTO llx_meutarefas_tarefa (ref, label, ...) VALUES ('T-0001', 'Minha tarefa', ...)
// 4. Define $this->id = last_insert_id
// 5. Se $notrigger == 0, dispara trigger TAREFA_CREATE

// $this->fetchCommon($id) internamente faz:
// 1. Gera: SELECT * FROM llx_meutarefas_tarefa WHERE rowid = $id
// 2. Preenche $this->ref, $this->label, etc.

// $this->updateCommon($user) internamente faz:
// 1. Gera: UPDATE llx_meutarefas_tarefa SET ref='...', label='...' WHERE rowid = $this->id

// $this->deleteCommon($user) internamente faz:
// 1. Gera: DELETE FROM llx_meutarefas_tarefa WHERE rowid = $this->id
```

---

## Capítulo 11: Hooks — Interceptando Páginas Sem Alterar o Core

### 11.1 O que são Hooks?

Hooks permitem que seu módulo **injete código em páginas existentes** do Dolibarr sem modificar os arquivos originais.

Exemplos do que você pode fazer com hooks:
- Adicionar campos em formulários existentes
- Modificar ações (ex: adicionar validação extra ao salvar faturas)
- Injetar HTML em qualquer página

### 11.2 Como Funciona

```
┌──────────────────────────────────────────┐
│ Página do Dolibarr (ex: facture/card.php)│
│                                          │
│ ... código normal ...                    │
│                                          │
│ // O Dolibarr chama hooks em vários      │
│ // pontos estratégicos:                  │
│ $hookmanager->executeHooks(              │
│     'formObjectOptions',                 │
│     $parameters,                         │
│     $object,                             │
│     $action                              │
│ );                                       │
│                                          │
│ ... mais código ...                      │
└──────────────────┬───────────────────────┘
                   │
                   │  Executa TODOS os módulos
                   │  que registraram este hook
                   │
                   ▼
┌──────────────────────────────────────────┐
│ Seu módulo: actions_meumodulo.class.php  │
│                                          │
│ function formObjectOptions(...) {        │
│     // Seu código aqui!                  │
│     // Pode adicionar campos, HTML, etc. │
│ }                                        │
└──────────────────────────────────────────┘
```

### 11.3 Passo a Passo para Criar um Hook

**Passo 1:** No descritor do módulo, declare quais contextos você quer interceptar:

```php
// Em modMeuModulo.class.php
$this->module_parts = array(
    'hooks' => array(
        'data' => array(
            'invoicecard',      // Intercepta ficha de faturas
            'thirdpartycard',   // Intercepta ficha de terceiros
            'ordercard',        // Intercepta ficha de pedidos
        ),
        'entity' => '0',
    ),
);
```

**Passo 2:** Crie o arquivo de hooks:

Arquivo: `custom/meumodulo/class/actions_meumodulo.class.php`

```php
<?php
require_once DOL_DOCUMENT_ROOT . '/core/class/commonhookactions.class.php';

class ActionsMeuModulo extends CommonHookActions
{
    public $db;
    public $error = '';
    public $errors = array();
    public $results = array();
    public $resprints = '';

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Hook: Adicionar campos extras no formulário de faturas
     * 
     * @param array     $parameters  Parâmetros do contexto
     * @param object    $object      O objeto sendo manipulado (Facture, Commande, etc.)
     * @param string    $action      Ação atual (create, edit, view, etc.)
     * @param HookManager $hookmanager
     * @return int  0=continua normal, 1=substitui código padrão
     */
    public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
    {
        global $conf, $user, $langs;
        
        // Verifica em qual página estamos
        $currentContext = $parameters['currentcontext'];
        
        if ($currentContext === 'invoicecard') {
            // Adiciona um campo personalizado na ficha da fatura
            $this->resprints .= '<tr>';
            $this->resprints .= '<td>Campo Custom</td>';
            $this->resprints .= '<td><input type="text" name="meu_campo_custom" class="flat"></td>';
            $this->resprints .= '</tr>';
        }
        
        return 0;
    }
    
    /**
     * Hook: Executar ação quando formulário é submetido
     */
    public function doActions($parameters, &$object, &$action, $hookmanager)
    {
        global $conf, $user, $langs;
        
        if (in_array($parameters['currentcontext'], array('invoicecard'))) {
            
            if ($action === 'add' || $action === 'update') {
                $meuCampo = GETPOST('meu_campo_custom', 'alpha');
                
                // Exemplo: salvar o valor extra em uma tabela separada
                if (!empty($meuCampo)) {
                    // ... lógica de salvamento ...
                    dol_syslog("MeuModulo: Salvou campo custom = " . $meuCampo);
                }
            }
        }
        
        return 0;
    }
    
    /**
     * Hook: Adicionar colunas na lista de faturas
     */
    public function printFieldListValue($parameters, &$object, &$action, $hookmanager)
    {
        if ($parameters['currentcontext'] === 'invoicelist') {
            // Adiciona uma coluna extra em cada linha
            $this->resprints = '<td class="center">Valor custom</td>';
        }
        return 0;
    }
}
```

### 11.4 Hooks Mais Usados

| Hook | Quando é chamado | Uso típico |
|---|---|---|
| `formObjectOptions` | Dentro de formulários | Adicionar campos |
| `doActions` | Ao processar ações (POST) | Interceptar salvamento |
| `printFieldListValue` | Em cada linha de lista | Adicionar colunas |
| `addMoreActionsButtons` | Na barra de ações | Adicionar botões |
| `beforePDFCreation` | Antes de gerar PDF | Modificar dados do PDF |
| `afterPDFCreation` | Após gerar PDF | Enviar email, etc. |
| `getNomUrl` | Ao gerar link do objeto | Customizar links |
| `formConfirm` | Em confirmações | Modificar diálogos |
| `doMassActions` | Em ações em lote | Adicionar ações em massa |
| `completeTabsHead` | Ao montar abas | Adicionar abas |

### 11.5 Retornos do Hook

| Retorno | Efeito |
|---|---|
| `0` | OK, continua executando código padrão |
| `1` | OK, **substitui** código padrão (usa `$this->resprints` em vez do padrão) |
| `-1` | Erro, para a execução |

---

## Capítulo 12: Triggers — Reagindo a Eventos de Negócio

### 12.1 Diferença entre Hooks e Triggers

| | Hooks | Triggers |
|---|---|---|
| **Quando** | Durante renderização de páginas | Após ações de negócio |
| **Propósito** | Modificar interface/comportamento | Reagir a eventos (log, email, integração) |
| **Arquivo** | `class/actions_xxx.class.php` | `core/triggers/interface_xx_xxx.class.php` |
| **Exemplo** | Adicionar campo num formulário | Enviar email quando pedido é criado |

### 12.2 Criando um Trigger

Arquivo: `custom/meutarefas/core/triggers/interface_99_modMeuTarefas_Triggers.class.php`

```php
<?php
require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';

class InterfaceMeuTarefasTriggers extends DolibarrTriggers
{
    public function __construct($db)
    {
        $this->db = $db;
        $this->family = "demo";
        $this->description = "Triggers do módulo MeuTarefas";
        $this->version = '1.0.0';
        $this->picto = 'meutarefas@meutarefas';
    }

    /**
     * Chamado automaticamente quando QUALQUER evento de negócio ocorre
     * 
     * @param string    $action  Nome do evento (ex: ORDER_CREATE, BILL_VALIDATE)
     * @param object    $object  O objeto envolvido (Commande, Facture, etc.)
     * @param User      $user    Usuário que disparou a ação
     * @param Translate $langs   Traduções
     * @param Conf      $conf    Configurações
     */
    public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
    {
        // Sai se o módulo não está ativo
        if (!isModEnabled('meutarefas')) return 0;
        
        switch ($action) {
            
            // Quando um pedido é criado
            case 'ORDER_CREATE':
                dol_syslog("MeuTarefas: Pedido criado #" . $object->ref);
                // Criar tarefa automaticamente
                $this->criarTarefaParaPedido($object, $user);
                break;
            
            // Quando uma fatura é validada
            case 'BILL_VALIDATE':
                dol_syslog("MeuTarefas: Fatura validada #" . $object->ref);
                break;
            
            // Quando um terceiro é criado
            case 'COMPANY_CREATE':
                dol_syslog("MeuTarefas: Empresa criada: " . $object->name);
                break;
                
            // Quando um pagamento é registrado
            case 'PAYMENT_CUSTOMER_CREATE':
                dol_syslog("MeuTarefas: Pagamento recebido de " . $object->amount);
                break;
        }
        
        return 0;
    }
    
    /**
     * Cria tarefa automática para um pedido
     */
    private function criarTarefaParaPedido($order, $user)
    {
        require_once DOL_DOCUMENT_ROOT . '/custom/meutarefas/class/tarefa.class.php';
        
        $tarefa = new Tarefa($this->db);
        $tarefa->label = 'Processar pedido ' . $order->ref;
        $tarefa->description = 'Pedido criado automaticamente para acompanhamento';
        $tarefa->priority = 1;  // Normal
        $tarefa->status = 1;    // Ativa
        $tarefa->fk_user = $user->id;
        $tarefa->create($user, 1);  // 1 = notrigger (evita recursão)
    }
}
```

### 12.3 Eventos de Trigger Disponíveis

| Evento | Quando |
|---|---|
| `COMPANY_CREATE` | Empresa criada |
| `COMPANY_MODIFY` | Empresa modificada |
| `COMPANY_DELETE` | Empresa deletada |
| `CONTACT_CREATE` | Contato criado |
| `PROPAL_VALIDATE` | Proposta validada |
| `PROPAL_CLOSE_SIGNED` | Proposta aceita |
| `ORDER_CREATE` | Pedido criado |
| `ORDER_VALIDATE` | Pedido validado |
| `ORDER_CLOSE` | Pedido fechado |
| `BILL_CREATE` | Fatura criada |
| `BILL_VALIDATE` | Fatura validada |
| `BILL_PAYED` | Fatura paga |
| `BILL_CANCEL` | Fatura cancelada |
| `PAYMENT_CUSTOMER_CREATE` | Pagamento de cliente |
| `PAYMENT_SUPPLIER_CREATE` | Pagamento a fornecedor |
| `PRODUCT_CREATE` | Produto criado |
| `SHIPPING_CREATE` | Expedição criada |
| `USER_CREATE` | Usuário criado |
| `USER_LOGIN` | Usuário fez login |
| `MEMBER_CREATE` | Membro criado |
| `TICKET_CREATE` | Ticket criado |

---

# PARTE 4 — EXERCÍCIOS

---

## Exercícios Nível Básico

### Exercício 1: Olá Dolibarr
**Objetivo:** Criar uma página que exibe "Olá, [nome do usuario]!" usando os objetos globais do Dolibarr.

**Requisitos:**
- Crie `custom/exercicios/ex01.php`
- Exiba o nome do usuário logado, data/hora atual, e versão do Dolibarr

---

### Exercício 2: Lista de Terceiros
**Objetivo:** Criar uma página que lista os 10 primeiros terceiros (empresas/clientes) do banco.

**Requisitos:**
- Use `$db->query()` para buscar da tabela `llx_societe`
- Exiba em uma tabela HTML com classes `liste`, `oddeven`
- Mostre: ID, Nome, Email, Status

---

### Exercício 3: Contador de Registros
**Objetivo:** Criar uma página que mostra quantas faturas, pedidos e terceiros existem.

**Requisitos:**
- Faça 3 consultas COUNT(*) nas tabelas `llx_facture`, `llx_commande`, `llx_societe`
- Exiba em cards simples

---

### Exercício 4: Formulário de Contato
**Objetivo:** Criar um formulário simples que salva um nome e email em uma tabela custom.

**Requisitos:**
- Crie a tabela manualmente no banco: `llx_exercicio_contatos (rowid, nome, email, date_creation)`
- Crie o formulário com os campos
- Ao submeter, insira no banco e mostre mensagem de sucesso

---

### Exercício 5: Página com Permissão
**Objetivo:** Criar uma página que só administradores podem acessar.

**Requisitos:**
- Use `$user->admin` para verificar
- Se não for admin, use `accessforbidden()` 
- Se for admin, mostre uma mensagem especial

---

## Exercícios Nível Intermediário

### Exercício 6: CRUD Completo
**Objetivo:** Criar um CRUD (Create, Read, Update, Delete) de "Anotações" com listagem e ficha.

**Requisitos:**
- Tabela: `llx_exercicio_anotacoes (rowid, titulo, conteudo TEXT, fk_user, status INT, date_creation)`
- `lista.php` com tabela, filtro por título, paginação, ordenação
- `card.php` com modos criar/editar/visualizar/excluir
- Use `$form->formconfirm()` para confirmar exclusão

---

### Exercício 7: AJAX — Busca Dinâmica
**Objetivo:** Criar um campo de busca que filtra resultados em tempo real via AJAX.

**Requisitos:**
- Input de busca que ao digitar faz fetch para um endpoint PHP
- O PHP busca no banco e retorna JSON
- O JavaScript popula uma tabela com os resultados

---

### Exercício 8: Select Dependente
**Objetivo:** Criar dois selects dependentes: Estado → Cidade (como no "Incluir NF-e").

**Requisitos:**
- Select de Estado (hardcoded ou do banco)
- Ao mudar o estado, buscar cidades via AJAX
- Popular o segundo select com as cidades
- Implementar cache no JavaScript

---

### Exercício 9: Modal com Formulário
**Objetivo:** Criar uma modal que aparece ao clicar em um botão e envia dados via AJAX.

**Requisitos:**
- HTML da modal (overlay + box + form + botões)
- CSS para overlay fixo e centralizado
- JavaScript para abrir/fechar/enviar
- PHP que recebe os dados e grava no banco
- Tratar sucesso/erro no frontend

---

### Exercício 10: Dashboard com Gráfico
**Objetivo:** Criar uma página dashboard com números e um gráfico simples.

**Requisitos:**
- Cards com contadores (total de registros por status)
- Tabela com os últimos 5 registros
- Gráfico de barras usando HTML/CSS puro (sem lib JS)

---

## Exercícios Nível Avançado

### Exercício 11: Módulo Completo
**Objetivo:** Criar um módulo "Inventário" do zero com descritor, menus, permissões, classe de dados.

**Requisitos:**
- `modMeuInventario.class.php` com menus e permissões
- Classe `Inventario` estendendo `CommonObject` com `$fields`
- SQL de criação de tabela
- Páginas de lista e ficha funcional
- Traduções em `langs/pt_BR/`

---

### Exercício 12: Hook em Fatura
**Objetivo:** Criar um hook que adiciona um campo "Número do Pedido de Compra" na ficha de fatura.

**Requisitos:**
- Arquivo `actions_meumodulo.class.php`
- Hook `formObjectOptions` no contexto `invoicecard`
- Salvar o valor em tabela separada usando hook `doActions`
- Exibir o valor na visualização da fatura

---

### Exercício 13: Trigger de Notificação
**Objetivo:** Criar um trigger que grava um log toda vez que um terceiro é criado.

**Requisitos:**
- Tabela `llx_exercicio_logs (rowid, evento, descricao, fk_user, date_creation)`
- Trigger que reage a `COMPANY_CREATE`, `ORDER_CREATE`, `BILL_VALIDATE`
- Grava registro de log com detalhes do evento
- Crie uma página para visualizar os logs

---

### Exercício 14: API REST
**Objetivo:** Criar um endpoint REST simples para o módulo custom.

**Requisitos:**
- Endpoint GET que lista registros em JSON
- Endpoint POST que cria um registro
- Autenticação via token simples (header Authorization)
- Tratamento de erros com códigos HTTP corretos

---

### Exercício 15: Relatório em PDF
**Objetivo:** Criar uma página que gera um relatório em PDF com dados do banco.

**Requisitos:**
- Usar a classe TCPDF (já incluída no Dolibarr)
- Cabeçalho com logo e dados da empresa
- Tabela com dados de registros
- Botão "Gerar PDF" que faz download

---

## Respostas Completas

---

### Resposta Exercício 1: Olá Dolibarr

Arquivo: `custom/exercicios/ex01.php`

```php
<?php
/**
 * Exercício 1 — Olá Dolibarr
 * Exibe informações básicas usando objetos globais
 */
require '../../main.inc.php';

// Verifica login
if (!$user->id) accessforbidden();

// Inicia página
llxHeader('', 'Exercício 1 - Olá Dolibarr');

print load_fiche_titre('Olá Dolibarr!', '', 'object_generic');

print '<div class="fichecenter">';
print '<table class="border centpercent">';

// Nome do usuário
print '<tr>';
print '<td class="titlefield">Usuário Logado</td>';
print '<td><strong>' . dol_escape_htmltag($user->getFullName($langs)) . '</strong></td>';
print '</tr>';

// Login
print '<tr>';
print '<td>Login</td>';
print '<td>' . dol_escape_htmltag($user->login) . '</td>';
print '</tr>';

// É admin?
print '<tr>';
print '<td>Administrador?</td>';
print '<td>' . ($user->admin ? '<span style="color:green;">Sim</span>' : 'Não') . '</td>';
print '</tr>';

// Data e hora
print '<tr>';
print '<td>Data e Hora Atual</td>';
print '<td>' . dol_print_date(dol_now(), 'dayhour') . '</td>';
print '</tr>';

// Versão do Dolibarr
print '<tr>';
print '<td>Versão Dolibarr</td>';
print '<td>' . DOL_VERSION . '</td>';
print '</tr>';

// Nome da empresa
global $mysoc;
print '<tr>';
print '<td>Empresa</td>';
print '<td>' . dol_escape_htmltag($mysoc->name) . '</td>';
print '</tr>';

// Prefixo do banco
print '<tr>';
print '<td>Prefixo do Banco</td>';
print '<td><code>' . MAIN_DB_PREFIX . '</code></td>';
print '</tr>';

print '</table>';
print '</div>';

llxFooter();
$db->close();
```

---

### Resposta Exercício 2: Lista de Terceiros

Arquivo: `custom/exercicios/ex02.php`

```php
<?php
/**
 * Exercício 2 — Lista de Terceiros
 * Consulta e exibe os 10 primeiros terceiros do banco
 */
require '../../main.inc.php';

if (!$user->id) accessforbidden();

llxHeader('', 'Exercício 2 - Lista de Terceiros');

print load_fiche_titre('Meus 10 Primeiros Terceiros', '', 'company');

// Consulta SQL
$sql = "SELECT rowid, nom as nome, email, status, datec 
        FROM " . MAIN_DB_PREFIX . "societe 
        ORDER BY nom ASC 
        LIMIT 10";

$resql = $db->query($sql);

if (!$resql) {
    dol_print_error($db);
} else {
    $num = $db->num_rows($resql);
    
    print '<table class="liste noborder centpercent">';
    
    // Cabeçalho
    print '<tr class="liste_titre">';
    print '<th>ID</th>';
    print '<th>Nome</th>';
    print '<th>Email</th>';
    print '<th class="center">Status</th>';
    print '<th class="center">Data Criação</th>';
    print '</tr>';
    
    if ($num > 0) {
        $i = 0;
        while ($i < $num) {
            $obj = $db->fetch_object($resql);
            
            // Determina label do status
            $statusLabel = ($obj->status == 1) 
                ? '<span style="color:green;">Ativo</span>' 
                : '<span style="color:red;">Inativo</span>';
            
            print '<tr class="oddeven">';
            print '<td>' . (int) $obj->rowid . '</td>';
            print '<td><strong>' . dol_escape_htmltag($obj->nome) . '</strong></td>';
            print '<td>' . dol_escape_htmltag($obj->email ?: '-') . '</td>';
            print '<td class="center">' . $statusLabel . '</td>';
            print '<td class="center">' . 
                  ($obj->datec ? dol_print_date($db->jdate($obj->datec), 'day') : '-') . 
                  '</td>';
            print '</tr>';
            
            $i++;
        }
    } else {
        print '<tr><td colspan="5" class="opacitymedium center">
               Nenhum terceiro encontrado.
               </td></tr>';
    }
    
    print '</table>';
    
    $db->free($resql);
}

llxFooter();
$db->close();
```

---

### Resposta Exercício 3: Contador de Registros

Arquivo: `custom/exercicios/ex03.php`

```php
<?php
/**
 * Exercício 3 — Contador de Registros
 * Dashboard simples com contadores
 */
require '../../main.inc.php';

if (!$user->id) accessforbidden();

llxHeader('', 'Exercício 3 - Contadores');

print load_fiche_titre('Dashboard de Contagens', '', 'object_generic');

// Função auxiliar para contar registros
function contarRegistros($db, $tabela, $where = '') {
    $sql = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . $tabela;
    if ($where) $sql .= " WHERE " . $where;
    $res = $db->query($sql);
    if ($res) {
        $obj = $db->fetch_object($res);
        return (int) $obj->total;
    }
    return 0;
}

$totalTerceiros = contarRegistros($db, 'societe');
$totalFaturas = contarRegistros($db, 'facture');
$totalPedidos = contarRegistros($db, 'commande');
$totalProdutos = contarRegistros($db, 'product');
$totalUsuarios = contarRegistros($db, 'user', 'statut = 1');

// CSS inline para os cards
print '<style>
.ex-cards{display:flex;gap:16px;flex-wrap:wrap;margin:20px 0;}
.ex-card{flex:1;min-width:180px;background:#fff;border:1px solid #ddd;border-radius:8px;
         padding:20px;text-align:center;box-shadow:0 2px 4px rgba(0,0,0,.08);}
.ex-card .num{font-size:2.5em;font-weight:bold;color:#0066cc;margin:10px 0;}
.ex-card .label{font-size:0.9em;color:#666;text-transform:uppercase;letter-spacing:1px;}
</style>';

print '<div class="ex-cards">';

$cards = [
    ['label' => 'Terceiros', 'num' => $totalTerceiros],
    ['label' => 'Faturas', 'num' => $totalFaturas],
    ['label' => 'Pedidos', 'num' => $totalPedidos],
    ['label' => 'Produtos', 'num' => $totalProdutos],
    ['label' => 'Usuários Ativos', 'num' => $totalUsuarios],
];

foreach ($cards as $card) {
    print '<div class="ex-card">';
    print '<div class="label">' . $card['label'] . '</div>';
    print '<div class="num">' . number_format($card['num'], 0, ',', '.') . '</div>';
    print '</div>';
}

print '</div>';

llxFooter();
$db->close();
```

---

### Resposta Exercício 4: Formulário de Contato

Arquivo: `custom/exercicios/ex04.php`

```php
<?php
/**
 * Exercício 4 — Formulário de Contato
 * Salva nome e email em tabela custom
 * 
 * ANTES DE USAR: Execute no phpMyAdmin:
 * CREATE TABLE llx_exercicio_contatos (
 *     rowid INT AUTO_INCREMENT PRIMARY KEY,
 *     nome VARCHAR(255) NOT NULL,
 *     email VARCHAR(255),
 *     date_creation DATETIME DEFAULT CURRENT_TIMESTAMP
 * );
 */
require '../../main.inc.php';

if (!$user->id) accessforbidden();

$action = GETPOST('action', 'alpha');

// ═══ Processar formulário ═══
if ($action == 'add') {
    $nome = GETPOST('nome', 'alpha');
    $email = GETPOST('email', 'alpha');
    
    $erros = [];
    if (empty($nome)) $erros[] = 'O nome é obrigatório.';
    if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $erros[] = 'Email inválido.';
    }
    
    if (!empty($erros)) {
        setEventMessages(null, $erros, 'errors');
    } else {
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "exercicio_contatos (nome, email, date_creation) 
                VALUES ('" . $db->escape($nome) . "', '" . $db->escape($email) . "', NOW())";
        
        if ($db->query($sql)) {
            setEventMessages('Contato "' . $nome . '" salvo com sucesso!', null, 'mesgs');
            // Redireciona para limpar POST (padrão PRG - Post/Redirect/Get)
            header("Location: " . $_SERVER['PHP_SELF']);
            exit;
        } else {
            setEventMessages('Erro ao salvar: ' . $db->lasterror(), null, 'errors');
        }
    }
}

// ═══ Exibição ═══
llxHeader('', 'Exercício 4 - Formulário de Contato');

print load_fiche_titre('Cadastro de Contato', '', 'object_generic');

// Formulário
print '<form method="POST" action="' . $_SERVER["PHP_SELF"] . '">';
print '<input type="hidden" name="action" value="add">';
print '<input type="hidden" name="token" value="' . newToken() . '">';

print '<table class="border centpercent">';
print '<tr>';
print '<td class="fieldrequired titlefieldcreate">Nome</td>';
print '<td><input type="text" name="nome" class="flat minwidth300" value="' . 
      dol_escape_htmltag(GETPOST('nome', 'alpha')) . '" placeholder="Digite o nome"></td>';
print '</tr>';
print '<tr>';
print '<td>Email</td>';
print '<td><input type="email" name="email" class="flat minwidth300" value="' . 
      dol_escape_htmltag(GETPOST('email', 'alpha')) . '" placeholder="email@exemplo.com"></td>';
print '</tr>';
print '</table>';

print '<div class="center" style="margin-top:15px;">';
print '<input type="submit" class="butAction" value="Salvar Contato">';
print '</div>';
print '</form>';

// Lista de contatos já salvos
print '<br>';
print load_fiche_titre('Contatos Salvos', '', '');

$sql = "SELECT rowid, nome, email, date_creation 
        FROM " . MAIN_DB_PREFIX . "exercicio_contatos 
        ORDER BY date_creation DESC";
$res = $db->query($sql);

if ($res) {
    $num = $db->num_rows($res);
    
    print '<table class="liste noborder centpercent">';
    print '<tr class="liste_titre">';
    print '<th>ID</th><th>Nome</th><th>Email</th><th class="center">Data</th>';
    print '</tr>';
    
    if ($num > 0) {
        while ($obj = $db->fetch_object($res)) {
            print '<tr class="oddeven">';
            print '<td>' . $obj->rowid . '</td>';
            print '<td>' . dol_escape_htmltag($obj->nome) . '</td>';
            print '<td>' . dol_escape_htmltag($obj->email ?: '-') . '</td>';
            print '<td class="center">' . dol_print_date($db->jdate($obj->date_creation), 'dayhour') . '</td>';
            print '</tr>';
        }
    } else {
        print '<tr><td colspan="4" class="opacitymedium center">Nenhum contato cadastrado.</td></tr>';
    }
    
    print '</table>';
}

llxFooter();
$db->close();
```

---

### Resposta Exercício 5: Página com Permissão

Arquivo: `custom/exercicios/ex05.php`

```php
<?php
/**
 * Exercício 5 — Página com Permissão
 * Apenas administradores podem acessar
 */
require '../../main.inc.php';

if (!$user->id) accessforbidden();

// ══ Verificação de permissão ══
if (!$user->admin) {
    accessforbidden('Apenas administradores podem acessar esta página.');
    // A função accessforbidden() exibe mensagem de erro e encerra
}

llxHeader('', 'Exercício 5 - Área Admin');

print load_fiche_titre('Painel do Administrador', '', 'object_generic');

print '<div class="info" style="padding:15px;background:#d4edda;border:1px solid #c3e6cb;
       border-radius:6px;margin-bottom:20px;">';
print '<strong>Parabéns, ' . dol_escape_htmltag($user->login) . '!</strong><br>';
print 'Você é um administrador e tem acesso total a esta página.';
print '</div>';

// Mostra informações privilegiadas
print '<table class="border centpercent">';
print '<tr><td class="titlefield">Seu ID</td><td>' . $user->id . '</td></tr>';
print '<tr><td>Último Login</td><td>' . dol_print_date($user->datelastlogin, 'dayhour') . '</td></tr>';
print '<tr><td>Empresa</td><td>' . dol_escape_htmltag($conf->global->MAIN_INFO_SOCIETE_NOM ?? '-') . '</td></tr>';
print '<tr><td>Versão PHP</td><td>' . phpversion() . '</td></tr>';
print '<tr><td>Versão MySQL</td><td>' . $db->getVersion() . '</td></tr>';
print '<tr><td>Diretório Dolibarr</td><td><code>' . DOL_DOCUMENT_ROOT . '</code></td></tr>';
print '</table>';

llxFooter();
$db->close();
```

---

### Resposta Exercício 7: AJAX — Busca Dinâmica

Arquivo: `custom/exercicios/ex07_busca.php`

```php
<?php
/**
 * Exercício 7 — Busca Dinâmica via AJAX
 * 
 * TABELA NECESSÁRIA (mesma do ex04):
 * CREATE TABLE llx_exercicio_contatos (
 *     rowid INT AUTO_INCREMENT PRIMARY KEY,
 *     nome VARCHAR(255) NOT NULL,
 *     email VARCHAR(255),
 *     date_creation DATETIME DEFAULT CURRENT_TIMESTAMP
 * );
 */
require '../../main.inc.php';

// ═══ HANDLER AJAX ═══
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) 
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    
    header('Content-Type: application/json; charset=UTF-8');
    
    $termo = GETPOST('termo', 'alpha');
    
    $sql = "SELECT rowid, nome, email, date_creation 
            FROM " . MAIN_DB_PREFIX . "exercicio_contatos 
            WHERE 1=1";
    
    if (!empty($termo)) {
        $sql .= " AND (nome LIKE '%" . $db->escape($termo) . "%' 
                  OR email LIKE '%" . $db->escape($termo) . "%')";
    }
    $sql .= " ORDER BY nome ASC LIMIT 50";
    
    $res = $db->query($sql);
    $resultados = [];
    
    if ($res) {
        while ($obj = $db->fetch_object($res)) {
            $resultados[] = [
                'id'    => (int) $obj->rowid,
                'nome'  => $obj->nome,
                'email' => $obj->email ?: '-',
                'data'  => $obj->date_creation ? date('d/m/Y H:i', strtotime($obj->date_creation)) : '-',
            ];
        }
    }
    
    echo json_encode(['success' => true, 'data' => $resultados], JSON_UNESCAPED_UNICODE);
    exit;
}

// ═══ PÁGINA NORMAL ═══
llxHeader('', 'Exercício 7 - Busca Dinâmica');

print load_fiche_titre('Busca Dinâmica de Contatos', '', 'object_generic');

// Campo de busca
print '<div style="margin-bottom:20px;">';
print '<input type="text" id="campoBusca" class="flat" style="width:400px;padding:8px;" 
       placeholder="Digite para buscar..." oninput="buscar()">';
print '<span id="buscaStatus" style="margin-left:10px;color:#999;font-size:0.85em;"></span>';
print '</div>';

// Tabela de resultados
print '<table class="liste noborder centpercent" id="tabelaResultados">';
print '<tr class="liste_titre">';
print '<th>ID</th><th>Nome</th><th>Email</th><th class="center">Data</th>';
print '</tr>';
print '<tbody id="corpoTabela">';
print '<tr><td colspan="4" class="opacitymedium center">Digite algo para buscar...</td></tr>';
print '</tbody>';
print '</table>';

// JavaScript
print '<script>
var _buscaTimer = null;  // Debounce timer

function buscar() {
    var termo = document.getElementById("campoBusca").value.trim();
    var status = document.getElementById("buscaStatus");
    
    // Debounce: espera 300ms após última digitação
    clearTimeout(_buscaTimer);
    _buscaTimer = setTimeout(function() {
        status.textContent = "Buscando...";
        
        fetch("' . $_SERVER['PHP_SELF'] . '?termo=" + encodeURIComponent(termo), {
            headers: { "X-Requested-With": "XMLHttpRequest" },
            credentials: "same-origin"
        })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            var corpo = document.getElementById("corpoTabela");
            
            if (!data.success || data.data.length === 0) {
                corpo.innerHTML = "<tr><td colspan=\"4\" class=\"opacitymedium center\">" +
                    "Nenhum resultado encontrado.</td></tr>";
                status.textContent = "0 resultados";
                return;
            }
            
            var html = "";
            for (var i = 0; i < data.data.length; i++) {
                var r = data.data[i];
                html += "<tr class=\"oddeven\">" +
                    "<td>" + r.id + "</td>" +
                    "<td><strong>" + escapeHtml(r.nome) + "</strong></td>" +
                    "<td>" + escapeHtml(r.email) + "</td>" +
                    "<td class=\"center\">" + r.data + "</td>" +
                    "</tr>";
            }
            corpo.innerHTML = html;
            status.textContent = data.data.length + " resultado(s)";
        })
        .catch(function(err) {
            status.textContent = "Erro: " + err.message;
        });
    }, 300);
}

function escapeHtml(text) {
    var div = document.createElement("div");
    div.appendChild(document.createTextNode(text));
    return div.innerHTML;
}

// Busca inicial (mostra todos)
buscar();
</script>';

llxFooter();
$db->close();
```

---

### Resposta Exercício 9: Modal com Formulário

Arquivo: `custom/exercicios/ex09_modal.php`

```php
<?php
/**
 * Exercício 9 — Modal com Formulário AJAX
 * Modal para adicionar contatos, tudo via AJAX
 * 
 * TABELA: llx_exercicio_contatos (mesma dos exercícios anteriores)
 */
require '../../main.inc.php';

// ═══ HANDLER AJAX ═══
if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) 
    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    
    header('Content-Type: application/json; charset=UTF-8');
    $action = GETPOST('action', 'alpha');
    
    // Listar contatos
    if ($action === 'listar') {
        $sql = "SELECT rowid, nome, email, date_creation 
                FROM " . MAIN_DB_PREFIX . "exercicio_contatos 
                ORDER BY date_creation DESC LIMIT 20";
        $res = $db->query($sql);
        $lista = [];
        while ($obj = $db->fetch_object($res)) {
            $lista[] = [
                'id' => (int) $obj->rowid,
                'nome' => $obj->nome,
                'email' => $obj->email ?: '-',
                'data' => date('d/m/Y H:i', strtotime($obj->date_creation)),
            ];
        }
        echo json_encode(['success' => true, 'data' => $lista], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Adicionar contato
    if ($action === 'adicionar') {
        $nome = GETPOST('nome', 'alpha');
        $email = GETPOST('email', 'alpha');
        
        if (empty($nome)) {
            echo json_encode(['success' => false, 'error' => 'Nome é obrigatório.']);
            exit;
        }
        
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "exercicio_contatos 
                (nome, email, date_creation) VALUES 
                ('" . $db->escape($nome) . "', '" . $db->escape($email) . "', NOW())";
        
        if ($db->query($sql)) {
            echo json_encode(['success' => true, 'message' => 'Contato salvo!']);
        } else {
            echo json_encode(['success' => false, 'error' => $db->lasterror()]);
        }
        exit;
    }
    
    echo json_encode(['success' => false, 'error' => 'Ação desconhecida']);
    exit;
}

// ═══ PÁGINA NORMAL ═══
llxHeader('', 'Exercício 9 - Modal AJAX');

print load_fiche_titre('Contatos com Modal AJAX', '', 'object_generic');

// Botão para abrir modal
print '<div style="margin-bottom:20px;">';
print '<button class="butAction" onclick="abrirModal()">
       <i class="fa fa-plus"></i> Novo Contato</button>';
print '</div>';

// Tabela (preenchida via AJAX)
print '<table class="liste noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>ID</th><th>Nome</th><th>Email</th><th class="center">Data</th>';
print '</tr>';
print '<tbody id="tabelaContatos">';
print '<tr><td colspan="4" class="center opacitymedium">Carregando...</td></tr>';
print '</tbody>';
print '</table>';

// ═══ HTML DA MODAL ═══
print '
<style>
.modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.45);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 100000;
}
.modal-overlay.visible { display: flex; }
.modal-box {
    background: #fff;
    border-radius: 8px;
    box-shadow: 0 4px 24px rgba(0,0,0,.2);
    max-width: 420px;
    width: 95%;
}
.modal-header {
    padding: 14px 18px;
    border-bottom: 2px solid #eee;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.modal-header strong { font-size: 1.1em; }
.modal-close {
    background: none;
    border: none;
    font-size: 1.5em;
    cursor: pointer;
    color: #aaa;
}
.modal-close:hover { color: #333; }
.modal-body { padding: 18px; }
.modal-body label {
    display: block;
    font-weight: 600;
    font-size: 0.85em;
    color: #555;
    margin-bottom: 4px;
}
.modal-body label span { color: red; }
.modal-body input {
    width: 100%;
    box-sizing: border-box;
    padding: 8px;
    border: 1px solid #ccc;
    border-radius: 4px;
    margin-bottom: 12px;
    font-size: 0.95em;
}
.modal-body input:focus { border-color: #007bff; outline: none; }
.modal-footer {
    padding: 12px 18px;
    border-top: 1px solid #eee;
    text-align: right;
    display: flex;
    gap: 8px;
    justify-content: flex-end;
}
.modal-notice {
    padding: 8px 12px;
    border-radius: 4px;
    margin-bottom: 12px;
    font-size: 0.85em;
    display: none;
}
.modal-notice.error { display: block; background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
.modal-notice.success { display: block; background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
</style>

<div id="contatoModal" class="modal-overlay" role="dialog">
    <div class="modal-box">
        <div class="modal-header">
            <strong>Novo Contato</strong>
            <button class="modal-close" onclick="fecharModal()">&times;</button>
        </div>
        <div class="modal-body">
            <div id="modalNotice" class="modal-notice"></div>
            <label for="modalNome">Nome <span>*</span></label>
            <input type="text" id="modalNome" placeholder="Nome do contato">
            <label for="modalEmail">Email</label>
            <input type="email" id="modalEmail" placeholder="email@exemplo.com">
        </div>
        <div class="modal-footer">
            <button class="butActionDelete" onclick="fecharModal()">Cancelar</button>
            <button class="butAction" id="btnSalvar" onclick="salvarContato()">Salvar</button>
        </div>
    </div>
</div>';

// ═══ JAVASCRIPT ═══
print '<script>
// Abre a modal
function abrirModal() {
    document.getElementById("modalNome").value = "";
    document.getElementById("modalEmail").value = "";
    document.getElementById("modalNotice").className = "modal-notice";
    document.getElementById("modalNotice").textContent = "";
    var btn = document.getElementById("btnSalvar");
    btn.disabled = false; btn.textContent = "Salvar"; btn.style.opacity = "";
    document.getElementById("contatoModal").classList.add("visible");
    document.getElementById("modalNome").focus();
}

// Fecha a modal
function fecharModal() {
    document.getElementById("contatoModal").classList.remove("visible");
}

// Salva via AJAX
function salvarContato() {
    var nome = document.getElementById("modalNome").value.trim();
    var email = document.getElementById("modalEmail").value.trim();
    var notice = document.getElementById("modalNotice");
    var btn = document.getElementById("btnSalvar");
    
    if (!nome) {
        notice.className = "modal-notice error";
        notice.textContent = "O nome é obrigatório.";
        document.getElementById("modalNome").focus();
        return;
    }
    
    btn.disabled = true;
    btn.textContent = "Salvando...";
    btn.style.opacity = "0.6";
    
    fetch("' . $_SERVER['PHP_SELF'] . '", {
        method: "POST",
        headers: {
            "X-Requested-With": "XMLHttpRequest",
            "Content-Type": "application/x-www-form-urlencoded"
        },
        credentials: "same-origin",
        body: "action=adicionar&nome=" + encodeURIComponent(nome) + "&email=" + encodeURIComponent(email)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            notice.className = "modal-notice success";
            notice.textContent = data.message;
            setTimeout(function() {
                fecharModal();
                carregarLista();  // Atualiza tabela
            }, 800);
        } else {
            notice.className = "modal-notice error";
            notice.textContent = data.error;
            btn.disabled = false; btn.textContent = "Tentar Novamente"; btn.style.opacity = "";
        }
    })
    .catch(function(err) {
        notice.className = "modal-notice error";
        notice.textContent = "Erro: " + err.message;
        btn.disabled = false; btn.textContent = "Tentar Novamente"; btn.style.opacity = "";
    });
}

// Carrega lista de contatos
function carregarLista() {
    fetch("' . $_SERVER['PHP_SELF'] . '?action=listar", {
        headers: { "X-Requested-With": "XMLHttpRequest" },
        credentials: "same-origin"
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        var corpo = document.getElementById("tabelaContatos");
        if (!data.success || data.data.length === 0) {
            corpo.innerHTML = "<tr><td colspan=\"4\" class=\"center opacitymedium\">Nenhum contato.</td></tr>";
            return;
        }
        var html = "";
        data.data.forEach(function(c) {
            html += "<tr class=\"oddeven\">" +
                "<td>" + c.id + "</td>" +
                "<td><strong>" + c.nome + "</strong></td>" +
                "<td>" + c.email + "</td>" +
                "<td class=\"center\">" + c.data + "</td></tr>";
        });
        corpo.innerHTML = html;
    });
}

// Fecha modal clicando fora ou ESC
document.addEventListener("click", function(e) {
    if (e.target.id === "contatoModal") fecharModal();
});
document.addEventListener("keydown", function(e) {
    if (e.key === "Escape") fecharModal();
});

// Carrega lista ao abrir a página
carregarLista();
</script>';

llxFooter();
$db->close();
```

---

### Resposta Exercício 12: Hook em Fatura

Arquivo: `custom/meumodulo/class/actions_meumodulo.class.php`

```php
<?php
/**
 * Exercício 12 — Hook que adiciona campo "Nº Pedido de Compra" na fatura
 */
require_once DOL_DOCUMENT_ROOT . '/core/class/commonhookactions.class.php';

class ActionsMeuModulo extends CommonHookActions
{
    public $db;
    public $error = '';
    public $errors = array();
    public $results = array();
    public $resprints = '';

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Adiciona campo no formulário de criação/edição de fatura
     */
    public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
    {
        global $conf, $user, $langs;
        
        if (!in_array($parameters['currentcontext'], array('invoicecard'))) {
            return 0;
        }
        
        // Busca valor salvo (se editando/visualizando)
        $valorSalvo = '';
        if (!empty($object->id)) {
            $sql = "SELECT valor FROM " . MAIN_DB_PREFIX . "meumodulo_extradata 
                    WHERE fk_object = " . (int) $object->id . " 
                    AND tipo_object = 'facture' 
                    AND campo = 'num_pedido_compra'";
            $res = $this->db->query($sql);
            if ($res && $this->db->num_rows($res) > 0) {
                $valorSalvo = $this->db->fetch_object($res)->valor;
            }
        }
        
        // Se está criando ou editando, mostra input
        if (in_array($action, array('create', 'edit', 'editline', ''))) {
            $this->resprints .= '<tr>';
            $this->resprints .= '<td>Nº Pedido de Compra</td>';
            $this->resprints .= '<td><input type="text" name="num_pedido_compra" class="flat minwidth200" value="' . 
                                 dol_escape_htmltag($valorSalvo) . '" placeholder="Ex: PO-2026-001"></td>';
            $this->resprints .= '</tr>';
        } else {
            // Modo visualização
            if (!empty($valorSalvo)) {
                $this->resprints .= '<tr>';
                $this->resprints .= '<td>Nº Pedido de Compra</td>';
                $this->resprints .= '<td><strong>' . dol_escape_htmltag($valorSalvo) . '</strong></td>';
                $this->resprints .= '</tr>';
            }
        }
        
        return 0;
    }
    
    /**
     * Salva o campo quando o formulário é submetido
     */
    public function doActions($parameters, &$object, &$action, $hookmanager)
    {
        global $conf, $user, $langs;
        
        if (!in_array($parameters['currentcontext'], array('invoicecard'))) {
            return 0;
        }
        
        // Verifica se a ação é de criação ou atualização
        if (in_array($action, array('add', 'update', 'confirm_valid'))) {
            $numPedido = GETPOST('num_pedido_compra', 'alpha');
            
            // Após a criação, $object->id já está definido
            if (!empty($object->id) && $numPedido !== '') {
                // Upsert (INSERT ou UPDATE)
                $sqlCheck = "SELECT rowid FROM " . MAIN_DB_PREFIX . "meumodulo_extradata 
                             WHERE fk_object = " . (int) $object->id . " 
                             AND tipo_object = 'facture' AND campo = 'num_pedido_compra'";
                $resCheck = $this->db->query($sqlCheck);
                
                if ($resCheck && $this->db->num_rows($resCheck) > 0) {
                    // UPDATE
                    $sql = "UPDATE " . MAIN_DB_PREFIX . "meumodulo_extradata 
                            SET valor = '" . $this->db->escape($numPedido) . "' 
                            WHERE fk_object = " . (int) $object->id . " 
                            AND tipo_object = 'facture' AND campo = 'num_pedido_compra'";
                } else {
                    // INSERT
                    $sql = "INSERT INTO " . MAIN_DB_PREFIX . "meumodulo_extradata 
                            (fk_object, tipo_object, campo, valor) VALUES 
                            (" . (int) $object->id . ", 'facture', 'num_pedido_compra', 
                            '" . $this->db->escape($numPedido) . "')";
                }
                $this->db->query($sql);
            }
        }
        
        return 0;
    }
}

/*
 * TABELA NECESSÁRIA:
 * 
 * CREATE TABLE llx_meumodulo_extradata (
 *     rowid INT AUTO_INCREMENT PRIMARY KEY, 
 *     fk_object INT NOT NULL,
 *     tipo_object VARCHAR(50) NOT NULL,
 *     campo VARCHAR(100) NOT NULL,
 *     valor TEXT,
 *     UNIQUE KEY uk_extra (fk_object, tipo_object, campo)
 * );
 * 
 * E NO DESCRITOR DO MÓDULO (modMeuModulo.class.php):
 * 
 * $this->module_parts = array(
 *     'hooks' => array(
 *         'data' => array('invoicecard'),
 *         'entity' => '0',
 *     ),
 * );
 */
```

---

### Resposta Exercício 13: Trigger de Notificação

Arquivo: `custom/meutarefas/core/triggers/interface_99_modMeuTarefas_LogTrigger.class.php`

```php
<?php
/**
 * Exercício 13 — Trigger que grava logs de eventos de negócio
 * 
 * TABELA NECESSÁRIA:
 * CREATE TABLE llx_exercicio_logs (
 *     rowid INT AUTO_INCREMENT PRIMARY KEY,
 *     evento VARCHAR(100) NOT NULL,
 *     descricao TEXT,
 *     fk_user INT,
 *     date_creation DATETIME DEFAULT CURRENT_TIMESTAMP
 * );
 */
require_once DOL_DOCUMENT_ROOT . '/core/triggers/dolibarrtriggers.class.php';

class InterfaceMeuTarefasLogTrigger extends DolibarrTriggers
{
    public function __construct($db)
    {
        $this->db = $db;
        $this->family = "demo";
        $this->description = "Grava logs de eventos de negócio";
        $this->version = '1.0.0';
        $this->picto = 'meutarefas@meutarefas';
    }

    public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
    {
        // Eventos que queremos monitorar
        $eventosMonitorados = array(
            'COMPANY_CREATE'  => 'Empresa criada',
            'COMPANY_MODIFY'  => 'Empresa modificada',
            'ORDER_CREATE'    => 'Pedido criado',
            'ORDER_VALIDATE'  => 'Pedido validado',
            'BILL_CREATE'     => 'Fatura criada',
            'BILL_VALIDATE'   => 'Fatura validada',
            'BILL_PAYED'      => 'Fatura paga',
        );
        
        // Se o evento não está na lista, ignora
        if (!isset($eventosMonitorados[$action])) {
            return 0;
        }
        
        // Monta descrição detalhada
        $descricao = $eventosMonitorados[$action];
        
        // Adiciona detalhes específicos do objeto
        if (!empty($object->ref)) {
            $descricao .= " | Ref: " . $object->ref;
        }
        if (!empty($object->name)) {
            $descricao .= " | Nome: " . $object->name;
        }
        if (!empty($object->nom)) {
            $descricao .= " | Nome: " . $object->nom;
        }
        if (!empty($object->total_ttc)) {
            $descricao .= " | Valor: R$ " . number_format($object->total_ttc, 2, ',', '.');
        }
        
        // Grava no banco
        $sql = "INSERT INTO " . MAIN_DB_PREFIX . "exercicio_logs 
                (evento, descricao, fk_user, date_creation) VALUES 
                ('" . $this->db->escape($action) . "', 
                 '" . $this->db->escape($descricao) . "', 
                 " . (int) $user->id . ", NOW())";
        
        $this->db->query($sql);
        
        // Log no arquivo do Dolibarr também
        dol_syslog("LogTrigger: " . $action . " - " . $descricao, LOG_INFO);
        
        return 0;
    }
}
```

Arquivo para visualizar os logs: `custom/exercicios/ex13_logs.php`

```php
<?php
/**
 * Exercício 13 — Visualização dos Logs gravados pelo Trigger
 */
require '../../main.inc.php';

if (!$user->id) accessforbidden();

llxHeader('', 'Exercício 13 - Logs de Eventos');

print load_fiche_titre('Logs de Eventos de Negócio', '', 'object_generic');

$page = max(0, GETPOSTINT('page'));
$limit = 25;
$offset = $limit * $page;

// Contagem total
$sqlCount = "SELECT COUNT(*) as total FROM " . MAIN_DB_PREFIX . "exercicio_logs";
$resCount = $db->query($sqlCount);
$totalRows = $resCount ? (int) $db->fetch_object($resCount)->total : 0;

// Barra de paginação
print_barre_liste('Logs', $page, $_SERVER["PHP_SELF"], '', '', '', '', $limit, $totalRows, 'object_generic');

// Query principal
$sql = "SELECT l.rowid, l.evento, l.descricao, l.fk_user, l.date_creation,
               u.login 
        FROM " . MAIN_DB_PREFIX . "exercicio_logs l
        LEFT JOIN " . MAIN_DB_PREFIX . "user u ON u.rowid = l.fk_user
        ORDER BY l.date_creation DESC";
$sql .= $db->plimit($limit, $offset);

$res = $db->query($sql);
$num = $res ? $db->num_rows($res) : 0;

print '<table class="liste noborder centpercent">';
print '<tr class="liste_titre">';
print '<th>ID</th><th>Evento</th><th>Descrição</th><th>Usuário</th><th class="center">Data</th>';
print '</tr>';

if ($num > 0) {
    $i = 0;
    while ($i < $num) {
        $obj = $db->fetch_object($res);
        
        // Cor por tipo de evento
        $corEvento = '#333';
        if (strpos($obj->evento, 'CREATE') !== false) $corEvento = '#28a745';
        if (strpos($obj->evento, 'VALIDATE') !== false) $corEvento = '#007bff';
        if (strpos($obj->evento, 'PAYED') !== false) $corEvento = '#17a2b8';
        if (strpos($obj->evento, 'MODIFY') !== false) $corEvento = '#ffc107';
        
        print '<tr class="oddeven">';
        print '<td>' . $obj->rowid . '</td>';
        print '<td><span style="color:' . $corEvento . ';font-weight:bold;">' . 
              dol_escape_htmltag($obj->evento) . '</span></td>';
        print '<td>' . dol_escape_htmltag($obj->descricao) . '</td>';
        print '<td>' . dol_escape_htmltag($obj->login ?: 'Sistema') . '</td>';
        print '<td class="center">' . dol_print_date($db->jdate($obj->date_creation), 'dayhour') . '</td>';
        print '</tr>';
        
        $i++;
    }
} else {
    print '<tr><td colspan="5" class="opacitymedium center">Nenhum log registrado ainda.</td></tr>';
}

print '</table>';

llxFooter();
$db->close();
```

---

## Referência Rápida

### Objetos Globais
```php
$db      // Banco de dados
$conf    // Configurações
$user    // Usuário logado
$langs   // Traduções
$mysoc   // Empresa principal
```

### Funções Mais Usadas
```php
GETPOST('param', 'tipo')          // Ler parâmetros sanitizados
GETPOSTINT('param')               // Ler inteiro
llxHeader('', 'Título')           // Abrir página
llxFooter()                       // Fechar página
load_fiche_titre(...)             // Título grande
print_barre_liste(...)            // Barra de paginação
print_liste_field_titre(...)      // Cabeçalho de coluna ordenável
dol_escape_htmltag($str)          // Escapar para HTML
dol_print_date($ts, 'format')    // Formatar data
price($valor)                     // Formatar preço
dol_buildpath('/custom/...', 1)   // Construir URL
setEventMessages(...)              // Flash messages
accessforbidden()                 // Negar acesso
dol_now()                         // Timestamp atual
newToken()                        // Token CSRF
isModEnabled('nome')              // Módulo ativo?
$user->hasRight('modulo', 'perm') // Verificar permissão
```

### Estrutura de Tabela SQL
```php
MAIN_DB_PREFIX . "nome_tabela"   // → "llx_nome_tabela"
$db->query($sql)                  // Executar
$db->fetch_object($res)           // Ler linha
$db->num_rows($res)               // Contar linhas
$db->escape($str)                 // Escapar string
$db->last_insert_id($tbl)         // Último ID inserido
$db->plimit($limit, $offset)      // LIMIT/OFFSET
```

---

> **Fim do Curso**  
> **Autor:** Material de estudo gerado automaticamente  
> **Data:** Fevereiro/2026
