# Validador Modular de NFS-e Nacional

## Visão Geral

O validador (`nfse_nacional_validator.lib.php`) verifica **todos os campos obrigatórios** antes de montar e enviar o XML da NFS-e ao webservice da SEFAZ. Ele espelha exatamente a lógica do código de emissão (`emissao_nfse_nacional.php`): cada campo que a emissão usa para montar uma tag XML tem uma regra correspondente no validador.

**Objetivo:** evitar rejeições em produção detectando dados faltantes ou inválidos antes do envio.

---

## Arquitetura

```
                     ┌─────────────────────────────┐
                     │     validarDadosNfseNacional │  ← função principal (mesma assinatura de antes)
                     │         (MOTOR)              │
                     └────────────┬────────────────┘
                                  │
                     ┌────────────▼────────────────┐
                     │    nfseNacRegistrarRegras()  │  ← array de regras
                     │                              │
                     │  ┌─────────────────────────┐ │
                     │  │ Regra 1 (emit_cnpj)     │ │
                     │  │ Regra 2 (emit_municipio) │ │
                     │  │ Regra 3 (emit_regime)    │ │
                     │  │ Regra 4 (toma_cnpjcpf)   │ │
                     │  │ ...                       │ │
                     │  │ Regra N (evento_dtfim)    │ │
                     │  └─────────────────────────┘ │
                     └─────────────────────────────┘
```

### Componentes

| Componente | Função | Arquivo |
|---|---|---|
| **Motor** | `validarDadosNfseNacional()` — itera as regras e coleta erros | `nfse_nacional_validator.lib.php` |
| **Regras** | `nfseNacRegistrarRegras()` — array com todas as validações | `nfse_nacional_validator.lib.php` |
| **Listas** | `nfseNacGetCodigosObra()` / `nfseNacGetCodigosEvento()` — listas centralizadas | `nfse_nacional_validator.lib.php` |
| **Emissão** | `gerarNfseNacional()` — usa o validador antes de montar o XML | `emissao_nfse_nacional.php` |

---

## Estrutura de uma Regra

Cada regra é um array associativo dentro de `nfseNacRegistrarRegras()`:

```php
[
    'id'       => 'toma_bairro',                              // ID único (snake_case)
    'grupo'    => 'tomador',                                   // Grupo/seção do XML
    'label'    => 'Bairro',                                    // Nome amigável do campo (exibido ao usuário)
    'campo'    => 'dadosDestinatario.bairro',                  // Campo de origem dos dados
    'tagXml'   => 'infDPS > toma > end > xBairro',            // Tag XML afetada
    'mensagem' => '[Tomador] Bairro não informado...',         // Mensagem técnica (vai para o log)
    'quando'   => function ($ctx) { return true; },            // Quando aplicar (condição)
    'validar'  => function ($ctx) {                            // Lógica de validação
        return !empty(trim($ctx['dadosDestinatario']['bairro'] ?? ''));
    },
],
```

### Campos obrigatórios da regra

| Campo | Tipo | Descrição |
|---|---|---|
| `id` | `string` | Identificador único. Convenção: `grupo_campo` (ex: `emit_cnpj`, `toma_nome`) |
| `grupo` | `string` | Grupo lógico: `emitente`, `tomador`, `servico`, `valores`, `tributacao`, `obra`, `evento` |
| `label` | `string` | Nome amigável do campo, exibido ao usuário na mensagem consolidada (ex: `Bairro`, `CNPJ`) |
| `campo` | `string` | Caminho legível do dado de origem (para documentação) |
| `tagXml` | `string` | Tag(s) XML que ficaria(m) vazia(s) se o campo não for preenchido |
| `mensagem` | `string` | Mensagem técnica detalhada — vai apenas para o log, **não** é exibida ao usuário |
| `quando` | `callable($ctx): bool` | Condição para aplicar a regra. `true` = sempre aplica |
| `validar` | `callable($ctx): bool` | Retorna `true` se válido, `false` se inválido |

### Campo opcional

| Campo | Tipo | Descrição |
|---|---|---|
| `_mensagem_dinamica` | `callable($ctx): string` | Gera mensagem de erro com dados do contexto (ex: código do serviço) |

