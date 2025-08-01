jQuery(document).ready(function($) {
    var isDeleting = false;
    var totalToDelete = 0;
    var totalDeleted = 0;
    var totalSkipped = 0;
    var deletedItems = [];
    var skippedItems = [];
    
    // Handle delete button click
    $('#clean-menu-items').on('click', function(e) {
        e.preventDefault();
        
        if (isDeleting) {
            return;
        }
        
        var menuId = $('#menu_id').val();
        var numItems = parseInt($('#num_items').val());
        var skipParents = $('#skip_parents').is(':checked');
        
        // Validation
        if (!menuId) {
            showNotice('error', 'Please select a menu to clean.');
            return;
        }
        
        if (!numItems || numItems < 1 || numItems > 500) {
            showNotice('error', 'Please enter a valid number of items (1-500).');
            return;
        }
        
        // Confirm action
        if (!confirm('Are you sure you want to delete ' + numItems + ' menu items? This action cannot be undone.')) {
            return;
        }
        
        // Start deletion process
        startDeletion(menuId, numItems, skipParents);
    });
    
    function startDeletion(menuId, numItems, skipParents) {
        isDeleting = true;
        totalToDelete = numItems;
        totalDeleted = 0;
        totalSkipped = 0;
        deletedItems = [];
        skippedItems = [];
        
        // Show progress
        $('#menu-cleaner-progress').show();
        $('#progress-total').text(totalToDelete);
        $('#progress-current').text(0);
        $('.progress-fill').css('width', '0%');
        $('#deletion-log').empty();
        
        // Disable form
        $('#menu-cleaner-form input, #menu-cleaner-form select').prop('disabled', true);
        $('.spinner').addClass('is-active');
        
        // Clear previous notices
        $('#menu-cleaner-notices').empty();
        
        // Start batch deletion
        deleteBatch(menuId, 0, skipParents);
    }
    
    function deleteBatch(menuId, offset, skipParents) {
        var batchSize = 10; // Delete 10 items at a time
        var remaining = totalToDelete - totalDeleted;
        
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
                skip_parents: skipParents ? 'true' : 'false'
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
                    if (totalProcessed < totalToDelete && (response.data.count > 0 || response.data.skipped_count > 0)) {
                        setTimeout(function() {
                            deleteBatch(menuId, 0, skipParents);
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
        
        $.ajax({
            url: menu_cleaner_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'menu_cleaner_get_count',
                nonce: menu_cleaner_ajax.nonce,
                menu_id: menuId
            },
            success: function(response) {
                if (response.success) {
                    var option = $('#menu_id option[value="' + menuId + '"]');
                    var menuName = option.text().split('(')[0].trim();
                    var itemText = response.data.count === 1 ? 'item' : 'items';
                    option.text(menuName + ' (' + response.data.count + ' ' + itemText + ')');
                }
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