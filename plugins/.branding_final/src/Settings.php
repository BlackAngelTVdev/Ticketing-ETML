<?php

/**
 * ---------------------------------------------------------------------
 *
 * GLPI Branding plugin - settings.
 *
 * Small wrapper around GLPI's core configuration storage. Only the
 * interface tweaks are stored in the database; the logos themselves are
 * files managed in the plugin storage directory (see Logo).
 *
 * ---------------------------------------------------------------------
 */

namespace GlpiPlugin\Branding;

use Config as GlpiConfig;

final class Settings
{
    /**
     * Context used in glpi_configs.
     */
    private const CONTEXT = 'plugin:branding';

    /**
     * Whether GLPI's "Show as map" toggle should be displayed in search
     * results (default: no, see issue #3).
     */
    public static function showMapButton(): bool
    {
        $values = GlpiConfig::getConfigurationValues(self::CONTEXT, ['show_map_button']);

        return ($values['show_map_button'] ?? '0') === '1';
    }

    public static function setShowMapButton(bool $show): void
    {
        GlpiConfig::setConfigurationValues(self::CONTEXT, [
            'show_map_button' => $show ? '1' : '0',
        ]);
    }
}
