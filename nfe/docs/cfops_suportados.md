# CFOPs suportados pelo sistema

Resumo: o sistema determina CFOP por regras (cfop_utils) e/ou montando prefixo+sufixo (cfop_builder). Abaixo a lista unificada (sem duplicatas) com descrição curta.

## Vendas / Saídas (internas e interestaduais)
- 5101 — Venda de produção do estabelecimento (saída interna)
- 5102 — Venda de mercadoria adquirida (saída interna)
- 5103 — Venda de produção própria a não contribuinte (interna)
- 5104 — Venda de mercadoria adquirida a não contribuinte (interna)
- 6101 — Venda de produção do estabelecimento (interestadual)
- 6102 — Venda de mercadoria adquirida (interestadual)
- 6108 — Venda a não contribuinte (Interestadual — DIFAL)

## Substituição Tributária (ST) — Saídas
- 5401 — Venda de produção com ST (interna)
- 5403 — Venda de mercadoria adquirida com ST (interna)
- 5404 — (gerado em cfop_utils; sinalizado como não oficial para ES)
- 5405 — Venda de mercadoria com ST retido anteriormente (interna)
- 6401 — Venda de produção com ST (interestadual)
- 6403 — Venda de mercadoria adquirida com ST (interestadual)
- 6404 — (gerado em cfop_utils; sinalizado como não oficial para ES)
- 6405 — Venda de mercadoria com ST retido (interestadual)

> Observação: cfop_utils pode produzir 5404/6404 (comentado como não oficial no ES). cfop_builder prefere 5405/6405 para esses casos.

## Devoluções / Entradas
- 1201 — Devolução de venda de produção própria (interna)
- 1202 — Devolução de venda de mercadoria (interna)
- 1203 — Devolução de venda a não contribuinte (interna)
- 1204 — Devolução de venda a não contribuinte (interna)
- 1410 — Devolução de venda de mercadoria com ST (interna)
- 1411 — Devolução de venda com ST retido (interna)
- 2201 — Devolução de venda de produção própria (interestadual)
- 2202 — Devolução de venda de mercadoria (interestadual)
- 2203 — Devolução de venda a não contribuinte (interestadual)
- 2204 — Devolução de venda a não contribuinte (interestadual)
- 2410 — Devolução de venda com ST (interestadual)
- 2411 — Devolução de venda com ST retido (interestadual)

## Exportação
- 7101 — Venda de produção do estabelecimento (Exportação)
- 7102 — Venda de mercadoria adquirida (Exportação)

## Operações Especiais (bonificação / demonstração)
- 5910 — Bonificação (saída interna: 5 + 910)
- 6910 — Bonificação (saída interestadual: 6 + 910)
- 5912 — Demonstração (saída interna: 5 + 912)
- 6912 — Demonstração (saída interestadual: 6 + 912)

---

Notas importantes para o cliente:
- O sistema aplica regras (origem/destino, contribuintes, regime ICMS, ST, tipo de operação) e escolhe o CFOP adequado automaticamente.
- Para casos especiais/estaduais não cobertos, o comportamento pode variar: cfop_utils inclui mapeamentos e fallback; cfop_builder monta o CFOP por critérios (mais explícito).
- Há CFOPs sinalizados no código como "não oficiais para ES" (5404/6404). Estes aparecem em cfop_utils mas são tratados/avisados; podemos remover/normalizar se for necessário.
- Podemos fornecer um documento formal (PDF) com essa lista e exemplos por cenário (venda interna a consumidor final, venda interestadual a não contribuinte, venda com ST, devolução, exportação) caso o cliente peça.

