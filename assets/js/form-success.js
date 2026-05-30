/**
 * JFBWQA — Form success display (v1.29)
 *
 * On a successful JetFormBuilder submission, hides every field row of OUR
 * form(s) so only the success message remains, until the form is reset or
 * (in the popup flow) the popup closes.
 *
 * "Our form(s)" = the form IDs localized in window.jfbwqaForm.formIds, which
 * the plugin computes server-side as the JFB forms that call our hook. This
 * keeps unrelated forms on the same page untouched.
 *
 * Mechanism: add the class `jfbwqa-submitted` to the matching
 * <form class="jet-form-builder" data-form-id="N">. The companion CSS hides
 * `.jet-form-builder-row` (all field rows + the submit row) while leaving
 * `.jet-form-builder-messages-wrap` visible.
 *
 * No jQuery dependency for the DOM work, but JFB fires its success event on
 * the jQuery event bus, so we bind through window.jQuery when present.
 */
(function () {
    'use strict';

    function cfg() {
        return (window.jfbwqaForm && typeof window.jfbwqaForm === 'object') ? window.jfbwqaForm : {};
    }

    function ourFormIds() {
        var ids = cfg().formIds;
        return Array.isArray(ids) ? ids.map(function (n) { return String(n); }) : [];
    }

    function isOurForm(formEl) {
        if (!formEl || !formEl.getAttribute) return false;
        var id = formEl.getAttribute('data-form-id');
        return id && ourFormIds().indexOf(String(id)) !== -1;
    }

    function markSubmitted(formEl) {
        if (!formEl) return;
        formEl.classList.add('jfbwqa-submitted');
    }

    function clearSubmitted(scope) {
        var root = scope || document;
        root.querySelectorAll('form.jet-form-builder.jfbwqa-submitted').forEach(function (f) {
            f.classList.remove('jfbwqa-submitted');
        });
    }

    // Resolve the form that just succeeded. JFB's event payload shape varies
    // across versions, so probe a few places, then fall back to "any of our
    // forms currently in the DOM".
    function resolveForms(eventTarget, extra) {
        var found = [];

        function consider(node) {
            if (!node) return;
            // node may be the form, or something inside/near it.
            var form = node.closest ? node.closest('form.jet-form-builder') : null;
            if (!form && node.querySelector) {
                form = node.matches && node.matches('form.jet-form-builder') ? node : node.querySelector('form.jet-form-builder');
            }
            if (form && isOurForm(form) && found.indexOf(form) === -1) {
                found.push(form);
            }
        }

        consider(eventTarget);
        if (extra && extra.target) consider(extra.target);
        if (extra && extra.$form && extra.$form[0]) consider(extra.$form[0]);

        if (!found.length) {
            // Fallback: mark every one of our forms present on the page.
            document.querySelectorAll('form.jet-form-builder[data-form-id]').forEach(function (f) {
                if (isOurForm(f)) found.push(f);
            });
        }
        return found;
    }

    function onSuccess(event, arg1, arg2) {
        var target = (event && event.target) ? event.target : null;
        var forms = resolveForms(target, arg1 || arg2 || {});
        forms.forEach(markSubmitted);
    }

    function bind() {
        if (!window.jQuery) {
            // JFB always ships jQuery on pages with a form; if it's missing
            // there's nothing to bind to.
            return;
        }
        var $ = window.jQuery;
        // JFB fires this on both document and window depending on version.
        $(document).on('jet-form-builder/ajax/on-success', onSuccess);
        $(window).on('jet-form-builder/ajax/on-success', onSuccess);

        // Restore fields when the popup reopens so a returning visitor sees a
        // fresh form. JetPopup-specific but harmless if JetPopup isn't present.
        $(window).on('jet-popup-open-trigger jet-popup/render-content/after', function () {
            // Small delay so we don't race JFB's own re-render.
            setTimeout(function () { clearSubmitted(document); }, 50);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
})();
