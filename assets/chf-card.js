jQuery(document).ready(function ($) {
    // 使用Intersection Observer实现懒加载
    if ('IntersectionObserver' in window) {
        // 创建观察器实例
        const cardObserver = new IntersectionObserver((entries, observer) => {
            entries.forEach(entry => {
                // 当卡片进入视口时
                if (entry.isIntersecting) {
                    const card = $(entry.target);
                    // 加载卡片内容
                    loadCardContent(card);
                    // 停止观察这个卡片
                    observer.unobserve(entry.target);
                }
            });
        }, {
            // 设置卡片进入视口20%时触发加载
            threshold: 0.2,
            // 提前200px加载，提升用户体验
            rootMargin: '0px 0px 200px 0px'
        });

        // 为所有卡片添加观察
        $('.custom-card').each(function() {
            // 添加占位内容
            $(this).html('<div class="custom-card-placeholder">网站卡片将在滚动到此处时加载...</div>');
            // 添加到观察列表
            cardObserver.observe(this);
        });
    } else {
        // 降级处理：不支持IntersectionObserver的浏览器
        // 使用传统的滚动事件实现懒加载
        $('.custom-card').each(function() {
            $(this).html('<div class="custom-card-placeholder">网站卡片将在滚动到此处时加载...</div>');
        });

        // 滚动事件处理函数
        const handleScroll = debounce(function() {
            $('.custom-card').each(function() {
                const card = $(this);
                // 如果卡片已经加载过或正在加载中，跳过
                if (card.data('loaded') || card.data('loading')) {
                    return;
                }
                
                // 检查卡片是否在视口中
                if (isElementInViewport(this)) {
                    loadCardContent(card);
                }
            });
        }, 200);

        // 绑定滚动事件
        $(window).on('scroll', handleScroll);
        // 初始触发一次，处理首屏内容
        setTimeout(handleScroll, 500);
    }

    /**
     * 加载卡片内容
     * @param {jQuery} card 卡片jQuery对象
     */
    function loadCardContent(card) {
        // 标记卡片正在加载中
        card.data('loading', true);
        
        var url = card.data('url'); // 获取 data-url 属性

        // 检查 URL 是否存在且非空
        if (!url || url.trim() === '') {
            console.error('Invalid or empty URL detected in custom-card');
            card.html('<div class="custom-card-error">无效URL: 缺少URL参数</div>');
            card.data('loaded', true);
            return; // 跳过该卡片的处理
        }

        // 安全日志
        console.log('Loading card for: ' + encodeURIComponent(url));

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
            complete: function() {
                // 标记卡片已加载完成
                card.data('loading', false);
                card.data('loaded', true);
            }
        });
    }

    /**
     * 检查元素是否在视口中
     * @param {Element} el DOM元素
     * @return {boolean} 是否在视口中
     */
    function isElementInViewport(el) {
        const rect = el.getBoundingClientRect();
        return (
            rect.top <= (window.innerHeight || document.documentElement.clientHeight) + 200 &&
            rect.bottom >= 0 &&
            rect.left <= (window.innerWidth || document.documentElement.clientWidth) &&
            rect.right >= 0
        );
    }

    /**
     * 防抖函数
     * @param {Function} func 要执行的函数
     * @param {number} wait 等待时间
     * @return {Function} 防抖处理后的函数
     */
    function debounce(func, wait) {
        let timeout;
        return function() {
            const context = this;
            const args = arguments;
            clearTimeout(timeout);
            timeout = setTimeout(() => {
                func.apply(context, args);
            }, wait);
        };
    }
});
