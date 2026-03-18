# Tutorial: Ocultar Campos na Ficha de Terceiros via Hook

## Como esconder campos na página `societe/card.php` sem alterar o código core do Dolibarr

---

## Sumário — O que vamos fazer

| # | O que fazer | Onde | Para quê |
|---|---|---|---|
| 1 | Registrar o hook `thirdpartycard` no módulo | `modLabApp.class.php` | Ativar a classe de hook na página de terceiros |
| 2 | Criar a lista de campos a ocultar | `actions_labapp.class.php` | Array configurável no topo da classe |
| 3 | Detectar o contexto da página | `actions_labapp.class.php` → `doActions()` | Identificar que estamos em `societe/card.php` |
| 4 | Injetar JavaScript via `ob_start()` | `actions_labapp.class.php` → `doActionsThirdPartyCard()` | Esconder os campos sem editar o HTML da página |
| 5 | Adicionar ou remover campos futuramente | `actions_labapp.class.php` → `$fieldsToHide` | Basta editar o array |

---

## Conceito — Como funciona o sistema de hooks do Dolibarr

O Dolibarr possui um mecanismo de **hooks** (ganchos) que permite que módulos customizados executem código em páginas core **sem editá-las**.

O fluxo é:

```
1. A página societe/card.php declara seus contextos:
   $hookmanager->initHooks(array('thirdpartycard', 'globalcard'));

2. O Dolibarr procura todos os módulos que registraram hooks para 'thirdpartycard'

3. Para cada módulo encontrado, instancia a classe actions_NOMEDOMODULO.class.php

4. Chama o método doActions() dessa classe, passando o contexto

5. Nosso código executa e pode modificar o comportamento da página
```

**O problema:** a página `societe/card.php` chama `doActions()` **antes** de gerar o HTML. Então não podemos simplesmente dar `echo` no HTML que queremos — o conteúdo iria para o lugar errado.

**A solução:** usamos `ob_start(callback)` para capturar **toda** a saída HTML da página e, no callback, injetar um `<script>` antes de `</body>` que manipula o DOM e esconde os campos via JavaScript.

---

## 1. Registrar o hook no módulo

**O que:** adicionar o contexto `'thirdpartycard'` na lista de hooks do módulo.

**Onde:** `custom/labapp/core/modules/modLabApp.class.php`

### Código

```php
$this->module_parts = array(
    'hooks' => array(
        'invoicecard',         // Ficha de fatura
        'productcard',         // Ficha de produto
        'productedit',         // Edição de produto
        'productlist',         // Lista de produtos
        'invoicelist',         // Lista de faturas
        'admincompany',        // Campos NFSe/NFe em admin/company.php
        'thirdpartycard',      // ← NOVO: Ficha de terceiro (societe/card.php)
    ),
);
```

### Explicação

| Item | O que faz |
|---|---|
| `'thirdpartycard'` | Diz ao Dolibarr: "quando a página `societe/card.php` chamar `executeHooks('doActions', ...)`, chame minha classe `ActionsLabapp`". |
| A string deve ser idêntica | O valor `'thirdpartycard'` deve ser exatamente o mesmo que `societe/card.php` usa em `initHooks()`. |

> **IMPORTANTE:** Após alterar o `module_parts`, **desative e reative o módulo** na interface do Dolibarr (`Configuração → Módulos → Lab Connecta → Desativar → Ativar`). Isso recria o registro de hooks no banco.

---

## 2. Criar a lista de campos a ocultar

**O que:** definir um array no topo da classe com os campos que devem ser escondidos.

**Onde:** `custom/labapp/class/actions_labapp.class.php`, no início da classe.

### Código

