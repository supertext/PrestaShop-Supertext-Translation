<?php

/**
 * @package     Supertext Translation for PrestaShop
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\PrestaShop;

use Supertext\PrestaShop\Api\LanguageCodes;
use Supertext\PrestaShop\Api\Reporting;
use Supertext\PrestaShop\Api\SupertextClient;
use Supertext\PrestaShop\Api\SupertextException;
use Supertext\PrestaShop\Sdk\Http\CurlTransport;

/**
 * The module's settings (PrestaShop configuration table), with the environment variables
 * SUPERTEXT_API_KEY, SUPERTEXT_API_ENDPOINT, SUPERTEXT_REVIEW_URL and SUPERTEXT_VERSION_REPORTS taking precedence.
 */
final class Settings
{
    public const API_KEY     = 'SUPERTEXT_API_KEY';
    public const ENVIRONMENT = 'SUPERTEXT_ENVIRONMENT';
    public const ENDPOINT    = 'SUPERTEXT_ENDPOINT';
    public const LANGUAGES   = 'SUPERTEXT_LANGUAGES';
    public const TIMEOUT     = 'SUPERTEXT_TIMEOUT';

    /** Address of the review service for languages with *Translation review* on (missing: Supertext's; '' = off). */
    public const REVIEW_URL  = 'SUPERTEXT_REVIEW_URL';

    /** "Send version information to Supertext": 1 or 0 (missing: on). */
    public const VERSION_REPORTS = 'SUPERTEXT_VERSION_REPORTS';

    /** The Commando Center reporter's state (JSON: what was reported last, the latest API key check). */
    public const REPORTER_STATE = 'SUPERTEXT_REPORTER_STATE';

    public const ALL = [self::API_KEY, self::ENVIRONMENT, self::ENDPOINT, self::REVIEW_URL, self::LANGUAGES, self::TIMEOUT, self::VERSION_REPORTS, self::REPORTER_STATE];

    public const SIGNUP_URL  = 'https://www.supertext.com/person/en/account/signin';
    public const API_KEY_URL = 'https://www.supertext.com/en/integrations/api';

    public const TONES = ['default', 'more', 'less'];

    public static function apiKey(): string
    {
        $env = (string) getenv('SUPERTEXT_API_KEY');

        return SupertextClient::normalizeKey($env !== '' ? $env : (string) \Configuration::getGlobalValue(self::API_KEY));
    }

    public static function apiKeyFromEnvironment(): bool
    {
        return trim((string) getenv('SUPERTEXT_API_KEY')) !== '';
    }

    public static function endpointFromEnvironment(): bool
    {
        return trim((string) getenv('SUPERTEXT_API_ENDPOINT')) !== '';
    }

    public static function environment(): string
    {
        $value = (string) \Configuration::getGlobalValue(self::ENVIRONMENT);

        return \in_array($value, ['live', 'staging', 'testing'], true) ? $value : 'live';
    }

    public static function customEndpoint(): string
    {
        return trim((string) \Configuration::getGlobalValue(self::ENDPOINT));
    }

    public static function baseUrl(): string
    {
        $env = trim((string) getenv('SUPERTEXT_API_ENDPOINT'));

        return SupertextClient::baseUrlFor(self::environment(), $env !== '' ? $env : self::customEndpoint());
    }

    public static function reviewUrlFromEnvironment(): bool
    {
        return trim((string) getenv('SUPERTEXT_REVIEW_URL')) !== '';
    }

    /** The saved review service address ('' = off); Supertext's review service when never saved. */
    public static function reviewUrlSetting(): string
    {
        return SupertextClient::reviewUrlFor(self::storedReviewUrl());
    }

    /** The review service used: SUPERTEXT_REVIEW_URL on the server wins over the setting ('' = Translation review off). */
    public static function reviewUrl(): string
    {
        return SupertextClient::reviewUrlFor(self::storedReviewUrl(), (string) getenv('SUPERTEXT_REVIEW_URL'));
    }

    /** False when the setting was never saved (installs from before Translation review). */
    private static function storedReviewUrl(): string|false
    {
        /** @var string|false|null $value getGlobalValue() returns false for a key that was never saved */
        $value = \Configuration::getGlobalValue(self::REVIEW_URL);

        return \is_string($value) ? $value : false;
    }

    public static function timeout(): int
    {
        $value = (int) \Configuration::getGlobalValue(self::TIMEOUT);

        return $value >= 30 ? $value : 180;
    }

    /**
     * The per-language settings. Codes saved by an earlier version are read as codes of Supertext's
     * list ("de" → "de-DE", "de-ch" → "de-CH", see LanguageCodes::migrate()).
     *
     * Languages saved before *Translation review* existed have it off.
     *
     * @return array<int, array{code: string, tone: string, review: bool}> id_lang => per-language settings
     */
    public static function languages(): array
    {
        $data = json_decode((string) \Configuration::getGlobalValue(self::LANGUAGES), true);
        $out  = [];

        foreach (\is_array($data) ? $data : [] as $idLang => $row) {
            $out[(int) $idLang] = [
                'code' => LanguageCodes::migrate((string) ($row['code'] ?? '')),
                'tone' => \in_array($row['tone'] ?? '', self::TONES, true) ? $row['tone'] : 'default',
                // Translations into this language go through the review service (Settings::client()->forReview()).
                'review' => (bool) ($row['review'] ?? false),
            ];
        }

        return $out;
    }

    /** @param array<int, array{code: string, tone: string, review: bool}> $languages */
    public static function saveLanguages(array $languages): void
    {
        \Configuration::updateGlobalValue(self::LANGUAGES, json_encode($languages, JSON_UNESCAPED_SLASHES));
    }

