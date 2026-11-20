<?php

/**
 * SPDX-FileCopyrightText: 2026 Manuel Buchner and contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\External_Trashbin\Tests;

use OCA\External_Trashbin\RecycleManager;
use OCP\Files\Storage\IStorage;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Runs RecycleManager against an in-memory storage whose failures are scripted,
 * covering the paths that decide whether user data is removed.
 */
final class RecycleManagerTest extends TestCase {
    /** @var array<string, int|null> path => file size, null for directories */
    private array $fs = [];
    private bool $renameWorks = true;
    private bool $copyLands = true;
    private bool $rmdirWorks = true;
    /** Paths whose mkdir() loses to a parallel request: it fails, but the directory exists afterwards. */
    private array $mkdirRaces = [];
    /** Real directories backing opendir() handles; removed after each test. */
    private array $tmpDirs = [];

    protected function setUp(): void {
        $this->fs = ['Team' => null, 'Team/Doc' => null, 'Team/Doc/a.txt' => 10, 'Team/Doc/b.txt' => 20];
    }

    protected function tearDown(): void {
        foreach ($this->tmpDirs as $dir) {
            array_map('unlink', glob($dir . '/*') ?: []);
            rmdir($dir);
        }
    }

    public function testCopyFallbackLeavesExactlyOneCompleteCopy(): void {
        $this->renameWorks = false;

        $result = $this->manager()->moveToRecycle('Team/Doc', 100);

        self::assertSame(['path' => '.recycle/100/Team/Doc', 'deletedAt' => 100], $result);
        self::assertSame(20, $this->fs['.recycle/100/Team/Doc/b.txt'] ?? null);
        self::assertArrayNotHasKey('Team/Doc', $this->fs);
    }

    public function testCopyThatNeverLandedKeepsTheSource(): void {
        $this->renameWorks = false;
        $this->copyLands = false;

        self::assertNull($this->manager()->moveToRecycle('Team/Doc/a.txt', 100));
        self::assertSame(10, $this->fs['Team/Doc/a.txt']);
    }

    public function testRefusedSourceRemovalKeepsSourceWholeAndCopyKept(): void {
        $this->renameWorks = false;
        $this->rmdirWorks = false; // e.g. a locked file inside the folder

        self::assertNull($this->manager()->moveToRecycle('Team/Doc', 100));
        self::assertSame(10, $this->fs['Team/Doc/a.txt'] ?? null, 'source must not be half-emptied');
        self::assertSame(20, $this->fs['Team/Doc/b.txt'] ?? null);
        self::assertSame(10, $this->fs['.recycle/100/Team/Doc/a.txt'] ?? null, 'verified copy must survive');
    }

    public function testParallelRequestCreatingTheRecycleDirIsNotAFailure(): void {
        $this->mkdirRaces = ['.recycle/100' => true];

        self::assertNotNull($this->manager()->moveToRecycle('Team/Doc/a.txt', 100));
        self::assertSame(10, $this->fs['.recycle/100/Team/Doc/a.txt'] ?? null);
    }

    private function manager(): RecycleManager {
        $s = $this->createStub(IStorage::class);
        $s->method('file_exists')->willReturnCallback(fn (string $p) => array_key_exists($p, $this->fs));
        $s->method('is_dir')->willReturnCallback(fn (string $p) => array_key_exists($p, $this->fs) && $this->fs[$p] === null);
        $s->method('filesize')->willReturnCallback(fn (string $p) => $this->fs[$p] ?? false);
        $s->method('mkdir')->willReturnCallback(function (string $p) {
            $this->fs[$p] = null;
            return !isset($this->mkdirRaces[$p]);
        });
        $s->method('rename')->willReturnCallback(fn (string $from, string $to) => $this->renameWorks && $this->move($from, $to, true));
        $s->method('copy')->willReturnCallback(fn (string $from, string $to) => $this->copyLands ? $this->move($from, $to, false) : true);
        $s->method('unlink')->willReturnCallback(function (string $p) {
            unset($this->fs[$p]);
            return true;
        });
        $s->method('rmdir')->willReturnCallback(function (string $p) {
            if (!$this->rmdirWorks) {
                return false;
            }
            foreach ($this->subtree($p) as $path) {
                unset($this->fs[$path]);
            }
            return true;
        });
        // A real directory handle listing the direct children, so a code path
        // that walks a folder entry by entry really runs.
        $s->method('opendir')->willReturnCallback(function (string $p) {
            $dir = $this->tmpDirs[] = sys_get_temp_dir() . '/etb-' . bin2hex(random_bytes(4));
            mkdir($dir);
            foreach ($this->subtree($p) as $path) {
                if ($path !== $p && !str_contains(substr($path, strlen($p) + 1), '/')) {
                    touch($dir . '/' . basename($path));
                }
            }
            return opendir($dir);
        });

        return new RecycleManager($s, new NullLogger());
    }

    private function move(string $from, string $to, bool $removeSource): bool {
        foreach ($this->subtree($from) as $path) {
            $this->fs[$to . substr($path, strlen($from))] = $this->fs[$path];
            if ($removeSource) {
                unset($this->fs[$path]);
            }
        }
        return true;
    }

    /** @return string[] */
    private function subtree(string $root): array {
        return array_values(array_filter(
            array_keys($this->fs),
            static fn (string $p) => $p === $root || str_starts_with($p, $root . '/'),
        ));
    }
}
