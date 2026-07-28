# Logging de queries

`FileQueryLogger` registra queries em JSON Lines (`.jsonl`): uma entrada JSON por
linha. O formato evita ambiguidades causadas por quebras de linha e pode ser
consumido por ferramentas de observabilidade.

## Configuração recomendada

```php
use Omegaalfa\QueryBuilder\Logger\FileQueryLogger;
use Omegaalfa\QueryBuilder\QueryBuilder;

$logger = new FileQueryLogger(
    logPath: __DIR__ . '/var/log/query-builder.jsonl',
);

$queryBuilder = new QueryBuilder(
    connection: $connection,
    logger: $logger,
);
```

O diretório deve existir e ser gravável pelo usuário do PHP. A classe não cria
diretórios automaticamente.

Defaults seguros:

- parâmetros não são registrados;
- stack traces não são registrados;
- arquivos são criados com permissão `0600` quando o sistema permite;
- o arquivo ativo gira ao atingir 10 MiB;
- até cinco arquivos rotacionados são mantidos;
- falhas no logger não interrompem a aplicação.

Exemplo de entrada:

```json
{"timestamp":"2026-07-28T03:30:00.123+00:00","level":"query","duration_ms":2.451,"affected_rows":1,"sql":"SELECT * FROM users WHERE id = :param0"}
```

`affected_rows` contém o valor fornecido por `PDOStatement::rowCount()`. Para
`SELECT`, esse valor pode variar entre drivers e não deve ser interpretado como
uma contagem portátil dos registros retornados.

## Registro de parâmetros

Habilite parâmetros somente quando houver uma necessidade operacional clara:

```php
$logger = new FileQueryLogger(
    logPath: __DIR__ . '/var/log/query-builder.jsonl',
    logParameters: true,
);
```

As chaves sensíveis conhecidas são substituídas por `[REDACTED]`, inclusive em
arrays aninhados. A lista padrão inclui senhas, tokens, cookies, autorização,
segredos, chaves de API e cartões.

Uma lista própria pode ser fornecida:

```php
$logger = new FileQueryLogger(
    logPath: __DIR__ . '/var/log/query-builder.jsonl',
    logParameters: true,
    sensitiveKeys: ['password', 'token', 'document', 'embedding'],
);
```

Valores individuais são limitados a 2.048 bytes, coleções a 100 itens e a
normalização a oito níveis. Esses limites podem ser ajustados por
`maxValueLength` e `maxCollectionItems`.

Redaction reduz o risco, mas não substitui uma política de classificação e
retenção de dados. Em produção, prefira manter `logParameters: false`.
Use placeholders em vez de inserir valores diretamente no SQL, pois literais
presentes na própria string SQL não podem ser identificados pela redaction de
parâmetros.

## Erros e stack traces

Erros incluem tipo, código e mensagem. O stack trace é opcional:

```php
$logger = new FileQueryLogger(
    logPath: __DIR__ . '/var/log/query-builder.jsonl',
    includeStackTrace: true,
);
```

Stack traces revelam caminhos e detalhes internos. Use essa opção apenas em
ambientes controlados.

Por padrão, problemas de serialização, locking, rotação ou escrita são tratados
como falhas de observabilidade e não substituem o resultado da consulta. Para
diagnóstico ou testes, o modo estrito pode ser ativado:

```php
$logger = new FileQueryLogger(
    logPath: __DIR__ . '/var/log/query-builder.jsonl',
    throwOnFailure: true,
);
```

Não use `throwOnFailure: true` indiscriminadamente em produção: uma falha de
disco poderia afetar uma operação que já foi concluída no banco.

## Rotação e retenção

```php
$logger = new FileQueryLogger(
    logPath: __DIR__ . '/var/log/query-builder.jsonl',
    maxFileSize: 20 * 1024 * 1024,
    maxFiles: 10,
    filePermissions: 0600,
);
```

Os arquivos são nomeados como:

```text
query-builder.jsonl
query-builder.jsonl.1
query-builder.jsonl.2
```

Use `maxFileSize: 0` para desativar a rotação interna e delegá-la ao
`logrotate` ou à plataforma de containers. Em containers, prefira um volume
persistente para arquivos ou uma implementação de `QueryLoggerInterface` que
envie eventos ao fluxo de observabilidade da plataforma.

## Desabilitando o logger

```php
$logger = new FileQueryLogger(
    logPath: __DIR__ . '/var/log/query-builder.jsonl',
    enabled: false,
);
```

Quando desabilitado, o caminho não é validado e nenhum arquivo é criado.
