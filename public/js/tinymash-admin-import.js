(function (global) {
    'use strict';

    async function readJsonResponse(response) {
        const contentType = (response.headers.get('content-type') || '').toLowerCase();
        const responseText = await response.text();
        let payload = null;

        if (contentType.includes('application/json') || /^[\s]*[\[{]/.test(responseText)) {
            try {
                payload = JSON.parse(responseText);
            } catch (error) {
                payload = null;
            }
        }

        if (!payload || typeof payload !== 'object') {
            const statusLabel = response.status > 0 ? ' (HTTP ' + response.status + ')' : '';
            const error = new Error('The server returned an unexpected response' + statusLabel + '. Reload this preview to resume.');
            error.requiresLogin = response.status === 401 || response.redirected;
            throw error;
        }

        if (!response.ok) {
            const error = new Error(payload.error || ('Import failed with status ' + response.status + '.'));
            error.requiresLogin = response.status === 401;
            throw error;
        }

        return payload;
    }

    function buildLoginUrl(loginUrl, returnUrl) {
        const fallback = typeof global.tinymashBrowserPath === 'function' ? global.tinymashBrowserPath('/login') : '/login';
        const url = new URL(loginUrl || fallback, global.location.origin);
        url.searchParams.set('return', returnUrl || global.location.pathname + global.location.search);
        return url.pathname + url.search;
    }

    global.TinyMashImportAdmin = Object.freeze({
        buildLoginUrl,
        readJsonResponse
    });
}(window));
