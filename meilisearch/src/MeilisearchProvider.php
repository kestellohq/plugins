<?php

declare(strict_types=1);

namespace Plugins\Meilisearch;

use Kestello\Cms\Api\Schema;
use Kestello\Cms\Cms;
use Kestello\Cms\Extension\PluginProvider;
use Kestello\Cms\Platform\DriverDefinition;
use Kestello\Cms\Platform\PlatformException;

/**
 * Adds "meilisearch" to the search drivers. The server address is an
 * endpoint: core hands the factory an HTTP client locked to it, so a
 * private address (http://meilisearch:7700) works without opening the
 * plugin's network access to anything else.
 */
final class MeilisearchProvider extends PluginProvider
{
    public function boot(Cms $cms): void
    {
        $cms->drivers->register('search', new DriverDefinition(
            name: 'meilisearch',
            label: 'Meilisearch',
            description: 'A Meilisearch server (self-hosted or Meilisearch Cloud). Typo tolerant and fast on large sites.',
            schema: Schema::object([
                'url' => Schema::string()->max(500)->description('Server address, for example http://meilisearch:7700 or https://ms-1234.meilisearch.io'),
                'api_key' => Schema::string()->max(500)->optional()->description('A key with search and documents access. Leave empty for a server without a master key.'),
                'index' => Schema::string()->max(60)->pattern('[A-Za-z0-9_-]+')->default('kestello')->description('Index name. Give each site on a shared server its own.'),
                'timeout' => Schema::number()->min(0.5)->max(30)->default(5.0),
            ]),
            secrets: ['api_key'],
            endpoints: ['url'],
            factory: static fn(array $o) => new MeilisearchEngine(
                $o['clients']['url'] ?? throw new PlatformException('Meilisearch needs a server address.'),
                (string) ($o['api_key'] ?? ''),
                (string) ($o['index'] ?? 'kestello'),
                (float) ($o['timeout'] ?? 5.0),
            ),
            health: static fn(MeilisearchEngine $engine) => $engine->ping(),
        ));
    }
}
