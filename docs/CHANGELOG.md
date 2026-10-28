# Changelog técnico

## Unreleased

### Segurança

- `insert()` agora aplica quoting a todas as colunas.
- Valores nunca mais usam nomes de coluna, campo ou input para criar placeholders.
- `insert()`, `insertBatch()`, `update()`, `where()`, `whereIn()`, `whereNotIn()`, `whereBetween()`, `having()` e `raw()` usam placeholders monotônicos (`:param0`, `:param1`, ...).
- `raw()` cria um placeholder distinto para cada ocorrência física de parâmetro.
- `toSql(true)` continua sendo uma representação de diagnóstico e agora substitui placeholders sem corromper prefixos ou backslashes.
- `selectRaw()` deixa explícito quando uma expressão SQL não deve ser tratada como identificador.

### API

- Adicionado `selectRaw(string $table, string|array $expressions)`.
- Adicionado `whereGroup(Closure $callback)` para grupos de condições compostas.
- `having()` aceita expressões como `COUNT(id)` e `SUM(price)`.
- Agregadores executam uma cópia do builder e não alteram a query original.
- `QueryBuilderInterface` e `ConnectionInterface` foram alinhadas com as implementações.
- `DatabaseFactory::create()` recebe `DatabaseSettings` explicitamente.
- `RedisCache::getMultiple()` agora preserva valores falsos, zero e strings vazias; apenas chaves ausentes são omitidas.
- `RedisCache::clear()` converte falhas do Redis em `CacheException`.
- `RedisCache::getMultiple()` passou a usar `mGet()` em uma única operação, reduzindo round-trips sem alterar o tratamento de valores válidos.
- Adicionado `RedisCache::remember()` com lock distribuído curto para reduzir cache stampede após expiração de chaves.
- A liberação do lock do `remember()` passou a ser atômica via Lua, impedindo a remoção de locks reassumidos por outro processo.
- Adicionada invalidação por tags com `RedisCache::flushTags()` e tags opcionais em `RedisCache::set()`.
- Adicionado `QueryBuilder::cacheTags()`, com indexação automática pela tabela e invalidação após operações de escrita usando Redis.
- Operadores textuais como `EQUALS` agora são normalizados tanto pelo nome do enum quanto pelo valor SQL (`=`), inclusive em execução aleatória dos testes.

### Conexão e transações

- `ConnectionInterface::pdo()` aceita o modo buffered explicitamente.
- `commit()` e `rollBack()` só alteram o nível transacional após sucesso da operação PDO.
- `Pdo\\Mysql` é protegido por verificação de disponibilidade para compatibilidade entre versões do PHP.

### Dependências e qualidade

- PHP mínimo: 8.3.
- `ext-redis` passou a ser opcional e está documentado em `suggest`.
- `illuminate/database` passou para `require-dev`.
- Adicionados PHPStan, PHP-CS-Fixer e Infection.
- O CI executa PHPStan e PHP-CS-Fixer em um job dedicado.
- PHPStan foi elevado para o nível 6, com tipos explícitos para parâmetros, propriedades e retornos iteráveis.
- O CI agora executa Infection com PCOV em um job separado de mutation testing.
- O CI passou a instalar `ext-redis` nos jobs de testes, qualidade e mutation testing, evitando que a cobertura do Redis seja ignorada.
- Os jobs de testes e mutation testing passaram a iniciar Redis 7 como serviço para validar operações reais de cache, TTL e locks.
- O gate de mutação considera apenas código coberto e exige MSI mínimo de 60%, reduzindo falsos negativos por código sem cobertura.
- `composer test` executa PHPUnit sem exigir driver de cobertura.

### Testes

- Adicionados testes para quoting de `insert()` e colisões de placeholders.
- Adicionados testes para `raw()`, `selectRaw()`, `whereGroup()` e agregadores.
- Corrigido o teste de locale do PgVector para PHP 8.5.
- Removido o uso de `Reflection::setAccessible()` dos testes.
- Adicionados testes de configuração, serialização, valores falsos, `getMultiple()`, prefixos, TTL e falhas de limpeza do Redis.
- Adicionados testes para cache hit, aquisição/liberação de lock, espera por outro processo e validação de TTL no `remember()`.
- Adicionados testes para garantir a comparação do token e a não remoção de locks substituídos.
- Adicionada suíte de integração Redis, com cobertura de leitura, exclusão, `mGet()`, TTL e `remember()`.
- Adicionados testes de associação, deduplicação e invalidação de entradas por tags.
- Adicionado teste de normalização de tags do `QueryBuilder`.

### Migração

Placeholders gerados são detalhes internos. Código consumidor não deve depender de nomes como `:param0`.

Antes:

```php
DatabaseFactory::create();
```

Agora:

```php
DatabaseFactory::create($databaseSettings);
```

Para SQL literal confiável, use `selectRaw()`; para campos comuns, continue usando `select()`.
