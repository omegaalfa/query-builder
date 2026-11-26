# Configuração dos bancos de dados

Guia de instalação, conexão e testes com SQLite, MySQL, PostgreSQL e Redis. Execute os comandos na **raiz do repositório**. Os exemplos de shell usam Bash (Linux, macOS ou WSL); comandos `docker compose` sem expansões de shell também podem ser executados no PowerShell.

## Índice

- [Requisitos e ambientes](#requisitos-e-ambientes)
- [Inicialização e configuração](#inicialização-e-configuração)
- [SQLite](#sqlite)
- [MySQL](#mysql)
- [PostgreSQL e pgvector](#postgresql-e-pgvector)
- [Redis](#redis)
- [Testes e verificação dos drivers](#testes-e-verificação-dos-drivers)
- [Parada e remoção de dados](#parada-e-remoção-de-dados)
- [Resolução de problemas](#resolução-de-problemas)

## Requisitos e ambientes

O [composer.json](../composer.json) exige **PHP >= 8.5**, `ext-pdo` e `ext-ctype`. Instale também o driver da conexão: `pdo_sqlite`, `pdo_mysql` ou `pdo_pgsql`. Para `RedisCache`, instale `ext-redis`; Redis não utiliza PDO. As extensões devem estar habilitadas no PHP que executa a aplicação, não apenas em outro SAPI ou instalação de PHP.

Para instalar a biblioteca em uma aplicação:

```bash
composer require omegaalfa/query-builder
```

Para trabalhar neste repositório com PHP local:

```bash
composer install
```

Docker com Compose permite executar os testes sem instalar PHP no host. O [Dockerfile.test](../Dockerfile.test) usa PHP 8.5.4 CLI, instala `intl`, `pdo_mysql`, `pdo_pgsql` e `redis` 6.3.0, e aproveita o suporte a SQLite da imagem PHP. Ele instala as dependências de desenvolvimento e copia código, testes e `.env.example` para a imagem. **Não há montagem automática do código local**: reconstrua `php-test` após mudanças.

| Ambiente | Arquivo / projeto Compose | Serviços e finalidade |
|---|---|---|
| Desenvolvimento | `docker-compose.yml` / `query-builder` | `mysql`, `pgvector`, `redis`; portas publicadas e volumes persistentes |
| Testes | `docker-compose.test.yml` / `query-builder-test` | Os mesmos três serviços e `php-test`; credenciais fixas de teste, sem portas publicadas |
| Benchmarks | `benchmarks/ecosystem/compose.yml` / `qb-ecosystem-bench` | `runner`, `mysql`, `postgres`; ambiente separado, com dados SQL em tmpfs |

Não misture os arquivos desses ambientes para executar a suíte. Experimentos
locais de performance ficam fora do pacote público; o benchmark reproduzível
oficial é `benchmarks/ecosystem/`.

As imagens atuais são MySQL **8.4.10**, PostgreSQL **17 com pgvector 0.8.5** e Redis **7.4.10**, fixadas por digest nos arquivos Compose da raiz. Essas são versões do ambiente do projeto, não uma declaração de versões mínimas dos bancos suportados pela biblioteca.

## Inicialização e configuração

### Desenvolvimento: arquivo `.env`

Crie `.env` a partir de [.env.example](../.env.example) **somente se ainda não existir**:

```bash
test -f .env || cp .env.example .env
chmod 600 .env
```

Edite as senhas `change-me` antes de iniciar os serviços. Não publique credenciais reais. O Compose de desenvolvimento usa:

| Variável em `.env` | Uso e valor de exemplo |
|---|---|
| `DOCKER_MYSQL_DATABASE` | Banco MySQL: `query_builder_test` |
| `DOCKER_MYSQL_USERNAME` | Usuário MySQL: `query_builder` |
| `DOCKER_MYSQL_PASSWORD` | Senha desse usuário, obrigatória |
| `DOCKER_MYSQL_ROOT_PASSWORD` | Senha de root, inclusive para o healthcheck, obrigatória |
| `DOCKER_MYSQL_PORT` | Porta publicada no host: `33069` |
| `PGVECTOR_DATABASE` | Banco PostgreSQL: `query_builder_test` |
| `PGVECTOR_USERNAME` | Usuário PostgreSQL: `query_builder` |
| `PGVECTOR_PASSWORD` | Senha PostgreSQL, obrigatória |
| `PGVECTOR_PORT` | Porta publicada no host: `54329` |
| `DOCKER_REDIS_PASSWORD` | Senha exigida pelo Redis, obrigatória |
| `DOCKER_REDIS_PORT` | Porta publicada no host: `63799` |

O Compose também exige os nomes dos bancos e usuários SQL da tabela. `PGVECTOR_HOST=127.0.0.1`, `DOCKER_REDIS_HOST=127.0.0.1` e `DOCKER_REDIS_DATABASE=0` são configurações para clientes PHP; não mudam a rede nem a inicialização do servidor. `.env.example` contém ainda `DB_*`, `MYSQL_*` e `PGSQL_*` com portas de outros ambientes locais. **Esses grupos não configuram os serviços do Compose atual.** Use os grupos da tabela para este ambiente.

`DatabaseSettings` não lê `.env` automaticamente. O projeto disponibiliza `Omegaalfa\QueryBuilder\Utils\EnvLoader`: `load()` carrega um arquivo confiável e, por padrão, preserva variáveis já injetadas no processo. O carregamento de `.env` pelo Compose serve à interpolação do YAML; não injeta todas as variáveis automaticamente em cada contêiner.

### Comandos de desenvolvimento

```bash
# Validar sem imprimir credenciais
docker compose -f docker-compose.yml config --quiet

# Todos os servidores; não existe serviço PHP neste arquivo
docker compose -f docker-compose.yml up -d mysql pgvector redis

# Um serviço isolado (escolha o desejado)
docker compose -f docker-compose.yml up -d mysql
docker compose -f docker-compose.yml up -d pgvector
docker compose -f docker-compose.yml up -d redis

# Estado, saúde e logs
docker compose -f docker-compose.yml ps -a
docker compose -f docker-compose.yml logs --tail=100 mysql pgvector redis
docker compose -f docker-compose.yml logs -f mysql
```

`up -d` inicia os contêineres; confira `healthy` em `ps` antes de conectar. `logs -f` acompanha a saída até Ctrl+C. Os healthchecks utilizam `mysqladmin ping`, `pg_isready` e `redis-cli ping`.

### Host versus rede Docker

| Servidor | PHP no host, com `.env.example` | Cliente na mesma rede Compose |
|---|---|---|
| MySQL | `127.0.0.1:33069` | `mysql:3306` |
| PostgreSQL | `127.0.0.1:54329` | `pgvector:5432` |
| Redis | `127.0.0.1:63799` | `redis:6379` |

Dentro de um contêiner, `127.0.0.1` indica o próprio contêiner. Os nomes de serviço só são resolvidos por clientes na rede Docker correspondente. O desenvolvimento usa a rede lógica `query_builder`, normalmente criada como `query-builder_query_builder`. O ambiente de testes usa sua própria rede padrão; `php-test` já está conectado a ela. As portas publicadas do desenvolvimento não existem no Compose de testes.

## SQLite

SQLite é um banco embutido: **não precisa iniciar um servidor, contêiner de banco ou porta de rede**. Instale `pdo_sqlite`; a extensão `sqlite3` não substitui o driver PDO exigido pela biblioteca.

### Banco em arquivo e consulta de verificação

Este exemplo completo pode ser salvo na raiz de uma aplicação com `vendor/autoload.php`:

```php
<?php
declare(strict_types=1);

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;

require __DIR__ . '/vendor/autoload.php';

$directory = __DIR__ . '/var';
if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Não foi possível criar o diretório do SQLite.');
}
$connection = new PDOConnection(new DatabaseSettings(
    driver: 'sqlite',
    host: '',
    database: $directory . '/app.sqlite',
    port: 0,
    username: '',
    password: '',
));
echo $connection->pdo()->query('SELECT 1')->fetchColumn(), PHP_EOL;
$connection->disconnect();
```

O PDO cria o arquivo se ele não existir; não cria os diretórios pais. O usuário PHP precisa poder escrever no arquivo e no diretório, inclusive para arquivos auxiliares de journal/WAL. Ajuste proprietário e permissões ao usuário da aplicação; não use permissões globais de escrita como solução padrão.

`database: '/caminho/absoluto/app.sqlite'` independe do diretório de trabalho. `database: 'var/app.sqlite'` é relativo ao diretório de trabalho do processo PHP, que pode mudar entre CLI, servidor web e Docker. `__DIR__` fixa o caminho relativo ao arquivo PHP. Não inclua `sqlite:` em `database`: `DatabaseSettings::toDsn()` acrescenta o prefixo.

### Banco em memória

```php
<?php
declare(strict_types=1);

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;

require __DIR__ . '/vendor/autoload.php';

$connection = new PDOConnection(new DatabaseSettings(
    driver: 'sqlite', host: '', database: ':memory:', port: 0, username: '', password: '',
));
echo $connection->pdo()->query('SELECT 1')->fetchColumn(), PHP_EOL;
$connection->disconnect();
```

O conteúdo de `:memory:` pertence à conexão PDO e desaparece ao fechá-la; outra conexão não compartilha esse banco. Reconectar também perde esse conteúdo.

### Persistência em Docker

O Compose de testes não define volume para SQLite. Arquivos criados dentro de `php-test` são descartados com o contêiner `--rm`. Para persistir SQLite em uma aplicação Docker, o diretório do banco precisa estar em um volume ou bind mount configurado pela própria aplicação, com permissões adequadas. O projeto não fornece esse serviço persistente; `:memory:` nunca persiste em volume. Veja os comandos de [testes](#testes-e-verificação-dos-drivers) abaixo.

## MySQL

Inicie `mysql` e confira a saúde com os comandos de inicialização. No desenvolvimento, o Compose traduz `DOCKER_MYSQL_*` para `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD` e `MYSQL_ROOT_PASSWORD` da imagem. Os dados ficam no volume lógico `mysql_data`, montado em `/var/lib/mysql`.

### Cliente MySQL

Com usuário/banco de `.env.example`, o comando solicita a senha definida em `DOCKER_MYSQL_PASSWORD`:

```bash
docker compose -f docker-compose.yml exec mysql mysql -u query_builder -p query_builder_test
```

Se alterou usuário ou banco, ajuste os argumentos. Execute `SELECT 1;` no cliente e use `exit` para sair. Pelo host, com o cliente instalado, use `mysql -h 127.0.0.1 -P 33069 -u query_builder -p query_builder_test` (ajuste a porta se necessário).

### PHP no host

Salve o exemplo na raiz, ao lado de `.env` e `vendor/`:

```php
<?php
declare(strict_types=1);

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\Utils\EnvLoader;

require __DIR__ . '/vendor/autoload.php';
EnvLoader::load(__DIR__, required: true);

$connection = new PDOConnection(new DatabaseSettings(
    driver: 'mysql',
    host: '127.0.0.1',
    database: EnvLoader::require('DOCKER_MYSQL_DATABASE'),
    port: EnvLoader::getInt('DOCKER_MYSQL_PORT', 33069) ?? 33069,
    username: EnvLoader::require('DOCKER_MYSQL_USERNAME'),
    password: EnvLoader::require('DOCKER_MYSQL_PASSWORD'),
));
echo $connection->pdo()->query('SELECT 1')->fetchColumn(), PHP_EOL;
$connection->disconnect();
```

Para PHP na rede Compose, use `host: 'mysql'` e `port: 3306`. Não use a porta publicada `33069` entre contêineres.

## PostgreSQL e pgvector

O serviço se chama **`pgvector`**, não `postgres`, nos dois arquivos Compose da raiz. O desenvolvimento traduz `PGVECTOR_DATABASE`, `PGVECTOR_USERNAME` e `PGVECTOR_PASSWORD` para `POSTGRES_DB`, `POSTGRES_USER` e `POSTGRES_PASSWORD`. Os dados ficam no volume lógico `pgvector_data`, em `/var/lib/postgresql/data`.

### Cliente psql e extensão vector

Com usuário/banco de `.env.example`:

```bash
docker compose -f docker-compose.yml exec pgvector psql -U query_builder -d query_builder_test
```

Execute `SELECT 1;` no cliente; `\q` encerra a sessão. Pelo host, com o cliente instalado, use `psql -h 127.0.0.1 -p 54329 -U query_builder -d query_builder_test` e informe a senha configurada quando solicitada.

A imagem já contém os arquivos de pgvector. Habilite a extensão **em cada database** que a utilizar, com um usuário autorizado:

```bash
docker compose -f docker-compose.yml exec pgvector psql -U query_builder -d query_builder_test -c "CREATE EXTENSION IF NOT EXISTS vector;"
```

`PgVectorIntegrationTest` executa esse comando automaticamente no banco de testes quando `PGVECTOR_INTEGRATION=1`. O Compose de testes já fornece essa variável. A extensão não é necessária para SQL comum. Os testes de integração atuais conferem PostgreSQL 17 e pgvector 0.8.5.

### PHP no host

```php
<?php
declare(strict_types=1);

use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\Utils\EnvLoader;

require __DIR__ . '/vendor/autoload.php';
EnvLoader::load(__DIR__, required: true);

$connection = new PDOConnection(new DatabaseSettings(
    driver: 'pgsql',
    host: EnvLoader::get('PGVECTOR_HOST', '127.0.0.1') ?? '127.0.0.1',
    database: EnvLoader::require('PGVECTOR_DATABASE'),
    port: EnvLoader::getInt('PGVECTOR_PORT', 54329) ?? 54329,
    username: EnvLoader::require('PGVECTOR_USERNAME'),
    password: EnvLoader::require('PGVECTOR_PASSWORD'),
));
echo $connection->pdo()->query('SELECT 1')->fetchColumn(), PHP_EOL;
$connection->disconnect();
```

Em outro contêiner da mesma rede, use `host: 'pgvector'` e `port: 5432`.

## Redis

Redis é um serviço opcional de **cache**, não um driver SQL. A biblioteca usa a extensão `redis`, através de `RedisCache`. Redis não substitui SQLite, MySQL ou PostgreSQL.

### Disponibilidade e persistência

O desenvolvimento exige `DOCKER_REDIS_PASSWORD`, usa AOF (`--appendonly yes`) e monta `redis_data` em `/data`. Para verificar a disponibilidade sem colocar a senha literal na linha de comando:

```bash
docker compose -f docker-compose.yml exec redis sh -c 'REDISCLI_AUTH="$REDIS_PASSWORD" redis-cli ping'
```

A resposta esperada é `PONG`. Nos testes, Redis não exige senha, tem AOF desativado e não possui volume persistente declarado:

```bash
docker compose -f docker-compose.test.yml exec redis redis-cli ping
```

Não dependa da persistência do Redis de testes ao recriar contêineres. No desenvolvimento, o volume conserva os dados, sujeitos à configuração de persistência do Redis; persistência não torna o cache fortemente consistente.

### PHP no host

```php
<?php
declare(strict_types=1);

use Omegaalfa\QueryBuilder\Cache\RedisCache;
use Omegaalfa\QueryBuilder\Utils\EnvLoader;

require __DIR__ . '/vendor/autoload.php';
EnvLoader::load(__DIR__, required: true);

$cache = new RedisCache(
    host: EnvLoader::get('DOCKER_REDIS_HOST', '127.0.0.1') ?? '127.0.0.1',
    prefix: 'database-setup:',
    port: EnvLoader::getInt('DOCKER_REDIS_PORT', 63799) ?? 63799,
    password: EnvLoader::require('DOCKER_REDIS_PASSWORD'),
    database: EnvLoader::getInt('DOCKER_REDIS_DATABASE', 0) ?? 0,
);
$key = 'check-' . bin2hex(random_bytes(8));
$cache->set($key, 'ok', 30); // set() retorna void; erros seguem a política do cache.
var_dump($cache->get($key)); // string(2) "ok"
$cache->delete($key);
```

Entre contêineres, use `redis:6379`. No Compose de testes, omita `password` e use `REDIS_HOST`/`REDIS_PORT` fornecidos a `php-test`; o cliente não lê essas variáveis sozinho.

### Namespace Redis v2 e implantação

As chaves físicas usam `qb:v2:{sha256(prefixo)}:`, com hash hexadecimal de 64 caracteres e tipos separados (`value:`, `lock:remember:`, `lock:swr:`, `tag:` e `key-tags:`). Prefixos iguais compartilham namespace; use prefixos diferentes por aplicação/ambiente quando quiser isolamento.

O layout v2 é incompatível com o armazenamento legado: **não há leitura de fallback, migração ou exclusão automática de chaves antigas**. Elas podem permanecer até expirar ou receber manutenção explícita. Evite coexistência com processos antigos: versões legadas que executam `FLUSHDB` podem apagar dados v2 e dados de outras aplicações no mesmo banco Redis.

`clear()` recusa prefixo vazio e limpa somente o namespace v2 usando SCAN e exclusão em lotes. `deletePattern()` opera sobre valores do namespace, com padrão lógico. A varredura não é snapshot atômico: escritas concorrentes e falhas parciais limitam a limpeza. Tags e invalidação não fornecem consistência forte; falhas de invalidação ou escritas externas podem deixar resultados obsoletos. Veja também [Cache no README](../README.md#cache).

## Testes e verificação dos drivers

### Ambiente isolado e suíte completa

Use bancos de teste: alguns testes criam/removem tabelas. **Não aponte a suíte para dados da aplicação.** O Compose de testes não depende das senhas do `.env` local; fornece explicitamente:

| Conexão em `php-test` | Variáveis / valores |
|---|---|
| MySQL | `DOCKER_MYSQL_HOST=mysql`, `DOCKER_MYSQL_PORT=3306`, `DOCKER_MYSQL_DATABASE=query_builder_test`, `DOCKER_MYSQL_USERNAME=query_builder`, `DOCKER_MYSQL_PASSWORD=query-builder-test` |
| PostgreSQL | `PGVECTOR_HOST=pgvector`, `PGVECTOR_PORT=5432`, `PGVECTOR_DATABASE=query_builder_test`, `PGVECTOR_USERNAME=query_builder`, `PGVECTOR_PASSWORD=query-builder-test`, `PGVECTOR_INTEGRATION=1` |
| Redis | `REDIS_HOST=redis`, `REDIS_PORT=6379`; sem senha |

MySQL usa `query-builder-test-root` para root. Essas credenciais são exclusivas dos testes. Os volumes SQL são `mysql_test_data` e `pgvector_test_data`, separados dos volumes de desenvolvimento.

```bash
docker compose -f docker-compose.test.yml config --quiet
docker compose -f docker-compose.test.yml up -d mysql pgvector redis
docker compose -f docker-compose.test.yml ps -a
docker compose -f docker-compose.test.yml build php-test
docker compose -f docker-compose.test.yml run --rm php-test composer test
```

O atalho `composer test:docker` executa `run --build --rm php-test composer test`; as dependências são iniciadas e aguardadas por `depends_on` com `service_healthy`. Para análise estática e estilo, use `composer quality:docker` ou `docker compose -f docker-compose.test.yml run --rm php-test composer quality` após o build.

### Extensões e PDO

No host:

```bash
php -v
php --ini
php -m
php -r 'print_r(PDO::getAvailableDrivers());'
```

Na imagem de testes já construída, sem iniciar bancos:

```bash
docker compose -f docker-compose.test.yml run --rm --no-deps php-test php -r 'print_r(PDO::getAvailableDrivers());'
docker compose -f docker-compose.test.yml run --rm --no-deps php-test php --ri redis
```

Para os testes SQL completos, a lista PDO deve conter `sqlite`, `mysql` e `pgsql`.

### Seleção de testes existentes

Não há suíte PHPUnit independente para cada banco: há classes específicas e testes com data providers. Após o build, os comandos abaixo selecionam testes reais. `--no-logging` evita gerar o relatório JUnit nas execuções pontuais.

```bash
# SQLite: arquivos e memória, sem iniciar servidores
docker compose -f docker-compose.test.yml run --rm --no-deps php-test vendor/bin/phpunit --no-coverage --no-logging tests/QueryBuilder/SQLitePathIntegrationTest.php

# MySQL: caso #1 do provider atual (sqlite #0, mysql #1, pgsql #2)
docker compose -f docker-compose.test.yml run --rm php-test vendor/bin/phpunit --no-coverage --no-logging --filter 'testBatchCteSubqueryRawAndBuilderReuse#1' tests/QueryBuilder/SqlCompilationContextBindingRegressionTest.php

# PostgreSQL: caso #2 do mesmo provider
docker compose -f docker-compose.test.yml run --rm php-test vendor/bin/phpunit --no-coverage --no-logging --filter 'testBatchCteSubqueryRawAndBuilderReuse#2' tests/QueryBuilder/SqlCompilationContextBindingRegressionTest.php

# PostgreSQL com pgvector
docker compose -f docker-compose.test.yml run --rm php-test vendor/bin/phpunit --no-coverage --no-logging tests/QueryBuilder/PgVectorIntegrationTest.php

# Cache Redis real
docker compose -f docker-compose.test.yml run --rm php-test vendor/bin/phpunit --no-coverage --no-logging tests/QueryBuilder/RedisCacheIntegrationTest.php
```

Os casos selecionados não substituem a suíte completa. `run php-test` inicia todas as dependências declaradas, mesmo quando o filtro seleciona um banco só; `--no-deps` no exemplo SQLite evita isso. Alguns testes de cache usam mocks; o arquivo selecionado faz integração com Redis.

Clientes interativos e logs dos bancos de teste:

```bash
docker compose -f docker-compose.test.yml exec mysql mysql -u query_builder -p query_builder_test
docker compose -f docker-compose.test.yml exec pgvector psql -U query_builder -d query_builder_test
docker compose -f docker-compose.test.yml logs --tail=100 mysql pgvector redis
```

## Parada e remoção de dados

Parar sem remover contêineres ou volumes:

```bash
docker compose -f docker-compose.yml stop
docker compose -f docker-compose.test.yml stop
```

Remover contêineres e redes, preservando volumes nomeados:

```bash
docker compose -f docker-compose.yml down
docker compose -f docker-compose.test.yml down
```

**Destrutivo: `down --volumes` remove os volumes do projeto e seus dados. Faça backup antes. Não execute para resolver um problema de senha sem avaliar a perda de dados.**

```bash
# Apaga MySQL, PostgreSQL e Redis persistentes de desenvolvimento
docker compose -f docker-compose.yml down --volumes

# Apaga os volumes SQL de testes
docker compose -f docker-compose.test.yml down --volumes
```

Os atalhos `composer docker:test:down` e `composer docker:test:reset` correspondem a `down` e `down --volumes` dos testes. Dados em memória ou na camada gravável de um contêiner não recebem a garantia de preservação dos volumes nomeados.

## Resolução de problemas

| Sintoma | Verificação e ação |
|---|---|
| Driver PDO não encontrado | Confira `PDO::getAvailableDrivers()`, `php -m` e `php --ini` no mesmo PHP da aplicação. Habilite `pdo_sqlite`, `pdo_mysql` ou `pdo_pgsql`; reinicie o serviço PHP quando aplicável. Para a imagem do projeto, reconstrua `php-test`. |
| Conexão recusada | Confira `ps -a`, logs e saúde. No host use a porta publicada do desenvolvimento; entre contêineres use nome do serviço e porta interna. O Compose de testes não publica portas. |
| Acesso negado | Confira usuário, senha, banco e ambiente. Variáveis de inicialização de MySQL/PostgreSQL não redefinem senhas em volumes já inicializados. Use as credenciais existentes ou faça a alteração administrativa apropriada; não remova volumes por tentativa. |
| Banco inexistente | Confira `DOCKER_MYSQL_DATABASE` ou `PGVECTOR_DATABASE` e o banco realmente criado. A conexão PHP não cria databases MySQL/PostgreSQL. A criação pelas variáveis da imagem ocorre na inicialização de dados novos. |
| Permissão negada no SQLite | Confira caminho absoluto, diretório pai, proprietário e escrita no arquivo/diretório pelo usuário PHP. Em Docker, confira permissões do volume e dos arquivos auxiliares. |
| Hostname não resolve | `mysql`, `pgvector` e `redis` exigem a rede Compose correspondente. No host use `127.0.0.1`; não use a porta publicada para uma conexão interna. |
| Porta ocupada no host | Identifique quem a utiliza. No desenvolvimento, ajuste `DOCKER_MYSQL_PORT`, `PGVECTOR_PORT` ou `DOCKER_REDIS_PORT` no seu `.env`, recrie o serviço com `up -d` e atualize os clientes. Não se aplica ao Compose de testes, sem portas publicadas. |
| Contêiner não saudável | Examine `ps -a` e logs. Aguarde a inicialização; confira armazenamento, permissões e credenciais do healthcheck. Uma senha root MySQL diferente da persistida pode afetar a verificação. |
| Redis indisponível | Execute o `redis-cli ping` do ambiente correto; confira senha, rede, porta e `ext-redis` no PHP. `RedisCache` pode lançar `CacheException` na conexão; não presuma que cache substitui a disponibilidade do banco SQL. |
| Teste pgvector ignorado ou extensão ausente | Use o Compose de testes, que fornece `PGVECTOR_INTEGRATION=1`, e a imagem `pgvector`. Confira a extensão no database correto e as permissões do usuário. |

O Compose de desenvolvimento publica portas sem restringir o endereço de bind a loopback. Considere a exposição de rede do host; os exemplos são de desenvolvimento/teste, não uma configuração de produção.
