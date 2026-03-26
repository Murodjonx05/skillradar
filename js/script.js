// This file is part of Moodle - http://moodle.org/
/* global Chart, localSkillRadarCreateArcPlugin */

(function() {
    'use strict';

    var chartHolderSkills = {inst: null};
    var showPercentage = true;

    var C = window.localSkillRadarCommon;
    if (!C) {
        return;
    }
    var RADAR_R_MIN = C.RADAR_R_MIN;
    var RADAR_R_MAX = C.RADAR_R_MAX;
    var escapeHtml = C.escapeHtml;
    var safeHexColor = C.safeHexColor;
    var getRank = C.getRank;
    var readConfig = C.readConfig;
    var hexToRgba = C.hexToRgba;
    var applyPrimaryColor = C.applyPrimaryColor;
    var resolvePrimaryColor = C.resolvePrimaryColor;

    function renderResults(container, payload) {
        if (!container) {
            return;
        }
        if (payload && payload.empty_single_quiz && payload.empty_message) {
            container.innerHTML = '<p class="local-skillradar-results-empty">' + escapeHtml(payload.empty_message) + '</p>';
            return;
        }
        if (!payload || !payload.chart) {
            var nr = (payload && payload.strings && payload.strings.noResults) ? payload.strings.noResults : 'No data.';
            container.innerHTML = '<p class="local-skillradar-results-empty">' + nr + '</p>';
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
                '<span class="local-skillradar-result-dot" style="background:' + safeHexColor(row.color) + ';"></span>' +
                '<span class="local-skillradar-result-label">' + escapeHtml(row.label) + '</span>' +
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
            return '<p><strong>' + escapeHtml(row.label) + '</strong>: ' +
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
        var requestUrl = apiUrl + '?' + params.toString();
        return fetch(requestUrl, {credentials: 'same-origin'}).then(function(response) {
            if (!response.ok) {
                throw new Error('skillradar_http_' + response.status);
            }
            return response.json();
        }).then(function(json) {
            return {payload: json, requestUrl: requestUrl};
        });
    }

    function buildSliceMetaHtml(slice, strings) {
        var s = strings || {};
        if (!slice) {
            return '<p class="small text-muted mb-0">' + escapeHtml(s.chartdebugNoPayload || 'No payload.') + '</p>';
        }
        var ch = slice.chart || {};
        var ov = slice.overall || {};
        var html = '<dl class="row small mb-0 local-skillradar-debug-dl">';
        html += '<dt class="col-sm-4">' + escapeHtml(s.chartdebugLabels || 'chart.labels') + '</dt>';
        html += '<dd class="col-sm-8"><code>' + escapeHtml(JSON.stringify(ch.labels || [])) + '</code></dd>';
        html += '<dt class="col-sm-4">' + escapeHtml(s.chartdebugValues || 'chart.values') + '</dt>';
        html += '<dd class="col-sm-8"><code>' + escapeHtml(JSON.stringify(ch.values || [])) + '</code></dd>';
        html += '<dt class="col-sm-4">' + escapeHtml(s.chartdebugOverall || 'overall') + '</dt>';
        html += '<dd class="col-sm-8"><code>' + escapeHtml(JSON.stringify(ov)) + '</code></dd>';
        html += '<dt class="col-sm-4">skills_detail</dt>';
        html += '<dd class="col-sm-8"><code>' + escapeHtml(JSON.stringify(slice.skills_detail || [])) + '</code></dd>';
        html += '</dl>';
        return html;
    }

    function buildFullMetaHtml(payload, requestUrl, userId, courseId, strings) {
        var s = strings || {};
        var html = '<p class="small mb-2"><strong>' + escapeHtml(s.chartdebugRequest || 'Request') + '</strong><br>' +
            '<code class="local-skillradar-debug-url">' + escapeHtml(requestUrl || '') + '</code></p>';
        html += '<dl class="row small mb-0 local-skillradar-debug-dl">';
        html += '<dt class="col-sm-4">courseId</dt><dd class="col-sm-8"><code>' + escapeHtml(String(courseId)) + '</code></dd>';
        html += '<dt class="col-sm-4">userid</dt><dd class="col-sm-8"><code>' + escapeHtml(String(userId)) + '</code></dd>';
        html += '</dl>';
        return html;
    }

    function updateChartJsDebug(chart, which) {
        var pre = document.getElementById('local-skillradar-debug-' + which + '-chartjs');
        if (!pre) {
            return;
        }
        if (!chart || !chart.data) {
            pre.textContent = '(no Chart instance)';
            return;
        }
        var dump = {
            labels: chart.data.labels,
            datasets: (chart.data.datasets || []).map(function(ds) {
                return {label: ds.label, data: ds.data};
            })
        };
        pre.textContent = JSON.stringify(dump, null, 2);
    }

    function renderChartDebugPanels(config, userId, requestUrl, payload) {
        if (!config.chartDebug) {
            return;
        }
        var strings = Object.assign({}, config.strings || {}, payload.strings || {});
        var course = payload.course_skills_radar;

        var metaCourse = document.getElementById('local-skillradar-debug-course-meta');
        var jsonCourse = document.getElementById('local-skillradar-debug-course-json');
        if (metaCourse) {
            metaCourse.innerHTML = buildSliceMetaHtml(course, strings);
        }
        if (jsonCourse) {
            jsonCourse.textContent = course ? JSON.stringify(course, null, 2) : 'null';
        }

        var metaFull = document.getElementById('local-skillradar-debug-full-meta');
        var jsonFull = document.getElementById('local-skillradar-debug-full-json');
        if (metaFull) {
            metaFull.innerHTML = buildFullMetaHtml(payload, requestUrl, userId, config.courseId, strings);
        }
        if (jsonFull) {
            jsonFull.textContent = JSON.stringify(payload, null, 2);
        }

        window.setTimeout(function() {
            updateChartJsDebug(chartHolderSkills.inst, 'course');
        }, 0);
    }

    function clearChartDebugPanels(config) {
        if (!config || !config.chartDebug) {
            return;
        }
        ['course', 'full'].forEach(function(which) {
            var m = document.getElementById('local-skillradar-debug-' + which + '-meta');
            var j = document.getElementById('local-skillradar-debug-' + which + '-json');
            if (m) {
                m.innerHTML = '';
            }
            if (j) {
                j.textContent = '';
            }
        });
        var cj = document.getElementById('local-skillradar-debug-course-chartjs');
        if (cj) {
            cj.textContent = '';
        }
    }

    function findFirstGraderUserId() {
        var row = document.querySelector('tr.userrow[data-uid]');
        return row ? parseInt(row.getAttribute('data-uid'), 10) : 0;
    }

    function buildPrimaryGradient(payload, ctx) {
        var canvas = ctx.canvas;
        var primary = resolvePrimaryColor(payload, null);
        var gradient = ctx.createLinearGradient(0, 0, canvas.width || 720, canvas.height || 720);
        gradient.addColorStop(0, hexToRgba(primary, 0.24));
        gradient.addColorStop(1, hexToRgba(primary, 0.08));
        return {
            gradient: gradient,
            solid: hexToRgba(primary, 1)
        };
    }

    function normalizeChartValues(payload) {
        var chartVals = payload.chart && payload.chart.values ? payload.chart.values : [];
        var hasUserValues = false;
        var userValues = new Array(chartVals.length);
        for (var vi = 0; vi < chartVals.length; vi++) {
            var v = chartVals[vi];
            if (v !== null) {
                hasUserValues = true;
            }
            userValues[vi] = v === null ? 0 : v;
        }
        return {
            hasUserValues: hasUserValues,
            userValues: userValues
        };
    }

    function buildPrimaryDataset(label, values, hasUserValues, gradient, solid) {
        return {
            label: label,
            data: values,
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
        };
    }

    function buildCourseAverageDataset(payload) {
        if (!payload.course_average || !payload.course_average.values) {
            return null;
        }

        return {
            label: payload.course_average.label ||
                ((payload.strings && payload.strings.courseAverageLegend) || 'Course average'),
            data: payload.course_average.values.map(function(value) {
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
        };
    }

    function buildDatasets(payload, ctx, datasetLabel) {
        datasetLabel = datasetLabel || 'Skill level (%)';
        var colors = buildPrimaryGradient(payload, ctx);
        var values = normalizeChartValues(payload);
        var datasets = [buildPrimaryDataset(
            datasetLabel,
            values.userValues,
            values.hasUserValues,
            colors.gradient,
            colors.solid
        )];

        var courseAverageDataset = buildCourseAverageDataset(payload);
        if (courseAverageDataset) {
            datasets.push(courseAverageDataset);
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

    function renderChart(canvas, payload, centerElements, chartHolder, datasetLabel) {
        chartHolder = chartHolder || chartHolderSkills;
        if (!canvas || typeof Chart === 'undefined') {
            return;
        }
        if (!payload || !payload.chart) {
            if (chartHolder.inst) {
                chartHolder.inst.destroy();
                chartHolder.inst = null;
            }
            return;
        }
        var labels = payload.chart.labels || [];
        var context = canvas.getContext('2d');
        if (chartHolder.inst) {
            chartHolder.inst.destroy();
            chartHolder.inst = null;
        }

        var useCenter = !!(centerElements && centerElements.button);
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

        var arcPlugins = typeof localSkillRadarCreateArcPlugin === 'function' ?
            [localSkillRadarCreateArcPlugin()] : [];
        var chartPlugins = useCenter ? [centerHtmlPlugin].concat(arcPlugins) : arcPlugins.slice();

        var pluginOpts = {
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
                        var dl = item.dataset.label || '';
                        var value = item.parsed.r;
                        if (dl) {
                            dl += ': ';
                        }
                        if (value === null || typeof value === 'undefined' || isNaN(value)) {
                            return dl + '—';
                        }
                        return dl + value + '% (' + getRank(value) + ')';
                    }
                }
            }
        };
        if (useCenter) {
            pluginOpts.centerHtmlPlugin = {
                element: centerElements.button
            };
        }

        chartHolder.inst = new Chart(context, {
            type: 'radar',
            data: {
                labels: labels,
                datasets: buildDatasets(payload, context, datasetLabel)
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
                plugins: pluginOpts,
                scales: {
                    r: {
                        min: RADAR_R_MIN,
                        max: RADAR_R_MAX,
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
            plugins: chartPlugins
        });

        if (useCenter && centerElements.value) {
            updateCenterScore(payload, centerElements.value, centerElements.label, centerElements.button);
        }
    }

    function boot() {
        var panel = document.getElementById('local-skillradar-panel');
        if (!panel) {
            return;
        }
        if (panel.getAttribute('data-sr-booted') === '1') {
            return;
        }
        var config = readConfig(panel);
        if (!config) {
            return;
        }
        panel.setAttribute('data-sr-booted', '1');
        applyPrimaryColor(panel, config.primaryColor || '#3B82F6');
        var results = document.getElementById('local-skillradar-results');
        var courseOverallEl = document.getElementById('local-skillradar-course-overall');
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
                    var cp = lastPayload.course_skills_radar || lastPayload;
                    updateCenterScore(cp, centerValue, centerLabel, centerButton);
                }
            });
        }

        function clearChartWhenNoUser() {
            if (chartHolderSkills.inst) {
                chartHolderSkills.inst.destroy();
                chartHolderSkills.inst = null;
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
            if (courseOverallEl) {
                courseOverallEl.textContent = '';
            }
            showPercentage = true;
        }

        function renderNoUserState() {
            lastPayload = null;
            clearChartWhenNoUser();
            clearChartDebugPanels(config);
            if (results) {
                results.innerHTML = '<p class="local-skillradar-results-empty">' +
                    ((config.strings && config.strings.selectStudent) || 'Select student') + '</p>';
            }
            if (textdebug) {
                textdebug.innerHTML = '';
            }
        }

        function renderCourseView(coursePayload, strings) {
            var courseLabel = strings.radarCourseSkillsDataset ||
                strings.radarQuestionSkillsDataset || 'Course skill (%)';
            if (!coursePayload || !canvas) {
                return;
            }
            renderChart(canvas, coursePayload, {
                button: centerButton,
                value: centerValue,
                label: centerLabel
            }, chartHolderSkills, courseLabel);
            if ((!coursePayload || !coursePayload.chart) && centerValue) {
                centerValue.textContent = '—';
                centerLabel.textContent = 'AVERAGE';
            }
            renderResults(results, coursePayload);
            if (courseOverallEl) {
                var percent = coursePayload.overall && coursePayload.overall.percent;
                courseOverallEl.textContent = (percent === null || typeof percent === 'undefined') ? '' :
                    ((strings.radarQuizModulesAvg || 'Average') + ': ' + Math.round(percent) + '%');
            }
        }

        function renderLoadedPayload(userId, payload, requestUrl) {
            payload.strings = Object.assign({}, config.strings || {}, payload.strings || {});
            lastPayload = payload;
            var coursePayload = payload.course_skills_radar;
            var strings = payload.strings || {};

            applyPrimaryColor(panel, resolvePrimaryColor(coursePayload, config.primaryColor));
            renderCourseView(coursePayload, strings);
            renderChartDebugPanels(config, userId, requestUrl || '', payload);

            if (config.debugSkillRadar) {
                renderTextDebug(textdebug, coursePayload || {});
                renderJsonDebug(jsondebug, {
                    userId: userId,
                    requestUrl: requestUrl,
                    payload: payload
                });
            }
        }

        function handleLoadError(userId) {
            lastPayload = null;
            clearChartWhenNoUser();
            clearChartDebugPanels(config);
            if (results) {
                results.innerHTML = '<p class="local-skillradar-results-empty">' +
                    ((config.strings && config.strings.fetchError) || 'Could not load Skill Radar.') + '</p>';
            }
            if (config.debugSkillRadar) {
                renderTextDebug(textdebug, {'skills_detail': []});
                renderJsonDebug(jsondebug, {
                    userId: userId,
                    error: 'fetch_failed'
                });
            }
        }

        function load(userId) {
            if (!userId) {
                renderNoUserState();
                return;
            }

            fetchPayload(config.apiUrl, config.courseId, userId, config.sesskey, true)
                .then(function(result) {
                    var payload = result.payload;
                    if (payload && payload.error) {
                        throw new Error(payload.error);
                    }
                    renderLoadedPayload(userId, payload, result.requestUrl);
                    return payload;
                })
                .catch(function() {
                    handleLoadError(userId);
                });
        }

        var initialUserId = 0;
        if (config.userId > 0) {
            initialUserId = config.userId;
        } else if (config.reportType === 'grader') {
            initialUserId = findFirstGraderUserId();
        }
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
