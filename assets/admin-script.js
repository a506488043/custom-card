/**
 * 网站卡片插件管理界面JavaScript
 */
jQuery(document).ready(function($) {
    // 编辑按钮点击事件
    $('.edit-card-btn').on('click', function() {
        var $row = $(this).closest('tr');
        var urlHash = $row.data('url-hash');
        var title = $row.find('.hidden-card-data .card-full-title').val();
        var image = $row.find('.hidden-card-data .card-full-image').val();
        var description = $row.find('.hidden-card-data .card-full-description').val();
        
        // 填充表单
        $('#edit-url-hash').val(urlHash);
        $('#edit-title').val(title);
        $('#edit-image').val(image);
        $('#edit-description').val(description);
        
        // 显示图片预览
        if (image) {
            $('#image-preview').attr('src', image).show();
        } else {
            $('#image-preview').hide();
        }
        
        // 显示模态框
        $('#edit-card-modal').show();
    });
    
    // 关闭模态框
    $('.chfm-modal-close').on('click', function() {
        $('#edit-card-modal').hide();
    });
    
    // 点击模态框外部关闭
    $(window).on('click', function(event) {
        if ($(event.target).is('.chfm-modal')) {
            $('#edit-card-modal').hide();
        }
    });
    
    // 图片URL变化时更新预览
    $('#edit-image').on('change input', function() {
        var imageUrl = $(this).val();
        if (imageUrl) {
            $('#image-preview').attr('src', imageUrl).show();
        } else {
            $('#image-preview').hide();
        }
    });
    
    // 提交编辑表单
    $('#edit-card-form').on('submit', function(e) {
        e.preventDefault();
        
        var formData = {
            'action': 'edit_card_cache',
            'nonce': chfmCardAdmin.nonce,
            'url_hash': $('#edit-url-hash').val(),
            'title': $('#edit-title').val(),
            'image': $('#edit-image').val(),
            'description': $('#edit-description').val()
        };
        
        // 显示加载状态
        $('#edit-status').html('<span style="color:#0073aa;">正在保存...</span>').show();
        
        // 发送AJAX请求
        $.post(chfmCardAdmin.ajaxUrl, formData, function(response) {
            if (response.success) {
                $('#edit-status').html('<span style="color:green;">' + chfmCardAdmin.editSuccess + '</span>');
                
                // 更新表格中的数据
                var $row = $('tr[data-url-hash="' + formData.url_hash + '"]');
                $row.find('.card-title-cell').text(formData.title.length > 30 ? formData.title.substring(0, 30) + '...' : formData.title);
                
                if (formData.image) {
                    if ($row.find('.card-image-cell img').length) {
                        $row.find('.card-image-cell img').attr('src', formData.image);
                    } else {
                        $row.find('.card-image-cell').html('<a href="' + formData.image + '" target="_blank"><img src="' + formData.image + '" alt="缩略图" class="card-thumbnail"></a>');
                    }
                } else {
                    $row.find('.card-image-cell').html('<span>无图片</span>');
                }
                
                // 更新隐藏的完整数据
                $row.find('.card-full-title').val(formData.title);
                $row.find('.card-full-image').val(formData.image);
                $row.find('.card-full-description').val(formData.description);
                
                // 3秒后关闭模态框
                setTimeout(function() {
                    $('#edit-card-modal').hide();
                    $('#edit-status').hide();
                }, 3000);
            } else {
                $('#edit-status').html('<span style="color:red;">' + chfmCardAdmin.editError + '</span>');
            }
        }).fail(function() {
            $('#edit-status').html('<span style="color:red;">请求失败，请检查网络连接</span>');
        });
    });
});
