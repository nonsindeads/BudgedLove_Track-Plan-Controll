<?php
declare(strict_types=1);

function hb_available_locales(): array
{
    return [
        'de' => 'Deutsch',
        'en' => 'English',
    ];
}

function hb_normalize_locale(?string $lang): string
{
    $lang = strtolower(trim((string)$lang));
    return array_key_exists($lang, hb_available_locales()) ? $lang : 'de';
}

function hb_get_locale(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        @session_start();
    }
    $lang = $_SESSION['lang'] ?? '';
    $requested = $_POST['lang'] ?? $_GET['lang'] ?? '';
    if ($requested !== '') {
        $lang = hb_normalize_locale($requested);
        $_SESSION['lang'] = $lang;
    }
    if ($lang === '') {
        $lang = 'de';
        $_SESSION['lang'] = $lang;
    }
    return $lang;
}

function hb_set_locale(string $lang): void
{
    if (session_status() !== PHP_SESSION_ACTIVE && !headers_sent()) {
        @session_start();
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $_SESSION['lang'] = hb_normalize_locale($lang);
}

function hb_t(string $key, ?string $fallback = null, array $vars = []): string
{
    $lang = hb_get_locale();
    $translations = hb_load_translations($lang);
    $value = $translations[$key] ?? $fallback ?? $key;
    if ($vars) {
        $replace = [];
        foreach ($vars as $name => $val) {
            $replace['{' . $name . '}'] = (string)$val;
        }
        $value = strtr($value, $replace);
    }
    return $value;
}

function hb_load_translations(string $lang): array
{
    static $cache = [];
    $lang = hb_normalize_locale($lang);
    if (isset($cache[$lang])) {
        return $cache[$lang];
    }

    $translations = hb_load_translation_file($lang);

    if (function_exists('hb_get_pdo')) {
        try {
            $pdo = hb_get_pdo();
            $stmt = $pdo->prepare('select translation_key, value from translations where lang = :lang');
            $stmt->execute(['lang' => $lang]);
            $overrides = [];
            foreach ($stmt->fetchAll() as $row) {
                $overrides[(string)$row['translation_key']] = (string)$row['value'];
            }
            $translations = array_merge($translations, $overrides);
        } catch (Throwable $e) {
            // ignore translation override errors
        }
    }

    $cache[$lang] = $translations;
    return $translations;
}

function hb_load_translation_file(string $lang): array
{
    $path = dirname(__DIR__) . '/lang/' . $lang . '.php';
    if (!is_file($path)) {
        return [];
    }
    $data = require $path;
    return is_array($data) ? $data : [];
}
