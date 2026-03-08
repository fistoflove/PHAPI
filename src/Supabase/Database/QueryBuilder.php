<?php

declare(strict_types=1);

namespace PHAPI\Supabase\Database;

use PHAPI\Supabase\Exceptions\SupabaseDatabaseException;
use PHAPI\Supabase\SupabaseConfig;
use PHAPI\Supabase\SupabaseTransport;

/**
 * Fluent PostgREST query builder.
 *
 * @api
 */
final class QueryBuilder
{
    private const REST_PREFIX = '/rest/v1/';

    private string $selectColumns = '*';
    /** @var array<int, string> */
    private array $filters = [];
    /** @var array<int, string> */
    private array $orders = [];
    private ?int $limitValue = null;
    private ?int $offsetValue = null;
    private bool $single = false;
    private bool $maybeSingle = false;
    private bool $count = false;
    private string $countMode = 'exact';
    private bool $csv = false;

    public function __construct(
        private readonly SupabaseTransport $transport,
        private readonly SupabaseConfig $config,
        private readonly string $table,
        private readonly ?string $accessToken = null,
    ) {
    }

    // ─── Select ──────────────────────────────────────────────────────

    public function select(string $columns = '*'): self
    {
        $clone = clone $this;
        $clone->selectColumns = preg_replace('/\s*,\s*/', ',', trim($columns)) ?? $columns;
        return $clone;
    }

    public function single(): self
    {
        $clone = clone $this;
        $clone->single = true;
        $clone->limitValue = 1;
        return $clone;
    }

    public function maybeSingle(): self
    {
        $clone = clone $this;
        $clone->maybeSingle = true;
        $clone->limitValue = 1;
        return $clone;
    }

    /**
     * Request a row count from PostgREST.
     *
     * The count is returned in the Content-Range response header.
     *
     * @param string $mode  'exact', 'planned', or 'estimated'
     */
    public function count(string $mode = 'exact'): self
    {
        $clone = clone $this;
        $clone->count = true;
        $clone->countMode = $mode;
        return $clone;
    }

    /**
     * Return results as CSV format.
     */
    public function csv(): self
    {
        $clone = clone $this;
        $clone->csv = true;
        return $clone;
    }

    // ─── Ordering & Pagination ───────────────────────────────────────

    public function order(string $column, string $direction = 'asc'): self
    {
        $clone = clone $this;
        $clone->orders[] = $column . '.' . $direction;
        return $clone;
    }

    public function limit(int $count): self
    {
        $clone = clone $this;
        $clone->limitValue = $count;
        return $clone;
    }

    public function range(int $from, int $to): self
    {
        $clone = clone $this;
        $clone->offsetValue = $from;
        $clone->limitValue = $to - $from + 1;
        return $clone;
    }

    // ─── Filters ─────────────────────────────────────────────────────

    public function eq(string $column, mixed $value): self
    {
        return $this->addFilter($column, 'eq', $value);
    }

    public function neq(string $column, mixed $value): self
    {
        return $this->addFilter($column, 'neq', $value);
    }

    public function gt(string $column, mixed $value): self
    {
        return $this->addFilter($column, 'gt', $value);
    }

    public function gte(string $column, mixed $value): self
    {
        return $this->addFilter($column, 'gte', $value);
    }

    public function lt(string $column, mixed $value): self
    {
        return $this->addFilter($column, 'lt', $value);
    }

    public function lte(string $column, mixed $value): self
    {
        return $this->addFilter($column, 'lte', $value);
    }

    public function like(string $column, string $pattern): self
    {
        return $this->addFilter($column, 'like', $pattern);
    }

    public function ilike(string $column, string $pattern): self
    {
        return $this->addFilter($column, 'ilike', $pattern);
    }

    public function is(string $column, mixed $value): self
    {
        return $this->addFilter($column, 'is', $value);
    }

    /**
     * @param array<int, mixed> $values
     */
    public function in(string $column, array $values): self
    {
        $encoded = '(' . implode(',', array_map(fn ($v): string => $this->encodeValue($v), $values)) . ')';
        $clone = clone $this;
        $clone->filters[] = rawurlencode($column) . '=in.' . $encoded;
        return $clone;
    }

