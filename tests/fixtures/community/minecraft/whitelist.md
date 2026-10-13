---
title: How to use the whitelist
order: 30
summary: Only let the players you choose join the server.
---

With the whitelist on, only players on the list can join. It is a simple way to keep a server private for friends.

All of these commands go in the **Console** tab, without a `/`. In game, ops can use the same commands with a `/` in front.

## Turn it on

```
whitelist on
```

Add yourself first, so you do not lock yourself out.

## Add and remove players

```
whitelist add PlayerName
whitelist remove PlayerName
```

## See who is on it

```
whitelist list
```

## Turn it off

```
whitelist off
```

## Where it is saved

The list is stored in `whitelist.json`, and whether it is on is stored as `white-list` in `server.properties`. If you edit `whitelist.json` by hand in the **Files** tab, run `whitelist reload` afterwards.

To kick players who are online but not on the list as soon as the whitelist is turned on or reloaded, set `enforce-whitelist=true` in `server.properties` and restart the server.
