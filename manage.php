<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/gradelib.php');

require_login();

global $CFG, $DB, $OUTPUT;

$courseid = required_param('courseid', PARAM_INT);
$course = get_course($courseid);
$context = context_course::instance($courseid);
require_capability('local/skillradar:manage', $context);

$PAGE->set_url(new moodle_url('/local/skillradar/manage.php', ['courseid' => $courseid]));
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');
$PAGE->set_heading($course->fullname);
$PAGE->set_title(get_string('manageheading', 'local_skillradar'));

$assetrev = (string)max(
    @filemtime(__DIR__ . '/js/chart.umd.min.js') ?: 0,
    @filemtime(__DIR__ . '/js/radar_arc_plugin.js') ?: 0,
    @filemtime(__DIR__ . '/js/manage.js') ?: 0,
    @filemtime(__DIR__ . '/styles/radar.css') ?: 0
);
$PAGE->requires->css(new moodle_url('/local/skillradar/styles/radar.css', ['v' => $assetrev]));
$PAGE->requires->js(new moodle_url('/local/skillradar/js/chart.umd.min.js', ['v' => $assetrev]), true);
$PAGE->requires->js(new moodle_url('/local/skillradar/js/radar_arc_plugin.js', ['v' => $assetrev]), true);
$PAGE->requires->js(new moodle_url('/local/skillradar/js/manage.js', ['v' => $assetrev]), true);

