<?php

declare(strict_types=1);

namespace Plugins\Meilisearch;

use Kestello\Contracts\Search\Engine;
use Kestello\Contracts\Search\SearchException;
use Kestello\Contracts\Search\SearchHit;
use Kestello\Contracts\Search\SearchQuery;
use Kestello\Contracts\Search\SearchResults;
use Kestello\Framework\Http\Client\HttpResponse;
use Kestello\Framework\Http\Client\ServiceClient;
use Throwable;

/**
 * One Meilisearch index holds every collection; documents carry their
 * collection, schedule and tags as filterable attributes. Writes are
 * asynchronous on the server (tasks), so a change shows up a moment later.
 */
final class MeilisearchEngine implements Engine
{
    private bool $configured = false;

    public function __construct(
        private readonly ServiceClient $client,
        private readonly string $key,
        private readonly string $index,
        private readonly float $timeout = 5.0,
    ) {}

    public function name(): string
    {
        return 'meilisearch';
    }

    public function index(array $documents): void
    {
        if ($documents === []) {
            return;
        }
        $this->configure();
        $rows = [];
        foreach ($documents as $doc) {
            $doc = $doc->trimmed();
            $rows[] = [
                'key' => self::key($doc->collection, $doc->id),
                'collection' => $doc->collection, 'id' => $doc->id, 'title' => $doc->title, 'body' => $doc->body,
                'url' => $doc->url, 'type' => $doc->type, 'locale' => $doc->locale, 'tags' => array_values($doc->tags),
                'meta' => (object) $doc->meta, 'published_at' => $doc->publishedAt ?? 0,
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            $this->call('POST', "/indexes/{$this->index}/documents", ['json' => $chunk, 'query' => ['primaryKey' => 'key']]);
        }
    }

    public function remove(string $collection, array $ids): void
    {
        if ($ids !== []) {
            $this->call('POST', "/indexes/{$this->index}/documents/delete-batch", ['json' => array_map(static fn($id) => self::key($collection, (string) $id), array_values($ids))]);
        }
    }

    public function flush(string $collection): void
    {
        $this->configure();
        $this->call('POST', "/indexes/{$this->index}/documents/delete", ['json' => ['filter' => 'collection = ' . self::quote($collection)]]);
    }

    public function search(SearchQuery $query): SearchResults
    {
        $base = ['collection = ' . self::quote($query->collection), 'published_at <= ' . $query->now()];
        if ($query->locale !== '') {
            $base[] = 'locale = ' . self::quote($query->locale);
        }
        $groups = $query->tagGroups();
        $filter = static function (?string $skip) use ($base, $groups): array {
            $all = $base;
            foreach ($groups as $name => $values) {
                if ($name !== $skip) {
                    $all[] = array_map(static fn($v) => 'tags = ' . self::quote($name . ':' . $v), $values);   // inner list: OR
                }
            }

            return $all;
        };

        $text = implode(' ', $query->terms());
        // the page itself, then one count per facet without that facet's own filter (so other choices keep their counts)
        $queries = [['indexUid' => $this->index, 'q' => $text, 'filter' => $filter(null), 'offset' => $query->offset(), 'limit' => $query->perPage,
            'attributesToRetrieve' => ['id', 'title', 'url', 'type', 'published_at', 'meta', 'body'], 'showRankingScore' => true]];
        foreach ($query->facets as $facet) {
            $queries[] = ['indexUid' => $this->index, 'q' => $text, 'filter' => $filter($facet), 'limit' => 0, 'facets' => ['tags']];
        }
        $responses = $this->call('POST', '/multi-search', ['json' => ['queries' => $queries]])['results'] ?? [];

        $page = $responses[0] ?? [];
        $hits = [];
        foreach ((array) ($page['hits'] ?? []) as $hit) {
            $hits[] = new SearchHit(
                id: (string) ($hit['id'] ?? ''),
                title: (string) ($hit['title'] ?? ''),
                url: isset($hit['url']) ? (string) $hit['url'] : null,
                type: (string) ($hit['type'] ?? ''),
                publishedAt: !empty($hit['published_at']) ? (int) $hit['published_at'] : null,
                meta: array_filter((array) ($hit['meta'] ?? []), 'is_scalar'),
                body: (string) ($hit['body'] ?? ''),
                score: (float) ($hit['_rankingScore'] ?? 0),
            );
        }
        $facets = [];
        foreach ($query->facets as $i => $facet) {
            foreach ((array) ($responses[$i + 1]['facetDistribution']['tags'] ?? []) as $tag => $count) {
                if (str_starts_with((string) $tag, $facet . ':')) {
                    $facets[$facet][substr((string) $tag, strlen($facet) + 1)] = (int) $count;
                }
            }
            $facets[$facet] ??= [];
            arsort($facets[$facet]);
        }

        return new SearchResults($hits, (int) ($page['estimatedTotalHits'] ?? $page['totalHits'] ?? count($hits)), $query->page, $query->perPage, $facets, 'meilisearch');
    }

    public function ping(): void
    {
        $health = $this->call('GET', '/health');
        if (($health['status'] ?? '') !== 'available') {
            throw new SearchException('Meilisearch is not available.');
        }
        $this->call('GET', '/version');   // the health route is public; this one checks the key
    }

    /** Searchable, filterable and ranking settings; creates the index on first use. */
    private function configure(): void
    {
        if ($this->configured) {
            return;
        }
        $this->call('PATCH', "/indexes/{$this->index}/settings", ['json' => [
            'searchableAttributes' => ['title', 'body'],
            'filterableAttributes' => ['collection', 'tags', 'published_at', 'locale'],
            'sortableAttributes' => ['published_at'],
            'displayedAttributes' => ['id', 'collection', 'title', 'url', 'type', 'published_at', 'meta', 'body'],
        ]]);
        $this->configured = true;
    }

    /**
     * @param array<string, mixed> $options
     * @return array<mixed>
     */
    private function call(string $method, string $path, array $options = []): array
    {
        if ($this->key !== '') {
            $options['bearer'] = $this->key;
        }
        $options['timeout'] = $this->timeout;
        try {
            $response = $this->client->request($method, $path, $options);
        } catch (Throwable $e) {
            throw new SearchException('Meilisearch could not be reached: ' . $e->getMessage(), previous: $e);
        }
        if (!$response->ok()) {
            throw new SearchException('Meilisearch answered ' . $response->status . self::reason($response));
        }
        $data = $response->body === '' ? [] : $response->json();

        return is_array($data) ? $data : [];
    }

    private static function reason(HttpResponse $response): string
    {
        try {
            $message = $response->json()['message'] ?? null;
        } catch (Throwable) {
            $message = null;
        }

        return is_string($message) ? ': ' . mb_substr($message, 0, 200) : '.';
    }

    /** Meilisearch ids allow [A-Za-z0-9_-]: other bytes (and "-") are written as -xx. */
    public static function key(string $collection, string $id): string
    {
        return preg_replace_callback('/[^A-Za-z0-9_]/', static fn($m) => '-' . bin2hex($m[0]), $collection . ':' . $id) ?? '';
    }

    private static function quote(string $value): string
    {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
