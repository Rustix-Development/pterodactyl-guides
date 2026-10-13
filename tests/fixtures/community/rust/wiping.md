---
title: How to wipe your server
order: 30
summary: Reset the map, blueprints or everything by removing the right save files.
---

A wipe resets progress on the server. There are two kinds:

- **Map wipe.** Removes the world: buildings, loot and everything players have placed. Players keep the blueprints they have learned.
- **Blueprint wipe.** Also removes every player's learned blueprints, so everyone starts from scratch.

All save files live in the `server/rust` folder.

## Before you start

1. Make a backup from the **Backups** tab, in case you want anything back.
2. **Stop** the server. Deleting save files while it runs does not work, because the server writes them again.

## Map wipe

1. Open the **Files** tab and go to `server/rust`.
2. Delete every file that starts with `proceduralmap.` and ends in `.sav`, including numbered backups such as `.sav.1` and `.sav.2`.
3. Delete the matching `.map` file too, starting with `proceduralmap.`.
4. Optional: to get a different map, change **World Seed** (and **World Size** if you want) on the **Startup** tab.
5. Start the server. A fresh map is built, which takes a few minutes.

If you use a custom map (the **Custom Map URL** setting), the save files are named after that map instead of `proceduralmap`.

## Blueprint wipe

Do a map wipe as above, and before starting the server, also delete every file in `server/rust` that starts with `player.blueprints.`.

## Forced wipes

Facepunch releases a major update on the first Thursday of each month. That update changes the save format, so every server gets a new map whether you delete files or not. Blueprints are only reset on a forced wipe when Facepunch announces a blueprint wipe for that update.

## Plugin data

If you run Oxide or Carbon, plugins keep their own data, such as homes, kits or shop balances. Check each plugin's page to see what should be cleared on a wipe.
