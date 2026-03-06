<?php

declare(strict_types=1);

namespace PHAPI\Tests\Integration\Supabase;

use PHAPI\Supabase\Exceptions\SupabaseDatabaseException;

/**
 * Integration tests for Supabase Database (PostgREST).
 *
 * Requires the 'posts' and 'categories' tables created by docker/supabase/init.sql.
 *
 * @group integration
 * @group supabase
 */
final class DatabaseIntegrationTest extends SupabaseIntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Clean up test data before each test
        $context = self::$factory->createServiceContext();
        try {
            $context->db()->from('posts')->neq('id', 0)->delete();
        } catch (\Throwable) {
            // Table might be empty
        }
        try {
            $context->db()->from('categories')->neq('id', 0)->delete();
        } catch (\Throwable) {
        }
    }

    // ─── INSERT ──────────────────────────────────────────────────────

    public function testInsertSingleRow(): void
    {
        $db = self::$factory->createServiceContext()->db();

        $result = $db->from('posts')->insert([
            'title' => 'Integration Test Post',
            'body' => 'Hello from PHAPI',
            'published' => true,
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('Integration Test Post', $result[0]['title']);
        $this->assertTrue($result[0]['published']);
    }

    public function testInsertMultipleRows(): void
    {
        $db = self::$factory->createServiceContext()->db();

        $result = $db->from('posts')->insert([
            ['title' => 'Post A', 'views' => 10],
            ['title' => 'Post B', 'views' => 20],
            ['title' => 'Post C', 'views' => 30],
        ]);

        $this->assertCount(3, $result);
        $titles = array_column($result, 'title');
        $this->assertContains('Post A', $titles);
        $this->assertContains('Post B', $titles);
        $this->assertContains('Post C', $titles);
    }

    public function testInsertReturnsSelectedColumns(): void
    {
        $db = self::$factory->createServiceContext()->db();

        $result = $db->from('posts')->select('id,title')->insert([
            'title' => 'Select Test',
        ]);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('id', $result[0]);
        $this->assertArrayHasKey('title', $result[0]);
    }

    // ─── SELECT ──────────────────────────────────────────────────────

    public function testSelectAll(): void
    {
        $db = self::$factory->createServiceContext()->db();
        $db->from('posts')->insert([
            ['title' => 'First', 'views' => 1],
            ['title' => 'Second', 'views' => 2],
        ]);

        $result = $db->from('posts')->get();

        $this->assertCount(2, $result);
    }

    public function testSelectWithColumns(): void
    {
        $db = self::$factory->createServiceContext()->db();
        $db->from('posts')->insert(['title' => 'Columns Test', 'body' => 'Body text', 'views' => 42]);

        $result = $db->from('posts')->select('title,views')->get();

        $this->assertCount(1, $result);
        $this->assertArrayHasKey('title', $result[0]);
        $this->assertArrayHasKey('views', $result[0]);
        $this->assertArrayNotHasKey('body', $result[0]);
    }

    public function testSelectSingle(): void
    {
        $db = self::$factory->createServiceContext()->db();
        $db->from('posts')->insert(['title' => 'Single Row']);

        $result = $db->from('posts')->eq('title', 'Single Row')->single()->get();

        $this->assertArrayHasKey('title', $result);
        $this->assertSame('Single Row', $result['title']);
    }

    public function testSelectMaybeSingleReturnsEmpty(): void
    {
        $db = self::$factory->createServiceContext()->db();

        $result = $db->from('posts')->eq('title', 'Nonexistent')->maybeSingle()->get();

        $this->assertSame([], $result);
    }

    // ─── FILTERS ─────────────────────────────────────────────────────

    public function testFilterEq(): void
    {
        $db = self::$factory->createServiceContext()->db();
        $db->from('posts')->insert([
            ['title' => 'Active', 'published' => true],
            ['title' => 'Draft', 'published' => false],
        ]);

        $result = $db->from('posts')->eq('published', true)->get();

        $this->assertCount(1, $result);
        $this->assertSame('Active', $result[0]['title']);
    }

    public function testFilterNeq(): void
    {
        $db = self::$factory->createServiceContext()->db();
        $db->from('posts')->insert([
            ['title' => 'Keep', 'views' => 100],
            ['title' => 'Skip', 'views' => 0],
        ]);

        $result = $db->from('posts')->neq('views', 0)->get();

        $this->assertCount(1, $result);
        $this->assertSame('Keep', $result[0]['title']);
    }

    public function testFilterGtAndLt(): void
    {
        $db = self::$factory->createServiceContext()->db();
        $db->from('posts')->insert([
            ['title' => 'Low', 'views' => 5],
            ['title' => 'Mid', 'views' => 50],
            ['title' => 'High', 'views' => 500],
        ]);

        $result = $db->from('posts')->gt('views', 10)->lt('views', 100)->get();

        $this->assertCount(1, $result);
        $this->assertSame('Mid', $result[0]['title']);
    }

    public function testFilterGteAndLte(): void
    {
        $db = self::$factory->createServiceContext()->db();
        $db->from('posts')->insert([
            ['title' => 'A', 'views' => 10],
            ['title' => 'B', 'views' => 20],
            ['title' => 'C', 'views' => 30],
        ]);

        $result = $db->from('posts')->gte('views', 10)->lte('views', 20)->get();

        $this->assertCount(2, $result);
    }

    public function testFilterLike(): void
    {
        $db = self::$factory->createServiceContext()->db();
        $db->from('posts')->insert([
            ['title' => 'PHP Tutorial'],
            ['title' => 'PHP Advanced'],
            ['title' => 'Go Basics'],
        ]);

        $result = $db->from('posts')->like('title', 'PHP%')->get();

        $this->assertCount(2, $result);
    }

    public function testFilterIlike(): void
    {
        $db = self::$factory->createServiceContext()->db();
        $db->from('posts')->insert([
            ['title' => 'php tutorial'],
            ['title' => 'PHP Advanced'],
            ['title' => 'Go Basics'],
        ]);

        $result = $db->from('posts')->ilike('title', 'php%')->get();

        $this->assertCount(2, $result);
    }

    public function testFilterIn(): void
    {
        $db = self::$factory->createServiceContext()->db();
        $inserted = $db->from('posts')->insert([
            ['title' => 'A', 'views' => 10],
            ['title' => 'B', 'views' => 20],
            ['title' => 'C', 'views' => 30],
        ]);

        $ids = array_column($inserted, 'id');
        $result = $db->from('posts')->in('id', [$ids[0], $ids[2]])->get();

        $this->assertCount(2, $result);
    }

    public function testFilterIsNull(): void
    {
        $db = self::$factory->createServiceContext()->db();
        $db->from('posts')->insert([
            ['title' => 'With Body', 'body' => 'content'],
            ['title' => 'No Body', 'body' => ''],
        ]);

        // body has DEFAULT '' so filter for empty
        $result = $db->from('posts')->eq('body', '')->get();

        $this->assertGreaterThanOrEqual(1, count($result));
    }

    public function testFilterIsNullOperator(): void
    {
        $db = self::$factory->createServiceContext()->db();
        $db->from('posts')->insert([
            ['title' => 'Has Body', 'body' => 'content'],
            ['title' => 'Null Body', 'body' => ''],
        ]);

        // Set one post's body to NULL explicitly
        $inserted = $db->from('posts')->eq('title', 'Null Body')->get();
        if ($inserted !== []) {
            $db->from('posts')->eq('id', $inserted[0]['id'])->update(['body' => null]);
        }

        $result = $db->from('posts')->is('body', null)->get();

        $this->assertGreaterThanOrEqual(1, count($result));
        foreach ($result as $row) {
            $this->assertNull($row['body']);
        }
    }

    public function testFilterContains(): void
    {
        $db = self::$factory->createServiceContext()->db();
        $db->from('posts')->insert([
            ['title' => 'Tagged PHP', 'tags' => '{php,swoole}'],
            ['title' => 'Tagged Go', 'tags' => '{go,grpc}'],
            ['title' => 'Tagged Both', 'tags' => '{php,go}'],
        ]);

        $result = $db->from('posts')->contains('tags', ['php'])->get();

        $this->assertGreaterThanOrEqual(2, count($result));
        $titles = array_column($result, 'title');
        $this->assertContains('Tagged PHP', $titles);
        $this->assertContains('Tagged Both', $titles);
    }

    public function testFilterContainedBy(): void
    {
        $db = self::$factory->createServiceContext()->db();
        $db->from('posts')->insert([
            ['title' => 'Just PHP', 'tags' => '{php}'],
            ['title' => 'PHP and Swoole', 'tags' => '{php,swoole}'],
            ['title' => 'Go Only', 'tags' => '{go}'],
        ]);

        // Tags contained by {php, swoole} — matches rows whose tags are a subset
        $result = $db->from('posts')->containedBy('tags', ['php', 'swoole'])->get();

        $titles = array_column($result, 'title');
        $this->assertContains('Just PHP', $titles);
        $this->assertContains('PHP and Swoole', $titles);
        $this->assertNotContains('Go Only', $titles);
    }

    public function testMultipleFilters(): void
    {
        $db = self::$factory->createServiceContext()->db();
        $db->from('posts')->insert([
            ['title' => 'Match', 'published' => true, 'views' => 100],
            ['title' => 'Wrong Views', 'published' => true, 'views' => 5],
            ['title' => 'Not Published', 'published' => false, 'views' => 100],
        ]);

        $result = $db->from('posts')
            ->eq('published', true)
            ->gt('views', 50)
            ->get();

        $this->assertCount(1, $result);
        $this->assertSame('Match', $result[0]['title']);
    }

    // ─── ORDERING & PAGINATION ───────────────────────────────────────

    public function testOrderAsc(): void
    {
        $db = self::$factory->createServiceContext()->db();
        $db->from('posts')->insert([
            ['title' => 'C', 'views' => 30],
            ['title' => 'A', 'views' => 10],
            ['title' => 'B', 'views' => 20],
        ]);

        $result = $db->from('posts')->order('views', 'asc')->get();

        $this->assertSame(10, $result[0]['views']);
        $this->assertSame(20, $result[1]['views']);
        $this->assertSame(30, $result[2]['views']);
    }

    public function testOrderDesc(): void
    {
        $db = self::$factory->createServiceContext()->db();
        $db->from('posts')->insert([
            ['title' => 'C', 'views' => 30],
            ['title' => 'A', 'views' => 10],
            ['title' => 'B', 'views' => 20],
        ]);

        $result = $db->from('posts')->order('views', 'desc')->get();

        $this->assertSame(30, $result[0]['views']);
    }

    public function testLimit(): void
    {
        $db = self::$factory->createServiceContext()->db();
        $db->from('posts')->insert([
            ['title' => 'A'],
            ['title' => 'B'],
            ['title' => 'C'],
        ]);

        $result = $db->from('posts')->limit(2)->get();

        $this->assertCount(2, $result);
    }

    public function testRange(): void
    {
        $db = self::$factory->createServiceContext()->db();
        $db->from('posts')->insert([
            ['title' => 'A', 'views' => 1],
            ['title' => 'B', 'views' => 2],
            ['title' => 'C', 'views' => 3],
            ['title' => 'D', 'views' => 4],
            ['title' => 'E', 'views' => 5],
        ]);

        $result = $db->from('posts')->order('views', 'asc')->range(1, 3)->get();

        $this->assertCount(3, $result);
        $this->assertSame(2, $result[0]['views']);
        $this->assertSame(4, $result[2]['views']);
    }

    // ─── UPDATE ──────────────────────────────────────────────────────

    public function testUpdateSingleRow(): void
    {
        $db = self::$factory->createServiceContext()->db();
        $inserted = $db->from('posts')->insert(['title' => 'Original', 'views' => 0]);
        $id = $inserted[0]['id'];

        $result = $db->from('posts')->eq('id', $id)->update(['title' => 'Updated', 'views' => 42]);

        $this->assertCount(1, $result);
        $this->assertSame('Updated', $result[0]['title']);
        $this->assertSame(42, $result[0]['views']);
    }

    public function testUpdateMultipleRows(): void
    {
        $db = self::$factory->createServiceContext()->db();
        $db->from('posts')->insert([
            ['title' => 'A', 'published' => false],
            ['title' => 'B', 'published' => false],
        ]);

        $result = $db->from('posts')->eq('published', false)->update(['published' => true]);

        $this->assertCount(2, $result);
        $this->assertTrue($result[0]['published']);
        $this->assertTrue($result[1]['published']);
    }

    // ─── UPSERT ──────────────────────────────────────────────────────

    public function testUpsertInsert(): void
    {
        $db = self::$factory->createServiceContext()->db();

        $result = $db->from('categories')->upsert([
            'name' => 'Unique Category',
            'description' => 'Upserted',
        ]);

        $this->assertCount(1, $result);
        $this->assertSame('Unique Category', $result[0]['name']);
    }

    // ─── DELETE ──────────────────────────────────────────────────────

    public function testDeleteByFilter(): void
    {
        $db = self::$factory->createServiceContext()->db();
        $db->from('posts')->insert([
            ['title' => 'Keep', 'published' => true],
            ['title' => 'Delete Me', 'published' => false],
        ]);

        $deleted = $db->from('posts')->eq('published', false)->delete();

        $this->assertCount(1, $deleted);
        $this->assertSame('Delete Me', $deleted[0]['title']);

        $remaining = $db->from('posts')->get();
        $this->assertCount(1, $remaining);
        $this->assertSame('Keep', $remaining[0]['title']);
    }

    // ─── RPC ─────────────────────────────────────────────────────────

    public function testRpcCall(): void
    {
        $db = self::$factory->createServiceContext()->db();

        $result = $db->rpc('hello', ['name' => 'PHAPI']);

        $this->assertSame('Hello, PHAPI!', $result['message'] ?? '');
    }

    public function testRpcCallDefaultArg(): void
    {
        $db = self::$factory->createServiceContext()->db();

        $result = $db->rpc('hello');

        $this->assertSame('Hello, world!', $result['message'] ?? '');
    }

    public function testRpcCallNonexistentFunctionFails(): void
    {
        $db = self::$factory->createServiceContext()->db();

        $this->expectException(SupabaseDatabaseException::class);
        $db->rpc('nonexistent_function');
    }

    // ─── ERROR HANDLING ──────────────────────────────────────────────

    public function testSelectFromNonexistentTable(): void
    {
        $db = self::$factory->createServiceContext()->db();

        $this->expectException(SupabaseDatabaseException::class);
        $db->from('nonexistent_table_xyz')->get();
    }

    // ─── IMMUTABILITY ────────────────────────────────────────────────

    public function testQueryBuilderImmutability(): void
    {
        $db = self::$factory->createServiceContext()->db();
        $db->from('posts')->insert([
            ['title' => 'Published', 'published' => true, 'views' => 100],
            ['title' => 'Draft', 'published' => false, 'views' => 5],
        ]);

        $base = $db->from('posts');
        $published = $base->eq('published', true);
        $highViews = $base->gt('views', 50);

        $publishedResult = $published->get();
        $highViewsResult = $highViews->get();

        $this->assertCount(1, $publishedResult);
        $this->assertSame('Published', $publishedResult[0]['title']);

        $this->assertCount(1, $highViewsResult);
        $this->assertSame('Published', $highViewsResult[0]['title']);
    }
}
