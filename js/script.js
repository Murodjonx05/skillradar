// This file is part of Moodle - http://moodle.org/
/* global Chart, localSkillRadarCreateArcPlugin */

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

    function findFirstGraderUserId() {
        var row = document.querySelector('tr.userrow[data-uid]');
        return row ? parseInt(row.getAttribute('data-uid'), 10) : 0;
    }

    function hexToRgb(hex) {
        var m = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex || '');
        return m ? {
            r: parseInt(m[1], 16),
            g: parseInt(m[2], 16),
            b: parseInt(m[3], 16)
        } : {r: 59, g: 130, b: 246};
    }

    function hexToRgba(hex, a) {
        var o = hexToRgb(hex);
        return 'rgba(' + o.r + ',' + o.g + ',' + o.b + ',' + a + ')';
    }

    function applyPrimaryColor(root, hex) {
        if (!root || !hex) {
            return;
        }
        var rgb = hexToRgb(hex);
        root.style.setProperty('--sr-primary', hex);
        root.style.setProperty('--sr-primary-muted', 'rgba(' + rgb.r + ',' + rgb.g + ',' + rgb.b + ',0.72)');
    }

    function resolvePrimaryColor(payload, fallback) {
        if (payload && payload.primaryColor) {
            return payload.primaryColor;
        }
        if (payload && payload.config && payload.config.primaryColor) {
            return payload.config.primaryColor;
        }
        return fallback || '#3B82F6';
    }

    function buildDatasets(payload, ctx) {
        var canvas = ctx.canvas;
        var primary = resolvePrimaryColor(payload, null);
        var gradient = ctx.createLinearGradient(0, 0, canvas.width || 600, canvas.height || 600);
        gradient.addColorStop(0, hexToRgba(primary, 0.24));
        gradient.addColorStop(1, hexToRgba(primary, 0.08));

        var hasUserValues = (payload.chart && payload.chart.values ? payload.chart.values : []).some(function(value) {
            return value !== null;
        });
        var chartVals = payload.chart && payload.chart.values ? payload.chart.values : [];
        var userValues = chartVals.map(function(value) {
            return value === null ? 0 : value;
        });
        var solid = hexToRgba(primary, 1);

        var datasets = [{
            label: 'Skill level (%)',
            data: userValues,
            radarArcSegments: true,
            radarArcStrokeWidth: 2.4,
            backgroundColor: hasUserValues ? gradient : 'rgba(148, 163, 184, 0.08)',
            borderColor: hasUserValues ? solid : 'rgba(148, 163, 184, 0.95)',
            borderDash: hasUserValues ? [] : [6, 6],
            pointBackgroundColor: hasUserValues ? solid : 'rgba(148, 163, 184, 0.95)',
            pointBorderColor: '#fff',
            pointHoverBackgroundColor: '#fff',
            pointHoverBorderColor: hasUserValues ? solid : 'rgba(148, 163, 184, 0.95)',
            pointRadius: hasUserValues ? 6 : 4,
            pointHoverRadius: 7,
            borderWidth: 0,
            fill: false,
            tension: 0,
            radarWaveNoise: hasUserValues,
            radarWaveAmp: 0.034,
            radarWaveShadowColor: hasUserValues ? solid : undefined
        }];

        if (payload.course_average && payload.course_average.values) {
            var avgVals = payload.course_average.values;
            datasets.push({
                label: payload.course_average.label ||
                    ((payload.strings && payload.strings.courseAverageLegend) || 'Course average'),
                data: avgVals.map(function(value) {
                    return value === null ? 0 : value;
                }),
                radarArcSegments: true,
                radarArcStrokeWidth: 1.8,
                radarWaveAmp: 0.022,
                radarWaveGlowAlpha: 0.55,
                radarWaveShadowBlur: 8,
                backgroundColor: 'rgba(100, 116, 139, 0.08)',
                borderColor: 'rgba(100, 116, 139, 0.9)',
                pointBackgroundColor: 'rgba(100, 116, 139, 0.9)',
                pointBorderColor: '#fff',
                pointRadius: 4,
                pointHoverRadius: 5,
                borderWidth: 0,
                fill: false,
                tension: 0
            });
        }

        return datasets;
    }

    function updateCenterScore(payload, valueNode, labelNode, buttonNode) {
        if (!valueNode || !labelNode || !buttonNode) {
            return;
        }
        var percent = payload.overall && payload.overall.percent !== null ? payload.overall.percent : null;
        var backendLetter = payload.overall && payload.overall.letter;
        var letter = (typeof backendLetter === 'string' && backendLetter.length > 0) ? backendLetter : getRank(percent);
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
            plugins: typeof localSkillRadarCreateArcPlugin === 'function' ?
                [centerHtmlPlugin, localSkillRadarCreateArcPlugin()] :
                [centerHtmlPlugin]
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
        applyPrimaryColor(panel, config.primaryColor || '#3B82F6');
        var results = document.getElementById('local-skillradar-results');
        var textdebug = document.getElementById('local-skillradar-text');
        var jsondebug = document.getElementById('local-skillradar-json');
        var canvas = document.getElementById('local-skillradar-canvas');
        var centerButton = document.getElementById('local-skillradar-center-score');
        var centerValue = document.getElementById('local-skillradar-score-value');
        var centerLabel = document.getElementById('local-skillradar-score-label');
        var lastPayload = null;

        if (centerButton && !centerButton.getAttribute('data-bound')) {
            centerButton.setAttribute('data-bound', '1');
            centerButton.addEventListener('click', function() {
                showPercentage = !showPercentage;
                if (lastPayload) {
                    updateCenterScore(lastPayload, centerValue, centerLabel, centerButton);
                }
            });
        }

        function clearChartWhenNoUser() {
            if (chartInstance) {
                chartInstance.destroy();
                chartInstance = null;
            }
            if (centerValue) {
                centerValue.textContent = '—';
            }
            if (centerLabel) {
                centerLabel.textContent = 'AVERAGE';
            }
            if (centerButton) {
                centerButton.classList.remove('rank-mode');
            }
            showPercentage = true;
        }

        function load(userId) {
            if (!userId) {
                lastPayload = null;
                clearChartWhenNoUser();
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
                lastPayload = payload;
                applyPrimaryColor(panel, resolvePrimaryColor(payload, config.primaryColor));
                renderChart(canvas, payload, {
                    button: centerButton,
                    value: centerValue,
                    label: centerLabel
                });
                renderResults(results, payload);
                if (config.debugSkillRadar) {
                    renderTextDebug(textdebug, payload);
                    renderJsonDebug(jsondebug, {
                        userId: userId,
                        payload: payload
                    });
                }
                return payload;
            }).catch(function() {
                lastPayload = null;
                renderResults(results, {'skills_detail': [], strings: config.strings || {}});
                if (config.debugSkillRadar) {
                    renderTextDebug(textdebug, {'skills_detail': []});
                    renderJsonDebug(jsondebug, {
                        userId: userId,
                        error: 'fetch_failed'
                    });
                }
            });
        }

        var initialUserId = config.userId > 0 ? config.userId :
            (config.reportType === 'grader' ? findFirstGraderUserId() : 0);
        load(initialUserId);

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
