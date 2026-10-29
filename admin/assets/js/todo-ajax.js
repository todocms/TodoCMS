/**
 * TodoCMS Global AJAX Wrapper
 * 
 * Intercepts fetch and jQuery.ajax calls to provide unified error handling and debugging.
 * Specifically targets "Direct access denied" errors and other common issues.
 */
(function (window, $) {
    'use strict';

    // --- 1. Intercept fetch API ---
    const originalFetch = window.fetch;
    window.fetch = function () {
        // Clone arguments to avoid side effects
        const args = Array.prototype.slice.call(arguments);
        const url = args[0];
        const options = args[1] || {};

        // Ensure X-Requested-With header is set (crucial for admin-ajax.php checks)
        if (!options.headers) {
            options.headers = {};
        }
        if (options.headers instanceof Headers) {
            if (!options.headers.has('X-Requested-With')) {
                options.headers.append('X-Requested-With', 'XMLHttpRequest');
            }
        } else if (Array.isArray(options.headers)) {
            // Handle array of headers (rare but possible)
            let hasHeader = false;
            for (let i = 0; i < options.headers.length; i++) {
                if (options.headers[i][0].toLowerCase() === 'x-requested-with') {
                    hasHeader = true; break;
                }
            }
            if (!hasHeader) options.headers.push(['X-Requested-With', 'XMLHttpRequest']);
        } else {
            // Plain object
            let hasHeader = false;
            for (let key in options.headers) {
                if (key.toLowerCase() === 'x-requested-with') {
                    hasHeader = true;
                    break;
                }
            }
            if (!hasHeader) {
                options.headers['X-Requested-With'] = 'XMLHttpRequest';
            }
        }
        args[1] = options;

        return originalFetch.apply(this, args)
            .then(response => {
                // Clone response to read body without consuming the original stream
                const clone = response.clone();

                if (!response.ok) {
                    console.error(`[TodoAjax] HTTP Error ${response.status} for ${url}`);
                }

                return clone.text().then(text => {
                    // Check for "Direct access denied"
                    if (text.includes('Direct access denied')) {
                        console.error(`[TodoAjax] CRITICAL: Direct access denied detected for ${url}`);
                        console.error('[TodoAjax] Response body:', text);
                    }

                    // Check for JSON errors
                    try {
                        const data = JSON.parse(text);
                        if (data && data.success === false) {
                            console.warn(`[TodoAjax] API Error for ${url}:`, data.error || data.message || 'Unknown error');
                            console.warn('[TodoAjax] Request details:', {
                                url: url,
                                method: options.method || 'GET',
                                body: options.body,
                                response: data
                            });
                        }
                    } catch (e) {
                        // Not JSON, or invalid JSON
                        if (response.ok && !text.includes('Direct access denied')) {
                            // If it was supposed to be JSON but isn't, log it
                            // console.warn(`[TodoAjax] Response for ${url} is not valid JSON:`, text.substring(0, 100) + '...');
                        }
                    }

                    return response; // Return original response
                });
            })
            .catch(error => {
                console.error(`[TodoAjax] Network Error for ${url}:`, error);
                throw error;
            });
    };

    // --- 2. Intercept jQuery.ajax (if jQuery is loaded) ---
    if ($) {
        $.ajaxSetup({
            beforeSend: function (xhr, settings) {
                // Ensure X-Requested-With is set (jQuery usually does this, but good to be sure)
                if (!settings.crossDomain) {
                    xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                }
            },
            dataFilter: function (data, type) {
                // Raw response data interception
                if (typeof data === 'string' && data.includes('Direct access denied')) {
                    console.error(`[TodoAjax] CRITICAL (jQuery): Direct access denied detected for ${this.url}`);
                    console.error('[TodoAjax] Response body:', data);
                }
                return data;
            },
            error: function (xhr, status, error) {
                console.error(`[TodoAjax] jQuery Error for ${this.url}:`, status, error);
                if (xhr.responseText && xhr.responseText.includes('Direct access denied')) {
                    console.error('[TodoAjax] Response body:', xhr.responseText);
                }
            }
        });
    }

})(window, window.jQuery);