```php
class ActionsLabapp
{
    /**
     * Lista de campos a ocultar na página de terceiros (societe/card.php).
     *
     * Cada entrada é um array com:
     *   'selector'   => Seletor CSS do input/textarea/select (modo criação/edição)
     *   'viewLabel'  => Texto da label na <td> (modo visualização)
     */
    private static $fieldsToHide = array(
        // Campos atualmente ocultos:
        array('selector' => '#address',  'viewLabel' => 'Address'),

        // Exemplos prontos — descomente para ativar:
        // array('selector' => '#fax',           'viewLabel' => 'Fax'),
        // array('selector' => '#url',           'viewLabel' => 'Web'),
        // array('selector' => '#barcode',       'viewLabel' => 'Gencod'),
        // array('selector' => '#phone',         'viewLabel' => 'Phone'),
        // array('selector' => '#phone_mobile',  'viewLabel' => 'PhoneMobile'),
        // array('selector' => '#email',         'viewLabel' => 'EMail'),
        // array('selector' => '#zipcode',       'viewLabel' => 'Zip'),
        // array('selector' => '#town',          'viewLabel' => 'Town'),
    );
```

### Explicação

| Propriedade | Para que serve |
|---|---|
| `selector` | Seletor CSS/jQuery usado no modo **criação** e **edição**. O JavaScript faz `document.querySelector(selector)` para encontrar o campo. Ex: `'#address'` encontra `<textarea id="address">`. |
| `viewLabel` | Texto da label usado no modo **visualização**. No modo view, os campos não têm `id` — são apenas texto em `<td>`. O JavaScript compara o texto da `<td>` com esse valor. |

### Como descobrir o seletor e a label de um campo

1. Abra `societe/card.php` no navegador
2. Clique com **botão direito** no campo que quer ocultar → **"Inspecionar"**
3. No DevTools, procure o atributo `id=""` do `<input>`, `<textarea>` ou `<select>`
4. Use `'#id_do_campo'` como `selector`
5. Para a `viewLabel`, veja o texto da `<td>` que contém o rótulo do campo

**Exemplo prático — campo Endereço:**

```html
<!-- Modo edição (societe/card.php?action=edit&socid=1) -->
<tr>
  <td class="tdtop">Adresse</td>
  <td colspan="3">
    <textarea name="address" id="address" ...>...</textarea>
                                  ↑
                       selector = '#address'
  </td>
</tr>

<!-- Modo visualização (societe/card.php?socid=1) -->
<tr>
  <td class="titlefield">Address</td>  ← viewLabel = 'Address'
  <td>Rua Exemplo, 123</td>
</tr>
```

---

## 3. Detectar o contexto da página

**O que:** no método `doActions()`, verificar se estamos na ficha de terceiros e delegar para o handler correto.

**Onde:** `actions_labapp.class.php`, método `doActions()`.

### Código

```php
public function doActions($parameters, &$object, &$action, $hookmanager)
{
    $contexts = empty($parameters['context'])
        ? array()
        : explode(':', $parameters['context']);

    // Já existia: admin/company.php
    if (in_array('admincompany', $contexts)) {
        return $this->doActionsAdminCompany($action);
    }

    // NOVO: societe/card.php
    if (in_array('thirdpartycard', $contexts)) {
        return $this->doActionsThirdPartyCard($action);
    }

    return 0;
}
```

### Explicação

| Linha | O que faz |
|---|---|
| `$parameters['context']` | String com os contextos separados por `:`. Ex: `'thirdpartycard:globalcard'`. |
| `explode(':', ...)` | Transforma em array: `['thirdpartycard', 'globalcard']`. |
| `in_array('thirdpartycard', $contexts)` | Verifica se estamos na página de terceiros. |
| `return $this->doActionsThirdPartyCard($action)` | Delega para o novo método privado. |
| `return 0` | Convenção do Dolibarr: 0 = "processou ok, continue o fluxo normal". |

---

## 4. Injetar o JavaScript que oculta os campos

**O que:** usar `ob_start()` para capturar toda a saída HTML e injetar um `<script>` que esconde os campos.

**Onde:** `actions_labapp.class.php`, novo método `doActionsThirdPartyCard()`.

### Código

