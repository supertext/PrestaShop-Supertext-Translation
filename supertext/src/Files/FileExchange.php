<?php

/**
 * @package     Supertext Translation for PrestaShop
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\PrestaShop\Files;

use Supertext\PrestaShop\Api\LanguageCodes;
use Supertext\PrestaShop\Api\SupertextException;
use Supertext\PrestaShop\Sdk\Files\FileJob;
use Supertext\PrestaShop\Sdk\Files\ImportedSegment;
use Supertext\PrestaShop\Sdk\Files\TranslationFile;
use Supertext\PrestaShop\Sdk\Files\TranslationFileError;
use Supertext\PrestaShop\Sdk\Files\TranslationFiles;
use Supertext\PrestaShop\Translation\EntityTypes;
use Supertext\PrestaShop\Translation\FieldPlanner;

/**
 * "Export for translation" and "Import translation": one product, category or CMS page as an
 * XLIFF 1.2 or HTML file (the Supertext SDK's TranslationFiles), and the translated file back
 * into the item's target language. The segments are the fields an API translation sends
 * (FieldPlanner, keyed by field name), and the import writes through the same save as API
 * translations. No PrestaShop dependencies (see ItemStore), so it is unit-tested on its own.
 *
 * Reference (stored in the file, read back on import): "<type>:<id>:<source iso>:<target iso>",
 * e.g. "product:42:en:fr", with PrestaShop's language iso codes.
 */
final class FileExchange
{
    /** The repository name, written into every file as the job's plugin. */
    public const PLUGIN = 'PrestaShop-Supertext-Translation';

    /** Uploads larger than this are refused before they are read. */
    public const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    public const XLIFF = 'xliff';
    public const HTML = 'html';

    public function __construct(private readonly ItemStore $store)
    {
    }

    public static function reference(string $type, int $id, string $sourceIso, string $targetIso): string
    {
        return $type . ':' . $id . ':' . $sourceIso . ':' . $targetIso;
    }

    /** @return array{type: string, id: int, source: string, target: string}|null null: not one of ours */
    public static function parseReference(string $reference): ?array
    {
        if (!preg_match('/^([a-z]+):([1-9][0-9]*):([A-Za-z-]{2,10}):([A-Za-z-]{2,10})$/D', $reference, $m) || !isset(EntityTypes::TYPES[$m[1]])) {
            return null;
        }

        return ['type' => $m[1], 'id' => (int) $m[2], 'source' => $m[3], 'target' => $m[4]];
    }

    /**
     * The Supertext code of a shop language: its Supertext language (setting), else the SDK's match
     * or default; a language Supertext doesn't know keeps its locale with "-" (en_IE → en-IE), so a
     * file can still go to any translator.
     *
     * @param array{locale: string, mapped: string, iso: string} $language
     */
    public static function languageCode(array $language, bool $target): string
    {
        try {
            return $target ? LanguageCodes::target($language['locale'], $language['mapped']) : LanguageCodes::source($language['locale'], $language['mapped']);
        } catch (SupertextException) {
            return str_replace('_', '-', $language['locale'] !== '' ? $language['locale'] : $language['iso']);
        }
    }

    /** @throws FileExchangeException too_large */
    public static function checkSize(int $bytes): void
    {
        if ($bytes > self::MAX_UPLOAD_BYTES) {
            throw new FileExchangeException(FileExchangeException::TOO_LARGE);
        }
    }

    /**
     * The file for one item: XLIFF with one job per target language, or HTML for exactly one.
     *
     * @param list<int> $targetIds
     *
     * @return array{name: string, contentType: string, content: string}
     *
     * @throws FileExchangeException
     */
    public function export(string $type, int $id, int $sourceId, array $targetIds, string $format): array
    {
        $source = $this->store->languageById($sourceId);

        if ($source === null) {
            throw new FileExchangeException(FileExchangeException::NO_LANGUAGE, ['%language%' => (string) $sourceId]);
        }

        $targetIds = array_values(array_unique(array_filter($targetIds, static fn (int $t): bool => $t !== $sourceId)));

        if ($targetIds === [] || ($format === self::HTML && \count($targetIds) !== 1)) {
            throw new FileExchangeException(FileExchangeException::HTML_ONE_LANGUAGE);
        }

        $jobs  = [];
        $codes = [];

        foreach ($targetIds as $targetId) {
            $target = $this->store->languageById($targetId);

            if ($target === null) {
                throw new FileExchangeException(FileExchangeException::NO_LANGUAGE, ['%language%' => (string) $targetId]);
            }

            $item = $this->store->item($type, $id, $sourceId, $targetId);

            if ($item === null) {
                throw new FileExchangeException(FileExchangeException::NOT_FOUND);
            }

            // Every field with source text, translated or not: the import decides what is written.
            $segments = (new FieldPlanner($item['fields'], $item['source'], $item['target'], true))->fileSegments();

            if ($segments === []) {
                throw new FileExchangeException(FileExchangeException::NOTHING);
            }

            $codes[] = self::languageCode($target, true);
            $jobs[]  = new FileJob(
                self::PLUGIN,
                self::reference($type, $id, $source['iso'], $target['iso']),
                self::languageCode($source, false),
                $codes[\count($codes) - 1],
                $segments,
            );
        }

        $base = 'prestashop-' . $type . '-' . $id . (\count($codes) === 1 ? '-' . self::fileNamePart($codes[0]) : '');

        return $format === self::HTML
            ? ['name' => $base . '.html', 'contentType' => 'text/html; charset=utf-8', 'content' => TranslationFiles::exportHtml($jobs[0])]
            : ['name' => $base . '.xlf', 'contentType' => 'application/x-xliff+xml', 'content' => TranslationFiles::exportXliff($jobs)];
    }

