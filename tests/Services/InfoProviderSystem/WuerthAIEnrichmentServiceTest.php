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

namespace App\Tests\Services\InfoProviderSystem;

use App\Entity\Parts\Category;
use App\Services\InfoProviderSystem\DTOs\ParameterDTO;
use App\Services\InfoProviderSystem\DTOs\PartDetailDTO;
use App\Services\InfoProviderSystem\DTOs\PurchaseInfoDTO;
use App\Services\InfoProviderSystem\WuerthAIEnrichmentService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WuerthAIEnrichmentServiceTest extends TestCase
{
    public function testEnrichPartDetailUsesLocalLlmResponseAndExistingCategories(): void
    {
        $root = new Category();
        $root->setName('Befestigung');
        $child = new Category();
        $child->setName('Schrauben');
        $child->setParent($root);

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            self::assertSame('POST', $method);
            self::assertSame('http://192.168.178.33:11434/api/generate', $url);
            self::assertSame('qwen3:8b', $options['json']['model']);
            self::assertFalse($options['json']['stream']);
            self::assertIsString($options['json']['prompt']);
            self::assertStringContainsString('Senkschraube mit Schlitz DIN 963', $options['json']['prompt']);
            self::assertStringContainsString('Befestigung -> Schrauben', $options['json']['prompt']);

            return new MockResponse((string) json_encode([
                'response' => json_encode([
                    'category_path' => 'Befestigung -> Schrauben',
                    'category_is_new' => false,
                    'readable_name' => 'Senkschraube mit Schlitz DIN 963 M2x5',
                    'description_points' => [
                        'Senkkopf mit Schlitzantrieb nach DIN 963',
                        'Gewinde M2 bei 5 mm Laenge',
                        'Stahl 4.8, blau passiviert verzinkt',
                    ],
                    'tags' => ['senkschraube', 'schlitz', 'din963', 'm2x5'],
                    'manufacturer' => 'Wuerth',
                    'mpn' => '0687914631',
                    'notes' => 'Aus Produkttitel und SKU abgeleitet.',
                    'thread_size' => 'M2',
                    'length_mm' => 5,
                    'drive_type' => 'Schlitz',
                    'head_type' => 'Senkkopf',
                    'material' => 'Stahl',
                    'finish' => 'verzinkt blau passiviert',
                    'strength_class' => '4.8',
                    'standard' => 'DIN 963',
                    'coating' => 'A2K',
                ], JSON_UNESCAPED_UNICODE),
            ], JSON_UNESCAPED_UNICODE));
        });

        $service = new WuerthAIEnrichmentService(
            client: $httpClient,
            em: $this->createEntityManager([$root, $child]),
            enabled: true,
            endpoint: 'http://192.168.178.33:11434/api/generate',
            model: 'qwen3:8b',
            debug: false,
        );

        $detail = new PartDetailDTO(
            provider_key: 'wuerth',
            provider_id: '4056807624631',
            name: 'Senkschraube mit Schlitz DIN 963, Stahl 4.8, verzinkt blau passiviert (A2K)',
            description: 'Original supplier title',
            gtin: '4056807624631',
            notes: 'Bestehende Notiz',
            vendor_infos: [
                new PurchaseInfoDTO(
                    distributor_name: 'Wuerth',
                    order_number: '0687914631',
                    prices: [],
                ),
            ],
            parameters: [
                new ParameterDTO(name: 'Existing', value_text: 'keep', group: 'Original'),
            ],
        );

        $enriched = $service->enrichPartDetail($detail);

        $this->assertSame('Senkschraube mit Schlitz DIN 963 M2x5', $enriched->name);
        $this->assertSame('Befestigung -> Schrauben', $enriched->category);
        $this->assertStringContainsString('Senkkopf mit Schlitzantrieb nach DIN 963', $enriched->description);
        $this->assertStringContainsString('Bestehende Notiz', (string) $enriched->notes);
        $this->assertStringContainsString('Tags: senkschraube, schlitz, din963, m2x5', (string) $enriched->notes);
        $this->assertSame('Wuerth', $enriched->manufacturer);
        $this->assertSame('0687914631', $enriched->mpn);
        $this->assertNotNull($enriched->parameters);
        $this->assertCount(10, $enriched->parameters);
        $this->assertSame('Existing', $enriched->parameters[0]->name);
        $this->assertSame('Strength class', $enriched->parameters[7]->name);
        $this->assertSame('DIN 963', $enriched->parameters[8]->value_text);
    }

    public function testEnrichPartDetailReturnsOriginalWhenLlmResponseIsInvalid(): void
    {
        $httpClient = new MockHttpClient([
            new MockResponse((string) json_encode([
                'response' => 'not-json',
            ])),
        ]);

        $service = new WuerthAIEnrichmentService(
            client: $httpClient,
            em: $this->createEntityManager([]),
            enabled: true,
            endpoint: 'http://192.168.178.33:11434/api/generate',
            model: 'qwen3:8b',
            debug: false,
        );

        $detail = new PartDetailDTO(
            provider_key: 'wuerth',
            provider_id: '4056807624631',
            name: 'Original name',
            description: 'Original description',
        );

        $enriched = $service->enrichPartDetail($detail);

        $this->assertSame('Original name', $enriched->name);
        $this->assertSame('Original description', $enriched->description);
        $this->assertNull($enriched->category);
    }

    /**
     * @param Category[] $categories
     */
    private function createEntityManager(array $categories): EntityManagerInterface
    {
        $repo = new class ($categories) {
            /**
             * @param Category[] $categories
             */
            public function __construct(private readonly array $categories)
            {
            }

            /**
             * @return Category[]
             */
            public function getFlatList(): array
            {
                return $this->categories;
            }
        };

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repo);

        return $em;
    }
}
