<?php

declare(strict_types=1);

namespace Supertext\PrestaShop\Sdk\V1;

/**
 * Packs segments into the HTML document sent to Supertext and reads them back from the translation.
 *
 * Every segment becomes one `<div data-st-id="N">` (N = its position). Supertext translates each such
 * element on its own and keeps the attribute, so the translation can be matched back even if the
 * elements come back reordered, nested differently or with other attributes added.
 */
final class HtmlDocument
{
    private const HEAD = "<!DOCTYPE html>\n<html><head><meta charset=\"utf-8\"></head><body>\n";
    private const FOOT = "</body></html>\n";

    /** Any element carrying data-st-id, with the value in double, single or no quotes. */
    private const START = '/<([a-zA-Z][a-zA-Z0-9]*)(?=[\s>\/])[^>]*?\sdata-st-id\s*=\s*(?:"(\d+)"|\'(\d+)\'|(\d+))[^>]*>/';

    /** ASCII whitespace as HTML defines it (no vertical tab, no Unicode spaces). */
    private const WHITESPACE = " \t\n\r\f";

    /** Record separator: stands for a line break while the other whitespace is collapsed. */
    private const LINE_BREAK = "\x1E";

    /**
     * @param list<Segment> $segments
     */
    public static function build(array $segments): string
    {
        $html = self::HEAD;
        foreach ($segments as $index => $segment) {
            $html .= '<div data-st-id="' . $index . '">' . self::pack($segment) . "</div>\n";
        }

        return $html . self::FOOT;
    }

    /**
     * What goes between `<div data-st-id>` and `</div>` for a segment (also what the batching counts).
     */
    public static function pack(Segment $segment): string
    {
        if ($segment->html) {
            return $segment->text;
        }

        $text = str_replace(["\r\n", "\r"], "\n", $segment->text);
        $text = strtr($text, ['&' => '&amp;', '<' => '&lt;', '>' => '&gt;', '"' => '&quot;', "'" => '&#39;']);

        return str_replace("\n", '<br>', $text);
    }

    /**
     * Reads the segments back from a (translated) document.
     *
     * @param list<Segment> $segments the segments as sent (only `html` is used)
     *
     * @return list<string|null> one result per segment, null when it is missing
     */
    public static function parse(string $html, array $segments): array
    {
        $count = \count($segments);
        $out = array_fill(0, $count, null);
        $offset = 0;

        while (preg_match(self::START, $html, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $tag = $m[1][0];
            $id = (int) self::firstNonEmpty($m[2][0] ?? '', $m[3][0] ?? '', $m[4][0] ?? '');
            $contentStart = $m[0][1] + \strlen($m[0][0]);

            [$end, $after] = self::findClose($html, $tag, $contentStart);
            if ($end < 0) {
                // No closing tag: skip this element, keep looking for others inside it.
                $offset = $contentStart;
                continue;
            }

            if ($id < $count && $out[$id] === null) {
                $inner = substr($html, $contentStart, $end - $contentStart);
                $out[$id] = $segments[$id]->html ? trim($inner, self::WHITESPACE) : self::plain($inner);
            }
            $offset = $after;
        }

        return $out;
    }

    /**
     * The trimmed inner HTML of the first element with each wanted data-st-id, read exactly as parse()
     * reads HTML segments (translation files: positions come from the file, so no list is allocated).
     *
     * @internal
     *
     * @param array<int, true> $positions the wanted data-st-id values as keys
     *
     * @return array<int, string> position => inner HTML, for the positions found
     */
    public static function htmlSegments(string $html, array $positions): array
    {
        $out = [];
        $offset = 0;

        while (preg_match(self::START, $html, $m, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $tag = $m[1][0];
            $id = (int) self::firstNonEmpty($m[2][0] ?? '', $m[3][0] ?? '', $m[4][0] ?? '');
            $contentStart = $m[0][1] + \strlen($m[0][0]);

            [$end, $after] = self::findClose($html, $tag, $contentStart);
            if ($end < 0) {
                $offset = $contentStart;
                continue;
            }

            if (isset($positions[$id]) && !isset($out[$id])) {
                $out[$id] = trim(substr($html, $contentStart, $end - $contentStart), self::WHITESPACE);
            }
            $offset = $after;
        }

        return $out;
    }

    /**
     * Plain text from a translated fragment: line breaks from `<br>`, other tags removed, whitespace
     * collapsed, entities decoded.
     */
    public static function plain(string $inner): string
    {
        $text = (string) preg_replace('/<br\s*\/?>/i', self::LINE_BREAK, $inner);
        $text = (string) preg_replace('/<[^>]*>/', '', $text);
        $text = (string) preg_replace('/[ \t\n\r\f]+/', ' ', $text);
        $text = (string) preg_replace('/ ?' . self::LINE_BREAK . ' ?/', "\n", $text);
        $text = (string) preg_replace('/\A +| +\z/', '', $text);

        return self::decode($text);
    }

    /**
     * Position of the matching closing tag and the position after it, counting nested tags of the same name.
     *
     * @return array{int, int} [-1, -1] when there is none
     */
    private static function findClose(string $html, string $tag, int $offset): array
    {
        $pattern = '/<(\/?)' . preg_quote($tag, '/') . '(?=[\s>\/])[^>]*?(\/?)>/i';
        $depth = 1;
        while (preg_match($pattern, $html, $t, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $offset = $t[0][1] + \strlen($t[0][0]);
            if ($t[1][0] !== '') {
                $depth--;
            } elseif ($t[2][0] === '') {
                $depth++; // a self-closing <div/> doesn't open anything
            }
            if ($depth === 0) {
                return [$t[0][1], $offset];
            }
        }

        return [-1, -1];
    }

    /**
     * Decodes the entities Supertext may produce; unknown named entities are left as they are.
     *
     * @internal
     */
    public static function decode(string $text): string
    {
        return (string) preg_replace_callback(
            '/&(#[xX][0-9a-fA-F]+|#[0-9]+|amp|lt|gt|quot|apos|nbsp);/',
            static function (array $m): string {
                $entity = $m[1];
                if ($entity[0] !== '#') {
                    return ['amp' => '&', 'lt' => '<', 'gt' => '>', 'quot' => '"', 'apos' => "'", 'nbsp' => "\u{00A0}"][$entity];
                }
                $digits = ltrim($entity[1] === 'x' || $entity[1] === 'X' ? substr($entity, 2) : substr($entity, 1), '0');
                // Too many digits would overflow; any such number is out of range anyway.
                if (\strlen($digits) > 8) {
                    return $m[0];
                }
                $codePoint = $entity[1] === 'x' || $entity[1] === 'X' ? (int) hexdec($digits === '' ? '0' : $digits) : (int) $digits;
                // Surrogates can't be encoded in UTF-8: keep them as they are, like out-of-range numbers.
                if ($codePoint <= 0 || $codePoint > 0x10FFFF || ($codePoint >= 0xD800 && $codePoint <= 0xDFFF)) {
                    return $m[0];
                }

                return Utf8::chr($codePoint);
            },
            $text,
        );
    }

    private static function firstNonEmpty(string ...$values): string
    {
        foreach ($values as $value) {
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
