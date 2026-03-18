```mermaid
flowchart TD
    Start([Início: determinarCSOSN]) --> ValidaCRT{CRT = 1 ou 2?\nSimples Nacional}
    ValidaCRT -->|Não| ErroCRT[❌ Erro: Não é Simples Nacional]
    ValidaCRT -->|Sim| ExtraiDados[📋 Extrai Dados:\n- Regime ICMS\n- UF Origem/Destino\n- NCM\n- CFOP]
    
    ExtraiDados --> ValidaDados{Dados\nobrigatórios OK?}
    ValidaDados -->|Não| ErroDados[❌ Erro: Dados incompletos]
    ValidaDados -->|Sim| TipoOperacao[🔍 Identifica:\n- Operação Interna/Interestadual\n- Entrada/Saída]
    
    TipoOperacao --> BuscaRegra[🗄️ Busca Regra Fiscal\ngetTaxRuleOptimized]
    
    BuscaRegra --> Busca1{1. UF + CFOP\n+ NCM + CRT?}
    Busca1 -->|Encontrou| RegraEncontrada[✅ Regra Fiscal Encontrada]
    Busca1 -->|Não| Busca2{2. UF + CFOP\n+ NCM?}
    Busca2 -->|Encontrou| RegraEncontrada
    Busca2 -->|Não| Busca3{3. UF + CFOP\n+ CRT\nNCM vazio?}
    Busca3 -->|Encontrou| RegraEncontrada
    Busca3 -->|Não| Busca4{4. UF + CFOP\nNCM vazio?}
    Busca4 -->|Encontrou| RegraEncontrada
    Busca4 -->|Não| SemRegra[⚠️ Warning: Sem regra fiscal]
    
    RegraEncontrada --> RegimeSwitch
    SemRegra --> RegimeSwitch
    
    RegimeSwitch{Regime ICMS\ndo Produto}
    
    RegimeSwitch -->|1 - Tributado| Tributado[🔵 determinarCSOSN_Tributado]
    RegimeSwitch -->|2 - Substituto| Substituto[🟠 determinarCSOSN_Substituto]
    RegimeSwitch -->|3 - Substituído| Substituido[🟡 determinarCSOSN_Substituido]
    RegimeSwitch -->|4 - Isento| Isento[🟢 determinarCSOSN_Isento]
    RegimeSwitch -->|5 - Não Tributado| NaoTributado[🔴 determinarCSOSN_NaoTributado]
    RegimeSwitch -->|6 - Suspensão| Suspenso[🟣 determinarCSOSN_Suspenso]
    
    Tributado --> TribSaida{É operação\nde saída?}
    TribSaida -->|Não| CSOSN102A[CSOSN: 102]
    TribSaida -->|Sim| TribRegra{Tem regra\nfiscal?}
    TribRegra -->|Não| CSOSN102B[⚠️ CSOSN: 102\nwarning: sem regra]
    TribRegra -->|Sim| TribCredito{icms_cred_aliq\n> 0?}
    TribCredito -->|Sim| CSOSN101[CSOSN: 101\nCOM crédito]
    TribCredito -->|Não| CSOSN102C[CSOSN: 102\nSEM crédito]
    
    Substituto --> SubRegra{Tem regra\nfiscal?}
    SubRegra -->|Não| CSOSN202A[⚠️ CSOSN: 202\nCRÍTICO: sem MVA/ST]
    SubRegra -->|Sim| SubValidaST{icms_st_aliq > 0\nE icms_st_mva > 0?}
    SubValidaST -->|Não| SubWarning[⚠️ Warning: ST sem configuração]
    SubValidaST -->|Sim| SubCredito
    SubWarning --> SubCredito{icms_cred_aliq\n> 0?}
    SubCredito -->|Sim| CSOSN201[CSOSN: 201\nCOM crédito + ST]
    SubCredito -->|Não| CSOSN202B[CSOSN: 202\nSEM crédito + ST]
    
    Substituido --> SubstValidaST{Tem ST na\nregra fiscal?}
    SubstValidaST -->|Sim| CSOSN500A[⚠️ CSOSN: 500\nWarning: não deve ter ST]
    SubstValidaST -->|Não| CSOSN500B[CSOSN: 500\nST já recolhido]
    
    Isento --> CSOSN103[CSOSN: 103\nIsenção]
    NaoTributado --> CSOSN400A[CSOSN: 400\nNão tributado]
    Suspenso --> CSOSN400B[CSOSN: 400\nSuspensão]
    
    CSOSN101 --> Validacao
    CSOSN102A --> Validacao
    CSOSN102B --> Validacao
    CSOSN102C --> Validacao
    CSOSN201 --> Validacao
    CSOSN202A --> Validacao
    CSOSN202B --> Validacao
    CSOSN500A --> Validacao
    CSOSN500B --> Validacao
    CSOSN103 --> Validacao
    CSOSN400A --> Validacao
    CSOSN400B --> Validacao
    
    Validacao[🔍 validarConsistenciaRegraFiscal]
    
    Validacao --> ValidaFinal{CSOSN\ndeterminado?}
    ValidaFinal -->|Não| ErroFinal[❌ Erro: Não foi possível determinar]
    ValidaFinal -->|Sim| Resultado
    
    Resultado[📦 Retorna Array:\n- csosn\n- dados_complementares\n- warnings\n- debug_info]
    
    ErroCRT --> Fim([Fim])
    ErroDados --> Fim
    ErroFinal --> Fim
    Resultado --> Fim
    
    style Start fill:#e1f5e1
    style Fim fill:#ffe1e1
    style ErroCRT fill:#ffcccc
    style ErroDados fill:#ffcccc
    style ErroFinal fill:#ffcccc
    style Resultado fill:#ccffcc
    style CSOSN101 fill:#cce5ff
    style CSOSN102A fill:#cce5ff
    style CSOSN102B fill:#fff3cc
    style CSOSN102C fill:#cce5ff
    style CSOSN201 fill:#ffe0cc
    style CSOSN202A fill:#ffcccc
    style CSOSN202B fill:#ffe0cc
    style CSOSN500A fill:#fff3cc
    style CSOSN500B fill:#ffeb99
    style CSOSN103 fill:#ccffcc
    style CSOSN400A fill:#ffccff
    style CSOSN400B fill:#ffccff
```
LEGENDA:
- 🔵 Azul claro = CSOSN 101/102 (Tributado normal)
- 🟠 Laranja = CSOSN 201/202 (Substituto com ST)
- 🟡 Amarelo = CSOSN 500 (Substituído)
- 🟢 Verde = CSOSN 103 (Isento)
- 🔴 Rosa = CSOSN 400 (Não tributado/Suspensão)
- ⚠️ Amarelo claro = Warnings
- ❌ Vermelho = Erros
