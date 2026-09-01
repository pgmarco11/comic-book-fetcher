# Comic Books Fetcher & Manager

A WordPress plugin for browsing comic publishers, series, and individual issues using the [Metron](https://metron.cloud/) and [Comic Vine](https://comicvine.gamespot.com/api/) APIs.

The plugin also provides collection and wish-list functionality for logged-in users.

## Features

* Browse comic publishers, series, and individual issues
* Search and filter publishers and series
* Retrieve issue and series covers from Comic Vine
* Use Metron covers when Comic Vine images are unavailable
* AJAX pagination and image loading
* Batch image requests to reduce API usage
* WordPress transient caching
* Personal comic collection management
* Comic wish-list functionality
* Publisher and comic genre taxonomies
* Administrative API settings
* Cache clearing and publisher cache warm-up tools

## Requirements

* WordPress
* PHP 7.4 or newer
* A Metron account and API credentials
* A Comic Vine API key
* jQuery, included with WordPress

## Installation

1. Download or clone this repository into your WordPress plugins directory:

   ```bash
   cd wp-content/plugins
   git clone https://github.com/pgmarco11/comic-book-fetcher.git
   ```

2. Activate **Comic Books Fetcher & Manager** from the WordPress Plugins screen.

3. Open **Comic Books → API Settings** in the WordPress dashboard.

4. Enter your Metron username and password.

5. Enter your Comic Vine API key.

6. Create the catalog pages required by the plugin:

   * `/comic-catalog/`
   * `/comic-catalog/issues/`
   * `/comic-catalog/issue/`

> The current templates and front-end links assume these page paths. Update the plugin templates if your website uses different URLs.

## API configuration

The API credentials are stored as WordPress options:

* `metron_api_username`
* `metron_api_password`
* `comic_vine_api_key`

Credentials can be entered through the plugin’s administrative settings screen.

Do not commit API credentials directly to the repository.

## Catalog structure

The catalog follows this general navigation structure:

```text
Publishers
└── Series
    └── Issues
        └── Issue details
```

The primary catalog routes are:

```text
/comic-catalog/
/comic-catalog/issues/?title_id=SERIES_ID
/comic-catalog/issue/?issue_id=ISSUE_ID&title_id=SERIES_ID
```

## Data and image flow

Metron is used as the primary catalog data source. Comic Vine enriches the Metron data and supplies preferred cover images when mappings are available.

Issue covers use the following priority:

1. Comic Vine issue image
2. Metron issue image
3. Plugin placeholder image

Series covers use cached images and batched Comic Vine requests when the Metron series list does not include a usable image.

## AJAX actions

The primary AJAX handlers include:

* `load_publishers`
* `load_book`
* `load_issues`
* `load_series_images_batch`
* `load_publisher_images_batch`

Additional AJAX handlers manage collection and wish-list actions.

AJAX requests use the `comicbooks_fetchers_data` nonce.

## Caching

The plugin uses WordPress transients to cache:

* Metron API responses
* Publisher records
* Publisher series pages
* Series metadata
* Metron-to-Comic-Vine ID mappings
* Issue lists
* Comic Vine issue images
* Comic Vine series images
* Logged-in collection status

Successful results are generally cached longer than missing or unsuccessful results.

The administrative settings screen includes tools for:

* Clearing series caches
* Warming caches for selected publisher IDs

Avoid clearing all transients during normal operation because the next request will need to rebuild the cold caches.

## Rate limiting

`MetronClient` spaces external Metron requests to help remain within API rate limits. API responses are cached so repeated catalog visits do not request the same records again.

When adding new API functionality:

* Check existing transients first
* Prefer batch requests over one request per item
* Avoid duplicate requests between PHP and JavaScript
* Cache successful mappings
* Briefly cache confirmed missing mappings
* Respect Metron and Comic Vine rate limits

## Project structure

```text
comic-book-fetcher.php
    Main plugin bootstrap, asset loading, and administrative settings

class-metron-client.php
    Metron HTTP client, request spacing, retries, and API caching

class-comic-data-service.php
    Metron and Comic Vine data retrieval and caching layer

class-comicbooks.php
    AJAX handlers and catalog controller

class-comic-renderer.php
    Issue and collection rendering helpers

templates/
    Catalog, issue-list, and issue-detail templates

includes/
    Collection and wish-list handlers

js/
    AJAX loading, lazy loading, collection, and wish-list behavior

css/
    Catalog and wish-list styles

images/
    Plugin images and placeholder artwork
```

## Development guidelines

When contributing to the plugin:

* Follow WordPress coding and security practices
* Sanitize all request data
* Verify AJAX nonces
* Escape rendered output
* Use prepared SQL statements
* Check caches before calling external APIs
* Prefer batch API requests
* Do not store credentials in source control
* Test both logged-in and logged-out catalog behavior

## Debugging

WordPress debugging can be enabled in `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Debug output will normally be written to:

```text
wp-content/debug.log
```

Disable verbose API logging on production sites after troubleshooting is complete.

## License

This project is distributed under the terms provided in [LICENSE](LICENSE).
