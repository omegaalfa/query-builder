<?php

declare(strict_types=1);

namespace Tests\QueryBuilder;

use DateTime;
use Omegaalfa\QueryBuilder\Enums\SqlOperator;
use Omegaalfa\QueryBuilder\Interfaces\CacheInterface;
use Omegaalfa\QueryBuilder\Interfaces\ConnectionInterface;
use Omegaalfa\QueryBuilder\Interfaces\PaginatorInterface;
use Omegaalfa\QueryBuilder\QueryBuilder;
use PHPUnit\Framework\TestCase;

/**
 * ═══════════════════════════════════════════════════════════════════════
 * TESTES ABRANGENTES DE SEGURANÇA PARA O MÉTODO toSql()
 * ═══════════════════════════════════════════════════════════════════════
 * 
 * Este arquivo verifica se o método toSql() mantém a segurança usando 
 * prepared statements (placeholders) e não permite SQL injection.
 * 
 * ⚠️ IMPORTANTE: toSql(true) é APENAS para DEBUG e NUNCA deve ser usado 
 * em produção ou com dados de usuários não confiáveis!
 * 
 * OBJETIVO DOS TESTES:
 * 1. Verificar que toSql() SEM parâmetros SEMPRE usa placeholders (:paramN)
 * 2. Verificar que toSql(true) escapa corretamente os valores para debug
 * 3. Simular ataques SQL injection reais e validar a proteção
 * 4. Testar edge cases e caracteres especiais
 * ═══════════════════════════════════════════════════════════════════════
 */
final class QueryBuilderToSqlSecurityTest extends TestCase
{
    private QueryBuilder $qb;

    protected function setUp(): void
    {
        $mockConnection = $this->createMock(ConnectionInterface::class);
        $mockPaginator = $this->createMock(PaginatorInterface::class);
        $mockCache = $this->createMock(CacheInterface::class);
        $this->qb = new QueryBuilder($mockConnection, $mockPaginator, $mockCache);
    }

    // ═══════════════════════════════════════════════════════════════════
    // GRUPO 1: SQL INJECTION CLÁSSICOS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ✅ Teste: SQL Injection via OR 1=1
     * Ataque: ' OR 1=1 --
     * Objetivo: Bypassar autenticação ou expor todos os registros
     */
    public function testSqlInjectionOr1Equals1(): void
    {
        $maliciousInput = "' OR 1=1 --";
        
        $this->qb->select('usuarios')
           ->where('nome', SqlOperator::EQUALS, $maliciousInput);
        
        // SEM parâmetros: DEVE usar placeholders
        $sqlSafe = $this->qb->toSql();
        $this->assertStringContainsString(':param', $sqlSafe, 
            "❌ FALHA DE SEGURANÇA: toSql() deve SEMPRE usar placeholders!");
        $this->assertStringNotContainsString('OR 1=1', $sqlSafe,
            "❌ FALHA DE SEGURANÇA: Código SQL malicioso não pode aparecer sem placeholders!");
        
        // COM parâmetros (debug): Deve escapar corretamente
        $sqlDebug = $this->qb->toSql(true);
        $this->assertStringContainsString("'\\' OR 1=1 --'", $sqlDebug,
            "⚠️ toSql(true) deve escapar aspas simples com addslashes()");
        
        echo "\n✅ PROTEÇÃO ATIVA: SQL Injection OR 1=1 bloqueado\n";
        echo "   SQL Seguro:  {$sqlSafe}\n";
        echo "   SQL Debug:   {$sqlDebug}\n";
    }

    /**
     * ✅ Teste: SQL Injection via UNION SELECT
     * Ataque: ' UNION SELECT password FROM admin_users --
     * Objetivo: Extrair dados de outras tabelas
     */
    public function testSqlInjectionUnionSelect(): void
    {
        $maliciousInput = "' UNION SELECT password FROM admin_users WHERE '1'='1";
        
        $this->qb->select('usuarios')
           ->where('email', SqlOperator::EQUALS, $maliciousInput);
        
        $sqlSafe = $this->qb->toSql();
        $this->assertStringContainsString(':param', $sqlSafe);
        $this->assertStringNotContainsString('UNION SELECT', $sqlSafe,
            "❌ FALHA: UNION SELECT não deve aparecer sem placeholders!");
        
        $sqlDebug = $this->qb->toSql(true);
        $this->assertStringContainsString("UNION SELECT", $sqlDebug);
        $this->assertMatchesRegularExpression("/'.*UNION SELECT.*'/", $sqlDebug,
            "UNION SELECT deve estar dentro de aspas simples");
        
        echo "\n✅ PROTEÇÃO ATIVA: SQL Injection UNION SELECT bloqueado\n";
        echo "   SQL Seguro: {$sqlSafe}\n";
    }

