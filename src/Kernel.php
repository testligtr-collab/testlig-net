<?php

declare(strict_types=1);

namespace App;

use App\Release\ReleaseRevision;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Resource\FileExistenceResource;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * Bakes the release revision into the compiled container of this release. The deploy workflow packs the
     * REVISION file into the tarball, and the container is compiled inside that release directory. An empty
     * value means "no valid revision" (local development, tests), and no revision header is sent then.
     */
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $file = $this->getProjectDir().'/REVISION';
        $container->addResource(new FileExistenceResource($file));
        if (is_file($file)) {
            $container->addResource(new FileResource($file));
        }
        $container->setParameter('app.release_revision', ReleaseRevision::fromFile($file) ?? '');
    }
}
