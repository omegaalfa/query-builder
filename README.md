<div align="center">

# 🧩 Omegaalfa Query Builder

![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%208.3-777BB4?style=for-the-badge&logo=php)
![License](https://img.shields.io/badge/license-MIT-green?style=for-the-badge)
![Status](https://img.shields.io/badge/status-production--ready-success?style=for-the-badge)
![PDO](https://img.shields.io/badge/dependency-PDO-blue?style=for-the-badge)
![Tests](https://img.shields.io/badge/tests-156%20passing-brightgreen?style=for-the-badge)

**Query Builder moderno, seguro e tipado para PHP 8.3+**

[📦 Instalação](#-instalação) • [🚀 Início Rápido](#-início-rápido) • [📖 Documentação](#-documentação-completa) • [🧪 Testes](#-testes) • [🔒 Segurança](#-segurança)

</div>

---

## 🚀 Sobre o Projeto

**Omegaalfa Query Builder** é uma biblioteca **moderna, leve e fortemente tipada** em **PHP 8.3+** para construção fluente de queries SQL com PDO.

### 🎯 Principais Características

- 🔒 **100% Prepared Statements** - Segurança contra SQL Injection por design
- 🎨 **Fluent API** - Interface intuitiva e encadeável
- 📦 **Runtime enxuto** - PDO e extensões PHP obrigatórias; ferramentas adicionais ficam em `require-dev`
- 🧪 **Testado** - 157 testes, 346 assertions
- ⚡ **Performance** - Cache inteligente e streaming de resultados
- 🔄 **Transações** - Suporte completo com savepoints
- 🌐 **Multi-driver** - MySQL, PostgreSQL e SQLite
- 📝 **Type-Safe** - Enums tipadas e strict_types
- 📚 **Documentado** - PHPDoc completo em todos os métodos

**Inspirado em:** Eloquent, Doctrine DBAL, Laravel Query Builder

O ambiente Docker opcional com PostgreSQL/pgvector, MySQL e Redis está documentado em [docs/docker.md](docs/docker.md).

O logging estruturado e sua configuração segura para produção estão documentados em [docs/logging.md](docs/logging.md).

A configuração dos bancos e dos testes está documentada em [docs/DATABASE_SETUP.md](docs/DATABASE_SETUP.md). O benchmark reproduzível do ecossistema fica em [benchmarks/ecosystem/README.md](benchmarks/ecosystem/README.md).

---

## 📦 Instalação

```bash
composer require omegaalfa/query-builder
cp .env.example .env
```

### Namespaces canônicos

Os segmentos de namespace seguem PSR-4 em `StudlyCaps`, por exemplo:

```php
use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\Enums\OrderDirection;
use Omegaalfa\QueryBuilder\Exceptions\QueryException;
use Omegaalfa\QueryBuilder\Interfaces\ConnectionInterface;
```

Ao migrar de uma versão que usava diretórios em minúsculas, atualize imports como
`QueryBuilder\connection` para `QueryBuilder\Connection`. Em sistemas de arquivos
case-sensitive, a capitalização faz parte do caminho resolvido pelo autoload PSR-4.

### Requisitos

- **PHP** >= 8.3 com `strict_types` habilitado
- **Extensão PDO** habilitada
- **Driver PDO** correspondente ao seu banco de dados
- **Bancos suportados:** MySQL 5.7+, MariaDB 10.2+, PostgreSQL 9.6+ e SQLite 3.x+

## Configuração dos bancos de dados

Consulte o [guia de configuração dos bancos de dados](docs/DATABASE_SETUP.md) para instalar os drivers PHP, configurar SQLite, iniciar MySQL, PostgreSQL e Redis e executar os testes com Docker Compose.

---

## 🚀 Início Rápido

### Configuração Básica

```php
<?php
declare(strict_types=1);

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\QueryBuilder;
use Omegaalfa\QueryBuilder\Utils\EnvLoader;

EnvLoader::load(__DIR__, required: true);

// 1. Configurar conexão
$config = new DatabaseSettings(
    driver: EnvLoader::get('DB_CONNECTION', 'mysql'),
    host: EnvLoader::get('DB_HOST', '127.0.0.1'),
    database: EnvLoader::require('DB_DATABASE'),
    username: EnvLoader::require('DB_USERNAME'),
    password: EnvLoader::require('DB_PASSWORD'),
    port: EnvLoader::getInt('DB_PORT', 3306) ?? 3306,
    charset: EnvLoader::get('DB_CHARSET', 'utf8mb4'),
    collation: EnvLoader::get('DB_COLLATION', 'utf8mb4_unicode_ci'),
);

// 2. Criar instâncias. O paginador padrão é criado sob demanda.
$connection = new PDOConnection($config);
$qb = new QueryBuilder($connection);

// 3. Usar!
$result = $qb
    ->select('usuarios', ['id', 'nome', 'email'])
    ->where('ativo', SqlOperator::EQUALS, true)
    ->orderBy('nome', OrderDirection::ASC)
    ->limit(10)
    ->execute();

foreach ($result->data as $usuario) {
    echo "{$usuario['nome']} - {$usuario['email']}\n";
}
```

### Construtor e paginação

Somente a conexão é obrigatória. Ao executar uma consulta com `paginate()`, o
`QueryBuilder` cria o `Paginator` padrão automaticamente. `limit()` apenas
limita os resultados e não executa uma consulta adicional de contagem:

```php
$qb = new QueryBuilder($connection);
```

Uma implementação personalizada de `PaginatorInterface` ainda pode ser
injetada explicitamente:

```php
$qb = new QueryBuilder(
    connection: $connection,
    paginator: $customPaginator,
);
```

Para configurar cache ou logger sem fornecer um paginador, prefira argumentos
nomeados:

```php
$qb = new QueryBuilder(
    connection: $connection,
    cache: $cache,
    logger: $logger,
);
```

---

## ⚡ Performance & Benchmarks

### Benchmark comparativo atual

O benchmark oficial compara construção e execução com PDO, Doctrine DBAL,
Illuminate Database, Cycle e Medoo em Docker. As medianas atuais do Omegaalfa
estão na tabela abaixo, em µs/op.

O benchmark oficial atual mede construção e execução em Docker. As medianas em
µs/op para o Omegaalfa são:

| Cenário | SQLite | MySQL | PostgreSQL |
|---|---:|---:|---:|
| `build_simple` | 2,13 | 2,21 | 2,26 |
| `build_complex` | 7,26 | 6,99 | 7,42 |
| `native_pk` | 35,24 | 353,98 | 387,46 |
| `execute_pk` | 24,90 | 331,66 | 372,22 |
| `materialize10000` | 2.072,15 | 2.845,18 | 4.148,45 |
| `batch_1000` | 1.543,46 | 7.992,80 | 8.559,29 |

Os valores são específicos das consultas, versões, drivers e máquina usados; não
representam superioridade universal. A metodologia completa e os resultados por
biblioteca estão em [benchmarks/ecosystem/README.md](benchmarks/ecosystem/README.md).


### Interpretação

- Os números são específicos das consultas, versões, drivers e máquina usados.
- Eles não representam superioridade universal entre as bibliotecas.
- A metodologia completa e os resultados por biblioteca estão em
  [benchmarks/ecosystem/README.md](benchmarks/ecosystem/README.md).

### Condições do teste

- PHP e bancos são executados nos containers definidos em
  `benchmarks/ecosystem/compose.yml`.
- As medições separam construção, execução, materialização e batches.
- Resultados variam conforme hardware, versões, drivers e configuração.

---

## 📖 Documentação Completa

### 📋 Índice de Métodos

**Operações Básicas:**
- [select()](#select) - Consulta SELECT
- [insert()](#insert) - Inserir registro
- [insertBatch()](#insertbatch) - Inserir múltiplos registros
- [returnLastInsertId() e returning()](#identidade-e-returning) - Retorno explícito do INSERT
- [update()](#update) - Atualizar registros
- [delete()](#delete) - Deletar registros

**Cláusulas WHERE:**
- [where()](#where) - Condição AND
- [orWhere()](#orwhere) - Condição OR
- [whereIn()](#wherein) - Verificar se está na lista
- [whereNotIn()](#wherenotin) - Verificar se NÃO está na lista
- [whereBetween()](#wherebetween) - Entre dois valores
- [whereNotBetween()](#wherenotbetween) - Fora de intervalo
- [whereNull()](#wherenull) - Verificar NULL
- [whereNotNull()](#wherenotnull) - Verificar NOT NULL

**JOINs:**
- [join()](#join) - Junção entre tabelas

**Agrupamento e Ordenação:**
- [groupBy()](#groupby) - Agrupar resultados
- [having()](#having) - Filtrar grupos
- [havingRaw()](#havingraw) - HAVING com SQL bruto
- [orderBy()](#orderby) - Ordenar resultados
- [limit()](#limit) - Limitar resultados

**Funções de Agregação:**
- [count()](#count) - Contar registros
- [sum()](#sum) - Somar valores
- [avg()](#avg) - Calcular média
- [min()](#min) - Valor mínimo
- [max()](#max) - Valor máximo
- [exists()](#exists) - Verificar existência

**Avançado:**
- [raw()](#raw) - Query SQL customizada
- [alias()](#alias) - Definir alias para tabela
- [transactional()](#transactional) - Executar em transação
- [cache()](#cache) - Habilitar cache de query
- [execute()](#execute) - Executar query
- [getQuerySql()](#getquerysql) - Obter SQL gerado
- [toSql()](#tosql) - SQL formatado para debug
- [explain()](#explain) - Análise de performance

---

## 🔨 Referência de Métodos

### SELECT

#### `select()`

Inicia uma consulta SELECT.

```php
public function select(string $table, array $fields = ['*']): self
```

**Parâmetros:**
- `$table` - Nome da tabela
- `$fields` - Array de colunas (default: `['*']`)

**Exemplo:**
```php
// SELECT básico
$qb->select('usuarios');

// Campos específicos
$qb->select('produtos', ['id', 'nome', 'preco']);

// Com funções SQL
$qb->select('pedidos', ['COUNT(*) as total', 'SUM(valor) as total_valor']);

// Com aliases de tabela
$qb->select('usuarios', ['u.id', 'u.nome', 'u.email'])->alias('u');
```

⚠️ **Segurança:** Para campos dinâmicos vindos de usuário, sempre use whitelist:
```php
$allowedFields = ['id', 'nome', 'email'];
$requestedFields = $_GET['fields'] ?? ['id'];
if (!is_array($requestedFields) || array_filter($requestedFields, 'is_string') !== $requestedFields) {
    throw new InvalidArgumentException('Campos inválidos.');
}
$userFields = array_values(array_intersect($requestedFields, $allowedFields));
if ($userFields === []) {
    throw new InvalidArgumentException('Selecione ao menos um campo permitido.');
}
$qb->select('usuarios', $userFields);
```

---

### INSERT

#### `insert()`

Insere um único registro.

```php
public function insert(string $table, array $data): self
```

**Parâmetros:**
- `$table` - Nome da tabela
- `$data` - Array associativo [coluna => valor]

**Exemplo:**
```php
$resultado = $qb->insert('usuarios', [
    'nome' => 'João Silva',
    'email' => 'joao@example.com',
    'ativo' => true,
    'criado_em' => new DateTime()
])->returnLastInsertId()->execute();

// Obter ID inserido
$novoId = $resultado->insertId; // ou $qb->getInsertId()
echo "Registro criado com ID: {$novoId}";
```

Um `insert(...)->execute()` comum não chama `PDO::lastInsertId()`. Isso permite
usar UUID, chaves naturais, chaves compostas e tabelas sem sequence. O retorno
do identificador precisa ser solicitado explicitamente.

#### Identidade e `RETURNING`

MySQL e SQLite usam `PDO::lastInsertId()` somente após `returnLastInsertId()`.
No PostgreSQL, o mesmo método gera `RETURNING "id"` e não depende de
`lastInsertId()`:

```php
$resultado = $qb->insert('usuarios', $dados)
    ->returnLastInsertId()
    ->execute();

$id = $resultado->insertId;
```

Para UUID, chaves naturais, compostas ou múltiplas colunas no PostgreSQL, use
`returning()` e consuma normalmente `data`:

```php
$resultado = $qb->insert('context_entries', [
    'tenant_id' => $tenantId,
    'external_id' => $uuid,
    'content' => $content,
])->returning(['tenant_id', 'external_id'])->execute();

$chave = iterator_to_array($resultado->data, false)[0];
```

---

#### `insertBatch()`

Insere múltiplos registros de uma vez.

```php
public function insertBatch(string $table, array $data): self
```

**Parâmetros:**
- `$table` - Nome da tabela
- `$data` - Array de arrays associativos

**Exemplo:**
```php
$usuarios = [
    ['nome' => 'João', 'email' => 'joao@example.com'],
    ['nome' => 'Maria', 'email' => 'maria@example.com'],
    ['nome' => 'Pedro', 'email' => 'pedro@example.com']
];

$qb->insertBatch('usuarios', $usuarios)->execute();
```

⚠️ **Importante:** Todos os registros devem ter as mesmas colunas.

#### Upsert PostgreSQL tipado

`onConflict()` pode ser combinado com `doUpdate()` ou `doNothing()` sem aceitar
fragmentos SQL arbitrários:

```php
$qb->insertBatch('ai_chunks', $chunks)
   ->onConflict(['external_id'])
   ->doUpdate([
       'content',
       'metadata',
       'embedding',
       'content_hash',
       'updated_at',
   ])
   ->execute();
```

```php
$qb->insertBatch('ai_chunks', $chunks)
   ->onConflict(['tenant_id', 'document_id', 'content_hash'])
   ->doNothing()
   ->execute();
```

As colunas de conflito e atualização devem existir nos registros inseridos. A
API suporta também INSERT unitário no SQLite; MySQL não é suportado por essa API de conflito.

---

### UPDATE

#### `update()`

Atualiza registros existentes.

```php
public function update(string $table, array $data): self
```

**Parâmetros:**
- `$table` - Nome da tabela
- `$data` - Array associativo [coluna => novo valor]

**Exemplo:**
```php
// Atualizar com WHERE
$qb->update('usuarios', [
    'nome' => 'João Santos',
    'atualizado_em' => new DateTime()
])
->where('id', SqlOperator::EQUALS, 123)
->execute();

// Atualizar múltiplos registros
$qb->update('produtos', ['ativo' => false])
   ->where('estoque', SqlOperator::EQUALS, 0)
   ->execute();
```

⚠️ **Sempre use WHERE** para evitar atualizar toda a tabela!

---

### DELETE

#### `delete()`

Remove registros.

```php
public function delete(string $table): self
```

**Parâmetros:**
- `$table` - Nome da tabela

**Exemplo:**
```php
// Deletar com WHERE
$qb->delete('usuarios')
   ->where('ativo', SqlOperator::EQUALS, false)
   ->where('ultimo_acesso', SqlOperator::LESS_THAN, '2020-01-01')
   ->execute();

// Deletar por ID
$qb->delete('produtos')
   ->where('id', SqlOperator::EQUALS, 456)
   ->execute();
```

⚠️ **Sempre use WHERE** para evitar deletar toda a tabela!

---

### WHERE

#### `where()`

Adiciona condição WHERE (AND).

```php
public function where(string $column, SqlOperator|string $operator, mixed $value): self
```

**Parâmetros:**
- `$column` - Nome da coluna
- `$operator` - Operador SQL (use enum `SqlOperator`)
- `$value` - Valor para comparação

**Operadores disponíveis:**
- `SqlOperator::EQUALS` - `=`
- `SqlOperator::NOT_EQUALS` - `!=`
- `SqlOperator::GREATER_THAN` - `>`
- `SqlOperator::LESS_THAN` - `<`
- `SqlOperator::GREATER_THAN_OR_EQUALS` - `>=`
- `SqlOperator::LESS_THAN_OR_EQUALS` - `<=`
- `SqlOperator::LIKE` - `LIKE`
- `SqlOperator::NOT_LIKE` - `NOT LIKE`

**Exemplo:**
```php
// Comparação simples
$qb->select('usuarios')
   ->where('ativo', SqlOperator::EQUALS, true)
   ->where('idade', SqlOperator::GREATER_THAN, 18);

// LIKE
$qb->select('produtos')
   ->where('nome', SqlOperator::LIKE, '%notebook%');

// Múltiplas condições (AND)
$qb->select('pedidos')
   ->where('status', SqlOperator::EQUALS, 'pendente')
   ->where('valor', SqlOperator::GREATER_THAN, 100)
   ->where('criado_em', SqlOperator::GREATER_THAN, '2024-01-01');
```

---

#### `orWhere()`

Adiciona condição WHERE (OR).

```php
public function orWhere(string $column, SqlOperator|string $operator, mixed $value): self
```

**Exemplo:**
```php
// WHERE status = 'pendente' OR status = 'processando'
$qb->select('pedidos')
   ->where('status', SqlOperator::EQUALS, 'pendente')
   ->orWhere('status', SqlOperator::EQUALS, 'processando');

// WHERE (categoria = 'A' OR categoria = 'B') AND ativo = true
$qb->select('produtos')
   ->where('categoria', SqlOperator::EQUALS, 'A')
   ->orWhere('categoria', SqlOperator::EQUALS, 'B')
   ->where('ativo', SqlOperator::EQUALS, true);
```

---

#### `whereIn()`

Verifica se valor está na lista.

```php
public function whereIn(string $column, array $values): self
```

**Exemplo:**
```php
// WHERE id IN (1, 2, 3, 4, 5)
$qb->select('usuarios')
   ->whereIn('id', [1, 2, 3, 4, 5]);

// WHERE status IN ('pendente', 'aprovado', 'processando')
$qb->select('pedidos')
   ->whereIn('status', ['pendente', 'aprovado', 'processando']);
```

---

#### `whereNotIn()`

Verifica se valor NÃO está na lista.

```php
public function whereNotIn(string $column, array $values): self
```

**Exemplo:**
```php
// WHERE status NOT IN ('cancelado', 'rejeitado')
$qb->select('pedidos')
   ->whereNotIn('status', ['cancelado', 'rejeitado']);
```

---

#### `whereBetween()`

Verifica se valor está entre dois valores.

```php
public function whereBetween(string $column, array $range): self
```

**Parâmetros:**
- `$range` - Array com 2 elementos: [min, max]

**Exemplo:**
```php
// WHERE preco BETWEEN 100 AND 500
$qb->select('produtos')
   ->whereBetween('preco', [100, 500]);

// WHERE data BETWEEN '2024-01-01' AND '2024-12-31'
$qb->select('pedidos')
   ->whereBetween('criado_em', ['2024-01-01', '2024-12-31']);
```

---

#### `whereNotBetween()`

Verifica se valor NÃO está entre dois valores.

```php
public function whereNotBetween(string $column, array $range): self
```

---

#### `whereNull()`

Verifica se coluna é NULL.

```php
public function whereNull(string $column): self
```

**Exemplo:**
```php
// WHERE deletado_em IS NULL
$qb->select('usuarios')
   ->whereNull('deletado_em');
```

---

#### `whereNotNull()`

Verifica se coluna NÃO é NULL.

```php
public function whereNotNull(string $column): self
```

**Exemplo:**
```php
// WHERE email IS NOT NULL
$qb->select('usuarios')
   ->whereNotNull('email');
```

---

### JOIN

#### `join()`

Cria junção (JOIN) entre tabelas.

```php
public function join(
    string $table, 
    string $key, 
    string $operator, 
    string $refer, 
    JoinType $type = JoinType::INNER
): self
```

**Parâmetros:**
- `$table` - Tabela a ser unida
- `$key` - Coluna da primeira tabela
- `$operator` - Operador de comparação (`=`, `<>`, `>`, `<`, `>=`, `<=`)
- `$refer` - Coluna da segunda tabela
- `$type` - Tipo de JOIN (enum `JoinType`)

**Tipos de JOIN:**
- `JoinType::INNER` - INNER JOIN (padrão)
- `JoinType::LEFT` - LEFT JOIN
- `JoinType::RIGHT` - RIGHT JOIN
- `JoinType::FULL` - FULL JOIN (não suportado no MySQL)

**Exemplo:**
```php
// INNER JOIN
$qb->select('usuarios', ['usuarios.id', 'usuarios.nome', 'pedidos.total'])
   ->join('pedidos', 'usuarios.id', '=', 'pedidos.usuario_id')
   ->where('pedidos.status', SqlOperator::EQUALS, 'concluido');

// LEFT JOIN
$qb->select('categorias', ['categorias.nome', 'COUNT(produtos.id) as total_produtos'])
   ->join('produtos', 'categorias.id', '=', 'produtos.categoria_id', JoinType::LEFT)
   ->groupBy('categorias.id');

// Múltiplos JOINs
$qb->select('pedidos', ['*'])
   ->join('usuarios', 'pedidos.usuario_id', '=', 'usuarios.id')
   ->join('enderecos', 'usuarios.id', '=', 'enderecos.usuario_id', JoinType::LEFT)
   ->where('pedidos.status', SqlOperator::EQUALS, 'pendente');
```

---

### AGRUPAMENTO E ORDENAÇÃO

#### `groupBy()`

Agrupa resultados por coluna.

```php
public function groupBy(string $column): self
```

**Exemplo:**
```php
// Contar pedidos por status
$qb->select('pedidos', ['status', 'COUNT(*) as total'])
   ->groupBy('status');

// Múltiplos agrupamentos
$qb->select('vendas', ['ano', 'mes', 'SUM(valor) as total'])
   ->groupBy('ano')
   ->groupBy('mes');
```

---

#### `having()`

Filtra grupos (após GROUP BY).

```php
public function having(string $column, SqlOperator $operator, mixed $value): self
```

**Exemplo:**
```php
// Categorias com mais de 10 produtos
$qb->select('produtos', ['categoria_id', 'COUNT(*) as total'])
   ->groupBy('categoria_id')
   ->having('total', SqlOperator::GREATER_THAN, 10);

// Usuários com mais de 5 pedidos
$qb->select('pedidos', ['usuario_id', 'COUNT(*) as total_pedidos'])
   ->groupBy('usuario_id')
   ->having('total_pedidos', SqlOperator::GREATER_THAN, 5);
```

---

#### `havingRaw()`

HAVING com SQL customizado (use com cuidado).

```php
public function havingRaw(string $condition): self
```

**Exemplo:**
```php
$qb->select('pedidos', ['usuario_id', 'SUM(valor) as total_gasto'])
   ->groupBy('usuario_id')
   ->havingRaw('SUM(valor) > AVG(valor) * 2');
```

⚠️ **ADVANCED API:** Use apenas com SQL confiável, nunca com input de usuário!

---

#### `orderBy()`

Ordena resultados.

```php
public function orderBy(string $column, OrderDirection $direction = OrderDirection::ASC): self
```

**Parâmetros:**
- `$column` - Coluna para ordenar
- `$direction` - Direção: `OrderDirection::ASC` ou `OrderDirection::DESC`

**Exemplo:**
```php
// Ordenação simples
$qb->select('produtos')
   ->orderBy('nome', OrderDirection::ASC);

// Múltiplas ordenações
$qb->select('usuarios')
   ->orderBy('ativo', OrderDirection::DESC)
   ->orderBy('nome', OrderDirection::ASC);

// Ordenar por função
$qb->select('pedidos', ['*', 'DATE(criado_em) as data'])
   ->orderBy('data', OrderDirection::DESC);
```

---

#### `limit()`

Limita o número de resultados sem executar `COUNT`.

```php
public function limit(int $limit, int $offset = 0): self
```

**Parâmetros:**
- `$limit` - Quantidade de registros (1-10000)
- `$offset` - Posição inicial (0-1000000)

**Exemplo:**
```php
// Primeiros 10 registros
$qb->select('produtos')->limit(10);

// Top-k ou janela sem contagem
$qb->select('produtos')->limit(20, 20);

// Com ordenação
$qb->select('usuarios')
   ->orderBy('criado_em', OrderDirection::DESC)
   ->limit(50);
```

#### `paginate()`

Executa paginação completa: consulta de contagem e consulta limitada.

```php
public function paginate(int $perPage, int $currentPage = 1): self
```

```php
$result = $qb->select('produtos')
    ->paginate(perPage: 20, currentPage: 2)
    ->execute();

echo $result->pagination->totalItems;
echo $result->pagination->totalPages;
```

Use `limit()` para top-k, inclusive `nearestNeighbors()`. Use `paginate()` apenas
quando a contagem total for necessária.

---

### FUNÇÕES DE AGREGAÇÃO

#### `count()`

Conta registros.

```php
public function count(string $column = '*'): int
```

**Exemplo:**
```php
// Total de usuários
$total = $qb->select('usuarios')->count();

// Total de usuários ativos
$ativos = $qb->select('usuarios')
             ->where('ativo', SqlOperator::EQUALS, true)
             ->count();

// Contar valores únicos
$categorias = $qb->selectRaw('produtos', 'COUNT(DISTINCT categoria_id) AS total')->execute();
foreach ($categorias->data as $row) {
    echo $row['total'];
}
```

---

#### `sum()`

Soma valores de uma coluna.

```php
public function sum(string $column): float
```

**Exemplo:**
```php
// Total de vendas
$total = $qb->select('pedidos')->sum('valor');

// Total de vendas aprovadas
$totalAprovado = $qb->select('pedidos')
                    ->where('status', SqlOperator::EQUALS, 'aprovado')
                    ->sum('valor');
```

---

#### `avg()`

Calcula média.

```php
public function avg(string $column): float
```

**Exemplo:**
```php
// Preço médio
$precoMedio = $qb->select('produtos')->avg('preco');

// Nota média de avaliações
$notaMedia = $qb->select('avaliacoes')
                ->where('produto_id', SqlOperator::EQUALS, 123)
                ->avg('nota');
```

---

#### `min()`

Retorna valor mínimo.

```php
public function min(string $column): mixed
```

**Exemplo:**
```php
// Menor preço
$menorPreco = $qb->select('produtos')
                 ->where('ativo', SqlOperator::EQUALS, true)
                 ->min('preco');
```

---

#### `max()`

Retorna valor máximo.

```php
public function max(string $column): mixed
```

**Exemplo:**
```php
// Maior preço
$maiorPreco = $qb->select('produtos')->max('preco');

// Data mais recente
$ultimaCompra = $qb->select('pedidos')
                   ->where('usuario_id', SqlOperator::EQUALS, 123)
                   ->max('criado_em');
```

---

#### `exists()`

Verifica se existe algum registro.

```php
public function exists(): bool
```

**Exemplo:**
```php
// Verificar se usuário existe
$existe = $qb->select('usuarios')
             ->where('email', SqlOperator::EQUALS, 'teste@example.com')
             ->exists();

if ($existe) {
    echo "Email já cadastrado!";
}
```

---

### MÉTODOS AVANÇADOS

#### `raw()`

Executa query SQL customizada.

```php
public function raw(string $query, array $params = []): self
```

**Exemplo:**
```php
// Query complexa com CTE
$qb->raw('
    WITH vendas_por_mes AS (
        SELECT DATE_FORMAT(data, "%Y-%m") as mes, SUM(valor) as total
        FROM vendas
        WHERE ano = ?
        GROUP BY mes
    )
    SELECT * FROM vendas_por_mes WHERE total > ?
', [2024, 10000])->execute();

// Window functions
$qb->raw('
    SELECT 
        nome,
        valor,
        ROW_NUMBER() OVER (PARTITION BY categoria ORDER BY valor DESC) as ranking
    FROM produtos
')->execute();
```

⚠️ **ADVANCED API:** Use apenas com SQL estático confiável!

---

#### `alias()`

Define alias para a tabela principal.

```php
public function alias(string $alias): self
```

**Exemplo:**
```php
$qb->select('usuarios', ['u.id', 'u.nome'])
   ->alias('u')
   ->join('pedidos', 'u.id', '=', 'pedidos.usuario_id')
   ->where('u.ativo', SqlOperator::EQUALS, true);
```

---

#### `transactional()`

Executa código dentro de transação com rollback automático.

```php
public function transactional(callable $callback): mixed
```

**Exemplo:**
```php
try {
    $pedidoId = $qb->transactional(function($qb, $pdo) {
        // 1. Criar pedido
        $qb->insert('pedidos', [
            'usuario_id' => 123,
            'total' => 99.90,
            'status' => 'pendente'
        ])->returnLastInsertId()->execute();
        
        $pedidoId = $qb->getInsertId();
        
        // 2. Adicionar itens
        $qb->insertBatch('itens_pedido', [
            ['pedido_id' => $pedidoId, 'produto_id' => 1, 'qtd' => 2],
            ['pedido_id' => $pedidoId, 'produto_id' => 5, 'qtd' => 1]
        ])->execute();
        
        // 3. Atualizar estoque
        $stmt = $pdo->prepare('UPDATE produtos SET estoque = estoque - ? WHERE id = ?');
        $stmt->execute([2, 1]);
        // Escritas diretas pelo PDO não são rastreadas para invalidação do cache.
        
        return $pedidoId;
    });
    
    echo "Pedido criado: {$pedidoId}";
    
} catch (Exception $e) {
    // Rollback automático já foi executado
    echo "Erro: {$e->getMessage()}";
}
```

---

#### `cache()`

Habilita cache de query.

```php
public function cache(int $ttl = 3600): self
```

**Parâmetros:**
- `$ttl` - Tempo de vida em segundos (default: 1 hora). A configuração vale para a próxima execução bem-sucedida do builder.

Sem chamar `cache()`, a operação não usa cache. `cache(0)` desabilita cache explicitamente, inclusive se um TTL positivo tiver sido configurado antes; valores negativos lançam `InvalidArgumentException`. Após uma execução bem-sucedida, o TTL é consumido. Se a consulta falhar no banco, a configuração permanece disponível para corrigir e repetir a operação.

**Exemplo:**
```php
// Cache por 1 hora
$result = $qb->select('configuracoes')
             ->cache(3600)
             ->execute();

// Cache por 5 minutos
$result = $qb->select('produtos')
             ->where('destaque', SqlOperator::EQUALS, true)
             ->cache(300)
             ->execute();

// Cache por 24 horas
$result = $qb->select('categorias')
             ->cache(86400)
             ->execute();
```

Para usar Redis, instale `ext-redis` e crie uma instância de `RedisCache`. A implementação aplica o prefixo configurado a todas as chaves, serializa valores em JSON, respeita TTL e oferece `getMultiple()`, `deletePattern()` e `clear()`:

```php
$cache = new \Omegaalfa\QueryBuilder\Cache\RedisCache(
    host: '127.0.0.1',
    prefix: 'app:',
    port: 6379,
    timeout: 2.5,
    database: 0,
);
```

`getMultiple()` usa uma única operação `mGet()` para reduzir round-trips ao Redis, preserva valores válidos como `false`, `0` e `''` e omite somente chaves ausentes. Erros de conexão, serialização e limpeza são convertidos em `CacheException`.

Para evitar o recálculo simultâneo quando uma chave expira, use `remember()`. Ele aplica um lock distribuído curto, aguarda o preenchimento feito por outro processo e usa o resolver como fallback:

```php
$value = $cache->remember('dashboard:summary', 300, static function (): array {
    return $repository->loadSummary();
});
```

A liberação do lock é feita por um script Lua atômico que compara o token do proprietário antes de remover a chave. Assim, um processo atrasado não remove o lock que já foi assumido por outro processo.

Para reduzir a latência depois da expiração, use `rememberStale()`. O primeiro TTL define a janela fresca; o segundo define por quanto tempo o valor antigo pode ser servido enquanto um processo protegido por lock atualiza o cache:

```php
$summary = $cache->rememberStale(
    'dashboard:summary',
    ttl: 300,
    staleTtl: 60,
    resolver: static fn (): array => $repository->loadSummary(),
    tags: ['dashboard'],
);
```

Se outro processo já estiver atualizando o valor stale, o valor antigo é retornado sem duplicar o trabalho.

Para observabilidade local da instância, consulte e zere os contadores em memória:

```php
$stats = $cache->getStats();
// hits, misses, stale_hits, refreshes, lock_contention e fallbacks
$cache->resetStats();
```

Essas métricas não são persistidas no Redis e não incluem valores, SQL ou credenciais.

Para health checks, use `health()` quando precisar da latência e de uma mensagem de erro, ou `ping()` quando apenas o status booleano for necessário:

```php
$health = $cache->health();
// ['available' => true, 'latency_ms' => 0.42]

if (!$cache->ping()) {
    // ativar fallback ou sinalizar indisponibilidade
}
```

Falhas de conexão retornam `available: false` e não lançam exceção nesses métodos.

O Redis também possui circuit breaker em memória. Por padrão, três falhas consecutivas abrem o circuito por 30 segundos, evitando novas chamadas enquanto o serviço está indisponível:

```php
$cache = new \Omegaalfa\QueryBuilder\Cache\RedisCache(
    host: '127.0.0.1',
    circuitFailureThreshold: 3,
    circuitRecoverySeconds: 30,
);

$state = $cache->circuitState();
$cache->resetCircuit();
```

Quando o circuito está aberto, a aplicação pode usar um fallback sem cache e tentar novamente após a janela de recuperação.

Para que `remember()` e `rememberStale()` executem o resolver quando o Redis estiver indisponível, habilite `failOpen`:

```php
$cache = new \Omegaalfa\QueryBuilder\Cache\RedisCache(
    host: '127.0.0.1',
    failOpen: true,
);
```

O modo padrão é `failOpen: false`, preservando o comportamento fail-closed e propagando `CacheException` para que a aplicação decida o fallback explicitamente. Com `failOpen: true`, `remember()` devolve o valor resolvido mesmo quando a gravação falha, e `getMultiple()` devolve uma lista vazia em falhas de infraestrutura. Essas ocorrências são contabilizadas em `fallbacks`.

Falhas transitórias podem ser repetidas automaticamente com backoff exponencial:

```php
$cache = new \Omegaalfa\QueryBuilder\Cache\RedisCache(
    host: '127.0.0.1',
    retryAttempts: 2,
    retryDelayMs: 50,
    retryBackoffMultiplier: 2.0,
);
```

As tentativas realizadas aparecem na métrica `retries`.

Para expor as métricas em formato Prometheus, use `prometheusMetrics()`:

```php
$payload = $cache->prometheusMetrics('app_cache');
```

Também é possível receber eventos de observabilidade sem dependências externas:

```php
$cache = new \Omegaalfa\QueryBuilder\Cache\RedisCache(
    host: '127.0.0.1',
    observer: static function (string $event, array $context): void {
        // enviar para o logger ou tracer da aplicação
    },
);
```

Os eventos atuais incluem `redis.retry` e `redis.circuit_opened`; falhas no callback são ignoradas para não interromper o cache.

Também é possível associar entradas a tags e invalidá-las em conjunto:

```php
$cache->set('user:1', $user, 300, tags: ['users', 'tenant:10']);
$cache->flushTags(['users']);
```

Tags vazias são ignoradas e duplicatas são removidas. A invalidação remove todas as chaves indexadas pelas tags informadas.

#### Namespace e limitações operacionais

As chaves Redis usam o namespace v2, derivado por SHA-256 do prefixo configurado. Instâncias com o mesmo prefixo compartilham o namespace; prefixos diferentes ficam isolados. A biblioteca não lê nem migra automaticamente chaves do formato legado. Após a atualização, o cache começa vazio no namespace v2; valores legados permanecem até expirarem, e índices legados sem TTL podem permanecer no Redis.

Faça uma implantação coordenada e encerre processos que ainda executem versões antigas antes de chamar `clear()`: versões antigas podem implementar a limpeza com `FLUSHDB`, que apaga todas as chaves do banco Redis, inclusive de outras aplicações. A implementação v2 limpa apenas seu namespace com `SCAN`, mas essa varredura não é um snapshot atômico; gravações concorrentes podem sobreviver ou ser removidas durante a limpeza.

A gravação do valor e a atualização dos índices de tags usam vários comandos Redis e não são atômicas. Escritas simultâneas na mesma chave ou uma escrita concorrente com `flushTags()` podem deixar valor e índices inconsistentes. A expiração automática remove o valor, mas não os índices de tags, que não têm TTL. `deletePattern()` apaga valores correspondentes sem limpar suas associações de tags. A correção de retornos `false` de comandos detecta falhas operacionais, mas não elimina essas janelas de concorrência ou referências órfãs.

No `QueryBuilder`, use `cacheTags()` para associar uma consulta a tags adicionais. A tabela consultada também é indexada automaticamente, e operações de `insert`, `update` e `delete` invalidam as entradas relacionadas quando o cache Redis está configurado:

```php
$users = $builder
    ->select('users')
    ->cache(300)
    ->cacheTags(['tenant:10'])
    ->execute();
```
             ->execute();
```

⚠️ **Importante:** O cache não isola por usuário. Para dados sensíveis, sempre filtre por `usuario_id`:

```php
$result = $qb->select('pedidos')
             ->where('usuario_id', SqlOperator::EQUALS, $currentUserId)
             ->cache(3600)
             ->execute();
```

---

#### `execute()`

Executa a query e retorna resultados.

```php
public function execute(bool $bufferedQuery = true): QueryResultDTO
```

**Retorno:**
```php
class QueryResultDTO {
    public iterable $data;      // Resultados (array ou Generator)
    public int $count;          // Número de linhas afetadas
    public ?PaginationDTO $pagination;  // Dados de paginação (se paginate usado)
}
```

**Exemplo:**
```php
$result = $qb->select('usuarios')->execute();

// Iterar resultados
foreach ($result->data as $usuario) {
    echo "{$usuario['nome']}\n";
}

// Informações
echo "Total: {$result->count} registros\n";

// Paginação (se usou paginate)
if ($result->pagination) {
    echo "Página {$result->pagination->currentPage} de {$result->pagination->totalPages}\n";
    echo "Total de itens: {$result->pagination->totalItems}\n";
}
```

---

#### `getQuerySql()`

Retorna SQL gerado (sem substituir parâmetros).

```php
public function getQuerySql(): string
```

**Exemplo:**
```php
$sql = $qb->select('usuarios')
          ->where('ativo', SqlOperator::EQUALS, true)
          ->getQuerySql();

echo $sql;
// SELECT * FROM `usuarios` WHERE `id` = :param0
```

---

#### `toSql()`

SQL formatado para debug (com valores substituídos).

```php
public function toSql(bool $withParams = false): string
```

**Exemplo:**
```php
$qb->select('usuarios')
   ->where('id', SqlOperator::EQUALS, 123)
   ->where('nome', SqlOperator::LIKE, '%João%');

// SQL com placeholders
echo $qb->toSql();
// SELECT * FROM `usuarios` WHERE `id` = :param0 AND `nome` LIKE :param1

// SQL com valores (apenas para debug!)
echo $qb->toSql(true);
// SELECT * FROM `usuarios` WHERE `id` = 123 AND `nome` LIKE '%João%'
```

⚠️ **Apenas para debug!** Nunca use `toSql(true)` em produção.

---

#### `explain()`

Analisa performance da query (EXPLAIN).

```php
public function explain(): array
```

**Exemplo:**
```php
$analysis = $qb->select('usuarios')
               ->join('pedidos', 'usuarios.id', '=', 'pedidos.usuario_id')
               ->where('usuarios.ativo', SqlOperator::EQUALS, true)
               ->explain();

print_r($analysis);
// [
//   ['id' => 1, 'select_type' => 'SIMPLE', 'table' => 'usuarios', 'type' => 'ALL', ...],
//   ['id' => 1, 'select_type' => 'SIMPLE', 'table' => 'pedidos', 'type' => 'ref', ...]
// ]
```

---

## 📝 Exemplos Completos

### Exemplo 1: CRUD Completo

```php
use Omegaalfa\QueryBuilder\Enums\SqlOperator;
use Omegaalfa\QueryBuilder\Enums\OrderDirection;

// CREATE
$qb->insert('produtos', [
    'nome' => 'Notebook Dell',
    'preco' => 3500.00,
    'estoque' => 10,
    'categoria_id' => 1,
    'ativo' => true
])->returnLastInsertId()->execute();

$produtoId = $qb->getInsertId();

// READ
$produto = $qb->select('produtos')
              ->where('id', SqlOperator::EQUALS, $produtoId)
              ->execute();

// UPDATE
$qb->update('produtos', ['preco' => 3200.00, 'estoque' => 8])
   ->where('id', SqlOperator::EQUALS, $produtoId)
   ->execute();

// DELETE
$qb->delete('produtos')
   ->where('id', SqlOperator::EQUALS, $produtoId)
   ->execute();
```

---

### Exemplo 2: Relatório com JOINs e Agregações

```php
// Relatório de vendas por categoria
$result = $qb->select('categorias', [
    'categorias.nome as categoria',
    'COUNT(vendas.id) as total_vendas',
    'SUM(vendas.valor) as valor_total',
    'AVG(vendas.valor) as ticket_medio'
])
->join('produtos', 'categorias.id', '=', 'produtos.categoria_id')
->join('vendas', 'produtos.id', '=', 'vendas.produto_id')
->whereBetween('vendas.data', ['2024-01-01', '2024-12-31'])
->groupBy('categorias.id')
->having('total_vendas', SqlOperator::GREATER_THAN, 100)
->orderBy('valor_total', OrderDirection::DESC)
->execute();

foreach ($result->data as $row) {
    echo "{$row['categoria']}: ";
    echo "R$ " . number_format($row['valor_total'], 2) . " ";
    echo "({$row['total_vendas']} vendas)\n";
}
```

---

### Exemplo 3: Paginação Completa

```php
$page = $_GET['page'] ?? 1;
$perPage = 20;
$result = $qb->select('usuarios', ['id', 'nome', 'email', 'criado_em'])
             ->where('ativo', SqlOperator::EQUALS, true)
             ->orderBy('criado_em', OrderDirection::DESC)
             ->paginate(perPage: $perPage, currentPage: (int) $page)
             ->execute();

// Exibir resultados
foreach ($result->data as $usuario) {
    echo "{$usuario['nome']} ({$usuario['email']})\n";
}

// Informações de paginação
$pg = $result->pagination;
echo "\nPágina {$pg->currentPage} de {$pg->totalPages}\n";
echo "Mostrando {$result->count} de {$pg->totalItems} usuários\n";

// Links de navegação
if ($pg->currentPage > 1) {
    echo "<a href='?page=" . ($pg->currentPage - 1) . "'>← Anterior</a> ";
}
if ($pg->currentPage < $pg->totalPages) {
    echo "<a href='?page=" . ($pg->currentPage + 1) . "'>Próxima →</a>";
}
```

---

### Exemplo 4: Transação Complexa

```php
try {
    $resultado = $qb->transactional(function($qb, $pdo) use ($usuarioId, $carrinho) {
        // 1. Criar pedido
        $qb->insert('pedidos', [
            'usuario_id' => $usuarioId,
            'status' => 'pendente',
            'total' => array_sum(array_column($carrinho, 'subtotal')),
            'criado_em' => new DateTime()
        ])->returnLastInsertId()->execute();
        
        $pedidoId = $qb->getInsertId();
        
        // 2. Adicionar itens do pedido
        $itens = [];
        foreach ($carrinho as $item) {
            $itens[] = [
                'pedido_id' => $pedidoId,
                'produto_id' => $item['produto_id'],
                'quantidade' => $item['quantidade'],
                'preco_unitario' => $item['preco'],
                'subtotal' => $item['subtotal']
            ];
        }
        $qb->insertBatch('itens_pedido', $itens)->execute();
        
        // 3. Atualizar estoque
        foreach ($carrinho as $item) {
            // Valores separados do SQL; verifica o estoque na própria atualização.
            $quantidade = (int) $item['quantidade'];
            if ($quantidade <= 0) {
                throw new InvalidArgumentException('Quantidade inválida.');
            }
            $stmt = $pdo->prepare(
                'UPDATE produtos SET estoque = estoque - ? WHERE id = ? AND estoque >= ?'
            );
            $stmt->execute([$quantidade, $item['produto_id'], $quantidade]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Produto indisponível ou estoque insuficiente.');
            }
            // PDO direto não invalida o cache; este fluxo exige gestão própria dessa invalidação.
        }
        
        // 4. Limpar carrinho
        $qb->delete('carrinho')
           ->where('usuario_id', SqlOperator::EQUALS, $usuarioId)
           ->execute();
        
        return $pedidoId;
    });
    
    echo "Pedido #{$resultado} criado com sucesso!";
    
} catch (Exception $e) {
    echo "Erro ao processar pedido: {$e->getMessage()}";
}
```

---

### Exemplo 5: Query Complexa com Subqueries

```php
// Produtos mais vendidos do mês com informações de categoria
// Este exemplo usa MONTH() e YEAR(), funções do MySQL. Para SQLite e PostgreSQL,
// adapte o SQL ao dialeto ou utilize um intervalo de datas com parâmetros.
$result = $qb->raw('
    SELECT 
        p.id,
        p.nome,
        c.nome as categoria,
        p.preco,
        vendas.total_vendido,
        vendas.receita_total
    FROM produtos p
    INNER JOIN categorias c ON p.categoria_id = c.id
    INNER JOIN (
        SELECT 
            produto_id,
            SUM(quantidade) as total_vendido,
            SUM(subtotal) as receita_total
        FROM itens_pedido ip
        INNER JOIN pedidos ped ON ip.pedido_id = ped.id
        WHERE 
            ped.status = ? AND
            MONTH(ped.criado_em) = MONTH(CURRENT_DATE) AND
            YEAR(ped.criado_em) = YEAR(CURRENT_DATE)
        GROUP BY produto_id
    ) vendas ON p.id = vendas.produto_id
    ORDER BY vendas.receita_total DESC
    LIMIT 10
', ['concluido'])->execute();

foreach ($result->data as $produto) {
    echo "{$produto['nome']} ({$produto['categoria']})\n";
    echo "  Vendidos: {$produto['total_vendido']}\n";
    echo "  Receita: R$ " . number_format($produto['receita_total'], 2) . "\n\n";
}
```

---

## 🧪 Testes

O projeto possui testes unitários e de integração com PHPUnit. Para os testes
que dependem de PostgreSQL/pgvector, Redis e drivers PDO, há um ambiente Docker
isolado; ele não depende da versão do PHP nem dos drivers instalados no host.

### Executar Testes

```bash
# Suíte local (usa o PHP instalado no host)
vendor/bin/phpunit

# Suíte completa no Docker (PHP 8.5, pdo_sqlite, pdo_mysql, pdo_pgsql e Redis)
docker compose -f docker-compose.test.yml run --build --rm php-test composer test

# Comandos equivalentes via Composer
composer test:docker
composer quality:docker

# Com cobertura local
vendor/bin/phpunit --coverage-html coverage/

# Teste específico local
vendor/bin/phpunit tests/QueryBuilder/QueryBuilderSecurityTest.php
```

O Compose de testes inicia MySQL, PostgreSQL com pgvector e Redis com healthchecks.
Esses serviços usam volumes próprios, sem publicar portas no host e sem compartilhar
os dados do ambiente de desenvolvimento. O runner é construído com PHP 8.5.4 e
instala as versões travadas em `composer.lock` durante o build.

Para desligar os serviços ou remover também os volumes de teste:

```bash
docker compose -f docker-compose.test.yml down
docker compose -f docker-compose.test.yml down --volumes
```

Além dos testes, o projeto possui validações de qualidade:

```bash
composer quality          # PHPStan nível 6 + PHP-CS-Fixer
composer quality:stan     # análise estática
composer quality:cs       # estilo, sem modificar arquivos
composer quality:mutation # Infection; código coberto, MSI mínimo de 60%
```

### Suítes de Teste

- ✅ `QueryBuilderUnitTest` - Testes unitários básicos
- ✅ `QueryBuilderIntegrationTest` - Testes de integração
- ✅ `QueryBuilderSecurityTest` - Testes de segurança
- ✅ `QueryBuilderBindingTest` - Testes de parameter binding
- ✅ `QueryBuilderCacheTest` - Testes de cache
- ✅ `QueryBuilderOperationsTest` - Testes de operações SQL
- ✅ `QueryBuilderExecutionTest` - Testes de execução
- ✅ `QueryBuilderFullTest` - Testes end-to-end

Os testes de PostgreSQL/pgvector são opcionais na execução local sem Docker e ficam
ativos no runner Docker e no CI. Testes de Redis também usam o serviço do ambiente
de teste quando a extensão `redis` está disponível.

---

## 🔒 Segurança

### Arquitetura Segura

Este Query Builder foi desenvolvido com **segurança por design**:

✅ **100% Prepared Statements** para valores  
✅ **Type-safe binding** (PDO::PARAM_INT, PDO::PARAM_NULL, etc)  
✅ **Strict types** habilitado em todos os arquivos  
✅ **Validação de operadores** em JOINs  
✅ **Enums tipadas** para operadores SQL  
✅ **Quote automático** de identificadores (Identifier Injection Protection)
✅ **Placeholders monotônicos** sem nomes derivados de entrada
✅ **Quoting de colunas** em `insert()` e `insertBatch()`
✅ **Transações** com estado interno preservado após falhas de commit/rollback
✅ **Redação de parâmetros sensíveis** no logger
✅ **Isolamento de Cache** por contexto de conexão (Multi-tenant safe)

### Documentação de Segurança

- 📄 [SECURITY.md](SECURITY.md) - Política de segurança
- 📄 [SECURITY_AUDIT_REVISED.md](SECURITY_AUDIT_REVISED.md) - Análise de segurança completa

### Uso Seguro

```php
// ✅ SEGURO - Valores sempre com prepared statements
$qb->where('email', SqlOperator::EQUALS, $_POST['email']);

// ✅ SEGURO - Whitelist para campos dinâmicos
$allowedFields = ['id', 'nome', 'email'];
$fields = array_intersect($_GET['fields'], $allowedFields);
$qb->select('usuarios', $fields);

// ⚠️ CUIDADO - O método select() agora aplica quoteIdentifier automaticamente,
// mas ainda é recomendado validar input de usuário para evitar erros de lógica.
$qb->select('usuarios', $_GET['fields']); 
```

### Reportar Vulnerabilidades

🔒 **Não reporte vulnerabilidades via issues públicas!**

- 📧 **Email:** security@omegaalfa.dev
- 🔐 **GitHub Security Advisory:** [Criar advisory](https://github.com/omegaalfa/query-builder/security/advisories/new)

**Resposta garantida em 24-48 horas.**

---

## 🗺️ Roadmap

### ✅ v1.0 (Atual)
- [x] Query Builder completo
- [x] Prepared statements obrigatórios
- [x] Enums tipadas
- [x] Cache integrado
- [x] Transações com savepoints
- [x] Multi-driver
- [x] 157 testes automatizados
- [x] Auditoria de segurança

### 🚧 v1.1 (Planejado)
- [ ] Query Builder para migrations
- [ ] Soft deletes
- [ ] Events/Observers
- [ ] Connection pooling
- [ ] Context-aware cache

### 💡 v2.0 (Futuro)
- [ ] Query caching com Redis/Memcached
- [ ] Read replicas
- [ ] Sharding support
- [ ] GraphQL query builder
- [ ] Performance profiler visual

---

## 🐛 Reportando Bugs

Encontrou um bug? Ajude-nos a melhorar!

### Como Reportar

1. Verifique se já não foi reportado nas [Issues](https://github.com/omegaalfa/query-builder/issues)
2. Abra uma nova issue com:

```markdown
### Descrição
[Descreva o problema]

### Reprodução
1. Configure conexão com MySQL 8.0
2. Execute: $qb->select(...)->execute()
3. Observe erro: [mensagem]

### Esperado vs Atual
Esperado: [comportamento correto]
Atual: [o que está acontecendo]

### Ambiente
- PHP: 8.4.x
- Query Builder: 1.0.x
- Driver: MySQL/PostgreSQL/SQLite
- SO: Linux/Windows/macOS
```

---

## 🤝 Contribuindo

Contribuições são muito bem-vindas!

### Como Contribuir

1. **Fork** o repositório
2. Crie uma **branch**: `git checkout -b feature/MinhaFeature`
3. **Commit**: `git commit -m 'Adiciona MinhaFeature'`
4. **Push**: `git push origin feature/MinhaFeature`
5. Abra um **Pull Request**

### Diretrizes

- ✅ Siga **PSR-12**
- ✅ Adicione **testes** para novas features
- ✅ Mantenha **strict_types**
- ✅ Documente com **PHPDoc**
- ✅ Atualize **README** se necessário

---

## 📄 Licença

Distribuído sob a **Licença MIT**. Veja [LICENSE](LICENSE) para detalhes.

```
✅ Uso comercial
✅ Modificação
✅ Distribuição
✅ Uso privado
⚠️ Sem garantia
⚠️ Atribuição obrigatória
```

---

## 💬 Contato e Suporte

### Autor

**Omegaalfa**

- 🌐 GitHub: [@omegaalfa](https://github.com/omegaalfa)
- 📧 Email: contato@omegaalfa.dev
- 💼 Website: [omegaalfa.dev](https://omegaalfa.dev)

### Comunidade

- 💬 [Discussions](https://github.com/omegaalfa/query-builder/discussions) - Dúvidas e discussões
- 🐛 [Issues](https://github.com/omegaalfa/query-builder/issues) - Reportar bugs
- 📚 [Wiki](https://github.com/omegaalfa/query-builder/wiki) - Documentação adicional
- 📋 [Changelog](CHANGELOG.md) - Histórico de versões

---

## ⭐ Mostre seu Apoio

Se este projeto foi útil:

- ⭐ Dê uma **estrela** no GitHub
- 🐦 **Compartilhe** com a comunidade
- 🤝 **Contribua** com código ou documentação
- ☕ [Apoie via GitHub Sponsors](https://github.com/sponsors/omegaalfa)

---

<div align="center">

**Desenvolvido com ❤️ por [Omegaalfa](https://github.com/omegaalfa)**

[![Stars](https://img.shields.io/github/stars/omegaalfa/query-builder?style=social)](https://github.com/omegaalfa/query-builder/stargazers)
[![Forks](https://img.shields.io/github/forks/omegaalfa/query-builder?style=social)](https://github.com/omegaalfa/query-builder/network/members)

[⬆ Voltar ao topo](#-omegaalfa-query-builder)

</div>

---

## Atualizações recentes da API

Esta versão também inclui correções de segurança e robustez: colunas de `insert()` são protegidas, valores não usam nomes derivados de entrada, `raw()` normaliza parâmetros físicos, transações preservam o estado quando `commit()` ou `rollBack()` falham, e `toSql(true)` continua restrito a diagnóstico.

### Placeholders seguros

Valores são sempre compilados usando placeholders monotônicos (`:param0`, `:param1`, etc.). Os nomes não dependem de tabelas, colunas ou chaves fornecidas pelo usuário.

### Expressões SQL explícitas

Use `selectRaw()` somente quando a expressão SQL for confiável:

```php
$query->selectRaw('orders', 'COUNT(*) AS total');
```

Para seleção normal, prefira `select()`:

```php
$query->select('orders', ['id', 'created_at']);
```

### Grupos de condições

`whereGroup()` permite agrupar condições compostas:

```php
$query
    ->select('users')
    ->where('active', '=', 1)
    ->whereGroup(function ($query): void {
        $query
            ->where('role', '=', 'admin')
            ->orWhere('role', '=', 'manager');
    });
```

### CTEs

Use `with()` para adicionar CTEs com SQL confiável ou outro builder. Os parâmetros da subconsulta são remapeados automaticamente para evitar colisões:

```php
$query
    ->select('active_users')
    ->with('active_users', 'SELECT id FROM users WHERE status = ?', [1]);
```

O alias da CTE aceita somente identificadores seguros. Para consultas compostas, uma closure também pode configurar um `QueryBuilderOperations`.

Subconsultas podem ser usadas diretamente nos filtros ou como origem da consulta:

```php
$query
    ->select('users')
    ->whereExists('SELECT 1 FROM orders WHERE orders.user_id = users.id')
    ->whereInSubquery('id', 'SELECT user_id FROM memberships WHERE active = ?', [1]);

$recent = $builder->fromSubquery(
    'recent_users',
    'SELECT id, name FROM users WHERE created_at > ?',
    ['2026-01-01'],
);
```

Também estão disponíveis `whereNotExists()` e `whereNotInSubquery()`. Os bindings das subconsultas são remapeados para os placeholders da consulta principal.

Para grandes volumes, use paginação por cursor:

```php
$page = $builder
    ->select('users')
    ->paginateCursor(perPage: 50, after: $cursor, column: 'id')
    ->execute();

$nextCursor = $page->pagination?->nextCursor;
```

O builder busca uma linha adicional para calcular `hasMore`, ordena pelo campo informado e devolve o próximo cursor opaco em `CursorPaginationDTO`.

Quando a coluna principal não for única, informe uma coluna de desempate. Também é possível paginar em ordem decrescente:

```php
$page = $builder
    ->select('events')
    ->paginateCursor(50, $cursor, 'created_at', OrderDirection::DESC, 'id')
    ->execute();
```

Nesse caso o cursor contém os valores de `created_at` e `id`, garantindo que registros com a mesma data não sejam repetidos ou ignorados.

### Agregadores

`count()`, `sum()`, `avg()`, `min()` e `max()` executam uma cópia interna do builder. A query original permanece disponível depois da agregação.

### Fábrica de conexão

Configure a conexão explicitamente:

```php
$settings = new DatabaseSettings(
    driver: 'sqlite',
    host: '',
    database: ':memory:',
    port: 0,
    username: '',
    password: '',
);

$query = DatabaseFactory::create($settings);
```

### SQLite: caminho do banco por ambiente

O Query Builder recebe o caminho do SQLite em `DatabaseSettings`; ele não carrega
arquivos `.env`. A aplicação consumidora pode carregar sua configuração com a
ferramenta que já utiliza, ou receber as variáveis do processo/contêiner:

```dotenv
DB_CONNECTION=sqlite
DB_DATABASE=/var/lib/app/data/app.sqlite
```

Repassando os valores de ambiente para a assinatura real de `DatabaseSettings`:

```php
use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;

$driver = getenv('DB_CONNECTION') ?: 'sqlite';
$database = getenv('DB_DATABASE');

if ($database === false || $database === '') {
    throw new RuntimeException('DB_DATABASE deve conter o caminho do arquivo SQLite.');
}

$settings = new DatabaseSettings(
    driver: $driver,
    host: '',
    database: $database,
    port: 0,
    username: '',
    password: '',
);

$connection = new PDOConnection($settings);
```

Use um caminho absoluto para evitar que o arquivo dependa do diretório de
trabalho do processo PHP. Um caminho relativo, como `var/app.sqlite`, é resolvido
relativamente ao diretório atual do processo. `:memory:` cria um banco em memória
e seus dados não persistem depois que a conexão é fechada.

Um arquivo SQLite inexistente é criado ao abrir a conexão quando o diretório pai
já existe e permite escrita. A biblioteca não cria diretórios: se o diretório não
existir, a conexão falhará. Um valor vazio produz a DSN `sqlite:` e abre um banco
temporário, que não persiste após o fechamento; por isso, valide `DB_DATABASE`
na aplicação antes de criar a conexão.

O processo PHP precisa poder ler e escrever o banco e escrever no diretório que o
contém. SQLite pode criar arquivos auxiliares de journal, `-wal` e `-shm` junto
do banco. Mantenha o arquivo e seu diretório fora da raiz pública do servidor.

Em Docker, monte um volume persistente no caminho configurado e garanta que o
usuário do PHP tenha as permissões necessárias nesse ponto de montagem. Por
exemplo, para um serviço de aplicação:

```yaml
services:
  app:
    volumes:
      - sqlite_data:/var/lib/app/data

volumes:
  sqlite_data:
```

O valor de `DB_DATABASE` dentro do contêiner deve ser
`/var/lib/app/data/app.sqlite`. O volume precisa persistir durante recriações do
contêiner; apagar o volume remove o banco.

O Query Builder não verifica permissões antes de conectar; testes automatizados
de negação de escrita não são portáveis porque dependem do usuário efetivo e do
sistema operacional. Uma falha real de acesso é reportada como erro de conexão.

### Qualidade e testes

```bash
composer install
composer quality
composer test
composer quality:mutation # requer PCOV ou Xdebug; MSI mínimo de 60%
```

O CI executa os testes, as verificações de qualidade e mutation testing automaticamente. Os jobs de testes e mutation testing iniciam um serviço Redis 7 e instalam `ext-redis` para validar o cache contra uma instância real. Redis permanece opcional em instalações de produção e `illuminate/database` é usado apenas no ambiente de desenvolvimento.

## 💬 Contato

Criado por **Omegaalfa**.  
Para dúvidas ou sugestões: [github.com/omegaalfa](https://github.com/omegaalfa)
