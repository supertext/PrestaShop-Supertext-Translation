<?php

/**
 * @package     Supertext Translation for PrestaShop
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\PrestaShop\Files;

use Supertext\PrestaShop\Api\SupertextException;

/**
 * What the export and import of translation files need from the shop. PrestaShopItemStore is
 * the real one; the unit tests use a fake, so FileExchange runs without PrestaShop.
 *
 * A language is array{id: int, iso: string, name: string, locale: string, mapped: string}:
 * PrestaShop's id_lang and iso_code, its name, its locale (en-GB) and the Supertext language
 * chosen for it in the module settings ("" when none).
 */
interface ItemStore
{
    /**
     * One item in a source and a target language, or null when it doesn't exist.
     *
     * @return array{title: string, fields: array<string, array{html: bool, size: int}>, source: array<string, string>, target: array<string, string>}|null
     */
    public function item(string $type, int $id, int $sourceId, int $targetId): ?array;

    /** @return array{id: int, iso: string, name: string, locale: string, mapped: string}|null */
    public function languageById(int $id): ?array;

    /** @return array{id: int, iso: string, name: string, locale: string, mapped: string}|null */
    public function languageByIso(string $iso): ?array;

    /**
     * Writes translated field values into the item's target language with the same rules as
     * API translations (sanitized single-line fields, URL slug, validation).
     *
     * @param array<string, string> $values    field => text
     * @param bool                  $overwrite whether the URL slug may replace a translated one
     *
     * @throws SupertextException|\PrestaShopException when it can't be saved
     */
    public function save(string $type, int $id, int $sourceId, int $targetId, array $values, bool $overwrite): void;
}
