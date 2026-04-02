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

namespace App\Tests\Services\InfoProviderSystem\Providers;

use App\Services\InfoProviderSystem\Providers\ProviderCapabilities;
use App\Services\InfoProviderSystem\Providers\WuerthProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class WuerthProviderTest extends TestCase
{
    public function testSearchByKeywordMapsFirstProduct(): void
    {
        $provider = new WuerthProvider(new MockHttpClient([
            new MockResponse((string) json_encode([
                'nothingFound' => false,
                'products' => [
                    [
                        'value' => '0687914631',
                        'description' => 'CLIPBLND-F.SHIEBTR-SCHIMOS-H-3000-SILBR',
                        'label' => 'Blendenprofil SCHIMOS 80/120-H fuer Holztueren',
                        'image' => 'https://example.com/image.jpg',
                    ],
                ],
            ])),
        ]));

        $results = $provider->searchByKeyword('4056807624631');

        $this->assertCount(1, $results);
        $this->assertSame('wuerth', $results[0]->provider_key);
        $this->assertSame('4056807624631', $results[0]->provider_id);
        $this->assertSame('4056807624631', $results[0]->gtin);
        $this->assertSame('Blendenprofil SCHIMOS 80/120-H fuer Holztueren', $results[0]->name);
    }

    public function testGetDetailsCreatesSupplierInfoFromProductValue(): void
    {
        $provider = new WuerthProvider(new MockHttpClient([
            new MockResponse((string) json_encode([
                'nothingFound' => false,
                'products' => [
                    [
                        'value' => '0687914631',
                        'description' => 'Technical name',
                        'label' => 'Visible name',
                        'image' => 'https://example.com/image.jpg',
                    ],
                ],
            ])),
        ]));

        $details = $provider->getDetails('4056807624631');

        $this->assertSame('Visible name', $details->name);
        $this->assertSame('Technical name', $details->description);
        $this->assertSame('4056807624631', $details->gtin);
        $this->assertCount(1, $details->vendor_infos ?? []);
        $this->assertSame('Wuerth', $details->vendor_infos[0]->distributor_name);
        $this->assertSame('0687914631', $details->vendor_infos[0]->order_number);
    }

    public function testSearchByKeywordReturnsEmptyOnMissingProduct(): void
    {
        $provider = new WuerthProvider(new MockHttpClient([
            new MockResponse((string) json_encode([
                'nothingFound' => true,
                'products' => [],
            ])),
        ]));

        $this->assertSame([], $provider->searchByKeyword('0000000000000'));
    }

    public function testCapabilitiesIncludeGTIN(): void
    {
        $provider = new WuerthProvider(new MockHttpClient());

        $this->assertContains(ProviderCapabilities::BASIC, $provider->getCapabilities());
        $this->assertContains(ProviderCapabilities::PICTURE, $provider->getCapabilities());
        $this->assertContains(ProviderCapabilities::GTIN, $provider->getCapabilities());
    }
}