    /**
     * @param array<int, mixed> $values
     */
    public function contains(string $column, array $values): self
    {
        $encoded = '{' . implode(',', array_map(fn ($v): string => $this->encodeValue($v), $values)) . '}';
        $clone = clone $this;
        $clone->filters[] = rawurlencode($column) . '=cs.' . $encoded;
        return $clone;
    }

    /**
     * @param array<int, mixed> $values
     */
    public function containedBy(string $column, array $values): self
    {
        $encoded = '{' . implode(',', array_map(fn ($v): string => $this->encodeValue($v), $values)) . '}';
        $clone = clone $this;
        $clone->filters[] = rawurlencode($column) . '=cd.' . $encoded;
        return $clone;
    }

    /**
     * Negate a filter.
     *
     * Usage: ->not('status', 'eq', 'active') produces status=not.eq.active
     */
    public function not(string $column, string $operator, mixed $value): self
    {
        $clone = clone $this;
        $clone->filters[] = rawurlencode($column) . '=not.' . $operator . '.' . $this->encodeValue($value);
        return $clone;
    }

    /**
     * Combine filters with OR logic.
     *
     * Usage: ->or('status.eq.active,status.eq.pending')
     */
    public function or(string $filters): self
    {
        $clone = clone $this;
        $clone->filters[] = 'or=(' . $filters . ')';
        return $clone;
    }

    /**
     * Full-text search using PostgreSQL tsvector.
     *
     * @param array{config?: string, type?: string} $options  type: 'plain'|'phrase'|'websearch'
     */
    public function textSearch(string $column, string $query, array $options = []): self
    {
        $type = match ($options['type'] ?? 'plain') {
            'phrase' => 'phfts',
            'websearch' => 'wfts',
            default => 'plfts',
        };

        $config = isset($options['config']) ? '(' . $options['config'] . ')' : '';

        $clone = clone $this;
        $clone->filters[] = rawurlencode($column) . '=' . $type . $config . '.' . rawurlencode($query);
        return $clone;
    }

    /**
     * Match multiple column values simultaneously.
     *
     * @param array<string, mixed> $criteria
     */
    public function match(array $criteria): self
    {
        $clone = clone $this;
        foreach ($criteria as $column => $value) {
            $clone->filters[] = rawurlencode($column) . '=eq.' . $this->encodeValue($value);
        }
        return $clone;
    }

    /**
     * Apply a raw PostgREST filter.
     *
     * Usage: ->filter('id', 'in', '(1,2,3)')
     */
    public function filter(string $column, string $operator, string $value): self
    {
        $clone = clone $this;
        $clone->filters[] = rawurlencode($column) . '=' . $operator . '.' . $value;
        return $clone;
    }

    /**
     * Range greater than (exclusive).
     */
    public function rangeGt(string $column, string $range): self
    {
        return $this->addFilter($column, 'sr', $range);
    }

    /**
     * Range greater than or equal (inclusive start).
     */
    public function rangeGte(string $column, string $range): self
    {
        return $this->addFilter($column, 'nxl', $range);
    }

    /**
     * Range less than (exclusive).
     */
    public function rangeLt(string $column, string $range): self
    {
        return $this->addFilter($column, 'sl', $range);
    }

    /**
     * Range less than or equal (inclusive end).
     */
    public function rangeLte(string $column, string $range): self
    {
        return $this->addFilter($column, 'nxr', $range);
    }

    /**
     * Adjacent range filter.
     */
    public function rangeAdjacent(string $column, string $range): self
    {
        return $this->addFilter($column, 'adj', $range);
    }

    /**
     * Overlaps filter for ranges/arrays.
     *
     * @param array<int, mixed> $values
     */
    public function overlaps(string $column, array $values): self
    {
        $encoded = '{' . implode(',', array_map(fn ($v): string => $this->encodeValue($v), $values)) . '}';
        $clone = clone $this;
        $clone->filters[] = rawurlencode($column) . '=ov.' . $encoded;
        return $clone;
    }

    // ─── Terminal: SELECT ────────────────────────────────────────────

