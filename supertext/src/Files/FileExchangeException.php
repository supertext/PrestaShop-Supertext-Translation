<?php

/**
 * @package     Supertext Translation for PrestaShop
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\PrestaShop\Files;

/**
 * An export or import that can't go ahead. $errorCode says which; the controller shows the
 * message for it in the employee's language, with $parameters for its %placeholders%.
 */
final class FileExchangeException extends \RuntimeException
{
    public const NOTHING = 'nothing';
    public const NOT_FOUND = 'not_found';
    public const NO_LANGUAGE = 'no_language';
    public const HTML_ONE_LANGUAGE = 'html_one_language';
    public const INVALID_FILE = 'invalid_file';
    public const MISSING_METADATA = 'missing_metadata';
    public const TOO_LARGE = 'too_large';

    /** @param array<string, string|int> $parameters e.g. ['%language%' => 'fr'] */
    public function __construct(
        public readonly string $errorCode,
        public readonly array $parameters = [],
    ) {
        parent::__construct($errorCode . ($parameters !== [] ? ' ' . json_encode($parameters, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : ''));
    }
}
