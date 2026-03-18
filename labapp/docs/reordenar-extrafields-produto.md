# Como reordenar Extrafields de Produto no Dolibarr (sem tocar no código raiz)

> **Para quem é este guia?**  
> Para qualquer pessoa que nunca mexeu com hooks ou PHP antes.  
> Vamos explicar cada parte como se você tivesse 5 anos e nunca tivesse visto código.

---

## 📖 O que é um Extrafield?

Quando você cria um Produto no Dolibarr, a tela mostra campos como:
- **Nome** (Label)
- **Referência**
- **Descrição**
- **Preço**
- ... e vários outros

Os **Extrafields** são campos **extras** que você mesmo criou (ex: "Código NCM", "CSOSN", "Código de Serviço").  
Por padrão, o Dolibarr coloca esses campos **no final do formulário**, bem longe do campo Descrição.

**O problema:** Queremos que eles apareçam logo **abaixo da Descrição**, para facilitar o preenchimento.

---

## 🧩 Por que não dá para simplesmente mover no PHP?

Imagine que o Dolibarr imprime a tela como se fosse uma cozinha montando um prato:

1. Primeiro coloca o pão (campos nativos: Nome, Referência, Descrição, Preço...)
2. Depois coloca a cobertura (Extrafields)

Quando chegamos no passo 2, o pão **já foi servido** — o PHP já enviou aquele HTML para o navegador.  
Não tem como voltar e inserir algo no meio do pão depois que foi servido.

**A solução** é diferente: deixamos tudo ser servido e depois, no navegador, **reorganizamos o prato** usando JavaScript.

---

## 🗺️ Mapa dos arquivos envolvidos

```
htdocs/
└── custom/
    └── labapp/
        ├── class/
        │   └── actions_labapp.class.php   ← AQUI fica o código do hook
        ├── core/
        │   └── modules/
        │       └── modLabApp.class.php    ← Declara em quais páginas o hook está ativo
        └── docs/
            └── reordenar-extrafields-produto.md   ← Este arquivo
```

---

## 🔧 Como funciona passo a passo

### Passo 1 — O módulo diz ao Dolibarr: "me avise quando abrir a tela de produto"

**Arquivo:** `custom/labapp/core/modules/modLabApp.class.php`

```php
$this->module_parts = array(
    'hooks' => array(
        'productcard',   // ← Esta linha registra o hook na tela de produto
        'productedit',   // ← E na edição do produto
        // ... outros contextos ...
    ),
);
```

**Em linguagem de criança:**  
É como colocar o seu nome na lista de convidados de uma festa. Quando a festa (tela de produto) começar, o Dolibarr vai te chamar.

---

### Passo 2 — O Dolibarr abre a tela e "liga" para todos os convidados

Dentro de `product/card.php` (arquivo do núcleo, **não mexemos aqui**), existe esta linha:

```php
$reshook = $hookmanager->executeHooks('formObjectOptions', $parameters, $object, $action);
```

**Em linguagem de criança:**  
O Dolibarr termina de montar o formulário e grita: "Ei, todos os módulos! Hora de adicionar seus extras!"  
O método `formObjectOptions` da nossa classe é chamado nesse momento.

---

### Passo 3 — Nossa função é chamada e injeta o JavaScript

**Arquivo:** `custom/labapp/class/actions_labapp.class.php`  
**Método:** `formObjectOptions`

```php
public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
{
    $contexts = explode(':', $parameters['context']);

    // Só age quando for tela de produto
    if (!array_intersect($contexts, array('productcard', 'productedit'))) {
        return 0;
    }

    // Só age nos modos de formulário editável
    if (!in_array($action, array('create', 'edit', ''))) {
        return 0;
    }

    // Imprime o script JS na página
    print <<<'JS'
    <script> ... (código de reordenação) ... </script>
    JS;

    return 0;
}
```

**Em linguagem de criança:**  
Quando o Dolibarr nos chama, verificamos: "Estou na tela de produto e em modo de edição/criação?"  
- Se **SIM**: colocamos um bilhetinho JavaScript na página antes de terminar.  
- Se **NÃO**: não fazemos nada (return 0 = "tudo bem, pode continuar").

---

### Passo 4 — O JavaScript reorganiza o formulário no navegador

Depois que a página carregou completamente no navegador do usuário, o JS executa:

