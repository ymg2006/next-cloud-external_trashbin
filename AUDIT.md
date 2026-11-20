# Final hardcoding and portability audit

Release: **2.5.0**  
Target: **Nextcloud 34-35**

## Runtime values not hardcoded

The application contains no deployment-specific runtime values for:

- Nextcloud hostname or public URL
- IP address, SMB server, share, domain, username, or password
- Nextcloud user ID such as `admin`
- External mount names such as `Cloud` or `Files`
- Database engine, database name, or table prefix such as `oc_`
- Docker container name, Unix account, app path, data path, or `occ` path

The database table is referenced as `external_trash_items`; Nextcloud's database layer applies the configured table prefix automatically.

## Intentional constants

These values are application or Nextcloud protocol identifiers, not deployment assumptions:

- App ID: `external_trashbin`
- External recycle directory: `.recycle`
- Nextcloud mount types: `external` and `external-session`
- DAV deleted-file suffix: `.d<timestamp>`
- Nextcloud compatibility: major versions 34 and 35
- Background retention interval: 30 minutes
- Recycle-move retry budget: 3 attempts, 200 ms linear backoff
- Storage wrapper priority: 2, i.e. directly inside `OCA\Files_Trashbin\Storage` (priority 1) and outside `oc_encryption` (priority 2, registered earlier); `StorageFactory::wrap()` applies wrappers in descending priority order

## Hardening changes in 2.5.0

- A failed recycle move no longer falls through to Nextcloud's own trash bin, which would copy the external payload into the account's home storage and quota. The delete is refused instead, with the reason reported to the client.
- `copy()` results are verified on the storage before the original is removed, and a copy is only discarded while the original is provably still present.
- A `rename()` that lands remotely but reports failure is detected instead of being treated as a failed attempt.
- The storage wrapper is registered inside the trash-bin wrapper, so a delete that follows a recycle move is intercepted before it can reach the real storage. Folder deletes no longer report a 403 after a successful recycle.
- Restore passes an explicit propagation time, which Nextcloud 35's typed `Propagator::propagateChange()` requires.

## Hardening changes in 2.4.0

- The storage wrapper is now attached only to external and external-session mounts. It no longer changes `fopen`, `unlink`, or `rmdir` behavior on primary/internal storage.
- Supported mount-type strings are centralized in one class.
- Deletion time uses Nextcloud's `ITimeFactory` rather than PHP's global `time()`.
- User-relative display paths are derived from `IRootFolder::getUserFolder()->getPath()` rather than assuming `/<uid>/files/`.
- SMB-specific comments were generalized because the runtime code uses `IStorage` and can operate on any writable external backend that supports the required storage operations.

## Deliberate compatibility boundary

The custom trash backend API is under `OCA\\Files_Trashbin`, not the stable public `OCP` namespace. Every app-private API in use (`ITrashBackend`, `MoveToTrashEvent`, `Expiration::isExpired()`, `Filesystem::addStorageWrapper()`, `Storage\\Wrapper\\Wrapper` method signatures) is identical between the Nextcloud 34.0.0 and 35.0.0 tags, so both majors are declared supported. Any further major release must be reviewed before the app is enabled on it.
