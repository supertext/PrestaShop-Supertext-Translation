<?php

declare(strict_types=1);

namespace Supertext\PrestaShop\Sdk\Files;

/**
 * One item to export: its segments plus what is needed to find the item again on import.
 */
final class FileJob
{
    /**
     * @param string            $plugin         the integration's name (its repository name), e.g. `Contao-Supertext-Translation`
     * @param string            $reference      what the integration needs to find the item again, e.g. `page:12` (opaque to the SDK)
     * @param string            $sourceLanguage
     * @param string            $targetLanguage
     * @param list<FileSegment> $segments
     */
    public function __construct(
        public readonly string $plugin,
        public readonly string $reference,
        public readonly string $sourceLanguage,
        public readonly string $targetLanguage,
        public readonly array $segments,
    ) {
    }
}
