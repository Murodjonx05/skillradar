// This file is part of Moodle - http://moodle.org/
/* global Chart */

(function() {
    'use strict';

    var chartInstance = null;
    var showPercentage = true;

    function readConfig(root) {
        try {
            return JSON.parse(root.getAttribute('data-config') || '{}');
        } catch (e) {
            return null;
        }
    }

    function getRank(percent) {
        if (percent === null || typeof percent === 'undefined' || isNaN(percent)) {
            return '—';
        }
        if (percent >= 95) {
            return 'S+';
        }
        if (percent >= 90) {
            return 'S';
        }
        if (percent >= 85) {
            return 'S-';
        }
        if (percent >= 80) {
            return 'A+';
        }
        if (percent >= 75) {
            return 'A';
        }
        if (percent >= 70) {
            return 'A-';
        }
        if (percent >= 65) {
            return 'B+';
        }
        if (percent >= 60) {
            return 'B';
        }
        if (percent >= 55) {
            return 'B-';
        }
        if (percent >= 50) {
            return 'C+';
        }
        if (percent >= 40) {
            return 'C';
        }
        if (percent >= 30) {
            return 'D';
        }
        if (percent >= 15) {
            return 'E+';
        }
        if (percent >= 5) {
            return 'E';
        }
        return 'E-';
    }

    function renderResults(container, payload) {
        if (!container) {
            return;
        }
        var rows = (payload.skills_detail || []).filter(function(item) {
            return !item.placeholder;
        });
        var hasRealValues = rows.some(function(row) {
            return row.value !== null;
        });
        if (!rows.length || !hasRealValues) {
            container.innerHTML = '<p class="local-skillradar-results-empty">' +
                (((payload.strings && payload.strings.noResults) || 'No graded skills yet.')) + '</p>';
            return;
        }
        var html = '<h5 class="local-skillradar-results-title">' +
            (((payload.strings && payload.strings.resultBreakdown) || 'Result breakdown')) +
            '</h5><div class="local-skillradar-results-list">';
        rows.forEach(function(row) {
            html += '<div class="local-skillradar-result-item">' +
                '<span class="local-skillradar-result-dot" style="background:' + row.color + ';"></span>' +
                '<span class="local-skillradar-result-label">' + row.label + '</span>' +
                '<span class="local-skillradar-result-value">' +
                (row.value === null ? '—' : row.value.toFixed(2) + '%') +
                '</span>' +
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

    /**
     * Parse numeric grade from grader cell (ball / raw), not yet a percentage.
     * @param {string} raw
     * @returns {number|null}
     */
    function parseGradeCellString(raw) {
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

    /**
     * @param {number|null|undefined} raw
     * @param {number|null|undefined} grademin
     * @param {number|null|undefined} grademax
     * @returns {number|null}
     */
    function rawGradeToPercent(raw, grademin, grademax) {
        if (raw === null || typeof raw === 'undefined' || isNaN(raw)) {
            return null;
        }
        var gmin = (grademin === null || grademin === undefined) ? 0 : Number(grademin);
        gmin = isNaN(gmin) ? 0 : gmin;
        var gmax = (grademax === null || grademax === undefined) ? 100 : Number(grademax);
        gmax = isNaN(gmax) ? 100 : gmax;
        var grange = gmax - gmin;
        if (grange <= 0) {
            return null;
        }
        return ((Number(raw) - gmin) / grange) * 100;
    }

    function getGradeCellRaw(row, gradeItemId) {
        var cell = row.querySelector('td[data-itemid="' + gradeItemId + '"]');
        if (!cell) {
            return null;
        }
        var input = cell.querySelector('input');
        if (input && typeof input.value === 'string') {
            return parseGradeCellString(input.value);
        }
        return parseGradeCellString(cell.textContent || '');
    }

    /**
     * Overall average: sum(non-placeholder values) / count, treating null as 0.
     * @param {Array<{placeholder?: boolean, value: number|null}>} detail
     * @returns {{percent: number|null, letter: string}}
     */
    function computeOverallFromDetail(detail) {
        var rows = (detail || []).filter(function(r) {
            return !r.placeholder;
        });
        if (!rows.length) {
            return {percent: null, letter: ''};
        }
        var sum = 0;
        var i;
        for (i = 0; i < rows.length; i++) {
            var v = rows[i].value;
            sum += (v === null || typeof v === 'undefined' || isNaN(v)) ? 0 : Number(v);
        }
        var pct = Math.round((sum / rows.length) * 100) / 100;
        return {
            percent: pct,
            letter: getRank(pct)
        };
    }

    function buildPayloadFromGradeTable(basePayload, userId) {
        var row = document.querySelector('tr.userrow[data-uid="' + userId + '"]');
        if (!row || !basePayload.mapping_meta || !basePayload.mapping_meta.length) {
            return basePayload;
        }

        var nextDetail = [];
        basePayload.mapping_meta.forEach(function(skill) {
            var weighted = 0;
            var weightSum = 0;
            (skill.items || []).forEach(function(mapping) {
                var ball = getGradeCellRaw(row, mapping.gradeitemid);
                if (ball === null) {
                    return;
                }
                var percent = rawGradeToPercent(ball, mapping.grademin, mapping.grademax);
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

        var nextValues = nextDetail.map(function(rowItem) {
            return rowItem.value;
        });
        while (nextValues.length < 3) {
            nextValues.push(null);
        }

        var nextOverall = computeOverallFromDetail(nextDetail);

        return Object.assign({}, basePayload, {
            skills: nextDetail.reduce(function(acc, rowItem) {
                acc[rowItem.label] = rowItem.value === null ? 0 : rowItem.value;
                return acc;
            }, {}),
            'skills_detail': nextDetail,
            overall: {
                percent: nextOverall.percent,
                letter: nextOverall.letter
            },
            chart: Object.assign({}, basePayload.chart || {}, {
                labels: nextDetail.map(function(rowItem) {
                    return rowItem.label;
                }),
                values: nextValues
            })
        });
    }

    function findFirstGraderUserId() {
        var row = document.querySelector('tr.userrow[data-uid]');
        return row ? parseInt(row.getAttribute('data-uid'), 10) : 0;
    }

    function buildDatasets(payload, ctx) {
        var canvas = ctx.canvas;
        var gradient = ctx.createLinearGradient(0, 0, canvas.width || 600, canvas.height || 600);
        gradient.addColorStop(0, 'rgba(54, 162, 235, 0.24)');
        gradient.addColorStop(1, 'rgba(54, 162, 235, 0.08)');

        var hasUserValues = (payload.chart && payload.chart.values ? payload.chart.values : []).some(function(value) {
            return value !== null;
        });
        var userValues = (payload.chart && payload.chart.values ? payload.chart.values : []).map(function(value) {
            return value === null ? 0 : value;
        });

        var datasets = [{
            label: 'Skill level (%)',
            data: userValues,
            backgroundColor: hasUserValues ? gradient : 'rgba(148, 163, 184, 0.08)',
            borderColor: hasUserValues ? 'rgba(54, 162, 235, 1)' : 'rgba(148, 163, 184, 0.95)',
            borderDash: hasUserValues ? [] : [6, 6],
            pointBackgroundColor: hasUserValues ? 'rgba(54, 162, 235, 1)' : 'rgba(148, 163, 184, 0.95)',
            pointBorderColor: '#fff',
            pointHoverBackgroundColor: '#fff',
            pointHoverBorderColor: hasUserValues ? 'rgba(54, 162, 235, 1)' : 'rgba(148, 163, 184, 0.95)',
            pointRadius: hasUserValues ? 3 : 2,
            pointHoverRadius: 4,
            borderWidth: 2.4,
            tension: 0.32,
            fill: true
        }];

        if (payload.course_average && payload.course_average.values) {
            datasets.push({
                label: payload.course_average.label ||
                    ((payload.strings && payload.strings.courseAverageLegend) || 'Course average'),
                data: payload.course_average.values.map(function(value) {
                    return value === null ? 0 : value;
                }),
                backgroundColor: 'rgba(100, 116, 139, 0.08)',
                borderColor: 'rgba(100, 116, 139, 0.9)',
                pointBackgroundColor: 'rgba(100, 116, 139, 0.9)',
                pointBorderColor: '#fff',
                pointRadius: 2,
                pointHoverRadius: 3,
                borderWidth: 1.8,
                tension: 0.28,
                fill: true
            });
        }

        return datasets;
    }

    function updateCenterScore(payload, valueNode, labelNode, buttonNode) {
        if (!valueNode || !labelNode || !buttonNode) {
            return;
        }
        var percent = payload.overall && payload.overall.percent !== null ? payload.overall.percent : null;
        var letter = payload.overall && payload.overall.letter ? payload.overall.letter : getRank(percent);
        if (showPercentage) {
            buttonNode.classList.remove('rank-mode');
            valueNode.textContent = percent === null ? '—' : Math.round(percent) + '%';
            labelNode.textContent = 'AVERAGE';
        } else {
            buttonNode.classList.add('rank-mode');
            valueNode.textContent = letter || '—';
            labelNode.textContent = 'GRADE';
        }
    }

    function renderChart(canvas, payload, centerElements) {
        if (!canvas || typeof Chart === 'undefined' || !payload.chart) {
            return;
        }
        var labels = payload.chart.labels || [];
        var context = canvas.getContext('2d');
        if (chartInstance) {
            chartInstance.destroy();
            chartInstance = null;
        }

        var centerHtmlPlugin = {
            id: 'centerHtmlPlugin',
            afterDraw: function(chart, args, pluginOptions) {
                var el = pluginOptions.element;
                var scale = chart.scales.r;
                if (!el || !scale) {
                    return;
                }
                el.style.left = scale.xCenter + 'px';
                el.style.top = scale.yCenter + 'px';
            }
        };

        chartInstance = new Chart(context, {
            type: 'radar',
            data: {
                labels: labels,
                datasets: buildDatasets(payload, context)
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: {
                    padding: {
                        top: 8,
                        right: 8,
                        bottom: 8,
                        left: 8
                    }
                },
                plugins: {
                    centerHtmlPlugin: {
                        element: centerElements.button
                    },
                    legend: {
                        position: 'top',
                        labels: {
                            boxWidth: 18,
                            boxHeight: 10,
                            padding: 18
                        }
                    },
                    tooltip: {
                        callbacks: {
                            label: function(item) {
                                var datasetLabel = item.dataset.label || '';
                                var value = item.parsed.r;
                                if (datasetLabel) {
                                    datasetLabel += ': ';
                                }
                                return datasetLabel + value + '% (' + getRank(value) + ')';
                            }
                        }
                    }
                },
                scales: {
                    r: {
                        min: 0,
                        max: 100,
                        ticks: {
                            display: false,
                            stepSize: 20
                        },
                        angleLines: {
                            color: 'rgba(0, 0, 0, 0.08)'
                        },
                        grid: {
                            color: 'rgba(0, 0, 0, 0.08)',
                            circular: true
                        },
                        pointLabels: {
                            font: {
                                size: 13,
                                weight: '600'
                            },
                            color: '#333',
                            padding: 8
                        }
                    }
                },
                animation: {
                    duration: 700
                }
            },
            plugins: [centerHtmlPlugin]
        });

        updateCenterScore(payload, centerElements.value, centerElements.label, centerElements.button);
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
        var canvas = document.getElementById('local-skillradar-canvas');
        var centerButton = document.getElementById('local-skillradar-center-score');
        var centerValue = document.getElementById('local-skillradar-score-value');
        var centerLabel = document.getElementById('local-skillradar-score-label');

        if (centerButton && !centerButton.getAttribute('data-bound')) {
            centerButton.setAttribute('data-bound', '1');
            centerButton.addEventListener('click', function() {
                showPercentage = !showPercentage;
                if (jsondebug && jsondebug.textContent) {
                    try {
                        var current = JSON.parse(jsondebug.textContent);
                        if (current.payload) {
                            updateCenterScore(current.payload, centerValue, centerLabel, centerButton);
                        }
                    } catch (e) {
                        // Ignore JSON debug parsing issues for the toggle.
                    }
                }
            });
        }

        function load(userId) {
            if (!userId) {
                if (results) {
                    results.innerHTML = '<p class="local-skillradar-results-empty">' +
                        ((config.strings && config.strings.selectStudent) || 'Select student') + '</p>';
                }
                if (textdebug) {
                    textdebug.innerHTML = '';
                }
                return;
            }
            fetchPayload(config.apiUrl, config.courseId, userId, config.sesskey, true).then(function(payload) {
                payload.strings = Object.assign({}, config.strings || {}, payload.strings || {});
                payload = buildPayloadFromGradeTable(payload, userId);
                renderChart(canvas, payload, {
                    button: centerButton,
                    value: centerValue,
                    label: centerLabel
                });
                renderResults(results, payload);
                renderTextDebug(textdebug, payload);
                renderJsonDebug(jsondebug, {
                    userId: userId,
                    payload: payload
                });
                return payload;
            }).catch(function() {
                renderResults(results, {'skills_detail': [], strings: config.strings || {}});
                renderTextDebug(textdebug, {'skills_detail': []});
                renderJsonDebug(jsondebug, {
                    userId: userId,
                    error: 'fetch_failed'
                });
            });
        }

        load(config.userId > 0 ? config.userId : findFirstGraderUserId());

        document.addEventListener('click', function(event) {
            var row = event.target.closest('tr.userrow[data-uid]');
            if (!row || config.reportType !== 'grader') {
                return;
            }
            load(parseInt(row.getAttribute('data-uid'), 10) || 0);
        });
    }

    window.localSkillRadarBoot = boot;
    boot();
})();
