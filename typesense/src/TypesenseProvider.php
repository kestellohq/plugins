<?php

declare(strict_types=1);

namespace Plugins\Typesense;

use Kestello\Cms\Api\Schema;
use Kestello\Cms\Cms;
use Kestello\Cms\Extension\PluginProvider;
use Kestello\Cms\Platform\DriverDefinition;
use Kestello\Cms\Platform\PlatformException;

/** Adds "typesense" to the search drivers (see the Meilisearch plugin for how the address is handled). */
final class TypesenseProvider extends PluginProvider
{
    public function boot(Cms $cms): void
    {
        $cms->drivers->register('search', new DriverDefinition(
            name: 'typesense',
            label: 'Typesense',
            description: 'A Typesense server (self-hosted or Typesense Cloud). Typo tolerant and fast on large sites.',
            schema: Schema::object([
                'url' => Schema::string()->max(500)->description('Server address, for example http://typesense:8108 or https://xyz.a1.typesense.net'),
                'api_key' => Schema::string()->max(500)->description('An admin key, or a scoped key with documents and collections access.'),
                'collection' => Schema::string()->max(60)->pattern('[A-Za-z0-9_-]+')->default('kestello')->description('Collection name. Give each site on a shared server its own.'),
                'timeout' => Schema::number()->min(0.5)->max(30)->default(5.0),
            ]),
            secrets: ['api_key'],
            endpoints: ['url'],
            factory: static fn(array $o) => new TypesenseEngine(
                $o['clients']['url'] ?? throw new PlatformException('Typesense needs a server address.'),
                (string) ($o['api_key'] ?? ''),
                (string) ($o['collection'] ?? 'kestello'),
                (float) ($o['timeout'] ?? 5.0),
            ),
            health: static fn(TypesenseEngine $engine) => $engine->ping(),
        ));
    }
}
