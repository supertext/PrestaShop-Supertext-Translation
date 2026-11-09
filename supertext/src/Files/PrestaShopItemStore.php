<?php

/**
 * @package     Supertext Translation for PrestaShop
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\PrestaShop\Files;

use Supertext\PrestaShop\Settings;
use Supertext\PrestaShop\Translation\EntityTranslator;
use Supertext\PrestaShop\Translation\EntityTypes;
use Supertext\PrestaShop\Translation\FieldPlanner;

/** The shop's products, categories, CMS pages and languages for FileExchange. */
final class PrestaShopItemStore implements ItemStore
{
    public function __construct(private readonly ?int $idShop = null)
    {
    }

    public function item(string $type, int $id, int $sourceId, int $targetId): ?array
    {
        if (!isset(EntityTypes::TYPES[$type])) {
            return null;
        }

        $item = EntityTranslator::fields($type, $id, $sourceId, $targetId, $this->idShop);

        return $item === null ? null : ['title' => $item['title'], 'fields' => $item['fields'], 'source' => $item['source'], 'target' => $item['target']];
    }

    public function languageById(int $id): ?array
    {
        $language = new \Language($id);

        return \Validate::isLoadedObject($language) ? self::describe($language) : null;
    }

    public function languageByIso(string $iso): ?array
    {
        $id = (int) \Language::getIdByIso($iso);

        return $id > 0 ? $this->languageById($id) : null;
    }

    public function save(string $type, int $id, int $sourceId, int $targetId, array $values, bool $overwrite): void
    {
        $item = EntityTranslator::fields($type, $id, $sourceId, $targetId, $this->idShop);

        if ($item === null) {
            throw new FileExchangeException(FileExchangeException::NOT_FOUND);
        }

        $planner = new FieldPlanner($item['fields'], $item['source'], $item['target'], $overwrite);

        EntityTranslator::write($item['object'], EntityTypes::get($type), $sourceId, $targetId, $values, $planner->shouldRewriteSlug(...));
    }

    /** @return array{id: int, iso: string, name: string, locale: string, mapped: string} */
    private static function describe(\Language $language): array
    {
        return [
            'id'     => (int) $language->id,
            'iso'    => (string) $language->iso_code,
            'name'   => (string) $language->name,
            'locale' => Settings::localeOf($language),
            'mapped' => Settings::languages()[(int) $language->id]['code'] ?? '',
        ];
    }
}
