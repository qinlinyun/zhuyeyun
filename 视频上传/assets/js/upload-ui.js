/**
 * 上传页通用 UI：进度条、消息框
 */
(function (global) {
    'use strict';

    function $(id) {
        return document.getElementById(id);
    }

    function showMessage(el, text) {
        if (!el) {
            return;
        }
        el.textContent = text || '';
        el.classList.toggle('hidden', !text);
    }

    function setProgress(prefix, percent, text) {
        const value = Math.max(0, Math.min(100, Math.round(percent)));
        const wrap = $(prefix + 'ProgressWrap');
        const bar = $(prefix + 'ProgressBar');
        const pct = $(prefix + 'ProgressPercent');
        const txt = $(prefix + 'ProgressText');
        if (wrap) {
            wrap.classList.remove('hidden');
        }
        if (bar) {
            bar.style.width = value + '%';
        }
        if (pct) {
            pct.textContent = value + '%';
        }
        if (txt) {
            txt.textContent = text || '正在上传...';
        }
        return value;
    }

    global.BackendUploadUI = {
        show: showMessage,
        progress: setProgress,
    };
})(typeof window !== 'undefined' ? window : this);
