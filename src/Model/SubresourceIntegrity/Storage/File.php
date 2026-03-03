<?php

/**
 * Copyright Falcon Media. All rights reserved.
 * https://www.falconmedia.nl
 */

declare(strict_types=1);

namespace FalconMedia\CspSriLock\Model\SubresourceIntegrity\Storage;

use Magento\Csp\Model\SubresourceIntegrity\StorageInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Filesystem;
use Psr\Log\LoggerInterface;

/**
 * Safer filesystem-based SRI hash storage.
 *
 * Prevents corrupted JSON by:
 * - using an exclusive file lock during write
 * - writing to a temp file first
 * - replacing the target file via atomic rename
 */
class File implements StorageInterface
{
    private const FILENAME = 'sri-hashes.json';

    private Filesystem $filesystem;
    private LoggerInterface $logger;

    public function __construct(
        Filesystem $filesystem,
        LoggerInterface $logger
    ) {
        $this->filesystem = $filesystem;
        $this->logger     = $logger;
    }

    public function load(?string $context): ?string
    {
        try {
            $staticDir = $this->filesystem->getDirectoryRead(DirectoryList::STATIC_VIEW);
            $path      = $this->resolveFilePath($context);

            if (!$staticDir->isFile($path)) {
                return null;
            }

            return $staticDir->readFile($path);
        } catch (FileSystemException $exception) {
            $this->logger->critical($exception);

            return null;
        }
    }

    public function save(string $data, ?string $context): bool
    {
        $path     = $this->resolveFilePath($context);
        $tmpPath  = $path . '.tmp';
        $lockPath = $path . '.lock';

        try {
            $staticDir = $this->filesystem->getDirectoryWrite(DirectoryList::STATIC_VIEW);

            // Ensure context directory exists (e.g. "frontend" / "adminhtml")
            $dir = dirname($path);
            if ($dir !== '.' && !$staticDir->isExist($dir)) {
                $staticDir->create($dir);
            }

            $absoluteLockPath = $staticDir->getAbsolutePath($lockPath);
            $lockHandle       = @fopen($absoluteLockPath, 'c');

            if (!$lockHandle) {
                $this->logger->critical(sprintf('Unable to open SRI lock file: %s',
                    $absoluteLockPath));

                return false;
            }

            try {
                if (!flock($lockHandle, LOCK_EX)) {
                    $this->logger->critical(sprintf('Unable to acquire SRI lock: %s',
                        $absoluteLockPath));

                    return false;
                }

                // Write to temp file first to avoid truncating the target on partial writes.
                $staticDir->writeFile($tmpPath, $data, 'w');

                $absoluteTmp    = $staticDir->getAbsolutePath($tmpPath);
                $absoluteTarget = $staticDir->getAbsolutePath($path);

                // Atomic replace (on the same filesystem).
                if (!@rename($absoluteTmp, $absoluteTarget)) {
                    // Fallback attempt
                    @unlink($absoluteTarget);
                    if (!@rename($absoluteTmp, $absoluteTarget)) {
                        $this->logger->critical(sprintf(
                            'Unable to atomically replace SRI file. tmp=%s target=%s',
                            $absoluteTmp,
                            $absoluteTarget
                        ));

                        return false;
                    }
                }

                return true;
            } finally {
                @flock($lockHandle, LOCK_UN);
                @fclose($lockHandle);
            }
        } catch (\Throwable $exception) {
            $this->logger->critical($exception);

            return false;
        }
    }

    public function remove(?string $context): bool
    {
        try {
            $staticDir = $this->filesystem->getDirectoryWrite(DirectoryList::STATIC_VIEW);

            return $staticDir->delete($this->resolveFilePath($context));
        } catch (FileSystemException $exception) {
            $this->logger->critical($exception);

            return false;
        }
    }

    private function resolveFilePath(?string $context): string
    {
        return ($context ? $context . DIRECTORY_SEPARATOR : '') . self::FILENAME;
    }
}
