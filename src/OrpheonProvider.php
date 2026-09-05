<?php

declare(strict_types=1);

namespace Orpheon\TranslationProvider;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Component\Translation\Dumper\XliffFileDumper;
use Symfony\Component\Translation\Exception\ProviderException;
use Symfony\Component\Translation\MessageCatalogue;
use Symfony\Component\Translation\Provider\ProviderInterface;
use Symfony\Component\Translation\TranslatorBag;
use Symfony\Component\Translation\TranslatorBagInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OrpheonProvider implements ProviderInterface
{
    private const DEFAULT_BRANCH_NAME = 'main';

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly LoggerInterface $logger,
        private readonly XliffFileDumper $xliffFileDumper,
        private readonly string $defaultLocale,
        private readonly string $endpoint,
        private readonly string $projectId,
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
            if (!$catalogue instanceof MessageCatalogue) {
                continue;
            }

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

                $response = $this->client->request('POST', \sprintf('/resources/%s/upload', rawurlencode($branchId)), [
                    'headers' => $formData->getPreparedHeaders()->toArray(),
                    'body' => $formData->bodyToString(),
                ]);

                if (300 <= $statusCode = $response->getStatusCode()) {
                    $this->logger->error(\sprintf('Unable to upload translations for domain "%s" and locale "%s" to Orpheon: (status code: "%s") "%s".', $domain, $locale, $statusCode, $response->getContent(false)));

                    if (500 <= $statusCode) {
                        throw new ProviderException(\sprintf('Unable to upload translations for domain "%s" and locale "%s" to Orpheon: (status code: "%s").', $domain, $locale, $statusCode), $response);
                    }
                }
            }
        }
    }

    /**
     * @param string[] $domains
     * @param string[] $locales
     */
    public function read(array $domains, array $locales): TranslatorBag
    {
        $domains = $domains ?: ['messages'];
        $translatorBag = new TranslatorBag();

        foreach ($locales as $locale) {
            foreach ($domains as $domain) {
                $response = $this->client->request('GET', \sprintf('/projects/%s/keys', rawurlencode($this->projectId)), [
                    'query' => [
                        'domain' => $domain,
                        'locale' => $locale,
                    ],
                ]);

                if (404 === $response->getStatusCode()) {
                    $this->logger->warning(\sprintf('Project "%s" does not exist in Orpheon.', $this->projectId));
                    continue;
                }

                if (200 !== $statusCode = $response->getStatusCode()) {
                    $this->logger->error(\sprintf('Unable to read the Orpheon response for domain "%s" and locale "%s": "%s".', $domain, $locale, $response->getContent(false)));

                    if (500 <= $statusCode) {
                        throw new ProviderException(\sprintf('Unable to read the Orpheon response for domain "%s" and locale "%s".', $domain, $locale), $response);
                    }

                    continue;
                }

                $catalogue = new MessageCatalogue($locale);

                foreach ($response->toArray()['member'] as $entry) {
                    foreach ($entry['phrases'] as $phrase) {
                        if ($phrase['locale'] !== $locale) {
                            continue;
                        }

                        $catalogue->set($entry['source'], $phrase['text'], $domain);
                    }
                }

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
        $response = $this->client->request('GET', \sprintf('/projects/%s', rawurlencode($this->projectId)));

        if (200 !== $statusCode = $response->getStatusCode()) {
            $this->logger->error(\sprintf('Unable to get the default branch for project "%s" from Orpheon: (status code: "%s") "%s".', $this->projectId, $statusCode, $response->getContent(false)));

            throw new ProviderException(\sprintf('Unable to get the default branch for project "%s" from Orpheon.', $this->projectId), $response);
        }

        $project = $response->toArray();

        if (isset($project['defaultBranch']['id'])) {
            return $project['defaultBranch']['id'];
        }

        // Projects created before the default branch was recorded only expose
        // their branch list, so fall back to the one named after the default.
        foreach ($project['branches'] ?? [] as $branch) {
            if (isset($branch['id'], $branch['name']) && self::DEFAULT_BRANCH_NAME === $branch['name']) {
                return $branch['id'];
            }
        }

        $this->logger->error(\sprintf('Project "%s" from Orpheon exposes no default branch and no branch named "%s".', $this->projectId, self::DEFAULT_BRANCH_NAME));

        throw new ProviderException(\sprintf('Unable to get the default branch for project "%s" from Orpheon.', $this->projectId), $response);
    }
}