    /**
     * ✅ Teste: SQL Injection via comentários SQL
     * Ataque: admin' --
     * Objetivo: Comentar o resto da query (remover verificação de senha)
     */
    public function testSqlInjectionSqlComments(): void
    {
        $maliciousInput = "admin' --";
        
        $this->qb->select('usuarios')
           ->where('username', SqlOperator::EQUALS, $maliciousInput);
        
        $sqlSafe = $this->qb->toSql();
        $this->assertStringContainsString(':param', $sqlSafe);
        
        $sqlDebug = $this->qb->toSql(true);
        $this->assertStringContainsString("'admin\\' --'", $sqlDebug,
            "Aspas e comentários devem ser escapados");
        
        echo "\n✅ PROTEÇÃO ATIVA: SQL Injection via comentário bloqueado\n";
        echo "   SQL Debug: {$sqlDebug}\n";
    }

    /**
     * ✅ Teste: SQL Injection via stacked queries
     * Ataque: '; DROP TABLE usuarios; --
     * Objetivo: Executar múltiplas queries maliciosas
     */
    public function testSqlInjectionStackedQueries(): void
    {
        $maliciousInput = "'; DROP TABLE usuarios; --";
        
        $this->qb->select('usuarios')
           ->where('id', SqlOperator::EQUALS, $maliciousInput);
        
        $sqlSafe = $this->qb->toSql();
        $this->assertStringContainsString(':param', $sqlSafe);
        $this->assertStringNotContainsString('DROP TABLE', $sqlSafe,
            "❌ CRÍTICO: DROP TABLE não pode aparecer fora de placeholder!");
        
        $sqlDebug = $this->qb->toSql(true);
        $this->assertStringContainsString("'\\'; DROP TABLE usuarios; --'", $sqlDebug);
        
        echo "\n✅ PROTEÇÃO ATIVA: Stacked queries (DROP TABLE) bloqueado\n";
        echo "   SQL Seguro: {$sqlSafe}\n";
    }

    /**
     * ✅ Teste: SQL Injection via hex encoding
     * Ataque: 0x61646D696E (admin em hex)
     * Objetivo: Bypass de filtros via encoding
     */
    public function testSqlInjectionHexEncoding(): void
    {
        $maliciousInput = "0x61646D696E";
        
        $this->qb->select('usuarios')
           ->where('role', SqlOperator::EQUALS, $maliciousInput);
        
        $sqlSafe = $this->qb->toSql();
        $this->assertStringContainsString(':param', $sqlSafe);
        
        $sqlDebug = $this->qb->toSql(true);
        $this->assertStringContainsString("'0x61646D696E'", $sqlDebug,
            "Hex strings devem ser tratados como strings normais");
        
        echo "\n✅ PROTEÇÃO ATIVA: Hex encoding tratado como string\n";
    }

    // ═══════════════════════════════════════════════════════════════════
    // GRUPO 2: CARACTERES ESPECIAIS E ENCODING
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ✅ Teste: Aspas simples e duplas
     */
    public function testSpecialCharactersQuotes(): void
    {
        $inputWithQuotes = "O'Reilly \"Books\"";
        
        $this->qb->select('produtos')
           ->where('nome', SqlOperator::EQUALS, $inputWithQuotes);
        
        $sqlSafe = $this->qb->toSql();
        $this->assertStringContainsString(':param', $sqlSafe);
        
        $sqlDebug = $this->qb->toSql(true);
        $this->assertStringContainsString("O\\'Reilly", $sqlDebug,
            "Aspas simples devem ser escapadas");
        // Aspas duplas são tratadas de forma diferente por addslashes
        $this->assertStringContainsString('Books', $sqlDebug,
            "Conteúdo deve estar presente");
        
        echo "\n✅ Aspas corretamente escapadas no debug\n";
    }

    /**
     * ✅ Teste: Backslashes
     */
    public function testSpecialCharactersBackslashes(): void
    {
        $inputWithBackslash = "C:\\Users\\Admin\\file.txt";
        
        $this->qb->select('arquivos')
           ->where('path', SqlOperator::EQUALS, $inputWithBackslash);
        
        $sqlDebug = $this->qb->toSql(true);
        $this->assertStringContainsString("\\\\", $sqlDebug,
            "Backslashes devem ser escapados");
        
        echo "\n✅ Backslashes escapados corretamente\n";
    }

