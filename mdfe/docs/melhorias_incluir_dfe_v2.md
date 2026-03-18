# Documentação — Melhorias na Inclusão de DF-e: Mensagens, Eventos, Consulta e Download

## Índice

1. [Visão Geral das Melhorias](#visão-geral-das-melhorias)
2. [Arquivos Modificados](#arquivos-modificados)
3. [Problema 1: Mensagens não apareciam ao incluir NF-e](#problema-1-mensagens-não-apareciam-ao-incluir-nf-e)
4. [Problema 2: Consulta não mostrava eventos](#problema-2-consulta-não-mostrava-eventos)
5. [Problema 3: NF-e incluídas não apareciam nos documentos](#problema-3-nf-e-incluídas-não-apareciam-nos-documentos)
6. [Problema 4: Download de XML não incluía Inclusão de DF-e](#problema-4-download-de-xml-não-incluía-inclusão-de-df-e)
7. [Fluxo Completo Atualizado](#fluxo-completo-atualizado)
8. [Detalhamento Técnico por Arquivo](#detalhamento-técnico-por-arquivo)
9. [Banco de Dados](#banco-de-dados)
10. [Como Testar](#como-testar)
11. [Glossário](#glossário)

---

## Visão Geral das Melhorias

Após a implementação inicial da funcionalidade "Incluir DF-e" (evento 110115), foram identificados **4 problemas**:

| # | Problema | Impacto |
|---|----------|---------|
| 1 | Ao clicar em "Incluir NF-e", nenhuma mensagem aparecia — nem em caso de sucesso, nem em caso de erro | Usuário não sabia se a operação funcionou |
| 2 | A tela de consulta (modal) não exibia o histórico de eventos da MDF-e | Não havia como ver cancelamentos, encerramentos ou inclusões de DF-e |
| 3 | NF-e incluídas via evento não apareciam na lista de documentos vinculados da consulta | Documentos adicionados depois ficavam "invisíveis" |
| 4 | O download de XML não incluía os XMLs de eventos de Inclusão de DF-e | Impossível obter a prova do evento junto à SEFAZ |

Todas as melhorias foram implementadas mantendo total retrocompatibilidade com funcionalidades existentes.

---

## Arquivos Modificados

| Arquivo | O que foi alterado |
|---------|-------------------|
| `mdfe_incluir_dfe.php` | Correção de tipos de GETPOST, adição de `setEventMessages()` |
| `mdfe_list.php` | Reescrita da função JS `confirmarIncluirDfe()` — mensagens de erro e sucesso |
| `mdfe_consulta.php` | Novo card "Histórico de Eventos", NF-e incluídas nos documentos vinculados |
| `mdfe_download.php` | Inclusão do evento 110115 no download de XMLs |

---

## Problema 1: Mensagens não apareciam ao incluir NF-e

### O que acontecia

Ao clicar em "Incluir NF-e" na modal:
- **Em caso de sucesso**: nenhuma mensagem era exibida, a página simplesmente recarregava
- **Em caso de erro**: o botão mudava para "Tentar Novamente" mas a mensagem de erro não aparecia visualmente

### Causa raiz

Foram identificadas **duas causas**:

#### Causa 1: Tipos inadequados no `GETPOST()` do backend

```php
// ANTES (incorreto)
$munCarrega   = trim(GETPOST('mun_carrega', 'alpha'));
$munDescarga  = trim(GETPOST('mun_descarga', 'alpha'));
$chNFe        = trim(GETPOST('chNFe', 'alpha'));
```

O tipo `'alpha'` no Dolibarr é restritivo e pode **remover espaços e caracteres especiais** de nomes de municípios. Exemplo:
- `"SAO PAULO"` → poderia se tornar `"SAOPAULO"` ou `"SAO"` (conforme a implementação interna)
- `"VITÓRIA DA CONQUISTA"` → poderia perder o acento e os espaços

Isso fazia a consulta IBGE falhar silenciosamente, retornando um erro que não era exibido corretamente na UI.

```php
// DEPOIS (correto)
$munCarrega   = trim(GETPOST('mun_carrega', 'restricthtml'));
$munDescarga  = trim(GETPOST('mun_descarga', 'restricthtml'));
$chNFe        = preg_replace('/\D/', '', trim(GETPOST('chNFe', 'restricthtml')));
```

- **`'restricthtml'`**: Preserva espaços, acentos e caracteres especiais, removendo apenas tags HTML perigosas
- **`preg_replace('/\D/', '', ...)`**: Para a chave da NF-e, remove explicitamente qualquer caractere não-numérico, garantindo que apenas os 44 dígitos sejam utilizados

#### Causa 2: Falta de feedback visual padrão do Dolibarr

O Dolibarr usa um sistema de **mensagens de sessão** chamado `setEventMessages()`. Essas mensagens são armazenadas na sessão PHP e exibidas automaticamente após o recarregamento da página. A implementação original não usava esse mecanismo.

### Solução implementada

#### Backend (`mdfe_incluir_dfe.php`)

Adicionado `setEventMessages()` **antes** do `jsonSuccess()`:

```php
// Mensagem de sucesso via sessão do Dolibarr (aparece após reload)
setEventMessages(
    'NF-e incluída com sucesso na MDF-e! Protocolo: ' . $nProtEvt . ' | Seq: ' . $nSeqResp,
    null,
    'mesgs'
);

jsonSuccess([
    'cStat'      => $cStat,
    'xMotivo'    => $xMotivo,
    'protocolo'  => $nProtEvt,
    'nSeqEvento' => $nSeqResp,
    'chNFe'      => $chNFe,
]);
```

**Como funciona o `setEventMessages()`:**
- **Parâmetro 1**: Texto da mensagem
- **Parâmetro 2**: Array de mensagens extra (null = nenhuma)
- **Parâmetro 3**: Tipo — `'mesgs'` = sucesso (verde), `'warnings'` = aviso (amarelo), `'errors'` = erro (vermelho)
- A mensagem é gravada em `$_SESSION['dol_events']` e exibida na próxima renderização de página

#### Frontend (`mdfe_list.php` — JavaScript)

A função `confirmarIncluirDfe()` foi reescrita:

**Sucesso:**
```javascript
if (data.success) {
    closeIncluirDfeModal();
    window.location.reload();
    // Mensagem aparece automaticamente via setEventMessages (sessão Dolibarr)
}
```
- Fecha a modal imediatamente
- Recarrega a página
- A mensagem verde do Dolibarr aparece automaticamente no topo da lista

**Erro:**
```javascript
else {
    if (notice) {
        notice.className = "mdfe-inc-notice error";
        notice.textContent = data.error || "Erro desconhecido ao incluir NF-e.";
    }
    if (btn) {
        btn.disabled = false;
        btn.style.opacity = "";
        btn.textContent = "Tentar Novamente";
    }
}
```
- A mensagem de erro aparece **dentro da modal**, no elemento `#incDfeNotice` (barra no topo da modal)
- O botão é reativado com texto "Tentar Novamente"
- CSS aplica fundo vermelho claro (`.mdfe-inc-notice.error`)

**Exceção de rede:**
```javascript
.catch(function(err) {
    if (notice) {
        notice.className = "mdfe-inc-notice error";
        notice.textContent = "Erro de comunicacao: " + ((err&&err.message)||"desconhecido");
    }
    if (btn) {
        btn.disabled = false;
        btn.style.opacity = "";
        btn.textContent = "Tentar Novamente";
    }
});
```
- Captura erros de rede, timeout ou resposta não-JSON
- Exibe a mensagem técnica do erro na barra de aviso da modal

**Validações de formulário** (antes do envio):
```javascript
if (!ufCarrega || !munCarrega) {
    notice.textContent = "Selecione a UF e o municipio de carregamento.";
    return;
}
if (!ufDescarga || !munDescarga) {
    notice.textContent = "Selecione a UF e o municipio de descarga.";
    return;
}
if (chNFe.length !== 44 || !/^[0-9]{44}$/.test(chNFe)) {
    notice.textContent = "A chave da NF-e deve ter exatamente 44 digitos numericos.";
    return;
}
```
- Textos em ASCII puro (sem acentos) para evitar problemas de encoding no JavaScript embutido em PHP

---

## Problema 2: Consulta não mostrava eventos

### O que acontecia

Ao clicar em "Consultar" uma MDF-e, a modal exibia todos os dados do XML original (emitente, veículos, percurso, etc.) mas **não mostrava nenhum evento** — cancelamento, encerramento ou inclusão de DF-e eram completamente invisíveis.

### Causa raiz

A função `renderConsultaHtml()` não recebia dados de eventos e não consultava a tabela `mdfe_eventos`.

### Solução implementada

#### 1. Consulta de eventos no despacho (`consultar_html`)

Antes de renderizar o HTML, agora buscamos os eventos no banco:

```php
if ($action === 'consultar_html') {
    header('Content-Type: text/html; charset=UTF-8');
    $dados = parseMdfeXml($xmlStr, $row);

    // NOVO: Busca eventos desta MDF-e no banco
    $sqlEvt = "SELECT id, tpEvento, nSeqEvento, protocolo_evento, motivo_evento, data_evento,
                      xml_requisicao, xml_resposta
               FROM " . MAIN_DB_PREFIX . "mdfe_eventos
               WHERE fk_mdfe_emitida = " . $mdfeId . "
               ORDER BY nSeqEvento ASC";
    $resEvt = $db->query($sqlEvt);
    $eventos = [];
    if ($resEvt) {
        while ($evtRow = $db->fetch_object($resEvt)) {
            $eventos[] = $evtRow;
        }
    }

    echo renderConsultaHtml($dados, $eventos);
    exit;
}
```

#### 2. Assinatura da função alterada

```php
// ANTES
function renderConsultaHtml($dados)

// DEPOIS
function renderConsultaHtml($dados, $eventos = [])
```

O parâmetro `$eventos = []` é opcional (valor padrão `[]`), mantendo compatibilidade se a função for chamada sem eventos.

#### 3. Novo card "Histórico de Eventos"

Um novo card foi adicionado no final da consulta, **antes do botão "Fechar"**, que exibe todos os eventos registrados para a MDF-e.

**Mapeamento de cores por tipo de evento:**

| tpEvento | Nome | Cor do Badge |
|----------|------|--------------|
| 110111 | Cancelamento | 🔴 Vermelho (`#dc3545`) |
| 110112 | Encerramento | ⚫ Cinza (`#6c757d`) |
| 110114 | Inclusão de Condutor | 🔵 Azul (`#17a2b8`) |
| 110115 | Inclusão de DF-e | 🟢 Verde (`#28a745`) |
| 110116 | Pagamento Op. Transporte | 🟠 Laranja (`#fd7e14`) |

**Informações exibidas por evento:**
- Badge colorido com o nome do tipo
- Número de sequência (`nSeqEvento`)
- Data e hora do registro
- Protocolo SEFAZ (se disponível)
- Motivo/descrição retornado pela SEFAZ

**Informação extra para Inclusão de DF-e (110115):**
- Exibe a chave da NF-e incluída em uma caixa verde com fonte monoespaçada
- A chave é extraída do XML de resposta usando regex: `/<chNFe>(\d{44})<\/chNFe>/`

**Estrutura HTML gerada:**
```html
<div class="nfse-card">
  <div class="nfse-card-header"><span>📅 Histórico de Eventos</span></div>
  <div class="nfse-card-body" style="display:block;">

    <!-- Evento 1 -->
    <div style="display:flex;align-items:flex-start;gap:10px;">
      <div>
        <span style="background:#28a745;color:#fff;...">Inclusão de DF-e</span>
      </div>
      <div style="flex:1;">
        <div class="nfse-data-grid-3">
          <div>Sequência: 1</div>
          <div>Data: 15/01/2025 14:30:00</div>
          <div>Protocolo: 135250000012345</div>
        </div>
        <div>Evento registrado e vinculado a MDF-e</div>
        <!-- Chave NF-e incluída -->
        <div style="background:#d4edda;color:#155724;...">
          NF-e: 31250112345678000199550010000001231123456789
        </div>
      </div>
    </div>

    <hr> <!-- Separador entre eventos -->

    <!-- Evento 2, 3, ... -->
  </div>
</div>
```

---

## Problema 3: NF-e incluídas não apareciam nos documentos

### O que acontecia

O card "Documentos Vinculados" na consulta mostrava apenas os documentos que estavam no XML **original** da MDF-e. NF-e adicionadas posteriormente via evento de Inclusão de DF-e não apareciam.

### Causa raiz

O card de documentos só parseava o XML da MDF-e (`<infDoc>`), que contém apenas os documentos que estavam no momento da emissão. Documentos incluídos via evento ficam nos XMLs dos eventos, não no XML original.

### Solução implementada

#### 1. Extração de NF-e dos eventos

Antes de renderizar o card de documentos, o sistema agora percorre os eventos de Inclusão de DF-e e extrai as chaves:

```php
$nfeIncluidas = [];
if (!empty($eventos)) {
    foreach ($eventos as $evt) {
        if ($evt->tpEvento === '110115' && !empty($evt->xml_resposta)) {
            $xmlEvt = is_resource($evt->xml_resposta)
                ? stream_get_contents($evt->xml_resposta)
                : (string)$evt->xml_resposta;

            if (preg_match_all('/<chNFe>(\d{44})<\/chNFe>/', $xmlEvt, $matches)) {
                foreach ($matches[1] as $ch) {
                    $nfeIncluidas[] = $ch;
                }
            }
        }
    }
    $nfeIncluidas = array_unique($nfeIncluidas);
}
```

**Como funciona:**
- Filtra apenas eventos com `tpEvento === '110115'` (Inclusão de DF-e)
- Lê o `xml_resposta` de cada evento
- Usa regex `/<chNFe>(\d{44})<\/chNFe>/` para extrair chaves de NF-e com exatamente 44 dígitos
- Remove duplicatas com `array_unique()`

#### 2. Mesclagem com documentos originais

Os documentos incluídos via evento são adicionados à lista de NF-e, sem duplicar:

```php
// Coleta NF-e do XML original
foreach ($dados['documentos'] as $doc) {
    foreach ($doc['chaves_nfe'] as $n) $allNfe[] = $n['chNFe'];
}

// Adiciona NF-e incluídas via evento (sem duplicar)
foreach ($nfeIncluidas as $chInc) {
    if (!in_array($chInc, $allNfe)) {
        $allNfe[] = $chInc;
    }
}
```

#### 3. Badge visual "incluída via evento"

Cada NF-e que veio de um evento recebe um badge verde para diferenciá-la:

```php
if ($isIncluida) {
    $html .= '<span style="background:#d4edda;color:#155724;padding:1px 6px;
               border-radius:3px;">incluída via evento</span>';
}
```

**Resultado visual:**
```
NF-e (3)
31250112345678000199550010000001231123456789
31250112345678000199550010000001241234567890
31250112345678000199550010000001251345678901  [incluída via evento]
```

#### 4. Condição de exibição do card atualizada

O card agora aparece se houver documentos originais **OU** NF-e incluídas via evento:

```php
// ANTES
if (!empty($dados['documentos'])) {

// DEPOIS
if (!empty($dados['documentos']) || !empty($nfeIncluidas)) {
```

Isso garante que mesmo uma MDF-e emitida com `indCarregaPosterior=1` (sem documentos originais) mostre os documentos que foram incluídos depois.

---

## Problema 4: Download de XML não incluía Inclusão de DF-e

### O que acontecia

Ao clicar em "Download XML" de uma MDF-e que teve NF-e incluídas via evento, o ZIP gerado continha apenas:
- XML de emissão
- XML de cancelamento (se cancelada)
- XML de encerramento (se encerrada)

O XML do evento de Inclusão de DF-e (110115) **não era incluído** no download.

### Causa raiz

Duas condições restritivas no código:

#### Causa 1: Filtro SQL limitado

```php
// ANTES — só buscava cancelamento e encerramento
$sql = "... WHERE fk_mdfe_emitida = ? AND tpEvento IN ('110111','110112') ...";
```

O `tpEvento IN ('110111','110112')` excluía explicitamente o evento 110115 (Inclusão de DF-e).

#### Causa 2: Verificação de status limitada

```php
// ANTES — só buscava eventos para cancelada/encerrada
$comEventos = in_array($status, ['cancelada', 'encerrada']);
$eventos = $comEventos ? mdfe_dl_getEventosXml($db, $mdfeId) : [];
```

Uma MDF-e com status `autorizada` podia ter eventos de Inclusão de DF-e, mas o sistema nunca os buscava porque verificava apenas os status `'cancelada'` e `'encerrada'`.

### Solução implementada

#### 1. Função `mdfe_dl_getEventosXml()` reescrita

```php
function mdfe_dl_getEventosXml($db, $mdfeId)
{
    // Mapeamento de todos os tipos de evento conhecidos
    $tpDescMap = [
        '110111' => 'cancelamento',
        '110112' => 'encerramento',
        '110114' => 'inclusao-condutor',
        '110115' => 'inclusao-dfe',
        '110116' => 'pagamento',
    ];

    $eventos = [];
    // SEM filtro de tpEvento — busca TODOS os eventos
    $sql = "SELECT tpEvento, nSeqEvento, xml_requisicao, xml_resposta
            FROM " . MAIN_DB_PREFIX . "mdfe_eventos
            WHERE fk_mdfe_emitida = " . (int)$mdfeId . "
            ORDER BY nSeqEvento ASC";
    $res = $db->query($sql);
    if (!$res) return $eventos;

    while ($row = $db->fetch_object($res)) {
        $tpDesc = $tpDescMap[$row->tpEvento] ?? ('evento-' . $row->tpEvento);
        $seq    = str_pad((string)$row->nSeqEvento, 3, '0', STR_PAD_LEFT);

        $respXml = is_resource($row->xml_resposta)
            ? stream_get_contents($row->xml_resposta)
            : (string)($row->xml_resposta ?? '');
        if (trim($respXml) !== '') {
            $eventos[] = ['tipo' => $tpDesc, 'seq' => $seq, 'xml' => trim($respXml)];
        }
    }
    return $eventos;
}
```

**Mudanças:**
- **Removido** o filtro `AND tpEvento IN (...)` — agora busca **todos** os tipos de evento
- **Adicionado** mapeamento completo de tipos no `$tpDescMap` (110111 a 110116)
- **Adicionado** `seq` (número de sequência) no array de retorno para nomes de arquivo únicos
- **Ordenação** por `nSeqEvento ASC` para manter ordem cronológica

#### 2. Verificação de status removida

```php
// ANTES — modo individual
$status    = strtolower((string)$row->status);
$comEventos = in_array($status, ['cancelada', 'encerrada']);
$eventos    = $comEventos ? mdfe_dl_getEventosXml($db, $mdfeId) : [];

// DEPOIS — modo individual
$eventos = mdfe_dl_getEventosXml($db, $mdfeId);
```

```php
// ANTES — modo batch
$comEventos  = in_array($status, ['cancelada', 'encerrada']);
$eventos     = $comEventos ? mdfe_dl_getEventosXml($db, (int)$row->id) : [];

// DEPOIS — modo batch
$eventos = mdfe_dl_getEventosXml($db, (int)$row->id);
```

Agora o download **sempre** busca eventos, independente do status da MDF-e.

#### 3. Nomes de arquivo com número de sequência

```php
// ANTES
$nomeEvt = $nomeBase . '-' . $evt['tipo'] . '.xml';

// DEPOIS
$nomeEvt = $nomeBase . '-' . $evt['tipo'] . '-seq' . ($evt['seq'] ?? '001') . '.xml';
```

**Exemplo de nomes gerados no ZIP:**
```
MDFe_31250112345678000199580010000000011001234567/
  ├── MDFe_31250112345678000199580010000000011001234567.xml          (emissão)
  ├── MDFe_31250112345678000199580010000000011001234567-inclusao-dfe-seq001.xml
  ├── MDFe_31250112345678000199580010000000011001234567-inclusao-dfe-seq002.xml
  └── MDFe_31250112345678000199580010000000011001234567-encerramento-seq003.xml
```

Isso evita conflito de nomes quando há múltiplos eventos do mesmo tipo (e.g., 3 inclusões de DF-e).

---

## Fluxo Completo Atualizado

```
Usuário clica "Incluir NF-e"  ──  Modal abre (mdfe_list.php)
       │
       ├─ Preenche UF/Mun Carrega, UF/Mun Descarga, chNFe
       │
       └─ Clica "Incluir NF-e"
               │
               ▼
         ┌─── VALIDAÇÃO JS ───┐
         │ UF preenchida?      │ ✗ → Msg erro na barra da modal
         │ Município selecionado? │
         │ chNFe = 44 dígitos? │
         └─────────────────────┘
               │ ✓
               ▼
         FETCH POST → mdfe_incluir_dfe.php?action=incluir_dfe
               │
               ▼
         ┌─── BACKEND PHP ───┐
         │ GETPOST restricthtml │← Preserva espaços/acentos
         │ Valida campos         │
         │ Busca MDF-e (autorizada)│
         │ Resolve IBGE            │
         │ Calcula nSeqEvento      │
         │ Monta config + cert     │
         │ sefazIncluiDFe()        │
         │ cStat === 135?          │ ✗ → jsonError(msg SEFAZ)
         │ Grava mdfe_eventos      │          │
         │ setEventMessages()      │          ▼
         │ jsonSuccess()           │   Modal: Msg erro + "Tentar Novamente"
         └────────────────────────┘
               │ ✓
               ▼
         JS: closeModal() + reload()
               │
               ▼
         Página recarrega com banner verde do Dolibarr:
         "NF-e incluída com sucesso na MDF-e! Protocolo: XXX | Seq: Y"

         ┌─── CONSULTAR ──────────────────┐
         │ Card Documentos → mostra NF-e  │
         │   incluídas com badge verde    │
         │ Card Eventos → mostra histórico│
         │   com badge colorido por tipo  │
         └────────────────────────────────┘

         ┌─── DOWNLOAD XML ───────────────┐
         │ ZIP contém XML de emissão +    │
         │   XML de cada evento (incluindo│
         │   inclusao-dfe-seqNNN.xml)     │
         └────────────────────────────────┘
```

---

## Detalhamento Técnico por Arquivo

### `mdfe_incluir_dfe.php`

**Localização:** `custom/mdfe/mdfe_incluir_dfe.php`

#### Alterações realizadas

| Linha | Antes | Depois | Por quê |
|-------|-------|--------|---------|
| ~143 | `GETPOST('mun_carrega', 'alpha')` | `GETPOST('mun_carrega', 'restricthtml')` | `alpha` remove espaços de cidades como "SAO PAULO" |
| ~145 | `GETPOST('mun_descarga', 'alpha')` | `GETPOST('mun_descarga', 'restricthtml')` | Mesmo motivo acima |
| ~146 | `GETPOST('chNFe', 'alpha')` | `preg_replace('/\D/', '', trim(GETPOST('chNFe', 'restricthtml')))` | Extrai explicitamente apenas dígitos da chave |
| ~240-244 | *(não existia)* | `setEventMessages('NF-e incluída...', null, 'mesgs')` | Mensagem verde aparece após reload da página |

#### Tipos de GETPOST no Dolibarr — referência

| Tipo | Comportamento | Quando usar |
|------|--------------|-------------|
| `'alpha'` | Restritivo — pode remover espaços e caracteres especiais | Códigos simples (UF, status) |
| `'alphanohtml'` | Remove HTML mas mantém a maioria dos caracteres | Textos simples |
| `'restricthtml'` | Remove apenas HTML perigoso, preserva espaços e acentos | Nomes de cidades, textos livres |
| `'int'` | Apenas inteiros | IDs numéricos |
| `'none'` | Sem filtro | Não recomendado (risco de XSS) |

---

### `mdfe_list.php`

**Localização:** `custom/mdfe/mdfe_list.php`

**Área alterada:** Função JavaScript `confirmarIncluirDfe()` (dentro do bloco `print '<script>...</script>'`)

#### Particularidade importante: JavaScript dentro de PHP

Todo o JavaScript do arquivo está dentro de uma string PHP de aspas simples:
```php
print '<script>
    function confirmarIncluirDfe() {
        // código JS aqui
    }
</script>';
```

Isso significa que:
- `\\` no PHP → `\` no JavaScript gerado
- `\\'` no PHP → `'` no JavaScript gerado
- Caracteres unicode como `\\u00ed` → `\u00ed` (= `í`) no JavaScript
- **Recomendação**: usar texto ASCII puro para evitar problemas de encoding

#### Decisões de design

| Cenário | Estratégia | Justificativa |
|---------|-----------|---------------|
| Sucesso | `setEventMessages()` no PHP + `reload()` no JS | Padrão Dolibarr — mensagem sobrevive ao reload |
| Erro SEFAZ | `notice.textContent` na modal | Usuário precisa ver o erro sem sair da modal |
| Erro de rede | `notice.textContent` com `err.message` | Informação técnica para diagnóstico |
| Validação JS | `notice.textContent` + `return` | Previne envio desnecessário à SEFAZ |

---

### `mdfe_consulta.php`

**Localização:** `custom/mdfe/mdfe_consulta.php`

#### Alteração 1: Assinatura da função

```php
// ANTES
function renderConsultaHtml($dados)

// DEPOIS
function renderConsultaHtml($dados, $eventos = [])
```

#### Alteração 2: Consulta de eventos no despacho

No trecho `if ($action === 'consultar_html')`, adicionada consulta SQL à tabela `mdfe_eventos`:

```php
$sqlEvt = "SELECT id, tpEvento, nSeqEvento, protocolo_evento, motivo_evento,
                  data_evento, xml_requisicao, xml_resposta
           FROM " . MAIN_DB_PREFIX . "mdfe_eventos
           WHERE fk_mdfe_emitida = " . $mdfeId . "
           ORDER BY nSeqEvento ASC";
```

Os resultados são passados como segundo parâmetro para `renderConsultaHtml($dados, $eventos)`.

#### Alteração 3: Card "Documentos Vinculados" ampliado

O card agora:
1. Percorre eventos de Inclusão DF-e (110115)
2. Extrai chaves NF-e dos XMLs de resposta
3. Mescla com NF-e originais (sem duplicar)
4. Exibe badge "incluída via evento" para as adicionadas

#### Alteração 4: Novo card "Histórico de Eventos"

Adicionado **após** o card de Informações Adicionais e **antes** do botão "Fechar". Exibe todos os eventos da MDF-e com:
- Badge colorido por tipo
- Dados de sequência, data, protocolo
- Para IncDFe: a chave NF-e incluída em caixa verde

---

### `mdfe_download.php`

**Localização:** `custom/mdfe/mdfe_download.php`

#### Alteração 1: Função `mdfe_dl_getEventosXml()` reescrita

- Removido filtro `tpEvento IN ('110111','110112')` — agora busca **todos** os eventos
- Adicionado mapeamento para 5 tipos: cancelamento, encerramento, inclusao-condutor, inclusao-dfe, pagamento
- Adicionado campo `seq` para nomenclatura de arquivos

#### Alteração 2: Download individual

Removida verificação de status (`$comEventos = in_array($status, ['cancelada', 'encerrada'])`). Agora eventos são buscados para qualquer status.

#### Alteração 3: Download em lote (batch)

Mesma remoção da verificação de status no modo batch.

#### Alteração 4: Nomenclatura de arquivos com sequência

Arquivos de eventos agora incluem o número de sequência: `inclusao-dfe-seq001.xml`.

---

## Banco de Dados

### Tabela: `llx_mdfe_eventos`

| Coluna | Tipo | Descrição |
|--------|------|-----------|
| `id` | INT, PK, AUTO | Identificador único |
| `fk_mdfe_emitida` | INT | Referência à MDF-e (tabela `llx_mdfe_emitidas`) |
| `tpEvento` | VARCHAR(6) | Código do tipo de evento (110111, 110112, 110115, etc.) |
| `nSeqEvento` | INT | Número sequencial do evento na MDF-e |
| `protocolo_evento` | VARCHAR(255) | Protocolo retornado pela SEFAZ |
| `motivo_evento` | TEXT | Motivo/descrição retornado pela SEFAZ |
| `data_evento` | DATETIME | Data/hora do registro do evento |
| `xml_requisicao` | LONGTEXT | XML enviado à SEFAZ |
| `xml_resposta` | LONGTEXT | XML de retorno da SEFAZ |
| `xml_evento_completo` | LONGTEXT | XML completo do evento (geralmente igual ao xml_resposta) |

### Tipos de evento (tpEvento)

| Código | Nome | Quando usar |
|--------|------|-------------|
| 110111 | Cancelamento | MDF-e precisa ser anulada |
| 110112 | Encerramento | Viagem concluída, MDF-e finalizada |
| 110114 | Inclusão de Condutor | Novo motorista adicionado |
| 110115 | Inclusão de DF-e | Nova NF-e/CT-e adicionada após emissão |
| 110116 | Pagamento Op. Transporte | Registro de pagamento do frete |

---

## Como Testar

### Pré-requisitos
- MDF-e emitida e autorizada (status = `autorizada`) em ambiente de **Homologação**
- Certificado digital A1 válido configurado no sistema
- Tabela IBGE populada com municípios

### Teste 1: Inclusão com sucesso

1. Acesse a lista de MDF-e (`mdfe_list.php`)
2. Encontre uma MDF-e com status "Autorizada"
3. Clique no dropdown ▼ → "Incluir NF-e"
4. Preencha:
   - UF Carregamento: selecione uma UF
   - Município Carregamento: selecione um município
   - UF Descarga: selecione uma UF
   - Município Descarga: selecione um município
   - Chave NF-e: 44 dígitos (em homologação, pode usar chave fictícia de mesma UF)
5. Clique "Incluir NF-e"
6. **Esperado**: Modal fecha, página recarrega, banner verde no topo: *"NF-e incluída com sucesso na MDF-e!"*

### Teste 2: Erro de validação (frontend)

1. Abra a modal de Incluir NF-e
2. Deixe algum campo vazio ou coloque menos de 44 dígitos
3. Clique "Incluir NF-e"
4. **Esperado**: Mensagem de erro aparece na barra no topo da modal (fundo vermelho claro)

### Teste 3: Erro da SEFAZ (backend)

1. Abra a modal de Incluir NF-e
2. Preencha com uma chave NF-e inválida (44 zeros, por exemplo)
3. Clique "Incluir NF-e"
4. **Esperado**: Mensagem de erro da SEFAZ aparece na barra da modal, botão muda para "Tentar Novamente"

### Teste 4: Consulta com eventos

1. Após inclusão com sucesso, clique em "Consultar" na mesma MDF-e
2. Role até o final da modal
3. **Esperado**:
   - Card "Documentos Vinculados" mostra a NF-e incluída com badge verde "incluída via evento"
   - Card "Histórico de Eventos" mostra o evento com badge verde "Inclusão de DF-e", data, protocolo e chave

### Teste 5: Download XML com eventos

1. Clique em "Download XML" de uma MDF-e que teve eventos
2. **Esperado**:
   - Se há eventos: baixa um ZIP contendo o XML de emissão + XMLs de cada evento
   - Nomes dos XMLs: `MDFe_<chave>-inclusao-dfe-seq001.xml`
   - Se não há eventos: baixa apenas o XML direto (sem ZIP)

### Teste 6: Download em lote

1. Aplique filtros na lista e clique em "Baixar XMLs em Lote"
2. **Esperado**: ZIP com subpastas por MDF-e (quando há eventos), cada subpasta contendo emissão + eventos

---

## Glossário

| Termo | Significado |
|-------|-------------|
| **MDF-e** | Manifesto Eletrônico de Documentos Fiscais — documento que acompanha o transporte de cargas |
| **DF-e** | Documento Fiscal Eletrônico — termo genérico para NF-e, CT-e, etc. |
| **NF-e** | Nota Fiscal Eletrônica — documento fiscal de venda de mercadorias |
| **tpEvento** | Tipo de Evento — código numérico que identifica o tipo de evento no padrão SEFAZ |
| **nSeqEvento** | Número Sequencial do Evento — sequência crescente por MDF-e (1, 2, 3...) |
| **cStat 135** | Código de status da SEFAZ que significa "Evento registrado e vinculado a MDF-e" |
| **SEFAZ** | Secretaria da Fazenda — órgão governamental que valida documentos fiscais eletrônicos |
| **GETPOST()** | Função do Dolibarr para ler parâmetros HTTP de forma segura (com sanitização) |
| **setEventMessages()** | Função do Dolibarr para adicionar mensagens à sessão, exibidas na próxima renderização |
| **restricthtml** | Tipo de sanitização do GETPOST que remove HTML perigoso mas preserva espaços e acentos |
| **NFePHP** | Biblioteca PHP open-source para comunicação com SEFAZ para NF-e, CT-e, MDF-e |
| **indCarregaPosterior** | Flag que indica que os documentos serão vinculados à MDF-e após a emissão |
| **ZIP** | Formato de arquivo compactado usado para agrupar múltiplos XMLs em um único download |
