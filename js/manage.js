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
            var nameEl = row && row.querySelector('[data-skill-name]');
            var colorEl = row && row.querySelector('[data-skill-color]');
            return {
                key: node.getAttribute('data-skill-key') || '',
                label: nameEl ? nameEl.getAttribute('data-skill-name') : node.textContent.trim(),
                color: colorEl ? colorEl.getAttribute('data-skill-color') : '#64748B'
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

        var detail = ensureMinSkills(defs.map(function(def) {
            return {
                key: def.key,
                label: def.label,
                color: def.color,
                value: 0,
                items: counts[def.key] || 0,
                empty: true,
                placeholder: false
            };
        }), strings);

        return {
            'skills_detail': detail,
            chart: {
                labels: detail.map(function(row) {
                    return row.label;
                }),
                values: detail.map(function(row) {
                    return row.value;
                }),
                colors: detail.map(function(row) {
                    return row.empty ? '#94A3B8' : row.color;
                }),
                placeholder: detail.map(function(row) {
                    return row.placeholder;
                }),
                keys: detail.map(function(row) {
                    return row.key;
                })
            },
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
                (row.value === null ? '0%' : row.value.toFixed(2) + '%') +
                '<span class="local-skillradar-result-meta">' + row.items + ' ' +
                (((payload.strings && payload.strings.mappedItems) || 'mapped')) +
                '</span></span>' +
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

    function buildDatasets(payload, ctx) {
        var canvas = ctx.canvas;
        var gradient = ctx.createLinearGradient(0, 0, canvas.width || 600, canvas.height || 600);
        gradient.addColorStop(0, 'rgba(54, 162, 235, 0.24)');
        gradient.addColorStop(1, 'rgba(54, 162, 235, 0.08)');

        var hasUserValues = (payload.chart && payload.chart.values ? payload.chart.values : []).some(function(value) {
            return value !== null;
        });

        return [{
            label: 'Skill level (%)',
            data: (payload.chart && payload.chart.values ? payload.chart.values : []).map(function(value) {
                return value === null ? 0 : value;
            }),
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
                labels: payload.chart.labels || [],
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
        var canvas = document.getElementById('local-skillradar-manage-canvas');
        var centerButton = document.getElementById('local-skillradar-manage-center-score');
        var centerValue = document.getElementById('local-skillradar-manage-score-value');
        var centerLabel = document.getElementById('local-skillradar-manage-score-label');
        var strings = Object.assign({}, config.strings || {});

        if (centerButton && !centerButton.getAttribute('data-bound')) {
            centerButton.setAttribute('data-bound', '1');
            centerButton.addEventListener('click', function() {
                showPercentage = !showPercentage;
                if (jsondebug && jsondebug.textContent) {
                    try {
                        var current = JSON.parse(jsondebug.textContent);
                        var nextPayload = current.payload || current.fallback;
                        if (nextPayload) {
                            updateCenterScore(nextPayload, centerValue, centerLabel, centerButton);
                        }
                    } catch (e) {
                        // Ignore JSON parsing issues for the toggle.
                    }
                }
            });
        }

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
                renderChart(canvas, output, {
                    button: centerButton,
                    value: centerValue,
                    label: centerLabel
                });
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
                renderChart(canvas, fallback, {
                    button: centerButton,
                    value: centerValue,
                    label: centerLabel
                });
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
