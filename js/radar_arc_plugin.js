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
     * @param {Array<{x: number, y: number}>} pts
     * @param {number} i
     * @param {number} cx0
     * @param {number} cy0
     * @param {number} maxDist
     * @param {number} headroomK
     * @param {number} chordK
     * @returns {{nextPoint: {x: number, y: number}, control1: {x: number, y: number}, control2: {x: number, y: number}}}
     */
    function buildBulgedSegment(pts, i, cx0, cy0, maxDist, headroomK, chordK) {
        var nextIndex = (i + 1) % pts.length;
        var start = pts[i];
        var end = pts[nextIndex];
        var startAngle = Math.atan2(start.y - cy0, start.x - cx0);
        var endAngle = Math.atan2(end.y - cy0, end.x - cx0);
        var startRadius = Math.hypot(start.x - cx0, start.y - cy0);
        var endRadius = Math.hypot(end.x - cx0, end.y - cy0);
        var deltaAngle = shortestAngleDelta(startAngle, endAngle);
        var deltaRadius = endRadius - startRadius;
        var chord = Math.hypot(end.x - start.x, end.y - start.y);
        var rawControl1 = {
            x: cx0 + (startRadius + deltaRadius / 3) * Math.cos(startAngle + deltaAngle / 3),
            y: cy0 + (startRadius + deltaRadius / 3) * Math.sin(startAngle + deltaAngle / 3)
        };
        var rawControl2 = {
            x: cx0 + (startRadius + (2 * deltaRadius) / 3) * Math.cos(startAngle + (2 * deltaAngle) / 3),
            y: cy0 + (startRadius + (2 * deltaRadius) / 3) * Math.sin(startAngle + (2 * deltaAngle) / 3)
        };

        return {
            nextPoint: end,
            control1: bulgeControl(cx0, cy0, rawControl1.x, rawControl1.y, maxDist, chord, 1 / 3, headroomK, chordK),
            control2: bulgeControl(cx0, cy0, rawControl2.x, rawControl2.y, maxDist, chord, 2 / 3, headroomK, chordK)
        };
    }

    /**
     * @param {CanvasRenderingContext2D} ctx
     * @param {Array<{x: number, y: number}>} pts
     * @param {number} cx0
     * @param {number} cy0
     * @param {number} maxDist
     * @param {number} headroomK
     * @param {number} chordK
     */
    function drawBulgedBezierPath(ctx, pts, cx0, cy0, maxDist, headroomK, chordK) {
        var i;
        ctx.moveTo(pts[0].x, pts[0].y);
        for (i = 0; i < pts.length; i++) {
            var segment = buildBulgedSegment(pts, i, cx0, cy0, maxDist, headroomK, chordK);
            ctx.bezierCurveTo(
                segment.control1.x,
                segment.control1.y,
                segment.control2.x,
                segment.control2.y,
                segment.nextPoint.x,
                segment.nextPoint.y
            );
        }
    }

    /**
     * @param {CanvasRenderingContext2D} ctx
     * @param {Array<{x: number, y: number}>} pts
     * @param {number} cx0
     * @param {number} cy0
     * @param {number} maxDist
     * @param {number} headroomK
     * @param {number} chordK
     * @param {number} steps
     * @param {number} phase
     * @param {number} ampPx
     */
    function drawWavyBezierPath(ctx, pts, cx0, cy0, maxDist, headroomK, chordK, steps, phase, ampPx) {
        var interiorPerRing = pts.length * (steps - 1);
        var globalIndex = 0;
        var i;
        ctx.moveTo(pts[0].x, pts[0].y);
        for (i = 0; i < pts.length; i++) {
            var segment = buildBulgedSegment(pts, i, cx0, cy0, maxDist, headroomK, chordK);
            var stepIndex;
            for (stepIndex = 1; stepIndex < steps; stepIndex++) {
                var t = stepIndex / steps;
                var point = cubicBezierPoint(pts[i], segment.control1, segment.control2, segment.nextPoint, t);
                var globalT = interiorPerRing > 0 ? globalIndex / interiorPerRing : 0;
                var noise = waveNoiseRadial(globalT, phase, ampPx);
                var wobble = applyRadialDelta(cx0, cy0, point.x, point.y, noise, maxDist);
                globalIndex += 1;
                ctx.lineTo(wobble.x, wobble.y);
            }
            ctx.lineTo(segment.nextPoint.x, segment.nextPoint.y);
        }
    }

    /**
     * @param {CanvasRenderingContext2D} ctx
     * @param {object} ds
     * @param {boolean} useWave
     * @param {string} border
     * @param {number} strokeW
     */
    function strokeRadarPath(ctx, ds, useWave, border, strokeW) {
        ctx.lineJoin = 'round';
        ctx.lineCap = 'round';
        if (ds.borderDash && ds.borderDash.length) {
            ctx.setLineDash(ds.borderDash);
        } else {
            ctx.setLineDash([]);
        }

        if (useWave && ds.radarWaveGlow !== false) {
            var blur = typeof ds.radarWaveShadowBlur === 'number' ? ds.radarWaveShadowBlur : 12;
            var shadowColor = typeof ds.radarWaveShadowColor === 'string' ? ds.radarWaveShadowColor : border;
            ctx.strokeStyle = border;
            ctx.lineWidth = strokeW + 2;
            ctx.shadowBlur = blur;
            ctx.shadowColor = shadowColor;
            ctx.globalAlpha = typeof ds.radarWaveGlowAlpha === 'number' ? ds.radarWaveGlowAlpha : 0.85;
            ctx.stroke();
            ctx.shadowBlur = 0;
            ctx.globalAlpha = 1;
        }

        ctx.strokeStyle = border;
        ctx.lineWidth = strokeW;
        ctx.stroke();
    }

    /**
     * @param {object} chart Chart.js instance
     * @param {number} datasetIndex
     */
    function drawRadarArcDataset(chart, datasetIndex) {
        var meta = chart.getDatasetMeta(datasetIndex);
        var ds = chart.data.datasets[datasetIndex];
        var scale = chart.scales.r;
        if (meta.hidden || !meta.data || !scale) {
            return;
        }

        var ctx = chart.ctx;
        var cx0 = scale.xCenter;
        var cy0 = scale.yCenter;
        var n = meta.data.length;
        var pts = [];
        var i;
        for (i = 0; i < n; i++) {
            var raw = ds.data[i];
            if (raw === null || typeof raw === 'undefined' || (typeof raw === 'number' && isNaN(raw))) {
                continue;
            }
            var el = meta.data[i];
            if (!el || el.skip) {
                continue;
            }
            if (!isFinite(el.x) || !isFinite(el.y)) {
                continue;
            }
            pts.push({x: el.x, y: el.y});
        }

        if (pts.length === 0) {
            return;
        }

        if (pts.length === 1) {
            var pr = typeof ds.pointRadius === 'number' ? ds.pointRadius : 6;
            var r = Math.max(2, Math.min(pr, 10));
            ctx.save();
            ctx.beginPath();
            ctx.arc(pts[0].x, pts[0].y, r, 0, TWO_PI);
            if (ds.backgroundColor) {
                ctx.fillStyle = ds.backgroundColor;
                ctx.fill();
            }
            ctx.strokeStyle = ds.borderColor || '#3B82F6';
            ctx.lineWidth = typeof ds.radarArcStrokeWidth === 'number' ? ds.radarArcStrokeWidth : 2.4;
            ctx.stroke();
            ctx.restore();
            return;
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
            drawBulgedBezierPath(ctx, pts, cx0, cy0, maxDist, headroomK, chordK);
        } else {
            drawWavyBezierPath(ctx, pts, cx0, cy0, maxDist, headroomK, chordK, steps, phase, ampPx);
        }

        ctx.closePath();

        if (ds.backgroundColor) {
            ctx.fillStyle = ds.backgroundColor;
            ctx.fill();
        }

        strokeRadarPath(
            ctx,
            ds,
            useWave,
            ds.borderColor || '#3B82F6',
            typeof ds.radarArcStrokeWidth === 'number' ? ds.radarArcStrokeWidth : 2.4
        );
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