    /**
     * Execute the query and return results.
     *
     * @return array<int|string, mixed>
     */
    public function get(): array
    {
        $path = $this->buildPath();
        $headers = $this->config->headers($this->accessToken);

        if ($this->csv) {
            $headers['Accept'] = 'text/csv';
        } elseif ($this->single || $this->maybeSingle) {
            $headers['Accept'] = 'application/vnd.pgrst.object+json';
        }

        if ($this->count) {
            $headers['Prefer'] = 'count=' . $this->countMode;
        }

        $response = $this->transport->request('GET', $path, null, $headers);
        return $this->handleResponse($response);
    }

    // ─── Terminal: INSERT ────────────────────────────────────────────

    /**
     * @param array<string, mixed>|array<int, array<string, mixed>> $data
     * @return array<int|string, mixed>
     */
    public function insert(array $data): array
    {
        $path = self::REST_PREFIX . rawurlencode($this->table)
            . '?select=' . $this->selectColumns;
        $headers = $this->config->headers($this->accessToken);
        $headers['Prefer'] = 'return=representation';

        $response = $this->transport->request('POST', $path, $data, $headers);
        return $this->handleResponse($response);
    }

    // ─── Terminal: UPDATE ────────────────────────────────────────────

    /**
     * @param array<string, mixed> $data
     * @return array<int|string, mixed>
     */
    public function update(array $data): array
    {
        $path = $this->buildPath();
        $headers = $this->config->headers($this->accessToken);
        $headers['Prefer'] = 'return=representation';

        $response = $this->transport->request('PATCH', $path, $data, $headers);
        return $this->handleResponse($response);
    }

    // ─── Terminal: UPSERT ────────────────────────────────────────────

    /**
     * @param array<string, mixed>|array<int, array<string, mixed>> $data
     * @return array<int|string, mixed>
     */
    public function upsert(array $data): array
    {
        $path = self::REST_PREFIX . rawurlencode($this->table)
            . '?select=' . $this->selectColumns;
        $headers = $this->config->headers($this->accessToken);
        $headers['Prefer'] = 'return=representation,resolution=merge-duplicates';

        $response = $this->transport->request('POST', $path, $data, $headers);
        return $this->handleResponse($response);
    }

    // ─── Terminal: DELETE ────────────────────────────────────────────

    /**
     * @return array<int|string, mixed>
     */
    public function delete(): array
    {
        $path = $this->buildPath();
        $headers = $this->config->headers($this->accessToken);
        $headers['Prefer'] = 'return=representation';

        $response = $this->transport->request('DELETE', $path, null, $headers);
        return $this->handleResponse($response);
    }

    // ─── Internals ───────────────────────────────────────────────────

    private function buildPath(): string
    {
        $parts = [
            'select=' . $this->selectColumns,
        ];

        $parts = array_merge($parts, $this->filters);

        if ($this->orders !== []) {
            $parts[] = 'order=' . implode(',', $this->orders);
        }

        if ($this->limitValue !== null) {
            $parts[] = 'limit=' . $this->limitValue;
        }

        if ($this->offsetValue !== null) {
            $parts[] = 'offset=' . $this->offsetValue;
        }

        return self::REST_PREFIX . rawurlencode($this->table) . '?' . implode('&', $parts);
    }

    private function addFilter(string $column, string $operator, mixed $value): self
    {
        $clone = clone $this;
        $clone->filters[] = rawurlencode($column) . '=' . $operator . '.' . $this->encodeValue($value);
        return $clone;
    }

    private function encodeValue(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return rawurlencode((string) $value);
    }

    /**
     * @param array{data: mixed, status: int, body: string} $response
     * @return array<int|string, mixed>
     */
    private function handleResponse(array $response): array
    {
        $status = $response['status'];

        if ($this->maybeSingle && ($status === 406 || $response['data'] === null)) {
            return [];
        }

        if ($status >= 400) {
            throw SupabaseDatabaseException::fromResponse($response, 'Database query failed');
        }

        $data = $response['data'];

        if ($this->single && !is_array($data)) {
            throw new SupabaseDatabaseException('Expected single row but got none', 404);
        }

        return is_array($data) ? $data : [];
    }
}
