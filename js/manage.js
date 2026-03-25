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

    function collectSkillDefinitions() {
        return Array.prototype.slice.call(document.querySelectorAll('[data-skill-key]')).map(function(node) {
            var row = node.closest('tr');
            return {
                key: node.getAttribute('data-skill-key') || '',
                label: row && row.querySelector('[data-skill-name]') ? row.querySelector('[data-skill-name]').getAttribute('data-skill-name') : node.textContent.trim(),
                color: row && row.querySelector('[data-skill-color]') ? row.querySelector('[data-skill-color]').getAttribute('data-skill-color') : '#64748B'
            };
        });
    }

    function buildFormPreviewPayload(strings) {
        var defs = collectSkillDefinitions();
        var counts = {};
        defs.forEach(function(def) {
            counts[def.key] = 0;
        });

        Array.prototype.slice.call(document.querySelectorAll('select[name^="skill_"]')).forEach(function(select) {
            var value = select.value;
            if (value && value !== '_none' && typeof counts[value] !== 'undefined') {
                counts[value]++;
            }
        });

        return {
            skills_detail: ensureMinSkills(defs.map(function(def) {
                return {
                    key: def.key,
                    label: def.label,
                    color: def.color,
                    value: 0,
                    items: counts[def.key] || 0,
                    empty: true,
                    placeholder: false
                };
            }), strings),
            overall: {
                percent: null,
                letter: ''
            },
            strings: strings || {}
        };
    }

    function renderResults(container, payload) {
        if (!container) {
            return;
        }
        var rows = (payload.skills_detail || []).filter(function(item) {
            return !item.placeholder;
        });
        if (!rows.length) {
            container.innerHTML = '<p class="local-skillradar-results-empty">' + ((payload.strings && payload.strings.noResults) || 'No graded skills yet.') + '</p>';
            return;
        }
        var html = '<h5 class="local-skillradar-results-title">' + (((payload.strings && payload.strings.resultBreakdown) || 'Result breakdown')) + '</h5><div class="local-skillradar-results-list">';
        rows.forEach(function(row) {
            html += '<div class="local-skillradar-result-item">' +
                '<span class="local-skillradar-result-dot" style="background:' + row.color + ';"></span>' +
                '<span class="local-skillradar-result-label">' + row.label + '</span>' +
                '<span class="local-skillradar-result-value">' + (row.value === null ? '0%' : row.value.toFixed(2) + '%') +
                '<span class="local-skillradar-result-meta">' + row.items + ' ' + (((payload.strings && payload.strings.mappedItems) || 'mapped')) + '</span></span>' +
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
                (row.value === null ? '0%' : row.value.toFixed(2) + '%') +
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

    function fetchPayload(config) {
        var params = new URLSearchParams();
        params.set('courseid', String(config.courseId));
        params.set('userid', String(config.userId));
        params.set('sesskey', config.sesskey);
        return fetch(config.apiUrl + '?' + params.toString(), {credentials: 'same-origin'}).then(function(response) {
            if (!response.ok) {
                throw new Error('skillradar_http_' + response.status);
            }
            return response.json();
        });
    }

    function hasRealGrades(payload) {
        return (payload.skills_detail || []).some(function(item) {
            return !item.placeholder && item.value !== null;
        });
    }

    function boot() {
        var root = document.getElementById('local-skillradar-manage-preview');
        if (!root || root.getAttribute('data-booted') === '1') {
            return;
        }
        var config = readConfig(root);
        if (!config) {
            return;
        }
        root.setAttribute('data-booted', '1');

        var loading = document.getElementById('local-skillradar-manage-loading');
        var results = document.getElementById('local-skillradar-manage-results');
        var textdebug = document.getElementById('local-skillradar-manage-text');
        var jsondebug = document.getElementById('local-skillradar-manage-json');
        var strings = Object.assign({}, config.strings || {});

        function updatePreview() {
            var fallback = buildFormPreviewPayload(strings);
            renderJsonDebug(jsondebug, {
                mode: 'form-preview',
                config: config,
                fallback: fallback
            });
            fetchPayload(config).then(function(payload) {
                payload.strings = Object.assign({}, strings, payload.strings || {});
                var output = hasRealGrades(payload) ? payload : fallback;
                renderResults(results, output);
                renderTextDebug(textdebug, output);
                renderJsonDebug(jsondebug, {
                    mode: hasRealGrades(payload) ? 'api-grades' : 'form-preview-fallback',
                    config: config,
                    payload: payload,
                    fallback: fallback
                });
                if (loading) {
                    loading.style.display = 'none';
                }
                return payload;
            }).catch(function() {
                renderResults(results, fallback);
                renderTextDebug(textdebug, fallback);
                renderJsonDebug(jsondebug, {
                    mode: 'fetch-fallback',
                    config: config,
                    fallback: fallback
                });
                if (loading) {
                    loading.style.display = 'none';
                }
                return fallback;
            });
        }

        document.addEventListener('change', function(event) {
            if (event.target.matches('select[name^="skill_"], input[name^="weight_"]')) {
                if (loading) {
                    loading.style.display = 'block';
                }
                updatePreview();
            }
        });

        updatePreview();
    }

    window.localSkillRadarManageBoot = boot;
    boot();
})();
