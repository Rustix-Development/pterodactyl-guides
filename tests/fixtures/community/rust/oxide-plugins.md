---
title: How to install Oxide plugins
framework: oxide
order: 40
summary: Add uMod (Oxide) plugins, edit their config and grant permissions.
---

Your server runs Oxide (also known as uMod). The panel installs and updates Oxide every time the server starts, so you only need to add plugins.

## Install a plugin

1. Find a plugin on [umod.org](https://umod.org/plugins) and download the `.cs` file.
2. Start the server once with Oxide if you have not yet, so the `oxide` folders are created.
3. Open the **Files** tab and go to `oxide/plugins`.
4. Click **Upload** and choose the `.cs` file.

Oxide loads new plugins on its own, no restart needed. The **Console** tab shows a `Loaded plugin` line with the plugin name when it works.

## Change a plugin's settings

1. Go to `oxide/config` in the **Files** tab and open the file named after the plugin, for example `PluginName.json`.
2. Edit it, then save the file.
3. In the **Console** tab, reload the plugin so it reads the new settings:

   ```
   oxide.reload PluginName
   ```

Make sure the file is still valid JSON. A missing comma or quote makes the plugin fail to load, and the console says why.

## Permissions

Most plugins use permissions to decide who can use them. The plugin's page lists them.

Give a permission to one player (name or Steam ID):

```
oxide.grant user YourName plugin.permission
```

Give a permission to everyone in a group. Oxide has `default` (all players) and `admin` groups:

```
oxide.grant group default plugin.permission
```

Take it away again with `oxide.revoke` in the same way.

## Useful console commands

| Command | What it does |
| --- | --- |
| `oxide.plugins` | Lists loaded plugins |
| `oxide.reload Name` | Reloads one plugin |
| `oxide.unload Name` | Unloads a plugin until the next reload or restart |
| `oxide.load Name` | Loads a plugin |

## Removing a plugin

Delete its `.cs` file from `oxide/plugins`. Its config and data files stay in `oxide/config` and `oxide/data` until you delete them.

## Troubleshooting

**"Error while compiling".** The plugin is outdated or needs another plugin it depends on. Check the plugin's page for requirements and a newer version.