    /**
     * ✅ Teste: Unicode e emojis
     */
    public function testSpecialCharactersUnicode(): void
    {
        $unicodeInput = "日本語 テスト 🔒 emoji";
        
        $this->qb->select('usuarios')
           ->where('nome', SqlOperator::EQUALS, $unicodeInput);
        
        $sqlDebug = $this->qb->toSql(true);
        $this->assertStringContainsString($unicodeInput, $sqlDebug,
            "Unicode deve ser preservado");
        
        echo "\n✅ Unicode preservado corretamente\n";
    }

    // ═══════════════════════════════════════════════════════════════════
    // GRUPO 3: OPERADORES SQL PERIGOSOS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ✅ Teste: LIKE com wildcards maliciosos
     */
    public function testLikeOperatorWithMaliciousWildcards(): void
    {
        $maliciousLike = "%' OR '1'='1";
        
        $this->qb->select('usuarios')
           ->where('nome', SqlOperator::LIKE, $maliciousLike);
        
        $sqlSafe = $this->qb->toSql();
        $this->assertStringContainsString('LIKE :param', $sqlSafe);
        
        $sqlDebug = $this->qb->toSql(true);
        $this->assertStringContainsString("LIKE '%\\' OR \\'1\\'=\\'1'", $sqlDebug);
        
        echo "\n✅ LIKE com wildcards maliciosos bloqueado\n";
    }

    /**
     * ✅ Teste: IN com valores maliciosos
     */
    public function testInOperatorWithMaliciousValues(): void
    {
        $maliciousArray = [1, 2, "3) OR 1=1 --"];
        
        $this->qb->select('usuarios')
           ->whereIn('id', $maliciousArray);
        
        $sqlSafe = $this->qb->toSql();
        $this->assertMatchesRegularExpression('/:id_in_\d+.*:id_in_\d+.*:id_in_\d+/', $sqlSafe,
            "IN deve ter placeholders separados para cada valor");
        
        $sqlDebug = $this->qb->toSql(true);
        $this->assertStringContainsString("'3) OR 1=1 --'", $sqlDebug,
            "Valor malicioso no IN deve ser escapado");
        
        echo "\n✅ IN operator com valores maliciosos bloqueado\n";
    }

    /**
     * ✅ Teste: BETWEEN com valores manipulados
     */
    public function testBetweenOperatorWithMaliciousValues(): void
    {
        $maliciousValues = ["1' OR '1'='1", "1000' OR '1'='1"];
        
        $this->qb->select('produtos')
           ->whereBetween('preco', $maliciousValues);
        
        $sqlSafe = $this->qb->toSql();
        $this->assertStringContainsString('BETWEEN', $sqlSafe);
        $this->assertMatchesRegularExpression('/:preco_bt\d+/', $sqlSafe,
            "BETWEEN deve ter placeholders");
        
        $sqlDebug = $this->qb->toSql(true);
        $this->assertStringContainsString("'1\\' OR \\'1\\'=\\'1'", $sqlDebug);
        
        echo "\n✅ BETWEEN com valores maliciosos bloqueado\n";
    }

    // ═══════════════════════════════════════════════════════════════════
    // GRUPO 4: TIPOS DE DADOS
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ✅ Teste: Booleanos
     */
    public function testDataTypesBoolean(): void
    {
        $this->qb->select('usuarios')
           ->where('ativo', SqlOperator::EQUALS, true)
           ->where('deletado', SqlOperator::EQUALS, false);
        
        $sqlSafe = $this->qb->toSql();
        $this->assertStringContainsString(':param0', $sqlSafe);
        
        $sqlDebug = $this->qb->toSql(true);
        $this->assertMatchesRegularExpression('/`ativo`\s*=\s*1/', $sqlDebug,
            "true deve ser convertido para 1");
        $this->assertMatchesRegularExpression('/`deletado`\s*=\s*0/', $sqlDebug,
            "false deve ser convertido para 0");
        
        echo "\n✅ Booleanos convertidos corretamente (1/0)\n";
        echo "   SQL Debug: {$sqlDebug}\n";
    }

