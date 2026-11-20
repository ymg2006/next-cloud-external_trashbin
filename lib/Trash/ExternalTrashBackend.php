<?php

/**
 * SPDX-FileCopyrightText: 2026 Manuel Buchner and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\External_Trashbin\Trash;

use OCA\External_Trashbin\Db\TrashRecord;
use OCA\External_Trashbin\Db\TrashRecordMapper;
use OCA\External_Trashbin\RecycleManager;
use OCA\External_Trashbin\Storage\ExternalMount;
use OCA\Files_Trashbin\Trash\ITrashBackend;
use OCA\Files_Trashbin\Trash\ITrashItem;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\Folder;
use OCP\Files\GenericFileException;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\IStorage;
use OCP\IUser;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

final class ExternalTrashBackend implements ITrashBackend {
    public function __construct(
        private TrashRecordMapper $mapper,
        private IRootFolder $rootFolder,
        private IUserManager $userManager,
        private ITimeFactory $timeFactory,
        private LoggerInterface $logger,
    ) {
    }

    /** @return ITrashItem[] */
    public function listTrashRoot(IUser $user): array {
        $items = [];
        foreach ($this->mapper->findForUser($user->getUID()) as $record) {
            try {
                $items[] = $this->makeTrashItem($record, $user);
            } catch (\Throwable $e) {
                // Keep the DB row. A temporarily unavailable network mount should reappear later.
                $this->logger->debug('External trash item is currently unavailable', [
                    'app' => 'external_trashbin',
                    'recordId' => $record->getId(),
                    'mountPoint' => $record->getMountPoint(),
                    'exception' => $e,
                ]);
            }
        }

        return $items;
    }

    public function getTrashRootItem(IUser $user, string $name): ?ITrashItem {
        try {
            $record = $this->findRecordForDavName($user->getUID(), $name);
            return $this->makeTrashItem($record, $user);
        } catch (DoesNotExistException) {
            return null;
        } catch (\Throwable $e) {
            $this->logger->debug('Requested external trash item is unavailable', [
                'app' => 'external_trashbin',
                'trashName' => $name,
                'exception' => $e,
            ]);
            return null;
        }
    }

    /**
     * Entries inside a deleted folder are still on the external resource, so
     * they can be listed straight from the physical `.recycle` directory. They
     * share the parent's metadata record and carry their own relative path.
     *
     * @return ITrashItem[]
     */
    public function listTrashFolder(ITrashItem $folder): array {
        $externalFolder = $this->requireExternalItem($folder);
        $node = $externalFolder->getNode();
        if (!$node instanceof Folder) {
            return [];
        }

        try {
            $children = $node->getDirectoryListing();
        } catch (\Throwable $e) {
            $this->logger->debug('Unable to list the contents of an external trash folder', [
                'app' => 'external_trashbin',
                'recordId' => $externalFolder->getRecord()->getId(),
                'recyclePath' => $node->getInternalPath(),
                'exception' => $e,
            ]);
            return [];
        }

        $items = [];
        foreach ($children as $child) {
            $items[] = $this->makeChildTrashItem($externalFolder, $child);
        }

        return $items;
    }

    public function restoreItem(ITrashItem $item): void {
        $externalItem = $this->requireExternalItem($item);
        if ($externalItem->getRelativePath() !== '') {
            $this->restoreFolderEntry($externalItem);
            return;
        }

        $record = $externalItem->getRecord();

        try {
            $source = $this->getSourceNode($record);
            $storage = $source->getStorage();
            $target = $this->getRestoreTarget($storage, $record);

            // Source and destination are on the same external storage. Using
            // Node::move() invokes Nextcloud's generic cache-move pipeline,
            // which can fail for external-trash entries whose metadata is null.
            // Rename the external-storage object directly and rebuild the affected cache
            // entries afterwards instead.
            $recycleManager = new RecycleManager($storage, $this->logger);
            if (!$recycleManager->restore($record->getRecyclePath(), $target)) {
                throw new GenericFileException('The external storage refused the restore rename');
            }

            $this->refreshCacheAfterRestore(
                $storage,
                $record->getRecyclePath(),
                $target,
                $record,
            );
        } catch (\Throwable $e) {
            $this->logger->error('Unable to restore external trash item', [
                'app' => 'external_trashbin',
                'recordId' => $record->getId(),
                'mountPoint' => $record->getMountPoint(),
                'recyclePath' => $record->getRecyclePath(),
                'originalPath' => $record->getOriginalPath(),
                'exception' => $e,
            ]);

            throw new GenericFileException('Unable to restore the external trash item: ' . $e->getMessage());
        }

        $this->mapper->delete($record);
    }

    public function removeItem(ITrashItem $item): void {
        $externalItem = $this->requireExternalItem($item);
        if ($externalItem->getRelativePath() !== '') {
            $this->removeFolderEntry($externalItem);
            return;
        }

        $this->removeRecord($externalItem->getRecord());
    }

    /**
     * Restore a single entry out of a deleted folder. The folder itself stays
     * in the trash, so its metadata record is kept and only this entry is
     * moved back below the folder's original location, recreating the original
     * directory structure when it no longer exists.
     */
    private function restoreFolderEntry(ExternalTrashItem $item): void {
        $record = $item->getRecord();
        $node = $item->getNode();
        $recyclePath = $node->getInternalPath();

        try {
            $storage = $node->getStorage();
            $target = $this->uniqueRestoreTarget(
                $storage,
                ltrim($record->getOriginalPath(), '/') . '/' . $item->getRelativePath(),
            );

            $recycleManager = new RecycleManager($storage, $this->logger);
            if (!$recycleManager->restore($recyclePath, $target)) {
                throw new GenericFileException('The external storage refused the restore rename');
            }

            $this->refreshCacheAfterRestore($storage, $recyclePath, $target, $record);
        } catch (\Throwable $e) {
            $this->logger->error('Unable to restore an entry of an external trash folder', [
                'app' => 'external_trashbin',
                'recordId' => $record->getId(),
                'mountPoint' => $record->getMountPoint(),
                'recyclePath' => $recyclePath,
                'relativePath' => $item->getRelativePath(),
                'exception' => $e,
            ]);

            throw new GenericFileException('Unable to restore the external trash item: ' . $e->getMessage());
        }
    }

    /**
     * Permanently delete a single entry out of a deleted folder. The folder and
     * its metadata record stay in the trash.
     */
    private function removeFolderEntry(ExternalTrashItem $item): void {
        try {
            $item->getNode()->delete();
        } catch (NotFoundException) {
            // Already gone on the external resource; nothing left to delete.
        } catch (\Throwable $e) {
            throw new GenericFileException(
                'The external resource is unavailable or refused permanent deletion: ' . $e->getMessage()
            );
        }
    }

    /**
     * Used by manual permanent deletion and retention expiry.
     * The metadata row is deleted only after the network copy is gone.
     */
    public function removeRecord(TrashRecord $record): void {
        try {
            $source = $this->getSourceNode($record);
            $source->delete();
        } catch (NotFoundException) {
            // The external file was already removed outside Nextcloud; clean stale metadata.
        } catch (\Throwable $e) {
            throw new GenericFileException(
                'The external resource is unavailable or refused permanent deletion: ' . $e->getMessage()
            );
        }

        $this->mapper->delete($record);
    }

    public function moveToTrash(IStorage $storage, string $internalPath): bool {
        // Move interception is handled by MoveToTrashEvent so only external mounts are affected.
        return false;
    }

    /**
     * Resolves ids of root items *and* of entries inside a deleted folder,
     * which is what the trash preview endpoint asks for when a folder is
     * opened in the Deleted files view.
     */
    public function getTrashNodeById(IUser $user, int $fileId): ?Node {
        try {
            $userFolder = $this->rootFolder->getUserFolder($user->getUID());
        } catch (\Throwable) {
            return null;
        }

        foreach ($userFolder->getById($fileId) as $node) {
            if (!ExternalMount::isSupported($node->getMountPoint()->getMountType())) {
                continue;
            }
            if (RecycleManager::isInRecycle($node->getInternalPath())) {
                return $node;
            }
        }

        return null;
    }

    private function makeTrashItem(TrashRecord $record, IUser $user): ExternalTrashItem {
        $node = $this->getSourceNode($record);
        $deletedBy = $record->getDeletedBy() !== null
            ? $this->userManager->get($record->getDeletedBy())
            : null;

        return new ExternalTrashItem(
            $record,
            $this,
            $record->getOriginalLocation(),
            $record->getDeletedAt(),
            '/' . $record->getTrashName(),
            $node,
            $user,
            $deletedBy,
        );
    }

    /**
     * Build the trash item for one entry of a deleted folder. Metadata record,
     * deletion time and deleting user come from the deleted folder; name, path
     * and file id come from the physical entry below `.recycle`.
     */
    private function makeChildTrashItem(ExternalTrashItem $parent, Node $child): ExternalTrashItem {
        return new ExternalTrashItem(
            $parent->getRecord(),
            $this,
            $parent->getOriginalLocation() . '/' . $child->getName(),
            $parent->getDeletedTime(),
            $parent->getTrashPath() . '/' . $child->getName(),
            $child,
            $parent->getUser(),
            $parent->getDeletedBy(),
            ltrim($parent->getRelativePath() . '/' . $child->getName(), '/'),
        );
    }

    private function findRecordForDavName(string $userId, string $name): TrashRecord {
        try {
            return $this->mapper->findForUserByTrashName($userId, $name);
        } catch (DoesNotExistException) {
            // Continue with the DAV form: <original-name>.d<deleted-time>.
        }

        if (!preg_match('/^(.*)\.d(\d+)$/', $name, $matches)) {
            throw new DoesNotExistException('External trash item not found');
        }

        $requestedBaseName = $matches[1];
        $deletedAt = (int)$matches[2];
        foreach ($this->mapper->findForUserByDeletedAt($userId, $deletedAt) as $record) {
            $originalBaseName = basename($record->getOriginalPath());
            if ($originalBaseName === $requestedBaseName || $record->getTrashName() === $requestedBaseName) {
                return $record;
            }
        }

        throw new DoesNotExistException('External trash item not found');
    }

    private function getSourceNode(TrashRecord $record): Node {
        // Initializes the user's external mounts before resolving the absolute mount path.
        $this->rootFolder->getUserFolder($record->getUserId());
        $fullPath = rtrim($record->getMountPoint(), '/') . '/' . ltrim($record->getRecyclePath(), '/');
        $node = $this->rootFolder->get($fullPath);

        if (!ExternalMount::isSupported($node->getMountPoint()->getMountType())) {
            throw new GenericFileException('Stored mount point is no longer an external mount');
        }

        $storage = $node->getStorage();
        $currentStorageId = method_exists($storage, 'getId') ? (string)$storage->getId() : get_class($storage);
        if ($record->getStorageId() !== $currentStorageId) {
            throw new GenericFileException('The external mount now points to a different storage');
        }

        return $node;
    }

    private function requireExternalItem(ITrashItem $item): ExternalTrashItem {
        if (!$item instanceof ExternalTrashItem) {
            throw new \LogicException('A foreign trash item was passed to the external trash backend');
        }
        return $item;
    }

    private function getRestoreTarget(IStorage $storage, TrashRecord $record): string {
        $target = ltrim($record->getOriginalPath(), '/');
        $parent = dirname($target);

        // If the original directory was removed while the item was in trash,
        // restore it to the root of the same external storage instead.
        if ($parent !== '.' && $parent !== '' && !$storage->is_dir($parent)) {
            $target = basename($target);
        }

        return $this->uniqueRestoreTarget($storage, $target);
    }

    private function uniqueRestoreTarget(IStorage $storage, string $target): string {
        if (!$storage->file_exists($target)) {
            return $target;
        }

        $info = pathinfo($target);
        $directory = ($info['dirname'] ?? '.') === '.' ? '' : $info['dirname'] . '/';
        $filename = $info['filename'] ?? basename($target);
        $extension = isset($info['extension']) ? '.' . $info['extension'] : '';

        for ($i = 1; $i <= 1000; $i++) {
            $candidate = $directory . $filename . ' (' . $i . ')' . $extension;
            if (!$storage->file_exists($candidate)) {
                return $candidate;
            }
        }

        return $directory . $filename . ' (' . bin2hex(random_bytes(4)) . ')' . $extension;
    }

    private function refreshCacheAfterRestore(
        IStorage $storage,
        string $recyclePath,
        string $targetPath,
        TrashRecord $record,
    ): void {
        try {
            $cache = $storage->getCache();

            // Remove stale source and target entries before scanning the real
            // restored object. Removing a folder entry also clears descendants.
            if ($cache->inCache($recyclePath)) {
                $cache->remove($recyclePath);
            }
            if ($cache->inCache($targetPath)) {
                $cache->remove($targetPath);
            }

            $storage->getScanner()->scan(
                $targetPath,
                \OC\Files\Cache\Scanner::SCAN_RECURSIVE,
            );
            // Updater::propagate() still accepts a null time, but
            // Propagator::propagateChange() is typed `int` since Nextcloud 35
            // and raises a TypeError on null. Always pass a concrete time.
            $mtime = $storage->filemtime($targetPath);
            $time = is_int($mtime) && $mtime > 0 ? $mtime : $this->timeFactory->getTime();
            $updater = $storage->getUpdater();
            $updater->propagate($targetPath, $time);

            // Restoring one entry of a deleted folder leaves that folder's size
            // and etag stale, so propagate the vacated parent as well.
            $recycleParent = dirname($recyclePath);
            if ($recycleParent !== '.' && $recycleParent !== '' && $cache->inCache($recycleParent)) {
                $updater->propagate($recycleParent, $time);
            }
        } catch (\Throwable $e) {
            // The network rename has already succeeded. A cache refresh failure
            // must not turn a successful restore into a DAV 404. External
            // storage browsing or files:scan can rebuild the cache later.
            $this->logger->warning('External trash item restored, but cache refresh failed', [
                'app' => 'external_trashbin',
                'recordId' => $record->getId(),
                'recyclePath' => $recyclePath,
                'targetPath' => $targetPath,
                'exception' => $e,
            ]);
        }
    }

}
