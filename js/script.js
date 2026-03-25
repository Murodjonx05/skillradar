// This file is part of Moodle - http://moodle.org/

(function() {
    'use strict';

    function readConfig(root) {
        try {
            return JSON.parse(root.getAttribute('data-config') || '{}');
        } catch (e) {
            return null;
        }
    }

    function ensureMinSkills(detail, strings) {
        var label = (strings && strings.notConfigured) || 'Not configured';
        var index = 0;
        while (detail.length < 3) {
            detail.push({
                key: '_placeholder_' + index,
                label: label,
                color: '#CBD5E1',
                value: 0,
                items: 0,
                empty: true,
                placeholder: true
            });
            index++;
        }
        return detail;
    }

    function renderResults(container, payload) {
        if (!container) {
            return;
        }
        var rows = (payload.skills_detail || []).filter(function(item) {
            return !item.placeholder;
        });
        if (!rows.length) {
            container.innerHTML = '<p class="local-skillradar-results-empty">' + (((payload.strings && payload.strings.noResults) || 'No graded skills yet.')) + '</p>';
            return;
        }
        var html = '<h5 class="local-skillradar-results-title">' + (((payload.strings && payload.strings.resultBreakdown) || 'Result breakdown')) + '</h5><div class="local-skillradar-results-list">';
        rows.forEach(function(row) {
            html += '<div class="local-skillradar-result-item">' +
                '<span class="local-skillradar-result-dot" style="background:' + row.color + ';"></span>' +
                '<span class="local-skillradar-result-label">' + row.label + '</span>' +
                '<span class="local-skillradar-result-value">' + (row.value === null ? '—' : row.value.toFixed(2) + '%') + '</span>' +
                '</div>';
        });
        html += '</div>';
        container.innerHTML = html;
    }

    function renderTextDebug(container, payload) {
        if (!container) {
            return;
        }
        var rows = payload.skills_detail || [];
        if (!rows.length) {
            container.innerHTML = '<p>Нет skills для показа.</p>';
            return;
        }
        container.innerHTML = rows.map(function(row) {
            return '<p><strong>' + row.label + '</strong>: ' +
                (row.value === null ? '—' : row.value.toFixed(2) + '%') +
                ' | items=' + row.items +
                ' | empty=' + (row.empty ? 'true' : 'false') +
                ' | placeholder=' + (row.placeholder ? 'true' : 'false') +
                '</p>';
        }).join('');
    }

    function renderJsonDebug(container, data) {
        if (container) {
            container.textContent = JSON.stringify(data, null, 2);
        }
    }

    function fetchPayload(apiUrl, courseId, userId, sesskey, includeAverage) {
        var params = new URLSearchParams();
        params.set('courseid', String(courseId));
        params.set('userid', String(userId));
        params.set('sesskey', sesskey);
        if (includeAverage) {
            params.set('courseavg', '1');
        }
        return fetch(apiUrl + '?' + params.toString(), {credentials: 'same-origin'}).then(function(response) {
            if (!response.ok) {
                throw new Error('skillradar_http_' + response.status);
            }
            return response.json();
        });
    }

    function parseGradePercent(raw) {
        if (typeof raw !== 'string') {
            return null;
        }
        var normalized = raw.replace(/\s+/g, '').replace(',', '.');
        if (!normalized || normalized === '-' || normalized === '—') {
            return null;
        }
        var value = parseFloat(normalized);
        return isNaN(value) ? null : value;
    }

    function getGradeCellPercent(row, gradeItemId) {
        var cell = row.querySelector('td[data-itemid="' + gradeItemId + '"]');
        if (!cell) {
            return null;
        }
        var input = cell.querySelector('input');
        if (input && typeof input.value === 'string') {
            return parseGradePercent(input.value);
        }
        return parseGradePercent(cell.textContent || '');
    }

    function buildPayloadFromGradeTable(basePayload, userId) {
        var row = document.querySelector('tr.userrow[data-uid="' + userId + '"]');
        if (!row || !basePayload.mapping_meta || !basePayload.mapping_meta.length) {
            return Object.assign({}, basePayload, {
                skills_detail: ensureMinSkills((basePayload.skills_detail || []).slice(), basePayload.strings || {})
            });
        }

        var nextDetail = [];
        basePayload.mapping_meta.forEach(function(skill) {
            var weighted = 0;
            var weightSum = 0;
            (skill.items || []).forEach(function(mapping) {
                var percent = getGradeCellPercent(row, mapping.gradeitemid);
                if (percent === null) {
                    return;
                }
                var weight = mapping.weight > 0 ? mapping.weight : 1;
                weighted += percent * weight;
                weightSum += weight;
            });
            nextDetail.push({
                key: skill.key,
                label: skill.label,
                color: skill.color,
                value: weightSum > 0 ? Math.round((weighted / weightSum) * 100) / 100 : null,
                items: (skill.items || []).length,
                empty: weightSum <= 0,
                placeholder: false
            });
        });

        return Object.assign({}, basePayload, {
            skills_detail: ensureMinSkills(nextDetail, basePayload.strings || {})
        });
    }

    function findFirstGraderUserId() {
        var row = document.querySelector('tr.userrow[data-uid]');
        return row ? parseInt(row.getAttribute('data-uid'), 10) : 0;
    }

    function boot() {
        var panel = document.getElementById('local-skillradar-panel');
        if (!panel) {
            return;
        }
        var config = readConfig(panel);
        if (!config) {
            return;
        }
        var results = document.getElementById('local-skillradar-results');
        var textdebug = document.getElementById('local-skillradar-text');
        var jsondebug = document.getElementById('local-skillradar-json');

        function load(userId) {
            if (!userId) {
                if (results) {
                    results.innerHTML = '<p class="local-skillradar-results-empty">' + ((config.strings && config.strings.selectStudent) || 'Select student') + '</p>';
                }
                if (textdebug) {
                    textdebug.innerHTML = '';
                }
                return;
            }
            fetchPayload(config.apiUrl, config.courseId, userId, config.sesskey, true).then(function(payload) {
                payload.strings = Object.assign({}, config.strings || {}, payload.strings || {});
                payload = buildPayloadFromGradeTable(payload, userId);
                renderResults(results, payload);
                renderTextDebug(textdebug, payload);
                renderJsonDebug(jsondebug, {
                    userId: userId,
                    payload: payload
                });
                return payload;
            }).catch(function() {
                renderResults(results, {skills_detail: [], strings: config.strings || {}});
                renderTextDebug(textdebug, {skills_detail: []});
                renderJsonDebug(jsondebug, {
                    userId: userId,
                    error: 'fetch_failed'
                });
            });
        }

        load(config.userId > 0 ? config.userId : findFirstGraderUserId());

        document.addEventListener('click', function(event) {
            var row = event.target.closest('tr.userrow[data-uid]');
            if (!row) {
                return;
            }
            load(parseInt(row.getAttribute('data-uid'), 10) || 0);
        });
    }

    window.localSkillRadarBoot = boot;
    boot();
})();
