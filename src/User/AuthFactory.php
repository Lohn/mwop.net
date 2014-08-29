<?php
namespace Mwop\User;

class AuthFactory
{
    public function __invoke($services)
    {
        $config = $services->get('Config');
        $config = $config['opauth'];
        return new Auth($config, $services->get('session'));
    }
}
