<?php

declare(strict_types=1);

namespace Supertext\PrestaShop\Sdk\Files;

/**
 * One job read back from a translation file (an XLIFF file can hold several). Pass it to
 * TranslationFiles::importTranslations() together with the item's current segments.
 */
final class TranslationFile
{
    public const XLIFF = 'xliff';
    public const HTML = 'html';

    /**
     * @param string         $format one of the constants of this class
     * @param list<FileUnit> $units
     */
    public function __construct(
        public readonly string $format,
        public readonly string $plugin,
        public readonly string $reference,
        public readonly string $sourceLanguage,
        public readonly string $targetLanguage,
        public readonly array $units,
    ) {
    }
}
