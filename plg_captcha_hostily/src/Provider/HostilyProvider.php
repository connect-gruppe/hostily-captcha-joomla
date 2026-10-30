<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Captcha.Hostily
 *
 * @copyright   (C) 2026 hostily
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Hostily\Plugin\Captcha\Hostily\Provider;

use Hostily\Plugin\Captcha\Hostily\Service\Checker;
use Hostily\Plugin\Captcha\Hostily\Service\Client;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Captcha\CaptchaProviderInterface;
use Joomla\CMS\Form\FormField;

// phpcs:disable PSR1.Files.SideEffects
\defined('_JEXEC') or die;
// phpcs:enable PSR1.Files.SideEffects

/**
 * Captcha-Anbieter fuer Joomla 5 und 6 (Registrierung ueber onCaptchaSetup). Joomla 4.4 hat diese Schnittstelle
 * nicht und nutzt die Ereignisse des Plugins (onInit, onDisplay, onCheckAnswer).
 */
final class HostilyProvider implements CaptchaProviderInterface
{
    public function __construct(private readonly Client $client, private readonly CMSApplicationInterface $app)
    {
    }

    public function getName(): string
    {
        return 'hostily';
    }

    public function display(string $name = '', array $attributes = []): string
    {
        if (!$this->client->isConfigured()) {
            return '';
        }
        $this->client->loadAssets($this->app);

        return $this->client->widget(
            (string) ($attributes['id'] ?? ''),
            (string) ($attributes['class'] ?? ''),
            $this->app->getLanguage()->getTag()
        );
    }

    public function checkAnswer(?string $code = null): bool
    {
        return Checker::check($this->client, $this->app, $code);
    }

    public function setupField(FormField $field, \SimpleXMLElement $element): void
    {
    }
}
