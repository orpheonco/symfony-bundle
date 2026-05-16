<?php

declare(strict_types=1);

namespace Orpheon\TranslationProvider\DependencyInjection;

use Orpheon\TranslationProvider\OrpheonProviderFactory;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

final class TranslationProviderExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $container->register('orpheon_provider.factory', OrpheonProviderFactory::class)
            ->setArguments([
                '$client' => new Reference('http_client'),
                '$logger' => new Reference('logger'),
                '$xliffFileDumper' => new Reference('translation.dumper.xliff'),
                '$defaultLocale' => '%kernel.default_locale%',
            ])
            ->addTag('translation.provider_factory');
    }
}
