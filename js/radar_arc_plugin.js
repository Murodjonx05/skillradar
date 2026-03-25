// This file is part of Moodle - http://moodle.org/
// Radar: cubic Bezier + outward bulge; optional wave/noise along the path + soft glow on stroke.

(function(global) {
    'use strict';

    var TWO_PI = Math.PI * 2;

    /** Fraction of remaining radius to outer ring used to push controls outward (0–1). */
    var DEFAULT_BULGE_HEADROOM = 0.58;
    /** Extra bulge as a fraction of chord length (visible bow even near 100%). */
    var DEFAULT_BULGE_CHORD = 0.16;
    /** Interior samples per edge for wave path (≥2). */
    var DEFAULT_WAVE_STEPS = 14;
    /** Radial noise amplitude as a fraction of max radius (subtle organic wobble). */
    var DEFAULT_WAVE_AMP = 0.032;

    /**
     * Shortest signed angle delta from a1 to a2 (radians).
     * @param {number} a1
     * @param {number} a2
     * @returns {number}
     */
    function shortestAngleDelta(a1, a2) {
        var d = a2 - a1;
        if (d > Math.PI) {
            d -= 2 * Math.PI;
        }
        if (d < -Math.PI) {
            d += 2 * Math.PI;
        }
        return d;
    }

    /**
     * @param {{x: number, y: number}} p0
     * @param {{x: number, y: number}} p1
     * @param {{x: number, y: number}} p2
     * @param {{x: number, y: number}} p3
     * @param {number} t 0..1
     * @returns {{x: number, y: number}}
     */
    function cubicBezierPoint(p0, p1, p2, p3, t) {
        var u = 1 - t;
        var uu = u * u;
        var tt = t * t;
        var uuu = uu * u;
        var ttt = tt * t;
        return {
            x: uuu * p0.x + 3 * uu * t * p1.x + 3 * u * tt * p2.x + ttt * p3.x,
            y: uuu * p0.y + 3 * uu * t * p1.y + 3 * u * tt * p2.y + ttt * p3.y
        };
    }

    /**
     * @param {object} scale Chart.js radial scale
     * @param {Array<{x: number, y: number}>} pts
     * @param {number} cx0
     * @param {number} cy0
     * @returns {number}
     */
    function getMaxRadiusPx(scale, pts, cx0, cy0) {
        if (scale && typeof scale.getDistanceFromCenterForValue === 'function') {
            try {
                var m = scale.getDistanceFromCenterForValue(scale.max);
                if (m > 0) {
                    return m;
                }
            } catch (e1) {
                // Fall through.
            }
        }
        var maxR = 0;
        var k;
        for (k = 0; k < pts.length; k++) {
            var r = Math.hypot(pts[k].x - cx0, pts[k].y - cy0);
            if (r > maxR) {
                maxR = r;
            }
        }
        return maxR > 0 ? maxR * 1.08 : 0;
    }

    /**
     * @param {number} cx0
     * @param {number} cy0
     * @param {number} x
     * @param {number} y
     * @param {number} maxDist
     * @param {number} chord
     * @param {number} u parameter 1/3 or 2/3 along the edge
     * @param {number} headroomK
     * @param {number} chordK
     * @returns {{x: number, y: number}}
     */
    function bulgeControl(cx0, cy0, x, y, maxDist, chord, u, headroomK, chordK) {
        var dx = x - cx0;
        var dy = y - cy0;
        var len = Math.hypot(dx, dy);
        if (len < 1e-6) {
            return {x: x, y: y};
        }
        var headroom = maxDist > 0 ? Math.max(0, maxDist - len) : 0;
        var w = Math.sin(Math.PI * u);
        var extra = headroom * headroomK * w + chord * chordK * w;
        var target = Math.min(len + extra, maxDist > 0 ? maxDist : len + extra);
        var f = target / len;
        return {x: cx0 + dx * f, y: cy0 + dy * f};
    }

    /**
     * Stable multi-frequency radial wobble (0..1 along closed perimeter).
     * @param {number} globalT
     * @param {number} phase
     * @param {number} ampPx
     * @returns {number}
     */
    function waveNoiseRadial(globalT, phase, ampPx) {
        return ampPx * (
            0.52 * Math.sin(TWO_PI * 3.7 * globalT + phase) +
            0.32 * Math.sin(TWO_PI * 10.2 * globalT + phase * 1.37) +
            0.18 * Math.sin(TWO_PI * 21.5 * globalT + phase * 2.11)
        );
    }

    /**
     * @param {number} cx0
     * @param {number} cy0
     * @param {number} x
     * @param {number} y
     * @param {number} deltaR
     * @param {number} maxDist
     * @returns {{x: number, y: number}}
     */
    function applyRadialDelta(cx0, cy0, x, y, deltaR, maxDist) {
        var dx = x - cx0;
        var dy = y - cy0;
        var len = Math.hypot(dx, dy);
        if (len < 1e-6) {
            return {x: x, y: y};
        }
        var next = Math.min(Math.max(0, len + deltaR), maxDist > 0 ? maxDist : len + deltaR);
        var f = next / len;
        return {x: cx0 + dx * f, y: cy0 + dy * f};
    }

    /**
     * @param {object} chart Chart.js instance
     * @param {number} datasetIndex
     */
    function drawRadarArcDataset(chart, datasetIndex) {
        var meta = chart.getDatasetMeta(datasetIndex);
        var ds = chart.data.datasets[datasetIndex];
        var scale = chart.scales.r;
        if (meta.hidden || !meta.data || meta.data.length < 2 || !scale) {
            return;
        }

        var ctx = chart.ctx;
        var cx0 = scale.xCenter;
        var cy0 = scale.yCenter;
        var n = meta.data.length;
        var pts = [];
        var i;
        for (i = 0; i < n; i++) {
            var el = meta.data[i];
            pts.push({x: el.x, y: el.y});
        }

        var maxDist = getMaxRadiusPx(scale, pts, cx0, cy0);
        var bulgeMul = typeof ds.radarArcBulge === 'number' ? ds.radarArcBulge : 1;
        var headroomK = DEFAULT_BULGE_HEADROOM * bulgeMul;
        var chordK = DEFAULT_BULGE_CHORD * bulgeMul;
        var useWave = ds.radarWaveNoise !== false;
        var steps = typeof ds.radarWaveSteps === 'number' ? Math.max(2, Math.floor(ds.radarWaveSteps)) : DEFAULT_WAVE_STEPS;
        var ampRatio = typeof ds.radarWaveAmp === 'number' ? ds.radarWaveAmp : DEFAULT_WAVE_AMP;
        var ampPx = maxDist * ampRatio;
        var phase = datasetIndex * 1.73 + 0.41;

        ctx.save();
        ctx.beginPath();

        if (!useWave) {
            ctx.moveTo(pts[0].x, pts[0].y);
            for (i = 0; i < n; i++) {
                var ja = (i + 1) % n;
                var p1a = pts[i];
                var p2a = pts[ja];
                var a1 = Math.atan2(p1a.y - cy0, p1a.x - cx0);
                var a2 = Math.atan2(p2a.y - cy0, p2a.x - cx0);
                var r1a = Math.hypot(p1a.x - cx0, p1a.y - cy0);
                var r2a = Math.hypot(p2a.x - cx0, p2a.y - cy0);
                var dAngA = shortestAngleDelta(a1, a2);
                var drA = r2a - r1a;
                var chordA = Math.hypot(p2a.x - p1a.x, p2a.y - p1a.y);
                var c1xa = cx0 + (r1a + drA / 3) * Math.cos(a1 + dAngA / 3);
                var c1ya = cy0 + (r1a + drA / 3) * Math.sin(a1 + dAngA / 3);
                var c2xa = cx0 + (r1a + (2 * drA) / 3) * Math.cos(a1 + (2 * dAngA) / 3);
                var c2ya = cy0 + (r1a + (2 * drA) / 3) * Math.sin(a1 + (2 * dAngA) / 3);
                var b1a = bulgeControl(cx0, cy0, c1xa, c1ya, maxDist, chordA, 1 / 3, headroomK, chordK);
                var b2a = bulgeControl(cx0, cy0, c2xa, c2ya, maxDist, chordA, 2 / 3, headroomK, chordK);
                ctx.bezierCurveTo(b1a.x, b1a.y, b2a.x, b2a.y, p2a.x, p2a.y);
            }
        } else {
            var interiorPerRing = n * (steps - 1);
            var g = 0;
            ctx.moveTo(pts[0].x, pts[0].y);
            for (i = 0; i < n; i++) {
                var jb = (i + 1) % n;
                var p0b = pts[i];
                var p3b = pts[jb];
                var b1b = Math.atan2(p0b.y - cy0, p0b.x - cx0);
                var b2b = Math.atan2(p3b.y - cy0, p3b.x - cx0);
                var r1b = Math.hypot(p0b.x - cx0, p0b.y - cy0);
                var r2b = Math.hypot(p3b.x - cx0, p3b.y - cy0);
                var dAngB = shortestAngleDelta(b1b, b2b);
                var drB = r2b - r1b;
                var chordB = Math.hypot(p3b.x - p0b.x, p3b.y - p0b.y);
                var c1xb = cx0 + (r1b + drB / 3) * Math.cos(b1b + dAngB / 3);
                var c1yb = cy0 + (r1b + drB / 3) * Math.sin(b1b + dAngB / 3);
                var c2xb = cx0 + (r1b + (2 * drB) / 3) * Math.cos(b1b + (2 * dAngB) / 3);
                var c2yb = cy0 + (r1b + (2 * drB) / 3) * Math.sin(b1b + (2 * dAngB) / 3);
                var b1c = bulgeControl(cx0, cy0, c1xb, c1yb, maxDist, chordB, 1 / 3, headroomK, chordK);
                var b2c = bulgeControl(cx0, cy0, c2xb, c2yb, maxDist, chordB, 2 / 3, headroomK, chordK);
                var k;
                for (k = 1; k < steps; k++) {
                    var tb = k / steps;
                    var ptb = cubicBezierPoint(p0b, b1c, b2c, p3b, tb);
                    var globalT = interiorPerRing > 0 ? g / interiorPerRing : 0;
                    g += 1;
                    var dNoise = waveNoiseRadial(globalT, phase, ampPx);
                    var wob = applyRadialDelta(cx0, cy0, ptb.x, ptb.y, dNoise, maxDist);
                    ctx.lineTo(wob.x, wob.y);
                }
                ctx.lineTo(p3b.x, p3b.y);
            }
        }

        ctx.closePath();

        if (ds.backgroundColor) {
            ctx.fillStyle = ds.backgroundColor;
            ctx.fill();
        }

        var strokeW = typeof ds.radarArcStrokeWidth === 'number' ? ds.radarArcStrokeWidth : 2.4;
        var border = ds.borderColor || '#3B82F6';
        ctx.lineJoin = 'round';
        ctx.lineCap = 'round';
        if (ds.borderDash && ds.borderDash.length) {
            ctx.setLineDash(ds.borderDash);
        } else {
            ctx.setLineDash([]);
        }

        if (useWave && ds.radarWaveGlow !== false) {
            var blur = typeof ds.radarWaveShadowBlur === 'number' ? ds.radarWaveShadowBlur : 12;
            var sh = typeof ds.radarWaveShadowColor === 'string' ? ds.radarWaveShadowColor : border;
            ctx.strokeStyle = border;
            ctx.lineWidth = strokeW + 2;
            ctx.shadowBlur = blur;
            ctx.shadowColor = sh;
            ctx.globalAlpha = typeof ds.radarWaveGlowAlpha === 'number' ? ds.radarWaveGlowAlpha : 0.85;
            ctx.stroke();
            ctx.shadowBlur = 0;
            ctx.globalAlpha = 1;
        }

        ctx.strokeStyle = border;
        ctx.lineWidth = strokeW;
        ctx.stroke();
        ctx.restore();
    }

    function createRadarArcPlugin() {
        return {
            id: 'localSkillRadarArc',
            beforeDatasetDraw: function(chart, args) {
                var idx = typeof args.index === 'number' ? args.index : (args.meta && args.meta.index);
                if (typeof idx !== 'number' || idx < 0) {
                    return;
                }
                var ds = chart.data.datasets[idx];
                if (!ds || !ds.radarArcSegments) {
                    return;
                }
                drawRadarArcDataset(chart, idx);
            }
        };
    }

    global.localSkillRadarCreateArcPlugin = createRadarArcPlugin;
}(window));
