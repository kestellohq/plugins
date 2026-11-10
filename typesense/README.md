# Typesense

Site search on a [Typesense](https://typesense.org) server, self-hosted or on Typesense Cloud. Visitors get typo-tolerant, instant results, and large sites get better ranking than Kestello's built-in search.

## Set it up

1. Activate the plugin under **Plugins**.
2. Open **System → Infrastructure**, select **Change** on the Search row and choose **Typesense**.
3. Fill in:
   - **Server address**: for example `http://typesense:8108` on your own server, or `https://xyz.a1.typesense.net` on Typesense Cloud.
   - **API key**: an admin key, or a scoped key with documents and collections access.
   - **Collection**: `kestello` by default. Give each site on a shared server its own.
4. Click **Test connection**, then save. The collection is rebuilt in the background.

The API key is stored sealed with the site's key. The plugin reaches only the server address you give it.

## What gets searched

Published entries of the content types marked searchable, with their titles, text and tags. Drafts, trashed entries and entries under a draft or private parent are never sent to the server.

## Requirements

Kestello 0.1 or newer, PHP 8.4 or newer, and a Typesense server.

Guides are at [docs.kestello.com](https://docs.kestello.com). Licensed under the GPL, version 2 or later.
