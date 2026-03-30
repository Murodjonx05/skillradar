# Security Best Practices Report

## Executive Summary

This review covered the PHP/Moodle local plugin in `/local/skillradar`, with a focus on request handlers, authorization checks, CSRF defenses, parameter validation, and the code paths that mutate quiz/question state.

The plugin is generally aligned with Moodle security conventions at its public entrypoints: forms use `sesskey`, core request parameters are typed, and the main pages require login plus capability checks. I found two meaningful issues that should be fixed. The higher-risk issue is an authorization gap in the random-by-skill flow that trusts a caller-supplied question-bank context without verifying it belongs to the current course's allowed banks. The second issue is an integrity/authorization weakness in the observer that persists question-to-skill mappings based on submitted form metadata without verifying that the changed question actually belongs to the claimed course.

## High

### SBP-001: Random-by-skill accepts arbitrary bank context IDs without server-side allowlist enforcement

Impact: A user who can manage Skill Radar for one course can potentially create random quiz slots sourced from an unexpected question bank context by tampering with `bankcontextid`, which risks crossing intended question-bank boundaries and exposing or consuming questions outside the course-approved bank list.

**Why this matters**

The page builds an allowed bank list for the UI, but the submitted `bankcontextid` is trusted if it is a positive integer. The downstream code uses that context ID to derive category IDs and build the random-question filter without re-checking that the chosen bank is one of the banks returned by the course-scoped allowlist.

**Evidence**

- [`random_by_skill.php:40`](./random_by_skill.php) to [`random_by_skill.php:69`](./random_by_skill.php) derives a default bank from course-visible banks, but this is only for initial selection.
- [`random_by_skill.php:79`](./random_by_skill.php) to [`random_by_skill.php:83`](./random_by_skill.php) accepts any posted `bankcontextid > 0`.
- [`random_by_skill.php:103`](./random_by_skill.php) to [`random_by_skill.php:116`](./random_by_skill.php) passes that unverified context ID into validation and slot creation.
- [`classes/random_question_manager.php:429`](./classes/random_question_manager.php) to [`classes/random_question_manager.php:441`](./classes/random_question_manager.php) converts any positive context ID directly into question category IDs.
- [`classes/random_question_manager.php:294`](./classes/random_question_manager.php) to [`classes/random_question_manager.php:332`](./classes/random_question_manager.php) uses the resulting filter to add random questions to the quiz.

**Recommendation**

Before validation or slot creation, reject any `bankcontextid` that is not present in the result of `random_question_manager::get_available_banks($courseid)`. Keep this validation server-side even if the UI select already constrains options.

## Medium

### SBP-002: Question mapping observer trusts submitted course/module metadata without verifying question ownership

**Why this matters**

The observer persists a question-to-skill mapping after `question_created` and `question_updated` by reading `cmid`, `courseid`, and `skillradar_skill_key` from the submitted form. It checks `sesskey`, confirms the user can manage the claimed course, and ensures the module belongs to that course, but it does not verify that the `questionid` from the event actually belongs to that course or to a bank/module associated with it.

That means a user who can edit a question elsewhere and who also has `local/skillradar:manage` in some course could potentially submit crafted form metadata that causes the observer to persist a mapping for an unrelated question into that course's Skill Radar data set. This is primarily an integrity issue, but it can also undermine later analytics and random-question selection.

**Evidence**

- [`classes/observer.php:119`](./classes/observer.php) to [`classes/observer.php:125`](./classes/observer.php) trusts posted form metadata after `data_submitted()` and `confirm_sesskey()`.
- [`classes/observer.php:132`](./classes/observer.php) to [`classes/observer.php:149`](./classes/observer.php) validates only the claimed `cmid`/`courseid` relationship and the actor's capability in that course.
- [`classes/observer.php:164`](./classes/observer.php) to [`classes/observer.php:175`](./classes/observer.php) writes the mapping for the event's `questionid` without checking that the question is actually part of that course's quiz/question-bank scope.

**Recommendation**

Before persisting the mapping, resolve the question's real bank/category/context and verify it belongs to the same course or to one of the course's allowed question-bank contexts. If that linkage cannot be proven, abort without writing the mapping.

## Lower-Risk Notes

- Public endpoints generally follow Moodle's core patterns: `require_login`, capability checks, and `sesskey` validation are present in [`api.php`](./api.php), [`manage.php`](./manage.php), [`map_questions.php`](./map_questions.php), and [`random_by_skill.php`](./random_by_skill.php).
- I did not find obvious SQL injection sinks in the reviewed code paths; database access is done through Moodle's parameterized APIs.
- Output escaping looks mostly disciplined, with `s()`, `format_string()`, and `html_writer` used in the main admin pages I reviewed.

## Suggested Fix Order

1. Fix SBP-001 first because it affects server-side authorization around question-bank selection and directly influences quiz mutation.
2. Fix SBP-002 next to tighten integrity checks around event-driven question mapping persistence.

## Verification Status

This report is based on static review only. I did not run the test suite or attempt exploit reproduction during this pass.
