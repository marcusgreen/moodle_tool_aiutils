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
 * Import an AI provider/placement configuration exported by export.php.
 *
 * @package    tool_aiutils
 * @copyright  2026 Marcus Green
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require('../../../config.php');

require_admin();

$url = new moodle_url('/admin/tool/aiutils/import.php');
$PAGE->set_url($url);
$PAGE->set_context(context_system::instance());
$PAGE->set_heading($SITE->fullname);
$PAGE->set_title(get_string('importaiconfig', 'tool_aiutils'));

$step = optional_param('step', 'upload', PARAM_ALPHA);
$error = '';
$rows = null;
$rawjson = '';

if ($step === 'apply' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    $rawjson = required_param('rawjson', PARAM_RAW);
    $data = json_decode($rawjson, true);
    if (!is_array($data) || !isset($data['exportformat'])) {
        $error = get_string('import_invalidfile', 'tool_aiutils');
        $step = 'upload';
    } else {
        $rows = \tool_aiutils\config_importer::apply($data);
        $step = 'done';
    }
} else if ($step === 'preview' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    if (empty($_FILES['configfile']['tmp_name']) || !is_uploaded_file($_FILES['configfile']['tmp_name'])) {
        $error = get_string('import_nofile', 'tool_aiutils');
        $step = 'upload';
    } else {
        $rawjson = file_get_contents($_FILES['configfile']['tmp_name']);
        $data = json_decode($rawjson, true);
        if (!is_array($data) || !isset($data['exportformat'])) {
            $error = get_string('import_invalidfile', 'tool_aiutils');
            $step = 'upload';
        } else {
            $rows = \tool_aiutils\config_importer::preview($data);
        }
    }
} else {
    $step = 'upload';
}

echo $OUTPUT->header();
echo '<a href="index.php" class="btn btn-secondary mb-3">' . get_string('backtochoice', 'tool_aiutils') . '</a><br/><br/>';
echo '<h2>' . get_string('importaiconfig', 'tool_aiutils') . '</h2>';

if ($error) {
    echo '<div class="alert alert-danger">' . s($error) . '</div>';
}

if ($step === 'upload') {
    echo '<div class="alert alert-warning">' . get_string('import_warning', 'tool_aiutils') . '</div>';
    echo '<form method="post" action="import.php" enctype="multipart/form-data">';
    echo '<input type="hidden" name="step" value="preview">';
    echo '<input type="hidden" name="sesskey" value="' . s(sesskey()) . '">';
    echo '<div class="mb-3"><input type="file" name="configfile" accept="application/json" required></div>';
    echo '<button type="submit" class="btn btn-primary">' . get_string('previewimport', 'tool_aiutils') . '</button>';
    echo '</form>';
} else if ($step === 'preview' && $rows !== null) {
    echo '<table class="table table-striped"><thead><tr>' .
        '<th>' . get_string('import_type', 'tool_aiutils') . '</th>' .
        '<th>' . get_string('import_name', 'tool_aiutils') . '</th>' .
        '<th>' . get_string('import_action', 'tool_aiutils') . '</th>' .
        '<th>' . get_string('import_detail', 'tool_aiutils') . '</th>' .
        '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $rowclass = $row['needsattention'] ? 'table-warning' : '';
        echo '<tr class="' . $rowclass . '">';
        echo '<td>' . s($row['type']) . '</td>';
        echo '<td>' . s($row['name']) . '</td>';
        echo '<td>' . s($row['action']) . '</td>';
        echo '<td>' . s($row['detail']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    echo '<form method="post" action="import.php">';
    echo '<input type="hidden" name="step" value="apply">';
    echo '<input type="hidden" name="sesskey" value="' . s(sesskey()) . '">';
    echo '<input type="hidden" name="rawjson" value="' . s($rawjson) . '">';
    echo '<button type="submit" class="btn btn-danger">' . get_string('confirmimport', 'tool_aiutils') . '</button> ';
    echo '<a href="import.php" class="btn btn-secondary">' . get_string('cancel') . '</a>';
    echo '</form>';
} else if ($step === 'done' && $rows !== null) {
    echo '<div class="alert alert-success">' . get_string('import_applied', 'tool_aiutils') . '</div>';
    echo '<table class="table table-striped"><thead><tr>' .
        '<th>' . get_string('import_type', 'tool_aiutils') . '</th>' .
        '<th>' . get_string('import_name', 'tool_aiutils') . '</th>' .
        '<th>' . get_string('import_action', 'tool_aiutils') . '</th>' .
        '<th>' . get_string('import_detail', 'tool_aiutils') . '</th>' .
        '</tr></thead><tbody>';
    foreach ($rows as $row) {
        $rowclass = $row['needsattention'] ? 'table-warning' : '';
        echo '<tr class="' . $rowclass . '">';
        echo '<td>' . s($row['type']) . '</td>';
        echo '<td>' . s($row['name']) . '</td>';
        echo '<td>' . s($row['action']) . '</td>';
        echo '<td>' . s($row['detail']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '<a href="' . (new moodle_url('/admin/settings.php', ['section' => 'aiprovider']))->out(false) .
        '" class="btn btn-primary">' . get_string('aiproviders', 'tool_aiutils') . '</a>';
}

echo $OUTPUT->footer();
