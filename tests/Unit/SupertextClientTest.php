<?php

namespace Supertext\PrestaShop\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Supertext\PrestaShop\Api\SupertextClient;
use Supertext\PrestaShop\Api\SupertextException;

final class SupertextClientTest extends TestCase
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    private array $calls = [];

    /** @param callable(string, string, ?string): array{status: int, body: string, headers?: array<string, string>} $handler */
    private function client(callable $handler, string $key = 'test-key'): SupertextClient
    {
        $transport = function (string $method, string $url, array $headers, ?string $body) use ($handler): array {
            $this->calls[] = compact('method', 'url', 'headers', 'body');

            return $handler($method, $url, $body) + ['headers' => []];
        };

        return new SupertextClient($key, 'https://api.test/v1', $transport, 10, 0.01, static function (): void {
        });
    }

    public function testRunsTheFullProtocol(): void
    {
        $polls  = 0;
        $client = $this->client(function (string $method, string $url) use (&$polls): array {
            return match (true) {
                $method === 'POST'                    => ['status' => 200, 'body' => '{"file_id":"f1"}'],
                str_ends_with($url, '/status')        => ['status' => 200, 'body' => json_encode(['status' => ++$polls < 2 ? 'running' : 'done'])],
                str_ends_with($url, '/translation')   => ['status' => 200, 'body' => '<div data-st-id="0">Hallo</div>'],
                default                               => ['status' => 200, 'body' => '{}'],
            };
        });

        $html = $client->translateDocument('<div data-st-id="0">Hello</div>', 'de-CH', 'en', 'more');

        self::assertStringContainsString('Hallo', $html);
        self::assertSame(
            ['POST translate/ai/file', 'GET translate/ai/file/f1/status', 'GET translate/ai/file/f1/status', 'GET translate/ai/file/f1/translation', 'DELETE translate/ai/file/f1'],
            array_map(static fn (array $c): string => $c['method'] . ' ' . str_replace('https://api.test/v1/', '', $c['url']), $this->calls)
        );

        $post = $this->calls[0];
        self::assertSame('Supertext-Auth-Key test-key', $post['headers']['Authorization']);
        self::assertStringStartsWith('multipart/form-data; boundary=', $post['headers']['Content-Type']);
        self::assertStringContainsString("name=\"target_lang\"\r\n\r\nde-CH\r\n", $post['body']);
        self::assertStringContainsString("name=\"source_lang\"\r\n\r\nen\r\n", $post['body']);
        self::assertStringContainsString("name=\"politeness\"\r\n\r\nmore\r\n", $post['body']);
        self::assertStringContainsString("filename=\"content.html\"\r\nContent-Type: text/html\r\n\r\n<div", $post['body']);
    }

    public function testSendsTheCodesAsGiven(): void
    {
        $client = $this->client(fn (string $method, string $url) => match (true) {
            $method === 'POST'                  => ['status' => 200, 'body' => '{"file_id":"f1"}'],
            str_ends_with($url, '/status')      => ['status' => 200, 'body' => '{"status":"done"}'],
            str_ends_with($url, '/translation') => ['status' => 200, 'body' => '<div data-st-id="0">Hola</div>'],
            default                             => ['status' => 200, 'body' => '{}'],
        });

        $client->translateDocument('<div data-st-id="0">Hello</div>', 'es', 'en');

        self::assertStringContainsString("name=\"target_lang\"\r\n\r\nes\r\n", (string) $this->calls[0]['body']);
        self::assertStringContainsString("name=\"source_lang\"\r\n\r\nen\r\n", (string) $this->calls[0]['body']);
    }

    public function testExplainsAToneTheLanguageLacks(): void
    {
        $client = $this->client(fn () => ['status' => 400, 'body' => '{"error_code":"FEATURE_ERROR","detail":"politeness not supported"}']);

        try {
            $client->translateDocument('<p>x</p>', 'en-US', 'de', 'more');
            self::fail('Expected an exception');
        } catch (SupertextException $e) {
            self::assertSame('unsupported_feature', $e->errorCode());
            self::assertSame('Supertext offers no formal or informal tone for this language. Set its tone to "Supertext default" in the module settings.', $e->template());
        }
    }

    public function testRecognisesARejectedKey(): void
    {
        $client = $this->client(fn () => ['status' => 403, 'body' => '{"error_code":"FORBIDDEN"}']);

        try {
            $client->validateApiKey();
            self::fail('Expected an exception');
        } catch (SupertextException $e) {
            self::assertTrue($e->isAuthenticationFailure());
            self::assertSame(403, $e->getCode());
        }
    }

    public function testAcceptsTheKeyWithItsPrefix(): void
    {
        $client = $this->client(fn () => ['status' => 200, 'body' => '{}'], '  Supertext-Auth-Key abc+/= ');
        $client->validateApiKey();

        self::assertSame('Supertext-Auth-Key abc+/=', $this->calls[0]['headers']['Authorization']);
    }

    public function testRetriesWhenRateLimited(): void
    {
        $limited = 2;
        $client  = $this->client(function () use (&$limited): array {
            return $limited-- > 0 ? ['status' => 429, 'body' => 'slow down'] : ['status' => 200, 'body' => '{}'];
        });
        $client->validateApiKey();

        self::assertCount(3, $this->calls);
    }

    public function testGivesUpAfterFourRetries(): void
    {
        $client = $this->client(fn () => ['status' => 429, 'body' => 'slow down']);

        try {
            $client->validateApiKey();
            self::fail('Expected an exception');
        } catch (SupertextException $e) {
            self::assertStringContainsString('Too many requests', $e->getMessage());
        }

        self::assertCount(5, $this->calls);
    }

    public function testHonoursRetryAfter(): void
    {
        self::assertSame(3.0, SupertextClient::retryDelay(0, '3'));
        self::assertGreaterThanOrEqual(2.0, SupertextClient::retryDelay(1));
    }

    public function testExplainsAuthenticationErrors(): void
    {
        $client = $this->client(fn () => ['status' => 401, 'body' => '{"detail":"bad key"}']);

        $this->expectException(SupertextException::class);
        $this->expectExceptionMessageMatches('/Authentication failed.*bad key/');
        $client->translateDocument('<p>x</p>', 'fr-FR');
    }

    public function testKeepsTheTranslatableTemplateApartFromSupertextsDetail(): void
    {
        $client = $this->client(fn () => ['status' => 418, 'body' => 'teapot']);

        try {
            $client->translateDocument('<p>x</p>', 'fr-FR');
            self::fail('Expected an exception');
        } catch (SupertextException $e) {
            self::assertSame('Supertext answered with HTTP 418. (teapot)', $e->getMessage());
            self::assertSame('Supertext answered with HTTP %status%.', $e->template());
            self::assertSame(['%status%' => 418], $e->parameters());
            self::assertSame('teapot', $e->detail());
        }
    }

    public function testStopsOnLimitExceededAndCleansUp(): void
    {
        $client = $this->client(function (string $method, string $url): array {
            return match (true) {
                $method === 'POST'             => ['status' => 200, 'body' => '{"file_id":"f2"}'],
                str_ends_with($url, '/status') => ['status' => 200, 'body' => '{"status":"limit_exceeded"}'],
                default                        => ['status' => 200, 'body' => '{}'],
            };
        });

        try {
            $client->translateDocument('<p>x</p>', 'fr-FR');
            self::fail('Expected an exception');
        } catch (SupertextException $e) {
            self::assertStringContainsString('limit is exceeded', $e->getMessage());
        }

        self::assertSame('DELETE', end($this->calls)['method']);
    }

    public function testRefusesToRunWithoutKey(): void
    {
        $client = $this->client(fn () => ['status' => 200, 'body' => '{}'], '');

        self::assertFalse($client->hasApiKey());
        $this->expectException(SupertextException::class);
        $client->validateApiKey();
    }

    public function testReportsAnUnsupportedLanguagePair(): void
    {
        $client = $this->client(fn () => ['status' => 404, 'body' => '{"error_code":"INVALID_LANGUAGE_PAIR","source_lang":"en","target_lang":"xx-YY"}']);

        try {
            $client->translateDocument('<p>x</p>', 'xx-YY', 'en');
            self::fail('Expected an exception');
        } catch (SupertextException $e) {
            self::assertSame('Supertext does not support this language pair (%source% → %target%). Check the Supertext language in the module settings.', $e->template());
            self::assertSame(['%source%' => 'en', '%target%' => 'xx-YY'], $e->parameters());
        }
    }

    public function testEnvironments(): void
    {
        self::assertSame(SupertextClient::LIVE, SupertextClient::baseUrlFor('live'));
        self::assertSame(SupertextClient::STAGING, SupertextClient::baseUrlFor('staging'));
        self::assertSame('http://127.0.0.1:8765/v1/', SupertextClient::baseUrlFor('live', 'http://127.0.0.1:8765/v1/'));
    }

    /** A client on https://api.test/v1 with the given review URL whose transport answers every call. */
    private function reviewingClient(string $reviewUrl): SupertextClient
    {
        $transport = function (string $method, string $url, array $headers, ?string $body): array {
            $this->calls[] = compact('method', 'url', 'headers', 'body');

            return match (true) {
                $method === 'POST'                  => ['status' => 200, 'body' => '{"file_id":"f1"}', 'headers' => []],
                str_ends_with($url, '/status')      => ['status' => 200, 'body' => '{"status":"done"}', 'headers' => []],
                str_ends_with($url, '/translation') => ['status' => 200, 'body' => '<div data-st-id="0">Hallo</div>', 'headers' => []],
                default                             => ['status' => 200, 'body' => '{}', 'headers' => []],
            };
        };

        return new SupertextClient('test-key', 'https://api.test/v1/', $transport, 10, 0.01, static function (): void {
        }, $reviewUrl, '1.2.3');
    }

    /** @return list<string> "METHOD url" of the calls so far */
    private function callLines(): array
    {
        return array_map(static fn (array $c): string => $c['method'] . ' ' . $c['url'], $this->calls);
    }

    public function testReviewLanguagesGoThroughTheReviewService(): void
    {
        $client = $this->reviewingClient('https://review.test/v1/');
        $review = $client->forReview(true);

        self::assertNotSame($client, $review);
        self::assertSame($review, $client->forReview(true), 'created once');
        self::assertSame($client, $client->forReview(false));

        $review->translateDocument('<div data-st-id="0">Hello</div>', 'de-CH', 'en');

        // All four calls (upload, status, download, delete) go to the review service, with the same key.
        self::assertSame([
            'POST https://review.test/v1/translate/ai/file',
            'GET https://review.test/v1/translate/ai/file/f1/status',
            'GET https://review.test/v1/translate/ai/file/f1/translation',
            'DELETE https://review.test/v1/translate/ai/file/f1',
        ], $this->callLines());
        self::assertSame('Supertext-Auth-Key test-key', $this->calls[0]['headers']['Authorization']);
        self::assertStringContainsString('PrestaShop-Supertext-Translation/1.2.3', $this->calls[0]['headers']['User-Agent']);

        // Other languages go directly to Supertext.
        $this->calls = [];
        $client->forReview(false)->translateDocument('<div data-st-id="0">Hello</div>', 'fr-FR', 'en');
        self::assertSame([
            'POST https://api.test/v1/translate/ai/file',
            'GET https://api.test/v1/translate/ai/file/f1/status',
            'GET https://api.test/v1/translate/ai/file/f1/translation',
            'DELETE https://api.test/v1/translate/ai/file/f1',
        ], $this->callLines());
        self::assertStringContainsString('PrestaShop-Supertext-Translation/1.2.3', $this->calls[0]['headers']['User-Agent']);
    }

    public function testAnEmptyReviewUrlTurnsReviewOff(): void
    {
        $client = $this->reviewingClient('');
        self::assertSame($client, $client->forReview(true));

        $client->forReview(true)->translateDocument('<div data-st-id="0">Hello</div>', 'de-CH', 'en');
        self::assertStringStartsWith('POST https://api.test/v1/', $this->callLines()[0]);

        // The API's own URL: no second client either.
        $same = $this->reviewingClient('https://api.test/v1');
        self::assertSame($same, $same->forReview(true));
    }

    public function testTheReviewServiceDefaultsToSupertexts(): void
    {
        self::assertSame('https://localizationreview-production.up.railway.app/v1/', SupertextClient::REVIEW_URL);
        // Never saved (installs from before Translation review): Supertext's review service.
        self::assertSame(SupertextClient::REVIEW_URL, SupertextClient::reviewUrlFor(false));
        self::assertSame(SupertextClient::REVIEW_URL, SupertextClient::reviewUrlFor(null));
        // Saved empty: off for every language.
        self::assertSame('', SupertextClient::reviewUrlFor(''));
        self::assertSame('https://review.test/v1/', SupertextClient::reviewUrlFor(' https://review.test/v1/ '));
        // SUPERTEXT_REVIEW_URL wins; empty keeps the setting.
        self::assertSame('http://127.0.0.1:3000/v1/', SupertextClient::reviewUrlFor('', 'http://127.0.0.1:3000/v1/'));
        self::assertSame(SupertextClient::REVIEW_URL, SupertextClient::reviewUrlFor(false, ''));
    }
}
