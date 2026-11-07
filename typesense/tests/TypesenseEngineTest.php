<?php

declare(strict_types=1);

namespace Plugins\Typesense\Tests;

use Kestello\Contracts\Search\SearchDocument;
use Kestello\Contracts\Search\SearchException;
use Kestello\Contracts\Search\SearchQuery;
use Kestello\Framework\Http\Client\FakeTransport;
use Kestello\Framework\Http\Client\HttpClient;
use Kestello\Framework\Http\Client\PendingRequest;
use Kestello\Framework\Http\Client\ServiceClient;
use Kestello\Framework\Testing\TestCase;
use Plugins\Typesense\TypesenseEngine;

require_once dirname(__DIR__) . '/src/TypesenseEngine.php';

/**
 * The Typesense engine against a fake Typesense server: what it sends, and how it reads
 * the answers. Run from a Kestello checkout: php bin/cms test /path/to/typesense/tests
 */
final class TypesenseEngineTest extends TestCase
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
        $base = 'http://ts:8108';
        $this->fake->on("GET {$base}/collections/site", ['status' => 404, 'json' => ['message' => 'Not Found']]);
        $this->fake->on("POST {$base}/collections", ['status' => 201, 'json' => ['name' => 'site']]);
        $this->fake->on("POST {$base}/collections/site/documents/import", ['body' => "{\"success\":true}\n{\"success\":true}"]);
        $this->fake->on("DELETE {$base}/collections/site/documents", ['json' => ['num_deleted' => 1]]);
        $this->fake->on("POST {$base}/multi_search", ['json' => ['results' => [
            ['found' => 3, 'hits' => [['document' => ['doc_id' => 'entry:7', 'title' => 'Harbour', 'url' => '/harbour', 'type' => 'post', 'published_at' => 5, 'meta' => '{"image":"/i.webp"}', 'body' => 'b'], 'text_match' => 99]]],
            ['found' => 3, 'hits' => [], 'facet_counts' => [['field_name' => 'tags', 'counts' => [['value' => 'type:post', 'count' => 2], ['value' => 'type:page', 'count' => 1], ['value' => 'author:1', 'count' => 3]]]]],
        ]]]);
        $engine = new TypesenseEngine(new ServiceClient($this->http, $base), 'k3y', 'site');

        $engine->index([self::doc('entry:7', 'Harbour'), self::doc('entry:8', 'Cove')]);
        [, $create, $import] = $this->sent();
        $this->assertContains(['name' => 'tags', 'type' => 'string[]', 'facet' => true], json_decode($create->body, true)['fields']);
        $this->assertSame('k3y', $import->headers['X-TYPESENSE-API-KEY']);
        $this->assertStringContainsString('action=upsert', $import->url);
        $this->assertSame('content-3aentry-3a7', json_decode(explode("\n", $import->body)[0], true)['id']);

        $this->fake->on("POST {$base}/collections/site/documents/import", ['body' => "{\"success\":true}\n{\"success\":false,\"error\":\"Field `published_at` must be an int64.\"}"]);
        $this->assertThrows(SearchException::class, fn() => $engine->index([self::doc('a', 'A'), self::doc('b', 'B')]), 'a refused document is an error even with HTTP 200');
        $this->sent();

        $engine->remove('content', ['entry:7', 'entry:8']);
        $this->assertStringContainsString('filter_by=' . rawurlencode('id:[content-3aentry-3a7,content-3aentry-3a8]'), $this->sent()[0]->url);

        $results = $engine->search(new SearchQuery('content', '', ['type:post', 'category:a`b'], 1, 10, ['type'], now: 9));
        $searches = json_decode($this->sent()[0]->body, true)['searches'];
        $this->assertSame('*', $searches[0]['q']);
        $this->assertSame('collection:=`content` && published_at:<=9 && tags:=[`type:post`] && tags:=[`category:ab`]', $searches[0]['filter_by'], 'backticks cannot break out of a value');
        $this->assertSame('collection:=`content` && published_at:<=9 && tags:=[`category:ab`]', $searches[1]['filter_by']);
        $this->assertSame([3, 'entry:7', ['image' => '/i.webp'], 99.0], [$results->total, $results->hits[0]->id, $results->hits[0]->meta, $results->hits[0]->score]);
        $this->assertSame(['type' => ['post' => 2, 'page' => 1]], $results->facets);
    }
}
