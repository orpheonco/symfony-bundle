<?php

declare(strict_types=1);

namespace Orpheon\TranslationProvider;

use Psr\Log\LoggerInterface;
use Symfony\Component\Translation\Dumper\XliffFileDumper;
use Symfony\Component\Translation\Exception\UnsupportedSchemeException;
use Symfony\Component\Translation\Loader\LoaderInterface;
use Symfony\Component\Translation\Provider\AbstractProviderFactory;
use Symfony\Component\Translation\Provider\Dsn;
use Symfony\Component\Translation\Provider\ProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OrpheonProviderFactory extends AbstractProviderFactory
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly LoggerInterface $logger,
        private readonly LoaderInterface $loader,
        private readonly XliffFileDumper $xliffFileDumper,
        private readonly string $defaultLocale,
    ) {
    }

    // private const string HOST = 'api.orpheon.co';
    private const string HOST = 'orpheon.eu.ngrok.io';

    protected function getSupportedSchemes(): array
    {
        return ['orpheon'];
    }

    public function create(Dsn $dsn): ProviderInterface
    {
        if ('orpheon' !== $dsn->getScheme()) {
            throw new UnsupportedSchemeException($dsn, 'orpheon', $this->getSupportedSchemes());
        }

        $endpoint = 'default' === $dsn->getHost() ? self::HOST : $dsn->getHost();
        $client = $this->client->withOptions([
            'base_uri' => 'https://'.$endpoint,
        ]);

        return new OrpheonProvider(
            $client,
            $this->logger,
            $this->loader,
            $this->xliffFileDumper,
            $this->defaultLocale
        );
    }
}
