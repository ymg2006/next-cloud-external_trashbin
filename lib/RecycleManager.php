<?php

/**
 * SPDX-FileCopyrightText: 2026 Manuel Buchner and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\External_Trashbin;

use OCP\Files\Storage\IStorage;
use Psr\Log\LoggerInterface;

final class RecycleManager {
    public const RECYCLE_ROOT = '.recycle';

    /** Bounded retries for transient network-storage hiccups (SMB/SFTP/S3). */
    private const MAX_ATTEMPTS = 3;
    private const RETRY_DELAY_US = 200_000;

    public function __construct(
        private IStorage $storage,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Move an item into a timestamp-specific directory while preserving the
     * original basename exactly.
     *
     * Example:
     *   source: reports/annual.pdf
     *   target: .recycle/1785182216/reports/annual.pdf
     *
     * @return array{path: string, deletedAt: int}|null Exact destination and
     * adjusted deletion timestamp, or null when the move failed.
     */
    public function moveToRecycle(string $sourcePath, int $deletedAt): ?array {
        $sourcePath = ltrim($sourcePath, '/');
        [$destination, $deletedAt] = $this->findAvailableDestination($sourcePath, $deletedAt);

        if (!$this->ensureDirForPath($destination)) {
            $this->logger->error('Failed to create external recycle directory', [
                'app' => 'external_trashbin',
                'destination' => $destination,
            ]);
            return null;
        }

        $moved = ['path' => $destination, 'deletedAt' => $deletedAt];

        if ($this->attemptWithRetry(
            fn () => $this->storage->rename($sourcePath, $destination),
            'rename',
            $sourcePath,
            $destination,
        )) {
            return $moved;
        }

        // A rename can be applied by the remote end and still report failure
        // (connection dropped after the server executed it, cache exception on
        // the way back). findAvailableDestination() proved the destination was
        // free, so "source gone, destination present" can only mean the move
        // landed. That copy is then the only one left and must never be
        // treated as a failed attempt or cleaned up.
        if ($this->sourceMovedToDestination($sourcePath, $destination)) {
            $this->logger->warning('External recycle rename reported a failure but the item is already at the destination; treating it as moved', [
                'app' => 'external_trashbin',
                'source' => $sourcePath,
                'destination' => $destination,
            ]);
            return $moved;
        }

        // rename() is unsupported or refused (cross-device move, permissions,
        // ...). Fall back to copy + remove. IStorage::copy() recurses into
        // directory contents, so files and directories share this path.
        $copied = $this->attemptWithRetry(
            fn () => $this->storage->copy($sourcePath, $destination),
            'copy',
            $sourcePath,
            $destination,
        );

        // copy() returning true is not proof: object stores and some network
        // backends report success for a copy that never materialised. Verify
        // before touching the original.
        if (!$copied || !$this->copyLanded($sourcePath, $destination)) {
            $this->logger->error('External recycle copy fallback did not produce a usable copy; blocking the delete', [
                'app' => 'external_trashbin',
                'source' => $sourcePath,
                'destination' => $destination,
                'copyReportedSuccess' => $copied,
            ]);
            $this->discardUnusableCopy($sourcePath, $destination);
            return null;
        }

        try {
            if ($this->remove($sourcePath)) {
                return $moved;
            }
        } catch (\Throwable $e) {
            $this->logger->error('External recycle copy landed but removing the original raised an error; keeping the recycle copy and blocking the delete', [
                'app' => 'external_trashbin',
                'source' => $sourcePath,
                'destination' => $destination,
                'exception' => $e,
            ]);
            return null;
        }

        // The copy is verified, so it is never discarded here: removal may have
        // already deleted part of the source. The delete is refused instead and
        // the copy is left behind for manual recovery. It carries no trash
        // record, so retention expiry will not pick it up either.
        $this->logger->error('External recycle copy landed but the original could not be removed; the recycle copy is kept unregistered and the delete is blocked', [
            'app' => 'external_trashbin',
            'source' => $sourcePath,
            'destination' => $destination,
        ]);
        return null;
    }

    /**
     * Run $attempt up to MAX_ATTEMPTS times with linear backoff, logging every
     * failed attempt (thrown exception or falsy return) instead of only the
     * first one. Gives up early once the source is gone, because no retry can
     * recover from that and the caller has to inspect the destination.
     */
    private function attemptWithRetry(callable $attempt, string $operation, string $sourcePath, string $destination): bool {
        for ($attemptNo = 1; $attemptNo <= self::MAX_ATTEMPTS; $attemptNo++) {
            try {
                if ($attempt()) {
                    return true;
                }
                $this->logger->warning('External recycle operation returned false', [
                    'app' => 'external_trashbin',
                    'operation' => $operation,
                    'source' => $sourcePath,
                    'destination' => $destination,
                    'attempt' => $attemptNo,
                ]);
            } catch (\Throwable $e) {
                $this->logger->warning('External recycle operation failed', [
                    'app' => 'external_trashbin',
                    'operation' => $operation,
                    'source' => $sourcePath,
                    'destination' => $destination,
                    'attempt' => $attemptNo,
                    'exception' => $e,
                ]);
            }

            if ($attemptNo === self::MAX_ATTEMPTS || !$this->exists($sourcePath)) {
                break;
            }
            usleep(self::RETRY_DELAY_US * $attemptNo);
        }

        return false;
    }

    /** True only when the item provably left the source and reached the destination. */
    private function sourceMovedToDestination(string $sourcePath, string $destination): bool {
        return !$this->exists($sourcePath) && $this->exists($destination);
    }

    /** Verify that a copy() that reported success actually produced the item. */
    private function copyLanded(string $sourcePath, string $destination): bool {
        try {
            if ($this->storage->is_dir($sourcePath)) {
                return $this->storage->is_dir($destination);
            }
            if (!$this->storage->file_exists($destination)) {
                return false;
            }

            $sourceSize = $this->storage->filesize($sourcePath);
            // An unreadable source size cannot disprove the copy; the size
            // check only has to catch truncated or missing copies.
            return $sourceSize === false || $this->storage->filesize($destination) === $sourceSize;
        } catch (\Throwable $e) {
            $this->logger->warning('Unable to verify the external recycle copy', [
                'app' => 'external_trashbin',
                'source' => $sourcePath,
                'destination' => $destination,
                'exception' => $e,
            ]);
            return false;
        }
    }

    /**
     * Remove a file or directory. Every Nextcloud storage deletes directory
     * contents in rmdir() itself. When rmdir() refuses (a locked entry), the
     * source must stay whole: walking it entry by entry would empty most of
     * the folder and still fail.
     */
    private function remove(string $path): bool {
        return $this->storage->is_dir($path) ? $this->storage->rmdir($path) : $this->storage->unlink($path);
    }

    /**
     * Drop a copy that cannot be used - but only while the original is still
     * in place. If the source is gone, the destination is the only remaining
     * copy of the payload and must survive.
     */
    private function discardUnusableCopy(string $sourcePath, string $destination): void {
        if (!$this->exists($sourcePath)) {
            $this->logger->error('Keeping the external recycle copy: the original is no longer present', [
                'app' => 'external_trashbin',
                'source' => $sourcePath,
                'destination' => $destination,
            ]);
            return;
        }

        try {
            if ($this->storage->is_dir($destination)) {
                $removed = $this->storage->rmdir($destination);
            } elseif ($this->storage->file_exists($destination)) {
                $removed = $this->storage->unlink($destination);
            } else {
                return;
            }

            if (!$removed) {
                $this->logger->warning('Leftover external recycle copy could not be removed', [
                    'app' => 'external_trashbin',
                    'destination' => $destination,
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Leftover external recycle copy could not be removed', [
                'app' => 'external_trashbin',
                'destination' => $destination,
                'exception' => $e,
            ]);
        }
    }

    /** Existence check that treats an unreachable storage as "still there". */
    private function exists(string $path): bool {
        try {
            return (bool)$this->storage->file_exists($path);
        } catch (\Throwable $e) {
            $this->logger->warning('Unable to check whether an external item exists; assuming it is still there', [
                'app' => 'external_trashbin',
                'path' => $path,
                'exception' => $e,
            ]);
            return true;
        }
    }

    public function restore(string $recyclePath, string $originalPath): bool {
        if (!$this->ensureDirForPath($originalPath)) {
            return false;
        }

        try {
            return $this->storage->rename($recyclePath, $originalPath);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to roll external recycle item back', [
                'app' => 'external_trashbin',
                'recyclePath' => $recyclePath,
                'originalPath' => $originalPath,
                'exception' => $e,
            ]);
            return false;
        }
    }

    public static function isInRecycle(string $path): bool {
        $path = ltrim($path, '/');
        return $path === self::RECYCLE_ROOT || str_starts_with($path, self::RECYCLE_ROOT . '/');
    }

    public static function getRecyclePath(string $sourcePath, int $deletedAt): string {
        $sourcePath = ltrim($sourcePath, '/');
        return self::RECYCLE_ROOT . '/' . $deletedAt . '/' . $sourcePath;
    }

    /** @return array{string, int} */
    private function findAvailableDestination(string $sourcePath, int $deletedAt): array {
        for ($attempt = 0; $attempt < 10000; $attempt++, $deletedAt++) {
            $candidate = self::getRecyclePath($sourcePath, $deletedAt);
            if (!$this->storage->file_exists($candidate)) {
                return [$candidate, $deletedAt];
            }
        }

        throw new \RuntimeException('Unable to allocate a unique external trash timestamp');
    }

    private function ensureDirForPath(string $filePath): bool {
        $directory = dirname(ltrim($filePath, '/'));
        if ($directory === '.' || $directory === '') {
            return true;
        }

        $current = '';
        foreach (explode('/', $directory) as $part) {
            if ($part === '') {
                continue;
            }
            $current = $current === '' ? $part : $current . '/' . $part;
            // re-check: a parallel delete (sync clients run several at once) may have created it meanwhile
            if (!$this->storage->is_dir($current) && !$this->storage->mkdir($current) && !$this->storage->is_dir($current)) {
                return false;
            }
        }

        return true;
    }
}
