<?php

declare(strict_types=1);

namespace Orpheon\TranslationProvider;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
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
        $branchId = $this->getDefaultBranchId();

        foreach ($translatorBag->getCatalogues() as $catalogue) {
            $locale = $catalogue->getLocale();

            foreach ($catalogue->getDomains() as $domain) {
                if (0 === \count($catalogue->all($domain))) {
                    continue;
                }

                $content = $this->xliffFileDumper->formatCatalogue($catalogue, $domain, [
                    'default_locale' => $this->defaultLocale,
                ]);

                $filename = \sprintf('%s.%s.xlf', $domain, $locale);

                $formData = new FormDataPart([
                    'file' => new DataPart($content, $filename, 'application/x-xliff+xml'),
                ]);

                $response = $this->client->request('POST', '/resources/'.$branchId.'/upload', [
                    'headers' => $formData->getPreparedHeaders()->toArray(),
                    'body' => $formData->bodyToString(),
                ]);

                $statusCode = $response->getStatusCode();

                if ($statusCode >= 300) {
                    $this->logger->error(\sprintf('Unable to upload translations for "%s" (%s) to Orpheon: (status code: "%s") "%s".', $domain, $locale, $statusCode, $response->getContent(false)));

                    if ($statusCode >= 500) {
                        throw new ProviderException(\sprintf('Unable to upload translations for "%s" (%s) to Orpheon: (status code: "%s").', $domain, $locale, $statusCode), $response);
                    }
                }
            }
        }
    }

    public function read(array $domains, array $locales): TranslatorBag
    {
        $translatorBag = new TranslatorBag();

        $domains[] = 'messages';
        foreach ($locales as $locale) {
            foreach ($domains as $domain) {
                $response = $this->client->request('GET', '/projects/'.$this->projectId.'/keys', [
                    'query' => [
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

    private function getDefaultBranchId(): string
    {
        $response = $this->client->request('GET', '/projects/'.$this->projectId);
        $project = $response->toArray();

        return $project['defaultBranch']['id'];
    }
}