```javascript
document.addEventListener('DOMContentLoaded', function () {

    // 1. Encontra a linha da Descrição
    var descEditor = document.querySelector('textarea[name="desc"]');
    var descRow = descEditor.closest('tr');

    // 2. Encontra todas as linhas de extrafields
    var allRows = document.querySelectorAll('tr[class*="field_options_"]');

    // 3. Move cada linha para logo após a Descrição
    var insertAfter = descRow;
    for (var j = 0; j < allRows.length; j++) {
        var row = allRows[j];
        insertAfter.parentNode.insertBefore(row, insertAfter.nextSibling);
        insertAfter = row;
    }
});
```

**Em linguagem de criança:**  
Imagina que o formulário é uma fila de pessoas. O JavaScript entra na fila, pega todas as pessoas dos "extrafields" (que estavam no final), e as coloca logo atrás da pessoa "Descrição".

---

## 🔍 Como o JS encontra a linha de Descrição?

O Dolibarr gera este HTML para o campo Descrição:

```html
<tr>
  <td class="tdtop">Description</td>
  <td>
    <textarea name="desc">...</textarea>
  </td>
</tr>
```

O JS procura primeiro pelo `<textarea name="desc">` (mais confiável) e sobe para encontrar o `<tr>` pai.  
Se o editor rico (CKEditor) estiver ativo, o `<textarea>` fica escondido — por isso temos um fallback que busca pelo texto da `<td>`: "Description", "Descrição" ou "Descripción".

---

## 🔍 Como o JS encontra as linhas de Extrafields?

O Dolibarr gera este HTML para cada extrafield:

```html
<tr class="oddeven field_options_NOME_DO_CAMPO ...">
  <td>Nome do Campo</td>
  <td><input ...></td>
</tr>
```

O seletor `tr[class*="field_options_"]` significa:  
> "Encontre todos os `<tr>` que tenham a palavra `field_options_` em qualquer lugar da sua `class`."

---

## ✅ Resultado

**Antes:**
```
[ Nome / Label          ]
[ Referência            ]
[ Preço                 ]
[ Descrição             ]  ← campo nativo
[ URL Pública           ]
[ Estoque padrão        ]
[ Peso                  ]
[ ... outros nativos... ]
[ NCM                   ]  ← extrafield (estava aqui, longe)
[ CSOSN                 ]  ← extrafield
[ Código de Serviço     ]  ← extrafield
```

**Depois:**
```
[ Nome / Label          ]
[ Referência            ]
[ Preço                 ]
[ Descrição             ]  ← campo nativo
[ NCM                   ]  ← extrafield (movido para cá ✓)
[ CSOSN                 ]  ← extrafield
[ Código de Serviço     ]  ← extrafield
[ URL Pública           ]
[ Estoque padrão        ]
[ Peso                  ]
[ ... outros nativos... ]
```

---

## 🛡️ Por que esta abordagem é segura?

| Critério | Status |
|---|---|
| Edita arquivos do núcleo Dolibarr? | ❌ Não |
| Funciona após atualização do Dolibarr? | ✅ Sim |
| Afeta outras telas? | ❌ Não (só `productcard` e `productedit`) |
| Quebra se não houver extrafields? | ❌ Não (o JS verifica antes de agir) |
| Funciona com CKEditor ativo? | ✅ Sim (há fallback por texto de label) |

---

## 🐛 Como depurar se não funcionar

1. Abra a tela de criar produto no navegador
2. Pressione **F12** para abrir as ferramentas do desenvolvedor
3. Vá para a aba **Console**
4. Se houver erros JS relacionados a `field_options`, o problema está no seletor
5. Na aba **Elements**, procure por `<tr class="... field_options_` para confirmar que o Dolibarr está gerando as classes esperadas

---

## 🔄 Como adicionar mais campos para mover

Não precisa fazer nada! O seletor `tr[class*="field_options_"]` pega **todos** os extrafields automaticamente.  
Quando você criar novos extrafields de produto no admin do Dolibarr, eles já vão aparecer abaixo da Descrição.

---

## 📁 Arquivos modificados nesta implementação

| Arquivo | Tipo | O que foi feito |
|---|---|---|
| `custom/labapp/class/actions_labapp.class.php` | Módulo customizado | Adicionado método `formObjectOptions` com o JS de reordenação |
| `custom/labapp/docs/reordenar-extrafields-produto.md` | Documentação | Este arquivo |

> ⚠️ **Nenhum arquivo do núcleo do Dolibarr foi modificado.**
