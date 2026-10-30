<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Captcha.Hostily
 *
 * @copyright   (C) 2026 hostily
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Hostily\Plugin\Captcha\Hostily\Service;

use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\Registry\Registry;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Gemeinsamer Kern fuer alle Joomla-Versionen: Einstellungen, Widget-HTML und Pruefung beim Dienst
 * (POST <Dienst>/api/v1/pruefen). Kennt keine Schnittstelle von Joomla, die es in 4.4 nicht gibt.
 *
 * Ergebnis von verify(): ['status' => 'ok'|'rejected'|'error', 'reasons' => string[]]. 'error' gibt es nur bei
 * "Bei Ausfall: blockieren"; bei "durchlassen" (Vorgabe) ist ein Ausfall oder ein Konfigurationsfehler 'ok'
 * mit dem Grund 'service' (und einem Eintrag im Protokoll).
 */
final class Client
{
    public const VERSION = '0.1.1';
    public const TOKEN_FIELD = 'hostily-captcha-token';
    public const DEFAULT_URL = 'https://captcha.hostily.de';
    public const LOG_CATEGORY = 'plg_captcha_hostily';

    private const CONFIG_ERRORS = ['invalid-input-secret', 'missing-input-secret', 'bad-request'];

    /** @var array<string, array{status: string, reasons: array<int, string>}> Ergebnisse dieser Anfrage je Token */
    private static array $verified = [];

    private static bool $loggerAdded = false;

    public function __construct(private readonly Registry $params)
    {
    }

    public function isConfigured(): bool
    {
        return $this->setting('site_key') !== '' && $this->setting('secret') !== '' && $this->serviceUrl() !== '';
    }

    /**
     * Dienst-Adresse ohne Schraegstrich am Ende; nur http(s).
     */
    public function serviceUrl(): string
    {
        $url = rtrim($this->setting('service_url'), '/');
        if ($url === '') {
            $url = self::DEFAULT_URL;
        }

        return preg_match('#^https?://[^\s/]+#i', $url) === 1 ? $url : '';
    }

    /**
     * Skript des Widgets einmal je Seite einbinden.
     */
    public function loadAssets(CMSApplicationInterface $app): void
    {
        $document = $app->getDocument();
        if (!method_exists($document, 'getWebAssetManager')) {
            return;
        }
        $document->getWebAssetManager()->registerAndUseScript(
            'plg_captcha_hostily',
            $this->serviceUrl() . '/widget/hostily-captcha.js',
            ['version' => false],
            ['defer' => true]
        );
    }

    /**
     * HTML des Widgets; leer, solange nicht eingerichtet.
     */
    public function widget(string $id, string $class, string $langTag): string
    {
        if (!$this->isConfigured()) {
            return '';
        }
        $lang = $this->setting('language');
        if ($lang === '' || $lang === 'auto') {
            $lang = strtolower(substr($langTag, 0, 2));
        }
        $e = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $html = '<hostily-captcha'
            . ($id !== '' ? ' id="' . $e($id) . '"' : '')
            . ($class !== '' ? ' class="' . $e($class) . '"' : '')
            . ' data-site-key="' . $e($this->setting('site_key')) . '"'
            . ' data-api="' . $e($this->serviceUrl() . '/api/') . '"'
            . ' data-modus="' . ($this->setting('mode') === 'unsichtbar' ? 'unsichtbar' : 'sichtbar') . '"';
        if ($lang === 'de' || $lang === 'en') {
            $html .= ' data-sprache="' . $lang . '"';
        }

        return $html . '></hostily-captcha>';
    }

    /**
     * Meldung fuer den Besucher zu einem Pruefergebnis (Sprachdateien des Plugins).
     *
     * @param array{status: string, reasons: array<int, string>} $result
     */
    public function message(array $result): string
    {
        if ($result['status'] === 'error') {
            return Text::_('PLG_CAPTCHA_HOSTILY_ERROR_UNAVAILABLE');
        }
        if (array_intersect($result['reasons'], ['token_fehlt', 'token_abgelaufen', 'token_bereits_verwendet']) !== []) {
            return Text::_('PLG_CAPTCHA_HOSTILY_ERROR_EXPIRED');
        }

        return Text::_('PLG_CAPTCHA_HOSTILY_ERROR_REJECTED');
    }

