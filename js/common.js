// This file is part of Moodle - http://moodle.org/
// Shared helpers for script.js and manage.js (load this file first).

(function(global) {
    'use strict';

    /**
     * Radial axis: min < 0 so 0% maps to an inner ring, not the geometric center.
     */
    var RADAR_R_MIN = -20;
    var RADAR_R_MAX = 100;

    function escapeHtml(s) {
        if (s === null || typeof s === 'undefined') {
            return '';
        }
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function safeHexColor(s) {
        return /^#[0-9A-Fa-f]{6}$/.test(String(s || '')) ? String(s) : '#64748B';
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

    function readConfig(root) {
        if (!root) {
            return null;
        }
        var raw = root.getAttribute('data-config') || '{}';
        if (root._srCfgRaw !== raw) {
            root._srCfgRaw = raw;
            try {
                root._srCfgParsed = JSON.parse(raw);
            } catch (e) {
                root._srCfgParsed = null;
            }
        }
        if (root._srCfgParsed === null) {
            return null;
        }
        return Object.assign({}, root._srCfgParsed);
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

    /**
     * API course_average.values align to skills_detail only; chart.labels can be longer (min-axes placeholders).
     * Chart.js requires every dataset data[] length to match labels[].
     *
     * @param {{chart?: object, course_average?: object}} payload
     * @returns {Array<number|null>}
     */
    function alignCourseAverageValuesToChart(payload) {
        var chart = payload.chart || {};
        var labels = chart.labels || [];
        var placeholder = chart.placeholder || [];
        var avgVals = (payload.course_average && payload.course_average.values) ? payload.course_average.values : [];
        var data = [];
        var vidx = 0;
        var i;
        for (i = 0; i < labels.length; i++) {
            if (placeholder[i]) {
                data.push(null);
            } else {
                var raw = vidx < avgVals.length ? avgVals[vidx] : null;
                vidx++;
                data.push(raw === null || typeof raw === 'undefined' ? 0 : raw);
            }
        }
        return data;
    }

    global.localSkillRadarCommon = {
        RADAR_R_MIN: RADAR_R_MIN,
        RADAR_R_MAX: RADAR_R_MAX,
        escapeHtml: escapeHtml,
        safeHexColor: safeHexColor,
        getRank: getRank,
        readConfig: readConfig,
        hexToRgb: hexToRgb,
        hexToRgba: hexToRgba,
        applyPrimaryColor: applyPrimaryColor,
        resolvePrimaryColor: resolvePrimaryColor,
        alignCourseAverageValuesToChart: alignCourseAverageValuesToChart
    };
})(typeof window !== 'undefined' ? window : this);
