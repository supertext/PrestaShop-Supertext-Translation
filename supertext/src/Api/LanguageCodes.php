<?php

/**
 * @package     Supertext Translation for PrestaShop
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\PrestaShop\Api;

use Supertext\PrestaShop\Sdk\V1\Languages;
use Supertext\PrestaShop\Sdk\V1\SupertextException as SdkException;

/**
 * Shop languages → Supertext language codes (the Supertext SDK's rules, the same in every
 * Supertext plugin).
 *
 * Supertext accepts exactly the codes of its `GET features` list: bare sources ("en"); targets
 * regional only for German, English, French, Italian and Portuguese ("de-CH", "en-US", "pt-BR"),
 * with a script for Chinese and Serbian ("zh-Hans", "sr-Latn"), bare for the others ("es", "nl").
 *
 * A language's *Supertext language* (a list per shop language in the module settings) wins;
 * otherwise a locale that equals a Supertext code apart from case and "_"/"-" is used ("de-CH",
 * "fr_ch" → "fr-CH", "es"); otherwise the SDK's default where one is obvious ("de" → "de-DE",
 * "es-ES" → "es", "zh-CN" → "zh-Hans"); anything else ("en-IE", "de-LI", "gsw") is the error
 * `unsupported_language` and nothing is sent. The same setting serves the source: a mapped
 * "de-CH" is sent as the source "de".
 *
 * Has no PrestaShop dependencies.
 */
final class LanguageCodes
{
    /**
     * Every target Supertext offers, sorted by English name: code, English name, whether it has formal/informal.
     *
     * @return list<array{code: string, name: string, politeness: bool}>
     */
    public static function targets(): array
    {
        return Languages::targetOptions();
    }

    /**
     * The targets for the settings list, with the name in $displayLocale where PHP's intl extension
     * knows it ("Deutsch (Schweiz)"), sorted by that name. Codes with a Unicode extension
     * (gsw-u-sd-chzh) keep Supertext's own name, which intl can't express.
     *
     * @return list<array{code: string, name: string, politeness: bool}>
     */
    public static function options(string $displayLocale = 'en'): array
    {
        $options = self::targets();

        if (class_exists(\Locale::class)) {
            foreach ($options as &$option) {
                if (!str_contains($option['code'], '-u-')) {
                    $name = (string) \Locale::getDisplayName($option['code'], $displayLocale);
                    $option['name'] = $name !== '' && $name !== $option['code'] ? self::ucfirst($name) : $option['name'];
                }
            }
            unset($option);

            $collator = class_exists(\Collator::class) ? new \Collator($displayLocale) : null;
            usort($options, static fn (array $a, array $b): int => $collator !== null ? (int) $collator->compare($a['name'], $b['name']) : strcmp($a['name'], $b['name']));
        }

        return $options;
    }

    /** Whether the code is one of Supertext's targets, exactly as the list writes it. */
    public static function isTarget(string $code): bool
    {
        return \in_array($code, array_column(Languages::TARGETS, 'code'), true);
    }

    /** The Supertext target that equals the locale apart from case and "_"/"-", else null ("de" → null, "de_ch" → "de-CH"). */
    public static function match(string $locale): ?string
    {
        return Languages::matchTarget($locale);
    }

    /**
     * What the settings list preselects for a shop language without a saved Supertext language:
     * the exact match, else the SDK's default ("de" → "de-DE", "es-ES" → "es"), else null ("en-IE").
     */
    public static function suggest(string $locale): ?string
    {
        return Languages::suggestTarget($locale);
    }

    /**
     * A Supertext language saved by an earlier version (typed freely before the list existed) as a
     * code of the list: "de-ch" → "de-CH", "de" → "de-DE" (what 0.1.0 sent for it), "es-ES" → "es".
     * A code the list doesn't know is kept as it is, so nothing configured is lost.
     */
    public static function migrate(string $code): string
    {
        $code = trim($code);

        return $code === '' ? '' : (Languages::suggestTarget($code) ?? $code);
    }

    /**
     * Target code to send: the language's Supertext language, else an exact match, else the SDK's default.
     *
     * @throws SupertextException unsupported_language when there is none of them (nothing is sent)
     */
    public static function target(string $locale, string $override = ''): string
    {
        try {
            return Languages::target($locale, self::mapping($locale, $override));
        } catch (SdkException $e) {
            throw SupertextClient::fromSdk($e);
        }
    }

    /** The target code, or null when the language has no Supertext language and no default (settings page, translate page). */
    public static function targetOrNull(string $locale, string $override = ''): ?string
    {
        try {
            return self::target($locale, $override);
        } catch (SupertextException) {
            return null;
        }
    }

    /**
     * Source code to send: the source form of the language's Supertext language, else of the
     * locale ("en-GB" → "en"). Empty stays empty (auto-detect).
     *
     * @throws SupertextException unsupported_language when there is neither
     */
    public static function source(string $locale, string $override = ''): string
    {
        try {
            return Languages::source($locale, self::mapping($locale, $override));
        } catch (SdkException $e) {
            throw SupertextClient::fromSdk($e);
        }
    }

    /** Whether Supertext offers formal/informal for a target code (null: a code the SDK's list doesn't know). */
    public static function supportsPoliteness(string $target): ?bool
    {
        return Languages::supportsPoliteness($target);
    }

    private static function ucfirst(string $name): string
    {
        return mb_strtoupper(mb_substr($name, 0, 1)) . mb_substr($name, 1);
    }

    /**
     * One language's setting as the SDK's mapping (locale → Supertext code).
     *
     * @return array<string, string>
     */
    private static function mapping(string $locale, string $override): array
    {
        return trim($override) !== '' && trim($locale) !== '' ? [trim($locale) => trim($override)] : [];
    }
}
