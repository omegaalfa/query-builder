<?php

declare(strict_types=1);

namespace Omegaalfa\QueryBuilder\connection;

use Omegaalfa\QueryBuilder\exceptions\DatabaseException;
use Omegaalfa\QueryBuilder\interfaces\ConnectionInterface;
use PDO;
use Throwable;

final class PDOConnection implements ConnectionInterface
{
    private ?PDO $connection = null;
    private int $transactionLevel = 0;
    private array $savepoints = [];
    private bool $bufferedQuery = true;

    public function __construct(protected DatabaseSettings $config)
    {
    }

    /**
     * Verifica se há uma conexão PDO ativa.
     *
     * Não garante que a conexão está funcional, apenas que foi instanciada.
     */
    public function isConnected(): bool
    {
        return $this->connection !== null;
    }

    /**
     * Retorna o nível de aninhamento de transações.
     *
     * @return int 0 = sem transação ativa, >0 = transações aninhadas
     */
    public function getTransactionLevel(): int
    {
        return $this->transactionLevel;
    }

    /**
     * Verifica se há uma transação ativa.
     */
    public function inTransaction(): bool
    {
        return $this->transactionLevel > 0;
    }

    /**
     * Executa transações com rollback automático em caso de erro.
     *
     * @param callable $callback Função que recebe a instância PDO
     * @return mixed O retorno do callback
     * @throws DatabaseException Se a transação falhar
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this->pdo());
            $this->commit();
            return $result;
        } catch (Throwable $e) {
            $this->rollBack();
            throw new DatabaseException(
                message: "Transaction failed: {$e->getMessage()}",
                code: (int)$e->getCode(),
                previousException: $e
            );
        }
    }

    /**
     * Inicia uma nova transação ou cria um savepoint para transação aninhada.
     *
     * @return bool
     * @throws DatabaseException Se savepoints não forem suportados no driver
     */
    public function beginTransaction(): bool
    {
        $pdo = $this->pdo();

        if ($this->transactionLevel === 0) {
            $result = $pdo->beginTransaction();
            if ($result) {
                $this->transactionLevel++;
            }
            return $result;
        }

        if (!$this->supportsSavepoints()) {
            throw new DatabaseException(
                "Nested transactions (savepoints) not supported by {$this->config->driver} driver"
            );
        }

        $savepoint = 'SAVEPOINT_' . $this->transactionLevel;
        $pdo->exec("SAVEPOINT {$savepoint}");
        $this->savepoints[] = $savepoint;
        $this->transactionLevel++;

        return true;
    }

    /**
     * Verifica se o driver atual suporta savepoints (transações aninhadas).
     *
     * @return bool True para MySQL e PostgreSQL, false para outros
     */
    private function supportsSavepoints(): bool
    {
        return in_array($this->config->driver, ['mysql', 'pgsql'], true);
    }

    /**
     * Retorna a instância PDO ativa, criando-a se necessário.
     *
     * Para alterar o modo buffered query, use setBufferedQuery() antes
     * de estabelecer a primeira conexão, ou use reconnect().
     *
     * @return PDO
     * @throws DatabaseException
     */
    public function pdo(): PDO
    {
        if ($this->connection === null) {
            $this->connect();
        }
        return $this->connection;
    }

    /**
     * Define o modo buffered query para conexões MySQL.
     *
     * Deve ser chamado ANTES de estabelecer a primeira conexão.
     * Para alterar em conexão existente, use reconnect().
     *
     * @param bool $bufferedQuery
     * @return $this
     * @throws DatabaseException Se a conexão já estiver estabelecida
     */
    public function setBufferedQuery(bool $bufferedQuery): self
    {
        if ($this->connection !== null) {
            throw new DatabaseException(
                "Cannot change buffered query mode after connection is established. Call disconnect() first."
            );
        }
        $this->bufferedQuery = $bufferedQuery;
        return $this;
    }

    /**
     * Estabelece a conexão com o banco de dados.
     *
     * Se a conexão já existir, este método não faz nada.
     *
     * @return void
     * @throws DatabaseException Se a conexão falhar
     */
    public function connect(): void
    {
        if ($this->connection !== null) {
            return;
        }

        $dsn = $this->config->toDsn();
        $options = $this->buildConnectionOptions();

        try {
            $this->connection = new PDO(
                $dsn,
                $this->config->username,
                $this->config->password,
                $options
            );
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->applyPostConnectionSettings();
        } catch (Throwable $e) {
            throw new DatabaseException(
                message: "Database connection failed: {$e->getMessage()}",
                code: (int)$e->getCode(),
                previousException: $e
            );
        }
    }

    /**
     * Constrói as opções de conexão específicas por driver.
     *
     * @return array Opções PDO configuradas
     */
    private function buildConnectionOptions(): array
    {
        $options = $this->config->options;

        if ($this->config->driver === 'mysql' && class_exists('\Pdo\Mysql')) {
            $options[\Pdo\Mysql::ATTR_USE_BUFFERED_QUERY] = $this->bufferedQuery;
        }

        return $options;
    }

    /**
     * Aplica configurações pós-conexão específicas por driver.
     *
     * @return void
     * @throws DatabaseException
     */
    private function applyPostConnectionSettings(): void
    {
        match ($this->config->driver) {
            'mysql' => $this->applyMySQLSettings(),
            'pgsql' => $this->applyPostgreSQLSettings(),
            'sqlite' => $this->applySQLiteSettings(),
            default => null
        };
    }

