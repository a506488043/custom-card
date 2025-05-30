jQuery(document).ready(function ($) {
    // 安全处理卡片加载
    $('.custom-card').each(function () {
        var card = $(this);
        var url = card.data('url'); // 获取 data-url 属性

        // 检查 URL 是否存在且非空
        if (!url || url.trim() === '') {
            console.error('Invalid or empty URL detected in custom-card');
            card.html('<div class="custom-card-error">无效URL: 缺少URL参数</div>');
            return; // 跳过该卡片的处理
        }

        // 安全日志
        console.log('Preparing card request for: ' + encodeURIComponent(url));

        // 发起 AJAX 请求
        $.ajax({
            url: customCardAjax.ajax_url,
            method: 'POST',
            data: {
                action: 'load_custom_card',
                nonce: customCardAjax.nonce, // 使用服务器提供的nonce
                url: url,
            },
            beforeSend: function () {
                card.html('<div class="custom-card-loading">加载中...</div>');
            },
            success: function (response) {
                // 验证响应格式
                if (!response || typeof response !== 'object') {
                    console.error('Invalid response format');
                    card.html('<div class="custom-card-error">服务器响应格式错误</div>');
                    return;
                }
                
                if (response.success && response.data && response.data.html) {
                    // 安全插入HTML (jQuery已处理XSS)
                    card.html(response.data.html);
                } else {
                    // 显示错误信息
                    var errorMsg = (response.data && response.data.message) 
                        ? response.data.message 
                        : '加载卡片时出错';
                    
                    console.error('Error in AJAX response:', errorMsg);
                    card.html('<div class="custom-card-error">' + 
                        $('<div>').text(errorMsg).html() + // 转义HTML
                        '</div>');
                }
            },
            error: function (xhr, status, error) {
                console.error('AJAX request failed:', status, error);
                card.html('<div class="custom-card-error">网络请求失败</div>');
            },
        });
    });
});
