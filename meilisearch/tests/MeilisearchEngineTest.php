<?php

declare(strict_types=1);

namespace Plugins\Meilisearch\Tests;

use Kestello\Contracts\Search\SearchDocument;
use Kestello\Contracts\Search\SearchException;
use Kestello\Contracts\Search\SearchQuery;
use Kestello\Framework\Http\Client\FakeTransport;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Framework\Http\Client\PendingRequest;
use Kestello\Framework\Http\Client\ServiceClient;
use Kestello\Framework\Testing\TestCase;
use Plugins\Meilisearch\MeilisearchEngine;

require_once dirname(__DIR__) . '/src/MeilisearchEngine.php';

/**
 * The Meilisearch engine against a fake Meilisearch server: what it sends, and how it reads
 * the answers. Run from a Kestello checkout: php bin/cms test /path/to/meilisearch/tests
 */
final class MeilisearchEngineTest extends TestCase
{
    private FakeTransport $fake;
    private HttpClient $http;

    protected function setUp(): void
    {
        $this->fake = new FakeTransport();
        $this->http = new HttpClient($this->fake, static fn(string $host) => ['10.0.0.5'], static fn(string $owner) => []);
    }

    /** @return list<PendingRequest> */
    private function sent(): array
    {
        $sent = $this->fake->sent;
        $this->fake->sent = [];

        return $sent;
    }

    /** @param list<string> $tags */
    private static function doc(string $id, string $title, array $tags = ['type:post']): SearchDocument
    {
        return new SearchDocument('content', $id, $title, 'Body of ' . $title, '/' . $id, 'post', 1_700_000_000, $tags, ['excerpt' => 'x']);
    }

    public function testIndexesSearchesAndRemovesThroughTheEngineApi(): void
    {
        $base = 'http://meili:7700';
        $this->fake->on("PATCH {$base}/indexes/site/settings", ['status' => 202, 'json' => ['taskUid' => 1]]);
        $this->fake->on("POST {$base}/indexes/site/documents", ['status' => 202, 'json' => ['taskUid' => 2]]);
        $this->fake->on("POST {$base}/indexes/site/documents/delete-batch", ['status' => 202, 'json' => ['taskUid' => 3]]);
        $this->fake->on("POST {$base}/indexes/site/documents/delete", ['status' => 202, 'json' => ['taskUid' => 4]]);
        $this->fake->on("POST {$base}/multi-search", ['json' => ['results' => [
            ['hits' => [['id' => 'entry:7', 'title' => 'Harbour', 'url' => '/harbour', 'type' => 'post', 'published_at' => 1_700_000_000, 'meta' => ['excerpt' => 'e', 'bad' => ['x']], 'body' => 'b', '_rankingScore' => 0.9]], 'estimatedTotalHits' => 31],
            ['hits' => [], 'facetDistribution' => ['tags' => ['type:post' => 20, 'type:page' => 11, 'author:1' => 31]]],
        ]]]);
        $engine = new MeilisearchEngine(new ServiceClient($this->http, $base), 'k3y', 'site');

        $engine->index([self::doc('entry:7', 'Harbour'), self::doc('entry:8', 'Cove')]);
        [$settings, $docs] = $this->sent();
        $this->assertSame(['title', 'body'], json_decode($settings->body, true)['searchableAttributes']);
        $this->assertSame('Bearer k3y', $docs->headers['Authorization']);
        $this->assertStringContainsString('primaryKey=key', $docs->url);
        $rows = json_decode($docs->body, true);
        $this->assertSame(['content-3aentry-3a7', 'entry:7', ['type:post']], [$rows[0]['key'], $rows[0]['id'], $rows[0]['tags']]);
        $engine->index([self::doc('entry:9', 'Again')]);
        $this->assertSame(1, count($this->sent()), 'settings are sent once');

        $engine->remove('content', ['entry:7']);
        $this->assertSame(['content-3aentry-3a7'], json_decode($this->sent()[0]->body, true));
        $engine->flush('content');
        $this->assertSame('collection = "content"', json_decode($this->sent()[0]->body, true)['filter']);

        $results = $engine->search(new SearchQuery('content', 'harb OR "x', ['type:post', 'type:page', 'category:a"b'], 2, 10, ['type'], now: 1_800_000_000));
        $queries = json_decode($this->sent()[0]->body, true)['queries'];
        $this->assertSame('harb or x', $queries[0]['q'], 'only plain words reach the engine');
        $this->assertSame(['collection = "content"', 'published_at <= 1800000000', ['tags = "type:post"', 'tags = "type:page"'], ['tags = "category:a\"b"']], $queries[0]['filter']);
        $this->assertSame(10, $queries[0]['offset']);
        $this->assertSame(['collection = "content"', 'published_at <= 1800000000', ['tags = "category:a\"b"']], $queries[1]['filter'], 'a facet is counted without its own filter');
        $this->assertSame([31, 'entry:7', 'Harbour', ['excerpt' => 'e']], [$results->total, $results->hits[0]->id, $results->hits[0]->title, $results->hits[0]->meta]);
        $this->assertSame(['type' => ['post' => 20, 'page' => 11]], $results->facets);

        $this->fake->on("POST {$base}/multi-search", ['status' => 401, 'json' => ['message' => 'The provided API key is invalid.']]);
        $this->assertThrows(SearchException::class, fn() => $engine->search(new SearchQuery('content', 'x')));
    }
}
