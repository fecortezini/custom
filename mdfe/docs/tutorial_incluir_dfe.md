# 📘 Tutorial Completo: Como Funciona a Modal "Incluir NF-e" em MDF-e

> **Nível:** Intermediário em PHP  
> **Pré-requisitos:** HTML/CSS básico, JavaScript (fetch API), noções de banco de dados SQL  
> **Objetivo:** Entender cada camada da funcionalidade de inclusão de DF-e (NF-e) em uma MDF-e autorizada — da modal no frontend até a comunicação com a SEFAZ no backend.

---

## Índice

1. [Visão Geral da Arquitetura](#1-visão-geral-da-arquitetura)
2. [Parte 1: O HTML da Modal (Frontend)](#2-parte-1-o-html-da-modal-frontend)
3. [Parte 2: O CSS da Modal](#3-parte-2-o-css-da-modal)
4. [Parte 3: O JavaScript (Controle da Modal + AJAX)](#4-parte-3-o-javascript-controle-da-modal--ajax)
5. [Parte 4: O Backend — Arquivo mdfe_incluir_dfe.php](#5-parte-4-o-backend--arquivo-mdfe_incluir_dfephp)
6. [Parte 5: Fluxo Completo Passo a Passo](#6-parte-5-fluxo-completo-passo-a-passo)
7. [Parte 6: Banco de Dados — Tabelas Envolvidas](#7-parte-6-banco-de-dados--tabelas-envolvidas)
8. [Parte 7: Padrões e Boas Práticas Utilizados](#8-parte-7-padrões-e-boas-práticas-utilizados)
9. [Como Reproduzir: Guia Passo a Passo](#9-como-reproduzir-guia-passo-a-passo)
10. [Exercícios Práticos](#10-exercícios-práticos)

---

## 1. Visão Geral da Arquitetura

A funcionalidade "Incluir NF-e em MDF-e" usa o padrão **Modal + AJAX + Endpoint PHP separado**. Veja o diagrama:

```
┌─────────────────────────────────────────────────────────────────┐
│                      NAVEGADOR (Frontend)                       │
│                                                                 │
│  ┌─────────────┐    ┌──────────────┐    ┌────────────────────┐  │
│  │ Botão       │───>│ Modal HTML   │───>│ JavaScript (fetch) │  │
│  │ "Incluir    │    │ com          │    │ Envia dados via    │  │
│  │  NF-e"      │    │ formulários  │    │ AJAX POST          │  │
│  └─────────────┘    └──────────────┘    └────────┬───────────┘  │
│                                                  │              │
└──────────────────────────────────────────────────┼──────────────┘
                                                   │
                        Requisição AJAX (POST)     │
                        X-Requested-With:          │
                        XMLHttpRequest             │
                                                   ▼
┌─────────────────────────────────────────────────────────────────┐
│                   SERVIDOR (Backend PHP)                         │
│                                                                 │
│  ┌──────────────────┐   ┌───────────┐   ┌───────────────────┐  │
│  │ mdfe_incluir_    │──>│ Valida    │──>│ NFePHP\MDFe\Tools │  │
│  │ dfe.php          │   │ dados +   │   │ Envia XML à SEFAZ │  │
│  │ (Endpoint AJAX)  │   │ banco     │   │                   │  │
│  └──────────────────┘   └───────────┘   └────────┬──────────┘  │
│                                                  │              │
│                                         ┌────────▼──────────┐  │
│                                         │ SEFAZ responde    │  │
│                                         │ cStat 135 = OK    │  │
│                                         └────────┬──────────┘  │
│                                                  │              │
│                                         ┌────────▼──────────┐  │
│                                         │ Grava no banco:   │  │
│                                         │ - mdfe_eventos    │  │
│                                         │ - mdfe_inclusao_  │  │
│                                         │   nfe             │  │
│                                         └───────────────────┘  │
│                                                                 │
└─────────────────────────────────────────────────────────────────┘
```

**Resumo do fluxo:**
1. Usuário clica em "Incluir NF-e" no dropdown de ações de uma MDF-e autorizada
2. Uma modal aparece com campos: UF + cidade de carregamento, UF + cidade de descarga, chave NF-e
3. Ao selecionar uma UF, JavaScript faz AJAX GET para buscar cidades (endpoint `buscar_cidades`)
4. Ao clicar "Incluir NF-e", JavaScript faz AJAX POST para o endpoint `incluir_dfe`
5. O PHP valida, monta XML, envia à SEFAZ via biblioteca NFePHP, grava resultado no banco
6. Se sucesso, a modal fecha e a página recarrega

---

## 2. Parte 1: O HTML da Modal (Frontend)

A modal é renderizada pelo PHP dentro de `mdfe_list.php`. Vamos ver cada pedaço:

### 2.1 A Estrutura Overlay + Box

```php
print '<div id="mdfeIncluirDfeModal" class="mdfe-inc-overlay" role="dialog" aria-modal="true">';
print '  <div class="mdfe-inc-box">';
```

**O que está acontecendo:**
- `id="mdfeIncluirDfeModal"` — identificador único para o JavaScript encontrar e manipular
- `class="mdfe-inc-overlay"` — cobre toda a tela com fundo escuro semi-transparente
- `role="dialog" aria-modal="true"` — acessibilidade: informa leitores de tela que é uma caixa de diálogo
- A div `.mdfe-inc-box` é o "cartão" central branco da modal

> **Conceito importante:** Usamos uma div de overlay (que cobre toda a tela) e dentro dela uma div box (que é a "caixa" visível). Isso é o padrão mais comum de modais na web.

### 2.2 O Header da Modal

```php
print '    <div class="mdfe-inc-header">';
print '      <div><strong>Incluir NF-e</strong> <small id="incDfeChaveResumo"></small></div>';
print '      <button class="mdfe-inc-close" onclick="closeIncluirDfeModal()" aria-label="Fechar">&times;</button>';
print '    </div>';
```

**O que está acontecendo:**
- Título fixo "Incluir NF-e"
- `<small id="incDfeChaveResumo">` — espaço reservado para exibir informações extras (se necessário)
- O botão `&times;` (×) chama `closeIncluirDfeModal()` para fechar a modal
- `aria-label="Fechar"` — acessibilidade para leitores de tela

### 2.3 O Body da Modal — Campos de Formulário

Aqui é onde o formulário vive. Vamos dissecar cada seção:

#### Seção: Município de Carregamento

```php
// Array de UFs brasileiras para os selects
$ufsIncDfe = ['AC','AL','AM','AP','BA','CE','DF','ES','GO','MA','MG','MS','MT',
              'PA','PB','PE','PI','PR','RJ','RN','RO','RR','RS','SC','SE','SP','TO'];

// Título visual da seção
print '      <div class="mdfe-inc-sep">Município de Carregamento</div>';

// Linha com dois campos lado a lado
print '      <div class="mdfe-inc-row">';

// Campo 1: Select de UF
print '        <div class="mdfe-inc-field">';
print '          <label class="mdfe-inc-label" for="incDfeUfCarrega">UF <span>*</span></label>';
print '          <select id="incDfeUfCarrega" class="mdfe-inc-select" 
                         onchange="incDfeCarregarCidades(\'carrega\')">';
print '            <option value="">Selecione...</option>';
foreach ($ufsIncDfe as $_uf) {
    print '            <option value="'.$_uf.'">'.$_uf.'</option>';
}
print '          </select>';
print '        </div>';

// Campo 2: Select de Município (começa desabilitado)
print '        <div class="mdfe-inc-field">';
print '          <label class="mdfe-inc-label" for="incDfeMunCarrega">Município <span>*</span></label>';
print '          <select id="incDfeMunCarrega" class="mdfe-inc-select" disabled>';
print '            <option value="">Selecione a UF primeiro</option>';
print '          </select>';
print '        </div>';
print '      </div>';
```

**O que está acontecendo:**

1. **O select de UF** — Quando o usuário troca a UF, o evento `onchange` chama `incDfeCarregarCidades('carrega')`. Essa função JavaScript faz um AJAX para buscar as cidades daquela UF.

2. **O select de Município** — Começa `disabled` e com texto "Selecione a UF primeiro". Só é habilitado após o JavaScript carregar as cidades via AJAX.

3. **O `<span>*</span>`** — Indica campo obrigatório (o asterisco vermelho, via CSS `.mdfe-inc-label span{color:#c00;}`).

> **Padrão "Select dependente":** Quando um select depende de outro (UF → Cidade), você usa o evento `onchange` no primeiro para popular o segundo via AJAX. Isso evita carregar TODAS as cidades do Brasil de uma vez.

#### Seção: Chave NF-e

```php
print '      <div class="mdfe-inc-sep">Documento Fiscal</div>';
print '      <div style="margin-top:8px;">';
print '        <label class="mdfe-inc-label" for="incDfeChNFe">
                  Chave NF-e (44 dígitos) <span>*</span>
                </label>';
print '        <input type="text" id="incDfeChNFe" class="mdfe-inc-input" maxlength="44" 
                      placeholder="00000000000000000000000000000000000000000000" 
                      style="font-family:Consolas,monospace;letter-spacing:1px;" 
                      oninput="document.getElementById(\'incDfeChLen\').textContent=this.value.length">';
print '        <div style="font-size:.75em;color:#aaa;text-align:right;margin-top:2px;">
                  <span id="incDfeChLen">0</span> / 44
                </div>';
print '      </div>';
```

**O que está acontecendo:**
- `maxlength="44"` — limita a 44 caracteres no HTML
- `font-family:Consolas,monospace` — usa fonte monoespaçada (boa para números)
- `letter-spacing:1px` — espaçamento entre caracteres para facilitar leitura
- `oninput` — a cada digitação, atualiza o contador "0 / 44" em tempo real
- O contador mostra quantos dígitos foram digitados

### 2.4 O Footer da Modal (Botões de Ação)

```php
print '    <div class="mdfe-inc-footer">';
print '      <button class="butActionDelete" onclick="closeIncluirDfeModal()">Fechar</button>';
print '      <button class="butAction" id="incDfeEnviarBtn" 
                      onclick="confirmarIncluirDfe()">Incluir NF-e</button>';
print '    </div>';
```

**O que está acontecendo:**
- `butActionDelete` — classe Dolibarr para botões de ação secundária (cinza/vermelho)
- `butAction` — classe Dolibarr para botões de ação principal (azul)
- O botão principal tem `id="incDfeEnviarBtn"` porque o JavaScript vai manipulá-lo (desabilitar durante envio, mudar texto)

---

## 3. Parte 2: O CSS da Modal

```css
/* Overlay — cobre toda a tela */
.mdfe-inc-overlay {
    position: fixed;      /* Fixo na viewport (não scrolla) */
    inset: 0;             /* top:0; right:0; bottom:0; left:0 — cobre tudo */
    background: rgba(0,0,0,.45);  /* Fundo preto semi-transparente */
    display: none;        /* Começa invisível */
    align-items: center;  /* Centraliza verticalmente */
    justify-content: center; /* Centraliza horizontalmente */
    z-index: 200001;      /* Fica acima de tudo */
}

/* Quando visível, muda display para flex */
.mdfe-inc-overlay.visible {
    display: flex;
}

/* A caixa branca da modal */
.mdfe-inc-box {
    background: #fff;
    border: 1px solid #c8c8c8;
    border-radius: 6px;
    box-shadow: 0 4px 24px rgba(0,0,0,.18);
    max-width: 520px;     /* Largura máxima */
    width: 95%;           /* Responsivo em telas menores */
    display: flex;
    flex-direction: column;
}
```

**Conceitos-chave do CSS:**

| Propriedade | O que faz | Por que usar |
|---|---|---|
| `position: fixed` | Fixa na janela, não no documento | A modal não se move quando você scrolla a página |
| `inset: 0` | Atalho para top/right/bottom/left = 0 | Cobre toda a viewport |
| `display: none` → `display: flex` | Controla visibilidade | Começa oculta, fica visível ao adicionar classe `.visible` |
| `z-index: 200001` | Camada de sobreposição | Garante que fica acima de dropdowns, menus, etc. |
| `rgba(0,0,0,.45)` | Cor com transparência | O fundo escuro que "apaga" o conteúdo por trás |

---

## 4. Parte 3: O JavaScript (Controle da Modal + AJAX)

### 4.1 Variáveis Globais

```javascript
var _mdfeIncluirDfeId = null;       // Armazena o ID da MDF-e sendo editada
var _incDfeCidadesCache = {};       // Cache de cidades por UF (evita requisições repetidas)
```

**Por que variável global?**  
Porque várias funções precisam acessar o ID da MDF-e: `openIncluirDfeModal()`, `confirmarIncluirDfe()`, `closeIncluirDfeModal()`. Como estão em funções separadas, o ID fica numa variável global.

**Por que cache de cidades?**  
Se o usuário seleciona "ES", as cidades são buscadas via AJAX. Se ele troca para "MG" e depois volta para "ES", o cache evita fazer outra requisição HTTP. Isso melhora a performance.

### 4.2 Abrindo a Modal

```javascript
function openIncluirDfeModal(id) {
    // 1. Fecha todos os dropdowns abertos
    document.querySelectorAll(".nfe-dropdown-menu")
            .forEach(function(m){ m.style.display = "none"; });
    
    // 2. Salva o ID da MDF-e
    _mdfeIncluirDfeId = id;
    
    // 3. Reseta todos os campos do formulário
    document.getElementById("incDfeUfCarrega").value = "";
    document.getElementById("incDfeUfDescarga").value = "";
    
    var munC = document.getElementById("incDfeMunCarrega");
    munC.innerHTML = "<option value=''>Selecione a UF primeiro</option>";
    munC.disabled = true;
    
    var munD = document.getElementById("incDfeMunDescarga");
    munD.innerHTML = "<option value=''>Selecione a UF primeiro</option>";
    munD.disabled = true;
    
    document.getElementById("incDfeChNFe").value = "";
    document.getElementById("incDfeChLen").textContent = "0";
    
    // 4. Reseta o botão de envio
    var btn = document.getElementById("incDfeEnviarBtn");
    if (btn) {
        btn.disabled = false;
        btn.style.opacity = "";
        btn.textContent = "Incluir NF-e";
    }
    
    // 5. Mostra a modal adicionando a classe "visible"
    var overlay = document.getElementById("mdfeIncluirDfeModal");
    if (overlay) overlay.classList.add("visible");
}
```

**Importante:** Sempre que abrir uma modal, resete TODOS os campos. Caso contrário, se o usuário fechou e reabriu, os dados antigos ainda estariam lá.

### 4.3 O Coração: Selects Dependentes via AJAX

```javascript
function incDfeCarregarCidades(tipo) {
    // 1. Determina qual par de selects usar (carrega ou descarga)
    var sufixo = tipo === "carrega" ? "Carrega" : "Descarga";
    var ufSel = document.getElementById("incDfeUf" + sufixo);
    var munSel = document.getElementById("incDfeMun" + sufixo);
    var uf = ufSel ? ufSel.value : "";
    
    // 2. Se nenhuma UF selecionada, reseta o select de município
    if (!uf) {
        munSel.innerHTML = "<option value=''>Selecione a UF primeiro</option>";
        munSel.disabled = true;
        return;
    }
    
    // 3. Verifica o cache antes de fazer AJAX
    if (_incDfeCidadesCache[uf]) {
        incDfePreencherMunicipios(munSel, _incDfeCidadesCache[uf]);
        return;  // Sem requisição HTTP! 🎯
    }
    
    // 4. Mostra "Carregando..." enquanto busca
    munSel.innerHTML = "<option value=''>Carregando...</option>";
    munSel.disabled = true;
    
    // 5. Faz a requisição AJAX GET
    fetch("/custom/mdfe/mdfe_incluir_dfe.php?action=buscar_cidades&uf=" + encodeURIComponent(uf), {
        headers: { "X-Requested-With": "XMLHttpRequest" },  // ← Identifica como AJAX
        credentials: "same-origin"                           // ← Envia cookies de sessão
    })
    .then(function(r) {
        if (!r.ok) throw new Error("Erro HTTP " + r.status);
        return r.json();  // ← Espera resposta JSON
    })
    .then(function(data) {
        if (!data.success) throw new Error(data.error || "Erro");
        // 6. Salva no cache
        _incDfeCidadesCache[uf] = data.cidades;
        // 7. Popula o select
        incDfePreencherMunicipios(munSel, data.cidades);
    })
    .catch(function(err) {
        munSel.innerHTML = "<option value=''>Erro ao carregar</option>";
        munSel.disabled = true;
    });
}
```

**Detalhamento da Fetch API:**

| Parte | O que faz |
|---|---|
| `fetch(url, options)` | Faz requisição HTTP assíncrona |
| `headers: {"X-Requested-With": "XMLHttpRequest"}` | Identifica a requisição como AJAX. O backend verifica isso para saber se deve retornar JSON |
| `credentials: "same-origin"` | Envia cookies da sessão do Dolibarr. Sem isso, o PHP não reconhece o usuário logado |
| `.then(r => r.json())` | Converte a resposta para objeto JavaScript |
| `.catch()` | Captura erros de rede ou do servidor |

### 4.4 Preenchendo o Select de Municípios

```javascript
function incDfePreencherMunicipios(selectEl, cidades) {
    var html = "<option value=''>Selecione...</option>";
    for (var i = 0; i < cidades.length; i++) {
        html += "<option value='" + cidades[i].nome + "'>" + cidades[i].nome + "</option>";
    }
    selectEl.innerHTML = html;
    selectEl.disabled = false;  // Habilita o select
}
```

**Nota:** O `value` de cada option é o **nome** da cidade (ex: "VITÓRIA"), não o código IBGE. O código IBGE é resolvido no backend. Isso simplifica o frontend.

### 4.5 Enviando os Dados (AJAX POST)

```javascript
function confirmarIncluirDfe() {
    if (!_mdfeIncluirDfeId) return;  // Segurança
    
    var notice = document.getElementById("incDfeNotice");
    var btn    = document.getElementById("incDfeEnviarBtn");

    // 1. Coleta todos os valores
    var ufCarrega   = document.getElementById("incDfeUfCarrega").value;
    var munCarrega  = document.getElementById("incDfeMunCarrega").value;
    var ufDescarga  = document.getElementById("incDfeUfDescarga").value;
    var munDescarga = document.getElementById("incDfeMunDescarga").value;
    var chNFe       = document.getElementById("incDfeChNFe").value.trim();

    // 2. Validação no frontend (antes do envio)
    if (!ufCarrega || !munCarrega) {
        notice.className = "mdfe-inc-notice error";
        notice.textContent = "Selecione a UF e o município de carregamento.";
        return;  // Para aqui, não envia
    }
    if (chNFe.length !== 44 || !/^[0-9]{44}$/.test(chNFe)) {
        notice.className = "mdfe-inc-notice error";
        notice.textContent = "A chave da NF-e deve ter exatamente 44 dígitos numéricos.";
        document.getElementById("incDfeChNFe").focus();
        return;
    }

    // 3. Desabilita o botão (evita duplo clique)
    btn.disabled = true;
    btn.style.opacity = "0.6";
    btn.textContent = "Enviando...";
    notice.textContent = "Enviando à SEFAZ, aguarde...";

    // 4. Envia via AJAX POST
    fetch("/custom/mdfe/mdfe_incluir_dfe.php", {
        method: "POST",
        headers: {
            "X-Requested-With": "XMLHttpRequest",
            "Content-Type": "application/x-www-form-urlencoded"
        },
        credentials: "same-origin",
        body: "action=incluir_dfe"
            + "&token=" + encodeURIComponent("TOKEN_CSRF")
            + "&id=" + encodeURIComponent(_mdfeIncluirDfeId)
            + "&uf_carrega=" + encodeURIComponent(ufCarrega)
            + "&mun_carrega=" + encodeURIComponent(munCarrega)
            + "&uf_descarga=" + encodeURIComponent(ufDescarga)
            + "&mun_descarga=" + encodeURIComponent(munDescarga)
            + "&chNFe=" + encodeURIComponent(chNFe)
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            closeIncluirDfeModal();
            window.location.reload();  // ← Recarrega para mostrar novo estado
        } else {
            // Mostra erro e restaura botão
            notice.className = "mdfe-inc-notice error";
            notice.textContent = data.error || "Erro desconhecido.";
            btn.disabled = false;
            btn.textContent = "Tentar Novamente";
        }
    })
    .catch(function(err) {
        notice.textContent = "Erro de comunicação: " + err.message;
        btn.disabled = false;
        btn.textContent = "Tentar Novamente";
    });
}
```

**Pontos críticos explicados:**

1. **Validação dupla** — Primeiro no JavaScript (experiência rápida para o usuário), depois no PHP (segurança real). NUNCA confie apenas na validação do frontend.

2. **Desabilitar botão** — Evita que o usuário clique duas vezes e envie duas requisições à SEFAZ (o que pode causar sequência duplicada).

3. **`Content-Type: application/x-www-form-urlencoded`** — Formato padrão de formulários. O PHP lê os dados via `$_POST` / `GETPOST()`.

4. **`encodeURIComponent()`** — Codifica caracteres especiais (acentos, &, =, etc.) para não quebrar a URL.

5. **`window.location.reload()`** — Após sucesso, recarrega a página. A flash message do Dolibarr (`setEventMessages`) aparece no reload.

### 4.6 Fechando a Modal

```javascript
function closeIncluirDfeModal() {
    var overlay = document.getElementById("mdfeIncluirDfeModal");
    if (overlay) overlay.classList.remove("visible");
    _mdfeIncluirDfeId = null;  // Limpa o ID
}

// Fecha ao clicar no overlay (fora da box)
document.addEventListener("click", function(e) {
    var overlay = document.getElementById("mdfeIncluirDfeModal");
    if (overlay && e.target === overlay) closeIncluirDfeModal();
});

// Fecha ao pressionar ESC
document.addEventListener("keydown", function(e) {
    if ((e.key === "Escape" || e.key === "Esc") && _mdfeIncluirDfeId !== null) {
        closeIncluirDfeModal();
    }
});
```

**Três formas de fechar:**
1. Botão "Fechar" (onclick)
2. Clicar fora da modal (no overlay escuro)
3. Pressionar tecla ESC

---

## 5. Parte 4: O Backend — Arquivo mdfe_incluir_dfe.php

Este é o arquivo PHP que recebe as requisições AJAX. Vamos analisar cada parte:

### 5.1 Configuração Inicial

```php
<?php
// Silencia warnings para não poluir o JSON
@ini_set('display_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

// O main.inc.php do Dolibarr carrega TUDO: banco de dados ($db), 
// configurações ($conf), idiomas ($langs), usuário ($user)
require_once '../../main.inc.php';

// Bibliotecas externas
require_once __DIR__ . '/../composerlib/vendor/autoload.php';   // NFePHP
require_once DOL_DOCUMENT_ROOT . '/custom/labapp/lib/ibge_utils.php';  // Busca IBGE
require_once DOL_DOCUMENT_ROOT . '/custom/mdfe/lib/certificate_security.lib.php'; // Certificado

use NFePHP\MDFe\Tools;
use NFePHP\MDFe\Common\Standardize;
```

**Por que silenciar warnings?**  
Porque se um `E_NOTICE` ou `E_WARNING` sair antes do JSON, a resposta deixa de ser JSON válido e o JavaScript quebra com erro de parse.

**`require_once '../../main.inc.php'`** — Linha mais importante! Carrega todo o framework Dolibarr. Após isso, você tem acesso a:
- `$db` — Connexão com banco de dados
- `$conf` — Configurações do sistema
- `$user` — Usuário logado
- `$langs` — Sistema de tradução
- `GETPOST()` — Função segura para pegar parâmetros $_GET/$_POST

### 5.2 Proteção: Apenas Requisições AJAX

```php
if (
    empty($_SERVER['HTTP_X_REQUESTED_WITH'])
    || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest'
) {
    http_response_code(403);
    exit('Acesso negado.');
}

header('Content-Type: application/json; charset=UTF-8');
```

**O que isso faz:**
- Verifica se o header `X-Requested-With: XMLHttpRequest` está presente
- Se alguém tentar acessar a URL diretamente no navegador, recebe 403
- Define que toda resposta será JSON

> **Segurança:** Isso não é 100% seguro (o header pode ser forjado), mas previne acessos acidentais. Para segurança real, use tokens CSRF (que já é usado via `newToken()`).

### 5.3 Funções Utilitárias de Resposta

```php
function jsonError(string $msg, int $httpCode = 200): void
{
    echo json_encode(['success' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function jsonSuccess(array $extra = []): void
{
    echo json_encode(array_merge(['success' => true], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}
```

**Por que criar essas funções?**
- **Padronização:** Toda resposta sempre tem `success: true/false`
- **Conveniência:** Um `exit` no final garante que nada mais é executado
- **`JSON_UNESCAPED_UNICODE`:** Permite acentos no JSON (ex: "Município não encontrado")

### 5.4 Roteamento por Action

```php
$action = GETPOST('action', 'alpha');
```

O parâmetro `action` define qual bloco de código será executado. É como um mini-roteador:

- `action=buscar_cidades` → Retorna lista de cidades
- `action=incluir_dfe` → Processa a inclusão

### 5.5 Action: buscar_cidades

```php
if ($action === 'buscar_cidades') {
    $uf = strtoupper(trim(GETPOST('uf', 'alpha')));
    
    // Validação
    if (strlen($uf) !== 2) {
        jsonError('Informe uma UF válida (2 letras).');
    }

    // Consulta SQL — busca municípios ativos da UF
    $sql = "SELECT codigo_ibge, nome
            FROM " . MAIN_DB_PREFIX . "estados_municipios_ibge
            WHERE active = 1 AND uf = '" . $db->escape($uf) . "'
            ORDER BY nome ASC";
    
    $res = $db->query($sql);
    if (!$res) {
        jsonError('Erro ao consultar municípios: ' . $db->lasterror());
    }

    // Monta array de resultados
    $cidades = [];
    while ($obj = $db->fetch_object($res)) {
        $cidades[] = [
            'codigo_ibge' => $obj->codigo_ibge,
            'nome'        => $obj->nome,
        ];
    }
    
    // Retorna JSON com array de cidades
    jsonSuccess(['cidades' => $cidades]);
}
```

**Detalhes importantes:**

| Detalhe | Explicação |
|---|---|
| `GETPOST('uf', 'alpha')` | `GETPOST` é do Dolibarr. O segundo parâmetro `'alpha'` sanitiza, permitindo apenas letras |
| `MAIN_DB_PREFIX` | Constante do Dolibarr (geralmente `llx_`). Prefixo das tabelas |
| `$db->escape($uf)` | Previne SQL Injection escapando caracteres perigosos |
| `$db->query($sql)` | Executa SQL e retorna resource |
| `$db->fetch_object($res)` | Pega próxima linha como objeto |

### 5.6 Action: incluir_dfe (O Principal)

Esta é a lógica mais complexa. Vamos por etapas:

#### Etapa 1: Coletar e Validar Dados

```php
if ($action === 'incluir_dfe') {
    $mdfeId       = (int) GETPOST('id', 'int');
    $ufCarrega    = strtoupper(trim(GETPOST('uf_carrega', 'alpha')));
    $munCarrega   = trim(GETPOST('mun_carrega', 'restricthtml'));
    $ufDescarga   = strtoupper(trim(GETPOST('uf_descarga', 'alpha')));
    $munDescarga  = trim(GETPOST('mun_descarga', 'restricthtml'));
    $chNFe        = preg_replace('/\D/', '', trim(GETPOST('chNFe', 'restricthtml')));

    // Validações (retorna erro e para execução imediatamente)
    if ($mdfeId <= 0)              jsonError('ID da MDF-e inválido.');
    if (strlen($ufCarrega) !== 2)  jsonError('Selecione a UF de carregamento.');
    if (empty($munCarrega))        jsonError('Selecione o município de carregamento.');
    if (strlen($chNFe) !== 44)     jsonError('A chave da NF-e deve ter 44 dígitos.');
    if (!ctype_digit($chNFe))      jsonError('A chave da NF-e deve conter apenas números.');
```

**Sobre `preg_replace('/\D/', '', ...)`:**  
Remove tudo que NÃO é dígito. Se o usuário colar "1234-5678...", fica "12345678".

**Sobre `ctype_digit($chNFe)`:**  
Verifica se TODOS os caracteres são dígitos numéricos.

#### Etapa 2: Verificar MDF-e no Banco

```php
    $sqlMdfe = "SELECT * FROM " . MAIN_DB_PREFIX . "mdfe_emitidas WHERE id = " . $mdfeId;
    $resMdfe = $db->query($sqlMdfe);
    if (!$resMdfe || $db->num_rows($resMdfe) === 0) {
        jsonError('MDF-e não encontrada.');
    }
    $mdfe = $db->fetch_object($resMdfe);

    // Regras de negócio
    if (strtolower($mdfe->status) !== 'autorizada') {
        jsonError('Só é possível incluir DF-e em MDF-e autorizada.');
    }
    if (empty($mdfe->chave_acesso)) jsonError('Chave de acesso não encontrada.');
    if (empty($mdfe->protocolo))    jsonError('Protocolo de autorização não encontrado.');
```

**Regra de negócio:** Só MDF-e com status "autorizada" aceita inclusão de NF-e. Cancelada, encerrada ou rejeitada não permite.

#### Etapa 3: Resolver Códigos IBGE dos Municípios

```php
    $ibgeCarrega = buscarDadosIbge($db, $munCarrega, $ufCarrega);
    if (!$ibgeCarrega) {
        jsonError("Município de carregamento '{$munCarrega}' não encontrado na base IBGE.");
    }

    $ibgeDescarga = buscarDadosIbge($db, $munDescarga, $ufDescarga);
    if (!$ibgeDescarga) {
        jsonError("Município de descarga '{$munDescarga}' não encontrado na base IBGE.");
    }
```

A SEFAZ exige **códigos numéricos IBGE** (ex: "3205309" para Vitória/ES). A função `buscarDadosIbge()` converte nome → código.

#### Etapa 4: Calcular Sequência do Evento

```php
    $sqlSeq = "SELECT COALESCE(MAX(nSeqEvento), 0) AS ultimo
               FROM " . MAIN_DB_PREFIX . "mdfe_eventos
               WHERE fk_mdfe_emitida = " . $mdfeId . "
               AND tpEvento = '110115'";
    $resSeq = $db->query($sqlSeq);
    $ultimoSeq = 0;
    if ($resSeq && $db->num_rows($resSeq) > 0) {
        $ultimoSeq = (int) $db->fetch_object($resSeq)->ultimo;
    }
    $nSeqEvento = $ultimoSeq + 1;
```

**O que é `nSeqEvento`?**  
Cada evento de uma MDF-e tem um número sequencial. Se já foram feitos 2 eventos do tipo 110115, o próximo será 3. A SEFAZ rejeita se você enviar a mesma sequência duas vezes.

**`COALESCE(MAX(...), 0)`** — Se não houver nenhum evento, retorna 0 (em vez de NULL).

#### Etapa 5: Configurar e Enviar à SEFAZ

```php
    try {
        // Busca ambiente (1=produção, 2=homologação)
        $ambienteVal = 2;
        $resAmb = $db->query("SELECT value FROM " . MAIN_DB_PREFIX . "nfe_config WHERE name = 'ambiente'");
        if ($resAmb && $db->num_rows($resAmb) > 0) {
            $ambienteVal = (int) $db->fetch_object($resAmb)->value;
        }

        // Dados do emitente (empresa) — vem do objeto global $mysoc
        global $mysoc;
        if (empty($mysoc->id)) $mysoc->fetch(0);
        $cnpj = preg_replace('/\D/', '', $mysoc->idprof1 ?? '');

        // Array de configuração para a lib NFePHP
        $configMdfe = [
            'atualizacao' => date('Y-m-d H:i:s'),
            'tpAmb'       => $ambienteVal,
            'razaosocial' => $mysoc->name ?? '',
            'cnpj'        => $cnpj,
            'ie'          => preg_replace('/\D/', '', $mysoc->idprof3 ?? ''),
            'siglaUF'     => $mysoc->state_code ?? 'ES',
            'versao'      => '3.00',
        ];

        // Carrega certificado digital A1
        $cert = incDfe_carregarCertificado($db);
        
        // Instancia o Tools da NFePHP com configuração + certificado
        $tools = new Tools(json_encode($configMdfe), $cert);

        // Array com dados do documento fiscal a incluir
        $infDoc = [
            [
                'cMunDescarga' => $ibgeDescarga->codigo_ibge,
                'xMunDescarga' => strtoupper($ibgeDescarga->nome),
                'chNFe'        => $chNFe,
            ],
        ];

        // CHAMADA PRINCIPAL: envia o evento 110115 à SEFAZ
        $resp = $tools->sefazIncluiDFe(
            $mdfe->chave_acesso,     // Chave da MDF-e
            $mdfe->protocolo,        // Protocolo de autorização
            $ibgeCarrega->codigo_ibge, // Código IBGE município de carregamento
            strtoupper($ibgeCarrega->nome), // Nome município
            $infDoc,                 // Documentos a incluir
            (string) $nSeqEvento     // Sequência do evento
        );
```

**Sobre `$mysoc`:**  
É o objeto global do Dolibarr que representa a **empresa principal** (dados societários). Os dados vêm de Configuração → Empresa: 
- `idprof1` = CNPJ
- `idprof3` = Inscrição Estadual

#### Etapa 6: Processar Resposta da SEFAZ

```php
        $st  = new Standardize();
        $std = $st->toStd($resp);   // Converte XML de resposta → objeto stdClass

        $cStat       = (int) ($std->infEvento->cStat      ?? 0);
        $xMotivo     = $std->infEvento->xMotivo            ?? 'Resposta inválida';
        $nProtEvt    = $std->infEvento->nProt              ?? '';
        $dhRegEvento = $std->infEvento->dhRegEvento        ?? date('Y-m-d\TH:i:sP');

        // cStat 135 = evento registrado com sucesso
        if ($cStat !== 135) {
            jsonError("$xMotivo");  // Mostra o motivo da SEFAZ
        }
```

**Tabela de cStat comuns:**

| cStat | Significado |
|---|---|
| 135 | Evento registrado e vinculado (sucesso!) |
| 573 | Evento já registrado (duplicado) |
| 215 | Validação de schema com erro |
| 594 | Evento não permitido para MDF-e no status atual |

#### Etapa 7: Gravar no Banco de Dados

```php
        // Tabela genérica de eventos
        $sqlInsert = "INSERT INTO " . MAIN_DB_PREFIX . "mdfe_eventos
            (fk_mdfe_emitida, tpEvento, nSeqEvento, protocolo_evento, 
             motivo_evento, data_evento, xml_requisicao, xml_resposta, xml_evento_completo)
            VALUES (...)";
        $db->query($sqlInsert);

        // Tabela específica de inclusões de NF-e
        $sqlInsertNfe = "INSERT INTO " . MAIN_DB_PREFIX . "mdfe_inclusao_nfe
            (fk_mdfe_emitida, chave_mdfe, protocolo_mdfe, nSeqEvento,
             cMunCarrega, xMunCarrega, cMunDescarga, xMunDescarga, chNFe,
             protocolo_evento, cStat, xMotivo, data_evento,
             xml_requisicao, xml_resposta)
            VALUES (...)";
        $db->query($sqlInsertNfe);

        // Flash message para aparecer após reload
        setEventMessages('NF-e incluída com sucesso!', null, 'mesgs');

        // Retorna sucesso
        jsonSuccess([
            'cStat'      => $cStat,
            'xMotivo'    => $xMotivo,
            'protocolo'  => $nProtEvt,
            'nSeqEvento' => $nSeqResp,
            'chNFe'      => $chNFe,
        ]);
    } catch (Exception $e) {
        error_log('[MDF-e IncluirDFe] ' . $e->getMessage());
        jsonError($e->getMessage());
    }
}
```

**Duas tabelas? Por quê?**
- `mdfe_eventos` — Tabela genérica para TODOS os tipos de evento (cancelamento, encerramento, inclusão, etc.)
- `mdfe_inclusao_nfe` — Tabela específica com dados detalhados da inclusão de NF-e

Isso é um padrão de "tabela geral + tabela específica", útil quando você tem vários tipos de eventos com campos diferentes.

---

## 6. Parte 5: Fluxo Completo Passo a Passo

```
1. Usuário clica "Incluir NF-e" na linha da MDF-e #42
   ↓
2. JavaScript chama openIncluirDfeModal(42)
   → Salva _mdfeIncluirDfeId = 42
   → Reseta campos
   → Mostra modal (classList.add("visible"))
   ↓
3. Usuário seleciona UF "ES" no carregamento
   ↓
4. JavaScript chama incDfeCarregarCidades('carrega')
   → Verifica cache → não tem
   → Faz fetch GET para mdfe_incluir_dfe.php?action=buscar_cidades&uf=ES
   ↓
5. PHP: action=buscar_cidades
   → Valida UF (2 letras) → OK
   → SQL: SELECT codigo_ibge, nome FROM llx_estados_municipios_ibge WHERE uf='ES'
   → Retorna JSON: { success: true, cidades: [{codigo_ibge: "3200102", nome: "AFONSO CLÁUDIO"}, ...] }
   ↓
6. JavaScript recebe JSON
   → Salva no cache _incDfeCidadesCache["ES"] = [...]
   → Popula select de municípios
   → Habilita o select
   ↓
7. Usuário seleciona "VITÓRIA", preenche UF descarga, município descarga, chave NF-e
   ↓
8. Usuário clica "Incluir NF-e"
   ↓
9. JavaScript chama confirmarIncluirDfe()
   → Valida campos no frontend
   → Desabilita botão
   → Faz fetch POST para mdfe_incluir_dfe.php com action=incluir_dfe
   ↓
10. PHP: action=incluir_dfe
    → Valida todos os campos novamente
    → Busca MDF-e no banco (status = autorizada?)
    → Resolve códigos IBGE dos municípios
    → Calcula nSeqEvento = último + 1
    → Monta configuração NFePHP
    → Carrega certificado digital
    → Chama tools->sefazIncluiDFe(...)
    ↓
11. SEFAZ processa e responde XML
    → cStat = 135 (sucesso)
    ↓
12. PHP grava nos dois bancos (mdfe_eventos + mdfe_inclusao_nfe)
    → Define flash message
    → Retorna JSON: { success: true, cStat: 135, protocolo: "...", ... }
    ↓
13. JavaScript recebe sucesso
    → Fecha modal
    → window.location.reload()
    ↓
14. Página recarrega
    → Flash message aparece no topo "NF-e incluída com sucesso!"
```

---

## 7. Parte 6: Banco de Dados — Tabelas Envolvidas

### Tabela: `llx_mdfe_emitidas`
Armazena as MDF-e emitidas.

| Campo | Tipo | Descrição |
|---|---|---|
| id | INT AUTO_INCREMENT | Identificador único |
| numero | VARCHAR | Número da MDF-e |
| serie | VARCHAR | Série |
| chave_acesso | VARCHAR(44) | Chave de 44 dígitos |
| protocolo | VARCHAR | Protocolo de autorização |
| status | VARCHAR | autorizada, cancelada, encerrada, rejeitada |
| data_emissao | DATETIME | Data/hora de emissão |
| uf_ini | VARCHAR(2) | UF de início |
| uf_fim | VARCHAR(2) | UF de fim |
| modal | INT | 1=Rod, 2=Aér, 3=Aqua, 4=Ferr |
| placa | VARCHAR | Placa do veículo |
| xml_mdfe | LONGTEXT | XML completo |

### Tabela: `llx_mdfe_eventos`
Armazena TODOS os eventos de qualquer MDF-e.

| Campo | Tipo | Descrição |
|---|---|---|
| id | INT AUTO_INCREMENT | Identificador único |
| fk_mdfe_emitida | INT | FK para mdfe_emitidas |
| tpEvento | VARCHAR | 110111=Cancel, 110112=Encerr, 110115=InclDFe |
| nSeqEvento | INT | Número sequencial do evento |
| protocolo_evento | VARCHAR | Protocolo devolvido pela SEFAZ |
| motivo_evento | TEXT | Descrição do resultado |
| data_evento | DATETIME | Data/hora do registro |
| xml_requisicao | LONGTEXT | XML enviado |
| xml_resposta | LONGTEXT | XML recebido |

### Tabela: `llx_mdfe_inclusao_nfe`
Armazena dados específicos de inclusão de NF-e.

| Campo | Tipo | Descrição |
|---|---|---|
| fk_mdfe_emitida | INT | FK para mdfe_emitidas |
| chave_mdfe | VARCHAR(44) | Chave da MDF-e |
| nSeqEvento | INT | Sequência |
| cMunCarrega | VARCHAR | Código IBGE carregamento |
| xMunCarrega | VARCHAR | Nome município carregamento |
| cMunDescarga | VARCHAR | Código IBGE descarga |
| xMunDescarga | VARCHAR | Nome município descarga |
| chNFe | VARCHAR(44) | Chave da NF-e incluída |
| cStat | INT | Código status SEFAZ |

---

## 8. Parte 7: Padrões e Boas Práticas Utilizados

### 1. Separação Frontend / Backend
- **Frontend (mdfe_list.php):** Renderiza HTML + JS, coleta dados, envia AJAX
- **Backend (mdfe_incluir_dfe.php):** Processa dados, comunica com SEFAZ, grava no banco

### 2. Validação Dupla
- **JavaScript:** Feedback instantâneo ao usuário
- **PHP:** Segurança real (nunca confie no frontend)

### 3. Respostas JSON Padronizadas
```json
// Sucesso
{ "success": true, "cStat": 135, "protocolo": "..." }

// Erro
{ "success": false, "error": "Mensagem de erro" }
```

### 4. Cache no Frontend
```javascript
var _incDfeCidadesCache = {};
```
Evita requisições HTTP repetidas para a mesma UF.

### 5. Prevenir Duplo-Clique
```javascript
btn.disabled = true;
btn.style.opacity = "0.6";
btn.textContent = "Enviando...";
```

### 6. Tratamento de Erros em Cadeia
```javascript
.then(r => r.json())
.then(data => { /* tratamento */ })
.catch(err => { /* erro de rede */ });
```

### 7. Flash Messages do Dolibarr
```php
setEventMessages('Mensagem!', null, 'mesgs');
```
A mensagem aparece após o `window.location.reload()`.

---

## 9. Como Reproduzir: Guia Passo a Passo

Se você quisesse criar uma funcionalidade semelhante (ex: "Incluir CT-e"), siga estes passos:

### Passo 1: Criar a Tabela no Banco
```sql
CREATE TABLE llx_mdfe_inclusao_cte (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fk_mdfe_emitida INT NOT NULL,
    chave_mdfe VARCHAR(44),
    protocolo_mdfe VARCHAR(50),
    nSeqEvento INT DEFAULT 1,
    cMunCarrega VARCHAR(10),
    xMunCarrega VARCHAR(100),
    cMunDescarga VARCHAR(10),
    xMunDescarga VARCHAR(100),
    chCTe VARCHAR(44) NOT NULL,
    protocolo_evento VARCHAR(50),
    cStat INT,
    xMotivo TEXT,
    data_evento DATETIME,
    xml_requisicao LONGTEXT,
    xml_resposta LONGTEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### Passo 2: Criar o Endpoint Backend
Crie `mdfe_incluir_cte.php` seguindo a mesma estrutura:
```php
<?php
require_once '../../main.inc.php';
// ... require das libs ...

// Verificar AJAX
// header JSON
// Funções jsonError/jsonSuccess

$action = GETPOST('action', 'alpha');

if ($action === 'buscar_cidades') {
    // Mesma lógica de buscar cidades
}

if ($action === 'incluir_cte') {
    // Coletar dados
    // Validar
    // Buscar MDF-e no banco
    // Resolver IBGE
    // Calcular nSeqEvento
    // Enviar à SEFAZ
    // Gravar nos bancos
    // Retornar JSON
}
```

### Passo 3: Criar o HTML da Modal em mdfe_list.php
```php
print '<div id="mdfeIncluirCteModal" class="mdfe-inc-overlay" role="dialog">';
// ... header, body com campos, footer com botões ...
print '</div>';
```

### Passo 4: Criar as Funções JavaScript
```javascript
var _mdfeIncluirCteId = null;

function openIncluirCteModal(id) { /* reset + show */ }
function closeIncluirCteModal() { /* hide */ }
function confirmarIncluirCte() { /* validar + fetch POST */ }
```

### Passo 5: Adicionar o Botão no Dropdown de Ações
```php
if ($status === 'autorizada') {
    print '<a class="nfe-dropdown-item" href="#" 
              onclick="openIncluirCteModal('.(int)$obj->id.'); return false;">
              Incluir CT-e
          </a>';
}
```

---

## 10. Exercícios Práticos

### Exercício 1 (Fácil)
Modifique a modal para que o campo de chave NF-e mude a cor da borda para verde quando tiver exatamente 44 dígitos, e vermelho quando não tiver.

**Dica:** Use o evento `oninput` e `style.borderColor`.

### Exercício 2 (Médio)
Adicione um campo opcional "Observação" (textarea, máximo 200 caracteres) na modal de inclusão de NF-e. O campo deve ser enviado ao backend mas NÃO é obrigatório.

### Exercício 3 (Avançado)
Crie uma funcionalidade para listar todas as NF-e já incluídas em uma MDF-e. Quando o usuário clicar em "Ver NF-e Incluídas", uma modal deve mostrar uma tabela com: chave NF-e, município de descarga, data do evento e protocolo.

**Dica:** Crie um novo action no backend que faz SELECT na `llx_mdfe_inclusao_nfe`.

---

## Glossário

| Termo | Significado |
|---|---|
| MDF-e | Manifesto Eletrônico de Documentos Fiscais |
| NF-e | Nota Fiscal Eletrônica |
| CT-e | Conhecimento de Transporte Eletrônico |
| DF-e | Documento Fiscal Eletrônico (termo genérico) |
| SEFAZ | Secretaria da Fazenda |
| IBGE | Instituto Brasileiro de Geografia e Estatística |
| cStat | Código de status retornado pela SEFAZ |
| tpEvento | Tipo de evento (110111=cancel, 110112=encerr, 110115=inclDFe) |
| nSeqEvento | Número sequencial do evento para uma mesma MDF-e |
| NFePHP | Biblioteca PHP open-source para comunicação com SEFAZ |

---

> **Autor:** Gerado automaticamente como material de estudo  
> **Data:** Fevereiro/2026
