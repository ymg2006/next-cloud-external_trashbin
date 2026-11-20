<?php

/**
 * SPDX-FileCopyrightText: 2026 Manuel Buchner and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\External_Trashbin\Listener;

use OC\Files\Filesystem;
use OCA\External_Trashbin\Db\TrashRecord;
use OCA\External_Trashbin\Db\TrashRecordMapper;
use OCA\External_Trashbin\RecycleManager;
use OCA\External_Trashbin\Storage\DeleteBypassRegistry;
use OCA\External_Trashbin\Storage\ExternalMount;
use OCA\External_Trashbin\Wrapper\ExternalRecycleWrapper;
use OCA\Files_Trashbin\Events\MoveToTrashEvent;
use OCA\Files_Trashbin\Trash\ITrashManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\BeforeFileSystemSetupEvent;
use OCP\Files\IRootFolder;
use OCP\Files\Mount\IMountPoint;
use OCP\Files\Storage\IStorage;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/** @template-implements IEventListener<Event> */
final class EventListener implements IEventListener {
    private static bool $handlingMove = false;

    public function __construct(
        private TrashRecordMapper $mapper,
        private IUserSession $userSession,
        private IRootFolder $rootFolder,
        private ITimeFactory $timeFactory,
        private ITrashManager $trashManager,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(Event $event): void {
        if ($event instanceof BeforeFileSystemSetupEvent) {
            $this->registerExternalStorageWrapper();
            return;
        }

        if ($event instanceof MoveToTrashEvent) {
            $this->handleMoveToTrash($event);
        }
    }

    /**
     * Storage wrappers are applied in *descending* priority order
     * (`StorageFactory::wrap()` sorts with `$b['priority'] - $a['priority']`),
     * so the highest priority ends up innermost and the lowest outermost.
     *
     * `OCA\Files_Trashbin\Storage` registers at priority 1. This wrapper must
     * sit directly inside it: when the trash bin is disabled for a delete,
     * `Files_Trashbin\Storage::doDelete()` calls `getWrapperStorage()->rmdir()`
     * on the next storage down, and that call is what this wrapper has to
     * intercept. Registering below priority 1 put the wrapper outside the
     * trash-bin wrapper, where the real storage delete ran unintercepted:
     * folder deletes then returned false (Sabre 403, "Delete folder: failed"),
     * file deletes were only masked by View::unlink()'s ghost-file rescue, and
     * a refused delete could not be blocked at all.
     *
     * Priority 2 also matches server-side encryption's `oc_encryption`
     * wrapper, which is registered during the earlier `preSetup` hook. PHP's
     * stable sort therefore keeps encryption inside this wrapper, so `fopen()`
     * still yields decrypted content.
     */
    private function registerExternalStorageWrapper(): void {
        Filesystem::addStorageWrapper(
            'external_trashbin',
            static function (string $mountPoint, IStorage $storage, IMountPoint $mount): IStorage {
                if (!ExternalMount::isSupported($mount->getMountType())) {
                    return $storage;
                }

                return new ExternalRecycleWrapper([
                    'storage' => $storage,
                    'mountPoint' => $mountPoint,
                ]);
            },
            2,
        );
    }

    private function handleMoveToTrash(MoveToTrashEvent $event): void {
        if (self::$handlingMove) {
            return;
        }

        $node = $event->getNode();
        $mount = $node->getMountPoint();
        if (!ExternalMount::isSupported($mount->getMountType())) {
            return;
        }

        $internalPath = ltrim($node->getInternalPath(), '/');
        if ($internalPath === '') {
            return;
        }

        $storage = $node->getStorage();
        $recycleManager = new RecycleManager($storage, $this->logger);

        // Deleting from .recycle means permanent deletion. Never recycle it again.
        if (RecycleManager::isInRecycle($internalPath)) {
            $event->disableTrashBin();
            return;
        }

        $user = $this->userSession->getUser();
        if ($user === null) {
            // Without a trash owner we cannot create a safe Nextcloud trash record.
            return;
        }

        $deletedAt = $this->timeFactory->getTime();
        $baseName = basename($internalPath);
        while ($this->mapper->trashNameExistsForUser(
            $user->getUID(),
            $baseName . '.d' . $deletedAt,
        )) {
            $deletedAt++;
        }

        self::$handlingMove = true;
        // The storage handed to RecycleManager still carries the trash-bin
        // wrapper above us, so its own unlink()/rmdir() calls (copy fallback,
        // leftover cleanup) would re-enter the trash pipeline and let the core
        // backend copy the payload into the account's home trash. Pausing the
        // trash manager makes every nested delete go straight to the storage.
        $this->trashManager->pauseTrash();
        try {
            $moveResult = $recycleManager->moveToRecycle($internalPath, $deletedAt);
            if ($moveResult === null) {
                // moveToRecycle() already retried and logged the specific cause.
                // Leaving the event enabled would hand the item to Nextcloud's
                // own trash bin, i.e. copy the external payload into the
                // account's home storage and quota - exactly what this app
                // exists to avoid. So the core trash bin stays disabled and the
                // wrapper refuses the delete that would otherwise follow, which
                // keeps the original in place and reports an error instead.
                $this->logger->error('Blocking delete: unable to move external item to .recycle', [
                    'app' => 'external_trashbin',
                    'source' => $internalPath,
                    'mountPoint' => $mount->getMountPoint(),
                ]);
                DeleteBypassRegistry::markFailed($storage, $internalPath);
                $event->disableTrashBin();
                return;
            }
            $recyclePath = $moveResult['path'];
            $deletedAt = $moveResult['deletedAt'];

            $record = new TrashRecord();
            $record->setUserId($user->getUID());
            $record->setDeletedBy($user->getUID());
            $record->setMountPoint($mount->getMountPoint());
            $record->setStorageId($this->getStorageIdentity($storage));
            $record->setOriginalPath($internalPath);
            $record->setOriginalLocation($this->getUserRelativePath(
                $node->getPath(),
                $user->getUID(),
                $internalPath,
            ));
            $record->setRecyclePath($recyclePath);
            $record->setTrashName($baseName . '.d' . $deletedAt);
            $record->setDeletedAt($deletedAt);

            try {
                $this->mapper->insert($record);
            } catch (\Throwable $e) {
                $rolledBack = $recycleManager->restore($recyclePath, $internalPath);
                $this->logger->error('Failed to register external trash item in Nextcloud', [
                    'app' => 'external_trashbin',
                    'source' => $internalPath,
                    'recyclePath' => $recyclePath,
                    'rolledBack' => $rolledBack,
                    'exception' => $e,
                ]);

                // Rolled back: refuse the delete (the client retries and gets a
                // fresh trash name; parallel deletes of same-named files collide
                // on the unique trash name). Not rolled back: the recycle copy is
                // the only one left, so report the delete as done. Either way the
                // core trash bin must not copy the payload into the home storage.
                if ($rolledBack) {
                    DeleteBypassRegistry::markFailed($storage, $internalPath);
                } else {
                    DeleteBypassRegistry::mark($storage, $internalPath);
                }
                $event->disableTrashBin();
                return;
            }

            DeleteBypassRegistry::mark($storage, $internalPath);
            $event->disableTrashBin();
        } catch (\Throwable $e) {
            // Same rule as a failed move: never fall back to the core trash bin.
            $this->logger->error('Blocking delete: external trash move raised an error', [
                'app' => 'external_trashbin',
                'path' => $internalPath,
                'exception' => $e,
            ]);
            DeleteBypassRegistry::markFailed($storage, $internalPath);
            $event->disableTrashBin();
        } finally {
            $this->trashManager->resumeTrash();
            self::$handlingMove = false;
        }
    }

    private function getUserRelativePath(string $nodePath, string $userId, string $fallback): string {
        try {
            $userFolderPath = rtrim($this->rootFolder->getUserFolder($userId)->getPath(), '/') . '/';
            if (str_starts_with($nodePath, $userFolderPath)) {
                return ltrim(substr($nodePath, strlen($userFolderPath)), '/');
            }
        } catch (\Throwable $e) {
            $this->logger->debug('Unable to derive the user-relative external trash location', [
                'app' => 'external_trashbin',
                'userId' => $userId,
                'exception' => $e,
            ]);
        }

        return ltrim($fallback, '/');
    }

    private function getStorageIdentity(IStorage $storage): string {
        return method_exists($storage, 'getId')
            ? (string)$storage->getId()
            : get_class($storage);
    }
}