---

## Contexto (`$ctx`)

O contexto é um array montado automaticamente pelo motor e passado para todas as regras. Contém os dados brutos + valores pré-calculados:

```php
$ctx = [
    // Dados brutos (passados pela emissão)
    'db'                  => $db,                    // Conexão com banco
    'dadosFatura'         => [...],                  // Dados da fatura
    'dadosEmitente'       => [...],                  // Dados da empresa
    'dadosDestinatario'   => [...],                  // Dados do cliente
    'listaServicos'       => [...],                  // Serviços da fatura
    'numeroCustom'        => null,                   // Número manual (opcional)

    // Pré-calculados (evita repetição nas regras)
    'cnpjEmitente'        => '12345678000199',       // CNPJ limpo (só dígitos)
    'cnpjcpfTomador'      => '12345678901',          // CPF/CNPJ limpo
    'codigoServico'       => '010201',               // Código do 1º serviço
    'valorTotal'          => 1500.00,                // Soma dos valores
    'descricaoConcat'     => 'Consultoria. Suporte', // Descrições concatenadas
    'crt'                 => 1,                      // CRT do emitente
    'regimeTributacao'    => 6,                      // Regime tributário
];
```

---

## Como Adicionar uma Nova Regra

### Passo 1: Identifique a necessidade

Olhe no código de emissão (`emissao_nfse_nacional.php`) qual campo está sendo usado para montar o XML. Exemplo: a emissão faz `$std->infDPS->toma->end->endNac->CEP = $cepTomador`.

### Passo 2: Crie a regra

Abra `nfse_nacional_validator.lib.php`, localize a função `nfseNacRegistrarRegras()` e adicione um novo bloco no grupo correto:

```php
// Dentro de nfseNacRegistrarRegras(), no grupo TOMADOR:

[
    'id'       => 'toma_cep',
    'grupo'    => 'tomador',
    'label'    => 'CEP',
    'campo'    => 'dadosDestinatario.cep',
    'tagXml'   => 'infDPS > toma > end > endNac > CEP',
    'mensagem' => '[Tomador] CEP não informado ou inválido (tag CEP). Deve ter 8 dígitos.',
    'quando'   => function ($ctx) { return true; },
    'validar'  => function ($ctx) {
        $cep = preg_replace('/\D/', '', $ctx['dadosDestinatario']['cep'] ?? '');
        return strlen($cep) === 8;
    },
],
```

### Passo 3: Pronto

Não é preciso alterar mais nada. O motor executa todas as regras registradas automaticamente.

---

## Regras com Condição (`quando`)

Algumas regras só devem ser executadas em determinadas situações. Exemplos:

### Regra só para Simples Nacional (CRT=1):
```php
'quando' => function ($ctx) {
    return $ctx['crt'] === 1;
},
```

### Regra só para códigos de construção civil (Obra):
```php
'quando' => function ($ctx) {
    return !empty($ctx['codigoServico'])
        && in_array($ctx['codigoServico'], nfseNacGetCodigosObra(), true);
},
```

### Regra só quando ISS é retido:
```php
'quando' => function ($ctx) {
    if (empty($ctx['codigoServico']) || $ctx['codigoServico'] === '990101') {
        return false;
    }
    $issRetido = (int)($ctx['listaServicos'][0]['extrafields']['iss_retido'] ?? -1);
    return $issRetido === 1;
},
```

---

## Mensagens Dinâmicas

Quando a mensagem de erro precisa incluir dados variáveis (como o código do serviço), use `_mensagem_dinamica`:

```php
[
    'id'       => 'obra_cObra',
    'grupo'    => 'obra',
    'mensagem' => '',  // vazio — será substituído pela dinâmica
    '_mensagem_dinamica' => function ($ctx) {
        return '[Obra] O código "' . $ctx['codigoServico']
             . '" exige o campo cObra preenchido.';
    },
    // ...
],
```

A `_mensagem_dinamica` tem prioridade sobre `mensagem` quando ambas existem.

---

## Mensagens Amigáveis Consolidadas