```php
private function doActionsThirdPartyCard($action)
{
    // Se não há campos para ocultar, não faz nada
    if (empty(self::$fieldsToHide)) {
        return 0;
    }

    // Converte a lista PHP para JSON para uso no JavaScript
    $fieldsJson = json_encode(self::$fieldsToHide, JSON_HEX_APOS | JSON_HEX_QUOT);

    ob_start(function ($html) use ($fieldsJson) {

        $script = <<<JSHIDE
<script>
(function() {
    'use strict';
    document.addEventListener('DOMContentLoaded', function() {

        var fieldsToHide = {$fieldsJson};

        for (var i = 0; i < fieldsToHide.length; i++) {
            var field    = fieldsToHide[i];
            var selector = field.selector  || '';
            var label    = field.viewLabel || '';

            // ── MODO EDIÇÃO / CRIAÇÃO ──
            if (selector) {
                // Busca o campo pelo seletor (#id)
                var el = document.querySelector(selector);
                if (el) {
                    // Esconde a linha <tr> inteira que contém o campo
                    var tr = el.closest('tr');
                    if (tr) {
                        tr.style.display = 'none';
                    }
                }

                // Busca também pela label[for="xxx"]
                var forAttr = selector.replace('#', '');
                var lbl = document.querySelector('label[for="' + forAttr + '"]');
                if (lbl) {
                    var trLabel = lbl.closest('tr');
                    if (trLabel) {
                        trLabel.style.display = 'none';
                    }
                }
            }

            // ── MODO VISUALIZAÇÃO ──
            if (label) {
                // Percorre todas as <td> que podem ser labels
                var allTds = document.querySelectorAll(
                    'td.titlefield, td.titlefieldmiddle, td.tdtop, table.border td:first-child'
                );
                for (var j = 0; j < allTds.length; j++) {
                    var td = allTds[j];
                    var text = (td.textContent || td.innerText || '').trim();
                    if (text === label) {
                        var trView = td.closest('tr');
                        if (trView) {
                            trView.style.display = 'none';
                        }
                    }
                }
            }
        }
    });
})();
</script>
JSHIDE;

        // Injeta o script antes de </body>
        $pos = strripos($html, '</body>');
        if ($pos !== false) {
            $html = substr($html, 0, $pos) . $script . substr($html, $pos);
        } else {
            $html .= $script;
        }

        return $html;
    });

    return 0;
}
```

### Explicação — O fluxo completo

```
1. doActionsThirdPartyCard() é chamado ANTES do HTML ser gerado

2. ob_start(callback) inicia a captura de saída

3. societe/card.php gera TODO o HTML normalmente (tabela, campos, botões, etc.)

4. Quando a página chama llxFooter() → ob_end_flush(), o callback executa

5. O callback recebe o HTML completo como string ($html)

6. Injeta o <script> antes de </body>

7. Retorna o HTML modificado

8. O navegador recebe o HTML, executa o <script> no DOMContentLoaded

9. O script percorre fieldsToHide e esconde cada campo
```

### Explicação — O JavaScript linha a linha

| Trecho | O que faz |
|---|---|
| `var fieldsToHide = {$fieldsJson}` | O PHP interpola o JSON dentro do JavaScript. Resultado: `var fieldsToHide = [{"selector":"#address","viewLabel":"Address"}]`. |
| `document.querySelector(selector)` | Busca o elemento pelo seletor CSS. `#address` encontra `<textarea id="address">`. |
| `el.closest('tr')` | Sobe na árvore DOM até encontrar a `<tr>` ancestral. É a linha inteira da tabela. |
| `tr.style.display = 'none'` | Esconde a linha CSS. O campo fica invisível mas ainda existe no DOM (não interfere no POST). |
| `document.querySelectorAll('td.titlefield, ...')` | No modo view, busca todas as `<td>` que podem ser labels de campos. |
| `text === label` | Compara o texto limpo da `<td>` com a `viewLabel` configurada. |

### Por que `ob_start() + <script>` em vez de manipular o PHP?

A página `societe/card.php` chama o hook `doActions()` **antes de gerar qualquer HTML**. Nesse ponto, o formulário ainda não existe. Portanto:

- Não podemos usar `echo` ou `print` (sairia antes da página)
- Não podemos modificar variáveis PHP da página (são locais)
- A única opção é capturar a saída via `ob_start()` e modificar antes de enviar ao navegador

---

## 5. Adicionar ou remover campos futuramente

Para ocultar um novo campo, basta adicionar uma linha ao array `$fieldsToHide`.

### Exemplo — Ocultar também Fax e URL

