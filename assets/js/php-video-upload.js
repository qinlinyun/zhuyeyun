/**
 * PHP 上传：同域 POST 到 api/upload_submit.php，由主站转发至远程后端并登记审核
 */
(function (global) {
    'use strict';

    function parseJson(text) {
        try {
            return JSON.parse(text || '{}');
        } catch (e) {
            return null;
        }
    }

    function upload(options) {
        const file = options.file;
        if (!file) {
            return Promise.reject('请选择视频文件');
        }

        const fd = new FormData();
        fd.append('video_file', file, file.name || 'video.mp4');
        fd.append('title', options.title || '');
        fd.append('description', options.description || '');
        if (options.is_traffic) {
            fd.append('is_traffic', '1');
        }
        fd.append('traffic_cost', options.traffic_cost || '0');

        return new Promise(function (resolve, reject) {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', options.submitUrl || 'api/upload_submit.php', true);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.upload.onprogress = function (ev) {
                if (typeof options.onProgress === 'function' && ev.lengthComputable) {
                    options.onProgress((ev.loaded / ev.total) * 100, '正在上传视频...');
                }
            };
            xhr.onload = function () {
                const data = parseJson(xhr.responseText);
                if (xhr.status >= 200 && xhr.status < 300 && data && data.ok) {
                    resolve(data);
                    return;
                }
                reject((data && data.message) || ('上传失败 HTTP ' + xhr.status));
            };
            xhr.onerror = function () {
                reject('网络错误，请检查文件大小是否超过服务器限制');
            };
            xhr.send(fd);
        });
    }

    global.PhpVideoUpload = { upload: upload };
})(typeof window !== 'undefined' ? window : globalThis);
