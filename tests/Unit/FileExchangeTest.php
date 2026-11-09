<?php

namespace Supertext\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Supertext\PrestaShop\Files\FileExchange;
use Supertext\PrestaShop\Files\FileExchangeException;
use Supertext\PrestaShop\Files\ItemStore;
use Supertext\PrestaShop\Sdk\Files\FileJob;
use Supertext\PrestaShop\Sdk\Files\FileSegment;
use Supertext\PrestaShop\Sdk\Files\TranslationFiles;

final class FileExchangeTest extends TestCase
{
    /** The French description with the source's inline codes. */
    private const DESCRIPTION = '<bpt id="1">&lt;p&gt;</bpt>Fait à <bpt id="2">&lt;strong&gt;</bpt>Berne<ept id="2">&lt;/strong&gt;</ept>.<ept id="1">&lt;/p&gt;</ept>';

    private FakeItemStore $store;
    private FileExchange $files;

    protected function setUp(): void
    {
        $this->store = new FakeItemStore();
        $this->files = new FileExchange($this->store);
    }

    public function testReferenceRoundTrip(): void
    {
        $reference = FileExchange::reference('product', 42, 'en', 'fr');

        self::assertSame('product:42:en:fr', $reference);
        self::assertSame(['type' => 'product', 'id' => 42, 'source' => 'en', 'target' => 'fr'], FileExchange::parseReference($reference));
        self::assertNull(FileExchange::parseReference('page:42:en:fr'), 'unknown type');
        self::assertNull(FileExchange::parseReference('product:0:en:fr'));
        self::assertNull(FileExchange::parseReference('product:42:en'));
    }

    public function testLanguageCodesUseTheMappingAndFallBackToTheLocale(): void
    {
        self::assertSame('de-CH', FileExchange::languageCode(['locale' => 'de-DE', 'mapped' => 'de-CH', 'iso' => 'de'], true));
        self::assertSame('de', FileExchange::languageCode(['locale' => 'de-DE', 'mapped' => 'de-CH', 'iso' => 'de'], false));
        self::assertSame('fr-FR', FileExchange::languageCode(['locale' => 'fr-FR', 'mapped' => '', 'iso' => 'fr'], true));
        // Unmapped and unknown to Supertext: the locale, so the export still works.
        self::assertSame('en-IE', FileExchange::languageCode(['locale' => 'en_IE', 'mapped' => '', 'iso' => 'ie'], true));
    }

    public function testExportXliffHasTheCollectorsKeysAndTheMappedLanguages(): void
    {
        $file = $this->files->export('product', 7, 1, [2, 3], FileExchange::XLIFF);

        self::assertSame('prestashop-product-7.xlf', $file['name']);
        self::assertSame('application/x-xliff+xml', $file['contentType']);

        $jobs = TranslationFiles::read($file['content']);
        self::assertCount(2, $jobs);
        self::assertSame(FileExchange::PLUGIN, $jobs[0]->plugin);
        self::assertSame('product:7:en:fr', $jobs[0]->reference);
        self::assertSame('en', $jobs[0]->sourceLanguage);
        self::assertSame('fr-CH', $jobs[0]->targetLanguage);
        self::assertSame('en-IE', $jobs[1]->targetLanguage);
        // Every field with source text (even one already translated), keyed by field name.
        self::assertSame(['name', 'description', 'meta_title'], array_map(static fn ($u) => $u->key, $jobs[0]->units));
    }

    public function testExportHtmlNeedsExactlyOneLanguage(): void
    {
        $file = $this->files->export('product', 7, 1, [2], FileExchange::HTML);

        self::assertSame('prestashop-product-7-fr-CH.html', $file['name']);
        self::assertSame('text/html; charset=utf-8', $file['contentType']);
        self::assertStringContainsString('data-st-id', $file['content']);

        $this->expectExceptionObject(new FileExchangeException(FileExchangeException::HTML_ONE_LANGUAGE));
        $this->files->export('product', 7, 1, [2, 3], FileExchange::HTML);
    }

