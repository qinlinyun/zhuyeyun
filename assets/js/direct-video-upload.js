/**
 * 用户 → 远程上传后端（PHP api/upload_video.php）→ 主站仅登记元数据（upload_complete）
 * 视频文件不经主站服务器
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

    function xhrPostForm(url, formData, onProgress) {
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
                reject((data && (data.message || data.error)) || ('远程上传失败 HTTP ' + xhr.status + ' @ ' + url));
            };
            xhr.onerror = function () {
                reject(new Error('无法连接远程上传后端 @ ' + url + '（请检查上传地址、证书与 Nginx client_max_body_size）'));
            };
            xhr.send(formData);
        });
    }

    function tryEndpoints(endpoints, runner) {
        const list = (endpoints || []).filter(Boolean);
        if (!list.length) {
            return Promise.reject('未配置远程上传地址');
        }
        let lastErr = '';
        function next(i) {
            if (i >= list.length) {
                return Promise.reject(lastErr || '所有远程上传地址均失败');
            }
            return runner(list[i]).catch(function (err) {
                lastErr = String(err && err.message ? err.message : err);
                return next(i + 1);
            });
        }
        return next(0);
    }

    function uploadToRemote(prepare, fd, onProgress) {
        return tryEndpoints(prepare.remote_upload_urls, function (endpoint) {
            return xhrPostForm(endpoint, fd, function (loaded, total) {
                if (typeof onProgress === 'function' && total > 0) {
                    onProgress(10 + (loaded / total) * 88, '正在上传到远程后端...');
                }
            });
        });
    }

    function uploadDirect(options) {
        const file = options.file;
        if (!file) {
            return Promise.reject('请选择视频文件');
        }

        return xhrPostJson(options.prepareUrl, {
            original_filename: file.name || 'video.mp4',
        }).then(function (prepare) {
            const token = prepare.upload_token;
            const storedFilename = prepare.stored_filename || prepare.backend_file_id;
            if (!token || !storedFilename) {
                throw new Error('预上传响应缺少令牌或路径');
            }

            const maxBytes = prepare.max_upload_bytes || 0;
            if (maxBytes > 0 && file.size > maxBytes) {
                throw new Error('视频超过限制（最大 ' + (prepare.max_upload_mb || 0) + ' MB）');
            }

            const fd = new FormData();
            fd.append('video_file', file, file.name || 'video.mp4');
            fd.append('upload_token', token);
            fd.append('stored_filename', storedFilename);
            if (options.title) {
                fd.append('title', options.title);
            }
            if (options.description) {
                fd.append('description', options.description);
            }

            if (typeof options.onProgress === 'function') {
                options.onProgress(5, '正在连接远程后端...');
            }

            return uploadToRemote(prepare, fd, options.onProgress).then(function (remote) {
                if (typeof options.onProgress === 'function') {
                    options.onProgress(96, '正在向主站登记审核...');
                }
                return xhrPostJson(options.completeUrl, {
                    upload_token: token,
                    title: options.title || '',
                    description: options.description || '',
                    original_filename: file.name || remote.original_filename || '',
                    stored_filename: remote.stored_filename || storedFilename,
                    backend_file_id: remote.backend_file_id || storedFilename,
                    size_bytes: remote.size_bytes || file.size || 0,
                    is_traffic: !!options.is_traffic,
                    traffic_cost: options.traffic_cost || '0',
                });
            });
        });
    }

    global.DirectVideoUpload = { upload: uploadDirect };
})(typeof window !== 'undefined' ? window : globalThis);
