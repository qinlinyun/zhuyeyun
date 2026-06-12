/**
 * 主站 upload.php：内嵌远程 embed_upload.php，上传完成后登记主站审核
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

    function completeOnMainSite(options) {
        return xhrPostJson(options.completeUrl, {
            upload_token: options.upload_token,
            title: options.title || '',
            description: options.description || '',
            original_filename: options.original_filename || '',
            stored_filename: options.stored_filename || '',
            backend_file_id: options.backend_file_id || options.stored_filename || '',
            size_bytes: options.size_bytes || 0,
            is_traffic: !!options.is_traffic,
            traffic_cost: options.traffic_cost || '0',
        });
    }

    function startEmbedUpload(options) {
        const iframe = options.iframe;
        const onProgress = options.onProgress;
        const mobileMode = !!options.mobileMode;
        const originalFilename = options.originalFilename || 'video.mp4';

        if (!iframe) {
            return Promise.reject('未找到上传区域');
        }

        if (typeof onProgress === 'function') {
            onProgress(5, '正在准备上传...');
        }

        return xhrPostJson(options.prepareUrl, {
            original_filename: originalFilename,
        }).then(function (prepare) {
            const embedUrl = prepare.embed_upload_url;
            if (!embedUrl) {
                throw new Error('未配置内嵌上传地址，请设置远程 UPLOAD_DOMAIN 或主站转码后端地址');
            }

            if (typeof options.onPrepare === 'function') {
                options.onPrepare(prepare);
            }

            if (typeof onProgress === 'function') {
                onProgress(12, '正在加载远程上传页...');
            }

            let embedOrigin = '';
            try {
                embedOrigin = new URL(embedUrl).origin;
            } catch (e) {
                embedOrigin = '';
            }

            return new Promise(function (resolve, reject) {
                let settled = false;

                function finishOk(payload) {
                    if (settled) return;
                    settled = true;
                    window.removeEventListener('message', onMessage);
                    resolve(Object.assign({ prepare: prepare }, payload));
                }

                function finishErr(msg) {
                    if (settled) return;
                    settled = true;
                    window.removeEventListener('message', onMessage);
                    reject(msg);
                }

                let remoteUploadDone = false;

                function acceptEmbedMessage(event) {
                    try {
                        if (iframe.contentWindow && event.source === iframe.contentWindow) {
                            return true;
                        }
                    } catch (e) {}
                    if (!embedOrigin) {
                        return false;
                    }
                    if (!event.origin || event.origin === 'null') {
                        return true;
                    }
                    try {
                        if (event.origin === embedOrigin) {
                            return true;
                        }
                        const a = new URL(event.origin);
                        const b = new URL(embedOrigin);
                        return a.hostname === b.hostname;
                    } catch (e) {
                        return false;
                    }
                }

                function onMessage(event) {
                    const data = event.data;
                    if (!data || data.type !== 'zhuyeyun-embed-upload') {
                        return;
                    }
                    if (!acceptEmbedMessage(event)) {
                        return;
                    }
                    if (data.ok === true) {
                        remoteUploadDone = true;
                        if (mobileMode) {
                            if (typeof options.onRemoteDone === 'function') {
                                options.onRemoteDone(data);
                            }
                            return;
                        }
                        finishOk(data);
                        return;
                    }
                    if (data.ok === false) {
                        if (mobileMode) {
                            if (!remoteUploadDone && typeof options.onRemoteError === 'function') {
                                options.onRemoteError(data.error || '远程上传失败');
                            }
                            return;
                        }
                        finishErr(data.error || '远程上传失败');
                    }
                    // 仅 progress / pending 消息：不结束流程
                }

                window.addEventListener('message', onMessage);

                iframe.onload = function () {
                    if (typeof onProgress === 'function') {
                        onProgress(18, '请在下方选择视频并点击上传');
                    }
                };

                iframe.onerror = function () {
                    finishErr('无法加载远程上传页面');
                };

                iframe.src = embedUrl;

                if (options.iframeTimeoutMs > 0) {
                    setTimeout(function () {
                        if (!settled) {
                            finishErr('上传超时，请重试');
                        }
                    }, options.iframeTimeoutMs);
                }
            });
        }).then(function (result) {
            if (mobileMode) {
                return result;
            }
            if (typeof onProgress === 'function') {
                onProgress(92, '正在向主站登记审核...');
            }
            const prepare = result.prepare;
            return xhrPostJson(options.completeUrl, {
                upload_token: prepare.upload_token,
                title: options.title || '',
                description: options.description || '',
                original_filename: result.original_filename || prepare.original_filename || '',
                stored_filename: result.stored_filename || prepare.stored_filename,
                backend_file_id: result.backend_file_id || prepare.backend_file_id,
                size_bytes: result.size_bytes || 0,
                is_traffic: !!options.is_traffic,
                traffic_cost: options.traffic_cost || '0',
            });
        });
    }

    global.EmbedVideoUpload = {
        start: startEmbedUpload,
        completeOnMainSite: completeOnMainSite,
    };
})(typeof window !== 'undefined' ? window : globalThis);
