<?php

namespace Paymenter\Extensions\Gateways\CoinsPay\Lib;

/**
 * Texts of the module in English or Russian: lang/en.php and lang/ru.php. Plain arrays rather
 * than Laravel translations, so the unit tests read them without Paymenter.
 */
final class Text
{
    private array $strings;

    private array $fallback;

    /**
     * @param  string  $lang  A locale of Paymenter: ru, ru_RU… get Russian, anything else English.
     */
    public function __construct(string $lang)
    {
        $this->fallback = self::load('en');
        $this->strings = self::code($lang) === 'en' ? $this->fallback : self::load(self::code($lang));
    }

    /** en or ru from a locale. */
    public static function code(string $lang): string
    {
        return str_starts_with(strtolower(trim($lang)), 'ru') ? 'ru' : 'en';
    }

    /** Text by key with sprintf arguments: t('log_paid', $number, $amount). */
    public function t(string $key, mixed ...$args): string
    {
        $text = $this->strings[$key] ?? $this->fallback[$key] ?? $key;

        return $args ? vsprintf($text, $args) : $text;
    }

    private static function load(string $lang): array
    {
        $file = dirname(__DIR__) . '/lang/' . preg_replace('/[^a-z]/', '', $lang) . '.php';
        if (!is_file($file)) {
            return [];
        }
        $strings = include $file;

        return is_array($strings) ? $strings : [];
    }
}
