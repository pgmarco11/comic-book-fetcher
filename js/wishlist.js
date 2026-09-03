jQuery(document).ready(function($) {

    const urlParams = new URLSearchParams(window.location.search);
    const urlPublisherId = urlParams.get('publisher_id');

    // Toast notification helper
    function toast(message, color = "#fff") {
        Toastify({
            text: message,
            duration: 3000,
            close: true,
            gravity: "top",
            position: "center",
            backgroundColor: color,
            stopOnFocus: true
        }).showToast();
    }

    // Custom confirm dialog using Toastify and Promise
    function toastConfirm(message) {
        return new Promise((resolve) => {
            const container = document.createElement('div');
            container.style.background = '#fff';
            container.style.color = '#000';
            container.style.padding = '15px';
            container.style.borderRadius = '5px';
            container.style.textAlign = 'center';
            container.style.minWidth = '250px';

            const text = document.createElement('div');
            text.textContent = message;
            container.appendChild(text);

            const buttons = document.createElement('div');
            buttons.style.marginTop = '10px';

            const yesBtn = document.createElement('button');
            yesBtn.textContent = 'Yes';
            yesBtn.style.marginRight = '10px';
            yesBtn.style.padding = '5px 10px';
            yesBtn.style.cursor = 'pointer';

            const noBtn = document.createElement('button');
            noBtn.textContent = 'No';
            noBtn.style.padding = '5px 10px';
            noBtn.style.cursor = 'pointer';

            buttons.appendChild(yesBtn);
            buttons.appendChild(noBtn);
            container.appendChild(buttons);

            const toast = Toastify({
                node: container,
                duration: -1, // persist until button clicked
                close: false,
                gravity: "top",
                position: "center",
                stopOnFocus: true,
            });
            toast.showToast();

            yesBtn.onclick = () => {
                toast.hideToast();
                resolve(true);
            };
            noBtn.onclick = () => {
                toast.hideToast();
                resolve(false);
            };
        });
    }

    // Batch wishlist status check
    function checkWishlistStatusBatch() {
        let itemIds = [];

        $('.add-to-wishlist').each(function () {
            let id = $(this).data('item-id');
            if (id) itemIds.push(id);
        });

        if (itemIds.length === 0) return;

        $.ajax({
            url: wishlist_ajax_obj.ajax_url,
            method: 'POST',
            data: {
                action: 'check_wishlist_status_batch',
                nonce: wishlist_ajax_obj.nonce,
                item_ids: itemIds
            },
            success: function (response) {
                if (response.success && Array.isArray(response.data)) {
                    $('.add-to-wishlist').each(function () {
                        let button = $(this);
                        let id = button.data('item-id').toString();

                        if (response.data.includes(id)) {
                            button.addClass('in-wishlist')
                                .css('background-color', 'red')
                                .text('Remove from Wishlist');
                        }
                    });
                }
            },
            error: function () {
                toast("Wishlist status check failed.", "#f00");
            }
        });
    }

    // Handle add/remove wishlist with async confirmation for removal
    $(document).on('click', '.add-to-wishlist', async function(e) {
        e.preventDefault();

        let button = $(this);
        let isInWishlist = button.hasClass('in-wishlist');
        let data = {
            action: isInWishlist ? 'remove_from_wishlist' : 'add_to_wishlist',
            nonce: wishlist_ajax_obj.nonce,
            item_id: button.data('item-id'),
        };

        if (!isInWishlist) {
            data.type = button.data('type');
            data.title = button.data('title');
            data.item_url = button.data('item-url');
            data.image_url = button.data('image-url');
            data.ebay_id = button.data('ebay-id');
            data.volume = button.data('volume');
        } else {
            const confirmed = await toastConfirm('Are you sure you want to remove this item from your wishlist?');
            if (!confirmed) return;
        }

        $.ajax({
            url: wishlist_ajax_obj.ajax_url,
            method: 'POST',
            data: data,
            success: function(response) {
                if (response.success) {
                    if (isInWishlist) {
                        button.removeClass('in-wishlist')
                            .css('background-color', '')
                            .text('Add to Wishlist');
                        toast("Removed from wishlist!", "#f00");
                    } else {
                        button.addClass('in-wishlist')
                            .css('background-color', 'red')
                            .text('Remove from Wishlist');
                        toast("Added to wishlist!", "#27ae60");
                    }
                } else {
                    toast(response.data || "Wishlist error", "#ffa500");
                }
            },
            error: function() {
                toast("Error processing wishlist request.", "#f00");
            }
        });
    });

    // Remove an item from the [user_wishlist] list.
    $(document).on('click', '.remove-from-wishlist', async function(e) {
            e.preventDefault();
    
            const button = $(this);
            if (button.prop('disabled')) return;
    
            const originalText = button.text();
            button.prop('disabled', true);
    
        try {
            const message = 'Remove this item from your wishlist?';
            const confirmed = typeof Toastify === 'function'
                    ? await toastConfirm(message)
                    : window.confirm(message);
    
                if (!confirmed) return;
    
                button.text('Removing...');
    
                const response = await $.ajax({
                    url: wishlist_ajax_obj.ajax_url,
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'remove_from_wishlist',
                        nonce: wishlist_ajax_obj.nonce,
                        item_id: button.attr('data-item-id')
                    }
                });
    
                if (!response || !response.success) {
                    throw new Error(
                        response && typeof response.data === 'string'
                            ? response.data
                            : 'Could not remove the item. Please try again.'
                    );
                }
    
                const list = button.closest('.user-wishlist');
                button.closest('li').remove();
    
                if (!list.children('li').length) {
                    list.replaceWith(
                        '<p class="has-white-color">Your wishlist is empty.</p>'
                    );
                }
        } catch (error) {
                window.alert(
                    error.message ||
                    'Removal failed. Refresh the page and try again.'
                );
        } finally {
                button.prop('disabled', false).text(originalText);
        }
    });

    // Initial batch check on page load
    checkWishlistStatusBatch();
});
