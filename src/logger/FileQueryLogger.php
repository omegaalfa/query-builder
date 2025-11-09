<?php


declare(strict_types=1);


namespace Omegaalfa\QueryBuilder\logger;

use JsonException;
use Omegaalfa\QueryBuilder\interfaces\QueryLoggerInterface;
use RuntimeException;
use Throwable;

class FileQueryLogger implements QueryLoggerInterface
{
    /**
     * @param string $logPath
     * @param bool $enabled
     */
    public function __construct(
        private readonly string $logPath,
        private readonly bool   $enabled = true
    )
    {
        if ($enabled && !is_writable(dirname($logPath))) {
            throw new RuntimeException("Log directory not writable: " . dirname($logPath));
        }
    }

    /**
     * @param string $sql
     * @param array $params
     * @param float $duration
     * @param int $rowCount
     * @return void
     * @throws JsonException
     */
    public function logQuery(string $sql, array $params, float $duration, int $rowCount): void
    {
        if (!$this->enabled) {
            return;
        }

        $entry = sprintf(
            "[%s] [%.4fs] [%d rows] %s | Params: %s\n",
            date('Y-m-d H:i:s'),
            $duration,
            $rowCount,
            $sql,
            json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)
        );

        file_put_contents($this->logPath, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * @param string $sql
     * @param array $params
     * @param Throwable $error
     * @return void
     * @throws JsonException
     */
    public function logError(string $sql, array $params, Throwable $error): void
    {
        if (!$this->enabled) {
            return;
        }

        $entry = sprintf(
            "[%s] [ERROR] %s\nSQL: %s\nParams: %s\nTrace: %s\n\n",
            date('Y-m-d H:i:s'),
            $error->getMessage(),
            $sql,
            json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            $error->getTraceAsString()
        );

        file_put_contents($this->logPath, $entry, FILE_APPEND | LOCK_EX);
    }
}