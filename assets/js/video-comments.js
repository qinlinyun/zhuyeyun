(function () {
    'use strict';

    const cfg = window.VIDEO_COMMENTS_CONFIG || {};
    const root = document.getElementById('videoCommentsRoot');
    if (!root || !cfg.videoId) return;

    const state = {
        page: 1,
        pages: 1,
        total: 0,
        loading: false,
        replyTo: null,
    };

    function esc(text) {
        return String(text ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function defaultAvatarSvg(className) {
        return '<svg class="' + esc(className) + '" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" aria-hidden="true">'
            + '<circle cx="12" cy="8" r="4" stroke-width="1.8"/>'
            + '<path stroke-width="1.8" stroke-linecap="round" d="M4 20c1.5-3 4.5-5 8-5s6.5 2 8 5"/>'
            + '</svg>';
    }

    function avatarHtml(user, className) {
        if (user && user.avatar) {
            return '<img src="' + esc(user.avatar) + '" alt="" class="' + esc(className) + '">';
        }
        return defaultAvatarSvg(className + ' video-comments__avatar--svg');
    }

    function toast(msg, isError) {
        const el = document.getElementById('videoCommentsToast');
        if (!el) return;
        el.textContent = msg;
        el.className = 'video-comments__toast' + (isError ? ' video-comments__toast--error' : '');
        el.hidden = false;
        clearTimeout(toast._t);
        toast._t = setTimeout(function () { el.hidden = true; }, 2800);
    }

    function fetchJson(url, options) {
        return fetch(url, Object.assign({ credentials: 'same-origin' }, options || {}))
            .then(function (res) {
                return res.json().then(function (data) {
                    if (!res.ok && data && !data.message) {
                        data.message = '请求失败';
                    }
                    return data;
                });
            });
    }

    function renderReplyForm(parentId) {
        return ''
            + '<div class="video-comments__reply-form" data-reply-form="' + parentId + '">'
            + '<div class="video-comments__form">'
            + avatarHtml(cfg.currentUser, 'video-comments__avatar')
            + '<div class="video-comments__input-wrap">'
            + '<textarea class="video-comments__textarea" maxlength="' + (cfg.maxLength || 1000) + '" placeholder="写下你的回复..."></textarea>'
            + '<div class="video-comments__actions">'
            + '<span class="video-comments__hint">支持楼中楼回复</span>'
            + '<div>'
            + '<button type="button" class="video-comments__link" data-cancel-reply>取消</button> '
            + '<button type="button" class="video-comments__submit" data-submit-reply="' + parentId + '">回复</button>'
            + '</div></div></div></div></div>';
    }

    function renderComment(item, isReply) {
        const name = esc((item.user && (item.user.display_name || item.user.username)) || '用户');
        const mine = item.is_mine ? '<span class="video-comments__badge">我</span>' : '';
        let toolbar = '';
        if (!isReply) {
            toolbar += '<button type="button" class="video-comments__link" data-reply="' + item.id + '">回复</button>';
        }
        if (item.can_delete) {
            toolbar += '<button type="button" class="video-comments__link video-comments__link--danger" data-delete="' + item.id + '">删除</button>';
        }

        let repliesHtml = '';
        if (!isReply && item.replies && item.replies.length) {
            repliesHtml = '<div class="video-comments__replies">'
                + item.replies.map(function (r) { return renderComment(r, true); }).join('')
                + '</div>';
        }

        return ''
            + '<article class="video-comments__item" data-comment-id="' + item.id + '">'
            + '<div class="video-comments__form">'
            + avatarHtml(item.user, 'video-comments__avatar')
            + '<div class="video-comments__input-wrap">'
            + '<div class="video-comments__meta"><span class="video-comments__name">' + name + '</span>' + mine
            + '<span class="video-comments__time">' + esc(item.created_at) + '</span></div>'
            + '<div class="video-comments__body">' + esc(item.content) + '</div>'
            + (toolbar ? '<div class="video-comments__toolbar">' + toolbar + '</div>' : '')
            + '<div data-reply-slot="' + item.id + '"></div>'
            + repliesHtml
            + '</div></div></article>';
    }

    function renderList(data) {
        const listEl = root.querySelector('[data-comments-list]');
        const countEl = root.querySelector('[data-comments-count]');
        const pagerEl = root.querySelector('[data-comments-pager]');
        if (!listEl) return;

        state.page = data.page || 1;
        state.pages = data.pages || 1;
        state.total = data.total || 0;

        if (countEl) {
            countEl.textContent = state.total + ' 条评论';
        }

        if (!data.items || !data.items.length) {
            listEl.innerHTML = '<div class="video-comments__empty">暂无评论，来抢沙发吧</div>';
        } else {
            listEl.innerHTML = data.items.map(function (item) { return renderComment(item, false); }).join('');
        }

        if (pagerEl) {
            if (state.pages <= 1) {
                pagerEl.innerHTML = '';
                pagerEl.hidden = true;
            } else {
                pagerEl.hidden = false;
                pagerEl.innerHTML = ''
                    + '<button type="button" data-page="' + (state.page - 1) + '" ' + (state.page <= 1 ? 'disabled' : '') + '>上一页</button>'
                    + '<span>' + state.page + ' / ' + state.pages + '</span>'
                    + '<button type="button" data-page="' + (state.page + 1) + '" ' + (state.page >= state.pages ? 'disabled' : '') + '>下一页</button>';
            }
        }
    }

    function loadComments(page) {
        if (state.loading) return;
        state.loading = true;
        const listEl = root.querySelector('[data-comments-list]');
        if (listEl) listEl.innerHTML = '<div class="video-comments__loading">加载中...</div>';

        fetchJson(cfg.listUrl + '?video_id=' + encodeURIComponent(cfg.videoId) + '&page=' + encodeURIComponent(page || 1))
            .then(function (data) {
                if (!data.ok) throw new Error(data.message || '加载失败');
                renderList(data);
            })
            .catch(function (err) {
                if (listEl) listEl.innerHTML = '<div class="video-comments__error">' + esc(err.message || '加载失败') + '</div>';
            })
            .finally(function () { state.loading = false; });
    }

    function submitComment(content, parentId) {
        const fd = new FormData();
        fd.append('video_id', String(cfg.videoId));
        fd.append('content', content);
        if (parentId) fd.append('parent_id', String(parentId));

        return fetchJson(cfg.postUrl, { method: 'POST', body: fd })
            .then(function (data) {
                if (!data.ok) throw new Error(data.message || '发表失败');
                toast(parentId ? '回复成功' : '评论成功');
                loadComments(parentId ? state.page : 1);
                return data;
            });
    }

    function deleteComment(id) {
        const fd = new FormData();
        fd.append('comment_id', String(id));
        return fetchJson(cfg.deleteUrl, { method: 'POST', body: fd })
            .then(function (data) {
                if (!data.ok) throw new Error(data.message || '删除失败');
                toast('已删除');
                loadComments(state.page);
            });
    }

    root.addEventListener('click', function (e) {
        const pageBtn = e.target.closest('[data-page]');
        if (pageBtn && !pageBtn.disabled) {
            loadComments(Number(pageBtn.getAttribute('data-page')));
            return;
        }

        const replyBtn = e.target.closest('[data-reply]');
        if (replyBtn) {
            const parentId = replyBtn.getAttribute('data-reply');
            const slot = root.querySelector('[data-reply-slot="' + parentId + '"]');
            if (slot) {
                slot.innerHTML = renderReplyForm(parentId);
                const ta = slot.querySelector('textarea');
                if (ta) ta.focus();
            }
            return;
        }

        const cancelBtn = e.target.closest('[data-cancel-reply]');
        if (cancelBtn) {
            const form = cancelBtn.closest('[data-reply-form]');
            if (form) form.remove();
            return;
        }

        const submitReplyBtn = e.target.closest('[data-submit-reply]');
        if (submitReplyBtn) {
            const parentId = submitReplyBtn.getAttribute('data-submit-reply');
            const form = submitReplyBtn.closest('[data-reply-form]');
            const ta = form ? form.querySelector('textarea') : null;
            const content = ta ? ta.value.trim() : '';
            if (!content) {
                toast('请输入回复内容', true);
                return;
            }
            submitReplyBtn.disabled = true;
            submitComment(content, parentId)
                .catch(function (err) { toast(err.message || '回复失败', true); })
                .finally(function () { submitReplyBtn.disabled = false; });
            return;
        }

        const deleteBtn = e.target.closest('[data-delete]');
        if (deleteBtn) {
            const id = deleteBtn.getAttribute('data-delete');
            if (!confirm('确定删除这条评论吗？')) return;
            deleteComment(id).catch(function (err) { toast(err.message || '删除失败', true); });
        }
    });

    const mainForm = root.querySelector('[data-main-comment-form]');
    if (mainForm) {
        mainForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const ta = mainForm.querySelector('textarea');
            const btn = mainForm.querySelector('[type="submit"]');
            const content = ta ? ta.value.trim() : '';
            if (!content) {
                toast('请输入评论内容', true);
                return;
            }
            if (btn) btn.disabled = true;
            submitComment(content, null)
                .then(function () { if (ta) ta.value = ''; })
                .catch(function (err) { toast(err.message || '发表失败', true); })
                .finally(function () { if (btn) btn.disabled = false; });
        });
    }

    loadComments(1);
})();
