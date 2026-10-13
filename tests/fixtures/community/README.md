# Pterodactyl Egg Guides

Help guides for game servers running on [Pterodactyl](https://pterodactyl.io), written by the community.

Every game host ends up writing the same pages: how to connect, how to make yourself admin, how to install plugins. This repo is one shared place for those guides, so they only need to be written once and everyone can improve them.

## How the guides show up

The [Server Guides](https://github.com/Rustix-Development/pterodactyl-guides) panel extension adds a Guides tab to each server. It syncs this repo, works out which game the server runs, and lists the matching guides. Guides can fill in the server's real details, so "connect to your server" becomes "connect to play.example.com:28015".

If a server runs a mod framework, such as Oxide or Carbon on Rust, the tab also shows the guides written for that framework.

## What is in here

Each game has its own folder:

```
rust/
  match.json        which eggs this folder is for
  connecting.md     a guide
  admin.md          another guide
minecraft/
  match.json
  ...
```

A guide is a Markdown file with a short header:

```markdown
---
title: How to make yourself admin
order: 20
summary: Give your Steam account owner rights on the server.
---

1. Open the Console tab.
2. Type `ownerid 7656119XXXXXXXXXX` and press Enter.
```

Folders starting with `_` or `.` are ignored by the extension. `_template/guide.md` is a starting point you can copy.

## Contributing

Found a mistake, or know a game we do not cover yet? You are very welcome to help. You do not need to be a developer: if you can edit a text file on GitHub, you can write a guide.

Read [CONTRIBUTING.md](CONTRIBUTING.md) to get started, and please follow our [Code of Conduct](CODE_OF_CONDUCT.md).

## License

[MIT](LICENSE). By contributing, you agree that your guides are shared under the same license.
