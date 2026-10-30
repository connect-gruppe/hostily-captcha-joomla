<?php

/**
 * @package     Joomla.Plugin
 * @subpackage  Captcha.Hostily
 *
 * @copyright   (C) 2026 hostily
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Hostily\Plugin\Captcha\Hostily\Extension\Hostily;
use Joomla\CMS\Extension\PluginInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\DI\Container;
use Joomla\DI\ServiceProviderInterface;
use Joomla\Event\DispatcherInterface;

return new class () implements ServiceProviderInterface {
    /**
     * Registers the service provider with a DI container.
     *
     * @param   Container  $container  The DI container.
     *
     * @return  void
     */
    public function register(Container $container)
    {
        $container->set(
            PluginInterface::class,
            function (Container $container) {
                $config = (array) PluginHelper::getPlugin('captcha', 'hostily');

                // Joomla 4 gibt dem Plugin den Dispatcher mit, ab Joomla 5 genuegt die Konfiguration.
                if (version_compare(JVERSION, '5.0', '<')) {
                    $plugin = new Hostily($container->get(DispatcherInterface::class), $config);
                } else {
                    $plugin = new Hostily($config);
                }
                $plugin->setApplication(Factory::getApplication());

                return $plugin;
            }
        );
    }
};
