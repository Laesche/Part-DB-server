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
    private const OPENAI_URL = 'https://api.openai.com/v1/responses';

    public function __construct(
        private HttpClientInterface $client,
        private EntityManagerInterface $em,
        #[Autowire('%env(bool:OPENAI_WUERTH_ENABLED)%')]
        private bool $enabled = true,
        #[Autowire('%env(string:OPENAI_API_KEY)%')]
        private string $apiKey = '',
        #[Autowire('%env(string:OPENAI_WUERTH_ENRICH_MODEL)%')]
        private string $model = 'gpt-5.4-mini',
        #[Autowire('%env(bool:OPENAI_WUERTH_DEBUG)%')]
        private bool $debug = false,
    ) {
    }

    public function enrichPartDetail(PartDetailDTO $detail): PartDetailDTO
    {
        if (!$this->enabled) {
            $this->logDebug('Wuerth AI enrichment skipped: OPENAI_WUERTH_ENABLED is false');
            return $detail;
        }

        if ($this->apiKey === '') {
            $this->logDebug('Wuerth AI enrichment skipped: OPENAI_API_KEY is empty');
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
                name: $this->cleanText($enrichment['normalized_name'] ?? null) ?? $detail->name,
                description: $this->cleanText($enrichment['normalized_description'] ?? null) ?? $detail->description,
                category: $resolvedCategory,
                manufacturer: $this->cleanText($enrichment['manufacturer'] ?? null) ?? $detail->manufacturer,
                mpn: $this->cleanText($enrichment['mpn'] ?? null) ?? $detail->mpn,
                preview_image_url: $detail->preview_image_url,
                manufacturing_status: $detail->manufacturing_status,
                provider_url: $detail->provider_url,
                footprint: $detail->footprint,
                gtin: $detail->gtin,
                notes: $this->mergeNotes($detail->notes, $this->cleanText($enrichment['notes'] ?? null)),
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

        $response = $this->client->request('POST', self::OPENAI_URL, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'model' => $this->model,
                'instructions' => implode("\n", [
                    'Please reply in JSON format only.',
                    'Create a fitting JSON object that can be used in the controller to enrich the information for Part-DB.',
                    'Use the provided name, description, and existing categories.',
                    'If no category fits, add a new category path.',
                    'Create a list of attributes for Part-DB from all attributes you can derive from the provided name and description.',
                    'Prefer an existing category path if it clearly fits.',
                    'For screws and fasteners, extract metric thread size, length in mm, drive type, head type, material, and finish whenever possible.',
                    'Use concise normalized technical wording.',
                    'Return only valid JSON matching the schema.',
                ]),
                'input' => [[
                    'role' => 'user',
                    'content' => [[
                        'type' => 'input_text',
                        'text' => json_encode([
                            'product' => [
                                'ean' => $detail->gtin,
                                'supplier_part_number' => $detail->vendor_infos[0]->order_number ?? null,
                                'name' => $detail->name,
                                'description' => $detail->description,
                                'existing_notes' => $detail->notes,
                            ],
                            'existing_category_paths' => $categoryPaths,
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
                    ]],
                ]],
                'text' => [
                    'format' => [
                        'type' => 'json_schema',
                        'name' => 'wuerth_enrichment',
                        'schema' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'normalized_name' => ['type' => 'string'],
                                'normalized_description' => ['type' => 'string'],
                                'manufacturer' => ['type' => ['string', 'null']],
                                'mpn' => ['type' => ['string', 'null']],
                                'category_path' => ['type' => ['string', 'null']],
                                'category_is_new' => ['type' => 'boolean'],
                                'notes' => ['type' => ['string', 'null']],
                                'thread_size' => ['type' => ['string', 'null']],
                                'length_mm' => ['type' => ['number', 'null']],
                                'drive_type' => ['type' => ['string', 'null']],
                                'head_type' => ['type' => ['string', 'null']],
                                'material' => ['type' => ['string', 'null']],
                                'finish' => ['type' => ['string', 'null']],
                            ],
                            'required' => [
                                'normalized_name',
                                'normalized_description',
                                'manufacturer',
                                'mpn',
                                'category_path',
                                'category_is_new',
                                'notes',
                                'thread_size',
                                'length_mm',
                                'drive_type',
                                'head_type',
                                'material',
                                'finish',
                            ],
                        ],
                    ],
                ],
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

    private function mergeNotes(?string $existingNotes, ?string $newNotes): ?string
    {
        $existingNotes = $this->cleanText($existingNotes);
        $newNotes = $this->cleanText($newNotes);

        if ($existingNotes === null) {
            return $newNotes;
        }

        if ($newNotes === null) {
            return $existingNotes;
        }

        return $existingNotes . "\n\n" . $newNotes;
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
        if (isset($data['output_text']) && is_string($data['output_text']) && $data['output_text'] !== '') {
            return $data['output_text'];
        }

        $output = $data['output'] ?? null;
        if (!is_array($output)) {
            return null;
        }

        foreach ($output as $item) {
            if (!is_array($item)) {
                continue;
            }

            $content = $item['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }

            foreach ($content as $contentItem) {
                if (!is_array($contentItem)) {
                    continue;
                }

                $text = $contentItem['text'] ?? null;
                if (is_string($text) && $text !== '') {
                    return $text;
                }
            }
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
