<?php

/**
 * SPDX-FileCopyrightText: 2026 Manuel Buchner and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\External_Trashbin\Storage;

use OCP\Files\Storage\IStorage;

/**
 * Request-local registry bridging the trash-move listener and the storage
 * wrapper's unlink()/rmdir() for a single delete.
 *
 * - mark()/consume(): the item was already moved to .recycle. The wrapper
 *   reports that one delete as successful without touching the storage again.
 * - markFailed()/isFailed(): the recycle attempt failed. The wrapper refuses
 *   every delete of that path for the rest of the request (fail closed)
 *   instead of permanently removing a source it could not recycle. The mark
 *   is deliberately not consumed: a second delete attempt in the same request
 *   must not fall through to the real unlink()/rmdir().
 *
 * No other external-storage delete behavior is changed.
 */
final class DeleteBypassRegistry {
    /** @var array<string, true> */
    private static array $paths = [];

    /** @var array<string, true> */
    private static array $failedPaths = [];

    public static function mark(IStorage $storage, string $path): void {
        $key = self::key($storage, $path);
        unset(self::$failedPaths[$key]);
        self::$paths[$key] = true;
    }

    public static function consume(IStorage $storage, string $path): bool {
        $key = self::key($storage, $path);
        if (!isset(self::$paths[$key])) {
            return false;
        }

        unset(self::$paths[$key]);
        return true;
    }

    public static function markFailed(IStorage $storage, string $path): void {
        $key = self::key($storage, $path);
        unset(self::$paths[$key]);
        self::$failedPaths[$key] = true;
    }

    public static function isFailed(IStorage $storage, string $path): bool {
        return isset(self::$failedPaths[self::key($storage, $path)]);
    }

    private static function key(IStorage $storage, string $path): string {
        $storageId = method_exists($storage, 'getId')
            ? (string)$storage->getId()
            : get_class($storage);

        return $storageId . "\0" . ltrim($path, '/');
    }
}
