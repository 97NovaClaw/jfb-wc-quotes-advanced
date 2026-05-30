/**
 * JFBWQA v2.0+ - Order Events admin app
 * Sortable rail, visibility toggle, inline rename with AJAX auto-save.
 */
(function ($) {
    'use strict';

    var cfg = window.jfbwqaSettingsApp || {};
    var $status = $('#jfbwqa-registry-status');
    var statusTimer = null;

    function setStatus(message, type) {
        $status.removeClass('is-error is-success').text(message || '');
        if (type === 'error') {
            $status.addClass('is-error');
        } else if (type === 'success') {
            $status.addClass('is-success');
        }
        if (statusTimer) {
            clearTimeout(statusTimer);
        }
        if (message && type === 'success') {
            statusTimer = setTimeout(function () {
                $status.text('');
            }, 2500);
        }
    }

    function postAjax(action, data) {
        data = data || {};
        data.action = action;
        data.nonce = cfg.nonce;

        setStatus(cfg.i18n && cfg.i18n.saving ? cfg.i18n.saving : 'Saving…', '');

        return $.post(cfg.ajaxUrl, data)
            .done(function (response) {
                if (response && response.success) {
                    setStatus(cfg.i18n && cfg.i18n.saved ? cfg.i18n.saved : 'Saved.', 'success');
                } else {
                    var msg = (response && response.data && response.data.message) || (cfg.i18n && cfg.i18n.error) || 'Error';
                    setStatus(msg, 'error');
                }
            })
            .fail(function () {
                setStatus((cfg.i18n && cfg.i18n.error) || 'Error', 'error');
            });
    }

    function collectSlugs() {
        var slugs = [];
        $('#jfbwqa-event-registry .jfbwqa-event-row').each(function () {
            slugs.push($(this).data('slug'));
        });
        return slugs;
    }

    function hideAllPanels() {
        $('.jfbwqa-event-row').removeClass('is-active');
        $('.jfbwqa-rail-tab').removeClass('is-active');
        $('.jfbwqa-pane-panel--event').prop('hidden', true);
        $('.jfbwqa-pane-panel--intake, .jfbwqa-pane-panel--advanced, .jfbwqa-pane-panel--intake-tools')
            .removeClass('is-active')
            .prop('hidden', true);
    }

    function activatePanel(panelId) {
        hideAllPanels();

        if (panelId === 'intake') {
            $('.jfbwqa-rail-tab--intake').addClass('is-active');
            $('.jfbwqa-pane-panel--intake, .jfbwqa-pane-panel--intake-tools')
                .addClass('is-active')
                .prop('hidden', false);
            return;
        }

        if (panelId === 'advanced') {
            $('.jfbwqa-rail-tab--advanced').addClass('is-active');
            $('.jfbwqa-pane-panel--advanced').addClass('is-active').prop('hidden', false);
            return;
        }

        if (panelId) {
            $('.jfbwqa-event-row[data-slug="' + panelId + '"]').addClass('is-active');
            $('.jfbwqa-pane-panel--event[data-slug="' + panelId + '"]').prop('hidden', false);
        }
    }

    function syncPanelHeading(slug, label) {
        $('.jfbwqa-pane-panel--event[data-slug="' + slug + '"] h2').text(label);
    }

    $(function () {
        var $list = $('#jfbwqa-event-registry');

        if (!$list.length) {
            return;
        }

        activatePanel('intake');

        $list.sortable({
            handle: '.jfbwqa-drag-handle',
            axis: 'y',
            containment: 'parent',
            tolerance: 'pointer',
            update: function () {
                postAjax('jfbwqa_registry_reorder', { slugs: collectSlugs() });
            }
        });

        $list.on('click', '.jfbwqa-event-row', function (e) {
            if ($(e.target).closest('.jfbwqa-drag-handle, .jfbwqa-eye-toggle, .jfbwqa-event-label').length) {
                return;
            }
            activatePanel($(this).data('slug'));
        });

        $('.jfbwqa-rail-tab').on('click', function () {
            activatePanel($(this).data('panel'));
        });

        $list.on('click', '.jfbwqa-eye-toggle', function (e) {
            e.preventDefault();
            e.stopPropagation();

            var $row = $(this).closest('.jfbwqa-event-row');
            var slug = $row.data('slug');
            var currentlyVisible = $(this).attr('aria-pressed') === 'true';
            var nextVisible = !currentlyVisible;
            var $icon = $(this).find('.dashicons');

            $(this).attr('aria-pressed', nextVisible ? 'true' : 'false');
            $(this).attr(
                'title',
                nextVisible
                    ? (cfg.i18n && cfg.i18n.hide) || 'Hide'
                    : (cfg.i18n && cfg.i18n.show) || 'Show'
            );
            $icon.toggleClass('dashicons-visibility', nextVisible);
            $icon.toggleClass('dashicons-hidden', !nextVisible);
            $row.toggleClass('is-hidden-event', !nextVisible);

            postAjax('jfbwqa_registry_toggle_visible', {
                slug: slug,
                visible: nextVisible ? 1 : 0
            });
        });

        $list.on('keydown', '.jfbwqa-event-label', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                $(this).blur();
            }
        });

        $list.on('blur', '.jfbwqa-event-label', function () {
            var $input = $(this);
            var $row = $input.closest('.jfbwqa-event-row');
            var slug = $row.data('slug');
            var label = $.trim($input.val());
            var defaultLabel = $input.data('default-label') || '';

            if (label === '') {
                label = defaultLabel;
                $input.val(label);
            }

            syncPanelHeading(slug, label);

            postAjax('jfbwqa_registry_rename', {
                slug: slug,
                label: label
            });
        });

        $list.on('focus', '.jfbwqa-event-label', function (e) {
            e.stopPropagation();
            activatePanel($(this).closest('.jfbwqa-event-row').data('slug'));
        });
    });
}(jQuery));
