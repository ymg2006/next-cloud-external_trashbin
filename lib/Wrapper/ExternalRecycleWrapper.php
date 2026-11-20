<?php

/**
 * SPDX-FileCopyrightText: 2026 Manuel Buchner and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\External_Trashbin\Wrapper;

use OC\Files\Storage\Wrapper\Wrapper;
use OCA\External_Trashbin\RecycleManager;
use OCA\External_Trashbin\Storage\DeleteBypassRegistry;
use OCP\Files\ForbiddenException;

/**
 * Adapts external storage to the custom trash backend.
 *
 * - Makes the original delete succeed after the listener already moved it.
 * - Refuses the delete when the recycle move failed, so nothing is removed
 *   permanently without a recycle copy.
 * - Resolves Nextcloud's DAV-only `.d<timestamp>` download suffix to the exact
 *   physical file recorded below `.recycle`.
 */
final class ExternalRecycleWrapper extends Wrapper {
    /** @return resource|false */
    public function fopen(string $path, string $mode) {
        return $this->storage->fopen($this->resolveDavTrashPath($path), $mode);
    }

    public function unlink(string $path): bool {
        if ($this->alreadyRecycled($path)) {
            return true;
        }
        $this->refuseWhenRecycleFailed($path);

        return $this->storage->unlink($path);
    }

    public function rmdir(string $path): bool {
        if ($this->alreadyRecycled($path)) {
            return true;
        }
        $this->refuseWhenRecycleFailed($path);

        return $this->storage->rmdir($path);
    }

    /**
     * Report the delete that follows a completed recycle move as successful
     * without touching the storage again.
     *
     * A mark is only trustworthy while the item really is gone: if a delete is
     * abandoned between the trash event and this call, the stale mark would
     * otherwise make a later delete of the same path in the same request look
     * successful while the item stays in place.
     */
    private function alreadyRecycled(string $path): bool {
        return DeleteBypassRegistry::consume($this, $path)
            && !$this->storage->file_exists($path);
    }

    /**
     * The listener could not move this item into `.recycle`. Refuse the delete
     * instead of letting it fall through to a permanent removal. A bare `false`
     * would reach the client as an unexplained "Forbidden", so the reason is
     * carried in a retryable ForbiddenException, which Nextcloud's DAV layer
     * forwards verbatim for files and directories.
     *
     * @throws ForbiddenException
     */
    private function refuseWhenRecycleFailed(string $path): void {
        if (!DeleteBypassRegistry::isFailed($this, $path)) {
            return;
        }

        throw new ForbiddenException(
            'The item could not be moved into the external recycle bin, so it was not deleted. See the external_trashbin log entries for the cause.',
            true,
        );
    }

    private function resolveDavTrashPath(string $path): string {
        $path = ltrim($path, '/');
        $recyclePrefix = RecycleManager::RECYCLE_ROOT . '/';
        if (!str_starts_with($path, $recyclePrefix)) {
            return $path;
        }

        // Direct paths are always preferred. This keeps ordinary external-storage access
        // and all legacy external-trash layouts untouched.
        if ($this->storage->file_exists($path)) {
            return $path;
        }

        // Nextcloud TrashFile opens `<physical-path>.d<deletedTime>`. Remove
        // only the final DAV suffix and use the physical object when present.
        if (preg_match('/^(.*)\.d\d+$/', $path, $matches) === 1) {
            $physicalPath = $matches[1];
            if ($this->storage->file_exists($physicalPath)) {
                return $physicalPath;
            }
        }

        return $path;
    }
}
