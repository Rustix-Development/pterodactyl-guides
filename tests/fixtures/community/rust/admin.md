---
title: How to make yourself admin
order: 20
summary: Give your Steam account owner or moderator rights with ownerid.
---

Owners can use admin commands in the F1 console, such as kicking and banning players or spawning items.

## Find your Steam ID

You need your SteamID64, a 17 digit number starting with `7656119`.

- In the Steam app, open your account name menu, then **Account details**. The Steam ID is shown under your account name.
- Or open your Steam profile in a browser. If you have not set a custom URL, the number is at the end of the link: `steamcommunity.com/profiles/7656119XXXXXXXXXX`.

## Make yourself owner

1. Make sure the server is running.
2. Open the **Console** tab in the panel.
3. Type the following, with your own Steam ID and name, and press Enter:

   ```
   ownerid 7656119XXXXXXXXXX "YourName"
   ```

4. Save it so it survives a restart:

   ```
   server.writecfg
   ```

5. If you are connected to the server, disconnect and join again. The new rights apply when you rejoin.

The list is saved in `server/rust/cfg/users.cfg`.

## Moderators

Use `moderatorid` instead of `ownerid` to give someone moderator rights, then run `server.writecfg` again.

## Removing an owner or moderator

```
removeowner 7656119XXXXXXXXXX
removemoderator 7656119XXXXXXXXXX
server.writecfg
```

## Troubleshooting

**Admin commands still do not work.** Disconnect and rejoin the server. Check that the Steam ID is the full 17 digit number.

**Admin is gone after a restart.** You forgot `server.writecfg`. Run the `ownerid` command again, then `server.writecfg`.