    /**
     * ✅ Teste: NULL
     */
    public function testDataTypesNull(): void
    {
        $this->qb->select('usuarios')
           ->where('deletado_em', SqlOperator::EQUALS, null);
        
        $sqlDebug = $this->qb->toSql(true);
        $this->assertStringContainsString('= NULL', $sqlDebug,
            "NULL deve aparecer como NULL (não como string)");
        
        echo "\n✅ NULL tratado corretamente\n";
    }

    /**
     * ✅ Teste: Números (int e float)
     */
    public function testDataTypesNumeric(): void
    {
        $this->qb->select('produtos')
           ->where('id', SqlOperator::EQUALS, 123)
           ->where('preco', SqlOperator::GREATER_THAN, 99.99);
        
        $sqlDebug = $this->qb->toSql(true);
        $this->assertMatchesRegularExpression('/`id`\s*=\s*123/', $sqlDebug,
            "Inteiros não devem ter aspas");
        $this->assertMatchesRegularExpression('/`preco`\s*>\s*99\.99/', $sqlDebug,
            "Floats não devem ter aspas");
        
        echo "\n✅ Números sem aspas (correto)\n";
    }

    /**
     * ✅ Teste: DateTime
     */
    public function testDataTypesDateTime(): void
    {
        $date = new DateTime('2024-01-15 10:30:00');
        
        $this->qb->select('pedidos')
           ->where('criado_em', SqlOperator::GREATER_THAN, $date);
        
        $sqlDebug = $this->qb->toSql(true);
        $this->assertStringContainsString("'2024-01-15 10:30:00'", $sqlDebug,
            "DateTime deve ser formatado como string SQL");
        
        echo "\n✅ DateTime formatado corretamente\n";
    }

    // ═══════════════════════════════════════════════════════════════════
    // GRUPO 5: MÚLTIPLAS CONDIÇÕES
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ✅ Teste: Múltiplas condições AND
     */
    public function testMultipleWhereConditionsAnd(): void
    {
        $this->qb->select('usuarios')
           ->where('nome', SqlOperator::EQUALS, "João' OR '1'='1")
           ->where('email', SqlOperator::EQUALS, "admin@test.com' --")
           ->where('ativo', SqlOperator::EQUALS, true);
        
        $sqlSafe = $this->qb->toSql();
        $this->assertStringContainsString(':param0', $sqlSafe);
        $this->assertStringContainsString(':param1', $sqlSafe);
        $this->assertStringContainsString(':param2', $sqlSafe);
        
        $sqlDebug = $this->qb->toSql(true);
        $this->assertStringContainsString("'João\\' OR \\'1\\'=\\'1'", $sqlDebug);
        $this->assertStringContainsString("'admin@test.com\\' --'", $sqlDebug);
        
        echo "\n✅ Múltiplas condições AND: todas protegidas\n";
    }

    /**
     * ✅ Teste: Condições OR
     */
    public function testMultipleWhereConditionsOr(): void
    {
        $this->qb->select('usuarios')
           ->where('role', SqlOperator::EQUALS, "admin' OR '1'='1")
           ->orWhere('role', SqlOperator::EQUALS, "superadmin'; DROP TABLE users; --");
        
        $sqlSafe = $this->qb->toSql();
        $this->assertStringContainsString('OR', $sqlSafe);
        
        $sqlDebug = $this->qb->toSql(true);
        $this->assertStringContainsString("'admin\\' OR \\'1\\'=\\'1'", $sqlDebug);
        $this->assertStringContainsString("DROP TABLE", $sqlDebug);
        
        echo "\n✅ Condições OR: ambas protegidas\n";
    }

    // ═══════════════════════════════════════════════════════════════════
    // GRUPO 6: EDGE CASES
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ✅ Teste: String vazia
     */
    public function testEdgeCaseEmptyString(): void
    {
        $this->qb->select('usuarios')
           ->where('nome', SqlOperator::EQUALS, '');
        
        $sqlDebug = $this->qb->toSql(true);
        $this->assertStringContainsString("''", $sqlDebug);
        
        echo "\n✅ String vazia tratada corretamente\n";
    }

    /**
     * ✅ Teste: String muito longa com SQL injection
     */
    public function testEdgeCaseVeryLongString(): void
    {
        $longString = str_repeat("A' OR '1'='1 ", 100);
        
        $this->qb->select('usuarios')
           ->where('descricao', SqlOperator::EQUALS, $longString);
        
        $sqlSafe = $this->qb->toSql();
        $this->assertStringContainsString(':param', $sqlSafe);
        
        $sqlDebug = $this->qb->toSql(true);
        $this->assertStringContainsString("A\\' OR \\'1\\'=\\'1", $sqlDebug);
        
        echo "\n✅ String longa com injection bloqueada\n";
    }

