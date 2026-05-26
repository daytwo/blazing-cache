<?php

namespace daytwo\blazingcache\tests;

use Craft;
use craft\web\Request;
use daytwo\blazingcache\BlazingCache;
use PHPUnit\Framework\TestCase;

/**
 * Test pagination cache key generation in Blazing Cache.
 * Ensures that paginated URLs produce different cache keys to prevent cache collisions.
 */
class BlazingCachePaginationTest extends TestCase
{
    private BlazingCache $plugin;
    private Request $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->plugin = BlazingCache::$plugin;
    }

    /**
     * Test that non-paginated URLs maintain backward compatibility
     */
    public function testNonPaginatedUrlKeepsExistingFormat(): void
    {
        $key = $this->buildCacheKeyWithPathAndQuery('/archive', []);
        $this->assertStringNotContainsString('/__page/', $key);
        $this->assertEquals('archive', $key);
    }

    /**
     * Test that home page (index) maintains backward compatibility
     */
    public function testHomePageKeepsExistingFormat(): void
    {
        $key = $this->buildCacheKeyWithPathAndQuery('/', []);
        $this->assertStringNotContainsString('/__page/', $key);
        $this->assertEquals('index', $key);
    }

    /**
     * Test that page 1 is treated as non-paginated
     */
    public function testPage1UsesNonPaginatedFormat(): void
    {
        $key = $this->buildCacheKeyWithPathAndQuery('/archive/p1', []);
        $this->assertStringNotContainsString('/__page/', $key);
        $this->assertEquals('archive', $key);
    }

    /**
     * Test that page 2 and higher include page segment in cache key
     */
    public function testPage2IncludesPageSegment(): void
    {
        $key = $this->buildCacheKeyWithPathAndQuery('/archive/p2', []);
        $this->assertStringContainsString('/__page/2', $key);
        $this->assertStringStartsWith('archive', $key);
    }

    /**
     * Test that different page numbers produce different cache keys
     */
    public function testDifferentPageNumbersProduceDifferentKeys(): void
    {
        $keyPage1 = $this->buildCacheKeyWithPathAndQuery('/archive', []);
        $keyPage2 = $this->buildCacheKeyWithPathAndQuery('/archive/p2', []);
        $keyPage3 = $this->buildCacheKeyWithPathAndQuery('/archive/p3', []);

        $this->assertNotEquals($keyPage1, $keyPage2);
        $this->assertNotEquals($keyPage2, $keyPage3);
        $this->assertNotEquals($keyPage1, $keyPage3);

        $this->assertStringNotContainsString('/__page/', $keyPage1);
        $this->assertStringContainsString('/__page/2', $keyPage2);
        $this->assertStringContainsString('/__page/3', $keyPage3);
    }

    /**
     * Test pagination with query parameters
     */
    public function testPaginationWithQueryParamsPage2(): void
    {
        $key = $this->buildCacheKeyWithPathAndQuery('/archive', ['page' => '2']);
        $this->assertStringContainsString('/__page/2', $key);
    }

    /**
     * Test pagination with query parameters page 1
     */
    public function testPaginationWithQueryParamsPage1(): void
    {
        $key = $this->buildCacheKeyWithPathAndQuery('/archive', ['page' => '1']);
        $this->assertStringNotContainsString('/__page/', $key);
    }

    /**
     * Test that page param is removed from query string hash
     */
    public function testPageParamRemovedFromQueryStringHash(): void
    {
        // Both should produce the same base cache key for pagination
        $keyWithPageParam = $this->buildCacheKeyWithPathAndQuery('/archive', ['page' => '2', 'sort' => 'date']);
        $keyWithoutPageParam = $this->buildCacheKeyWithPathAndQuery('/archive/p2', ['sort' => 'date']);

        // Extract the base path and page segment (without query hash)
        $basePart1 = explode('/__qs/', $keyWithPageParam)[0];
        $basePart2 = explode('/__qs/', $keyWithoutPageParam)[0];

        $this->assertEquals($basePart1, $basePart2);
    }

    /**
     * Test paginated archive with additional query params
     */
    public function testPaginatedUrlWithQueryParams(): void
    {
        $key = $this->buildCacheKeyWithPathAndQuery('/sailors/news-archive/p2', ['sort' => 'date']);
        
        // Should include page segment
        $this->assertStringContainsString('/__page/2', $key);
        
        // Should still include query string hash for the 'sort' param
        $this->assertStringContainsString('/__qs/', $key);
        
        // Page segment should come after query hash
        $parts = explode('/__page/', $key);
        $this->assertCount(2, $parts);
    }

    /**
     * Test that archive and archive/p2 produce different keys
     */
    public function testArchiveVsArchivePage2(): void
    {
        $keyArchive = $this->buildCacheKeyWithPathAndQuery('/sailors/news-archive', []);
        $keyArchivePage2 = $this->buildCacheKeyWithPathAndQuery('/sailors/news-archive/p2', []);

        $this->assertNotEquals($keyArchive, $keyArchivePage2);
        $this->assertStringNotContainsString('/__page/', $keyArchive);
        $this->assertStringContainsString('/__page/2', $keyArchivePage2);
    }

    /**
     * Test nested paths with pagination
     */
    public function testNestedPathsWithPagination(): void
    {
        $key = $this->buildCacheKeyWithPathAndQuery('/blog/category/tech/p3', []);
        
        $this->assertStringContainsString('/__page/3', $key);
        $this->assertStringStartsWith('blog/category/tech', $key);
    }

    /**
     * Test that high page numbers are handled correctly
     */
    public function testHighPageNumbers(): void
    {
        $keyPage10 = $this->buildCacheKeyWithPathAndQuery('/archive/p10', []);
        $keyPage100 = $this->buildCacheKeyWithPathAndQuery('/archive/p100', []);

        $this->assertStringContainsString('/__page/10', $keyPage10);
        $this->assertStringContainsString('/__page/100', $keyPage100);
        $this->assertNotEquals($keyPage10, $keyPage100);
    }

    /**
     * Helper method to build cache key with mocked request
     * Note: This accesses the private buildCacheKey method via reflection
     */
    private function buildCacheKeyWithPathAndQuery(string $path, array $queryParams): string
    {
        // Use reflection to access the private buildCacheKey method
        $reflection = new \ReflectionClass($this->plugin);
        $method = $reflection->getMethod('buildCacheKey');
        $method->setAccessible(true);

        // Create a mock request with the specified path and query params
        $mockRequest = $this->createMockRequest($path, $queryParams);

        return $method->invoke($this->plugin, $mockRequest);
    }

    /**
     * Create a mock Request object with specified path and query params
     */
    private function createMockRequest(string $path, array $queryParams): Request
    {
        $mockRequest = $this->createMock(Request::class);

        $mockRequest->method('getPathInfo')
            ->willReturn($path);

        $mockRequest->method('getQueryParams')
            ->willReturn($queryParams);

        $mockRequest->method('getQueryParam')
            ->willReturnCallback(function ($key) use ($queryParams) {
                return $queryParams[$key] ?? null;
            });

        return $mockRequest;
    }
}