    /**
     * @return array{status: string, reasons: array<int, string>}
     */
    public function verify(string $token, string $ip): array
    {
        // Ein leeres Token wird trotzdem gesendet: ist der Dienst erreichbar, ist die Einsendung abgelehnt; ist er es
        // nicht, gilt "Bei Ausfall" (sonst sperrt ein nicht ladendes Widget alle Besucher aus).
        if ($token !== '' && isset(self::$verified[$token])) {
            return self::$verified[$token];
        }
        $result = $this->ask($token, $ip);
        if ($token !== '') {
            // Ein Token gilt nur einmal, derselbe Aufruf kann aber mehrfach pruefen.
            if (\count(self::$verified) >= 50) {
                self::$verified = [];
            }
            self::$verified[$token] = $result;
        }

        return $result;
    }

    /**
     * @return array{status: string, reasons: array<int, string>}
     */
    private function ask(string $token, string $ip): array
    {
        $payload = json_encode(['secret' => $this->setting('secret'), 'response' => $token, 'remoteip' => $ip]);
        $code    = 0;
        $raw     = '';

        try {
            [$code, $raw] = $this->post($this->serviceUrl() . '/api/v1/pruefen', (string) $payload);
        } catch (\Throwable $e) {
            return $this->failure('service unreachable: ' . $e->getMessage());
        }
        $data = json_decode($raw, true);

        if ($code !== 200 || !\is_array($data) || !isset($data['success'])) {
            return $this->failure('unexpected answer from service (HTTP ' . $code . ')');
        }
        $codes = [];
        if (isset($data['error-codes']) && \is_array($data['error-codes'])) {
            foreach ($data['error-codes'] as $c) {
                if (\is_string($c)) {
                    $codes[] = (string) preg_replace('/[^a-z0-9_-]/i', '', $c);
                }
            }
        }
        if (array_intersect($codes, self::CONFIG_ERRORS) !== []) {
            return $this->failure('service rejected the configuration: ' . implode(',', $codes));
        }
        if ($data['success'] === true) {
            return ['status' => 'ok', 'reasons' => []];
        }
        $reasons = [];
        if (isset($data['gruende']) && \is_array($data['gruende'])) {
            foreach ($data['gruende'] as $r) {
                if (\is_string($r)) {
                    $reasons[] = substr((string) preg_replace('/[^a-z0-9_:.-]/i', '', $r), 0, 60);
                }
            }
        }

        return ['status' => 'rejected', 'reasons' => $reasons];
    }

    /**
     * Schlanker HTTP-Client ohne Abhaengigkeit: cURL, sonst Streams. Keine Weiterleitungen.
     *
     * @return array{0: int, 1: string} HTTP-Status und Antworttext
     */
    private function post(string $url, string $json): array
    {
        $ua = 'hostily-captcha-joomla/' . self::VERSION;
        if (\function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $json,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
                CURLOPT_USERAGENT => $ua,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => 8,
            ]);
            $body = curl_exec($ch);
            $err  = curl_error($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($body === false) {
                throw new \RuntimeException($err !== '' ? $err : 'request failed');
            }

            return [$http, (string) $body];
        }

        $context = stream_context_create(['http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\nAccept: application/json\r\nUser-Agent: " . $ua . "\r\n",
            'content' => $json,
            'timeout' => 8,
            'follow_location' => 0,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new \RuntimeException('request failed');
        }
        $http = 0;
        if (isset($http_response_header[0]) && preg_match('#^HTTP/\S+\s+(\d{3})#', $http_response_header[0], $m)) {
            $http = (int) $m[1];
        }

        return [$http, $body];
    }

    /**
     * Dienst- oder Konfigurationsproblem: durchlassen (Vorgabe) oder blockieren, immer protokollieren.
     *
     * @return array{status: string, reasons: array<int, string>}
     */
    private function failure(string $why): array
    {
        $this->log('hostily Captcha: ' . substr($why, 0, 300));
        if ($this->setting('on_error') === 'block') {
            return ['status' => 'error', 'reasons' => ['service']];
        }

        return ['status' => 'ok', 'reasons' => ['service']];
    }

    /**
     * Eintrag in administrator/logs/plg_captcha_hostily.log.php (und in jedes andere Protokoll, das Joomla fuehrt).
     */
    private function log(string $message): void
    {
        try {
            if (!self::$loggerAdded) {
                self::$loggerAdded = true;
                Log::addLogger(['text_file' => 'plg_captcha_hostily.log.php'], Log::ALL, [self::LOG_CATEGORY]);
            }
            Log::add($message, Log::ERROR, self::LOG_CATEGORY);
        } catch (\Throwable $e) {
            // Protokollfehler duerfen das Formular nicht stoeren.
        }
    }

    private function setting(string $name): string
    {
        $value = $this->params->get($name, '');

        return \is_string($value) ? trim($value) : '';
    }
}
