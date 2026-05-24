/**
 * JFBWQA - Email Action Composer
 * v1.28
 *
 * Reveals one section inside the "JFBWQA: Email Action Composer" metabox
 * based on whichever option is selected in WC's native "Order actions"
 * dropdown (#wc-orders-actions-select on HPOS, #order_actions on legacy).
 *
 * Also greys/un-greys the per-order Order-Details-Table fieldset based
 * on the master "Override the plugin-settings table layout" checkbox in
 * each section.
 *
 * No jQuery dependency. Runs after DOMContentLoaded.
 */
(function () {
    'use strict';

    function debugLog() {
        if (typeof window !== 'undefined' && window.JFBWQA_DEBUG) {
            console.log.apply(console, ['[JFBWQA composer]'].concat([].slice.call(arguments)));
        }
    }

    function findOrderActionSelect() {
        // WC has used a few selectors over the years. Probe in order of
        // likelihood. First match wins.
        var candidates = [
            'select[name="wc_order_action"]',          // legacy + HPOS standard
            'select#wc-orders-actions-select',         // older HPOS variants
            'select#order_actions',                    // very legacy
        ];
        for (var i = 0; i < candidates.length; i++) {
            var node = document.querySelector(candidates[i]);
            if (node) return node;
        }
        return null;
    }

    function showSectionForAction(action) {
        var sections = document.querySelectorAll('#jfbwqa-action-composer .jfbwqa-action-section');
        var emptyState = document.querySelector('#jfbwqa-action-composer .jfbwqa-empty-state');
        var anyMatched = false;
        sections.forEach(function (section) {
            if (action && section.getAttribute('data-jfbwqa-action') === action) {
                section.style.display = '';
                anyMatched = true;
            } else {
                section.style.display = 'none';
            }
        });
        if (emptyState) {
            emptyState.style.display = anyMatched ? 'none' : '';
        }
        debugLog('action="' + action + '"', 'matched=' + anyMatched);
    }

    function bindActionDropdown() {
        var select = findOrderActionSelect();
        if (!select) {
            // The dropdown isn't on the page yet (or this isn't an order
            // edit screen). Try once more on a small delay because some
            // HPOS UIs render their action select via React after initial
            // DOMContentLoaded.
            setTimeout(function () {
                var retry = findOrderActionSelect();
                if (retry) {
                    debugLog('Found order-action select on retry');
                    attach(retry);
                }
            }, 250);
            return;
        }
        attach(select);
    }

    function attach(select) {
        // Initial render based on whatever's currently selected (usually
        // the empty placeholder, but on form re-submission the previous
        // selection may persist).
        showSectionForAction(select.value || '');
        select.addEventListener('change', function () {
            showSectionForAction(select.value || '');
        });
    }

    function bindOverrideTableToggles() {
        var toggles = document.querySelectorAll('#jfbwqa-action-composer .jfbwqa-override-table-toggle');
        toggles.forEach(function (cb) {
            // The fieldset is the direct sibling of the label-with-checkbox.
            var fieldset = cb.closest('td')
                ? cb.closest('td').querySelector('.jfbwqa-table-overrides')
                : null;
            if (!fieldset) return;
            function refresh() {
                fieldset.style.opacity = cb.checked ? '1' : '0.5';
                var inputs = fieldset.querySelectorAll('input[type="checkbox"]');
                inputs.forEach(function (i) { i.disabled = !cb.checked; });
            }
            refresh();
            cb.addEventListener('change', refresh);
        });
    }

    function init() {
        bindActionDropdown();
        bindOverrideTableToggles();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
