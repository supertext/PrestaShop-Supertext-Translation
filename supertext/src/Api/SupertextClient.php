<?php

/**
 * @package     Supertext Translation for PrestaShop
 * @copyright   (C) Supertext AG
 * @license     MIT
 */

namespace Supertext\PrestaShop\Api;

use Supertext\PrestaShop\Sdk\Http\HttpTransport;
use Supertext\PrestaShop\Sdk\V1\Client;
use Supertext\PrestaShop\Sdk\V1\Protocol;
use Supertext\PrestaShop\Sdk\V1\SupertextException as SdkException;

/**
 * Supertext AI file translation API v1 (https://api.supertext.com/v1/), through the
 * Supertext PHP SDK bundled in lib/supertext-sdk (namespace Supertext\PrestaShop\Sdk\V1).
 *
 * The SDK does the protocol (upload one HTML document, poll its status, download the
 * translation, delete the file), the auth header and HTTP 429 retries. Language codes are
 * sent as given: resolve them with LanguageCodes first.
 * This class keeps the module's interface and turns SDK errors into this module's
 * SupertextException, whose English template the back office translates
 * (domain Modules.Supertext.Admin).
 *
 * Has no PrestaShop dependencies.
 */
final class SupertextClient
{
    public const LIVE = Protocol::LIVE_BASE_URL;
    public const STAGING = 'https://api.staging.supertext.com/v1/';
    public const TESTING = 'https://api.testing.supertext.com/v1/';

    /**
     * Supertext's review service (translation proxy of Supertext Localization Review). It speaks
     * the same API and forwards to the live Supertext API, keeping every translation so reviewers
     * can correct them on the shop's website. Used for languages with *Translation review* on.
     */
    public const REVIEW_URL = 'https://localizationreview-production.up.railway.app/v1/';

    private readonly Client $sdk;
    private readonly string $apiKey;

    /** @var (\Closure(): self)|null builds the client for the review service (null: none) */
    private readonly ?\Closure $makeReviewClient;

    private ?self $reviewClient = null;

    /**
     * @param HttpTransport|callable(string $method, string $url, array<string, string> $headers, ?string $body): array{status: int, body: string, headers?: array<string, string>} $transport
     * @param callable(float $seconds): void|null $sleep tests: the SDK's clock then counts the slept time
     * @param string $reviewUrl the review service for languages with *Translation review* on ('' = none, see forReview())
     * @param string $pluginVersion the module's version, sent in the User-Agent
     */
    public function __construct(
        string $apiKey,
        string $baseUrl,
        HttpTransport|callable $transport,
        private readonly int $pollTimeout = 180,
        float $pollInterval = 2.0,
        ?callable $sleep = null,
        string $reviewUrl = '',
        string $pluginVersion = '',
    ) {
        $this->apiKey = self::normalizeKey($apiKey);
        $baseUrl      = $baseUrl !== '' ? $baseUrl : self::LIVE;
        $reviewUrl    = trim($reviewUrl);

        // Same key, timeouts, transport and User-Agent; only the base URL differs.
        $this->makeReviewClient = $reviewUrl === '' || rtrim($reviewUrl, '/') === rtrim($baseUrl, '/')
            ? null
            : fn (): self => new self($apiKey, $reviewUrl, $transport, $pollTimeout, $pollInterval, $sleep, '', $pluginVersion);

        $elapsed = 0.0;
        $sdkSleep = $sleep === null ? null : static function (float $seconds) use ($sleep, &$elapsed): void {
            $elapsed += $seconds;
            $sleep($seconds);
        };
        $clock = $sleep === null ? null : static function () use (&$elapsed): float {
            return $elapsed;
        };

        $this->sdk = new Client(
            $this->apiKey,
            baseUrl: $baseUrl,
            pollInterval: $pollInterval,
            timeout: (float) $pollTimeout,
            requestTimeout: 60.0,
            // The module's version too, so Supertext and the review service see which release calls.
            userAgent: Reporting::PLUGIN_NAME . ($pluginVersion !== '' ? '/' . $pluginVersion : ''),
            transport: $transport instanceof HttpTransport ? $transport : new CallableTransport($transport),
            sleep: $sdkSleep,
            clock: $clock,
        );
    }

    /** Supertext shows the key as "Supertext-Auth-Key <key>"; accept it with or without that prefix. */
    public static function normalizeKey(string $key): string
    {
        return (string) preg_replace('/^Supertext-Auth-Key\s+/i', '', trim($key));
    }

    public static function baseUrlFor(string $environment, string $customEndpoint = ''): string
    {
        if (trim($customEndpoint) !== '') {
            return trim($customEndpoint);
        }

        return match ($environment) {
            'staging' => self::STAGING,
            'testing' => self::TESTING,
            default   => self::LIVE,
        };
    }

    /**
     * The review service's base URL: SUPERTEXT_REVIEW_URL on the server (non-empty) wins over the
     * setting; a setting that was never saved (false/null) means Supertext's review service;
     * an empty setting turns Translation review off for every language.
     */
    public static function reviewUrlFor(string|false|null $setting, string $environment = ''): string
    {
        if (trim($environment) !== '') {
            return trim($environment);
        }

        return $setting === false || $setting === null ? self::REVIEW_URL : trim($setting);
    }

