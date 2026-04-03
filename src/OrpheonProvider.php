<?php

declare(strict_types=1);

namespace Orpheon\TranslationProvider;

use Psr\Log\LoggerInterface;
use Symfony\Component\Translation\Dumper\XliffFileDumper;
use Symfony\Component\Translation\Exception\ProviderException;
use Symfony\Component\Translation\Loader\LoaderInterface;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Provider\ProviderInterface;
use Symfony\Component\Translation\TranslatorBag;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OrpheonProvider implements ProviderInterface
{
    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly LoggerInterface $logger,
        private readonly LoaderInterface $loader,
        private readonly XliffFileDumper $xliffFileDumper,
        private readonly string $endpoint,
        private readonly string $projectId,
        private readonly string $defaultLocale,
    ) {
    }

    public function __toString(): string
    {
        return \sprintf('orpheon://%s', $this->endpoint);
    }

    public function write(TranslatorBagInterface $translatorBag): void
    {
        $keysByDomain = [];

        foreach ($translatorBag->getCatalogues() as $catalogue) {
            $locale = $catalogue->getLocale();

            foreach ($catalogue->getDomains() as $domain) {
                foreach ($catalogue->all($domain) as $source => $translation) {
                    $keysByDomain[$domain][$source][] = ['locale' => $locale, 'text' => $translation];
                }
            }
        }

        $existingKeys = $this->getExistingKeys();

        foreach ($keysByDomain as $domain => $keys) {
            foreach ($keys as $source => $phrases) {
                $apiDomain = 'messages' !== $domain ? $domain : null;
                $existingKeyId = $existingKeys[$apiDomain][$source] ?? null;

                if (null !== $existingKeyId) {
                    $response = $this->client->request('PATCH', '/keys/'.$existingKeyId, [
                        'json' => ['phrases' => $phrases],
                        'headers' => ['Content-Type' => 'application/merge-patch+json'],
                    ]);
                } else {
                    $response = $this->client->request('POST', '/projects/'.$this->projectId.'/keys', [
                        'json' => [
                            'source' => $source,
                            'domain' => $apiDomain,
                            'phrases' => $phrases,
                        ],
                        'headers' => ['Content-Type' => 'application/ld+json'],
                    ]);
                }

                $statusCode = $response->getStatusCode();

                if ($statusCode >= 300) {
                    $this->logger->error(\sprintf('Unable to push translation key "%s" to Orpheon: (status code: "%s") "%s".', $source, $statusCode, $response->getContent(false)));

                    if ($statusCode >= 500) {
                        throw new ProviderException(\sprintf('Unable to push translation key "%s" to Orpheon: (status code: "%s").', $source, $statusCode), $response);
                    }
                }
            }
        }
    }

    /**
     * @return array<string|null, array<string, string>> Indexed by domain then source, value is the key ID
     */
    private function getExistingKeys(): array
    {
        $response = $this->client->request('GET', '/projects/'.$this->projectId.'/keys');

        $keys = [];
        foreach ($response->toArray()['member'] as $entry) {
            $keys[$entry['domain'] ?? null][$entry['source']] = $entry['id'];
        }

        return $keys;
    }

    public function read(array $domains, array $locales): TranslatorBag
    {
        $translatorBag = new TranslatorBag();

        $domains[] = 'messages';
        foreach ($locales as $locale) {
            foreach ($domains as $domain) {
                $response = $this->client->request('GET', '/projects/'.$this->projectId.'/keys', [
                    'query' => [
                        // 'domain' => $domain,
                        'locale' => $locale,
                    ],
                ]);

                $data = $response->toArray()['member'];
                $catalogue = new MessageCatalogue($locale);
                foreach ($data as $entry) {
                    foreach ($entry['phrases'] as $phrase) {
                        if ($phrase['locale'] !== $locale) {
                            continue;
                        }

                        $catalogue->set($entry['source'], $phrase['text'], $domain);
                    }
                }

                $this->xliffFileDumper->formatCatalogue($catalogue, $domain, ['default_locale' => $this->defaultLocale]);

                $translatorBag->addCatalogue($catalogue);
            }
        }

        return $translatorBag;
    }

    public function delete(TranslatorBagInterface $translatorBag): void
    {
        // TODO: Implement delete() method.
    }
}
