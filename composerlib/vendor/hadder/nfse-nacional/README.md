# NFSe Padrão Nacional

Pacote para geração de NFSe Padrão Nacional (https://www.nfse.gov.br/) usando componentes NFePHP (https://github.com/nfephp-org).

Este pacote foi desenvolvido para atender algumas das minhas necessidades, implementei o que utilizei e a toque de caixa. Se quiser colaborar envie seu PR.

**Em desenvolvimento. Use por sua conta e risco.**

## Install

**Este pacote é desenvolvido para uso do [Composer](https://getcomposer.org/), então não terá nenhuma explicação de instalação alternativa.**

```bash
composer require hadder/nfse-nacional
```


### Serviços implementados
- consultarNfseChave
- consultarDpsChave
- consultarNfseEventos
- consultarDanfse
- enviaDps
- cancelaNfse

## Requerimentos
- PHP 8.2+
- ext-zlib
- ext-openssl
- ext-dom
- ext-curl

## FAQ - E999 - Erro não catalogado
Podem existir diversos motivos para esse erro ocorrer, já que ele se refere a uma falha não catalogada pela própria Receita, incluindo erros de servidor (500) e outros problemas aleatórios.

Vale mencionar que, no ambiente de **homologação**, esses erros costumam aparecer sem motivo algum, enquanto no ambiente de **produção** a nota normalmente é emitida sem problemas.

Como a Receita só atualiza suas APIs quando está inspirada, listamos abaixo as causas mais comuns com base nos relatos que já recebemos:

- CPF/CNPJ do **prestador** não existente/cadastrado/habilitado na NFSe Nacional/Prefeitura;
