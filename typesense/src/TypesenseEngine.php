<?php

declare(strict_types=1);

namespace Plugins\Typesense;

use Kestello\Contracts\Search\Engine;
use Kestello\Contracts\Search\SearchException;
use Kestello\Contracts\Search\SearchHit;
use Kestello\Contracts\Search\SearchQuery;
use Kestello\Contracts\Search\SearchResults;
use Kestello\Framework\Http\Client\HttpResponse;
use Kestello\Framework\Http\Client\ServiceClient;
use Throwable;

/**
 * One Typesense collection holds every Kestello collection (named "collection" in
 * the document, to keep the two apart). Created with its schema on first write.
 */
final class TypesenseEngine implements Engine
{
    private bool $ready = false;

    public function __construct(
        private readonly ServiceClient $client,
        private readonly string $key,
        private readonly string $collection,
        private readonly float $timeout = 5.0,
    ) {}

    public function name(): string
    {
        return 'typesense';
    }

    public function index(array $documents): void
    {
        if ($documents === []) {
            return;
        }
        $this->ensure();
        $lines = [];
        foreach ($documents as $doc) {
            $doc = $doc->trimmed();
            $lines[] = json_encode([
                'id' => self::key($doc->collection, $doc->id),
                'doc_id' => $doc->id, 'collection' => $doc->collection, 'title' => $doc->title, 'body' => $doc->body,
                'url' => (string) $doc->url, 'type' => $doc->type, 'locale' => $doc->locale, 'tags' => array_values($doc->tags),
                'meta' => json_encode((object) $doc->meta, JSON_THROW_ON_ERROR), 'published_at' => $doc->publishedAt ?? 0,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        foreach (array_chunk($lines, 500) as $chunk) {
            $response = $this->send('POST', "/collections/{$this->collection}/documents/import", [
                'query' => ['action' => 'upsert'], 'body' => implode("\n", $chunk), 'headers' => ['Content-Type' => 'text/plain'],
            ]);
            foreach (explode("\n", trim($response->body)) as $line) {     // one result per document; 200 even when some fail
                $result = json_decode($line, true);
                if (is_array($result) && ($result['success'] ?? true) === false) {
                    throw new SearchException('Typesense refused a document: ' . mb_substr((string) ($result['error'] ?? 'unknown error'), 0, 200));
                }
            }
        }
    }

    public function remove(string $collection, array $ids): void
    {
        foreach (array_chunk(array_values($ids), 100) as $chunk) {
            $keys = array_map(static fn($id) => self::key($collection, (string) $id), $chunk);   // [A-Za-z0-9_-] only: safe in a filter
            $this->call('DELETE', "/collections/{$this->collection}/documents", ['query' => ['filter_by' => 'id:[' . implode(',', $keys) . ']']], missingOk: true);
        }
    }

    public function flush(string $collection): void
    {
        $this->call('DELETE', "/collections/{$this->collection}/documents", ['query' => ['filter_by' => 'collection:=' . self::quote($collection)]], missingOk: true);
    }

    public function search(SearchQuery $query): SearchResults
    {
        $base = ['collection:=' . self::quote($query->collection), 'published_at:<=' . $query->now()];
        if ($query->locale !== '') {
            $base[] = 'locale:=' . self::quote($query->locale);
        }
        $groups = $query->tagGroups();
        $filter = static function (?string $skip) use ($base, $groups): string {
            $all = $base;
            foreach ($groups as $name => $values) {
                if ($name !== $skip) {
                    $all[] = 'tags:=[' . implode(',', array_map(static fn($v) => self::quote($name . ':' . $v), $values)) . ']';
                }
            }

            return implode(' && ', $all);
        };

        $terms = $query->terms();
        $common = ['collection' => $this->collection, 'q' => $terms === [] ? '*' : implode(' ', $terms), 'query_by' => 'title,body', 'query_by_weights' => '3,1', 'prefix' => 'true'];
        $searches = [$common + ['filter_by' => $filter(null), 'page' => $query->page, 'per_page' => $query->perPage,
            'include_fields' => 'doc_id,title,url,type,published_at,meta,body']];
        foreach ($query->facets as $facet) {
            $searches[] = $common + ['filter_by' => $filter($facet), 'per_page' => 0, 'facet_by' => 'tags', 'max_facet_values' => 250];
        }
        $results = $this->call('POST', '/multi_search', ['json' => ['searches' => $searches]])['results'] ?? [];
        foreach ($results as $result) {
            if (isset($result['error'])) {
                throw new SearchException('Typesense search failed: ' . mb_substr((string) $result['error'], 0, 200));
            }
        }

        $page = $results[0] ?? [];
        $hits = [];
        foreach ((array) ($page['hits'] ?? []) as $hit) {
            $d = (array) ($hit['document'] ?? []);
            $meta = json_decode((string) ($d['meta'] ?? '{}'), true);
            $hits[] = new SearchHit(
                id: (string) ($d['doc_id'] ?? ''),
                title: (string) ($d['title'] ?? ''),
                url: ($d['url'] ?? '') !== '' ? (string) $d['url'] : null,
                type: (string) ($d['type'] ?? ''),
                publishedAt: !empty($d['published_at']) ? (int) $d['published_at'] : null,
                meta: is_array($meta) ? array_filter($meta, 'is_scalar') : [],
                body: (string) ($d['body'] ?? ''),
                score: (float) ($hit['text_match'] ?? 0),
            );
        }
        $facets = [];
        foreach ($query->facets as $i => $facet) {
            foreach ((array) ($results[$i + 1]['facet_counts'] ?? []) as $field) {
                foreach ((array) ($field['counts'] ?? []) as $c) {
                    if (str_starts_with((string) ($c['value'] ?? ''), $facet . ':')) {
                        $facets[$facet][substr((string) $c['value'], strlen($facet) + 1)] = (int) $c['count'];
                    }
                }
            }
            $facets[$facet] ??= [];
            arsort($facets[$facet]);
        }

        return new SearchResults($hits, (int) ($page['found'] ?? count($hits)), $query->page, $query->perPage, $facets, 'typesense');
    }

    public function ping(): void
    {
        if (($this->call('GET', '/health')['ok'] ?? false) !== true) {
            throw new SearchException('Typesense is not healthy.');
        }
        $this->call('GET', "/collections/{$this->collection}", missingOk: true);   // checks the key
    }

    private function ensure(): void
    {
        if ($this->ready) {
            return;
        }
        $response = $this->send('GET', "/collections/{$this->collection}", allow: [404]);
        if ($response->status === 404) {
            $this->call('POST', '/collections', ['json' => [
                'name' => $this->collection,
                'default_sorting_field' => 'published_at',
                'fields' => [
                    ['name' => 'title', 'type' => 'string'],
                    ['name' => 'body', 'type' => 'string'],
                    ['name' => 'collection', 'type' => 'string', 'facet' => true],
                    ['name' => 'tags', 'type' => 'string[]', 'facet' => true],
                    ['name' => 'locale', 'type' => 'string', 'facet' => true],
                    ['name' => 'published_at', 'type' => 'int64'],
                    ['name' => 'doc_id', 'type' => 'string', 'index' => false, 'optional' => true],
                    ['name' => 'url', 'type' => 'string', 'index' => false, 'optional' => true],
                    ['name' => 'type', 'type' => 'string', 'index' => false, 'optional' => true],
                    ['name' => 'meta', 'type' => 'string', 'index' => false, 'optional' => true],
                ],
            ]], allow: [409]);   // 409: created meanwhile by another worker
        }
        $this->ready = true;
    }

    /**
     * @param array<string, mixed> $options
     * @param list<int> $allow
     * @return array<mixed>
     */
    private function call(string $method, string $path, array $options = [], bool $missingOk = false, array $allow = []): array
    {
        $response = $this->send($method, $path, $options, $missingOk ? [404, ...$allow] : $allow);
        if (!$response->ok() || $response->body === '') {
            return [];
        }
        $data = $response->json();

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $options
     * @param list<int> $allow statuses that are not errors
     */
    private function send(string $method, string $path, array $options = [], array $allow = []): HttpResponse
    {
        $options['headers'] = ($options['headers'] ?? []) + ['X-TYPESENSE-API-KEY' => $this->key];
        $options['timeout'] = $this->timeout;
        try {
            $response = $this->client->request($method, $path, $options);
        } catch (Throwable $e) {
            throw new SearchException('Typesense could not be reached: ' . $e->getMessage(), previous: $e);
        }
        if (!$response->ok() && !in_array($response->status, $allow, true)) {
            throw new SearchException('Typesense answered ' . $response->status . self::reason($response));
        }

        return $response;
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

    /** Document ids: [A-Za-z0-9_-] only (other bytes written as -xx), so they are safe in filters and paths. */
    public static function key(string $collection, string $id): string
    {
        return preg_replace_callback('/[^A-Za-z0-9_]/', static fn($m) => '-' . bin2hex($m[0]), $collection . ':' . $id) ?? '';
    }

    /** Typesense filter values go in backticks, which can't be escaped: drop them from the value. */
    private static function quote(string $value): string
    {
        return '`' . str_replace('`', '', $value) . '`';
    }
}
