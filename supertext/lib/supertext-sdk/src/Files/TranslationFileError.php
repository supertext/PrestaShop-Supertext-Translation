<?php

declare(strict_types=1);

namespace Supertext\PrestaShop\Sdk\Files;

/**
 * A job that can't be exported or a file that can't be read.
 *
 * `$errorCode` is one of the constants below (fixed strings, the same in every Supertext SDK), so an
 * integration can show its own, translated message for it. The English `getMessage()` is safe to show or log.
 */
final class TranslationFileError extends \RuntimeException
{
    /** Export: no jobs, an empty or invalid metadata field or key, duplicate keys, or only blank segments. */
    public const INVALID_JOB = 'invalid_job';
    /** Read: not XLIFF or HTML, not well-formed, a DTD; import: a file not returned by read(). */
    public const INVALID_FILE = 'invalid_file';
    /** Read: the file lacks the plugin, reference, languages or segment metadata. */
    public const MISSING_METADATA = 'missing_metadata';
    /** Read: the file is larger than TranslationFiles::MAX_FILE_CHARACTERS. */
    public const TOO_LARGE = 'too_large';

    /**
     * @param string $errorCode one of the constants of this class
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
    ) {
        parent::__construct($message);
    }
}
