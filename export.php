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

/**
 * Export this site's AI provider/placement configuration as JSON.
 *
 * @package    tool_aiutils
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require('../../../config.php');

require_admin();

$url = new moodle_url('/admin/tool/aiutils/export.php');
$PAGE->set_url($url);
$PAGE->set_context(context_system::instance());
$PAGE->set_heading($SITE->fullname);
$PAGE->set_title(get_string('exportaiconfig', 'tool_aiutils'));

if (optional_param('download', 0, PARAM_BOOL)) {
    require_sesskey();
    $data = \tool_aiutils\config_exporter::export();
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $filename = 'tool_aiutils_config_' . date('Y-m-d_H-i-s') . '.json';

    header('Content-Type: application/json');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($json));
    header('Cache-Control: no-cache, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $json;
    exit;
}

echo $OUTPUT->header();
echo '<a href="index.php" class="btn btn-secondary mb-3">' . get_string('backtochoice', 'tool_aiutils') . '</a><br/><br/>';
echo '<h2>' . get_string('exportaiconfig', 'tool_aiutils') . '</h2>';
echo '<div class="alert alert-warning">' . get_string('export_warning', 'tool_aiutils') . '</div>';
echo '<a class="btn btn-primary" href="' .
    (new moodle_url('/admin/tool/aiutils/export.php', ['download' => 1, 'sesskey' => sesskey()]))->out(false) .
    '">' . get_string('downloadaiconfig', 'tool_aiutils') . '</a>';
echo $OUTPUT->footer();
