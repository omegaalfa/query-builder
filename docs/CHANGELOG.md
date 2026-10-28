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
- `composer test` executa PHPUnit sem exigir driver de cobertura.

### Testes

- Adicionados testes para quoting de `insert()` e colisões de placeholders.
- Adicionados testes para `raw()`, `selectRaw()`, `whereGroup()` e agregadores.
- Corrigido o teste de locale do PgVector para PHP 8.5.
- Removido o uso de `Reflection::setAccessible()` dos testes.
- Estado validado atualmente: 157 testes, 346 assertions, sem falhas.

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
