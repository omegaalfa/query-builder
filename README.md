
# 🧩 Omgaalfa Query Builder

![PHP Version](https://img.shields.io/badge/PHP-%3E%3D%208.2-777BB4?style=for-the-badge&logo=php)
![License](https://img.shields.io/badge/license-MIT-green?style=for-the-badge)
![Status](https://img.shields.io/badge/status-stable-success?style=for-the-badge)
![PDO](https://img.shields.io/badge/dependency-PDO-blue?style=for-the-badge)

---

## 🚀 Sobre o Projeto

**Omgaalfa Query Builder** é uma biblioteca **moderna, leve e tipada** em **PHP 8.2+**, criada para facilitar a **construção fluente de queries SQL** com **PDO**, **cache**, **paginação** e **transações**.

Inspirada em Eloquent e Doctrine, mas com **zero dependências externas** e foco em **performance e simplicidade**.

---

## 📦 Instalação

```bash
composer require omgaalfa/query-builder
```

---

## 🛠️ Requisitos

- PHP >= 8.2
- Extensão `pdo` habilitada
- Banco de dados compatível (MySQL, MariaDB, PostgreSQL, SQLite, etc.)

---

## ⚙️ Exemplo de Uso

```php
use Omegaalfa\QueryBuilder\Connection\PDOConnection;
use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\QueryBuilder;
use Omegaalfa\QueryBuilder\Paginator;
use Omegaalfa\QueryBuilder\enums\SqlOperator;
use Omegaalfa\QueryBuilder\enums\OrderDirection;

// Configuração da conexão
$config = new DatabaseSettings(
    driver: 'mysql',
    host: 'localhost',
    database: 'ecommerce',
    username: 'root',
    password: '',
    port: 3306
);

// Instanciando
$connection = new PDOConnection($config);
$paginator  = new Paginator();
$query      = new QueryBuilder($connection, $paginator);

// SELECT com filtros
$sql = $query
    ->select('produtos', ['id', 'nome', 'preco'])
    ->where('preco', SqlOperator::GREATER_THAN, 100)
    ->orderBy('preco', OrderDirection::DESC)
    ->limit(10)
    ->getQuerySql();

echo $sql;
// Resultado: SELECT id, nome, preco FROM produtos WHERE preco > ? ORDER BY preco DESC LIMIT 10
```

---

## 📚 Recursos Suportados

- ✅ SELECT / INSERT / UPDATE / DELETE
- ✅ WHERE / OR WHERE / WHERE IN / WHERE BETWEEN
- ✅ JOINs (INNER, LEFT, RIGHT, FULL)
- ✅ ORDER BY / GROUP BY / HAVING
- ✅ Consulta RAW (`raw`)
- ✅ Paginação integrada
- ✅ Suporte a SQL parametrizado (prepared statements)
- ✅ Totalmente tipado (Enums e interfaces)
- ✅ Compatível com PSR e padrão SOLID
- ✅ Sem dependências externas

---

## 🧪 Testes

Você pode escrever testes com PHPUnit. Exemplo de comando:

```bash
vendor/bin/phpunit
```

---

## ✅ Roadmap

- [x] Suporte completo a SQL fluente
- [x] Enums tipadas para JOINs, operadores e ordenação
- [x] Paginação nativa com suporte ao total
- [x] Suporte a consultas RAW
- [ ] Cache de queries (em andamento)
- [ ] Integração com outras camadas de repositório
- [ ] Compatibilidade multi-driver estendida

---

## 📄 Licença

Distribuído sob a licença **MIT**. Veja `LICENSE` para mais informações.

---

## 🤝 Contribuindo

Pull Requests, Issues e Forks são bem-vindos!  
Siga os padrões de código e documente qualquer comportamento novo.

---

## 💬 Contato

Criado por **Omegaalfa**.  
Para dúvidas ou sugestões: [github.com/omegaalfa](https://github.com/omegaalfa)
