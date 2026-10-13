---
title: How to make yourself operator (op)
order: 20
summary: Give yourself full command access with the op command.
---

Operators (ops) can use every server command in game, such as `/gamemode`, `/tp` and `/ban`.

## Steps

1. Make sure the server is running.
2. Open the **Console** tab in the panel.
3. Type the following with your exact Minecraft username and press Enter. Commands in the console do not need a `/` in front.

   ```
   op YourName
   ```

4. The console replies `Made YourName a server operator`.

In game you can now use commands with a `/`, for example `/gamemode creative`.

Ops are saved in `ops.json`, so they stay after a restart.

## Removing op

```
deop YourName
```

## Troubleshooting

**"That player does not exist".** Check the spelling of the username. On servers in online mode, the name must belong to a real Minecraft account.

**Some commands still say you have no permission.** The **op-permission-level** in `server.properties` sets how much ops can do. The default, 4, allows everything.
