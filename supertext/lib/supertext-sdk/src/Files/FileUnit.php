<?php

declare(strict_types=1);

namespace Supertext\PrestaShop\Sdk\Files;

/**
 * One segment found in a translation file.
 */
final class FileUnit
{
    /**
     * @param string      $key      the segment's key
     * @param string|null $checksum the source's checksum at export time, or null when the file doesn't carry one
     */
    public function __construct(
        public readonly string $key,
        public readonly ?string $checksum,
    ) {
    }
}