    public function testNothingToExport(): void
    {
        $this->store->items['product:8'] = ['title' => '', 'fields' => FakeItemStore::FIELDS, 'source' => ['name' => '  '], 'target' => []];

        try {
            $this->files->export('product', 8, 1, [2], FileExchange::XLIFF);
            self::fail('no exception');
        } catch (FileExchangeException $e) {
            self::assertSame(FileExchangeException::NOTHING, $e->errorCode);
        }
    }

    public function testImportWritesOkSegmentsThroughTheStore(): void
    {
        $result = $this->import($this->translated(['name' => 'Boîte', 'description' => self::DESCRIPTION, 'meta_title' => 'Pralinés']));

        self::assertNull($result['error']);
        self::assertSame('Praline box', $result['title']);
        self::assertSame('French', $result['language']);
        self::assertSame(2, $result['counts']['ok']);
        // meta_title is already translated in French: kept unless "overwrite".
        self::assertSame(1, $result['counts']['kept']);
        self::assertSame([['product', 7, 1, 2, ['name' => 'Boîte', 'description' => '<p>Fait à <strong>Berne</strong>.</p>'], false]], $this->store->saved);
    }

    public function testImportWithOverwriteReplacesTranslatedFields(): void
    {
        $result = $this->import($this->translated(['name' => 'Boîte', 'description' => self::DESCRIPTION, 'meta_title' => 'Pralinés']), overwrite: true);

        self::assertSame(3, $result['counts']['ok']);
        self::assertSame('Pralinés', $this->store->saved[0][4]['meta_title']);
    }

    public function testSourceChangedOnlyWithTheOption(): void
    {
        $file = $this->translated(['name' => 'Boîte', 'description' => self::DESCRIPTION]);
        $this->store->items['product:7']['source']['name']  = 'Chocolate box';
        $this->store->items['product:7']['target']['name'] = '';

        $result = $this->import($file);
        self::assertSame(1, $result['counts']['changed']);
        self::assertArrayNotHasKey('name', $this->store->saved[0][4]);

        $this->store->saved = [];
        $result = $this->import($file, includeChanged: true);
        self::assertSame(0, $result['counts']['changed']);
        self::assertSame('Boîte', $this->store->saved[0][4]['name']);
    }

    public function testUntranslatedInvalidAndMissingAreNotWritten(): void
    {
        // description: a code the source doesn't have → invalid; name: no target → untranslated.
        $xliff = $this->translated(['description' => 'Berne<bpt id="9">&lt;b&gt;</bpt>']);
        // meta_title removed from the file → missing.
        $xliff = (string) preg_replace('#<trans-unit id="meta_title".*?</trans-unit>#s', '', $xliff);

        $result = $this->import($xliff, overwrite: true);

        self::assertSame(0, $result['counts']['ok']);
        self::assertSame(1, $result['counts']['untranslated']);
        self::assertSame(1, $result['counts']['invalid']);
        self::assertSame(1, $result['counts']['missing']);
        self::assertSame([], $this->store->saved);
    }

    public function testFileOfAnotherSystem(): void
    {
        $content = TranslationFiles::exportXliff([new FileJob('Contao-Supertext-Translation', 'page:1', 'de', 'fr-CH', [new FileSegment('title', 'Hallo')])]);
        $results = $this->files->import($content, false, false, static fn (): bool => true);

        self::assertSame(['code' => 'other_system', 'parameters' => ['%plugin%' => 'Contao-Supertext-Translation']], $results[0]['error']);
    }

    public function testUnknownReferenceMissingItemAndMissingLanguage(): void
    {
        $job = static fn (string $reference): string => TranslationFiles::exportXliff([new FileJob(FileExchange::PLUGIN, $reference, 'en', 'fr', [new FileSegment('name', 'Praline box')])]);

        self::assertSame('not_found', $this->files->import($job('order:7'), false, false, static fn (): bool => true)[0]['error']['code']);
        self::assertSame('not_found', $this->files->import($job('product:99:en:fr'), false, false, static fn (): bool => true)[0]['error']['code']);

        $error = $this->files->import($job('product:7:en:nl'), false, false, static fn (): bool => true)[0]['error'];
        self::assertSame(['code' => 'no_language', 'parameters' => ['%language%' => 'nl']], $error);

        self::assertSame('not_allowed', $this->files->import($job('product:7:en:fr'), false, false, static fn (): bool => false)[0]['error']['code']);
        self::assertSame([], $this->store->saved);
    }

