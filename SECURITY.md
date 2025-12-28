# 🔒 Política de Segurança

## 📋 Status de Segurança

**Status Geral:** 🟢 **SEGURO para produção**

Este Query Builder foi desenvolvido com **segurança por design**:
- ✅ 100% prepared statements para valores
- ✅ Type-safe binding (PDO::PARAM_*)
- ✅ Strict types habilitado
- ✅ Enums tipadas para operadores SQL
- ✅ Transações com rollback automático

**Última análise:** Dezembro 2025  
**Análise completa:** [SECURITY_AUDIT_REVISED.md](SECURITY_AUDIT_REVISED.md)

---

## 📋 Versões Suportadas

| Versão | Suportada          | Status |
| ------ | ------------------ | ------ |
| 1.0.x  | ✅ Sim            | Atual  |
| < 1.0  | ❌ Não            | Descontinuada |

---

## 🐛 Reportando Vulnerabilidades

### ⚠️ IMPORTANTE: Não reporte vulnerabilidades via Issues públicas!

Para reportar problemas de segurança de forma responsável:

### 📧 Contato de Segurança

- **Email:** security@omegaalfa.dev
- **GitHub:** [Security Advisory](https://github.com/omegaalfa/query-builder/security/advisories/new)

### 📝 O que incluir no reporte

Por favor, inclua em seu reporte:

1. **Descrição** clara da vulnerabilidade
2. **Passos para reproduzir** o problema (PoC se possível)
3. **Impacto potencial** da vulnerabilidade
4. **Versão afetada** do Query Builder
5. **Sugestão de correção** (se tiver)

### ⏱️ Tempo de Resposta

- **24 horas**: Confirmação de recebimento
- **48 horas**: Avaliação inicial de severidade
- **7 dias**: Fix para vulnerabilidades críticas
- **30 dias**: Fix para vulnerabilidades médias/baixas

### 🏆 Reconhecimento

Pesquisadores que reportarem vulnerabilidades de forma responsável serão:

- Creditados no CHANGELOG (se desejarem)
- Mencionados no arquivo SECURITY.md
- Notificados quando a correção for lançada

---

## 📚 Melhores Práticas de Uso Seguro

### ✅ Padrões Seguros

```php
// ✅ SEGURO - Valores sempre usam prepared statements
$query->where('email', SqlOperator::EQUALS, $_POST['email']);
$query->whereIn('status', $_POST['statuses']);

// ✅ SEGURO - Whitelist para campos dinâmicos
$allowedFields = ['id', 'name', 'email', 'created_at'];
$userFields = $_GET['fields'] ?? [];
$safeFields = array_intersect($userFields, $allowedFields);
$query->select('users', $safeFields);

// ✅ SEGURO - Operadores tipados
$query->where('age', SqlOperator::GREATER_THAN, 18);
$query->orderBy('name', OrderDirection::ASC);

// ✅ SEGURO - JOINs validados
$query->join('orders', 'users.id', '=', 'orders.user_id', JoinType::LEFT);
```

### ❌ Padrões Inseguros (API Misuse)

```php
// ❌ INSEGURO - Input direto em campos
$fields = explode(',', $_GET['fields']);
$query->select('users', $fields); // Pode injetar expressões SQL!

// ✅ CORREÇÃO:
$allowedFields = ['id', 'name', 'email'];
$fields = array_intersect($_GET['fields'], $allowedFields);
$query->select('users', $fields);

// ❌ INSEGURO - Input em nome de tabela
$table = $_GET['table'];
$query->select($table); // Injection risk!

// ✅ CORREÇÃO:
$allowedTables = ['users', 'orders', 'products'];
if (in_array($_GET['table'], $allowedTables, true)) {
    $query->select($_GET['table']);
}

// ❌ INSEGURO - raw() com input de usuário
$query->raw($_POST['custom_query']); // NUNCA!

// ✅ CORREÇÃO:
// Apenas use raw() com SQL estático confiável
$query->raw('SELECT * FROM users WHERE created_at > ?', [date('Y-m-d')]);
```

### 🔐 Cache Seguro

```php
// ⚠️ Cache sem isolamento - pode vazar dados entre usuários
$query->select('orders')->cache(3600);

// ✅ CORREÇÃO - Sempre isole por contexto
$query->select('orders')
      ->where('user_id', '=', $currentUserId)
      ->cache(3600);

// Ou adicione contexto explícito se implementar cacheContext():
$query->select('sensitive_data')
      ->cacheContext($userId . ':' . $userRole)
      ->cache(3600);
```

---

## 📖 Recursos de Segurança Adicionais

### Links Úteis

- [OWASP SQL Injection](https://owasp.org/www-community/attacks/SQL_Injection)
- [PHP PDO Security](https://www.php.net/manual/en/pdo.prepared-statements.php)
- [CWE-89: SQL Injection](https://cwe.mitre.org/data/definitions/89.html)

### Ferramentas de Análise

```bash
# Análise estática com PHPStan
composer phpstan

# Testes de segurança
vendor/bin/phpunit tests/QueryBuilder/QueryBuilderSecurityTest.php
```

---

## 🔄 Atualizações de Segurança

### Como se Manter Atualizado

1. **Watch** o repositório no GitHub
2. Habilite notificações de **Releases**
3. Leia o **CHANGELOG** antes de atualizar
4. Teste em ambiente de **staging** primeiro

### Processo de Update

```bash
# Verificar versão atual
composer show omegaalfa/query-builder

# Atualizar para última versão
composer update omegaalfa/query-builder

# Executar testes
vendor/bin/phpunit
```

---

## 📜 Histórico de Segurança

### Vulnerabilidades Conhecidas

*Nenhuma vulnerabilidade reportada até o momento.*

Se você descobrir uma vulnerabilidade, por favor reporte através dos canais apropriados.

---

## 📞 Contato

- 📧 **Email de Segurança:** security@omegaalfa.dev
- 💬 **Discussões Gerais:** [GitHub Discussions](https://github.com/omegaalfa/query-builder/discussions)
- 🐛 **Bugs Não-Críticos:** [GitHub Issues](https://github.com/omegaalfa/query-builder/issues)

---

**Última Atualização:** 28 de Dezembro de 2025  
**Versão da Política:** 1.0