if (data_submitted() && confirm_sesskey()) {
    $action = optional_param('action', '', PARAM_ALPHA);
    if ($action === 'addskill') {
        $skillkey = clean_param(optional_param('skill_key', '', PARAM_ALPHANUMEXT), PARAM_ALPHANUMEXT);
        $displayname = optional_param('displayname', '', PARAM_TEXT);
        $cfgadd = \local_skillradar\manager::get_course_config($courseid);
        $color = $cfgadd->primarycolor ?? '#3B82F6';
        if ($skillkey !== '' && $displayname !== '' &&
                !$DB->record_exists(\local_skillradar\manager::TABLE_DEF, ['courseid' => $courseid, 'skill_key' => $skillkey])) {
            $sortorder = (int)$DB->get_field_sql(
                "SELECT COALESCE(MAX(sortorder), 0) FROM {" . \local_skillradar\manager::TABLE_DEF . "} WHERE courseid = ?",
                [$courseid]
            );
            $DB->insert_record(\local_skillradar\manager::TABLE_DEF, (object)[
                'courseid' => $courseid,
                'skill_key' => $skillkey,
                'displayname' => $displayname,
                'color' => $color,
                'sortorder' => $sortorder + 1,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
            \local_skillradar\manager::invalidate_course_cache($courseid);
        }
    } else if ($action === 'deleteskill') {
        $defid = optional_param('defid', 0, PARAM_INT);
        $def = $DB->get_record(\local_skillradar\manager::TABLE_DEF, ['id' => $defid, 'courseid' => $courseid]);
        if ($def) {
            $DB->delete_records(\local_skillradar\manager::TABLE_DEF, ['id' => $defid]);
            $DB->delete_records(\local_skillradar\manager::TABLE_MAP, ['courseid' => $courseid, 'skill_key' => $def->skill_key]);
            \local_skillradar\manager::invalidate_course_cache($courseid);
        }
    } else if ($action === 'saveconfig') {
        $config = \local_skillradar\manager::get_course_config($courseid);
        $config->overallmode = optional_param('overallmode', 'average', PARAM_ALPHA);
        if (!in_array($config->overallmode, ['average', 'final'], true)) {
            $config->overallmode = 'average';
        }
        $config->courseavg = optional_param('courseavg', 0, PARAM_BOOL);
        $pc = clean_param(optional_param('primarycolor', '#3B82F6', PARAM_TEXT), PARAM_TEXT);
        $config->primarycolor = preg_match('/^#[0-9A-Fa-f]{6}$/', $pc) ? $pc : '#3B82F6';
        \local_skillradar\manager::save_course_config($config);
        \local_skillradar\manager::invalidate_course_cache($courseid);
    } else if ($action === 'savemaps') {
        $rows = [];
        foreach ($DB->get_records('grade_items', ['courseid' => $courseid], 'sortorder ASC') as $item) {
            $skillkey = clean_param(optional_param('skill_' . $item->id, '', PARAM_ALPHANUMEXT), PARAM_ALPHANUMEXT);
            $weight = optional_param('weight_' . $item->id, 1, PARAM_FLOAT);
            if ($skillkey === '' || $skillkey === '_none') {
                continue;
            }
            $rows[] = (object)[
                'gradeitemid' => $item->id,
                'skill_key' => $skillkey,
                'weight' => $weight > 0 ? $weight : 1,
            ];
        }
        \local_skillradar\manager::replace_mappings($courseid, $rows);
        \local_skillradar\manager::invalidate_course_cache($courseid);
    }
}

\local_skillradar\manager::purge_stale_mappings($courseid);

$definitions = \local_skillradar\manager::get_definitions($courseid);
$mappings = \local_skillradar\manager::get_mappings($courseid);
$mappingcounts = \local_skillradar\manager::count_items_per_skill($courseid);
$gradeitems = $DB->get_records('grade_items', ['courseid' => $courseid], 'sortorder ASC');
$mapbyitem = [];
foreach ($mappings as $mapping) {
    $mapbyitem[$mapping->gradeitemid] = $mapping;
}

$cfg = \local_skillradar\manager::get_course_config($courseid);

$debugskillradar = !empty($CFG->debugdeveloper);

$previewconfig = [
    'courseId' => $courseid,
    'userId' => \local_skillradar\manager::find_preview_userid($courseid),
    'primaryColor' => $cfg->primarycolor ?? '#3B82F6',
    'apiUrl' => (new moodle_url('/local/skillradar/api.php'))->out(false),
    'sesskey' => sesskey(),
    'includeCourseAverage' => !empty($cfg->courseavg),
    'debugSkillRadar' => $debugskillradar,
    'strings' => [
        'loading' => get_string('loading', 'local_skillradar'),
        'notConfigured' => get_string('notconfigured', 'local_skillradar'),
        'previewFailed' => get_string('managepreviewfailed', 'local_skillradar'),
        'chartMissing' => get_string('managechartmissing', 'local_skillradar'),
        'resultBreakdown' => get_string('resultbreakdown', 'local_skillradar'),
        'noResults' => get_string('noresults', 'local_skillradar'),
        'mappedItems' => get_string('mappeditems', 'local_skillradar'),
        'courseAverageLegend' => get_string('courseaveragelegend', 'local_skillradar'),
    ],
];

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('manageheading', 'local_skillradar'));

echo html_writer::tag('h3', get_string('sectionskills', 'local_skillradar'));
echo '<form method="post" action="' . s($PAGE->url->out(false)) . '" class="mb-3">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '" />';
echo '<input type="hidden" name="action" value="addskill" />';
echo '<div class="row align-items-end g-3">';
echo '<div class="col-md-5"><label class="form-label">' . get_string('skillkey', 'local_skillradar') . '</label><input class="form-control" name="skill_key" required maxlength="100" /></div>';
echo '<div class="col-md-5"><label class="form-label">' . get_string('displayname', 'local_skillradar') . '</label><input class="form-control" name="displayname" required maxlength="255" /></div>';
echo '<div class="col-md-2"><button class="btn btn-primary" type="submit">' . get_string('addskill', 'local_skillradar') . '</button></div>';
echo '</div></form>';

if ($definitions) {
    $table = new html_table();
    $table->head = [
        get_string('skillkey', 'local_skillradar'),
        get_string('displayname', 'local_skillradar'),
        get_string('warning', 'local_skillradar'),
        '',
    ];
    foreach ($definitions as $definition) {
        $mappedcount = (int)($mappingcounts[$definition->skill_key] ?? 0);
        $warning = $mappedcount < 3 ? get_string('skillwarningfewitems', 'local_skillradar', $mappedcount) : '';
        $deleteform = '<form method="post" action="' . s($PAGE->url->out(false)) . '" class="d-inline">' .
            '<input type="hidden" name="sesskey" value="' . sesskey() . '" />' .
            '<input type="hidden" name="action" value="deleteskill" />' .
            '<input type="hidden" name="defid" value="' . (int)$definition->id . '" />' .
            '<button class="btn btn-link p-0" type="submit">' . get_string('delete') . '</button></form>';
        $table->data[] = [
            html_writer::div(
                s($definition->skill_key),
                '',
                ['data-skill-key' => s($definition->skill_key), 'data-skill-color' => s($definition->color)]
            ),
            html_writer::div(s($definition->displayname), '', ['data-skill-name' => s($definition->displayname)]),
            $warning,
            $deleteform,
        ];
    }
    echo html_writer::table($table);
}

echo html_writer::tag('h3', get_string('sectionsettings', 'local_skillradar'));
echo '<form method="post" action="' . s($PAGE->url->out(false)) . '" class="mb-4">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '" />';
echo '<input type="hidden" name="action" value="saveconfig" />';
$options = [
    'average' => get_string('overallaverage', 'local_skillradar'),
    'final' => get_string('overallfinal', 'local_skillradar'),
];
$selectoptions = '';
foreach ($options as $value => $label) {
    $selectoptions .= html_writer::tag('option', $label, ['value' => $value, 'selected' => (($cfg->overallmode ?? 'average') === $value) ? 'selected' : null]);
}
echo '<div class="mb-3"><label class="form-label" for="id_overallmode">' . get_string('overallmode', 'local_skillradar') . '</label>' .
    html_writer::tag('select', $selectoptions, ['name' => 'overallmode', 'id' => 'id_overallmode', 'class' => 'form-select']) .
    '</div>';
echo '<p class="text-muted small mb-3">' . get_string('minaxesauto', 'local_skillradar') . '</p>';
echo '<div class="mb-3"><label class="form-label" for="id_primarycolor">' . get_string('primarycolor', 'local_skillradar') . '</label>' .
    '<input class="form-control form-control-color" type="color" name="primarycolor" id="id_primarycolor" value="' .
    s($cfg->primarycolor ?? '#3B82F6') . '" />' .
    '<p class="text-muted small mt-1 mb-0">' . get_string('primarycolor_help', 'local_skillradar') . '</p></div>';
echo '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="courseavg" value="1" id="id_courseavg" ' . (!empty($cfg->courseavg) ? 'checked' : '') . ' />' .
    '<label class="form-check-label" for="id_courseavg">' . get_string('courseavg', 'local_skillradar') . '</label></div>';
echo '<button class="btn btn-secondary" type="submit">' . get_string('savechanges') . '</button>';
echo '</form>';

echo html_writer::tag('h3', get_string('sectionmapping', 'local_skillradar'));
echo '<form method="post" action="' . s($PAGE->url->out(false)) . '">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '" />';
echo '<input type="hidden" name="action" value="savemaps" />';
$mappingtable = new html_table();
$mappingtable->head = [get_string('gradeitem', 'grades'), get_string('skill', 'local_skillradar'), get_string('weight', 'local_skillradar')];
$unmapped = [];
$skilloptions = ['_none' => get_string('unmap', 'local_skillradar')];
foreach ($definitions as $definition) {
    $skilloptions[$definition->skill_key] = $definition->displayname;
}
foreach ($gradeitems as $gradeitem) {
    $item = new grade_item($gradeitem);
    $current = $mapbyitem[$gradeitem->id]->skill_key ?? '_none';
    $weight = $mapbyitem[$gradeitem->id]->weight ?? 1;
    $opts = '';
    foreach ($skilloptions as $value => $label) {
        $opts .= html_writer::tag('option', $label, ['value' => $value, 'selected' => ((string)$current === (string)$value) ? 'selected' : null]);
    }
    $mappingtable->data[] = [
        format_string($item->get_name(true, false)),
        html_writer::tag('select', $opts, ['class' => 'form-select', 'name' => 'skill_' . $gradeitem->id]),
        '<input class="form-control" type="number" step="0.00001" min="0.00001" name="weight_' . $gradeitem->id . '" value="' . s($weight) . '" />',
    ];
    if ($current === '_none') {
        $unmapped[] = $item->get_name(true, false);
    }
}
echo html_writer::table($mappingtable);
echo '<button class="btn btn-primary mt-2" type="submit">' . get_string('savemaps', 'local_skillradar') . '</button>';
echo '</form>';

if ($unmapped) {
    echo html_writer::tag('h4', get_string('unmapped', 'local_skillradar'));
    echo html_writer::alist($unmapped);
}

echo html_writer::tag('h3', get_string('preview', 'local_skillradar'));
$managedebugblocks = '';
if ($debugskillradar) {
    $managedebugblocks = html_writer::div('', 'local-skillradar-textdebug', ['id' => 'local-skillradar-manage-text']) .
        html_writer::tag('pre', '', ['class' => 'local-skillradar-jsondebug', 'id' => 'local-skillradar-manage-json']);
}
echo html_writer::div(
    html_writer::div(
        html_writer::tag('canvas', '', ['id' => 'local-skillradar-manage-canvas']) .
        html_writer::div(
            html_writer::div(
                html_writer::div('0%', 'local-skillradar-score-value', ['id' => 'local-skillradar-manage-score-value']) .
                html_writer::div('AVERAGE', 'local-skillradar-score-label', ['id' => 'local-skillradar-manage-score-label']),
                'local-skillradar-center-score',
                ['id' => 'local-skillradar-manage-center-score', 'title' => 'Toggle score mode']
            ),
            'local-skillradar-center-anchor'
        ),
        'local-skillradar-chartwrap'
    ) .
    html_writer::div(get_string('loading', 'local_skillradar'), 'local-skillradar-manage-loading', ['id' => 'local-skillradar-manage-loading']) .
    html_writer::div('', 'local-skillradar-results', ['id' => 'local-skillradar-manage-results']) .
    $managedebugblocks,
    'local-skillradar-body',
    ['id' => 'local-skillradar-manage-preview', 'data-config' => json_encode($previewconfig)]
);
echo html_writer::script(
    "(function() {" .
    "var loading = document.getElementById('local-skillradar-manage-loading');" .
    "if (loading) { loading.textContent = 'Bootstrapping...'; }" .
    "function run() {" .
    "if (window.localSkillRadarManageBoot) {" .
    "window.localSkillRadarManageBoot();" .
    "} else if (loading) {" .
    "loading.textContent = 'manage.js not loaded';" .
    "}" .
    "}" .
    "if (document.readyState === 'complete') { run(); } else { window.addEventListener('load', run, {once: true}); }" .
    "})();"
);

echo $OUTPUT->footer();
