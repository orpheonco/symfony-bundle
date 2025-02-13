<?php

declare(strict_types=1);

namespace Orpheon\TranslationProvider;

use Psr\Log\LoggerInterface;
use Symfony\Component\Translation\Dumper\XliffFileDumper;
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
        // TODO: Implement write() method.
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

                $data = $response->toArray()['hydra:member'];
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