O motor **não** exibe uma mensagem por regra. Em vez disso, agrupa os campos que falharam por grupo e monta **uma única mensagem amigável** por grupo.

### Exemplo

Se o tomador não tiver Bairro, Município e Endereço, o usuário verá:

> **Preencha os campos Endereço, Bairro e Município no cadastro do cliente.**

Em vez de 3 mensagens separadas.

### Templates por grupo

| Grupo | Template |
|---|---|
| `emitente` | Preencha os campos {campos} nos dados da empresa. |
| `tomador` | Preencha os campos {campos} no cadastro do cliente. |
| `servico` | Preencha os campos {campos} no cadastro do produto/serviço. |
| `valores` | Preencha os campos {campos} nos itens da fatura. |
| `tributacao` | Preencha os campos {campos} no cadastro do serviço. |
| `obra` | Preencha os campos {campos} nos dados adicionais da fatura. |
| `evento` | Preencha os campos {campos} nos dados adicionais da fatura. |

### Labels

Cada regra tem um campo `label` que define o nome amigável do campo (ex: `'Bairro'`, `'CNPJ'`, `'Data Início'`). Quando vários campos do mesmo grupo falham, os labels são concatenados com vírgula e "e".

### Log técnico

As mensagens técnicas detalhadas (com tags XML, prefixo `[Grupo]`, etc.) continuam sendo registradas no `error_log` para debug, mas **nunca** são exibidas ao usuário.

---

## Listas Centralizadas

As listas de códigos de serviço que exigem Obra ou Evento estão centralizadas em duas funções:

```php
nfseNacGetCodigosObra()    // Códigos de construção civil
nfseNacGetCodigosEvento()  // Códigos de eventos
```

Essas funções são usadas em 3 lugares:
1. **Validador** — para decidir se campos de obra/evento são obrigatórios
2. **Emissão** (`emissao_nfse_nacional.php`) — para montar os grupos obra/evento no XML
3. **Tela da fatura** (`actions_nfse.class.php` → `formObjectOptions`) — para mostrar/ocultar campos

### Para adicionar um novo código de serviço de obra:

Edite `nfseNacGetCodigosObra()` no arquivo `nfse_nacional_validator.lib.php`:

```php
function nfseNacGetCodigosObra()
{
    return [
        '070201', '070202', '070401', '070501', '070502',
        '070601', '070602', '070701', '070801', '071701',
        '071901', '141403', '141404',
        '999999',  // ← adicione aqui
    ];
}
```

A alteração reflete automaticamente na validação, emissão e interface.

---

## Regras Existentes (Referência)

| ID | Grupo | Tag XML | Condição |
|---|---|---|---|
| `emit_cnpj` | emitente | `prest > CNPJ` | sempre |
| `emit_municipio` | emitente | `cLocEmi` | sempre |
| `emit_regime_simples` | emitente | `prest > regTrib > opSimpNac` | CRT = 1 |
| `toma_cnpjcpf` | tomador | `toma > CNPJ/CPF` | sempre |
| `toma_cnpjcpf_tamanho` | tomador | `toma > CNPJ/CPF` | se documento informado |
| `toma_nome` | tomador | `toma > xNome` | sempre |
| `toma_endereco` | tomador | `toma > end > xLgr` | sempre |
| `toma_bairro` | tomador | `toma > end > xBairro` | sempre |
| `toma_municipio` | tomador | `toma > end > endNac > cMun` | sempre |
| `toma_cep` | tomador | `toma > end > endNac > CEP` | sempre |
| `serv_codigo` | servico | `serv > cServ > cTribNac` | sempre |
| `serv_descricao` | servico | `serv > cServ > xDescServ` | sempre |
| `serv_cnbs` | servico | `serv > cServ > cNBS` | sempre |
| `val_total_positivo` | valores | `valores > vServPrest > vServ` | sempre |
| `trib_iss_retido_preenchido` | tributacao | `trib > tribMun > tpRetISSQN` | código ≠ 990101 |
| `trib_iss_retido_aliquota` | tributacao | `trib > tribMun > pAliq` | ISS retido = 1 |
| `obra_cObra` | obra | `serv > obra > cObra` | código na lista de obra |
| `evento_xnome` | evento | `serv > atvevento > xNome` | código na lista de evento |
| `evento_dtini` | evento | `serv > atvevento > dtIni` | código na lista de evento |
| `evento_dtfim` | evento | `serv > atvevento > dtFim` | código na lista de evento |
| `evento_cep` | evento | `serv > atvevento > end > CEP` | código na lista de evento |
| `evento_xlgr` | evento | `serv > atvevento > end > xLgr` | código na lista de evento |
| `evento_nro` | evento | `serv > atvevento > end > nro` | código na lista de evento |
| `evento_xbairro` | evento | `serv > atvevento > end > xBairro` | código na lista de evento |

