<?php

namespace Supertext\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Supertext\PrestaShop\Api\LanguageCodes;
use Supertext\PrestaShop\Api\SupertextException;

/** Shop language → Supertext code: the setting, else an exact match, else the SDK's default, else unsupported_language. */
final class LanguageCodesTest extends TestCase
{
    public function testLocaleThatIsASupertextCodeIsUsed(): void
    {
        self::assertSame('de-CH', LanguageCodes::target('de-CH'));
        self::assertSame('fr-CH', LanguageCodes::target('fr_ch'));
        self::assertSame('es', LanguageCodes::target('ES'));
        self::assertSame('zh-Hant', LanguageCodes::target('zh_hant'));
    }

    public function testDefaultsOnlyWhereObvious(): void
    {
        self::assertSame('de-DE', LanguageCodes::target('de'));
        self::assertSame('es', LanguageCodes::target('es-ES'));
        self::assertSame('sl', LanguageCodes::target('sl-SI'));
        self::assertSame('zh-Hans', LanguageCodes::target('zh-CN'));
        self::assertSame('zh-Hant', LanguageCodes::target('zh-TW'));

        foreach (['en-IE', 'de-LI', 'gsw', 'sr-RS', 'xx'] as $locale) {
            self::assertNull(LanguageCodes::targetOrNull($locale), $locale);
        }
    }

    public function testSuggestionForTheSettingsList(): void
    {
        self::assertSame('de-CH', LanguageCodes::suggest('de-ch'));
        self::assertSame('de-DE', LanguageCodes::suggest('de'));
        self::assertSame('es', LanguageCodes::suggest('es-ES'));
        self::assertNull(LanguageCodes::suggest('en-IE'));
    }

    public function testSavedCodesAreMigratedToTheList(): void
    {
        self::assertSame('de-CH', LanguageCodes::migrate(' de-ch '));
        self::assertSame('de-DE', LanguageCodes::migrate('de'));
        self::assertSame('es', LanguageCodes::migrate('es-ES'));
        self::assertSame('xx-YY', LanguageCodes::migrate('xx-YY'));
        self::assertSame('', LanguageCodes::migrate(''));
    }

    public function testOptionsAreLocalized(): void
    {
        $codes = array_column(LanguageCodes::options('de'), 'code');
        sort($codes);
        $all = array_column(LanguageCodes::targets(), 'code');
        sort($all);
        self::assertSame($all, $codes);

        $names = array_column(LanguageCodes::options('de'), 'name', 'code');
        if (class_exists(\Locale::class)) {
            self::assertSame('Deutsch (Schweiz)', $names['de-CH']);
        }
        self::assertStringNotContainsString('sd=', $names['gsw-u-sd-chzh']);
        self::assertTrue(LanguageCodes::isTarget('de-CH'));
        self::assertFalse(LanguageCodes::isTarget('de-ch'));
    }

    public function testUnmappedLanguageIsAnUnsupportedLanguage(): void
    {
        try {
            LanguageCodes::target('en-IE');
            self::fail('Expected an exception');
        } catch (SupertextException $e) {
            self::assertSame('unsupported_language', $e->errorCode());
            self::assertSame('The language %language% has no Supertext language. Ask your administrator to choose one in Modules → Module Manager → Supertext Translation → Configure → Languages (list Supertext language).', $e->template());
            self::assertSame(['%language%' => 'en-IE'], $e->parameters());
            self::assertSame('', $e->detail());
        }
    }

    public function testTheSettingWins(): void
    {
        self::assertSame('es', LanguageCodes::target('es-ES', 'es'));
        self::assertSame('de-AT', LanguageCodes::target('de-DE', 'de-AT'));
        self::assertSame('gsw-u-sd-chzh', LanguageCodes::target('de-CH', 'gsw-u-sd-chzh'));
        // A code Supertext adds later is sent as it is.
        self::assertSame('uk', LanguageCodes::target('uk-UA', 'uk'));
    }

    public function testSourceIsBareAndUsesTheSameSetting(): void
    {
        self::assertSame('en', LanguageCodes::source('en-US'));
        self::assertSame('en', LanguageCodes::source('en_GB'));
        self::assertSame('de', LanguageCodes::source('de'));
        self::assertSame('es', LanguageCodes::source('es-ES'));
        self::assertSame('de', LanguageCodes::source('xx', 'de-CH'));
        self::assertSame('', LanguageCodes::source(''));
    }

    public function testMatchPrefillsTheSetting(): void
    {
        self::assertSame('de-CH', LanguageCodes::match('de-ch'));
        self::assertNull(LanguageCodes::match('de'));
        self::assertContains('nb', array_column(LanguageCodes::targets(), 'code'));
    }

    public function testPoliteness(): void
    {
        self::assertTrue(LanguageCodes::supportsPoliteness('de-CH'));
        self::assertFalse(LanguageCodes::supportsPoliteness('en-US'));
        self::assertNull(LanguageCodes::supportsPoliteness('xx'));
    }
}
