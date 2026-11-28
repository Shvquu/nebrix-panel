<?php

declare(strict_types=1);

namespace Nebrix\View;

/**
 * Message-based translator: templates use the English text as the key, so a
 * missing translation always falls back to readable English.
 * Language files: resources/lang/<code>.php returning [english => translated],
 * plus optional area files resources/lang/<code>/*.php in the same format.
 */
final class Translator
{
    private string $locale = 'en';

    /** @var array<string, array<string, string>> */
    private array $catalogs = [];

    public function __construct(private readonly string $path)
    {
    }

    public function setLocale(string $locale): void
    {
        if (preg_match('/^[a-z]{2}$/', $locale) === 1) {
            $this->locale = $locale;
        }
    }

    public function locale(): string
    {
        return $this->locale;
    }

    /**
     * @param array<string, string|int|float> $replace placeholders written as :name
     */
    public function translate(string $message, array $replace = []): string
    {
        $translated = $this->catalog($this->locale)[$message] ?? $message;

        foreach ($replace as $key => $value) {
            $translated = str_replace(':' . $key, (string) $value, $translated);
        }

        return $translated;
    }

    /**
     * Translates into a given language without changing the current one
     * (texts stored for a customer, e.g. invoice lines in their language).
     *
     * @param array<string, string|int|float> $replace placeholders written as :name
     */
    public function translateTo(string $locale, string $message, array $replace = []): string
    {
        $translated = (preg_match('/^[a-z]{2}$/', $locale) === 1 ? $this->catalog($locale) : [])[$message] ?? $message;

        foreach ($replace as $key => $value) {
            $translated = str_replace(':' . $key, (string) $value, $translated);
        }

        return $translated;
    }

    /** A date in the conventional format of the language (27.11.2026 / 2026-11-27). */
    public static function date(string $locale, \DateTimeInterface $date): string
    {
        return $date->format($locale === 'de' ? 'd.m.Y' : 'Y-m-d');
    }

    /**
     * @return array<string, string>
     */
    private function catalog(string $locale): array
    {
        if ($locale === 'en') {
            return [];
        }

        return $this->catalogs[$locale] ??= self::load($this->path, $locale);
    }

    /**
     * The catalog of a language: resources/lang/<code>.php plus the area files
     * resources/lang/<code>/*.php (later files add to, never replace, earlier ones).
     *
     * @return array<string, string>
     */
    public static function load(string $path, string $locale): array
    {
        $catalog = [];
        $files = [$path . '/' . $locale . '.php', ...(glob($path . '/' . $locale . '/*.php') ?: [])];
        foreach ($files as $file) {
            $part = is_file($file) ? require $file : [];
            if (is_array($part)) {
                $catalog += array_filter($part, 'is_string');
            }
        }

        return $catalog;
    }
}
