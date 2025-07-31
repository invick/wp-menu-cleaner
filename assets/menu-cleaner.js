jQuery(document).ready(function($) {
    var isDeleting = false;
    var totalToDelete = 0;
    var totalDeleted = 0;
    var deletedItems = [];
    
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
        deletedItems = [];
        
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
                    
                    // Update progress
                    var progress = (totalDeleted / totalToDelete) * 100;
                    $('#progress-current').text(totalDeleted);
                    $('.progress-fill').css('width', progress + '%');
                    
                    // Add to log
                    if (response.data.deleted.length > 0) {
                        response.data.deleted.forEach(function(item) {
                            deletedItems.push(item.id);
                            var logEntry = $('<div class="log-entry">').html(
                                '<span class="dashicons dashicons-yes"></span> Deleted: ' + 
                                '<strong>' + escapeHtml(item.title) + '</strong> ' +
                                '(ID: ' + item.id + ', Order: ' + item.order + ')'
                            );
                            $('#deletion-log').prepend(logEntry);
                        });
                    }
                    
                    // Continue if more items to delete
                    if (totalDeleted < totalToDelete && response.data.count > 0) {
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
        if (totalDeleted > 0) {
            showNotice('success', 'Successfully deleted ' + totalDeleted + ' menu items.');
            
            // Update menu item count in dropdown
            updateMenuCount($('#menu_id').val());
        } else {
            showNotice('info', 'No items were deleted.');
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