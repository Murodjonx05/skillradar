<?php
// This file is part of Moodle - http://moodle.org/

require_once(__DIR__ . '/../../config.php');

global $DB, $OUTPUT, $PAGE;

$cmid = optional_param('cmid', 0, PARAM_INT);
$quizid = optional_param('quizid', 0, PARAM_INT);
$bankcontextid = optional_param('bankcontextid', 0, PARAM_INT);
$cat = optional_param('cat', '', PARAM_SEQUENCE);
$category = optional_param('category', '', PARAM_SEQUENCE);

if ($cmid > 0) {
    [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'quiz');
    $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);
} else if ($quizid > 0) {
    $quiz = $DB->get_record('quiz', ['id' => $quizid], '*', MUST_EXIST);
    $course = get_course((int)$quiz->course);
    $cm = get_coursemodule_from_instance('quiz', (int)$quiz->id, (int)$course->id, false, MUST_EXIST);
    $cmid = (int)$cm->id;
} else {
    throw new moodle_exception('missingparam', 'error', '', 'cmid');
}

$modulecontext = context_module::instance((int)$cm->id);
$coursecontext = context_course::instance((int)$course->id);
require_login($course, false, $cm);
require_capability('mod/quiz:manage', $modulecontext);
require_capability('local/skillradar:manage', $coursecontext);

$PAGE->set_url(new moodle_url('/local/skillradar/random_by_skill.php', ['cmid' => $cmid]));
$PAGE->set_cm($cm, $course);
$PAGE->set_course($course);
$PAGE->set_context($modulecontext);
$PAGE->set_pagelayout('incourse');
$PAGE->set_heading($course->fullname);
$PAGE->set_title(get_string('randomskill_heading', 'local_skillradar'));

$selectedcategory = $category !== '' ? $category : $cat;
if ($bankcontextid < 1 && $selectedcategory !== '') {
    $parts = explode(',', $selectedcategory);
    if (count($parts) === 2) {
        $bankcontextid = (int)$parts[1];
    }
}

$banks = \local_skillradar\random_question_manager::get_available_banks((int)$course->id);
if ($bankcontextid < 1) {
    $recent = \core_question\local\bank\question_bank_helper::get_recently_used_open_banks(
        $USER->id,
        0,
        null,
        ['moodle/question:useall']
    );
    foreach ($recent as $bank) {
        if ((int)$bank->cminfo->course === (int)$course->id && isset($banks[(int)$bank->contextid])) {
            $bankcontextid = (int)$bank->contextid;
            break;
        }
    }
}
if ($bankcontextid < 1 && isset($banks[(int)$modulecontext->id])) {
    $bankcontextid = (int)$modulecontext->id;
}
if ($bankcontextid < 1 && $banks !== []) {
    $firstbank = reset($banks);
    $bankcontextid = (int)$firstbank->contextid;
}
if ($bankcontextid > 0 && !isset($banks[$bankcontextid])) {
    if ($banks !== []) {
        $firstbank = reset($banks);
        $bankcontextid = (int)$firstbank->contextid;
    } else {
        $bankcontextid = 0;
    }
}

$definitions = \local_skillradar\manager::get_definitions((int)$course->id);

$errors = [];
$values = [];
foreach ($definitions as $definition) {
    $values[(string)$definition->skill_key] = '0';
}

