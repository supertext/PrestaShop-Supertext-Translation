<?php

declare(strict_types=1);

namespace Supertext\PrestaShop\Sdk\Files;

/**
 * An element of the small XML parser in TranslationFiles.
 *
 * @internal
 */
final class XmlElement
{
    /** @var array<string, string> attributes by local name */
    public array $attrs = [];

    /** @var list<XmlElement|string> */
    public array $children = [];

    public function __construct(public readonly string $name)
    {
    }

    public function localName(): string
    {
        $colon = strrpos($this->name, ':');

        return $colon === false ? $this->name : substr($this->name, $colon + 1);
    }

    public function is(string $localName): bool
    {
        return $this->localName() === $localName;
    }
}
