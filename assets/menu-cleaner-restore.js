jQuery(document).ready(function($) {
    // Handle view items click
    $('.view-session-items').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var session = $button.data('session');
        var $itemsRow = $('#items-' + session);
        var $container = $itemsRow.find('.items-container');
        
        if ($itemsRow.is(':visible')) {
            $itemsRow.slideUp();
            return;
        }
        
        // Load items if not already loaded
        if ($container.is(':empty')) {
            $container.html('<p>Loading items...</p>');
            
            $.ajax({
                url: menu_cleaner_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'menu_cleaner_get_session_items',
                    nonce: menu_cleaner_ajax.nonce,
                    session: session
                },
                success: function(response) {
                    if (response.success) {
                        var html = '<div class="deleted-items-list">';
                        response.data.items.forEach(function(item) {
                            html += '<div class="deleted-item">';
                            html += '<label>';
                            html += '<input type="checkbox" class="restore-item-checkbox" value="' + item.id + '" checked> ';
                            html += '<strong>' + escapeHtml(item.item_title) + '</strong> ';
                            html += '(ID: ' + item.item_id + ')';
                            html += '</label>';
                            html += '</div>';
                        });
                        html += '</div>';
                        html += '<p style="margin-top: 10px;">';
                        html += '<button class="button restore-selected" data-session="' + session + '">Restore Selected Items</button> ';
                        html += '<button class="button select-all">Select All</button> ';
                        html += '<button class="button select-none">Select None</button>';
                        html += '</p>';
                        
                        $container.html(html);
                    } else {
                        $container.html('<p>Error loading items.</p>');
                    }
                }
            });
        }
        
        $itemsRow.slideDown();
    });
    
    // Handle restore all click
    $('.restore-all-items').on('click', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var session = $button.data('session');
        var menuId = $button.data('menu-id');
        
        if (!confirm('Are you sure you want to restore all items from this deletion session?')) {
            return;
        }
        
        $button.prop('disabled', true).text('Restoring...');
        
        $.ajax({
            url: menu_cleaner_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'menu_cleaner_restore_items',
                nonce: menu_cleaner_ajax.nonce,
                session: session,
                menu_id: menuId,
                items: 'all'
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', 'Successfully restored ' + response.data.restored_count + ' items.');
                    
                    // Remove the row
                    $button.closest('tr').fadeOut(function() {
                        $(this).next('.session-items').remove();
                        $(this).remove();
                    });
                } else {
                    showNotice('error', response.data.message || 'Error restoring items.');
                    $button.prop('disabled', false).text('Restore All');
                }
            },
            error: function() {
                showNotice('error', 'An error occurred while restoring items.');
                $button.prop('disabled', false).text('Restore All');
            }
        });
    });
    
    // Handle restore selected items
    $(document).on('click', '.restore-selected', function(e) {
        e.preventDefault();
        
        var $button = $(this);
        var session = $button.data('session');
        var $container = $button.closest('.items-container');
        var selectedItems = [];
        
        $container.find('.restore-item-checkbox:checked').each(function() {
            selectedItems.push($(this).val());
        });
        
        if (selectedItems.length === 0) {
            alert('Please select at least one item to restore.');
            return;
        }
        
        if (!confirm('Are you sure you want to restore ' + selectedItems.length + ' selected items?')) {
            return;
        }
        
        $button.prop('disabled', true).text('Restoring...');
        
        $.ajax({
            url: menu_cleaner_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'menu_cleaner_restore_items',
                nonce: menu_cleaner_ajax.nonce,
                session: session,
                items: selectedItems
            },
            success: function(response) {
                if (response.success) {
                    showNotice('success', 'Successfully restored ' + response.data.restored_count + ' items.');
                    
                    // Remove restored items from list
                    selectedItems.forEach(function(itemId) {
                        $container.find('.restore-item-checkbox[value="' + itemId + '"]').closest('.deleted-item').fadeOut();
                    });
                    
                    // Check if all items are restored
                    setTimeout(function() {
                        if ($container.find('.deleted-item:visible').length === 0) {
                            $button.closest('tr').prev('tr').fadeOut(function() {
                                $(this).next('.session-items').remove();
                                $(this).remove();
                            });
                        }
                    }, 500);
                } else {
                    showNotice('error', response.data.message || 'Error restoring items.');
                }
                $button.prop('disabled', false).text('Restore Selected Items');
            },
            error: function() {
                showNotice('error', 'An error occurred while restoring items.');
                $button.prop('disabled', false).text('Restore Selected Items');
            }
        });
    });
    
    // Handle select all/none
    $(document).on('click', '.select-all', function(e) {
        e.preventDefault();
        $(this).closest('.items-container').find('.restore-item-checkbox').prop('checked', true);
    });
    
    $(document).on('click', '.select-none', function(e) {
        e.preventDefault();
        $(this).closest('.items-container').find('.restore-item-checkbox').prop('checked', false);
    });
    
    function showNotice(type, message) {
        var noticeClass = 'notice-' + type;
        var icon = '';
        
        switch(type) {
            case 'success':
                icon = 'dashicons-yes-alt';
                break;
            case 'error':
                icon = 'dashicons-dismiss';
                break;
        }
        
        var notice = $('<div class="notice ' + noticeClass + ' is-dismissible">')
            .html('<p><span class="dashicons ' + icon + '"></span> ' + escapeHtml(message) + '</p>');
        
        $('#menu-cleaner-notices').html(notice);
        
        // Make dismissible
        notice.on('click', '.notice-dismiss', function() {
            notice.fadeOut();
        });
        
        // Add dismiss button if not present
        if (!notice.find('.notice-dismiss').length) {
            notice.append('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Dismiss this notice.</span></button>');
        }
    }
    
    function escapeHtml(text) {
        var map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        
        return text.replace(/[&<>"']/g, function(m) { return map[m]; });
    }
});