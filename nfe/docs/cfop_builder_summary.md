# Resumo muito curto — como o CFOP é montado

Regra geral: CFOP = prefixo (1 dígito) + sufixo (3 dígitos).

Prefixo 
- Exportação → 7
- Devolução → 1 (interna) / 2 (interestadual)
- Saída (venda/bonificação/etc) → 5 (interna) / 6 (interestadual)

Sufixos e decisões (natureza)
- Exportação: produção própria → 101 ; adquirido → 102
- Devolução:
  - substituto ST → 410
  - substituído ST → 411
  - normal → 201 (própria) / 202 (adquirida)
- Bonificação → 910
- Demonstração → 912
- Venda interestadual a NÃO contribuinte (DIFAL) → 108
- Substituição tributária (regime = substituto):
  - não contribuinte → 101 / 102 (não destaca ST)
  - contribuinte → 401 (própria) / 403 (adquirida)
- Substituído (ST já recolhido) → 405
- Venda interna a NÃO contribuinte → 103 (própria) / 104 (adquirida)
- Venda padrão (tributado/isenção/imune) → 101 (própria) / 102 (adquirida)

Exemplos rápidos
- Venda interna, produção própria → 5101
- Venda interestadual, não contribuinte (DIFAL) → 6108
- Venda interna com ST destacado (produção própria) → 5401
- Devolução interna de mercadoria adquirida → 1202
- Exportação de mercadoria adquirida → 7102

Notas
- "Própria" vs "Adquirida" vem do cadastro do produto (campo de origem/fornecimento).
- "Contribuinte" vs "Não contribuinte" vem do indicador do destinatário.
- CFOP final deve ser validado; use o trace para auditoria passo-a-passo em homologação.