    /**
     * ✅ Teste: Strings numéricas
     */
    public function testEdgeCaseNumericStrings(): void
    {
        $this->qb->select('usuarios')
           ->where('id', SqlOperator::EQUALS, '123')
           ->where('codigo', SqlOperator::EQUALS, '00456');
        
        $sqlDebug = $this->qb->toSql(true);
        $this->assertStringContainsString("'123'", $sqlDebug);
        $this->assertStringContainsString("'00456'", $sqlDebug);
        
        echo "\n✅ Strings numéricas com aspas (correto)\n";
    }

    // ═══════════════════════════════════════════════════════════════════
    // GRUPO 7: VERIFICAÇÕES DE SEGURANÇA GERAL
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ✅ Teste: Garantir que placeholders SEMPRE estão presentes
     */
    public function testSecurityPlaceholdersAlwaysPresent(): void
    {
        $this->qb->select('usuarios')
           ->where('id', SqlOperator::EQUALS, 123)
           ->where('nome', SqlOperator::EQUALS, "' OR '1'='1");
        
        $sql = $this->qb->toSql();
        $this->assertStringContainsString(':param', $sql,
            "❌ CRÍTICO: toSql() SEM parâmetros DEVE usar placeholders!");
        $this->assertStringNotContainsString("' OR '1'='1", $sql,
            "❌ CRÍTICO: Código malicioso não pode aparecer sem placeholder!");
        $this->assertStringNotContainsString('123', $sql,
            "Valores não devem aparecer diretamente no SQL");
        
        echo "\n✅ SEGURANÇA VERIFICADA: Placeholders sempre presentes\n";
        echo "   SQL: {$sql}\n";
    }

    /**
     * ✅ Teste: Verificar que nenhum ataque funciona
     */
    public function testSecurityNoInjectionWorksWithPlaceholders(): void
    {
        $attacks = [
            "' OR '1'='1",
            "'; DROP TABLE usuarios; --",
            "admin' --",
            "' UNION SELECT * FROM admin_users --",
            "1'; UPDATE usuarios SET role='admin' WHERE '1'='1",
        ];
        
        echo "\n═══════════════════════════════════════════════════════════════════\n";
        echo "🔒 TESTE FINAL: Verificando proteção contra ataques conhecidos\n";
        echo "═══════════════════════════════════════════════════════════════════\n";
        
        foreach ($attacks as $i => $attack) {
            $this->setUp(); // Reset QueryBuilder
            $this->qb->select('usuarios')->where('username', SqlOperator::EQUALS, $attack);
            
            $sql = $this->qb->toSql();
            
            // NUNCA deve conter os comandos SQL diretamente
            $this->assertStringNotContainsString('OR 1=1', $sql);
            $this->assertStringNotContainsString('DROP TABLE', $sql);
            $this->assertStringNotContainsString('UNION SELECT', $sql);
            $this->assertStringNotContainsString('UPDATE', $sql);
            
            // SEMPRE deve ter placeholder
            $this->assertStringContainsString(':param', $sql);
            
            echo "   ✅ Ataque " . ($i + 1) . " BLOQUEADO: {$attack}\n";
            echo "      SQL gerado: {$sql}\n\n";
        }
        
        echo "═══════════════════════════════════════════════════════════════════\n";
        echo "🎉 TODOS OS ATAQUES FORAM BLOQUEADOS COM SUCESSO!\n";
        echo "═══════════════════════════════════════════════════════════════════\n";
    }

    /**
     * ✅ Teste: Verificar addslashes em toSql(true)
     */
    public function testSecurityAddslashesWorksProperly(): void
    {
        $testCases = [
            "Test'String" => "Test\\'String",
            "Test\\String" => "Test\\\\String",
        ];
        
        foreach ($testCases as $input => $expectedEscaped) {
            $this->setUp();
            $this->qb->select('test')->where('col', SqlOperator::EQUALS, $input);
            
            $sqlDebug = $this->qb->toSql(true);
            $this->assertStringContainsString("'$expectedEscaped'", $sqlDebug,
                "addslashes deve escapar: $input -> $expectedEscaped");
        }
        
        echo "\n✅ addslashes funcionando corretamente\n";
    }
}
