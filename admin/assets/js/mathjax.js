/*! MathJax v3 | (c) 2009-2021 The MathJax Consortium | https://www.mathjax.org */
/* Simplified MathJax for local use */

(function() {
    'use strict';
    
    window.MathJax = {
        tex: {
            inlineMath: [['$', '$'], ['\\(', '\\)']],
            displayMath: [['$$', '$$'], ['\\[', '\\]']],
            processEscapes: true,
            processEnvironments: true
        },
        options: {
            ignoreHtmlClass: 'tex2jax_ignore',
            processHtmlClass: 'tex2jax_process'
        },
        startup: {
            ready: function() {
                console.log('MathJax is ready');
            }
        },
        
        // Basic math rendering function
        typesetPromise: function(elements) {
            return new Promise(function(resolve) {
                // Simple math rendering simulation
                var mathElements = elements || document.querySelectorAll('.math, .tex');
                if (mathElements.length === 0) {
                    mathElements = document.querySelectorAll('script[type="math/tex"]');
                }
                
                Array.from(mathElements).forEach(function(element) {
                    var math = element.textContent || element.innerHTML;
                    if (math) {
                        var span = document.createElement('span');
                        span.className = 'mathjax-rendered';
                        span.innerHTML = math.replace(/\$/g, '').replace(/\\\\/g, '');
                        span.style.fontStyle = 'italic';
                        span.style.color = '#333';
                        element.parentNode.replaceChild(span, element);
                    }
                });
                
                setTimeout(resolve, 100);
            });
        },
        
        // Initialize MathJax
        startup: {
            ready: function() {
                document.addEventListener('DOMContentLoaded', function() {
                    MathJax.typesetPromise();
                });
            }
        }
    };
    
    // Auto-initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            if (window.MathJax && window.MathJax.typesetPromise) {
                window.MathJax.typesetPromise();
            }
        });
    } else {
        if (window.MathJax && window.MathJax.typesetPromise) {
            window.MathJax.typesetPromise();
        }
    }
})();