    public function testInvalidFilesAndSize(): void
    {
        foreach (['<html><head><title>x</title></head><body><p>no file</p></body></html>' => FileExchangeException::MISSING_METADATA, '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a "b">]><x/>' => FileExchangeException::INVALID_FILE] as $content => $code) {
            try {
                $this->files->import($content, false, false, static fn (): bool => true);
                self::fail('no exception for ' . $content);
            } catch (FileExchangeException $e) {
                self::assertSame($code, $e->errorCode, $content);
            }
        }

        FileExchange::checkSize(FileExchange::MAX_UPLOAD_BYTES);
        $this->expectExceptionObject(new FileExchangeException(FileExchangeException::TOO_LARGE));
        FileExchange::checkSize(FileExchange::MAX_UPLOAD_BYTES + 1);
    }

    /**
     * The exported XLIFF for product 7 into French, with <target>s added.
     *
     * @param array<string, string> $targets key => target content (XLIFF, with the source's inline codes)
     */
    private function translated(array $targets): string
    {
        $xliff = $this->files->export('product', 7, 1, [2], FileExchange::XLIFF)['content'];

        foreach ($targets as $key => $target) {
            $xliff = (string) preg_replace_callback('#(<trans-unit id="' . $key . '"[^>]*>\s*<source>.*?</source>)#s', static fn (array $m): string => $m[1] . '<target>' . $target . '</target>', $xliff);
        }

        return $xliff;
    }

    /** @return array<string, mixed> the first job's result */
    private function import(string $content, bool $includeChanged = false, bool $overwrite = false): array
    {
        return $this->files->import($content, $includeChanged, $overwrite, static fn (string $type): bool => $type === 'product')[0];
    }
}

final class FakeItemStore implements ItemStore
{
    public const FIELDS = [
        'name'        => ['html' => false, 'size' => 128],
        'description' => ['html' => true, 'size' => 0],
        'meta_title'  => ['html' => false, 'size' => 255],
    ];

    /** @var array<string, array{title: string, fields: array<string, array{html: bool, size: int}>, source: array<string, string>, target: array<string, string>}> */
    public array $items = [
        'product:7' => [
            'title'  => 'Praline box',
            'fields' => self::FIELDS,
            'source' => ['name' => 'Praline box', 'description' => '<p>Made in <strong>Bern</strong>.</p>', 'meta_title' => 'Pralines'],
            'target' => ['name' => 'Praline box', 'description' => '', 'meta_title' => 'Pralinés maison'],
        ],
    ];

    /** @var list<array{0: string, 1: int, 2: int, 3: int, 4: array<string, string>, 5: bool}> */
    public array $saved = [];

    private const LANGUAGES = [
        1 => ['id' => 1, 'iso' => 'en', 'name' => 'English', 'locale' => 'en-US', 'mapped' => 'en-US'],
        2 => ['id' => 2, 'iso' => 'fr', 'name' => 'French', 'locale' => 'fr-CH', 'mapped' => ''],
        3 => ['id' => 3, 'iso' => 'ie', 'name' => 'Irish English', 'locale' => 'en-IE', 'mapped' => ''],
    ];

    public function item(string $type, int $id, int $sourceId, int $targetId): ?array
    {
        return $this->items[$type . ':' . $id] ?? null;
    }

    public function languageById(int $id): ?array
    {
        return self::LANGUAGES[$id] ?? null;
    }

    public function languageByIso(string $iso): ?array
    {
        foreach (self::LANGUAGES as $language) {
            if ($language['iso'] === $iso) {
                return $language;
            }
        }

        return null;
    }

    public function save(string $type, int $id, int $sourceId, int $targetId, array $values, bool $overwrite): void
    {
        $this->saved[] = [$type, $id, $sourceId, $targetId, $values, $overwrite];
    }
}
