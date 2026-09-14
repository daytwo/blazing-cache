<?php

namespace daytwo\blazingcache\tests;

use craft\web\Request;
use daytwo\blazingcache\BlazingCache;
use PHPUnit\Framework\TestCase;

/**
 * Test pagination cache key generation in Blazing Cache.
 *
 * New contract (see BlazingCache::buildCacheKey):
 *  - getPathInfo() is already stripped of the pagination trigger by Craft,
 *    e.g. /sailors/news-archive/p2 => "sailors/news-archive".
 *  - getPageNum() resolves the page number (default 1).
 *  - the path/query pagination triggers and 'page' are removed from the query
 *    hash, which is appended as /__qs/<md5>, and /__page/<n> is appended when
 *    page > 1.
 *
 * NOTE: buildCacheKey() reads Craft::$app->getConfig()->getGeneral(), so these
 * tests require a bootstrapped Craft application to actually execute. No
 * bootstrap or PHPUnit runner is present in this repository.
 */
class BlazingCachePaginationTest extends TestCase
{
    private BlazingCache $plugin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plugin = BlazingCache::$plugin;
    }

    /**
     * Non-paginated paths keep the bare path as the cache key.
     */
    public function testNonPaginatedPathProducesBareKey(): void
    {
        $key = $this->buildCacheKey('/archive');

        $this->assertSame('archive', $key);
        $this->assertStringNotContainsString('/__page/', $key);
    }

    /**
     * The home page resolves to the "index" key.
     */
    public function testHomePageProducesIndexKey(): void
    {
        $this->assertSame('index', $this->buildCacheKey('/'));
        $this->assertSame('index', $this->buildCacheKey(''));
        $this->assertSame('index', $this->buildCacheKey('', [], 1));
    }

    /**
     * Page 1 is treated as non-paginated even with the page trigger.
     */
    public function testPage1IsTreatedAsNonPaginated(): void
    {
        $key = $this->buildCacheKey('/archive', [], 1);

        $this->assertSame('archive', $key);
        $this->assertStringNotContainsString('/__page/', $key);
    }

    /**
     * A stripped path at page 2 appends the page segment to the bare path.
     */
    public function testPage2AppendsPageSegment(): void
    {
        $key = $this->buildCacheKey('/archive', [], 2);

        $this->assertStringContainsString('/__page/2', $key);
        $this->assertStringStartsWith('archive', $key);
    }

    /**
     * A stripped path at page 3 appends the page segment to the bare path.
     */
    public function testPage3AppendsPageSegment(): void
    {
        $key = $this->buildCacheKey('/archive', [], 3);

        $this->assertStringContainsString('/__page/3', $key);
        $this->assertStringStartsWith('archive', $key);
    }

    /**
     * Base, page 2 and page 3 all produce distinct keys.
     */
    public function testBaseAndPaginatedPathsProduceDistinctKeys(): void
    {
        $keyPage1 = $this->buildCacheKey('/archive', [], 1);
        $keyPage2 = $this->buildCacheKey('/archive', [], 2);
        $keyPage3 = $this->buildCacheKey('/archive', [], 3);

        $this->assertNotSame($keyPage1, $keyPage2);
        $this->assertNotSame($keyPage2, $keyPage3);
        $this->assertNotSame($keyPage1, $keyPage3);

        $this->assertStringNotContainsString('/__page/', $keyPage1);
        $this->assertStringContainsString('/__page/2', $keyPage2);
        $this->assertStringContainsString('/__page/3', $keyPage3);
    }

    /**
     * Pagination combined with a query parameter keeps both segments, and the
     * page segment comes after the query hash.
     */
    public function testPaginationWithQueryParamKeepsBothSegmentsInOrder(): void
    {
        $key = $this->buildCacheKey('/archive', ['search' => 'orc'], 2);

        $this->assertStringContainsString('/__qs/', $key);
        $this->assertStringContainsString('/__page/2', $key);

        $this->assertLessThan(
            strpos($key, '/__page/'),
            strpos($key, '/__qs/'),
            'The query hash must be placed before the page segment.'
        );

        // Exactly one page segment.
        $this->assertCount(2, explode('/__page/', $key));
    }

    /**
     * The 'page' query parameter is stripped from the query hash, so it does
     * not affect the generated key.
     */
    public function testPageQueryParamExcludedFromQueryHash(): void
    {
        $withPageParam = $this->buildCacheKey('/archive', ['page' => '2', 'sort' => 'date'], 2);
        $withoutPageParam = $this->buildCacheKey('/archive', ['sort' => 'date'], 2);

        $this->assertSame($withoutPageParam, $withPageParam);
        $this->assertStringContainsString('archive/__qs/', $withPageParam);
        $this->assertStringContainsString('/__page/2', $withPageParam);
    }

    /**
     * Query parameter order does not affect the resulting key.
     */
    public function testQueryHashIsOrderIndependent(): void
    {
        $keyA = $this->buildCacheKey('/archive', ['a' => '1', 'b' => '2'], 1);
        $keyB = $this->buildCacheKey('/archive', ['b' => '2', 'a' => '1'], 1);

        $this->assertSame($keyA, $keyB);
    }

    /**
     * The reference table from the specification.
     *
     * | URL                                | pathInfo                 | page | key
     * |------------------------------------|--------------------------|------|-----------------------------------------------
     * | /sailors/news-archive              | sailors/news-archive     | 1    | sailors/news-archive
     * | /sailors/news-archive/p2           | sailors/news-archive     | 2    | sailors/news-archive/__page/2
     * | /sailors/news-archive/p2?search=orc| sailors/news-archive     | 2    | sailors/news-archive/__qs/<md5>/__page/2
     * | /                                  | ""                       | 1    | index
     * | /?search=orc                       | ""                       | 1    | index/__qs/<md5>
     */
    public function testSailorsNewsArchiveTable(): void
    {
        $searchHash = md5(http_build_query(['search' => 'orc']));

        $this->assertSame(
            'sailors/news-archive',
            $this->buildCacheKey('/sailors/news-archive', [], 1)
        );

        $this->assertSame(
            'sailors/news-archive/__page/2',
            $this->buildCacheKey('/sailors/news-archive', [], 2)
        );

        $this->assertSame(
            'sailors/news-archive/__qs/' . $searchHash . '/__page/2',
            $this->buildCacheKey('/sailors/news-archive', ['search' => 'orc'], 2)
        );

        $this->assertSame(
            'index',
            $this->buildCacheKey('/', [], 1)
        );

        $this->assertSame(
            'index/__qs/' . $searchHash,
            $this->buildCacheKey('/', ['search' => 'orc'], 1)
        );
    }

    /**
     * Nested paths with pagination preserve the full stripped path.
     */
    public function testNestedPathWithPagination(): void
    {
        $key = $this->buildCacheKey('/blog/category/tech', [], 3);

        $this->assertStringStartsWith('blog/category/tech', $key);
        $this->assertStringContainsString('/__page/3', $key);
    }

    /**
     * Higher page numbers are preserved and remain distinct.
     */
    public function testHighPageNumbers(): void
    {
        $keyPage10 = $this->buildCacheKey('/archive', [], 10);
        $keyPage100 = $this->buildCacheKey('/archive', [], 100);

        $this->assertStringContainsString('/__page/10', $keyPage10);
        $this->assertStringContainsString('/__page/100', $keyPage100);
        $this->assertNotSame($keyPage10, $keyPage100);
    }

    /**
     * Invoke the private buildCacheKey method via reflection.
     */
    private function buildCacheKey(string $pathInfo, array $queryParams = [], int $pageNum = 1): string
    {
        $reflection = new \ReflectionClass($this->plugin);
        $method = $reflection->getMethod('buildCacheKey');
        $method->setAccessible(true);

        return $method->invoke($this->plugin, $this->createMockRequest($pathInfo, $queryParams, $pageNum));
    }

    /**
     * Create a mock Request reflecting the new contract: pathInfo is already
     * stripped of the pagination trigger and getPageNum() resolves the page.
     */
    private function createMockRequest(string $pathInfo, array $queryParams = [], int $pageNum = 1): Request
    {
        $mockRequest = $this->createMock(Request::class);

        $mockRequest->method('getPathInfo')
            ->willReturn($pathInfo);

        $mockRequest->method('getPageNum')
            ->willReturn($pageNum);

        $mockRequest->method('getQueryParams')
            ->willReturn($queryParams);

        return $mockRequest;
    }
}
