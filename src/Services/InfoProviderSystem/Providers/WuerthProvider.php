<?php
/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 *
 *  Copyright (C) 2019 - 2026 Jan Böhmer (https://github.com/jbtronics)
 *
 *  This program is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Affero General Public License as published
 *  by the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Affero General Public License for more details.
 *
 *  You should have received a copy of the GNU Affero General Public License
 *  along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

declare(strict_types=1);

namespace App\Services\InfoProviderSystem\Providers;

use App\Services\InfoProviderSystem\DTOs\PartDetailDTO;
use App\Services\InfoProviderSystem\DTOs\PurchaseInfoDTO;
use App\Services\InfoProviderSystem\DTOs\SearchResultDTO;
use App\Services\InfoProviderSystem\WuerthAIEnrichmentService;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class WuerthProvider implements InfoProviderInterface
{
    private const SEARCH_URL = 'https://eshop.wuerth.de/is-bin/INTERSHOP.enfinity/WFS/1401-B1-Site/de_DE/-/EUR/ViewParametricSearch-Suggest';
    private const DEFAULT_CATEGORY = 'Wuerth';

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly ?WuerthAIEnrichmentService $aiEnrichmentService = null,
    )
    {
    }

    public function getProviderInfo(): array
    {
        return [
            'name' => 'Wuerth',
            'description' => 'Looks up products by EAN on the Wuerth eShop.',
            'url' => 'https://eshop.wuerth.de/',
        ];
    }

    public function getProviderKey(): string
    {
        return 'wuerth';
    }

    public function isActive(): bool
    {
        return true;
    }

    public function searchByKeyword(string $keyword): array
    {
        $product = $this->lookupProduct($keyword);
        if ($product === null) {
            return [];
        }

        return [$this->mapProductToSearchResult($keyword, $product)];
    }

    public function getDetails(string $id): PartDetailDTO
    {
        $product = $this->lookupProduct($id);
        if ($product === null) {
            throw new \RuntimeException(sprintf('No Wuerth product found for EAN "%s".', $id));
        }

        $detail = $this->mapProductToDetail($id, $product);

        return $this->aiEnrichmentService?->enrichPartDetail($detail) ?? $detail;
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
     * @return array<string, mixed>|null
     */
    private function lookupProduct(string $ean): ?array
    {
        $response = $this->client->request('GET', self::SEARCH_URL, [
            'query' => [
                'SearchTerm' => $ean,
                'CategoriesSearchActivated' => 'true',
            ],
        ]);

        $json = $response->toArray(false);
        if (!is_array($json) || !empty($json['nothingFound'])) {
            return null;
        }

        $products = $json['products'] ?? null;
        if (!is_array($products) || !isset($products[0]) || !is_array($products[0])) {
            return null;
        }

        return $products[0];
    }

    /**
     * @param array<string, mixed> $product
     */
    private function mapProductToSearchResult(string $ean, array $product): SearchResultDTO
    {
        return new SearchResultDTO(
            provider_key: $this->getProviderKey(),
            provider_id: $ean,
            name: (string) ($product['label'] ?? $product['value'] ?? $ean),
            description: (string) ($product['description'] ?? ''),
            category: self::DEFAULT_CATEGORY,
            preview_image_url: isset($product['image']) && is_string($product['image']) ? $product['image'] : null,
            provider_url: $this->extractProductUrl($product),
            gtin: $ean,
        );
    }

    /**
     * @param array<string, mixed> $product
     */
    private function mapProductToDetail(string $ean, array $product): PartDetailDTO
    {
        $supplierPartNumber = isset($product['value']) && is_string($product['value']) ? $product['value'] : $ean;

        return new PartDetailDTO(
            provider_key: $this->getProviderKey(),
            provider_id: $ean,
            name: (string) ($product['label'] ?? $supplierPartNumber),
            description: (string) ($product['description'] ?? ''),
            category: self::DEFAULT_CATEGORY,
            preview_image_url: isset($product['image']) && is_string($product['image']) ? $product['image'] : null,
            provider_url: $this->extractProductUrl($product),
            gtin: $ean,
            vendor_infos: [
                new PurchaseInfoDTO(
                    distributor_name: 'Wuerth',
                    order_number: $supplierPartNumber,
                    prices: [],
                    product_url: $this->extractProductUrl($product),
                ),
            ],
        );
    }

    /**
     * @param array<string, mixed> $product
     */
    private function extractProductUrl(array $product): ?string
    {
        $target = $product['target'] ?? null;
        return is_string($target) && $target !== '' ? $target : null;
    }
}
