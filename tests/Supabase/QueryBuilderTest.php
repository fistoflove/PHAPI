<?php

declare(strict_types=1);

namespace PHAPI\Tests\Supabase;

use PHAPI\Supabase\Database\QueryBuilder;
use PHAPI\Supabase\Exceptions\SupabaseDatabaseException;
use PHAPI\Supabase\SupabaseConfig;
use PHPUnit\Framework\TestCase;

final class QueryBuilderTest extends TestCase
{
    private FakeTransport $transport;
    private SupabaseConfig $config;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport();
        $this->config = new SupabaseConfig([
            'url' => 'https://test.supabase.co',
            'anon_key' => 'anon-key',
        ]);
    }

    private function builder(string $table = 'posts'): QueryBuilder
    {
        return new QueryBuilder($this->transport, $this->config, $table, 'user-token');
    }

    // ─── SELECT ──────────────────────────────────────────────────────

    public function testSelectAll(): void
    {
        $this->transport->addResponse([
            'data' => [['id' => 1, 'title' => 'Hello']],
            'status' => 200,
            'body' => '[]',
        ]);

        $result = $this->builder()->get();

        $this->assertCount(1, $result);
        $this->assertSame('GET', $this->transport->lastRequest()['method']);
        $this->assertStringContainsString('select=*', $this->transport->lastRequest()['path']);
    }

    public function testSelectSpecificColumns(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);

        $this->builder()->select('id, title')->get();

        $this->assertStringContainsString('select=id, title', $this->transport->lastRequest()['path']);
    }

    public function testSelectWithFilters(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);

        $this->builder()
            ->select('*')
            ->eq('published', true)
            ->gt('views', 100)
            ->get();

        $path = $this->transport->lastRequest()['path'];
        $this->assertStringContainsString('published=eq.true', $path);
        $this->assertStringContainsString('views=gt.100', $path);
    }

    public function testSelectWithOrder(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);

        $this->builder()->order('created_at', 'desc')->get();

        $this->assertStringContainsString('order=created_at.desc', $this->transport->lastRequest()['path']);
    }

    public function testSelectWithLimit(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);

        $this->builder()->limit(10)->get();

        $this->assertStringContainsString('limit=10', $this->transport->lastRequest()['path']);
    }

    public function testSelectWithRange(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);

        $this->builder()->range(10, 19)->get();

        $path = $this->transport->lastRequest()['path'];
        $this->assertStringContainsString('limit=10', $path);
        $this->assertStringContainsString('offset=10', $path);
    }

    public function testSingleSetsAcceptHeader(): void
    {
        $this->transport->addResponse(['data' => ['id' => 1], 'status' => 200, 'body' => '{}']);

        $this->builder()->single()->get();

        $headers = $this->transport->lastRequest()['headers'];
        $this->assertSame('application/vnd.pgrst.object+json', $headers['Accept']);
    }

    // ─── FILTERS ─────────────────────────────────────────────────────

    public function testFilterEq(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);
        $this->builder()->eq('status', 'active')->get();
        $this->assertStringContainsString('status=eq.active', $this->transport->lastRequest()['path']);
    }

    public function testFilterNeq(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);
        $this->builder()->neq('status', 'deleted')->get();
        $this->assertStringContainsString('status=neq.deleted', $this->transport->lastRequest()['path']);
    }

    public function testFilterGt(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);
        $this->builder()->gt('age', 18)->get();
        $this->assertStringContainsString('age=gt.18', $this->transport->lastRequest()['path']);
    }

    public function testFilterGte(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);
        $this->builder()->gte('age', 18)->get();
        $this->assertStringContainsString('age=gte.18', $this->transport->lastRequest()['path']);
    }

    public function testFilterLt(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);
        $this->builder()->lt('price', 50)->get();
        $this->assertStringContainsString('price=lt.50', $this->transport->lastRequest()['path']);
    }

    public function testFilterLte(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);
        $this->builder()->lte('price', 50)->get();
        $this->assertStringContainsString('price=lte.50', $this->transport->lastRequest()['path']);
    }

    public function testFilterLike(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);
        $this->builder()->like('name', '%test%')->get();
        $this->assertStringContainsString('name=like.%25test%25', $this->transport->lastRequest()['path']);
    }

    public function testFilterIlike(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);
        $this->builder()->ilike('name', '%test%')->get();
        $this->assertStringContainsString('name=ilike.%25test%25', $this->transport->lastRequest()['path']);
    }

    public function testFilterIs(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);
        $this->builder()->is('deleted_at', null)->get();
        $this->assertStringContainsString('deleted_at=is.null', $this->transport->lastRequest()['path']);
    }

    public function testFilterIn(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);
        $this->builder()->in('id', [1, 2, 3])->get();
        $this->assertStringContainsString('id=in.(1,2,3)', $this->transport->lastRequest()['path']);
    }

    public function testFilterContains(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);
        $this->builder()->contains('tags', ['php', 'swoole'])->get();
        $this->assertStringContainsString('tags=cs.{php,swoole}', $this->transport->lastRequest()['path']);
    }

    public function testFilterContainedBy(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);
        $this->builder()->containedBy('tags', ['php', 'swoole', 'go'])->get();
        $this->assertStringContainsString('tags=cd.{php,swoole,go}', $this->transport->lastRequest()['path']);
    }

    public function testFilterBoolean(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);
        $this->builder()->eq('active', false)->get();
        $this->assertStringContainsString('active=eq.false', $this->transport->lastRequest()['path']);
    }

    // ─── ADVANCED FILTERS ────────────────────────────────────────────

    public function testFilterNot(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);
        $this->builder()->not('status', 'eq', 'deleted')->get();
        $this->assertStringContainsString('status=not.eq.deleted', $this->transport->lastRequest()['path']);
    }

    public function testFilterOr(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);
        $this->builder()->or('status.eq.active,status.eq.pending')->get();
        $this->assertStringContainsString('or=(status.eq.active,status.eq.pending)', $this->transport->lastRequest()['path']);
    }

    public function testTextSearchPlain(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);
        $this->builder()->textSearch('body', 'php swoole')->get();
        $this->assertStringContainsString('body=plfts.php%20swoole', $this->transport->lastRequest()['path']);
    }

    public function testTextSearchPhrase(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);
        $this->builder()->textSearch('body', 'the cat', ['type' => 'phrase'])->get();
        $this->assertStringContainsString('body=phfts.the%20cat', $this->transport->lastRequest()['path']);
    }

    public function testTextSearchWebsearch(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);
        $this->builder()->textSearch('body', 'cat OR dog', ['type' => 'websearch'])->get();
        $this->assertStringContainsString('body=wfts.', $this->transport->lastRequest()['path']);
    }

    public function testTextSearchWithConfig(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);
        $this->builder()->textSearch('body', 'gato', ['config' => 'spanish'])->get();
        $this->assertStringContainsString('body=plfts(spanish).gato', $this->transport->lastRequest()['path']);
    }

    public function testMatch(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);
        $this->builder()->match(['status' => 'active', 'published' => true])->get();
        $path = $this->transport->lastRequest()['path'];
        $this->assertStringContainsString('status=eq.active', $path);
        $this->assertStringContainsString('published=eq.true', $path);
    }

    public function testCustomFilter(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);
        $this->builder()->filter('id', 'in', '(1,2,3)')->get();
        $this->assertStringContainsString('id=in.(1,2,3)', $this->transport->lastRequest()['path']);
    }

    public function testRangeGt(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);
        $this->builder()->rangeGt('during', '[2023-01-01,2023-12-31]')->get();
        $this->assertStringContainsString('during=sr.', $this->transport->lastRequest()['path']);
    }

    public function testOverlaps(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);
        $this->builder()->overlaps('tags', ['php', 'go'])->get();
        $this->assertStringContainsString('tags=ov.{php,go}', $this->transport->lastRequest()['path']);
    }

    // ─── MODIFIERS ──────────────────────────────────────────────────

    public function testCountSetsPreferHeader(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);
        $this->builder()->count()->get();
        $this->assertSame('count=exact', $this->transport->lastRequest()['headers']['Prefer']);
    }

    public function testCountPlannedMode(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);
        $this->builder()->count('planned')->get();
        $this->assertSame('count=planned', $this->transport->lastRequest()['headers']['Prefer']);
    }

    public function testCsvSetsAcceptHeader(): void
    {
        $this->transport->addResponse(['data' => null, 'status' => 200, 'body' => 'id,title']);
        $this->builder()->csv()->get();
        $this->assertSame('text/csv', $this->transport->lastRequest()['headers']['Accept']);
    }

    // ─── INSERT ──────────────────────────────────────────────────────

    public function testInsert(): void
    {
        $this->transport->addResponse([
            'data' => [['id' => 1, 'title' => 'New Post']],
            'status' => 201,
            'body' => '[]',
        ]);

        $result = $this->builder()->insert(['title' => 'New Post']);

        $this->assertSame('POST', $this->transport->lastRequest()['method']);
        $this->assertSame('New Post', $this->transport->lastRequest()['body']['title']);
        $this->assertStringContainsString('return=representation', $this->transport->lastRequest()['headers']['Prefer']);
    }

    // ─── UPDATE ──────────────────────────────────────────────────────

    public function testUpdate(): void
    {
        $this->transport->addResponse([
            'data' => [['id' => 1, 'title' => 'Updated']],
            'status' => 200,
            'body' => '[]',
        ]);

        $result = $this->builder()->eq('id', 1)->update(['title' => 'Updated']);

        $this->assertSame('PATCH', $this->transport->lastRequest()['method']);
        $this->assertSame('Updated', $this->transport->lastRequest()['body']['title']);
        $this->assertStringContainsString('id=eq.1', $this->transport->lastRequest()['path']);
    }

    // ─── UPSERT ──────────────────────────────────────────────────────

    public function testUpsert(): void
    {
        $this->transport->addResponse([
            'data' => [['id' => 1, 'title' => 'Upserted']],
            'status' => 200,
            'body' => '[]',
        ]);

        $result = $this->builder()->upsert(['id' => 1, 'title' => 'Upserted']);

        $this->assertSame('POST', $this->transport->lastRequest()['method']);
        $this->assertStringContainsString('resolution=merge-duplicates', $this->transport->lastRequest()['headers']['Prefer']);
    }

    // ─── DELETE ──────────────────────────────────────────────────────

    public function testDelete(): void
    {
        $this->transport->addResponse([
            'data' => [['id' => 1]],
            'status' => 200,
            'body' => '[]',
        ]);

        $result = $this->builder()->eq('id', 1)->delete();

        $this->assertSame('DELETE', $this->transport->lastRequest()['method']);
        $this->assertStringContainsString('id=eq.1', $this->transport->lastRequest()['path']);
    }

    // ─── ERROR HANDLING ──────────────────────────────────────────────

    public function testGetThrowsOnError(): void
    {
        $this->transport->addResponse([
            'data' => ['message' => 'relation "posts" does not exist'],
            'status' => 404,
            'body' => '{}',
        ]);

        $this->expectException(SupabaseDatabaseException::class);
        $this->builder()->get();
    }

    public function testInsertThrowsOnError(): void
    {
        $this->transport->addResponse([
            'data' => ['message' => 'duplicate key'],
            'status' => 409,
            'body' => '{}',
        ]);

        $this->expectException(SupabaseDatabaseException::class);
        $this->builder()->insert(['title' => 'Duplicate']);
    }

    // ─── IMMUTABILITY ────────────────────────────────────────────────

    public function testImmutability(): void
    {
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);
        $this->transport->addResponse(['data' => [], 'status' => 200, 'body' => '[]']);

        $base = $this->builder();
        $filtered = $base->eq('active', true);
        $limited = $base->limit(5);

        $filtered->get();
        $path1 = $this->transport->requests[0]['path'];

        $limited->get();
        $path2 = $this->transport->requests[1]['path'];

        $this->assertStringContainsString('active=eq.true', $path1);
        $this->assertStringNotContainsString('limit=5', $path1);

        $this->assertStringNotContainsString('active=eq.true', $path2);
        $this->assertStringContainsString('limit=5', $path2);
    }
}
