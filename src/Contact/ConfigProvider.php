<?php
/**
 * @license http://opensource.org/licenses/BSD-2-Clause BSD-2-Clause
 * @copyright Copyright (c) Matthew Weier O'Phinney
 */

declare(strict_types=1);

namespace Mwop\Contact;

use Phly\EventDispatcher\ListenerProvider\AttachableListenerProvider;

class ConfigProvider
{
    public function __invoke() : array
    {
        return [
            'contact'      => $this->getConfig(),
            'dependencies' => $this->getDependencies(),
            'templates'    => $this->getTemplateConfig(),
        ];
    }

    public function getConfig() : array
    {
        return [
            'recaptcha_pub_key'  => null,
            'recaptcha_priv_key' => null,
            'message' => [
                'to'   => null,
                'from' => null,
                'sender' => [
                    'address' => null,
                    'name'    => null,
                ],
            ],
        ];
    }

    public function getDependencies() : array
    {
        return [
            'factories' => [
                Handler\DisplayContactFormHandler::class => Handler\DisplayContactFormHandlerFactory::class,
                Handler\ProcessContactFormHandler::class => Handler\ProcessContactFormHandlerFactory::class,
                Handler\DisplayThankYouHandler::class    => Handler\DisplayThankYouHandlerFactory::class,
                Listener\SendMessageListener::class      => Listener\SendMessageListenerFactory::class,
            ],
            'delegators' => [
                AttachableListenerProvider::class => [
                    Listener\SendMessageListenerDelegator::class,
                ],
            ],
        ];
    }

    public function getTemplateConfig() : array
    {
        return [
            'paths' => [
                'contact' => [__DIR__ . '/templates'],
            ],
        ];
    }
}
