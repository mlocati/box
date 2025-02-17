<?php

declare(strict_types=1);

/*
 * This file is part of the box project.
 *
 * (c) Kevin Herrera <kevin@herrera.io>
 *     Théo Fidry <theo.fidry@gmail.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

/*
 * This file originates from https://github.com/paragonie/pharaoh.
 *
 * For maintenance reasons it had to be in-lined within Box. To simplify the
 * configuration for PHP-CS-Fixer, the original license is in-lined as follows:
 *
 * The MIT License (MIT)
 *
 * Copyright (c) 2015 - 2018 Paragon Initiative Enterprises
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all
 * copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 */

namespace KevinGH\Box\Pharaoh;

use KevinGH\Box\Console\Command\Extract;
use KevinGH\Box\Phar\CompressionAlgorithm;
use KevinGH\Box\Phar\PharMeta;
use OutOfBoundsException;
use ParagonIE\ConstantTime\Hex;
use Phar;
use RuntimeException;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use function file_exists;
use function getenv;
use function is_readable;
use function iter\mapKeys;
use function iter\toArrayWithKeys;
use function KevinGH\Box\FileSystem\make_tmp_dir;
use function KevinGH\Box\FileSystem\remove;
use function random_bytes;
use function sprintf;

// TODO: rename it to SafePhar

/**
 * @private
 *
 * Pharaoh is a wrapper around Phar. This is necessary because the Phar API is quite limited and will crash if say two
 * PHARs with the same alias are loaded.
 */
final class Pharaoh
{
    private static array $ALGORITHMS;
    private static string $stubfile;
    private static string $phpExecutable;

    private PharMeta $meta;
    private string $tmp;
    private string $file;
    private string $fileName;
    private array $compressionCount;

    /**
     * @var array<string, SplFileInfo>
     */
    private array $files;

    public function __construct(string $file)
    {
        $file = Path::canonicalize($file);

        if (!file_exists($file)) {
            throw InvalidPhar::fileNotFound($file);
        }

        if (!is_readable($file)) {
            throw InvalidPhar::fileNotReadable($file);
        }

        self::initAlgorithms();
        self::initStubFileName();

        $this->file = $file;
        $this->fileName = basename($file);

        $this->tmp = make_tmp_dir('HumbugBox', 'Pharaoh');

        self::dumpPhar($file, $this->tmp);
        [
            $this->meta,
            $this->files,
        ] = self::loadDumpedPharFiles($this->tmp);
    }

    public function __destruct()
    {
        unset($this->pharInfo);

        if (isset($this->phar)) {
            $path = $this->phar->getPath();
            unset($this->phar);

            Phar::unlinkArchive($path);
        }

        if (null !== $this->tmp) {
            remove($this->tmp);
        }
    }

    public function getTmp(): string
    {
        return $this->tmp;
    }

    public function getFile(): string
    {
        return $this->file;
    }

    public function getPubKeyContent(): ?string
    {
        return $this->meta->pubKeyContent;
    }

    public function hasPubKey(): bool
    {
        return null !== $this->getPubKeyContent();
    }

    public function getFileName(): string
    {
        return $this->fileName;
    }

    /**
     * @deprecated
     */
    public function equals(self $pharInfo): bool
    {
        return
            $pharInfo->getFilesCompressionCount() === $this->getFilesCompressionCount()
            && $pharInfo->getNormalizedMetadata() === $this->getNormalizedMetadata();
    }

    public function getCompression(): CompressionAlgorithm
    {
        return $this->meta->compression;
    }

    /**
     * @return array<string, positive-int|0> The number of files per compression algorithm label.
     */
    public function getFilesCompressionCount(): array
    {
        if (!isset($this->compressionCount)) {
            $this->compressionCount = self::calculateCompressionCount($this->meta->filesMeta);
        }

        return $this->compressionCount;
    }

    /**
     * @return array{'compression': CompressionAlgorithm, compressedSize: int}
     */
    public function getFileMeta(string $path): array
    {
        $meta = $this->meta->filesMeta[$path] ?? null;

        if (null === $meta) {
            throw new OutOfBoundsException(
                sprintf(
                    'No metadata found for the file "%s".',
                    $path,
                ),
            );
        }

        return $meta;
    }

    public function getVersion(): ?string
    {
        // TODO: review this fallback value
        return $this->meta->version ?? 'No information found';
    }

    public function getNormalizedMetadata(): ?string
    {
        return $this->meta->normalizedMetadata;
    }

    public function getSignature(): ?array
    {
        return $this->meta->signature;
    }

    public function getStubContent(): ?string
    {
        return $this->meta->stub;
    }

    /**
     * @return array<string, SplFileInfo>
     */
    public function getFiles(): array
    {
        return $this->files;
    }

    private static function initAlgorithms(): void
    {
        if (!isset(self::$ALGORITHMS)) {
            self::$ALGORITHMS = [];

            foreach (CompressionAlgorithm::cases() as $compressionAlgorithm) {
                self::$ALGORITHMS[$compressionAlgorithm->value] = $compressionAlgorithm->name;
            }
        }
    }

    private static function initStubFileName(): void
    {
        if (!isset(self::$stubfile)) {
            self::$stubfile = Hex::encode(random_bytes(12)).'.pharstub';
        }
    }

    private static function dumpPhar(string $file, string $tmp): void
    {
        $extractPharProcess = new Process([
            self::getPhpExecutable(),
            self::getBoxBin(),
            'extract',
            $file,
            $tmp,
            '--no-interaction',
            '--internal',
        ]);
        $extractPharProcess->run();

        if (false === $extractPharProcess->isSuccessful()) {
            throw new InvalidPhar(
                $extractPharProcess->getErrorOutput(),
                $extractPharProcess->getExitCode(),
                new ProcessFailedException($extractPharProcess),
            );
        }
    }

    /**
     * @return array{PharMeta, array<string, SplFileInfo>}
     */
    private static function loadDumpedPharFiles(string $tmp): array
    {
        $dumpedFiles = toArrayWithKeys(
            mapKeys(
                static fn (string $filePath) => Path::makeRelative($filePath, $tmp),
                Finder::create()
                    ->files()
                    ->ignoreDotFiles(false)
                    ->in($tmp),
            ),
        );

        $meta = PharMeta::fromJson($dumpedFiles[Extract::PHAR_META_PATH]->getContents());
        unset($dumpedFiles[Extract::PHAR_META_PATH]);

        return [$meta, $dumpedFiles];
    }

    private static function getPhpExecutable(): string
    {
        if (isset(self::$phpExecutable)) {
            return self::$phpExecutable;
        }

        $phpExecutable = (new PhpExecutableFinder())->find();

        if (false === $phpExecutable) {
            throw new RuntimeException('Could not find a PHP executable.');
        }

        self::$phpExecutable = $phpExecutable;

        return self::$phpExecutable;
    }

    private static function getBoxBin(): string
    {
        // TODO: move the constraint strings declaration in one place
        return getenv('BOX_BIN') ?: $_SERVER['SCRIPT_NAME'];
    }

    /**
     * @param array<string, array{'compression': CompressionAlgorithm, compressedSize: int}> $filesMeta
     */
    private static function calculateCompressionCount(array $filesMeta): array
    {
        $count = array_fill_keys(
            self::$ALGORITHMS,
            0,
        );

        foreach ($filesMeta as ['compression' => $compression]) {
            ++$count[$compression->name];
        }

        return $count;
    }
}