    /**
     * Imports a translated file: one result per job in it.
     *
     * Written: "ok" segments, and "source_changed" ones with $includeChanged; of those, fields that
     * are already translated only with $overwrite (otherwise counted as kept), as for API translations.
     *
     * @param \Closure(string): bool $canEdit whether the employee may edit items of a type
     *
     * @return list<array{reference: string, title: string, language: string, error: array{code: string, parameters: array<string, string|int>}|null, counts: array{ok: int, changed: int, untranslated: int, invalid: int, missing: int, kept: int}}>
     *
     * @throws FileExchangeException invalid_file, missing_metadata, too_large: the file itself
     */
    public function import(string $content, bool $includeChanged, bool $overwrite, \Closure $canEdit): array
    {
        self::checkSize(\strlen($content));

        try {
            $files = TranslationFiles::read($content);
        } catch (TranslationFileError $e) {
            throw new FileExchangeException(match ($e->errorCode) {
                TranslationFileError::MISSING_METADATA => FileExchangeException::MISSING_METADATA,
                TranslationFileError::TOO_LARGE        => FileExchangeException::TOO_LARGE,
                default                                => FileExchangeException::INVALID_FILE,
            });
        }

        $results = [];

        foreach ($files as $file) {
            $results[] = $this->importJob($file, $includeChanged, $overwrite, $canEdit);
        }

        return $results;
    }

    /**
     * @param \Closure(string): bool $canEdit
     *
     * @return array{reference: string, title: string, language: string, error: array{code: string, parameters: array<string, string|int>}|null, counts: array{ok: int, changed: int, untranslated: int, invalid: int, missing: int, kept: int}}
     */
    private function importJob(TranslationFile $file, bool $includeChanged, bool $overwrite, \Closure $canEdit): array
    {
        $result = [
            'reference' => $file->reference,
            'title'     => $file->reference,
            'language'  => $file->targetLanguage,
            'error'     => null,
            'counts'    => ['ok' => 0, 'changed' => 0, 'untranslated' => 0, 'invalid' => 0, 'missing' => 0, 'kept' => 0],
        ];
        $fail = static function (string $code, array $parameters = []) use (&$result): array {
            $result['error'] = ['code' => $code, 'parameters' => $parameters];

            return $result;
        };

        if ($file->plugin !== self::PLUGIN) {
            return $fail('other_system', ['%plugin%' => $file->plugin]);
        }

        $ref = self::parseReference($file->reference);

        if ($ref === null) {
            return $fail(FileExchangeException::NOT_FOUND);
        }

        $source = $this->store->languageByIso($ref['source']);
        $target = $this->store->languageByIso($ref['target']);

        if ($target === null || $source === null) {
            return $fail(FileExchangeException::NO_LANGUAGE, ['%language%' => $target === null ? $ref['target'] : $ref['source']]);
        }

        $result['language'] = $target['name'];

        if (!$canEdit($ref['type'])) {
            return $fail('not_allowed');
        }

        $item = $this->store->item($ref['type'], $ref['id'], $source['id'], $target['id']);

        if ($item === null) {
            return $fail(FileExchangeException::NOT_FOUND);
        }

        $result['title'] = $item['title'] !== '' ? $item['title'] : '#' . $ref['id'];
        $current         = (new FieldPlanner($item['fields'], $item['source'], $item['target'], true))->fileSegments();
        $values          = [];

        foreach (TranslationFiles::importTranslations($file, $current) as $segment) {
            switch ($segment->status) {
                case ImportedSegment::OK:
                    $values[$segment->key] = (string) $segment->text;
                    break;
                case ImportedSegment::SOURCE_CHANGED:
                    if ($includeChanged) {
                        $values[$segment->key] = (string) $segment->text;
                    } else {
                        ++$result['counts']['changed'];
                    }
                    break;
                case ImportedSegment::UNTRANSLATED:
                    ++$result['counts']['untranslated'];
                    break;
                case ImportedSegment::INVALID_MARKUP:
                    ++$result['counts']['invalid'];
                    break;
                default:
                    ++$result['counts']['missing'];
            }
        }

        $write = (new FieldPlanner($item['fields'], $item['source'], $item['target'], $overwrite))->fit($values);

        $result['counts']['kept'] = \count(array_filter(array_keys($values), static fn (string $field): bool => !isset($write[$field]) && trim($values[$field]) !== ''));

        if ($write !== []) {
            try {
                $this->store->save($ref['type'], $ref['id'], $source['id'], $target['id'], $write, $overwrite);
            } catch (SupertextException $e) {
                // The module's own message (template), translated by the controller.
                return $fail('message:' . $e->template(), $e->parameters());
            } catch (\Exception $e) {
                return $fail('save_failed', ['%error%' => $e->getMessage()]);
            }
        }

        $result['counts']['ok'] = \count($write);

        return $result;
    }

    private static function fileNamePart(string $code): string
    {
        return (string) preg_replace('/[^A-Za-z0-9-]+/', '-', $code);
    }
}
