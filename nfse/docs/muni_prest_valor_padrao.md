# Como funciona: valor padrão do campo `muni_prest`

> **Nível:** Para qualquer pessoa, sem precisar saber programar.

---

## O que é esse campo?

`muni_prest` é um **campo extra** (extrafield) da fatura.
Ele guarda o nome do **município onde o serviço foi prestado**.

Na nota fiscal eletrônica (NFSe), esse campo é obrigatório.

---

## O problema que resolvemos

Antes, o campo ficava **em branco** quando uma fatura era aberta.
O usuário precisava digitar a cidade na mão, toda hora.

Isso era chato e causava erros (cidade errada, falta de preenchimento, etc.).

---

## O que o código faz agora?

Imagine que você tem um caderno de notas (a fatura).
O caderno tem um espaço em branco chamado "cidade do serviço".

O código funciona assim:

```
1. Abre o caderno da fatura
2. Olha se o espaço "cidade do serviço" está EM BRANCO
3. Se estiver em branco:
       → Pega o nome da cidade lá do cadastro da EMPRESA (emitente)
       → Escreve essa cidade no espaço em branco
       → Salva tudo
4. Se JÁ tiver alguma cidade escrita:
       → Não faz nada (não sobrescreve o que o usuário digitou)
```

---

## O código linha por linha

```php
// PASSO 1: Pega a fatura da tela atual
$fac = $object ?? new Facture($this->db);
if (empty($fac->id)) { $id = GETPOST('facid','int'); if ($id) $fac->fetch($id); }
```
> 🟢 `$object` é a fatura que já está aberta na tela.
> Se por acaso não existir, o código pega o número da fatura da URL (`facid`) e busca ela no banco de dados.

---

```php
// PASSO 2: Carrega os campos extras da fatura
if (method_exists($fac,'fetch_optionals')) $fac->fetch_optionals();
```
> 🟢 Os campos extras (extrafields) não vêm carregados automaticamente.
> `fetch_optionals()` é como "abrir a gaveta de anotações extras" do caderno.
> Sem isso, o campo `muni_prest` aparece como vazio mesmo que já tenha valor.

---

```php
// PASSO 3: Só preenche se estiver vazio (não sobrescreve o que já existe)
if (empty($fac->array_options['options_muni_prest']) && !empty($fac->id)) {
```
> 🟢 Verifica duas coisas ao mesmo tempo:
> - O campo `muni_prest` está **vazio**? (`empty(...)`)  
> - A fatura tem um **ID válido**? (`!empty($fac->id)`)  
> Só continua se as DUAS forem verdadeiras.

---

```php
    global $mysoc;
    $cidadeEmitente = $mysoc->town ?? '';
```
> 🟢 `$mysoc` é o objeto com os dados da **sua empresa** (a que emite a nota).
> `$mysoc->town` = a cidade cadastrada no Dolibarr em **Configurações → Empresa**.  
> O `?? ''` significa: "se não existir, usa string vazia" (evita erro).

---

```php
    if (!empty($cidadeEmitente)) {
        $fac->array_options['options_muni_prest'] = $cidadeEmitente; // define na memória
        $fac->updateExtraField('muni_prest'); // salva no banco de dados
    }
```
> 🟢 **Linha 1:** Escreve a cidade no campo dentro do objeto PHP (só na memória, ainda não salvou).  
> 🟢 **Linha 2:** `updateExtraField()` salva de verdade no banco de dados (`MySQL/MariaDB`).  
> Sem a segunda linha, a mudança seria perdida ao fechar a página.

---

## Resumo visual

```
Fatura aberta
     │
     ▼
muni_prest vazio?
  ├─ NÃO → não faz nada (respeita o valor que já está lá)
  └─ SIM → pega $mysoc->town (cidade da empresa emitente)
                 │
                 ▼
           Grava no campo
                 │
                 ▼
         updateExtraField() → salva no banco
```

---

## Onde fica cada coisa?

| O quê | Onde no Dolibarr |
|---|---|
| `$mysoc->town` | Configurações → Empresa → Campo "Cidade" |
| `muni_prest` (extrafield) | Admin → Faturas de clientes → Atributos extras |
| Valor salvo no banco | Tabela `llx_facture_extrafields`, coluna `muni_prest` |

---

## Atenção

- O preenchimento automático só acontece **uma vez**: quando o campo está vazio.
- Se o usuário digitar outra cidade manualmente, esse valor é preservado.
- A cidade vem do cadastro da empresa. Se estiver errada, corrija em **Configurações → Empresa**.
