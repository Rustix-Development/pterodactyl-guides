---
title: How to install plugins on Paper
framework: paper
order: 40
summary: Add Paper or Spigot plugins by uploading their .jar files.
---

Paper runs plugins made for Paper, and most plugins made for Spigot and Bukkit. Mods for Fabric or Forge do not work on Paper.

## Install a plugin

1. Download the plugin's `.jar` file. Good places to look are [Hangar](https://hangar.papermc.io), [Modrinth](https://modrinth.com/plugins) and [SpigotMC](https://www.spigotmc.org/resources/). Pick a version that supports your server's Minecraft version.
2. **Stop** the server from the **Console** tab.
3. Open the **Files** tab and go to the `plugins` folder.
4. Click **Upload** and choose the `.jar` file.
5. Start the server.

To check it loaded, type this in the console:

```
plugins
```

Working plugins are listed in green, plugins that failed to load in red. If a plugin is red, scroll up in the console to see why.

## Change a plugin's settings

Most plugins create a folder with the same name inside `plugins` on their first start, with a `config.yml` inside.

1. Open the file in the **Files** tab and edit it.
2. Save the file.
3. Restart the server, or use the plugin's own reload command if its page lists one.

YAML is picky about spaces. Use spaces, not tabs, and keep the indentation the same as the lines around it.

## Removing a plugin

Stop the server, delete the plugin's `.jar` from `plugins`, then start the server. Its folder with settings and data can be deleted too if you do not need it.

## Troubleshooting

**Do not use `/reload`.** It can break plugins and leak memory. Restart the server instead.

**The plugin needs another plugin.** Many plugins depend on others, such as Vault or ProtocolLib. The console names the missing one, and the plugin's page links to it.
