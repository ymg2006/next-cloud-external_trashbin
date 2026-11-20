# Changelog

## 2.5.0

- **Deleted folders can be browsed again.** `listTrashFolder()` returned an
  empty array, so opening a deleted folder in **Deleted files** showed "No
  deleted files" while the core backend lists the folder's contents. Entries
  are now listed straight from the physical `.recycle` directory, including
  nested folders, with previews resolved through `getTrashNodeById()`.
- A single entry of a deleted folder can now be restored or permanently
  deleted on its own: the entry carries its path relative to the deleted
  folder, so `restoreItem()` moves just that entry back below the folder's
  original location (recreating the original directory if needed) and
  `removeItem()` deletes only that entry. Both keep the folder's metadata
  record, which previously would have made a single-entry action operate on
  the whole folder.
- **Fixed: folder deletes reported a failure.** The storage wrapper was
  registered at priority 0, but `StorageFactory::wrap()` sorts wrappers by
  *descending* priority, so the lowest number ends up outermost - the wrapper
  sat outside `OCA\Files_Trashbin\Storage` (priority 1) instead of inside it.
  The trash-bin wrapper's fall-through delete therefore went straight to the
  real storage on a path the app had just moved away: `rmdir()` returned
  `false`, which DAV turns into an unlogged 403 ("Delete folder: failed",
  although the payload was recycled correctly). File deletes only looked fine
  because `View::unlink()` rescues a vanished file and returns `true`, and a
  refused delete could not be blocked at all - the real `unlink()` ran before
  the wrapper was consulted. The wrapper is now registered at priority 2,
  directly inside the trash-bin wrapper and still outside the encryption
  wrapper.
- **Fixed: restore logged a `TypeError`.** `refreshCacheAfterRestore()` called
  `Updater::propagate($path)` without a time; `Propagator::propagateChange()`
  is typed `int` since Nextcloud 35, so the cache refresh always aborted with
  "must be of type int, null given" after an otherwise successful restore. The
  restored item's mtime (falling back to `ITimeFactory`) is now passed.
- The recycle move runs with `ITrashManager::pauseTrash()` held, so the
  deletes the copy fallback performs on the trash-bin-wrapped storage cannot
  re-enter the trash pipeline and hand the payload to the core trash bin.
- A consumed recycle mark is only honoured while the item really is gone, so a
  delete abandoned between the trash event and the storage call can no longer
  make a later delete of the same path report success without deleting.
- **Behavior change:** when the app cannot move an external item into
  `.recycle`, the delete is now refused - the original stays in place and the
  client gets an explanatory error. Previously the app bowed out silently and
  Nextcloud's own trash bin took over (`LegacyTrashBackend` is registered for
  `OCP\Files\Storage\IStorage`), which copied the external payload into the
  account's home storage and quota: the opposite of what this app is for. A
  failed recycle attempt that threw nothing also produced *zero* log output.
- `RecycleManager::moveToRecycle()` retries `rename()` and the `copy()`
  fallback up to 3 times with linear backoff before giving up, to ride out
  transient network-storage hiccups (SMB/SFTP/S3). Retrying stops early once
  the source is gone, since no retry can recover from that.
- A `rename()` that is applied by the remote end but still reports failure
  (dropped connection after the server executed it) is now recognised - source
  gone plus destination present means the move landed - and reported as a
  successful recycle instead of being cleaned up as a failed attempt.
- The `copy()` fallback verifies that the copy exists (and, for files, matches
  the source size) before the original is removed. `copy()` returning `true`
  is not proof on object stores.
- A copy that cannot be used is only discarded while the original is provably
  still in place; otherwise the copy is the last remaining payload and is kept.
- Directories now get the same copy+verify+remove fallback as files
  (`IStorage::copy()` already recurses into directory contents; recycling no
  longer skips directories when `rename()` fails).
- `ExternalRecycleWrapper::unlink()`/`rmdir()` refuse a delete whose recycle
  attempt failed by throwing `OCP\Files\ForbiddenException` with the reason,
  which the DAV layer forwards to the client instead of a bare "Forbidden".
  The failure mark is request-scoped and not consumed, so a repeated delete in
  the same request cannot fall through to a permanent removal.
- Every failure branch in the recycle path (including a `rename()`/`copy()`
  that returns `false` without throwing) is now logged at `error`/`warning`
  level with operation/source/destination/attempt context.
- Declared compatibility with Nextcloud 35. Every app-private API used
  (`ITrashBackend`, `MoveToTrashEvent`, `Expiration::isExpired()`,
  `Filesystem::addStorageWrapper()`, `Storage\Wrapper\Wrapper` signatures) is
  unchanged between the 34.0.0 and 35.0.0 tags.
- **Fixed: parallel deletes were refused.** Sync clients delete several items
  of a folder at once; their requests raced to create the same
  `.recycle/<timestamp>/...` directory, and every request whose `mkdir()` lost
  logged "Failed to create external recycle directory" and had its delete
  blocked. `ensureDirForPath()` now re-checks `is_dir()` after a failed
  `mkdir()`.
- **Fixed: two paths still handed deletes to the core trash bin.** A trash
  record that could not be stored (parallel deletes of same-named files hit
  the unique trash name) was rolled back and then left to the core trash bin,
  and any exception during the recycle move fell back to it as well. Both now
  refuse the delete, like a failed move.
- **Fixed: a refused folder removal no longer half-empties the folder.** When
  the copy fallback could not `rmdir()` the original (e.g. a locked file),
  it deleted the remaining entries one by one. The original now stays whole.
- Added PHPUnit tests for the recycle move (`composer install && composer test`).

## 2.4.0

- Completed deployment-specific hardcoding audit.
- Restricted the DAV/download/delete storage wrapper to external mounts only.
- Centralized supported external mount types.
- Replaced `time()` with Nextcloud's `ITimeFactory`.
- Removed the hardcoded `/<uid>/files/` path assumption.
- Generalized runtime documentation from SMB-only wording to external storage.
- Added final audit documentation and release validation.

## 2.3.2

- Restored items through a direct same-storage rename.
- Avoided the generic cache move path that produced null metadata errors.
- Rebuilt cache state after a successful restore.

## 2.3.1

- Replaced unsupported `QueryBuilder::selectLiteral()` usage.

## 2.3.0

- Stored new recycle payloads below `.recycle/<timestamp>/<original-path>` while preserving the original basename.
