<?php

namespace Bolt\Extension\boltabandoned\Editcontentcustomtabs;

use Bolt\Extension\SimpleExtension;
use Silex\Application;

/**
 * Editcontentcustomtabs extension class.
 *
 * @author Alan Smithee <alan.smithee@example.com>
 */
class EditcontentcustomtabsExtension extends SimpleExtension
{
    /**
     * @inheritdoc
     */
    protected function registerServices(Application $app)
    {
        $app['storage.request.edit'] = $app->share(
            function ($app) {
                return new ContentRequest\CustomEdit(
                    $app['storage'],
                    $app['config'],
                    $app['users'],
                    $app['filesystem'],
                    $app['logger.system'],
                    $app['logger.flash']
                );
            }
        );
    }

    /**
     * @inheritdoc
     */
    protected function registerTwigPaths()
    {
        return [
            'templates' => ['position' => 'prepend', 'namespace' => 'bolt']
        ];
    }
}
