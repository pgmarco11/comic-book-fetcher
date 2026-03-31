jQuery(document).ready(function($){    

    $(document).on('click', '.remove-from-collection', async function (e) {
        e.preventDefault();

        const btn = $(this);
        const postId = btn.data('post-id');

        const confirmRemove = await toastConfirm('Remove this issue from your collection?');
        if (!confirmRemove) return;

        btn.text('Removing...').prop('disabled', true);

        $.post(comicbooks_fetchers_data.ajax_url, {
            action: 'remove_comic_from_collection',
            security: comicbooks_fetchers_data.nonce,
            post_id: postId
        }, function (response) {

            if (response.success) {
                btn.closest('article').fadeOut(300, function () {
                    $(this).remove();
                });
            } else {
                btn.text('Error Removing');
                setTimeout(() => btn.text('Remove from Collection'), 2000);
            }

        }).fail(function () {
            btn.text('Error Removing');
            setTimeout(() => btn.text('Remove from Collection'), 2000);
        }).always(function () {
            btn.prop('disabled', false);
        });
    });
    
});