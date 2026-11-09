<?php

declare(strict_types=1);

namespace Supertext\PrestaShop\Sdk\Files;

/**
 * One text of a job in a translation file: plain text, or an HTML fragment when `$html` is true.
 */
final class FileSegment
{
    /**
     * @param string $key  the integration's own key for the text (unique in the job), e.g. `title` or `body.3`
     * @param string $text the text, or an HTML fragment when `$html` is true
     */
    public function __construct(
        public readonly string $key,
        public readonly string $text,
        public readonly bool $html = false,
    ) {
    }
}
