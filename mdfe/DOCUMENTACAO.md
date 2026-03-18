# Módulo MDF-e – Documentação Completa

> **Para quem é este guia?**  
> Para qualquer pessoa que precise entender, manter ou expandir o módulo MDF-e no Dolibarr, mesmo com pouco conhecimento de programação.  
> Linguagem simples, exemplos práticos e analogias do mundo real.

---

## Índice

1. [O que é um MDF-e?](#1-o-que-é-um-mdfe)
2. [Estrutura de arquivos do módulo](#2-estrutura-de-arquivos-do-módulo)
3. [Tabelas do banco de dados](#3-tabelas-do-banco-de-dados)
4. [Fluxo de vida de um MDF-e](#4-fluxo-de-vida-de-um-mdfe)
5. [Endpoints (arquivos de ação)](#5-endpoints-arquivos-de-ação)
6. [Sistema de eventos](#6-sistema-de-eventos)
7. [Como adicionar um novo tipo de evento](#7-como-adicionar-um-novo-tipo-de-evento)
8. [Consulta / Visualização de um MDF-e](#8-consulta--visualização-de-um-mdfe)
9. [Certificado digital](#9-certificado-digital)
10. [Perguntas frequentes (FAQ)](#10-perguntas-frequentes-faq)

---

## 1. O que é um MDF-e?

**MDF-e (Manifesto Eletrônico de Documentos Fiscais)** é um documento eletrônico obrigatório para transportadoras.  
Pense nele como um **"rosto da carga no caminhão"** — lista todos os documentos fiscais (NF-e, CT-e) que estão sendo transportados em determinada viagem.

### Por que é necessário?
A fiscalização federal brasileira exige que, ao percorrer rodovias federais, o motorista apresente o MDF-e validado com os documentos que ele carrega.

### Quem emite?
Transportadoras e empresas que fazem o transporte de mercadorias próprias.

---

## 2. Estrutura de arquivos do módulo

```
custom/mdfe/
│
├── DOCUMENTACAO.md          ← Este arquivo
├── mdfe_list.php            ← Listagem de todos os MDF-es emitidos
├── mdfe_consulta.php        ← Exibe os detalhes de um MDF-e (abre em modal)
│
├── mdfe_incluir_dfe.php     ← Endpoint: inclui uma NF-e num MDF-e já autorizado
├── mdfe_incluir_condutor.php← Endpoint: inclui um condutor num MDF-e já autorizado
├── mdfe_encerrar.php        ← Endpoint: encerra o MDF-e
├── mdfe_cancelar.php        ← Endpoint: cancela o MDF-e
│
└── core/
    └── modules/
        └── modMDFe.class.php← Definição do módulo: instalação, tabelas, permissões
```

> **Endpoint** = um arquivo PHP que recebe uma requisição (normalmente via AJAX/JavaScript), executa uma ação no servidor (como comunicar com a SEFAZ) e devolve um resultado em JSON.

---

## 3. Tabelas do banco de dados

O módulo usa as seguintes tabelas. Todas são criadas automaticamente quando você ativa o módulo pela primeira vez no Dolibarr (`Admin → Módulos → MDF-e → Ativar`).

> **Prefixo**: No Dolibarr, todas as tabelas podem ter um prefixo (ex: `llx_`). Nos arquivos PHP isso é escrito como `MAIN_DB_PREFIX`. Nos exemplos abaixo, usamos o nome sem prefixo.

---

### 3.1 `mdfe_emitidas`

Cada linha representa **um MDF-e emitido**. É a tabela principal.

| Coluna | Tipo | O que guarda |
|--------|------|--------------|
| `id` | INT | Identificador único (gerado automaticamente) |
| `chave_acesso` | VARCHAR(44) | A "impressão digital" do documento (44 números) |
| `numero` | VARCHAR | Número do MDF-e |
| `serie` | VARCHAR | Série do MDF-e |
| `status` | VARCHAR | Estado atual: `rascunho`, `autorizada`, `encerrada`, `cancelada` |
| `xml_emitido` | LONGTEXT | O XML completo assinado e enviado à SEFAZ |
| `protocolo` | VARCHAR | Protocolo retornado pela SEFAZ após autorização |
| `motivo_cancelamento` | TEXT | Justificativa informada ao cancelar (preenchido só se cancelado) |
| `data_encerramento` | DATETIME | Quando foi encerrado (se encerrado) |
| `protocolo_encerramento` | VARCHAR | Protocolo do encerramento devolvido pela SEFAZ |

---

### 3.2 `mdfe_eventos`

Registra **todos os eventos** que aconteceram com cada MDF-e após a autorização. É o "diário de bordo" do documento.

| Coluna | Tipo | O que guarda |
|--------|------|--------------|
| `id` | INT | Identificador único |
| `fk_mdfe_emitida` | INT | Qual MDF-e esse evento pertence (referência à tabela `mdfe_emitidas.id`) |
| `tpEvento` | VARCHAR(6) | Código do tipo de evento (veja tabela abaixo) |
| `nSeqEvento` | INT | Número de sequência do evento **dentro do mesmo tipo** |
| `protocolo_evento` | VARCHAR | Protocolo retornado pela SEFAZ para este evento |
| `motivo_evento` | VARCHAR | Texto de resposta da SEFAZ (ex: "Evento registrado e vinculado ao MDF-e") |
| `data_evento` | DATETIME | Quando ocorreu |
| `xml_requisicao` | LONGTEXT | XML enviado à SEFAZ |
| `xml_resposta` | LONGTEXT | XML de resposta recebido da SEFAZ |

**Códigos de tipo de evento (`tpEvento`):**

| Código | Descrição |
|--------|-----------|
| `110111` | Cancelamento |
| `110112` | Encerramento |
| `110114` | Inclusão de Condutor |
| `110115` | Inclusão de NF-e (DF-e) |

---

### 3.3 `mdfe_inclusao_nfe`

Guarda os dados estruturados de cada **NF-e incluída via evento** (após autorização do MDF-e).

> **Por que existe se já tem `mdfe_eventos`?**  
> A tabela `mdfe_eventos` guarda os XMLs brutos. Esta tabela existe para consultar os dados de forma rápida sem precisar analisar o XML.

| Coluna | Tipo | O que guarda |
|--------|------|--------------|
| `id` | INT | Identificador único |
| `fk_mdfe_emitida` | INT | Qual MDF-e pertence |
| `chave_mdfe` | VARCHAR(44) | Chave de acesso do MDF-e |
| `protocolo_mdfe` | VARCHAR | Protocolo do MDF-e |
| `nSeqEvento` | INT | Sequência do evento de inclusão |
| `cMunCarrega` | VARCHAR(7) | Código IBGE do município de carregamento |
| `xMunCarrega` | VARCHAR | Nome do município de carregamento |
| `cMunDescarga` | VARCHAR(7) | Código IBGE do município de descarga |
| `xMunDescarga` | VARCHAR | Nome do município de descarga |
| `chNFe` | VARCHAR(44) | Chave de acesso da NF-e incluída |
| `protocolo_evento` | VARCHAR | Protocolo SEFAZ do evento de inclusão |
| `cStat` | VARCHAR | Código de status da SEFAZ (ex: `135` = sucesso) |
| `xMotivo` | VARCHAR | Texto de retorno da SEFAZ |
| `data_evento` | DATETIME | Data/hora da inclusão |
| `xml_requisicao` | LONGTEXT | XML enviado |
| `xml_resposta` | LONGTEXT | XML recebido |
| `criado_em` | DATETIME | Data de criação do registro |

---

### 3.4 `mdfe_inclusao_condutor`

Guarda os dados de cada **condutor incluído via evento**.

| Coluna | Tipo | O que guarda |
|--------|------|--------------|
| `id` | INT | Identificador único |
| `fk_mdfe_emitida` | INT | Qual MDF-e pertence |
| `chave_mdfe` | VARCHAR(44) | Chave do MDF-e |
| `nSeqEvento` | INT | Sequência do evento |
| `xNome` | VARCHAR(60) | Nome completo do condutor |
| `cpf` | VARCHAR(11) | CPF do condutor (apenas números) |
| `protocolo_evento` | VARCHAR | Protocolo SEFAZ |
| `cStat` | VARCHAR | Código de status (`135` = sucesso) |
| `xMotivo` | VARCHAR | Texto retornado pela SEFAZ |
| `data_evento` | DATETIME | Data/hora da inclusão |
| `xml_requisicao` | LONGTEXT | XML enviado |
| `xml_resposta` | LONGTEXT | XML recebido |
| `criado_em` | DATETIME | Data de criação |

---

## 4. Fluxo de vida de um MDF-e

```
[ Rascunho ]
     │
     ▼  (usuário envia à SEFAZ)
[ Autorizada ]  ◄── aqui podem ocorrer eventos:
     │                 • Incluir NF-e
     │                 • Incluir Condutor
     │
     ├──► [ Cancelada ]  (evento 110111)
     │
     └──► [ Encerrada ]  (evento 110112)
```

- Um MDF-e **só pode ter eventos** quando está com status `autorizada`.
- Após **encerrado** ou **cancelado**, nenhuma ação adicional é possível.

---

## 5. Endpoints (arquivos de ação)

### 5.1 `mdfe_incluir_dfe.php` — Incluir NF-e

**O que faz:** Registra na SEFAZ que uma nova NF-e foi carregada no caminhão após a emissão do MDF-e.

**Ações disponíveis:**

| Parâmetro `action` | O que faz |
|--------------------|-----------|
| `buscar_cidades` | Consulta o IBGE para retornar nome e código de um município pelo código |
| `incluir_dfe` | Executa a inclusão da NF-e na SEFAZ |

**Parâmetros para `incluir_dfe`:**

```
POST mdfe_incluir_dfe.php
  action         = incluir_dfe
  id             = 42               ← ID do MDF-e na tabela mdfe_emitidas
  cMunCarrega    = 3550308          ← Código IBGE do município de carregamento
  xMunCarrega    = São Paulo        ← Nome do município de carregamento
  cMunDescarga   = 4106902          ← Código IBGE do município de descarga
  xMunDescarga   = Curitiba         ← Nome do município de descarga
  chNFe          = 35240100000000...← Chave de acesso da NF-e (44 dígitos)
```

**O que acontece internamente:**
1. Busca o MDF-e no banco pelo `id`
2. Calcula o próximo `nSeqEvento` para o tipo `110115` neste MDF-e
3. Chama a biblioteca NFePHP: `$tools->sefazIncluiDFe(...)`
4. Se a SEFAZ retornar `cStat=135` (sucesso):
   - Salva o evento na tabela `mdfe_eventos`
   - Salva os dados estruturados em `mdfe_inclusao_nfe`
5. Retorna JSON com `sucesso=true` ou mensagem de erro

---

### 5.2 `mdfe_incluir_condutor.php` — Incluir Condutor

**O que faz:** Registra na SEFAZ um novo condutor para o MDF-e já autorizado.

**Parâmetros:**

```
POST mdfe_incluir_condutor.php
  action = incluir_condutor
  id     = 42            ← ID do MDF-e
  xNome  = JOAO DA SILVA ← Nome do condutor (min. 2, max. 60 caracteres)
  cpf    = 12345678901   ← CPF do condutor (apenas números, 11 dígitos)
```

**O que acontece internamente:**
1. Valida os campos (nome e CPF obrigatórios; CPF deve ter 11 dígitos)
2. Calcula o próximo `nSeqEvento` para o tipo `110114`
3. Chama `$tools->sefazIncluiCondutor($chave, $nSeqEvento, $xNome, $cpf)`
4. Se `cStat=135`:
   - Salva em `mdfe_eventos`
   - Salva em `mdfe_inclusao_condutor`
5. Retorna JSON

---

### 5.3 `mdfe_encerrar.php` — Encerrar

**O que faz:** Comunica à SEFAZ que a entrega foi concluída e o MDF-e pode ser encerrado.

**Parâmetros:**
```
POST mdfe_encerrar.php
  action          = encerrar
  id              = 42
  cUFEncerra      = 41         ← Código da UF onde encerrou
  cMunEncerra     = 4106902    ← Código IBGE do município de encerramento
  xMunEncerra     = Curitiba
```

---

### 5.4 `mdfe_cancelar.php` — Cancelar

**O que faz:** Cancela um MDF-e autorizado e registra a justificativa.

**Parâmetros:**
```
POST mdfe_cancelar.php
  action   = cancelar
  id       = 42
  justificativa = Emissão em duplicidade  ← Mínimo 15 caracteres
```

---

## 6. Sistema de eventos

### O que é o `nSeqEvento`?

O `nSeqEvento` é o **número de ordem do evento**. A SEFAZ exige que cada evento de mesmo tipo para um mesmo MDF-e tenha um número sequencial único.

**Regra importante:**
> A sequência é **por tipo de evento** (`tpEvento`), não global.

**Exemplo:**
```
MDF-e 42, histórico:
  Inclusão NF-e  (110115) → nSeqEvento = 1
  Inclusão NF-e  (110115) → nSeqEvento = 2
  Incl. Condutor (110114) → nSeqEvento = 1  ← começa do 1 para este tipo
  Inclusão NF-e  (110115) → nSeqEvento = 3
  Encerramento   (110112) → nSeqEvento = 1  ← começa do 1 para este tipo
```

**Como é calculado no código:**
```php
// Pega o maior nSeqEvento existente para este MDF-e E este tipo de evento
SELECT COALESCE(MAX(nSeqEvento), 0) + 1
FROM mdfe_eventos
WHERE fk_mdfe_emitida = 42
  AND tpEvento = '110115'
```

### Padrão de escrita dupla (dual-write)

Todo evento escreve em **duas tabelas**:

```
SEFAZ retorna cStat=135
        │
        ├──► mdfe_eventos        (dados gerais + XMLs completos)
        └──► mdfe_inclusao_nfe   (dados estruturados, fácil de consultar)
              ou
             mdfe_inclusao_condutor
```

---

## 7. Como adicionar um novo tipo de evento

Exemplo: Adicionar o evento `110116 – Pagamento de Operação de Transporte`.

### Passo 1 – Criar a tabela (se necessário)

Em `core/modules/modMDFe.class.php`, no método `createTables()`, adicione uma instrução `CREATE TABLE IF NOT EXISTS`:

```php
$sql = "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . "mdfe_inclusao_pagamento (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    fk_mdfe_emitida INT NOT NULL,
    chave_mdfe      VARCHAR(44),
    nSeqEvento      INT DEFAULT 1,
    -- campos específicos do seu evento --
    valor           DECIMAL(15,2),
    forma_pagamento VARCHAR(2),
    protocolo_evento VARCHAR(50),
    cStat           VARCHAR(3),
    xMotivo         VARCHAR(255),
    data_evento     DATETIME,
    xml_requisicao  LONGTEXT,
    xml_resposta    LONGTEXT,
    criado_em       DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
$this->db->query($sql);
```

> Após modificar este arquivo, você precisa **desativar e reativar o módulo** no Dolibarr para a tabela ser criada.

### Passo 2 – Criar o endpoint

Copie `mdfe_incluir_condutor.php` como base e modifique:

```php
// Mude o código do tipo de evento
AND tpEvento = '110116'   // <-- no cálculo do nSeqEvento

// Mude a chamada à biblioteca NFePHP
$result = $tools->sefazPagamento($chave, $nSeqEvento, $valor, $formaPagamento);

// Mude o INSERT na tabela específica
INSERT INTO mdfe_inclusao_pagamento ...
```

### Passo 3 – Adicionar o botão no `mdfe_list.php`

Localize onde ficam os outros botões do dropdown (busque por `openIncluirCondutorModal`) e adicione:

```php
print '<a class="nfe-dropdown-item" href="#" onclick="openPagamentoModal('.$id.')">Informar Pagamento</a>';
```

Depois, adicione o modal HTML e o JavaScript no final do arquivo, seguindo o mesmo padrão dos outros modais.

### Passo 4 – Atualizar `mdfe_consulta.php`

Em `renderConsultaHtml()`, complete os arrays de configuração de eventos:

```php
$tpEventoLabels = [
    // ...existentes...
    '110116' => 'Pagamento de Operação de Transporte',   // <-- adicione
];
$tpEventoColors = [
    // ...existentes...
    '110116' => '#fd7e14',  // laranja
];
$tpEventoIcons = [
    // ...existentes...
    '110116' => '💰',
];
```

E na seção da timeline, adicione um bloco `if ($evt->tpEvento === '110116')` para mostrar os dados específicos do pagamento.

No `action consultar_html`, adicione a query para buscar os registros da nova tabela e passe o array para `renderConsultaHtml()`.

---

## 8. Consulta / Visualização de um MDF-e

O arquivo `mdfe_consulta.php` é carregado via AJAX quando o usuário clica em "Consultar" na lista.

### Como funciona

```
Usuário clica em "Consultar"
  │
  ▼
JavaScript faz POST para mdfe_consulta.php
  com action=consultar_html e id=42
  │
  ▼
mdfe_consulta.php:
  1. Busca o registro em mdfe_emitidas
  2. Parseia o XML do MDF-e (parseMdfeXml)
  3. Busca eventos em mdfe_eventos
  4. Busca NF-es incluídas em mdfe_inclusao_nfe
  5. Busca condutores incluídos em mdfe_inclusao_condutor
  6. Monta o HTML (renderConsultaHtml)
  7. Retorna o HTML pronto
  │
  ▼
JavaScript injeta o HTML na modal e exibe
```

### Função `parseMdfeXml($xmlStr, $row)`

Lê o XML do MDF-e e o "traduz" em um array PHP fácil de usar:

```php
$dados = [
    'numero'                => '000001',
    'serie'                 => '1',
    'chave_acesso'          => '35240100...',
    'status'                => 'autorizada',
    'motivo_cancelamento'   => '',      // vazio se não cancelado
    'condutores'            => [        // motoristas originais do XML
        ['xNome' => 'JOÃO DA SILVA', 'CPF' => '12345678901']
    ],
    'documentos'            => [        // NF-es/CT-es do XML original
        ['xMunDescarga' => 'Curitiba', 'chaves_nfe' => ['35240100...'], ...]
    ],
    // ... muitos outros campos
];
```

### Função `renderConsultaHtml($dados, $eventos, $nfeIncluidas, $condutoresIncluidos)`

Recebe todos os dados e monta o HTML dos cards:

| Parâmetro | De onde vem |
|-----------|-------------|
| `$dados` | `parseMdfeXml()` — dados do XML + campos do banco |
| `$eventos` | Query na `mdfe_eventos` |
| `$nfeIncluidas` | Query na `mdfe_inclusao_nfe` |
| `$condutoresIncluidos` | Query na `mdfe_inclusao_condutor` |

---

## 9. Certificado digital

Para comunicar com a SEFAZ, é necessário um **certificado digital A1** (arquivo `.pfx`).

O módulo carrega o certificado da seguinte forma:

```php
// 1. Lê as informações salvas no banco (caminho do arquivo e senha encriptada)
$certInfo = getCertificateInfo($db);

// 2. Descriptografa a senha usando a chave do Dolibarr
$senha = decryptPassword($certInfo->certificate_password);

// 3. Lê o arquivo .pfx e cria o objeto de certificado
$pfxContent = file_get_contents($certInfo->certificate_path);
$certificate = NFePHP\Common\Certificate::readPfx($pfxContent, $senha);

// 4. Cria a instância da ferramenta de comunicação com a SEFAZ
$tools = new NFePHP\MDFe\Tools($config, $certificate);
```

> Para configurar o certificado: acesse `Admin → MDF-e → Certificado Digital` no Dolibarr.

---

## 10. Perguntas frequentes (FAQ)

**Q: Por que após criar as novas tabelas no `modMDFe.class.php` elas não aparecem no banco?**  
A: As tabelas são criadas quando o módulo é **ativado**. Se o módulo já estiver ativo, você precisa desativá-lo e reativá-lo em `Admin → Módulos`.

---

**Q: O que significa `cStat = 135`?**  
A: É o código da SEFAZ que significa **"Evento registrado e vinculado ao MDF-e"** — ou seja, sucesso. Outros códigos importantes:
- `100` = Autorizado o uso do MDF-e
- `101` = Cancelamento de MDF-e homologado
- `132` = MDF-e encerrado

---

**Q: Por que usar `MAIN_DB_PREFIX` em vez de escrever `llx_` diretamente?**  
A: O prefixo das tabelas pode ser personalizado durante a instalação do Dolibarr. Usar `MAIN_DB_PREFIX` garante compatibilidade com qualquer instalação.

---

**Q: Posso incluir mais de uma NF-e de uma vez?**  
A: O endpoint atual (`mdfe_incluir_dfe.php`) processa uma NF-e por vez. Para múltiplas, seria necessário chamar o endpoint múltiplas vezes ou adaptar o código para aceitar um array de chaves.

---

**Q: Onde fica o XML completo de cada evento?**  
A: Na tabela `mdfe_eventos`, nas colunas `xml_requisicao` (o que foi enviado à SEFAZ) e `xml_resposta` (o que a SEFAZ devolveu).

---

**Q: Como testar sem enviar para a SEFAZ real?**  
A: Configure o módulo para usar o ambiente de **homologação** (tpAmb=2) nas configurações do módulo. Assim todas as comunicações vão para o servidor de testes da SEFAZ.

---

*Documentação gerada para o módulo MDF-e no Dolibarr — versão atual.*
