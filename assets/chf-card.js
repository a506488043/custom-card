jQuery(document).ready(function ($) {
    $('.custom-card').each(function () {
        var card = $(this);
        var url = card.data('url'); // 获取 data-url 属性

        // 检查 URL 是否存在且非空
        if (!url || url.trim() === '') {
            console.error('Invalid or empty URL detected in custom-card:', url);
            card.html('<div class="custom-card-error">Invalid URL: URL is missing.</div>');
            return; // 跳过该卡片的处理
        }

        console.log('Sending AJAX request for URL:', url);

        // 发起 AJAX 请求
        $.ajax({
            url: customCardAjax.ajax_url,
            method: 'POST',
            data: {
                action: 'load_custom_card',
                nonce: customCardAjax.nonce,
                url: url,
            },
            beforeSend: function () {
                card.find('.custom-card-loading').text('Loading...');
            },
            success: function (response) {
                if (response.success) {
                    card.html(response.data.html);
                } else {
                    console.error('Error in AJAX response:', response.data.message);
                    card.html('<div class="custom-card-error">' + response.data.message + '</div>');
                }
            },
            error: function () {
                console.error('AJAX request failed for URL:', url);
                card.html('<div class="custom-card-error">Error loading card.</div>');
            },
        });
    });
});
