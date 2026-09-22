<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace tool_aiutils;

/**
 * Builds a portable export of a site's AI configuration.
 *
 * @package    tool_aiutils
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class config_exporter {

    /** @var string Placeholder used in place of a redacted secret value. */
    const REDACTED = '__REDACTED__';

    /** @var string Regex matched (case-insensitively) against config keys to decide what to redact. */
    const SECRET_KEY_PATTERN = '/key|secret|token|password|credential/i';

    /**
     * Build the export array.
     *
     * @return array
     */
    public static function export(): array {
        global $DB, $CFG;

        $providerrecords = $DB->get_records('ai_providers', null, 'id ASC');
        $providers = [];
        foreach ($providerrecords as $record) {
            $config = json_decode($record->config ?? '', true) ?? [];
            $actionconfig = json_decode($record->actionconfig ?? '', true) ?? [];
            $providers[] = [
                'name' => $record->name,
                'provider' => $record->provider,
                'enabled' => (bool) $record->enabled,
                'config' => self::redact($config),
                'actionconfig' => self::redact($actionconfig),
            ];
        }

        $placements = [];
        $pluginmanager = \core\plugin_manager::instance();
        foreach ($pluginmanager->get_plugins_of_type('aiplacement') as $component => $plugin) {
            $placements[$component] = $plugin->is_enabled() ? true : false;
        }

        return [
            'exportformat' => 1,
            'exportedat' => time(),
            'sourcewwwroot' => $CFG->wwwroot,
            'moodlerelease' => $CFG->release,
            'providers' => $providers,
            'placements' => $placements,
        ];
    }

    /**
     * Recursively redact values for keys that look like secrets.
     *
     * @param array $config
     * @return array
     */
    private static function redact(array $config): array {
        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $config[$key] = self::redact($value);
            } else if (is_string($key) && preg_match(self::SECRET_KEY_PATTERN, $key)) {
                $config[$key] = self::REDACTED;
            }
        }
        return $config;
    }
}
