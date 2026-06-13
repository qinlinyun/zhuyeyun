/**
 * 远程上传后端分片客户端：>25MB 自动分片，每片 25MB
 */
(function (global) {
    'use strict';

    const CHUNK_SIZE = 25 * 1024 * 1024;
    const CHUNK_THRESHOLD = 25 * 1024 * 1024;
    const MAX_RETRIES = 3;

    function parseJson(text) {
        try {
            return JSON.parse(text || '{}');
        } catch (e) {
            return null;
        }
    }

    function xhrPost(url, formData, onProgress) {
        return new Promise(function (resolve, reject) {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', url, true);
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            if (onProgress && xhr.upload) {
                xhr.upload.onprogress = function (ev) {
                    if (ev.lengthComputable) {
                        onProgress(ev.loaded, ev.total);
                    }
                };
            }
            xhr.onload = function () {
                const data = parseJson(xhr.responseText);
                if (xhr.status >= 200 && xhr.status < 300 && data && data.ok) {
                    resolve(data);
                    return;
                }
                reject((data && (data.error || data.message)) || ('请求失败 HTTP ' + xhr.status));
            };
            xhr.onerror = function () {
                reject('网络错误');
            };
            xhr.send(formData);
        });
    }

    function xhrPostJson(url, body, headers) {
        return new Promise(function (resolve, reject) {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', url, true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            const extra = headers || {};
            Object.keys(extra).forEach(function (key) {
                xhr.setRequestHeader(key, extra[key]);
            });
            xhr.onload = function () {
                const data = parseJson(xhr.responseText);
                if (xhr.status >= 200 && xhr.status < 300 && data && data.ok) {
                    resolve(data);
                    return;
                }
                reject((data && (data.error || data.message)) || ('请求失败 HTTP ' + xhr.status));
            };
            xhr.onerror = function () {
                reject('网络错误');
            };
            xhr.send(JSON.stringify(body));
        });
    }

    function sliceFile(file, chunkSize) {
        const chunks = [];
        let offset = 0;
        let index = 0;
        while (offset < file.size) {
            const end = Math.min(offset + chunkSize, file.size);
            chunks.push({ index: index, blob: file.slice(offset, end) });
            offset = end;
            index += 1;
        }
        return chunks;
    }

    function uploadChunk(endpoint, token, sessionId, chunkIndex, blob) {
        const fd = new FormData();
        fd.append('upload_token', token);
        fd.append('session_id', sessionId);
        fd.append('chunk_index', String(chunkIndex));
        fd.append('chunk', blob, 'chunk-' + chunkIndex + '.part');

        let attempt = 0;
        function run() {
            return xhrPost(endpoint, fd).catch(function (err) {
                attempt += 1;
                if (attempt < MAX_RETRIES) {
                    return new Promise(function (r) {
                        setTimeout(r, 800 * attempt);
                    }).then(run);
                }
                throw err;
            });
        }
        return run();
    }

    function shouldUseChunk(file) {
        return !!(file && file.size > CHUNK_THRESHOLD);
    }

    /**
     * @param {object} options
     * @param {File} options.file
     * @param {string} options.uploadToken
     * @param {string} options.storedFilename
     * @param {string} options.initUrl
     * @param {string} options.chunkUrl
     * @param {string} options.finishUrl
     * @param {function(number,string)=} options.onProgress
     */
    function upload(options) {
        const file = options.file;
        const token = options.uploadToken || '';
        const storedFilename = options.storedFilename || '';
        const onProgress = options.onProgress || function () {};

        if (!file || file.size <= 0) {
            return Promise.reject('请选择有效的 mp4 文件');
        }
        if (!/\.mp4$/i.test(file.name)) {
            return Promise.reject('当前仅支持 mp4 格式');
        }
        if (!shouldUseChunk(file)) {
            return Promise.reject('文件未超过 25MB，无需分片');
        }

        onProgress(0, '正在初始化分片上传...');

        return xhrPostJson(options.initUrl, {
            upload_token: token,
            file_name: file.name,
            file_size: file.size,
            chunk_size: CHUNK_SIZE,
            target_relative: storedFilename,
            stored_filename: storedFilename,
        }, {
            'X-Upload-Token': token,
        }).then(function (initData) {
            const sessionId = initData.session_id;
            const chunkSize = initData.chunk_size || CHUNK_SIZE;
            const chunkUrl = initData.chunk_url || options.chunkUrl;
            const finishUrl = initData.finish_url || options.finishUrl;
            const chunks = sliceFile(file, chunkSize);
            let uploadedBytes = 0;

            let chain = Promise.resolve();
            chunks.forEach(function (chunk) {
                chain = chain.then(function () {
                    return uploadChunk(chunkUrl, token, sessionId, chunk.index, chunk.blob).then(function () {
                        uploadedBytes += chunk.blob.size;
                        const pct = Math.min(98, Math.round((uploadedBytes / file.size) * 100));
                        onProgress(
                            pct,
                            '分片上传 ' + (chunk.index + 1) + '/' + chunks.length + '（每片 ' + Math.round(chunkSize / 1024 / 1024) + 'MB）'
                        );
                    });
                });
            });

            return chain.then(function () {
                onProgress(99, '正在合并视频文件...');
                return xhrPostJson(finishUrl, {
                    upload_token: token,
                    session_id: sessionId,
                }, {
                    'X-Upload-Token': token,
                    'X-Session-Id': sessionId,
                });
            }).then(function (finishData) {
                onProgress(100, '上传完成');
                return {
                    ok: true,
                    stored_filename: finishData.stored_filename || storedFilename,
                    backend_file_id: finishData.backend_file_id || finishData.stored_filename || storedFilename,
                    original_filename: finishData.original_filename || file.name,
                    size_bytes: finishData.size_bytes || file.size,
                    chunked: true,
                };
            });
        });
    }

    global.BackendChunkUploadClient = {
        CHUNK_SIZE: CHUNK_SIZE,
        CHUNK_THRESHOLD: CHUNK_THRESHOLD,
        shouldUseChunk: shouldUseChunk,
        upload: upload,
    };
})(typeof window !== 'undefined' ? window : globalThis);
