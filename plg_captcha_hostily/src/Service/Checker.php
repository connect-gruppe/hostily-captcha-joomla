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
use Joomla\Utilities\IpHelper;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Die Pruefung der Einsendung, gleich fuer die Ereignisse von Joomla 4.4 und den Anbieter von Joomla 5/6.
 */
final class Checker
{
    /**
     * @return bool true = durchlassen; abgelehnt wird mit \RuntimeException und Meldung (Joomla zeigt sie am Formular)
     *
     * @throws \RuntimeException
     */
    public static function check(Client $client, CMSApplicationInterface $app, ?string $code = null): bool
    {
        // Ohne Site-Key und Secret ist nichts geschuetzt und nichts gesperrt.
        if (!$client->isConfigured()) {
            return true;
        }
        $token = (string) $code;
        if ($token === '') {
            $token = (string) $app->getInput()->post->get(Client::TOKEN_FIELD, '', 'raw');
        }
        $token  = substr(trim($token), 0, 4096);
        $result = $client->verify($token, (string) IpHelper::getIp());
        if ($result['status'] === 'ok') {
            return true;
        }

        throw new \RuntimeException($client->message($result), 403);
    }
}
