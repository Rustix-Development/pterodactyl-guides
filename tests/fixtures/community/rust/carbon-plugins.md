---
title: How to install Carbon plugins
framework: carbon
order: 40
summary: Add plugins to Carbon, edit their config and grant permissions.
---

Your server runs Carbon. The panel downloads the latest Carbon every time the server starts, so you only need to add plugins. Carbon runs its own plugins and most Oxide (uMod) plugins too.

## Install a plugin

1. Download the plugin's `.cs` file, for example from [umod.org](https://umod.org/plugins) or [Codefling](https://codefling.com).
2. Start the server once with Carbon if you have not yet, so the `carbon` folders are created.
3. Open the **Files** tab and go to `carbon/plugins`.
4. Click **Upload** and choose the `.cs` file.

Carbon picks up new plugins on its own, no restart needed. Check the **Console** tab to see it load.

## Change a plugin's settings

1. Go to `carbon/configs` in the **Files** tab and open the file named after the plugin.
2. Edit it, then save the file.
3. In the **Console** tab, reload the plugin:

   ```
   c.reload PluginName
   ```

Make sure the file is still valid JSON. A missing comma or quote makes the plugin fail to load, and the console says why.

## Permissions

Give a permission to one player (name or Steam ID):

```
c.grant user YourName plugin.permission
```

Give a permission to a group, such as `default` (all players) or `admin`:

```
c.grant group default plugin.permission
```

Take it away again with `c.revoke` in the same way. The plugin's page lists its permissions.

## Useful console commands

| Command | What it does |
| --- | --- |
| `c.plugins` | Lists loaded plugins |
| `c.reload Name` | Reloads one plugin |

## Removing a plugin

Delete its `.cs` file from `carbon/plugins`. Its config and data files stay in `carbon/configs` and `carbon/data` until you delete them.
