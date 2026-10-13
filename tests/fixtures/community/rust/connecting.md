---
title: How to connect to your server
order: 10
summary: Join your Rust server from the in-game console or the server browser.
---

Your server's address is `{{server.address}}`.

## Before you start

Start the server from the **Console** tab and wait until the console shows `Server startup complete`. The first start can take several minutes while the map is built.

## Connect with the F1 console

1. Launch Rust and wait for the main menu.
2. Press **F1** to open the console.
3. Type the following and press Enter:

   ```
   client.connect {{server.address}}
   ```

## Find it in the server browser

1. In the main menu, click **Play Game**.
2. Open the **Community** tab.
3. Search for your server's name. This is the **Server Name** on the **Startup** tab of the panel.
4. Click the server, then **Join Server**.

New servers can take a while to appear in the list. The F1 console works straight away.

## Troubleshooting

**"Disconnected: Connection attempt failed".** The server is probably still starting. Check the Console tab for `Server startup complete` and try again.

**The server is not in the browser.** Use the F1 console method. Also check the Community tab filters, which can hide modded or full servers.

**"Steam Auth Failed".** Restart Steam and the game, then try again.
