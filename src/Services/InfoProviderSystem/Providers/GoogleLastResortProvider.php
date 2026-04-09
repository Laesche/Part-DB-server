<?php

declare(strict_types=1);

namespace App\Services\InfoProviderSystem\Providers;

use App\Helpers\RandomizeUseragentHttpClient;
use App\Services\InfoProviderSystem\DTOs\PartDetailDTO;
use App\Services\InfoProviderSystem\DTOs\PurchaseInfoDTO;
use App\Services\InfoProviderSystem\DTOs\SearchResultDTO;
use App\Services\Parts\CategorySuggestionService;
use App\Settings\InfoProviderSystem\GoogleLastResortSettings;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class GoogleLastResortProvider implements InfoProviderInterface
{
    private readonly HttpClientInterface $httpClient;

    public function __construct(
        HttpClientInterface $httpClient,
        private readonly GoogleLastResortSettings $settings,
        private readonly CategorySuggestionService $categorySuggestionService,
    ) {
        $this->httpClient = (new RandomizeUseragentHttpClient($httpClient))->withOptions([
            'timeout' => 12,
        ]);
    }

    public function getProviderInfo(): array
    {
        return [
            'name' => 'Google Last Resort',
            'description' => 'Uses the first Google result for a GTIN/EAN and extracts basic data from the matching product page.',
            'url' => 'https://www.google.com/',
            'disabled_help' => 'Enable the provider in the provider settings to use it as the last fallback for GTIN/EAN scans.',
            'settings_class' => GoogleLastResortSettings::class,
        ];
    }

    public function getProviderKey(): string
    {
        return 'google_last_resort';
    }

    public function isActive(): bool
    {
        return $this->settings->enabled;
    }

    public function searchByKeyword(string $keyword): array
    {
        $gtin = $this->normalizeGtin($keyword);
        if ($gtin === null) {
            return [];
        }

        $resolved = $this->resolveFirstProduct($gtin);
        if ($resolved === null) {
            return [];
        }

        $providerId = $this->encodeProviderId($gtin, $resolved['url']);

        return [
            new SearchResultDTO(
                provider_key: $this->getProviderKey(),
                provider_id: $providerId,
                name: $resolved['name'],
                description: $resolved['description'],
                category: $resolved['category'],
                preview_image_url: $resolved['preview_image_url'],
                provider_url: $resolved['url'],
                gtin: $gtin,
            ),
        ];
    }

    public function getDetails(string $id): PartDetailDTO
    {
        $decoded = $this->decodeProviderId($id);
        $gtin = $decoded['gtin'] ?? $this->normalizeGtin($id);
        $url = $decoded['url'] ?? null;

        if ($gtin === null) {
            throw new \InvalidArgumentException('Invalid GTIN/EAN for Google last-resort provider.');
        }

        $resolved = $url !== null
            ? $this->fetchProductPageDetails($gtin, $url)
            : $this->resolveFirstProduct($gtin);

        if ($resolved === null) {
            throw new \RuntimeException(sprintf('No web result found for GTIN "%s".', $gtin));
        }

        return new PartDetailDTO(
            provider_key: $this->getProviderKey(),
            provider_id: $this->encodeProviderId($gtin, $resolved['url']),
            name: $resolved['name'],
            description: $resolved['description'],
            category: $resolved['category'],
            preview_image_url: $resolved['preview_image_url'],
            provider_url: $resolved['url'],
            gtin: $gtin,
            notes: 'Imported from a web search result. Review the data before saving.',
            vendor_infos: [
                new PurchaseInfoDTO(
                    distributor_name: parse_url($resolved['url'], PHP_URL_HOST) ?: 'Website',
                    order_number: $gtin,
                    prices: [],
                    product_url: $resolved['url'],
                ),
            ],
        );
    }

    public function getCapabilities(): array
    {
        return [
            ProviderCapabilities::BASIC,
            ProviderCapabilities::PICTURE,
            ProviderCapabilities::GTIN,
        ];
    }

    /**
     * @return array{url: string, name: string, description: string, category: ?string, preview_image_url: ?string}|null
     */
    private function resolveFirstProduct(string $gtin): ?array
    {
        foreach ($this->fetchCandidateUrls($gtin) as $targetUrl) {
            $details = $this->fetchProductPageDetails($gtin, $targetUrl);
            if ($details !== null) {
                return $details;
            }
        }

        return null;
    }

    /**
     * @return string[]
     */
    private function fetchCandidateUrls(string $gtin): array
    {
        $candidates = [];

        try {
            $searchResponse = $this->httpClient->request('GET', 'https://www.google.com/search', [
                'query' => [
                    'q' => $gtin,
                    'hl' => $this->settings->language,
                    'gl' => strtoupper($this->settings->country),
                    'num' => 10,
                ],
            ]);

            $html = $searchResponse->getContent();
            $dom = new Crawler($html);

            foreach ($dom->filter('a[href]') as $element) {
                $href = (string) ($element->getAttribute('href') ?? '');
                $targetUrl = $this->extractGoogleTargetUrl($href);
                if ($targetUrl !== null) {
                    $candidates[] = $targetUrl;
                }
            }
        } catch (\Throwable) {
            // Google often rate-limits or returns JS-only pages for server-side requests.
        }

        if ($candidates !== []) {
            return array_values(array_unique($candidates));
        }

        try {
            $searchResponse = $this->httpClient->request('GET', 'https://html.duckduckgo.com/html/', [
                'query' => [
                    'q' => $gtin,
                ],
            ]);

            $html = $searchResponse->getContent();
            $dom = new Crawler($html);

            foreach ($dom->filter('a.result__a[href]') as $element) {
                $href = (string) ($element->getAttribute('href') ?? '');
                $targetUrl = $this->extractDuckDuckGoTargetUrl($href);
                if ($targetUrl !== null) {
                    $candidates[] = $targetUrl;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        return array_values(array_unique($candidates));
    }

    /**
     * @return array{url: string, name: string, description: string, category: ?string, preview_image_url: ?string}|null
     */
    private function fetchProductPageDetails(string $gtin, string $url): ?array
    {
        try {
            $response = $this->httpClient->request('GET', $url);
            $html = $response->getContent();
        } catch (\Throwable) {
            return null;
        }

        $dom = new Crawler($html);

        $canonicalUrl = $this->resolveCanonicalUrl($dom, $url);
        $name = trim((string) (
            $this->getMetaContent($dom, 'og:title')
            ?? ($dom->filter('title')->count() > 0 ? $dom->filter('title')->text() : '')
        ));
        $description = trim((string) (
            $this->getMetaContent($dom, 'og:description')
            ?? $this->getMetaContent($dom, 'description')
            ?? ($dom->filter('p')->count() > 0 ? $dom->filter('p')->first()->text() : '')
        ));

        if ($name === '' && $description === '') {
            return null;
        }

        $previewImageUrl = $this->getMetaContent($dom, 'og:image') ?? $this->getMetaContent($dom, 'twitter:image');
        $category = $this->categorySuggestionService->guessCategoryPathFromTexts($name, $description);

        return [
            'url' => $canonicalUrl,
            'name' => $name !== '' ? $name : $gtin,
            'description' => $description,
            'category' => $category,
            'preview_image_url' => $previewImageUrl,
        ];
    }

    private function resolveCanonicalUrl(Crawler $dom, string $fallbackUrl): string
    {
        $canonicalUrl = null;
        if ($dom->filter('link[rel="canonical"]')->count() > 0) {
            $canonicalUrl = $dom->filter('link[rel="canonical"]')->first()->attr('href');
        } elseif ($dom->filter('meta[property="og:url"]')->count() > 0) {
            $canonicalUrl = $dom->filter('meta[property="og:url"]')->first()->attr('content');
        }

        if (!is_string($canonicalUrl) || trim($canonicalUrl) === '') {
            return $fallbackUrl;
        }

        if (parse_url($canonicalUrl, PHP_URL_SCHEME) === null) {
            $parts = parse_url($fallbackUrl);
            $scheme = $parts['scheme'] ?? 'https';
            $host = $parts['host'] ?? '';
            if ($host !== '') {
                return $scheme . '://' . $host . $canonicalUrl;
            }
        }

        return $canonicalUrl;
    }

    private function getMetaContent(Crawler $dom, string $name): ?string
    {
        $meta = $dom->filter('meta[property="' . $name . '"]');
        if ($meta->count() > 0) {
            return $meta->first()->attr('content');
        }

        $meta = $dom->filter('meta[name="' . $name . '"]');
        if ($meta->count() > 0) {
            return $meta->first()->attr('content');
        }

        return null;
    }

    private function extractGoogleTargetUrl(string $href): ?string
    {
        if ($href === '') {
            return null;
        }

        $candidate = null;
        if (str_starts_with($href, '/url?')) {
            $query = parse_url($href, PHP_URL_QUERY);
            if (is_string($query) && $query !== '') {
                parse_str($query, $params);
                $candidate = is_string($params['q'] ?? null) ? $params['q'] : null;
            }
        } elseif (preg_match('#^https?://#i', $href) === 1) {
            $candidate = $href;
        }

        if (!is_string($candidate) || filter_var($candidate, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $host = strtolower((string) parse_url($candidate, PHP_URL_HOST));
        if ($host === '' || str_contains($host, 'google.')) {
            return null;
        }

        return $candidate;
    }

    private function extractDuckDuckGoTargetUrl(string $href): ?string
    {
        if ($href === '') {
            return null;
        }

        $candidate = null;
        if (str_starts_with($href, '//duckduckgo.com/l/?')) {
            $query = parse_url('https:' . $href, PHP_URL_QUERY);
            if (is_string($query) && $query !== '') {
                parse_str($query, $params);
                $candidate = is_string($params['uddg'] ?? null) ? urldecode($params['uddg']) : null;
            }
        } elseif (preg_match('#^https?://#i', $href) === 1) {
            $candidate = $href;
        }

        if (!is_string($candidate) || filter_var($candidate, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $host = strtolower((string) parse_url($candidate, PHP_URL_HOST));
        if ($host === '' || str_contains($host, 'duckduckgo.')) {
            return null;
        }

        return $candidate;
    }

    private function normalizeGtin(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) < 8) {
            return null;
        }

        return $digits;
    }

    private function encodeProviderId(string $gtin, string $url): string
    {
        $json = json_encode(['gtin' => $gtin, 'url' => $url], JSON_INVALID_UTF8_SUBSTITUTE);
        if (!is_string($json)) {
            return $gtin;
        }

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /**
     * @return array{gtin?: string, url?: string}
     */
    private function decodeProviderId(string $value): array
    {
        $normalized = strtr($value, '-_', '+/');
        $padding = strlen($normalized) % 4;
        if ($padding > 0) {
            $normalized .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode($normalized, true);
        if (!is_string($decoded)) {
            return [];
        }

        $data = json_decode($decoded, true);
        if (!is_array($data)) {
            return [];
        }

        return $data;
    }
}
