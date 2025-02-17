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

namespace KevinGH\Box\RequirementChecker;

/**
 * @private
 */
final class Requirement
{
    public function __construct(
        private readonly string $type,
        private readonly string $condition,
        private readonly string $message,
        private readonly string $helpMessage,
    ) {
    }

    public static function forPHP(string $requiredPhpVersion, ?string $packageName): self
    {
        return new self(
            'php',
            $requiredPhpVersion,
            null === $packageName
                ? sprintf(
                    'The application requires a version matching "%s".',
                    $requiredPhpVersion,
                )
                : sprintf(
                    'The package "%s" requires a version matching "%s".',
                    $packageName,
                    $requiredPhpVersion,
                ),
            null === $packageName
                ? sprintf(
                    'The application requires a version matching "%s".',
                    $requiredPhpVersion,
                )
                : sprintf(
                    'The package "%s" requires a version matching "%s".',
                    $packageName,
                    $requiredPhpVersion,
                ),
        );
    }

    public static function forRequiredExtension(string $extension, ?string $packageName): self
    {
        return new self(
            'extension',
            $extension,
            null === $packageName
                ? sprintf(
                    'The application requires the extension "%s".',
                    $extension,
                )
                : sprintf(
                    'The package "%s" requires the extension "%s".',
                    $packageName,
                    $extension,
                ),
            null === $packageName
                ? sprintf(
                    'The application requires the extension "%s". You either need to enable it or request the application to be shipped with a polyfill for this extension.',
                    $extension,
                )
                : sprintf(
                    'The package "%s" requires the extension "%s". You either need to enable it or request the application to be shipped with a polyfill for this extension.',
                    $packageName,
                    $extension,
                ),
        );
    }

    public static function forConflictingExtension(string $extension, ?string $packageName): self
    {
        return new self(
            'extension-conflict',
            $extension,
            null === $packageName
                ? sprintf(
                    'The application conflicts with the extension "%s".',
                    $extension,
                )
                : sprintf(
                    'The package "%s" conflicts with the extension "%s".',
                    $packageName,
                    $extension,
                ),
            null === $packageName
                ? sprintf(
                    'The application conflicts with the extension "%s". You need to disable it in order to run this application.',
                    $extension,
                )
                : sprintf(
                    'The package "%s" conflicts with the extension "%s". You need to disable it in order to run this application.',
                    $packageName,
                    $extension,
                ),
        );
    }

    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'condition' => $this->condition,
            'message' => $this->message,
            'helpMessage' => $this->helpMessage,
        ];
    }
}
