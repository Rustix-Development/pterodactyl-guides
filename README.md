# Server Guides

A **Guides** tab on every server with help articles for its game: how to connect, how to make yourself admin, how to wipe, how to install plugins for the mod framework the server runs. The guides come from a shared GitHub repo the community writes together, plus any repos of your own, and they fill in each server's real address.

## Features

- **Guides tab on every server.** A list on the left, the guide on the right (on phones the list first, then the guide). Guides are grouped into **General**, which every server of the game gets, and the server's framework, such as **Oxide** or **Carbon** on Rust. Switch the server from Oxide to Carbon on its Startup page and the Oxide guides make way for the Carbon ones.
- **Real details in the text.** Guides can use `{{server.name}}`, `{{server.address}}`, `{{server.ip}}`, `{{server.port}}` and `{{server.domain}}`, which the panel replaces for each server. When the [Domains & SSL](https://github.com/Rustix-Development/pterodactyl-domains) extension is installed and the server has a working domain, the address and domain use it (without the port when the domain has an SRV record).
- **Community guides built in.** [Rustix-Development/pterodactyl-egg-guides](https://github.com/Rustix-Development/pterodactyl-egg-guides) is always included, always last, and cannot be removed. Anyone can improve it with a pull request.
- **Your own repos on top.** Add as many repos as you like (private ones with a token) and put them in order. A guide in your repo with the same file name as a community guide replaces it, so you can rewrite any guide for your own setup or add guides like "How to contact our support".
- **Matched automatically.** Each game folder says which eggs it is for: by egg tag, egg name or Docker image. Imported default eggs pick up their guides without any setup.
- **Admin page** under **Admin → Guides**: the repos with their sync status and any files that were skipped, how often to sync, **Sync now**, every egg with the guides it matched, and a dropdown to pin a folder to an egg (for custom eggs) or turn guides off for it.
- **Fast and independent of GitHub.** The panel downloads the repos on a schedule and keeps its own copy. Opening the tab only reads that copy, so it is instant and keeps working when GitHub is down. A failed sync keeps the last good copy.
- **Safe.** Guides are Markdown. Raw HTML is shown as text, script links are removed, links open in a new tab, and images can only come from the repo itself and are served by the panel. A broken guide or `match.json` is skipped and listed on the admin page; it never breaks the tab.
- **Subuser permission**: **Guides → view** shows the tab. Owners and admins always see it. The tab is hidden on servers with no guides.

## Writing guides

The repo format is described in the community repo's [CONTRIBUTING guide](https://github.com/Rustix-Development/pterodactyl-egg-guides/blob/main/CONTRIBUTING.md). In short:

```
<repo>/
  rust/
    match.json            which eggs this folder is for
    connecting.md         a guide
    oxide-plugins.md      a guide for one framework
    images/console.png    images used by the guides
```

`match.json`:

```json
{
  "name": "Rust",
  "tags": ["rust"],
  "eggs": ["Rust"],
  "images": ["ghcr.io/pterodactyl/games:rust"],
  "framework": { "variable": "FRAMEWORK", "values": { "oxide": "Oxide", "carbon": "Carbon" } }
}
```

A guide starts with a small header:

```
---
title: How to install plugins
framework: oxide
order: 20
summary: One line shown under the title in the list.
---
```

Without `framework` a guide is a general one. Images are linked as `images/<file>` (PNG, JPEG, WebP or GIF), and a link to `other-guide.md` opens that guide in the tab.

### How a server gets its guides

1. An admin pin for the server's egg (or "No guides" to turn them off).
2. A folder whose `tags` include one of the egg's tags.
3. A folder whose `eggs` include the egg's name.
4. A folder with an `images` entry the server's Docker image starts with.

The framework comes from the egg's startup variable named in `framework.variable` when the egg has it (an unknown value, empty or `vanilla` means no framework), otherwise from an egg tag equal to one of the `values` keys. So tag your Paper egg `paper` to show the Paper guides.

When several repos have the same game folder, their guides are merged: a guide with the same file name in a higher repo replaces the lower one, and the highest repo with a valid `match.json` decides the matching.

## Settings

Open **Admin → Guides**.

| Setting | Default | What it does |
|---|---|---|
| Guide repos | the community repo | `owner/name` or a GitHub URL, a branch (default `main`) and, for private repos, a token. Use a fine-grained token with read access to the repo's contents. Tokens are stored encrypted and never sent to browsers. |
| Sync automatically | Every hour | Every 15 or 30 minutes, every 1, 3, 6 or 12 hours, once a day, or only when you press **Sync now**. Also under **Admin → Extensions → Server Guides → Settings**. |
| Eggs | Automatic | Per egg: automatic matching, a pinned guide folder, or no guides. |

## Limits

- A repo download is at most 25 MB, a guide 256 KB, a `match.json` 16 KB, an image 5 MB, and all extracted files of one repo 50 MB. Files that break a limit or the naming rules are skipped and listed under **Problems**.
- Only `match.json`, `<guide>.md` and `images/<file>` in lower-case game folders are read from the archive; everything else (and folders starting with `_` or `.`) is ignored. Archive paths are never used as they are, so an archive cannot write outside the extension's folder.
- At most 20 repos besides the community one.

## Requirements

- Pterodactyl Panel 2.0 (2.0-develop or later)
- PHP 8.3+ with the zip extension (part of every panel install)
- The panel's scheduler running every minute (the `schedule:run` cron entry from the panel install guide). It runs `guides:sync --due` every five minutes, which downloads a repo only once its interval has passed.
- Outgoing HTTPS from the panel to `api.github.com` and `codeload.github.com`.

## Install

1. Download `guides-<version>.pteroext` from the [latest release](https://github.com/Rustix-Development/pterodactyl-guides/releases/latest).
2. In the panel, open **Admin → Extensions**, upload the file and enable it.
3. Reload the panel, open **Admin → Guides** and press **Sync now**, or wait for the first scheduled sync.

From a terminal instead:

```sh
php artisan p:extension:install /path/to/guides-<version>.pteroext --enable
php artisan guides:sync
```

## Commands

```sh
php artisan guides:sync          # download every repo now
php artisan guides:sync --due    # only repos whose interval has passed (what the scheduler runs)
```

## API

| Method | Path | Permission |
|---|---|---|
| GET | `/api/client/servers/{server}/extensions/guides/guides` | view |
| GET | `/api/client/servers/{server}/extensions/guides/guides/{slug}` | view |
| GET | `/extensions/guides/{server uuid}/images/{repo}/{folder}/{file}` | view |
| GET | `/api/admin/extensions/guides/overview` | admins |
| POST | `/api/admin/extensions/guides/repos` | admins |
| PATCH, DELETE | `/api/admin/extensions/guides/repos/{id}` | admins |
| PUT | `/api/admin/extensions/guides/repos/order` | admins |
| PUT | `/api/admin/extensions/guides/interval` | admins |
| POST | `/api/admin/extensions/guides/sync` | admins |
| PUT | `/api/admin/extensions/guides/eggs/{egg}` | admins |

A guide comes back as HTML rendered by the panel with the server's details filled in.

## Build from source

```sh
npm install
npm run typecheck
npm test
npm run build
PANEL=/var/www/pterodactyl php tests/guides.php   # sync, parsing, matching and rendering checks
php artisan p:extension:doctor /path/to/guides
php artisan p:extension:pack /path/to/guides
```

To try a repo before it is on GitHub, serve it with the stand-in for GitHub's archive endpoint in `tests/fake-github.php` and point the panel at it with `GUIDES_GITHUB_API_URL=http://127.0.0.1:8099` in its `.env`. The same variable points the extension at a GitHub Enterprise API.

## Uninstall

Remove it under **Admin → Extensions**, or from a terminal:

```sh
php artisan p:extension:remove guides
```

Its data stays in the database tables `ext_guides_repos`, `ext_guides_folders`, `ext_guides_guides` and `ext_guides_eggs`, and the synced copies in `storage/app/ext-guides`.

## License

MIT. See [LICENSE](LICENSE).

This is a community extension and is not affiliated with or endorsed by Pterodactyl.