    /**
     * Aplica configurações específicas do MySQL.
     *
     * @return void
     * @throws DatabaseException
     */
    private function applyMySQLSettings(): void
    {
        if (empty($this->config->collation)) {
            return;
        }

        $charset = $this->config->charset;
        $collation = $this->config->collation;

        // Validação rigorosa: permite alfanuméricos, underscores e hífens
        if (!preg_match('/^[a-z0-9_-]+$/i', $charset) ||
            !preg_match('/^[a-z0-9_-]+$/i', $collation)) {
            throw new DatabaseException('Invalid charset or collation format');
        }

        // Validar que collation corresponde ao charset
        if (!str_starts_with($collation, $charset . '_')) {
            throw new DatabaseException(
                "Collation '{$collation}' does not match charset '{$charset}'"
            );
        }

        try {
            $this->connection->exec("SET NAMES '{$charset}' COLLATE '{$collation}'");
        } catch (Throwable $e) {
            throw new DatabaseException(
                "Failed to set MySQL charset/collation: {$e->getMessage()}",
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Aplica configurações específicas do PostgreSQL.
     *
     * @return void
     * @throws DatabaseException
     */
    private function applyPostgreSQLSettings(): void
    {
        if (empty($this->config->charset)) {
            return;
        }

        $charset = strtoupper($this->config->charset);

        // Whitelist de encodings PostgreSQL comuns
        $validEncodings = [
            'UTF8', 'LATIN1', 'LATIN2', 'LATIN9', 'SQL_ASCII',
            'WIN1252', 'WIN1251', 'ISO_8859_5', 'ISO_8859_6',
            'ISO_8859_7', 'ISO_8859_8', 'EUC_JP', 'EUC_CN', 'EUC_KR'
        ];

        if (!in_array($charset, $validEncodings, true)) {
            // Apenas aceita formato válido para encodings não padrão
            if (!preg_match('/^[A-Z0-9_]+$/', $charset)) {
                throw new DatabaseException("Invalid PostgreSQL encoding format: {$charset}");
            }

            trigger_error(
                "Using non-standard PostgreSQL encoding: {$charset}",
                E_USER_NOTICE
            );
        }

        try {
            $this->connection->exec("SET client_encoding TO '{$charset}'");
        } catch (Throwable $e) {
            throw new DatabaseException(
                "Failed to set PostgreSQL encoding '{$charset}': {$e->getMessage()}",
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Aplica configurações específicas do SQLite.
     *
     * @return void
     * @throws DatabaseException
     */
    private function applySQLiteSettings(): void
    {
        // CRÍTICO: Habilita foreign key constraints (desabilitado por padrão!)
        try {
            $this->connection->exec('PRAGMA foreign_keys = ON');

            // Verificar se foi realmente habilitado
            $enabled = $this->connection->query('PRAGMA foreign_keys')->fetchColumn();
            if ($enabled != 1) {
                trigger_error(
                    'SQLite foreign keys could not be enabled',
                    E_USER_WARNING
                );
            }
        } catch (Throwable $e) {
            throw new DatabaseException(
                "Failed to enable SQLite foreign keys: {$e->getMessage()}",
                (int)$e->getCode(),
                $e
            );
        }

        // OPCIONAL: Write-Ahead Logging para melhor concorrência
        try {
            $this->connection->exec('PRAGMA journal_mode = WAL');
        } catch (Throwable) {
            // Pode falhar em sistemas de arquivos especiais - não é crítico
        }
    }

    /**
     * Confirma a transação ativa ou libera o savepoint atual.
     *
     * @return bool
     * @throws DatabaseException
     */
    public function commit(): bool
    {
        if ($this->transactionLevel === 0) {
            throw new DatabaseException("No active transaction to commit");
        }

        $pdo = $this->connection;
        if ($pdo === null) {
            throw new DatabaseException("Cannot commit: connection is not active");
        }

        $this->transactionLevel--;

        if ($this->transactionLevel === 0) {
            return $pdo->commit();
        }

        $savepoint = array_pop($this->savepoints);
        $pdo->exec("RELEASE SAVEPOINT {$savepoint}");

        return true;
    }

    /**
     * Desfaz a transação ativa ou volta ao savepoint anterior.
     *
     * @return bool
     * @throws DatabaseException
     */
    public function rollBack(): bool
    {
        if ($this->transactionLevel === 0) {
            throw new DatabaseException("No active transaction to rollback");
        }

        $pdo = $this->connection;
        if ($pdo === null) {
            throw new DatabaseException("Cannot rollback: connection is not active");
        }

        $this->transactionLevel--;

        if ($this->transactionLevel === 0) {
            $this->savepoints = [];
            return $pdo->rollBack();
        }

        $savepoint = array_pop($this->savepoints);
        $pdo->exec("ROLLBACK TO SAVEPOINT {$savepoint}");

        return true;
    }

    /**
     * Retorna o nome do driver de banco de dados.
     */
    public function getDriver(): string
    {
        return $this->config->driver;
    }

    /**
     * Retorna as configurações da conexão.
     */
    public function getConfig(): DatabaseSettings
    {
        return $this->config;
    }

    /**
     * Fecha e reabre a conexão com novo modo buffered query.
     *
     * @param bool $bufferedQuery Modo buffered query para nova conexão
     * @return PDO Nova instância PDO
     * @throws DatabaseException Se a reconexão falhar
     */
    public function reconnect(bool $bufferedQuery = true): PDO
    {
        $this->bufferedQuery = $bufferedQuery;
        $this->disconnect();
        $this->connect();
        return $this->pdo();
    }

    /**
     * Fecha a conexão explicitamente e limpa o estado de transações.
     *
     * Emite warning se houver transações ativas não finalizadas.
     */
    public function disconnect(): void
    {
        if ($this->transactionLevel > 0) {
            trigger_error(
                "Disconnecting with {$this->transactionLevel} active transaction(s). State will be reset.",
                E_USER_WARNING
            );
        }

        $this->connection = null;
        $this->transactionLevel = 0;
        $this->savepoints = [];
    }
}