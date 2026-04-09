<?php

declare(strict_types=1);

namespace App\Settings\InfoProviderSystem;

use App\Settings\SettingsIcon;
use Jbtronics\SettingsBundle\Metadata\EnvVarMode;
use Jbtronics\SettingsBundle\Settings\Settings;
use Jbtronics\SettingsBundle\Settings\SettingsParameter;
use Jbtronics\SettingsBundle\Settings\SettingsTrait;

#[Settings(name: 'google_last_resort_provider', label: 'Google Last Resort Provider', description: 'Uses the first Google result for a GTIN/EAN and extracts basic product metadata from the found product page.')]
#[SettingsIcon('fa-magnifying-glass')]
class GoogleLastResortSettings
{
    use SettingsTrait;

    #[SettingsParameter(label: 'Enabled', description: 'Enable the Google last-resort provider for GTIN/EAN scans when no normal provider matches.',
        envVar: 'bool:PROVIDER_GOOGLE_LAST_RESORT_ENABLED', envVarMode: EnvVarMode::OVERWRITE
    )]
    public bool $enabled = false;

    #[SettingsParameter(label: 'Google language', description: 'Language code used for the Google search request.')]
    public string $language = 'en';

    #[SettingsParameter(label: 'Google country', description: 'Country code used for the Google search request.')]
    public string $country = 'de';
}
