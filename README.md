<div align="center">

# 🧩 Omegaalfa Query Builder

![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%208.4-777BB4?style=for-the-badge&logo=php)
![License](https://img.shields.io/badge/license-MIT-green?style=for-the-badge)
![Status](https://img.shields.io/badge/status-production--ready-success?style=for-the-badge)
![PDO](https://img.shields.io/badge/dependency-PDO-blue?style=for-the-badge)
![Tests](https://img.shields.io/badge/tests-73%20passing-brightgreen?style=for-the-badge)

**Query Builder moderno, seguro e tipado para PHP 8.4+**

[📦 Instalação](#-instalação) • [🚀 Início Rápido](#-início-rápido) • [📖 Documentação](#-documentação-completa) • [🧪 Testes](#-testes) • [🔒 Segurança](#-segurança)

</div>

---

## 🚀 Sobre o Projeto

**Omegaalfa Query Builder** é uma biblioteca **moderna, leve e fortemente tipada** em **PHP 8.4+** para construção fluente de queries SQL com PDO.

### 🎯 Principais Características

- 🔒 **100% Prepared Statements** - Segurança contra SQL Injection por design
- 🎨 **Fluent API** - Interface intuitiva e encadeável
- 📦 **Zero Dependências** - Apenas PHP 8.4+ e PDO
- 🧪 **Testado** - 73 testes, 145 assertions
- ⚡ **Performance** - Cache inteligente e streaming de resultados
- 🔄 **Transações** - Suporte completo com savepoints
- 🌐 **Multi-driver** - MySQL, PostgreSQL, SQLite, SQL Server
- 📝 **Type-Safe** - Enums tipadas e strict_types
- 📚 **Documentado** - PHPDoc completo em todos os métodos

**Inspirado em:** Eloquent, Doctrine DBAL, Laravel Query Builder

O ambiente Docker opcional com PostgreSQL/pgvector, MySQL e Redis está documentado em [docs/docker.md](docs/docker.md).

O logging estruturado e sua configuração segura para produção estão documentados em [docs/logging.md](docs/logging.md).

A metodologia de comparação com Doctrine DBAL está documentada em [docs/benchmarks.md](docs/benchmarks.md).

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

- **PHP** >= 8.4 com `strict_types` habilitado
- **Extensão PDO** habilitada
- **Driver PDO** correspondente ao seu banco de dados
- **Bancos suportados:** MySQL 5.7+, MariaDB 10.2+, PostgreSQL 9.6+, SQLite 3.x+, SQL Server, Oracle

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

### Streaming de Grandes Volumes

Benchmark comparativo entre **Omegaalfa Query Builder** (`execute(false)`) e **Laravel Eloquent** (`cursor()`) para processamento de grandes datasets com **unbuffered queries** (streaming real).

<details>
<summary>📊 <b>Ver Resultados Completos</b></summary>

#### Tabela Comparativa

| Registros | Omega Time (s) | Omega Mem (MB) | Laravel Time (s) | Laravel Mem (MB) | Verificação |
|-----------|----------------|----------------|------------------|------------------|-------------|
| 10        | 0.145          | 0.000          | 0.024            | 4.000            | ✅ 10/10    |
| 100       | 0.001          | 0.000          | 0.002            | 0.000            | ✅ 100/100  |
| 1,000     | 0.003          | 0.000          | 0.008            | 0.000            | ✅ 1K/1K    |
| 10,000    | 0.097          | 0.000          | 0.067            | 0.000            | ✅ 10K/10K  |
| 100,000   | 0.189          | 2.000          | 0.653            | 0.000            | ✅ 100K/100K |
| **1,000,000** | **1.441**  | **32.016**     | **6.630**        | **0.000**        | ✅ **1M/1M** |

#### Gráfico de Performance (Tempo de Execução)

```
Processamento de 1 Milhão de Registros

Omegaalfa Query Builder:  ████                1.44s  (4.6x mais rápido)
Laravel Eloquent Cursor:  ████████████████████ 6.63s
```

#### Gráfico de Consumo de Memória

```
Memória Peak para 1 Milhão de Registros

Omegaalfa (streaming):    ████████████████████ 32.02 MB
Laravel (cursor):                              ~0.00 MB*

* Valores medidos pelo script de benchmark
```

</details>

### 🏆 Conclusões

- ✅ **Até 4.6x mais rápido** que Laravel Eloquent para volumes acima de 1M de registros
- ✅ **Consumo de memória previsível** - ~32MB constantes para streaming de 1M de registros
- ✅ **Escalabilidade linear** - performance proporcional ao volume de dados
- ✅ **Ideal para:** ETL, relatórios, exports, processamento batch, big data

### 📌 Condições do Teste

- **PHP:** 8.4.1
- **Banco:** MySQL 8.0 com `big.csv` (1M registros)
- **Ambiente:** WSL2 Ubuntu
- **Método Omega:** `execute(false)` - unbuffered query com Generator
- **Método Laravel:** `DB::cursor()` - lazy collection streaming
- **Hardware:** Ambiente de desenvolvimento padrão

> ⚠️ **Nota:** Resultados podem variar conforme hardware, configuração do PHP/MySQL e características dos dados. Benchmark disponível em [`teste.php`](teste.php) para validação independente.

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
$qb->select('pedidos', ['id', 'COUNT(*) as total', 'SUM(valor) as total_valor']);

// Com aliases de tabela
$qb->select('usuarios', ['u.id', 'u.nome', 'u.email'])->alias('u');
```

⚠️ **Segurança:** Para campos dinâmicos vindos de usuário, sempre use whitelist:
```php
$allowedFields = ['id', 'nome', 'email'];
$userFields = array_intersect($_GET['fields'], $allowedFields);
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
API falha precocemente quando usada fora do driver PostgreSQL.

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
- `SqlOperator::NOT_EQUALS` - `<>`
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
$categorias = $qb->select('produtos')->count('DISTINCT categoria_id');
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
        $qb->update('produtos', ['estoque' => 'estoque - 2'])
           ->where('id', SqlOperator::EQUALS, 1)
           ->execute();
        
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
- `$ttl` - Tempo de vida em segundos (default: 1 hora)

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
// SELECT * FROM `usuarios` WHERE `ativo` = :param0
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
// SELECT * FROM `usuarios` WHERE `ativo` = :param0 AND `nome` LIKE :param1

// SQL com valores (apenas para debug!)
echo $qb->toSql(true);
// SELECT * FROM `usuarios` WHERE `ativo` = 123 AND `nome` LIKE '%João%'
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
            // Verificar estoque disponível
            $produto = $qb->select('produtos', ['estoque'])
                          ->where('id', SqlOperator::EQUALS, $item['produto_id'])
                          ->execute();
            
            $estoque = $produto->data[0]['estoque'];
            if ($estoque < $item['quantidade']) {
                throw new Exception("Estoque insuficiente para produto {$item['produto_id']}");
            }
            
            // Decrementar estoque
            $pdo->exec("
                UPDATE produtos 
                SET estoque = estoque - {$item['quantidade']}
                WHERE id = {$item['produto_id']}
            ");
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

O projeto possui **cobertura completa de testes** com PHPUnit.

### Executar Testes

```bash
# Todos os testes
vendor/bin/phpunit

# Com cobertura
vendor/bin/phpunit --coverage-html coverage/

# Testes específicos
vendor/bin/phpunit tests/QueryBuilder/QueryBuilderSecurityTest.php
```

PHPStan ainda não está configurado neste projeto; `composer test` é o comando de validação suportado.

### Suítes de Teste

- ✅ `QueryBuilderUnitTest` - Testes unitários básicos
- ✅ `QueryBuilderIntegrationTest` - Testes de integração
- ✅ `QueryBuilderSecurityTest` - Testes de segurança
- ✅ `QueryBuilderBindingTest` - Testes de parameter binding
- ✅ `QueryBuilderCacheTest` - Testes de cache
- ✅ `QueryBuilderOperationsTest` - Testes de operações SQL
- ✅ `QueryBuilderExecutionTest` - Testes de execução
- ✅ `QueryBuilderFullTest` - Testes end-to-end

**Status:** 73 testes, 145 assertions, 0 failures

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
- [x] 73 testes automatizados
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

## 💬 Contato

Criado por **Omegaalfa**.  
Para dúvidas ou sugestões: [github.com/omegaalfa](https://github.com/omegaalfa)
