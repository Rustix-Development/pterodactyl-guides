A Guides tab on every server with help articles for its game and mod framework, synced from GitHub repos the community writes together.

## Install

Download `guides-${VERSION}.pteroext` below, then upload it under **Admin → Extensions** and enable it. From a terminal instead:

```sh
php artisan p:extension:install /path/to/guides-${VERSION}.pteroext --enable
```

The community guides are downloaded on the first scheduled sync, or press **Sync now** under **Admin → Guides**. Requires Pterodactyl Panel 2.0 (2.0-develop or later), PHP 8.3+ and the panel's scheduler. See the README for features and settings.

This is a community extension and is not affiliated with or endorsed by Pterodactyl.
