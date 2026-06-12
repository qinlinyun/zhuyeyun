/**
 * 注册页：AJAX 提交（可选增强）；无此脚本时表单仍会以 POST 提交到 register.php。
 */
(function () {
    'use strict';

    var form = document.getElementById('registerForm');
    var submitBtn = document.getElementById('registerSubmitBtn');
    var formMessage = document.getElementById('registerFormMessage');
    if (!form || !submitBtn) {
        return;
    }

    var fieldInputMap = {
        username: document.getElementById('registerUsername'),
        email: document.getElementById('registerEmail'),
        verify_code: document.getElementById('verifyCode'),
        password: document.getElementById('registerPassword'),
        confirm: document.getElementById('registerConfirm')
    };

    var errorBorder = 'border-red-500';
    var normalBorder = ['border-gray-300', 'dark:border-gray-600'];

    function clearFieldErrors() {
        form.querySelectorAll('[data-field-error]').forEach(function (el) {
            el.textContent = '';
            el.classList.add('hidden');
        });
        Object.keys(fieldInputMap).forEach(function (key) {
            var input = fieldInputMap[key];
            if (!input) {
                return;
            }
            input.classList.remove(errorBorder);
            normalBorder.forEach(function (cls) {
                input.classList.add(cls);
            });
        });
        if (formMessage) {
            formMessage.textContent = '';
            formMessage.classList.add('hidden');
        }
    }

    function showFieldErrors(fieldErrors) {
        clearFieldErrors();
        var firstFocus = null;
        Object.keys(fieldErrors || {}).forEach(function (field) {
            var msg = fieldErrors[field];
            if (!msg) {
                return;
            }
            var errEl = document.getElementById('registerError-' + field);
            var input = fieldInputMap[field];
            if (errEl) {
                errEl.textContent = msg;
                errEl.classList.remove('hidden');
            }
            if (input) {
                normalBorder.forEach(function (cls) {
                    input.classList.remove(cls);
                });
                input.classList.add(errorBorder);
                if (!firstFocus) {
                    firstFocus = input;
                }
            }
        });
        if (firstFocus) {
            firstFocus.focus();
        }
    }

    function showFormMessage(text) {
        if (!formMessage || !text) {
            return;
        }
        formMessage.textContent = text;
        formMessage.classList.remove('hidden');
        formMessage.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    function setSubmitting(loading) {
        submitBtn.disabled = loading;
        submitBtn.textContent = loading ? '提交中…' : '注册';
    }

    Object.keys(fieldInputMap).forEach(function (key) {
        var input = fieldInputMap[key];
        if (!input) {
            return;
        }
        input.addEventListener('input', function () {
            var field = input.name === 'verify_code' ? 'verify_code' : input.name;
            var errEl = document.getElementById('registerError-' + field);
            if (errEl && !errEl.classList.contains('hidden')) {
                errEl.textContent = '';
                errEl.classList.add('hidden');
                input.classList.remove(errorBorder);
                normalBorder.forEach(function (cls) {
                    input.classList.add(cls);
                });
            }
        });
    });

    form.addEventListener('submit', function (e) {
        if (form.dataset.ajax !== '1') {
            return;
        }
        e.preventDefault();
        clearFieldErrors();
        setSubmitting(true);

        var action = form.getAttribute('action') || 'register.php';
        fetch(action, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin',
            body: new URLSearchParams(new FormData(form)).toString()
        })
            .then(function (r) {
                return r.text().then(function (text) {
                    var data = null;
                    try {
                        data = text ? JSON.parse(text) : null;
                    } catch (err) {
                        throw new Error('服务器返回异常，请刷新后重试');
                    }
                    if (!r.ok && (!data || !data.message)) {
                        throw new Error((data && data.message) || ('请求失败 HTTP ' + r.status));
                    }
                    return data || { ok: false, message: '服务器无响应' };
                });
            })
            .then(function (data) {
                if (data.ok) {
                    window.location.href = data.redirect || 'login.php';
                    return;
                }
                if (data.field_errors && Object.keys(data.field_errors).length) {
                    showFieldErrors(data.field_errors);
                }
                if (data.message) {
                    showFormMessage(data.message);
                } else if (!data.field_errors || !Object.keys(data.field_errors).length) {
                    showFormMessage('注册失败，请检查填写内容');
                }
            })
            .catch(function (err) {
                showFormMessage(err && err.message ? err.message : '网络错误，请稍后重试');
            })
            .finally(function () {
                setSubmitting(false);
            });
    });
})();