```php
private static $fieldsToHide = array(
    array('selector' => '#address',  'viewLabel' => 'Address'),
    array('selector' => '#fax',      'viewLabel' => 'Fax'),     // ← adicionado
    array('selector' => '#url',      'viewLabel' => 'Web'),     // ← adicionado
);
```

### Exemplo — Parar de ocultar o Endereço

```php
private static $fieldsToHide = array(
    // array('selector' => '#address',  'viewLabel' => 'Address'),  // ← comentado
    array('selector' => '#fax',      'viewLabel' => 'Fax'),
);
```

### Como não ocultar nada (desativar a funcionalidade)

```php
private static $fieldsToHide = array();  // array vazio → nada acontece
```

---

## Referência — Lista completa de campos disponíveis

Estes são os campos da página `societe/card.php` e seus seletores:

| Campo | `selector` | `viewLabel` | Presente em |
|---|---|---|---|
| Endereço | `#address` | `Address` | Edição + View |
| CEP | `#zipcode` | `Zip` | Criação + Edição + View |
| Cidade | `#town` | `Town` | Criação + Edição + View |
| País | `select[name="country_id"]` | `Country` | Criação + Edição + View |
| Estado | `#state_id` | `State` | Criação + Edição + View |
| Telefone | `#phone` | `Phone` | Criação + Edição + View |
| Celular | `#phone_mobile` | `PhoneMobile` | Criação + Edição + View |
| Fax | `#fax` | `Fax` | Criação + Edição + View |
| Website | `#url` | `Web` | Criação + Edição + View |
| E-mail | `#email` | `EMail` | Criação + Edição + View |
| Código de Barras | `#barcode` | `Gencod` | Criação + Edição + View |
| Status | `#status` | `Status` | Criação + Edição |
| N° IVA | `#tva_intra` | `VATIntra` | Criação + Edição + View |

> **Nota:** As labels `viewLabel` usam as chaves de tradução do Dolibarr em inglês (ex: `'Address'`, `'Zip'`). Se o seu Dolibarr estiver em português, o texto real na `<td>` pode ser diferente (ex: `'Endereço'`, `'CEP'`). Nesse caso, use o texto que aparece na tela como `viewLabel`, ou inspecione o HTML para confirmar.

---

## Diagrama — Arquitetura da solução

```
┌──────────────────────────────────────────────────────────┐
│  societe/card.php (código core — NÃO MODIFICADO)         │
│                                                          │
│  1. $hookmanager->initHooks(['thirdpartycard'])          │
│  2. $hookmanager->executeHooks('doActions', ...)         │
│                          │                               │
│                          ▼                               │
│  ┌────────────────────────────────────────┐              │
│  │  ActionsLabapp::doActions()            │              │
│  │  → detecta contexto 'thirdpartycard'  │              │
│  │  → chama doActionsThirdPartyCard()    │              │
│  │  → ob_start(callback)                │              │
│  └────────────────────────────────────────┘              │
│                                                          │
│  3. societe/card.php renderiza toda a página HTML        │
│                                                          │
│  4. ob_end_flush() → callback executa:                   │
│     ┌──────────────────────────────────────┐             │
│     │  Injeta <script> antes de </body>    │             │
│     │  O script esconde os campos          │             │
│     │  configurados em $fieldsToHide       │             │
│     └──────────────────────────────────────┘             │
│                                                          │
│  5. HTML final é enviado ao navegador                    │
└──────────────────────────────────────────────────────────┘
```

---

## Checklist de implementação

| # | Verificar | Como |
|---|---|---|
| 1 | Hook registrado | `modLabApp.class.php` → `module_parts['hooks']` contém `'thirdpartycard'` |
| 2 | Módulo reativado | Desativar + Ativar o módulo Lab Connecta no admin |
| 3 | Array configurado | `$fieldsToHide` contém ao menos um campo |
| 4 | Campo oculto na criação | Abrir `societe/card.php?action=create` — campo não deve aparecer |
| 5 | Campo oculto na edição | Abrir `societe/card.php?action=edit&socid=1` — campo não deve aparecer |
| 6 | Campo oculto na visualização | Abrir `societe/card.php?socid=1` — campo não deve aparecer |
| 7 | Outros campos intactos | Verificar que campos não listados em `$fieldsToHide` continuam visíveis |
