<?php

declare(strict_types=1);

namespace Omegaalfa\QueryBuilder\connection;

use Omegaalfa\QueryBuilder\DatabaseSettings;
use Omegaalfa\QueryBuilder\exceptions\DatabaseException;
use Omegaalfa\QueryBuilder\interfaces\ConnectionInterface;
use PDO;
use Pdo\Mysql;
use PDOException;
use PDOStatement;
use Throwable;

final class PDOConnection implements ConnectionInterface
{
    private const int MAX_RECONNECT_ATTEMPTS = 3;
    private const int RECONNECT_DELAY_MS = 100;
    private const array DRIVER_CAPABILITIES = [
        'mysql' => [
            'savepoints' => true,
            'timezone' => true,
            'strict_mode' => true,
        ],
        'pgsql' => [
            'savepoints' => true,
            'timezone' => true,
            'search_path' => true,
        ],
        'sqlite' => [
            'savepoints' => false,
            'foreign_keys' => true,
            'wal' => true,
        ],
    ];
    private ?PDO $connection = null;
    private int $transactionLevel = 0;
    private array $savepoints = [];
    private bool $bufferedQuery = true;

    // Mapa de capabilities por driver
    private int $reconnectAttempts = 0;

    public function __construct(protected DatabaseSettings $dbSettings)
    {
    }

