# Changelog

## 1.0.1

- Fixed guides breaking after the first scheduled sync on a standard install. The panel's scheduler runs from root's crontab, so the synced copy was owned by root and the web server could not read it ("Permission denied" on Sync now). Synced files now belong to the same user as the panel's storage folder. After updating, the next scheduled sync fixes the folder on its own.

## 1.0.0

- First release: a Guides tab on every server with help articles for its game, grouped into general guides and the ones for the server's mod framework, with the server's name, address, IP, port and domain filled in. Guides come from the community repo (Rustix-Development/pterodactyl-egg-guides) and any GitHub repos you add above it, public or private, synced on a schedule into the panel's own copy. An admin page lists the repos, sync status and skipped files, and lets you pin a guide folder to any egg or turn guides off for it. Subusers need the new **View guides** permission.