---

## Fluxo de Execução

```
Usuário clica "Gerar NFS-e"
       │
       ▼
actions_nfse.class.php → doActions('gerarnfsenacional')
       │
       ▼
Monta arrays: dadosFatura, dadosEmitente, dadosDestinatario, listaServicos
       │
       ▼
gerarNfseNacional() em emissao_nfse_nacional.php
       │
       ▼
validarDadosNfseNacional()  ← VALIDADOR
       │
       ├── Monta contexto ($ctx)
       ├── Carrega regras (nfseNacRegistrarRegras)
       ├── Para cada regra:
       │     ├── quando($ctx) == false? → pula
       │     └── validar($ctx) == false? → agrupa label no grupo
       │
       ├── Consolida labels por grupo em mensagens amigáveis
       │     ex: "Preencha os campos Bairro e Município no cadastro do cliente."
       │
       ├── Loga mensagens técnicas detalhadas (error_log)
       │
       ├── Erros encontrados? → setEventMessages() e retorna
       └── Sem erros? → continua montagem do XML e envio
```

---

## Debugando Erros

Quando o validador encontra erros, ele registra no log do PHP:

```
[NFSE VALIDADOR] 2 erro(s) na fatura 123:
[NFSE VALIDADOR]   → [Tomador] Bairro não informado (tag xBairro)...
[NFSE VALIDADOR]   → [Tributação] ISS marcado como RETIDO mas...
```

Verifique o log em `php_error.log` ou no log do Apache (`error.log`).

---

## Perguntas Frequentes

### Posso desabilitar uma regra temporariamente?
Sim. Altere o `quando` para retornar `false`:
```php
'quando' => function ($ctx) { return false; }, // DESABILITADA
```

### Posso adicionar regra que faz query no banco?
Sim. O `$ctx['db']` contém a conexão com o banco. Veja a regra `trib_iss_retido_aliquota` como exemplo.

### A ordem das regras importa?
Sim, as regras são executadas na ordem em que aparecem no array. Os erros são retornados na mesma ordem.

### Preciso alterar algo no código de emissão ao adicionar uma regra?
Não. O validador é chamado automaticamente antes da emissão. Basta adicionar a regra no array.

---

## Correções no Código de Emissão (XSD)

Além do validador, foram aplicadas correções no `emissao_nfse_nacional.php` para garantir conformidade total com o schema XSD `DPS_v1.01.xsd`:

| Correção | Antes | Depois |
|---|---|---|
| **totTrib para Regime Normal** | `pTotTribSN` só era gerado para CRT=1; regime normal ficava sem `totTrib` | Para CRT≠1, define `indTotTrib = 0` (sem estimativa, conforme Decreto 8.264/2014) |
| **tpRetISSQN para 990101** | Código 990101 (não incidência) ficava sem `tpRetISSQN`, campo obrigatório no XSD | Define `tpRetISSQN = 1` (Não Retido) automaticamente |
| **cNBS** | Tag `cNBS` era comentada/ignorada | Lê `cod_nbs` da tabela `nfse_codigo_servico` e preenche `cServ > cNBS` |

### Tabela `nfse_codigo_servico` — nova coluna `cod_nbs`

A coluna `cod_nbs VARCHAR(9)` foi adicionada à tabela de serviços para armazenar o Código NBS (Nomenclatura Brasileira de Serviços). É preenchido na tela **Serviços e Alíquotas** e usado pelo validador (`serv_cnbs`) e pela emissão.