    /**
     * Retorna o número total de tentativas de reconexão desde o início
     *
     * @return int
     */
    public function getReconnectAttempts(): int
    {
        return $this->reconnectAttempts;
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
     * Garante que a conexão está viva antes de iniciar a transação.
     *
     * @param callable $callback Função que recebe a instância PDO
     * @return mixed O retorno do callback
     * @throws DatabaseException Se a transação falhar
     */
    public function transaction(callable $callback): mixed
    {
        $this->ensureConnection();
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
     * Garante que a conexão está viva, reconectando se necessário
     *
     * @return void
     * @throws DatabaseException Se não conseguir reconectar
     */
    public function ensureConnection(): void
    {
        if (!$this->isAlive()) {
            $this->handleDeadConnection();
        }
    }

    /**
     * Verifica se a conexão está realmente funcional (não apenas instanciada)
     *
     * Executa uma query leve para testar se a conexão está viva.
     * Útil para detectar timeouts em long-running processes.
     *
     * @return bool True se a conexão está ativa e funcional
     */
    public function isAlive(): bool
    {
        if ($this->connection === null) {
            return false;
        }

        try {
            // Query leve que funciona em todos os drivers
            $stmt = $this->connection->query('SELECT 1');
            return $stmt !== false;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Trata conexão morta com retry automático
     *
     * @throws DatabaseException
     */
    private function handleDeadConnection(): void
    {
        $this->disconnect();

        for ($attempt = 1; $attempt <= self::MAX_RECONNECT_ATTEMPTS; $attempt++) {
            try {
                $this->connect();
                $this->reconnectAttempts = 0; // Reset no sucesso
                return;
            } catch (DatabaseException $e) {
                $this->reconnectAttempts++; // ✅ Incrementa a cada falha

                if ($attempt === self::MAX_RECONNECT_ATTEMPTS) {
                    throw new DatabaseException(
                        "Failed to reconnect after $attempt attempts (total failures: $this->reconnectAttempts): {$e->getMessage()}",
                        0,
                        $e
                    );
                }

                // Backoff exponencial
                usleep(self::RECONNECT_DELAY_MS * 1000 * $attempt);
            }
        }
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
                "Disconnecting with $this->transactionLevel active transaction(s). State will be reset.",
                E_USER_WARNING
            );
        }

        $this->connection = null;
        $this->transactionLevel = 0;
        $this->savepoints = [];
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

        $dsn = $this->dbSettings->toDsn();
        $options = $this->buildConnectionOptions();

        try {
            $this->connection = new PDO(
                $dsn,
                $this->dbSettings->username,
                $this->dbSettings->password,
                $options
            );

            // Forçar modo exception para todos os erros
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // dbSettingsurações recomendadas para produção
            $this->connection->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $this->connection->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

            // Timeout de statement (30 segundos)
            if ($this->dbSettings->driver === 'mysql') {
                $this->connection->setAttribute(PDO::ATTR_TIMEOUT, 30);
            }

            $this->applyPostConnectionSettings();

        } catch (PDOException $e) {
            $this->connection = null;
            throw new DatabaseException(
                message: "Database connection failed [{$this->dbSettings->driver}@{$this->dbSettings->host}]: {$e->getMessage()}",
                code: (int)$e->getCode(),
                previousException: $e
            );
        } catch (Throwable $e) {
            $this->connection = null;
            throw new DatabaseException(
                message: "Unexpected error during connection: {$e->getMessage()}",
                code: (int)$e->getCode(),
                previousException: $e
            );
        }
    }

    /**
     * Constrói as opções de conexão específicas por driver.
     *
     * @return array Opções PDO dbSettingsuradas
     */
    private function buildConnectionOptions(): array
    {
        $options = $this->dbSettings->options;

        // dbSettingsurações específicas do MySQL
        if ($this->dbSettings->driver === 'mysql') {
            $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
            $options[PDO::ATTR_EMULATE_PREPARES] = false;

            // Suporte ao novo driver ext-pdo_mysql
            if (class_exists(Mysql::class)) {
                $options[Mysql::ATTR_USE_BUFFERED_QUERY] = $this->bufferedQuery;
            }

            // FOUND_ROWS para UPDATE statements
            $options[Pdo\Mysql::ATTR_FOUND_ROWS] = true;

            // Timeout de conexão (5 segundos)
            $options[PDO::ATTR_TIMEOUT] = 5;
        }

        // dbSettingsurações específicas do PostgreSQL
        if ($this->dbSettings->driver === 'pgsql') {
            $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
            $options[PDO::ATTR_EMULATE_PREPARES] = false;
        }

        // SQLite
        if ($this->dbSettings->driver === 'sqlite') {
            $options[PDO::ATTR_ERRMODE] = PDO::ERRMODE_EXCEPTION;
            $options[PDO::ATTR_TIMEOUT] = 5;
        }

        return $options;
    }

    /**
     * Aplica dbSettingsurações pós-conexão específicas por driver.
     *
     * @return void
     * @throws DatabaseException
     */
    private function applyPostConnectionSettings(): void
    {
        match ($this->dbSettings->driver) {
            'mysql' => $this->applyMySQLSettings(),
            'pgsql' => $this->applyPostgreSQLSettings(),
            'sqlite' => $this->applySQLiteSettings(),
            default => null
        };
    }

    /**
     * Aplica dbSettingsurações específicas do MySQL.
     *
     * @return void
     * @throws DatabaseException
     */
    private function applyMySQLSettings(): void
    {
        if (empty($this->dbSettings->collation)) {
            return;
        }

        $charset = $this->dbSettings->charset;
        $collation = $this->dbSettings->collation;

        // Validação rigorosa: permite alfanuméricos, underscores e hífens
        if (!preg_match('/^[a-z0-9_-]+$/i', $charset) ||
            !preg_match('/^[a-z0-9_-]+$/i', $collation)) {
            throw new DatabaseException('Invalid charset or collation format');
        }

        // Validar que collation corresponde ao charset
        if (!str_starts_with($collation, $charset . '_')) {
            throw new DatabaseException(
                "Collation '$collation' does not match charset '$charset'"
            );
        }

        try {
            $this->connection->exec("SET NAMES '$charset' COLLATE '$collation'");

            // dbSettingsurações adicionais recomendadas para MySQL (usando capabilities)
            if ($this->hasCapability('strict_mode')) {
                $this->connection->exec("SET sql_mode='STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE'");
            }

            if ($this->hasCapability('timezone')) {
                $this->connection->exec("SET time_zone='+00:00'"); // UTC
            }

        } catch (PDOException $e) {
            throw new DatabaseException(
                "Failed to set MySQL charset/collation: {$e->getMessage()}",
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Verifica se o driver suporta uma capability específica
     *
     * @param string $capability
     * @return bool
     */
    private function hasCapability(string $capability): bool
    {
        return self::DRIVER_CAPABILITIES[$this->dbSettings->driver][$capability] ?? false;
    }

    /**
     * Aplica dbSettingsurações específicas do PostgreSQL.
     *
     * @return void
     * @throws DatabaseException
     */
    private function applyPostgreSQLSettings(): void
    {
        if (empty($this->dbSettings->charset)) {
            return;
        }

        $charset = strtoupper($this->dbSettings->charset);

        // Whitelist de encodings PostgreSQL comuns
        $validEncodings = [
            'UTF8', 'LATIN1', 'LATIN2', 'LATIN9', 'SQL_ASCII',
            'WIN1252', 'WIN1251', 'ISO_8859_5', 'ISO_8859_6',
            'ISO_8859_7', 'ISO_8859_8', 'EUC_JP', 'EUC_CN', 'EUC_KR'
        ];

        if (!in_array($charset, $validEncodings, true)) {
            // Apenas aceita formato válido para encodings não padrão
            if (!preg_match('/^[A-Z0-9_]+$/', $charset)) {
                throw new DatabaseException("Invalid PostgreSQL encoding format: $charset");
            }

            trigger_error("Using non-standard PostgreSQL encoding: $charset");
        }

        try {
            $this->connection->exec("SET client_encoding TO '$charset'");

            // dbSettingsurações recomendadas para PostgreSQL (usando capabilities)
            if ($this->hasCapability('timezone')) {
                $this->connection->exec("SET timezone TO 'UTC'");
            }

            if ($this->hasCapability('search_path')) {
                $this->connection->exec("SET search_path TO public");
            }

        } catch (PDOException $e) {
            throw new DatabaseException(
                "Failed to set PostgreSQL encoding '$charset': {$e->getMessage()}",
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Aplica dbSettingsurações específicas do SQLite.
     *
     * @return void
     * @throws DatabaseException
     */
    private function applySQLiteSettings(): void
    {
        try {
            // CRÍTICO: Habilita foreign key constraints (usando capability check)
            if ($this->hasCapability('foreign_keys')) {
                $this->connection->exec('PRAGMA foreign_keys = ON');

                // Verificar se foi realmente habilitado
                $enabled = $this->connection->query('PRAGMA foreign_keys')->fetchColumn();
                if ($enabled !== 1) {
                    trigger_error(
                        'SQLite foreign keys could not be enabled',
                        E_USER_WARNING
                    );
                }
            }

            // Write-Ahead Logging para melhor concorrência (usando capability check)
            if ($this->hasCapability('wal')) {
                $this->connection->exec('PRAGMA journal_mode = WAL');
            }

            // Sincronização normal (balance entre segurança e performance)
            $this->connection->exec('PRAGMA synchronous = NORMAL');

            // Cache de 2MB
            $this->connection->exec('PRAGMA cache_size = -2000');

            // Timeout de busy (5 segundos)
            $this->connection->exec('PRAGMA busy_timeout = 5000');

        } catch (PDOException $e) {
            throw new DatabaseException(
                "Failed to dbSettingsure SQLite: {$e->getMessage()}",
                (int)$e->getCode(),
                $e
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
            try {
                $result = $pdo->beginTransaction();
                if ($result) {
                    $this->transactionLevel++;
                }
                return $result;
            } catch (PDOException $e) {
                throw new DatabaseException(
                    "Failed to begin transaction: {$e->getMessage()}",
                    (int)$e->getCode(),
                    $e
                );
            }
        }

        if (!$this->supportsSavepoints()) {
            throw new DatabaseException(
                "Nested transactions (savepoints) not supported by {$this->dbSettings->driver} driver"
            );
        }

        $savepoint = 'SAVEPOINT_' . $this->transactionLevel;

        try {
            $pdo->exec("SAVEPOINT $savepoint");
            $this->savepoints[] = $savepoint;
            $this->transactionLevel++;
        } catch (PDOException $e) {
            throw new DatabaseException(
                "Failed to create savepoint: {$e->getMessage()}",
                (int)$e->getCode(),
                $e
            );
        }

        return true;
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
     * Verifica se o driver atual suporta savepoints (transações aninhadas).
     *
     * ✅ Usa capability map para melhor extensibilidade
     *
     * @return bool True se suporta savepoints
     */
    private function supportsSavepoints(): bool
    {
        return self::DRIVER_CAPABILITIES[$this->dbSettings->driver]['savepoints'] ?? false;
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

        try {
            if ($this->transactionLevel === 0) {
                return $pdo->commit();
            }

            $savepoint = array_pop($this->savepoints);
            $pdo->exec("RELEASE SAVEPOINT $savepoint");

        } catch (PDOException $e) {
            throw new DatabaseException(
                "Failed to commit transaction: {$e->getMessage()}",
                (int)$e->getCode(),
                $e
            );
        }

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

        try {
            if ($this->transactionLevel === 0) {
                $this->savepoints = [];
                return $pdo->rollBack();
            }

            $savepoint = array_pop($this->savepoints);
            $pdo->exec("ROLLBACK TO SAVEPOINT $savepoint");

        } catch (PDOException $e) {
            throw new DatabaseException(
                "Failed to rollback transaction: {$e->getMessage()}",
                (int)$e->getCode(),
                $e
            );
        }

        return true;
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
     * Retorna o nome do driver de banco de dados.
     */
    public function getDriver(): string
    {
        return $this->dbSettings->driver;
    }

    /**
     * Retorna as configurações da conexão.
     */
    public function getDbSettings(): DatabaseSettings
    {
        return $this->dbSettings;
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
     * Retorna estatísticas da conexão para monitoramento
     *
     * @return array{driver: string, host: string, database: string, connected: bool, alive: bool, transaction_level: int, reconnect_attempts: int}
     */
    public function getStats(): array
    {
        return [
            'driver' => $this->dbSettings->driver,
            'host' => $this->dbSettings->host,
            'database' => $this->dbSettings->database,
            'connected' => $this->isConnected(),
            'alive' => $this->isAlive(),
            'transaction_level' => $this->transactionLevel,
            'reconnect_attempts' => $this->reconnectAttempts,
        ];
    }

    /**
     * Verifica se há uma conexão PDO ativa.
     *
     * Não garante que a conexão está funcional, apenas que foi instanciada.
     * Para verificar se está realmente funcional, use isAlive()
     */
    public function isConnected(): bool
    {
        return $this->connection !== null;
    }

    /**
     * Executa uma query raw com proteção contra conexão morta
     *
     * ✅ Retorno tipado como PDOStatement para consistência
     *
     * @param string $query
     * @param array $params
     * @return PDOStatement
     * @throws DatabaseException
     */
    public function execute(string $query, array $params = []): PDOStatement
    {
        $this->ensureConnection();

        try {
            $stmt = $this->connection->prepare($query);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            throw new DatabaseException(
                "Query execution failed: {$e->getMessage()}",
                (int)$e->getCode(),
                $e
            );
        }
    }

    /**
     * Cleanup ao destruir o objeto
     *
     * ✅ Tenta rollback de transações pendentes
     * Nota: Pode não executar dependendo da ordem de shutdown do PHP
     */
    public function __destruct()
    {
        if ($this->transactionLevel > 0) {
            trigger_error(
                "PDOConnection destroyed with $this->transactionLevel active transaction(s). Attempting rollback.",
                E_USER_WARNING
            );

            try {
                while ($this->transactionLevel > 0) {
                    $this->rollBack();
                }
            } catch (Throwable $e) {
                // Silenciar erros no destrutor, mas logar se possível
                error_log("Failed to rollback transaction in destructor: {$e->getMessage()}");
            }
        }
    }
}