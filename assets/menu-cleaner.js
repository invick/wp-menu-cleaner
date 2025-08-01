jQuery(document).ready(function($) {
    var isDeleting = false;
    var totalToDelete = 0;
    var totalDeleted = 0;
    var totalSkipped = 0;
    var deletedItems = [];
    var skippedItems = [];
    
    // Handle deletion mode change
    $('#deletion_mode').on('change', function() {
        var mode = $(this).val();
        if (mode === 'count') {
            $('#num_items_row').show();
        } else {
            $('#num_items_row').hide();
        }
        
        // Update menu counts when mode changes
        updateAllMenuCounts();
    });
    
    // Handle delete button click
    $('#clean-menu-items').on('click', function(e) {
        e.preventDefault();
        
        if (isDeleting) {
            return;
        }
        
        var menuId = $('#menu_id').val();
        var numItems = parseInt($('#num_items').val());
        var skipParents = $('#skip_parents').is(':checked');
        var deletionMode = $('#deletion_mode').val();
        
        // Validation
        if (!menuId) {
            showNotice('error', 'Please select a menu to clean.');
            return;
        }
        
        if (deletionMode === 'count' && (!numItems || numItems < 1 || numItems > 500)) {
            showNotice('error', 'Please enter a valid number of items (1-500).');
            return;
        }
        
        // Confirm action
        var confirmMessage;
        if (deletionMode === 'draft') {
            confirmMessage = 'Are you sure you want to delete all draft menu items? This action cannot be undone.';
        } else if (deletionMode === 'orphaned') {
            confirmMessage = 'Are you sure you want to delete all orphaned menu items (items linking to deleted content)? This action cannot be undone.';
        } else {
            confirmMessage = 'Are you sure you want to delete ' + numItems + ' menu items? This action cannot be undone.';
        }
        
        if (!confirm(confirmMessage)) {
            return;
        }
        
        // Start deletion process
        startDeletion(menuId, numItems, skipParents, deletionMode);
    });
    
    function startDeletion(menuId, numItems, skipParents, deletionMode) {
        isDeleting = true;
        
        // For draft/orphaned modes, we don't know the total upfront
        if (deletionMode === 'draft' || deletionMode === 'orphaned') {
            totalToDelete = '?'; // Unknown initially
            
            // Get the actual count first
            $.ajax({
                url: menu_cleaner_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'menu_cleaner_get_count',
                    nonce: menu_cleaner_ajax.nonce,
                    menu_id: menuId,
                    deletion_mode: deletionMode
                },
                success: function(response) {
                    if (response.success) {
                        totalToDelete = response.data.count;
                        $('#progress-total').text(totalToDelete);
                        
                        if (totalToDelete === 0) {
                            var message = deletionMode === 'draft' ? 
                                'No draft menu items found.' : 
                                'No orphaned menu items found.';
                            showNotice('info', message);
                            resetForm();
                            return;
                        }
                        
                        // Continue with deletion
                        deleteBatch(menuId, 0, skipParents, deletionMode);
                    }
                }
            });
        } else {
            totalToDelete = numItems;
            $('#progress-total').text(totalToDelete);
            deleteBatch(menuId, 0, skipParents, deletionMode);
        }
        
        totalDeleted = 0;
        totalSkipped = 0;
        deletedItems = [];
        skippedItems = [];
        
        // Show progress
        $('#menu-cleaner-progress').show();
        $('#progress-current').text(0);
        $('.progress-fill').css('width', '0%');
        $('#deletion-log').empty();
        
        // Disable form
        $('#menu-cleaner-form input, #menu-cleaner-form select').prop('disabled', true);
        $('.spinner').addClass('is-active');
        
        // Clear previous notices
        $('#menu-cleaner-notices').empty();
    }
    
    function deleteBatch(menuId, offset, skipParents, deletionMode) {
        var batchSize = 10; // Delete 10 items at a time
        var remaining = (deletionMode === 'count') ? totalToDelete - totalDeleted : 999; // For draft/orphaned, continue until no more items
        
        if (remaining <= 0) {
            completeDeletion();
            return;
        }
        
        $.ajax({
            url: menu_cleaner_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'menu_cleaner_delete_items',
                nonce: menu_cleaner_ajax.nonce,
                menu_id: menuId,
                batch_size: Math.min(batchSize, remaining),
                offset: 0, // Always 0 because we're deleting from the end
                skip_parents: skipParents ? 'true' : 'false',
                deletion_mode: deletionMode || 'count'
            },
            success: function(response) {
                if (response.success) {
                    totalDeleted += response.data.count;
                    
                    // Handle skipped items
                    if (response.data.skipped && response.data.skipped.length > 0) {
                        totalSkipped += response.data.skipped.length;
                        response.data.skipped.forEach(function(item) {
                            skippedItems.push(item.id);
                            var logEntry = $('<div class="log-entry log-entry-skipped">').html(
                                '<span class="dashicons dashicons-minus"></span> Skipped: ' + 
                                '<strong>' + escapeHtml(item.title) + '</strong> ' +
                                '(ID: ' + item.id + ', Order: ' + item.order + ') - ' +
                                '<em>' + escapeHtml(item.reason) + '</em>'
                            );
                            $('#deletion-log').prepend(logEntry);
                        });
                    }
                    
                    // Update progress
                    var totalProcessed = totalDeleted + totalSkipped;
                    var progress = Math.min((totalProcessed / totalToDelete) * 100, 100);
                    $('#progress-current').text(totalProcessed);
                    $('#deleted-count').text(totalDeleted);
                    $('#skipped-count').text(totalSkipped);
                    $('.progress-fill').css('width', progress + '%');
                    
                    // Show details if we have skipped items
                    if (totalSkipped > 0) {
                        $('#progress-details').show();
                    }
                    
                    // Add to log for deleted items
                    if (response.data.deleted.length > 0) {
                        response.data.deleted.forEach(function(item) {
                            deletedItems.push(item.id);
                            var logEntry = $('<div class="log-entry log-entry-deleted">').html(
                                '<span class="dashicons dashicons-yes"></span> Deleted: ' + 
                                '<strong>' + escapeHtml(item.title) + '</strong> ' +
                                '(ID: ' + item.id + ', Order: ' + item.order + ')'
                            );
                            $('#deletion-log').prepend(logEntry);
                        });
                    }
                    
                    // Continue if more items to process
                    var totalProcessed = totalDeleted + totalSkipped;
                    var shouldContinue = false;
                    
                    if (deletionMode === 'draft' || deletionMode === 'orphaned') {
                        // For draft/orphaned, continue if we found items to delete
                        shouldContinue = response.data.has_more || (response.data.count > 0 || response.data.skipped_count > 0);
                    } else {
                        // For count mode, continue until we reach the target
                        shouldContinue = totalProcessed < totalToDelete && (response.data.count > 0 || response.data.skipped_count > 0);
                    }
                    
                    if (shouldContinue) {
                        setTimeout(function() {
                            deleteBatch(menuId, 0, skipParents, deletionMode);
                        }, 100); // Small delay between batches
                    } else {
                        completeDeletion();
                    }
                } else {
                    showNotice('error', response.data.message || 'An error occurred while deleting items.');
                    resetForm();
                }
            },
            error: function() {
                showNotice('error', 'An error occurred. Please try again.');
                resetForm();
            }
        });
    }
    
    function completeDeletion() {
        isDeleting = false;
        
        // Show success message
        if (totalDeleted > 0 || totalSkipped > 0) {
            var message = 'Process completed: ';
            if (totalDeleted > 0) {
                message += totalDeleted + ' items deleted';
            }
            if (totalSkipped > 0) {
                if (totalDeleted > 0) message += ', ';
                message += totalSkipped + ' items skipped';
            }
            showNotice('success', message);
            
            // Update menu item count in dropdown
            updateMenuCount($('#menu_id').val());
        } else {
            showNotice('info', 'No items were processed.');
        }
        
        resetForm();
    }
    
    function resetForm() {
        $('.spinner').removeClass('is-active');
        $('#menu-cleaner-form input, #menu-cleaner-form select').prop('disabled', false);
        isDeleting = false;
    }
    
    function updateMenuCount(menuId) {
        if (!menuId) return;
        
        var deletionMode = $('#deletion_mode').val();
        
        $.ajax({
            url: menu_cleaner_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'menu_cleaner_get_count',
                nonce: menu_cleaner_ajax.nonce,
                menu_id: menuId,
                deletion_mode: deletionMode
            },
            success: function(response) {
                if (response.success) {
                    var option = $('#menu_id option[value="' + menuId + '"]');
                    var menuName = option.text().split('(')[0].trim();
                    var countText;
                    
                    if (deletionMode === 'draft') {
                        countText = response.data.count + ' draft ' + (response.data.count === 1 ? 'item' : 'items');
                    } else if (deletionMode === 'orphaned') {
                        countText = response.data.count + ' orphaned ' + (response.data.count === 1 ? 'item' : 'items');
                    } else {
                        countText = response.data.count + ' ' + (response.data.count === 1 ? 'item' : 'items');
                    }
                    
                    option.text(menuName + ' (' + countText + ')');
                }
            }
        });
    }
    
    function updateAllMenuCounts() {
        $('#menu_id option').each(function() {
            var menuId = $(this).val();
            if (menuId) {
                updateMenuCount(menuId);
            }
        });
    }
    
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
            case 'warning':
                icon = 'dashicons-warning';
                break;
            case 'info':
                icon = 'dashicons-info';
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