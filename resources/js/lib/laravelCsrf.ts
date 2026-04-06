/**
 * Laravel CSRF for fetch(): prefer X-XSRF-TOKEN from the XSRF-TOKEN cookie so we stay in sync
 * with the session. Sending a stale X-CSRF-TOKEN from <meta> overrides the cookie and causes 419.
 *
 * @see https://laravel.com/docs/csrf#csrf-x-csrf-token
 */
function readXsrfTokenFromCookie(): string | null {
    if (typeof document === 'undefined') {
        return null;
    }
    const row = document.cookie.split('; ').find((r) => r.startsWith('XSRF-TOKEN='));
    if (!row) {
        return null;
    }
    const value = row.slice('XSRF-TOKEN='.length);
    try {
        return decodeURIComponent(value);
    } catch {
        return value;
    }
}

function readCsrfFromMeta(): string | null {
    if (typeof document === 'undefined') {
        return null;
    }
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content?.trim() || null;
}

/**
 * Headers for same-origin POST requests that must pass VerifyCsrfToken.
 */
export function buildCsrfFetchHeaders(sharedCsrfToken?: string | null): Record<string, string> {
    const headers: Record<string, string> = {
        'X-Requested-With': 'XMLHttpRequest',
    };

    const xsrf = readXsrfTokenFromCookie();
    if (xsrf) {
        headers['X-XSRF-TOKEN'] = xsrf;
        return headers;
    }

    const plain = sharedCsrfToken?.trim() || readCsrfFromMeta();
    if (plain) {
        headers['X-CSRF-TOKEN'] = plain;
    }

    return headers;
}
