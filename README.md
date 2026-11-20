# External Trashbin 2.5.0 for Nextcloud 34-35

External Trashbin keeps deleted external-storage payloads on the same external
resource while integrating them with Nextcloud's normal **Deleted files** view.

## What it does

- Moves deleted items from supported external mounts to
  `.recycle/<deleted-time>/<original-path>` on that same storage.
- Keeps the original physical filename unchanged.
- Shows the item in Nextcloud **Deleted files**.
- Supports download, restore, permanent deletion, Empty deleted files, and
  maximum-age retention cleanup.
- Leaves primary/internal Nextcloud storage on the standard `files_trashbin`
  backend.
- Retains metadata when the external resource is temporarily unavailable so the
  operation can be retried later.

## Supported scope

The app acts only on mounts whose Nextcloud mount type is:

- `external`
- `external-session`

This includes writable external-storage backends such as SMB/CIFS, Local, SFTP,
WebDAV, Nextcloud, S3-compatible storage, and other backends exposed through the
External Storage app, provided the backend supports the required move/copy/delete
operations.

The implementation was developed against SMB/CIFS. Other backends must be
validated on staging because their rename and directory semantics can differ.

## Compatibility

- Nextcloud: **34 and 35**
- PHP: **8.2 or later** (Nextcloud 35 raises its own floor to 8.3)
- Database: uses Nextcloud's database abstraction and does not hardcode a table
  prefix or database engine

The custom trash contracts are currently under the app-private
`OCA\\Files_Trashbin\\Trash` namespace. Every app-private API used is identical
between the Nextcloud 34.0.0 and 35.0.0 tags, but each major upgrade still
requires a compatibility review before the app is enabled.

## Hardcoding audit

No deployment-specific runtime values are embedded in the application. In
particular, the app does not contain a hostname, IP address, SMB server/share,
credential, Nextcloud username, mount name, database prefix, Docker container
name, Unix user, or installation path.

Intentional protocol/application constants are documented in [AUDIT.md](AUDIT.md).

Version 2.4.0 also restricted its storage wrapper to external mounts only. It no
longer changes file operations on primary/internal storage.

## Upgrade from 2.3.x

No database migration is required. Existing rows and physical recycle paths are
compatible with 2.5.0.

Back up the Nextcloud database and the external storage before upgrading.

### LinuxServer.io Docker

The LinuxServer.io image normally provides an `occ` wrapper that executes as
`abc`:

```bash
docker exec nextcloud occ app:disable external_trashbin || true
docker cp external_trashbin-2.5.0-nc34-35.zip nextcloud:/tmp/
docker exec nextcloud sh -c '
rm -rf /config/www/nextcloud/apps/external_trashbin &&
unzip -q /tmp/external_trashbin-2.5.0-nc34-35.zip -d /config/www/nextcloud/apps &&
chown -R abc:abc /config/www/nextcloud/apps/external_trashbin
'
docker exec nextcloud occ app:enable external_trashbin
docker exec nextcloud occ maintenance:repair
docker restart nextcloud
```

Explicit fallback when the image does not provide the wrapper:

```bash
docker exec -u abc nextcloud php /app/www/public/occ app:enable external_trashbin
```

### Official Nextcloud Docker image

```bash
docker exec -u www-data NEXTCLOUD_CONTAINER php /var/www/html/occ app:disable external_trashbin || true
docker cp external_trashbin-2.5.0-nc34-35.zip NEXTCLOUD_CONTAINER:/tmp/
docker exec NEXTCLOUD_CONTAINER sh -c '
rm -rf /var/www/html/custom_apps/external_trashbin &&
unzip -q /tmp/external_trashbin-2.5.0-nc34-35.zip -d /var/www/html/custom_apps &&
chown -R www-data:www-data /var/www/html/custom_apps/external_trashbin
'
docker exec -u www-data NEXTCLOUD_CONTAINER php /var/www/html/occ app:enable external_trashbin
docker exec -u www-data NEXTCLOUD_CONTAINER php /var/www/html/occ maintenance:repair
```

Adjust the writable app path when `apps_paths` uses a different location.

## Verification

```bash
docker exec nextcloud occ app:list | grep -A3 external_trashbin
docker exec nextcloud occ migrations:status external_trashbin
docker exec nextcloud occ app:getpath external_trashbin
```

Expected version:

```text
external_trashbin: 2.5.0
```

## Production acceptance test

Use disposable files and folders:

1. Delete a file from each external mount type you use.
2. Confirm its payload exists below `.recycle/<timestamp>/...` on the same
   external resource.
3. Confirm it appears in **Deleted files**.
4. Download it and compare the checksum.
5. Restore it and compare the checksum again.
6. Delete it again and use **Delete permanently**.
7. Confirm the external recycle copy is gone.
8. Repeat with a non-empty folder.
9. Disconnect the external resource and verify restore/permanent deletion fails
   without deleting the database metadata.
10. Reconnect and retry.
11. Verify ordinary internal-storage trash behavior remains unchanged.
12. Make `.recycle` unwritable for the mount's credentials, delete a file, and
    confirm the client reports the refusal, the file is still there, the
    Nextcloud log names the cause, and nothing landed in the account's own
    **Deleted files**.
13. Open a deleted folder in **Deleted files**, confirm its entries are listed,
    download one, restore one entry (the folder must stay in the trash), and
    permanently delete another (siblings must survive).

## Known limitations

- A deleted folder keeps one metadata record covering the whole folder.
  Browsing it in **Deleted files** lists the entries directly from the
  external resource, and single entries can be restored or permanently
  deleted; the folder itself stays in the trash until it is restored or
  removed as a whole. Restoring a single entry recreates the folder's original
  path on the external storage if it no longer exists.
- Session-authenticated mounts may be unavailable to background jobs until the
  user's session mounts them again.
- Files manually placed inside `.recycle` are not imported because no metadata
  record exists.
- File versions associated with an external file are not moved into this custom
  recycle area.
- The package still depends on private Nextcloud internals and should be
  integration-tested after every Nextcloud maintenance update.
- Since 2.5.0, a delete on an external mount is refused (the item stays in
  place and the client reports the reason) if the app cannot move it into
  `.recycle` after retries. Before 2.5.0 the delete continued and Nextcloud's
  own trash bin absorbed the item, which pulled the external payload into the
  account's home storage and quota. The new behavior trades a small chance of
  "can't delete right now" for never moving external payloads into home
  storage. Check the Nextcloud log (`external_trashbin` context) for the cause
  before retrying.
- A copy fallback that succeeds while the original cannot be removed leaves an
  unregistered copy below `.recycle`. It is logged at `error` level with both
  paths and has to be removed or restored manually: retention expiry only
  covers items that have a metadata record.

## Recovery

Disabling this app does not delete external recycle payloads or metadata:

```bash
docker exec nextcloud occ app:disable external_trashbin
```

The physical payload location is recorded in the `external_trash_items` table
(with the configured Nextcloud table prefix applied automatically).
