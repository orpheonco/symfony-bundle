<?php
declare(strict_types=1);

namespace Orpheon\TranslationProvider\DependencyInjection;

use Orpheon\TranslationProvider\OrpheonProviderFactory;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;
use Symfony\Component\DependencyInjection\ContainerBuilder;

class TranslationProviderExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {

//        Orpheon\TranslationProvider\OrpheonProviderFactory:
//        arguments:
//            $client: '@http_client'
//            $logger: '@logger'
//            $loader: '@translation.loader.xliff'
//            $xliffFileDumper: '@translation.dumper.xliff'
//            $defaultLocale: '%kernel.default_locale%'
//        tags:
//            - { name: translation.provider_factory }

        $container->register('orpheon_provider.factory', OrpheonProviderFactory::class)
            ->setArgument('$client', new Reference('http_client'))
            ->setArgument('$logger', new Reference('logger'))
            ->setArgument('$loader', new Reference('translation.loader.xliff'))
            ->setArgument('$xliffFileDumper', new Reference('translation.dumper.xliff'))
            ->setArgument('$defaultLocale', '%kernel.default_locale%')
            ->addTag('translation.provider_factory');

    }
}