    /**
     * The client for translations into a language: the review service when its *Translation
     * review* is on (same key, same protocol, created on first use), else this one. Without a
     * review URL (or with the API's own URL), always this one. translateDocument() uploads, polls,
     * downloads and deletes with the same client, so a job is collected where it was submitted.
     */
    public function forReview(bool $review): self
    {
        if (!$review || $this->makeReviewClient === null) {
            return $this;
        }

        return $this->reviewClient ??= ($this->makeReviewClient)();
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Translates a complete HTML document and returns the translated HTML.
     *
     * @param string $targetLanguage a Supertext target code (LanguageCodes::target()), sent as given
     * @param string $sourceLanguage a Supertext source code (LanguageCodes::source()), sent as given. Empty: auto-detect.
     * @param string $politeness     "default", "more" (formal) or "less" (informal)
     */
    public function translateDocument(string $html, string $targetLanguage, string $sourceLanguage = '', string $politeness = 'default'): string
    {
        if (\function_exists('set_time_limit')) {
            @set_time_limit($this->pollTimeout + 60);
        }

        return $this->call(fn (): string => $this->sdk->translateHtml($html, $targetLanguage, $sourceLanguage !== '' ? $sourceLanguage : null, $politeness));
    }

    /** Cost-free check that the key is accepted. */
    public function validateApiKey(): void
    {
        $this->call(fn () => $this->sdk->validateApiKey());
    }

    /** Seconds to wait before retry $attempt (0-based): Retry-After if sent, else 1, 2, 4, 8 plus jitter. */
    public static function retryDelay(int $attempt, string $retryAfter = ''): float
    {
        return Protocol::retryDelayMs($attempt, $retryAfter !== '' ? $retryAfter : null) / 1000;
    }

    /**
     * @template T
     * @param \Closure(): T $fn
     * @return T
     */
    private function call(\Closure $fn): mixed
    {
        try {
            return $fn();
        } catch (SdkException $e) {
            throw self::fromSdk($e);
        }
    }

    /**
     * The module's message for an SDK error code. Keep the arms of the "$message = match"
     * block literal: tests/Support/ModuleStrings.php reads them for the translation files.
     */
    public static function fromSdk(SdkException $e): SupertextException
    {
        $code = $e->errorCode;
        $message = match (true) {
            $code === SdkException::MISSING_API_KEY        => 'No Supertext API key is configured. No Supertext account yet? Create one at https://www.supertext.com/person/en/account/signin. Generate your API key at https://www.supertext.com/en/integrations/api (requires the Admin role).',
            $code === SdkException::AUTHENTICATION_FAILURE => 'Authentication failed. Please check the Supertext API key. A new key can be generated at https://www.supertext.com/en/integrations/api (requires the Admin role). No Supertext account yet? Create one at https://www.supertext.com/person/en/account/signin.',
            $code === SdkException::INVALID_LANGUAGE_PAIR  => 'Supertext does not support this language pair (%source% → %target%). Check the Supertext language in the module settings.',
            $code === SdkException::UNSUPPORTED_LANGUAGE   => 'The language %language% has no Supertext language. Ask your administrator to choose one in Modules → Module Manager → Supertext Translation → Configure → Languages (list Supertext language).',
            $code === SdkException::UNSUPPORTED_FEATURE    => 'Supertext offers no formal or informal tone for this language. Set its tone to "Supertext default" in the module settings.',
            $code === SdkException::NOT_FOUND              => 'The requested Supertext resource was not found.',
            $code === SdkException::PAYLOAD_TOO_LARGE      => 'The content is too large for Supertext to translate in one go.',
            $code === SdkException::TOO_MANY_REQUESTS      => 'Too many requests to Supertext. Please try again shortly.',
            $code === SdkException::SERVICE_UNAVAILABLE    => 'The Supertext service is currently unavailable.',
            $code === SdkException::TRANSPORT_ERROR        => 'Could not reach Supertext: %error%',
            $code === SdkException::NO_FILE_ID             => 'Supertext did not return a file id.',
            $code === SdkException::TRANSLATION_ERROR      => 'Supertext could not translate the document.',
            $code === SdkException::QUOTA_EXCEEDED         => 'Your Supertext translation limit is exceeded. Please upgrade your subscription.',
            $code === SdkException::FILE_DELETED           => 'The document was deleted at Supertext before it could be downloaded.',
            $code === SdkException::TIMEOUT                => 'Timed out waiting for the Supertext translation.',
            $code === SdkException::INCOMPLETE_RESPONSE    => 'The translated document was empty.',
            default                                        => 'Supertext answered with HTTP %status%.',
        };

        $detail = (string) $e->detail;
        $parameters = match ($code) {
            SdkException::INVALID_LANGUAGE_PAIR => ['%source%' => (string) $e->sourceLang ?: '?', '%target%' => (string) $e->targetLang ?: '?'],
            SdkException::UNSUPPORTED_LANGUAGE => ['%language%' => (string) ($e->targetLang ?? $e->sourceLang) ?: '?'],
            SdkException::UNSUPPORTED_FEATURE => [],
            SdkException::TRANSPORT_ERROR => ['%error%' => $detail],
            default => ['%status%' => (int) $e->status],
        };
        if ($code === SdkException::TRANSPORT_ERROR) {
            $detail = ''; // already in the message
        }

        if ($code === SdkException::UNSUPPORTED_LANGUAGE) {
            $detail = ''; // nothing was sent; the language is in the message
        }

        return new SupertextException($message, $parameters, (int) $e->status, $e, $detail);
    }
}
