<?php

/**
 * SPDX-FileCopyrightText: 2026 Manuel Buchner and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\External_Trashbin\Trash;

use OCA\External_Trashbin\Db\TrashRecord;
use OCA\Files_Trashbin\Trash\TrashItem;
use OCP\Files\Node;

/**
 * Exposes the original filename in Nextcloud while keeping the physical object
 * at the exact path recorded in the external-trash metadata.
 *
 * Nextcloud's Sabre trash file appends `.d<deletedTime>` when opening a root
 * item. ExternalRecycleWrapper translates that DAV-only suffix back to the
 * physical path, so the object stored below `.recycle` can keep its original
 * basename. Entries inside a deleted folder are addressed without the suffix
 * and carry their path relative to the deleted folder, which is what makes an
 * individual restore or permanent deletion of such an entry possible.
 */
final class ExternalTrashItem extends TrashItem {
    private string $displayName;
    private string $physicalInternalPath;

    public function __construct(
        private TrashRecord $record,
        ExternalTrashBackend $backend,
        string $originalLocation,
        int $deletedTime,
        string $trashPath,
        private Node $node,
        \OCP\IUser $user,
        ?\OCP\IUser $deletedBy,
        private string $relativePath = '',
    ) {
        parent::__construct(
            $backend,
            $originalLocation,
            $deletedTime,
            $trashPath,
            $node,
            $user,
            $deletedBy,
        );

        $this->displayName = $relativePath === ''
            ? basename($record->getOriginalPath())
            : $node->getName();
        $this->physicalInternalPath = $node->getInternalPath();
    }

    public function getRecord(): TrashRecord {
        return $this->record;
    }

    /** The physical node below `.recycle` that this item represents. */
    public function getNode(): Node {
        return $this->node;
    }

    /**
     * Path inside the deleted folder, empty for a root trash item. A non-empty
     * value means the item is one entry of a deleted folder whose metadata
     * record covers the whole folder.
     */
    public function getRelativePath(): string {
        return $this->relativePath;
    }

    /**
     * TrashFile/TrashFolder append the deletion suffix to the DAV name.
     */
    public function getName(): string {
        return $this->displayName;
    }

    /**
     * Return the exact physical path. The storage wrapper removes the DAV-only
     * suffix that Nextcloud appends before fopen().
     */
    public function getInternalPath(): string {
        return $this->physicalInternalPath;
    }
}
