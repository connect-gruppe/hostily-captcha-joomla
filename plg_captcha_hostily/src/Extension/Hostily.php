<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Captcha.Hostily
 *
 * @copyright   (C) 2026 hostily
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Hostily\Plugin\Captcha\Hostily\Extension;

use Hostily\Plugin\Captcha\Hostily\Provider\HostilyProvider;
use Hostily\Plugin\Captcha\Hostily\Service\Checker;
use Hostily\Plugin\Captcha\Hostily\Service\Client;
use Joomla\CMS\Application\CMSWebApplicationInterface;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * hostily Captcha (captcha.hostily.de) als Captcha-Plugin.
 *
 * Joomla 5 und 6 rufen onCaptchaSetup und holen sich den Anbieter (HostilyProvider). Joomla 4.4 kennt nur die
 * Ereignisse onInit / onDisplay / onCheckAnswer, die unten stehen; ab Joomla 5 sind sie ueberholt, aber noch vorhanden.
 */
final class Hostily extends CMSPlugin implements SubscriberInterface
{
    /**
     * @var    boolean
     */
    protected $autoloadLanguage = true;

    public static function getSubscribedEvents(): array
    {
        // Auf Joomla 4.4 wird der Name nie ausgeloest, das Eintragen schadet nicht.
        return ['onCaptchaSetup' => 'setupCaptcha'];
    }

    /**
     * Joomla 5/6: Anbieter in die Registry eintragen.
     *
     * @param   \Joomla\CMS\Event\Captcha\CaptchaSetupEvent  $event
     */
    public function setupCaptcha($event): void
    {
        $event->getCaptchaRegistry()->add(new HostilyProvider($this->client(), $this->getApplication()));
    }

    /**
     * Joomla 4.4: Widget-Skript laden.
     *
     * @param   string  $id  Id des Feldes
     *
     * @return  boolean
     */
    public function onInit($id = 'dynamic_hostily_1')
    {
        $app = $this->getApplication();
        if (!$app instanceof CMSWebApplicationInterface) {
            return false;
        }
        if ($this->client()->isConfigured()) {
            $this->client()->loadAssets($app);
        }

        return true;
    }

    /**
     * Joomla 4.4: HTML des Widgets.
     *
     * @param   string  $name   Name des Feldes
     * @param   string  $id     Id des Feldes
     * @param   string  $class  CSS-Klasse
     *
     * @return  string
     */
    public function onDisplay($name = null, $id = 'dynamic_hostily_1', $class = '')
    {
        return $this->client()->widget((string) $id, (string) $class, $this->getApplication()->getLanguage()->getTag());
    }

    /**
     * Joomla 4.4: Einsendung pruefen.
     *
     * @param   string  $code  Antwort aus dem Formularfeld (das Widget liefert sie im Feld hostily-captcha-token)
     *
     * @return  boolean
     *
     * @throws  \RuntimeException
     */
    public function onCheckAnswer($code = null)
    {
        return Checker::check($this->client(), $this->getApplication(), $code === null ? null : (string) $code);
    }

    /**
     * Joomla 4.4: nichts zu tun.
     */
    public function onSetupField($field = null, $element = null)
    {
        return null;
    }

    private function client(): Client
    {
        return new Client($this->params);
    }
}