    /** Stores the migrated codes (languages()) when they differ from what is saved; run on upgrade and on the settings page. */
    public static function migrateLanguages(): void
    {
        $stored = json_decode((string) \Configuration::getGlobalValue(self::LANGUAGES), true);

        if (\is_array($stored) && $stored !== [] && $stored !== self::languages()) {
            self::saveLanguages(self::languages());
        }
    }

    /**
     * The code sent to Supertext as target language: the language's Supertext language (setting),
     * else its locale when that is a Supertext code apart from case and "_"/"-", else the SDK's
     * default for the locale ("de" → "de-DE", "es-ES" → "es").
     *
     * @throws SupertextException unsupported_language: the language has neither (e.g. "en-IE")
     */
    public static function targetCode(\Language $language): string
    {
        return LanguageCodes::target(self::localeOf($language), self::override((int) $language->id));
    }

    /** The target code, or null when the language has no Supertext language and no default. */
    public static function targetCodeOrNull(\Language $language): ?string
    {
        return LanguageCodes::targetOrNull(self::localeOf($language), self::override((int) $language->id));
    }

    /**
     * The code sent to Supertext as source language: the source form of the same setting or of the
     * locale (de-CH → de, en-GB → en).
     *
     * @throws SupertextException unsupported_language
     */
    public static function sourceCode(\Language $language): string
    {
        return LanguageCodes::source(self::localeOf($language), self::override((int) $language->id));
    }

    /** The language's locale (en-GB). Not iso_code: PrestaShop uses country-like iso codes for some languages (gb, br, mx). */
    public static function localeOf(\Language $language): string
    {
        return (string) ($language->locale ?: $language->language_code ?: $language->iso_code);
    }

    private static function override(int $idLang): string
    {
        return self::languages()[$idLang]['code'] ?? '';
    }

    public static function tone(int $idLang): string
    {
        return self::languages()[$idLang]['tone'] ?? 'default';
    }

    /** Whether translations into this language go through the review service (*Translation review*). */
    public static function review(int $idLang): bool
    {
        return self::languages()[$idLang]['review'] ?? false;
    }

    /* ------------------------------------------------------------------ Commando Center */

    /** The "Send version information to Supertext" setting (on unless switched off). */
    public static function versionReportsSetting(): bool
    {
        $value = (string) \Configuration::getGlobalValue(self::VERSION_REPORTS);

        return $value === '' || (bool) (int) $value;
    }

    /** Whether reports are sent: SUPERTEXT_VERSION_REPORTS on the server wins over the setting. */
    public static function versionReportsEnabled(): bool
    {
        return Reporting::enabled(self::versionReportsSetting());
    }

    /** The shop's base URL as PrestaShop knows it (default shop, https when SSL is enabled), with a trailing slash. */
    public static function siteUrl(): ?string
    {
        $shop   = new \Shop((int) \Configuration::get('PS_SHOP_DEFAULT'));
        $ssl    = (bool) \Configuration::get('PS_SSL_ENABLED');
        $domain = (string) ($ssl ? $shop->domain_ssl : $shop->domain);

        return $domain !== '' ? ($ssl ? 'https://' : 'http://') . $domain . $shop->getBaseURI() : null;
    }

    /** The public ping endpoint (module front controller "ping"); works with and without friendly URLs. */
    public static function pingUrl(?string $siteUrl): ?string
    {
        return $siteUrl !== null ? rtrim($siteUrl, '/') . '/index.php?fc=module&module=supertext&controller=ping' : null;
    }

    /** The module's version from supertext.php (the official version source). */
    public static function moduleVersion(): string
    {
        $module  = \Module::getInstanceByName('supertext');
        $version = $module instanceof \Module ? (string) $module->version : '';

        return $version !== '' ? $version : '0.0.0';
    }

    public static function reporting(): Reporting
    {
        $siteUrl = self::siteUrl();

        return new Reporting(
            enabled: self::versionReportsEnabled(),
            siteUrl: $siteUrl,
            pluginVersion: self::moduleVersion(),
            systemVersion: \defined('_PS_VERSION_') ? (string) _PS_VERSION_ : null,
            callbackUrl: self::pingUrl($siteUrl),
            apiBaseUrl: self::baseUrl(),
            load: static function (): ?string {
                $value = (string) \Configuration::getGlobalValue(self::REPORTER_STATE);

                return $value !== '' ? $value : null;
            },
            save: static function (string $json): void {
                \Configuration::updateGlobalValue(self::REPORTER_STATE, $json);
            },
        );
    }

    /**
     * Runs the reporter's onRequest() (or another action) once the back-office response is out, where
     * PHP allows it (PHP-FPM, LiteSpeed); otherwise at the end of the request. Never fails the request.
     *
     * @param (\Closure(Reporting, string): mixed)|null $action default: onRequest
     */
    public static function reportAfterResponse(?\Closure $action = null): void
    {
        static $queued = false;

        if ($queued && $action === null) {
            return;
        }

        $queued = $queued || $action === null;

        register_shutdown_function(static function () use ($action): void {
            try {
                if (\function_exists('fastcgi_finish_request')) {
                    fastcgi_finish_request();
                } elseif (\function_exists('litespeed_finish_request')) {
                    \call_user_func('litespeed_finish_request');
                }

                $reporting = self::reporting();
                $action !== null ? $action($reporting, self::apiKey()) : $reporting->onRequest(self::apiKey());
            } catch (\Throwable) {
                // Reporting is optional.
            }
        });
    }

    public static function client(): SupertextClient
    {
        // The API key check, the reports and translation files never use the review service.
        return new SupertextClient(
            self::apiKey(),
            self::baseUrl(),
            new CurlTransport(),
            self::timeout(),
            reviewUrl: self::reviewUrl(),
            pluginVersion: self::moduleVersion(),
        );
    }
}
