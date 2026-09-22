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
 * Applies a config_exporter export to the current site.
 *
 * @package    tool_aiutils
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class config_importer {

    /**
     * Build a preview of what apply() would do, without writing anything.
     *
     * @param array $data Decoded export data.
     * @return array List of rows: ['type' => 'provider'|'placement', 'name' => ..., 'action' => 'create'|'update'|'unchanged',
     *                'needsattention' => bool, 'detail' => string]
     */
    public static function preview(array $data): array {
        global $DB;

        $rows = [];
        $existing = $DB->get_records('ai_providers');

        foreach ($data['providers'] ?? [] as $providerdata) {
            $match = self::find_matching_provider($existing, $providerdata);
            $redactedfields = self::redacted_fields($providerdata['config'] ?? []);
            $detail = $redactedfields
                ? get_string('import_redactedfields', 'tool_aiutils', implode(', ', $redactedfields))
                : '';

            $rows[] = [
                'type' => 'provider',
                'name' => $providerdata['name'] . ' (' . $providerdata['provider'] . ')',
                'action' => $match ? 'update' : 'create',
                'needsattention' => !empty($redactedfields),
                'detail' => $detail,
            ];
        }

        $pluginmanager = \core\plugin_manager::instance();
        $installed = $pluginmanager->get_plugins_of_type('aiplacement');
        foreach ($data['placements'] ?? [] as $component => $enabled) {
            if (!isset($installed[str_replace('aiplacement_', '', $component)])) {
                $rows[] = [
                    'type' => 'placement',
                    'name' => $component,
                    'action' => 'skip',
                    'needsattention' => true,
                    'detail' => get_string('import_placementnotinstalled', 'tool_aiutils'),
                ];
                continue;
            }
            $currentlyenabled = $installed[str_replace('aiplacement_', '', $component)]->is_enabled();
            $rows[] = [
                'type' => 'placement',
                'name' => $component,
                'action' => ((bool) $currentlyenabled === (bool) $enabled) ? 'unchanged' : 'update',
                'needsattention' => false,
                'detail' => '',
            ];
        }

        return $rows;
    }

    /**
     * Apply an export to the current site.
     *
     * @param array $data Decoded export data.
     * @return array Summary of what was done, same shape as preview() rows.
     */
    public static function apply(array $data): array {
        global $DB;

        require_once(__DIR__ . '/../../../../ai/classes/manager.php');
        $manager = new \core_ai\manager($DB);

        $results = [];
        $existing = $DB->get_records('ai_providers');

        foreach ($data['providers'] ?? [] as $providerdata) {
            $match = self::find_matching_provider($existing, $providerdata);
            $config = self::merge_with_existing(
                $providerdata['config'] ?? [],
                $match ? (json_decode($match->config ?? '', true) ?? []) : []
            );
            $actionconfig = self::merge_with_existing(
                $providerdata['actionconfig'] ?? [],
                $match ? (json_decode($match->actionconfig ?? '', true) ?? []) : []
            );

            $stillredacted = self::redacted_fields($config);
            $enabled = $providerdata['enabled'] && empty($stillredacted);

            if (!class_exists($providerdata['provider'])) {
                $results[] = [
                    'type' => 'provider',
                    'name' => $providerdata['name'],
                    'action' => 'skip',
                    'needsattention' => true,
                    'detail' => get_string('import_providerclassmissing', 'tool_aiutils', $providerdata['provider']),
                ];
                continue;
            }

            if ($match) {
                $provider = new $match->provider(
                    enabled: $match->enabled,
                    id: $match->id,
                    name: $match->name,
                    config: $match->config,
                    actionconfig: $match->actionconfig,
                );
                $manager->update_provider_instance($provider, $config, $actionconfig);
                if ((bool) $match->enabled !== $enabled) {
                    $enabled ? $manager->enable_provider_instance($provider) : $manager->disable_provider_instance($provider);
                }
                $action = 'update';
            } else {
                $manager->create_provider_instance(
                    classname: $providerdata['provider'],
                    name: $providerdata['name'],
                    enabled: $enabled,
                    config: $config,
                    actionconfig: $actionconfig,
                );
                $action = 'create';
            }

            $results[] = [
                'type' => 'provider',
                'name' => $providerdata['name'] . ' (' . $providerdata['provider'] . ')',
                'action' => $action,
                'needsattention' => !empty($stillredacted),
                'detail' => $stillredacted
                    ? get_string('import_redactedfields', 'tool_aiutils', implode(', ', $stillredacted))
                    : '',
            ];
        }

        $pluginmanager = \core\plugin_manager::instance();
        $installed = $pluginmanager->get_plugins_of_type('aiplacement');
        foreach ($data['placements'] ?? [] as $component => $enabled) {
            $shortname = str_replace('aiplacement_', '', $component);
            if (!isset($installed[$shortname])) {
                $results[] = [
                    'type' => 'placement',
                    'name' => $component,
                    'action' => 'skip',
                    'needsattention' => true,
                    'detail' => get_string('import_placementnotinstalled', 'tool_aiutils'),
                ];
                continue;
            }
            \core\plugininfo\aiplacement::enable_plugin($shortname, (int) (bool) $enabled);
            $results[] = [
                'type' => 'placement',
                'name' => $component,
                'action' => 'update',
                'needsattention' => false,
                'detail' => '',
            ];
        }

        return $results;
    }

    /**
     * Find an existing provider record matching an export entry by provider class + name.
     *
     * @param array $existing Records from mdl_ai_providers, keyed by id.
     * @param array $providerdata Single provider entry from the export.
     * @return \stdClass|null
     */
    private static function find_matching_provider(array $existing, array $providerdata): ?\stdClass {
        foreach ($existing as $record) {
            if ($record->provider === $providerdata['provider'] && $record->name === $providerdata['name']) {
                return $record;
            }
        }
        return null;
    }

    /**
     * Overlay imported config onto existing config: anything still marked redacted falls back
     * to the value already present on the target site (or blank if there is none).
     *
     * @param array $imported
     * @param array $current
     * @return array
     */
    private static function merge_with_existing(array $imported, array $current): array {
        foreach ($imported as $key => $value) {
            if (is_array($value)) {
                $imported[$key] = self::merge_with_existing($value, $current[$key] ?? []);
            } else if ($value === config_exporter::REDACTED) {
                $imported[$key] = $current[$key] ?? '';
            }
        }
        return $imported;
    }

    /**
     * List the (possibly nested, dot-joined) keys still holding the redacted placeholder.
     *
     * @param array $config
     * @param string $prefix
     * @return string[]
     */
    private static function redacted_fields(array $config, string $prefix = ''): array {
        $fields = [];
        foreach ($config as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $fields = array_merge($fields, self::redacted_fields($value, $path));
            } else if ($value === config_exporter::REDACTED) {
                $fields[] = $path;
            } else if ($value === '' && is_string($key) && preg_match(config_exporter::SECRET_KEY_PATTERN, $key)) {
                $fields[] = $path;
            }
        }
        return $fields;
    }
}