if (data_submitted() && confirm_sesskey()) {
    $postedbankcontextid = optional_param('bankcontextid', $bankcontextid, PARAM_INT);
    if ($postedbankcontextid > 0) {
        $bankcontextid = $postedbankcontextid;
    }
    $skillcounts = [];
    foreach ($definitions as $definition) {
        $skillkey = (string)$definition->skill_key;
        $raw = trim((string)optional_param('count_' . $skillkey, '0', PARAM_RAW_TRIMMED));
        $values[$skillkey] = $raw;
        if ($raw === '') {
            $raw = '0';
        }
        if (!preg_match('/^\d+$/', $raw)) {
            $errors[$skillkey] = get_string('randomskill_error_nonnegative', 'local_skillradar');
            continue;
        }
        $skillcounts[$skillkey] = (int)$raw;
    }

    if (array_sum($skillcounts) < 1) {
        $errors['_form'] = get_string('randomskill_error_zero', 'local_skillradar');
    }

    $errors = $errors + \local_skillradar\random_question_manager::validate_skill_quotas_for_quiz(
        (int)$course->id,
        (int)$quiz->id,
        $skillcounts,
        $bankcontextid
    );

    if ($errors === []) {
        $result = \local_skillradar\random_question_manager::add_random_questions_to_quiz(
            (int)$cm->id,
            $skillcounts,
            0,
            $bankcontextid
        );
        $messagea = (object)[
            'slots' => (int)$result['totalslots'],
            'skills' => (int)$result['usedskills'],
        ];
        redirect(
            new moodle_url('/mod/quiz/edit.php', ['cmid' => $cmid]),
            get_string('randomskill_success', 'local_skillradar', $messagea),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
}

$pools = \local_skillradar\random_question_manager::get_available_skill_pools((int)$course->id, $bankcontextid);
$poolbykey = [];
foreach ($pools as $pool) {
    $poolbykey[(string)$pool['definition']->skill_key] = $pool;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('randomskill_heading', 'local_skillradar'));
echo html_writer::div(get_string('randomskill_help', 'local_skillradar'), 'alert alert-info mb-3');
echo html_writer::link(
    new moodle_url('/mod/quiz/edit.php', ['cmid' => $cmid]),
    get_string('back'),
    ['class' => 'btn btn-link mb-3 px-0']
);

if ($definitions === []) {
    echo $OUTPUT->notification(get_string('mapquestions_needdefs', 'local_skillradar'), \core\output\notification::NOTIFY_WARNING);
    echo $OUTPUT->footer();
    exit;
}

if ($banks === []) {
    echo $OUTPUT->notification(get_string('randomskill_error_nobank', 'local_skillradar'), \core\output\notification::NOTIFY_WARNING);
    echo $OUTPUT->footer();
    exit;
}

if (isset($errors['_form'])) {
    echo $OUTPUT->notification($errors['_form'], \core\output\notification::NOTIFY_ERROR);
}

$table = new html_table();
$table->head = [
    get_string('displayname', 'local_skillradar'),
    get_string('skillkey', 'local_skillradar'),
    get_string('randomskill_available', 'local_skillradar'),
    get_string('randomskill_requested', 'local_skillradar'),
];
$table->attributes['class'] = 'generaltable';

foreach ($definitions as $definition) {
    $skillkey = (string)$definition->skill_key;
    $pool = $poolbykey[$skillkey] ?? ['questioncount' => 0];
    $input = html_writer::empty_tag('input', [
        'type' => 'number',
        'name' => 'count_' . $skillkey,
        'min' => '0',
        'step' => '1',
        'value' => $values[$skillkey] ?? '0',
        'class' => 'form-control',
        'style' => 'max-width: 8rem;',
        'data-skillkey' => $skillkey,
        'data-skillname' => format_string($definition->displayname),
        'data-available' => (string)((int)($pool['questioncount'] ?? 0)),
    ]);
    if (isset($errors[$skillkey])) {
        $input .= html_writer::div($errors[$skillkey], 'text-danger small mt-1');
    }
    $table->data[] = [
        format_string($definition->displayname),
        s($skillkey),
        (int)($pool['questioncount'] ?? 0),
        $input,
    ];
}

echo '<form method="post" action="' . s($PAGE->url->out(false)) . '" id="random-skill-form">';
echo '<input type="hidden" name="sesskey" value="' . sesskey() . '" />';
echo html_writer::start_div('mb-3');
echo html_writer::tag('label', get_string('randomskill_bank', 'local_skillradar'), ['for' => 'id_bankcontextid', 'class' => 'form-label']);
$bankoptions = [];
foreach ($banks as $contextid => $bank) {
    $bankoptions[$contextid] = $bank->coursenamebankname;
}
echo html_writer::select($bankoptions, 'bankcontextid', $bankcontextid, null, ['id' => 'id_bankcontextid', 'class' => 'form-select', 'style' => 'max-width: 36rem;']);
echo html_writer::end_div();
echo html_writer::table($table);
echo html_writer::tag('button', get_string('randomskill_submit', 'local_skillradar'), [
    'type' => 'submit',
    'id' => 'id_randomskill_submit',
    'class' => 'btn btn-primary',
]);
echo '</form>';

$messages = [
    'nonnegative' => get_string('randomskill_error_nonnegative', 'local_skillradar'),
    'zero' => get_string('randomskill_error_zero', 'local_skillradar'),
    'shortage' => get_string('randomskill_error_shortage', 'local_skillradar', (object)[
        'skill' => '__SKILL__',
        'requested' => '__REQUESTED__',
        'available' => '__AVAILABLE__',
    ]),
];
$PAGE->requires->js_init_code(
    '(function() {
        const form = document.getElementById("random-skill-form");
        if (!form) {
            return;
        }

        const bankSelect = document.getElementById("id_bankcontextid");
        const submitButton = document.getElementById("id_randomskill_submit");
        const inputs = Array.from(form.querySelectorAll(\'input[type="number"][data-skillkey]\'));
        const messages = ' . json_encode($messages) . ';

        function ensureErrorNode(input) {
            let node = input.parentNode.querySelector(".random-skill-inline-error");
            if (!node) {
                node = document.createElement("div");
                node.className = "text-danger small mt-1 random-skill-inline-error";
                input.parentNode.appendChild(node);
            }
            return node;
        }

        function setError(input, message) {
            const node = ensureErrorNode(input);
            node.textContent = message || "";
            input.classList.toggle("is-invalid", !!message);
        }

        function getShortageMessage(skillname, requested, available) {
            return messages.shortage
                .replace("__SKILL__", skillname)
                .replace("__REQUESTED__", String(requested))
                .replace("__AVAILABLE__", String(available));
        }

        function validateInput(input) {
            const raw = String(input.value || "").trim();
            if (raw === "") {
                setError(input, "");
                return {valid: true, count: 0};
            }
            if (!/^\\d+$/.test(raw)) {
                setError(input, messages.nonnegative);
                return {valid: false, count: 0};
            }

            const count = Number(raw);
            const available = Number(input.dataset.available || "0");
            if (count > available) {
                setError(input, getShortageMessage(input.dataset.skillname || input.dataset.skillkey || "", count, available));
                return {valid: false, count: count};
            }

            setError(input, "");
            return {valid: true, count: count};
        }

        function validateForm() {
            let allValid = true;
            let total = 0;
            inputs.forEach((input) => {
                const result = validateInput(input);
                total += result.count;
                if (!result.valid) {
                    allValid = false;
                }
            });
            submitButton.disabled = !allValid || total < 1;
        }

        inputs.forEach((input) => {
            input.addEventListener("input", validateForm);
            input.addEventListener("change", validateForm);
        });

        if (bankSelect) {
            bankSelect.addEventListener("change", function() {
                const url = new URL(window.location.href);
                url.searchParams.set("cmid", ' . (int)$cmid . ');
                url.searchParams.set("bankcontextid", bankSelect.value);
                inputs.forEach((input) => {
                    const value = String(input.value || "").trim();
                    if (value !== "" && value !== "0") {
                        url.searchParams.set(input.name, value);
                    } else {
                        url.searchParams.delete(input.name);
                    }
                });
                window.location.assign(url.toString());
            });
        }

        form.addEventListener("submit", function(event) {
            validateForm();
            if (submitButton.disabled) {
                event.preventDefault();
            }
        });

        validateForm();
    })();'
);
echo $OUTPUT->footer();
