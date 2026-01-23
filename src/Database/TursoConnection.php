<?php

declare(strict_types=1);

namespace PHAPI\Database;

use Swoole\Coroutine\Http\Client;

final class TursoConnection implements DatabaseConnectionInterface
{
    private string $host;
    private int $port;
    private bool $ssl;
    private string $token;

    public function __construct(string $url, string $token)
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            throw new \InvalidArgumentException('Invalid Turso URL.');
        }

        $this->host = $parts['host'];
        $this->ssl = ($parts['scheme'] ?? 'https') === 'https';
        $this->port = $parts['port'] ?? ($this->ssl ? 443 : 80);
        $this->token = $token;
    }

    public function exec(string $sql): int
    {
        $this->execute($sql, []);
        return 0;
    }

    public function query(string $sql): DatabaseStatementInterface
    {
        $statement = new TursoStatement($this, $sql);
        $statement->execute();
        return $statement;
    }

    public function prepare(string $sql): DatabaseStatementInterface
    {
        return new TursoStatement($this, $sql);
    }

    /**
     * @param string $sql
     * @param array<int|string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function execute(string $sql, array $params): array
    {
        $client = new Client($this->host, $this->port, $this->ssl);
        $client->set([
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'Content-Type' => 'application/json',
            ],
        ]);

        $payload = json_encode([
            'statements' => [
                ['q' => $sql, 'params' => $params],
            ],
        ]);

        if ($payload === false) {
            throw new \RuntimeException('Failed to encode Turso request payload.');
        }

        $client->post('/v1/execute', $payload);

        $status = $client->statusCode ?? 0;
        $body = $client->body ?? '';
        $client->close();

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException('Turso request failed with status ' . $status);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $this->extractRows($decoded);
    }

    /**
     * @param array<string, mixed>|array<int, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    private function extractRows(array $payload): array
    {
        $results = $payload['results'] ?? $payload;
        if (is_array($results) && isset($results[0]) && is_array($results[0])) {
            $entry = $results[0];
        } elseif (is_array($results)) {
            $entry = $results;
        } else {
            return [];
        }

        $rows = $entry['results']['rows'] ?? $entry['response']['result']['rows'] ?? $entry['result']['rows'] ?? [];
        $columns = $entry['results']['columns'] ?? $entry['response']['result']['cols'] ?? $entry['result']['cols'] ?? null;

        if (!is_array($rows)) {
            return [];
        }

        if (is_array($columns) && $columns !== []) {
            $mapped = [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $combined = [];
                foreach ($columns as $index => $column) {
                    $combined[$column] = $row[$index] ?? null;
                }
                $mapped[] = $combined;
            }
            return $mapped;
        }

        return array_map(function ($row) {
            return is_array($row) ? $row : [];
        }, $rows);
    }
}
