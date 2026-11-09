<?php

declare(strict_types=1);

namespace Supertext\PrestaShop\Sdk\Files;

/**
 * The result of importing one current segment.
 */
final class ImportedSegment
{
    /** Translated, source unchanged since the export. */
    public const OK = 'ok';
    /** Translated, but the source changed since the export (or the file has no checksum): the integration decides. */
    public const SOURCE_CHANGED = 'source_changed';
    /** No translation in the file. */
    public const UNTRANSLATED = 'untranslated';
    /** The translation uses markup the current source doesn't have. */
    public const INVALID_MARKUP = 'invalid_markup';
    /** The file has no segment with this key. */
    public const MISSING = 'missing';

    /**
     * @param string      $status one of the constants of this class
     * @param string|null $text   the translation (plain text or HTML, like the segment) for ok and source_changed, else null
     */
    public function __construct(
        public readonly string $key,
        public readonly string $status,
        public readonly ?string $text,
    ) {
    }
}
