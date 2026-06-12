/**
 * 分片上传客户端：init → chunk × N → finish → complete（远程模式）
 */
(function (global) {
    'use strict';

    const DEFAULT_CHUNK = 25 * 1024 * 1024;
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
                reject((data && (data.message || data.error)) || ('请求失败 HTTP ' + xhr.status));
            };
            xhr.onerror = function () {
                reject('网络错误');
            };
            xhr.send(formData);
        });
    }

    function xhrPostJson(url, body) {
        return new Promise(function (resolve, reject) {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', url, true);
            xhr.setRequestHeader('Content-Type', 'application/json');
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.onload = function () {
                const data = parseJson(xhr.responseText);
                if (xhr.status >= 200 && xhr.status < 300 && data && data.ok) {
                    resolve(data);
                    return;
                }
                reject((data && (data.message || data.error)) || ('请求失败 HTTP ' + xhr.status));
            };
            xhr.onerror = function () {
                reject('网络错误');
            };
            xhr.send(JSON.stringify(body));
        });
    }

    function tryEndpoints(endpoints, runner) {
        endpoints = (endpoints || []).filter(Boolean);
        if (!endpoints.length) {
            return Promise.reject('未配置上传端点');
        }
        let lastErr = '';
        function next(i) {
            if (i >= endpoints.length) {
                return Promise.reject(lastErr || '所有上传端点均失败');
            }
            return runner(endpoints[i]).catch(function (err) {
                lastErr = String(err);
                return next(i + 1);
            });
        }
        return next(0);
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

    function VideoUploader(config) {
        this.config = Object.assign({
            initUrl: 'api/upload/init.php',
            prepareUrl: 'api/upload_prepare.php',
            completeUrl: 'api/upload_complete.php',
            localFinishUrl: 'api/upload/finish.php',
            chunkSize: DEFAULT_CHUNK,
        }, config || {});
    }

    VideoUploader.prototype.upload = function (file, meta, callbacks) {
        const self = this;
        const onProgress = (callbacks && callbacks.onProgress) || function () {};
        const metaFields = meta || {};

        if (!file || file.size <= 0) {
            return Promise.reject('请选择有效的 mp4 文件');
        }
        if (!/\.mp4$/i.test(file.name)) {
            return Promise.reject('当前仅支持 mp4 格式');
        }

        const initUrl = self.config.initUrl || self.config.prepareUrl;
        const initBody = {
            file_name: file.name,
            file_size: file.size,
            chunk_size: self.config.chunkSize,
        };

        onProgress(0, '正在初始化上传会话...');

        return xhrPostJson(initUrl, initBody).then(function (initData) {
            const token = initData.upload_token;
            const sessionId = initData.session_id;
            const chunkSize = initData.chunk_size || self.config.chunkSize;
            const chunkEndpoints = initData.chunk_endpoints || initData.upload_endpoints || [];
            const finishEndpoints = initData.finish_endpoints || [];
            const isRemote = initData.mode === 'remote';
            const chunks = sliceFile(file, chunkSize);
            let uploadedBytes = 0;

            function uploadAllChunks() {
                let chain = Promise.resolve();
                chunks.forEach(function (chunk) {
                    chain = chain.then(function () {
                        return tryEndpoints(chunkEndpoints, function (endpoint) {
                            return uploadChunk(endpoint, token, sessionId, chunk.index, chunk.blob);
                        }).then(function () {
                            uploadedBytes += chunk.blob.size;
                            const pct = Math.min(99, Math.round((uploadedBytes / file.size) * 100));
                            onProgress(pct, '正在上传分片 ' + (chunk.index + 1) + '/' + chunks.length);
                        });
                    });
                });
                return chain;
            }

            return uploadAllChunks().then(function () {
                onProgress(99, '正在合并文件...');
                const finishPayload = {
                    upload_token: token,
                    session_id: sessionId,
                };

                if (isRemote) {
                    return tryEndpoints(finishEndpoints, function (endpoint) {
                        return xhrPostJson(endpoint, finishPayload);
                    });
                }

                return xhrPostJson(self.config.localFinishUrl, finishPayload);
            }).then(function (finishData) {
                if (!isRemote) {
                    return {
                        upload_token: token,
                        stored_filename: finishData.stored_filename,
                        backend_file_id: finishData.backend_file_id || finishData.stored_filename,
                        original_filename: finishData.original_filename || file.name,
                        size_bytes: finishData.size_bytes || file.size,
                    };
                }

                return {
                    upload_token: token,
                    stored_filename: finishData.stored_filename,
                    backend_file_id: finishData.backend_file_id || finishData.stored_filename,
                    original_filename: finishData.original_filename || file.name,
                    size_bytes: finishData.size_bytes || file.size,
                };
            }).then(function (remoteData) {
                if (!isRemote) {
                    const fd = new FormData();
                    fd.append('title', metaFields.title || '');
                    fd.append('description', metaFields.description || '');
                    fd.append('stored_filename', remoteData.stored_filename);
                    fd.append('backend_file_id', remoteData.backend_file_id);
                    fd.append('original_filename', remoteData.original_filename);
                    fd.append('size_bytes', String(remoteData.size_bytes));
                    if (metaFields.is_traffic) {
                        fd.append('is_traffic', '1');
                    }
                    if (metaFields.traffic_cost !== undefined) {
                        fd.append('traffic_cost', String(metaFields.traffic_cost));
                    }
                    fd.append('upload_token', remoteData.upload_token);

                    return xhrPost(self.config.completeUrl, fd).then(function (data) {
                        onProgress(100, '上传完成，等待审核');
                        return data;
                    });
                }

                const completeBody = {
                    upload_token: remoteData.upload_token,
                    stored_filename: remoteData.stored_filename,
                    backend_file_id: remoteData.backend_file_id,
                    original_filename: remoteData.original_filename,
                    size_bytes: remoteData.size_bytes,
                    title: metaFields.title || '',
                    description: metaFields.description || '',
                    is_traffic: !!metaFields.is_traffic,
                    traffic_cost: metaFields.traffic_cost || '0',
                };

                return xhrPostJson(self.config.completeUrl, completeBody).then(function (data) {
                    onProgress(100, '上传完成，等待审核');
                    return data;
                });
            });
        });
    };

    global.VideoUploader = VideoUploader;
})(typeof window !== 'undefined' ? window : globalThis);
