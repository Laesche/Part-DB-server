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

namespace App\Services\InfoProviderSystem;

use App\Entity\Parts\Category;
use App\Services\InfoProviderSystem\DTOs\ParameterDTO;
use App\Services\InfoProviderSystem\DTOs\PartDetailDTO;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final readonly class WuerthAIEnrichmentService
{
    public function __construct(
        private HttpClientInterface $client,
        private EntityManagerInterface $em,
        #[Autowire('%env(bool:WUERTH_LLM_ENABLED)%')]
        private bool $enabled = true,
        #[Autowire('%env(string:WUERTH_LLM_ENDPOINT)%')]
        private string $endpoint = 'http://127.0.0.1:11434/api/generate',
        #[Autowire('%env(string:WUERTH_LLM_MODEL)%')]
        private string $model = 'qwen3:8b',
        #[Autowire('%env(bool:WUERTH_LLM_DEBUG)%')]
        private bool $debug = false,
    ) {
    }

    public function enrichPartDetail(PartDetailDTO $detail): PartDetailDTO
    {
        if (!$this->enabled) {
            $this->logDebug('Wuerth AI enrichment skipped: WUERTH_LLM_ENABLED is false');
            return $detail;
        }

        if (trim($this->endpoint) === '') {
            $this->logDebug('Wuerth AI enrichment skipped: WUERTH_LLM_ENDPOINT is empty');
            return $detail;
        }

        try {
            $enrichment = $this->requestEnrichment($detail, $this->getSelectableCategoryPaths());
            if (!is_array($enrichment)) {
                $this->logDebug('Wuerth AI enrichment returned no usable enrichment payload');
                return $detail;
            }

            $resolvedCategory = $this->resolveCategoryPath($enrichment);
            $this->logDebug('Wuerth AI enrichment resolved category: ' . ($resolvedCategory ?? 'none'));

            return new PartDetailDTO(
                provider_key: $detail->provider_key,
                provider_id: $detail->provider_id,
                name: $this->cleanText($enrichment['readable_name'] ?? null)
                    ?? $this->cleanText($enrichment['normalized_name'] ?? null)
                    ?? $detail->name,
                description: $this->buildDescription($detail, $enrichment),
                category: $resolvedCategory,
                manufacturer: $this->cleanText($enrichment['manufacturer'] ?? null) ?? $detail->manufacturer,
                mpn: $this->cleanText($enrichment['mpn'] ?? null) ?? $detail->mpn,
                preview_image_url: $detail->preview_image_url,
                manufacturing_status: $detail->manufacturing_status,
                provider_url: $detail->provider_url,
                footprint: $detail->footprint,
                gtin: $detail->gtin,
                notes: $this->mergeNotes(
                    $detail->notes,
                    $this->cleanText($enrichment['notes'] ?? null),
                    $this->sanitizeStringList($enrichment['tags'] ?? null)
                ),
                datasheets: $detail->datasheets,
                images: $detail->images,
                parameters: $this->mergeParameters($detail->parameters ?? [], $enrichment),
                vendor_infos: $detail->vendor_infos,
                mass: $detail->mass,
                manufacturer_product_url: $detail->manufacturer_product_url,
            );
        } catch (\Throwable $e) {
            error_log('Wuerth AI enrichment failed: ' . $e::class . ' - ' . $e->getMessage());

            return $detail;
        }
    }

    /**
     * @param string[] $categoryPaths
     * @return array<string, mixed>|null
     */
    private function requestEnrichment(PartDetailDTO $detail, array $categoryPaths): ?array
    {
        $this->logDebug('Wuerth AI enrichment started for ' . ($detail->gtin ?? 'unknown'));

        $response = $this->client->request('POST', $this->endpoint, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $this->model,
                'prompt' => $this->buildPrompt($detail, $categoryPaths),
                'format' => $this->getResponseSchema(),
                'stream' => false,
            ],
        ]);

        $statusCode = $response->getStatusCode();
        $rawContent = $response->getContent(false);

        $this->logDebug('Wuerth AI request sent');
        $this->logDebug('Wuerth AI response status: ' . $statusCode);
        $this->logDebug('Wuerth AI raw response: ' . $this->truncateForLog($rawContent));

        $data = json_decode($rawContent, true);
        if (!is_array($data)) {
            $this->logDebug('Wuerth AI response JSON decode failed');
            return null;
        }

        $text = $this->extractTextFromResponse($data);
        if (!is_string($text) || $text === '') {
            $this->logDebug('Wuerth AI no text in response');
            return null;
        }

        $decoded = json_decode($text, true);
        $this->logDebug('Wuerth AI extracted text: ' . $this->truncateForLog($text));

        if (!is_array($decoded)) {
            $this->logDebug('Wuerth AI extracted text is not valid JSON');
            return null;
        }

        return $decoded;
    }

    private function buildPrompt(PartDetailDTO $detail, array $categoryPaths): string
    {
        $input = [
            'product' => [
                'ean' => $detail->gtin,
                'supplier_part_number' => $detail->vendor_infos[0]->order_number ?? null,
                'title' => $detail->name,
                'description' => $detail->description,
                'existing_notes' => $detail->notes,
            ],
            'existing_category_paths' => $categoryPaths,
        ];

        $json = json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($json)) {
            $json = '{}';
        }

        return implode("\n\n", [
            'You enrich German Wuerth fastener and hardware product data for Part-DB.',
            'Return only valid JSON matching the provided schema.',
            'Prefer one of the existing category paths when it clearly fits.',
            'If no existing path fits, create a new category path using " -> " as separator.',
            'The category path should be specific but not overly deep.',
            'Create a concise readable_name in German.',
            'Create 3 to 6 description_points in German with technical facts only.',
            'Create short lowercase tags without duplicates.',
            'For screws and fasteners, extract thread size, length in mm, drive type, head type, material, strength class, standard, surface finish, and coating when possible.',
            'If a value is unknown, return null for scalar fields and an empty array only for list fields.',
            'Input data:',
            $json,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function getResponseSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => [
                'category_path' => ['type' => ['string', 'null']],
                'category_is_new' => ['type' => 'boolean'],
                'readable_name' => ['type' => 'string'],
                'description_points' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'tags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'manufacturer' => ['type' => ['string', 'null']],
                'mpn' => ['type' => ['string', 'null']],
                'notes' => ['type' => ['string', 'null']],
                'thread_size' => ['type' => ['string', 'null']],
                'length_mm' => ['type' => ['number', 'null']],
                'drive_type' => ['type' => ['string', 'null']],
                'head_type' => ['type' => ['string', 'null']],
                'material' => ['type' => ['string', 'null']],
                'finish' => ['type' => ['string', 'null']],
                'strength_class' => ['type' => ['string', 'null']],
                'standard' => ['type' => ['string', 'null']],
                'coating' => ['type' => ['string', 'null']],
            ],
            'required' => [
                'category_path',
                'category_is_new',
                'readable_name',
                'description_points',
                'tags',
                'manufacturer',
                'mpn',
                'notes',
                'thread_size',
                'length_mm',
                'drive_type',
                'head_type',
                'material',
                'finish',
                'strength_class',
                'standard',
                'coating',
            ],
        ];
    }

    /**
     * @return string[]
     */
    private function getSelectableCategoryPaths(): array
    {
        $repo = $this->em->getRepository(Category::class);
        $categories = method_exists($repo, 'getFlatList') ? $repo->getFlatList() : [];
        $paths = [];

        foreach ($categories as $category) {
            if (!$category instanceof Category || $category->isNotSelectable()) {
                continue;
            }

            $paths[] = $category->getFullPath(' -> ');
        }

        $paths = array_values(array_unique($paths));
        sort($paths, SORT_NATURAL | SORT_FLAG_CASE);

        return $paths;
    }

    /**
     * @param array<string, mixed> $enrichment
     * @return ParameterDTO[]
     */
    private function mergeParameters(array $existingParameters, array $enrichment): array
    {
        $parameters = $existingParameters;
        $mappings = [
            'thread_size' => ['name' => 'Thread size'],
            'length_mm' => ['name' => 'Length', 'unit' => 'mm'],
            'drive_type' => ['name' => 'Drive type'],
            'head_type' => ['name' => 'Head type'],
            'material' => ['name' => 'Material'],
            'finish' => ['name' => 'Finish'],
            'strength_class' => ['name' => 'Strength class'],
            'standard' => ['name' => 'Standard'],
            'coating' => ['name' => 'Coating'],
        ];

        foreach ($mappings as $field => $config) {
            $value = $enrichment[$field] ?? null;
            if ($value === null || $value === '') {
                continue;
            }

            if (is_numeric($value)) {
                $parameters[] = new ParameterDTO(
                    name: $config['name'],
                    value_typ: (float) $value,
                    unit: $config['unit'] ?? null,
                    group: 'AI enrichment',
                );
                continue;
            }

            $parameters[] = new ParameterDTO(
                name: $config['name'],
                value_text: trim((string) $value),
                group: 'AI enrichment',
            );
        }

        return $parameters;
    }

    /**
     * @param array<string, mixed> $enrichment
     */
    private function buildDescription(PartDetailDTO $detail, array $enrichment): string
    {
        $descriptionPoints = $this->sanitizeStringList($enrichment['description_points'] ?? null);
        if ($descriptionPoints === []) {
            return $detail->description;
        }

        return implode("\n", array_map(
            static fn (string $point): string => '- ' . $point,
            $descriptionPoints
        ));
    }

    /**
     * @param array<string, mixed> $enrichment
     */
    private function resolveCategoryPath(array $enrichment): ?string
    {
        $categoryPath = $this->cleanText($enrichment['category_path'] ?? null);
        if ($categoryPath === null) {
            return null;
        }

        return implode(' -> ', array_filter(array_map(
            static fn (string $segment): string => trim($segment),
            preg_split('/\s*->\s*/', $categoryPath) ?: []
        ), static fn (string $segment): bool => $segment !== ''));
    }

    /**
     * @param string[] $tags
     */
    private function mergeNotes(?string $existingNotes, ?string $newNotes, array $tags = []): ?string
    {
        $existingNotes = $this->cleanText($existingNotes);
        $newNotes = $this->cleanText($newNotes);
        $tagNotes = $this->formatTagsAsNotes($tags);

        if ($newNotes === null) {
            $newNotes = $tagNotes;
        } elseif ($tagNotes !== null) {
            $newNotes .= "\n\n" . $tagNotes;
        }

        if ($existingNotes === null) {
            return $newNotes;
        }

        if ($newNotes === null) {
            return $existingNotes;
        }

        return $existingNotes . "\n\n" . $newNotes;
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private function sanitizeStringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $item) {
            $clean = $this->cleanText($item);
            if ($clean === null) {
                continue;
            }

            $key = mb_strtolower($clean);
            if (isset($result[$key])) {
                continue;
            }

            $result[$key] = $clean;
        }

        return array_values($result);
    }

    /**
     * @param string[] $tags
     */
    private function formatTagsAsNotes(array $tags): ?string
    {
        if ($tags === []) {
            return null;
        }

        return 'Tags: ' . implode(', ', $tags);
    }

    private function cleanText(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        return $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function extractTextFromResponse(array $data): ?string
    {
        if (isset($data['response']) && is_string($data['response']) && $data['response'] !== '') {
            return $data['response'];
        }

        return null;
    }

    private function truncateForLog(string $value, int $limit = 2000): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? '');

        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit) . '...';
    }

    private function logDebug(string $message): void
    {
        if (!$this->debug) {
            return;
        }

        error_log($message);
    }
}
