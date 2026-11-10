# Meilisearch

Site search on a [Meilisearch](https://www.meilisearch.com) server, self-hosted or on Meilisearch Cloud. Visitors get typo-tolerant, instant results, and large sites get better ranking than Kestello's built-in search.

## Set it up

1. Activate the plugin under **Plugins**.
2. Open **System → Infrastructure**, select **Change** on the Search row and choose **Meilisearch**.
3. Fill in:
   - **Server address**: for example `http://meilisearch:7700` on your own server, or `https://ms-1234.meilisearch.io` on Meilisearch Cloud.
   - **API key**: a key with search and documents access. Leave it empty for a server without a master key.
   - **Index name**: `kestello` by default. Give each site on a shared server its own.
4. Click **Test connection**, then save. The index is rebuilt in the background.

The API key is stored sealed with the site's key. The plugin reaches only the server address you give it.

## What gets searched

Published entries of the content types marked searchable, with their titles, text and tags. Drafts, trashed entries and entries under a draft or private parent are never sent to the server.

## Requirements

Kestello 0.1 or newer, PHP 8.4 or newer, and a Meilisearch server.

Guides are at [docs.kestello.com](https://docs.kestello.com). Licensed under the GPL, version 2 or later.
