<?php

declare(strict_types=1);

namespace Orpheon\TranslationProvider;

use Psr\Log\LoggerInterface;
use Symfony\Component\Translation\Dumper\XliffFileDumper;
use Symfony\Component\Translation\Exception\UnsupportedSchemeException;
use Symfony\Component\Translation\Provider\AbstractProviderFactory;
use Symfony\Component\Translation\Provider\Dsn;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OrpheonProviderFactory extends AbstractProviderFactory
{
    private const string HOST = 'api.orpheon.co';

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly LoggerInterface $logger,
        private readonly XliffFileDumper $xliffFileDumper,
        private readonly string $defaultLocale,
    ) {
    }

    public function create(Dsn $dsn): OrpheonProvider
    {
        if ('orpheon' !== $dsn->getScheme()) {
            throw new UnsupportedSchemeException($dsn, 'orpheon', $this->getSupportedSchemes());
        }

        $endpoint = 'default' === $dsn->getHost() ? self::HOST : $dsn->getHost();
        $endpoint .= $dsn->getPort() ? ':'.$dsn->getPort() : '';

        $client = $this->client->withOptions([
            'base_uri' => 'https://'.$endpoint,
            'auth_bearer' => $this->getPassword($dsn),
        ]);

        return new OrpheonProvider(
            $client,
            $this->logger,
            $this->xliffFileDumper,
            $this->defaultLocale,
            $endpoint,
            $this->getUser($dsn),
        );
    }

    protected function getSupportedSchemes(): array
    {
        return ['orpheon'];
    }
}
