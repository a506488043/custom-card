<?php
/**
 * 卡片模板 - 安全增强版
 */
// 安全检查：防止直接访问PHP文件
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// 安全处理显示逻辑
$show_title = !empty($data['title']);
$show_image = !empty($data['image']);
$show_desc = !empty($data['description']);
$force_show_url = !$show_title && !$show_desc && !$show_image; // 三者都没有时显示URL
?>
<a href="<?php echo esc_url($data['url']); ?>" 
   class="strict-card" 
   target="_blank"
   rel="noopener noreferrer"
   style="background: #f5f5f5;"
   aria-label="<?php echo $show_title ? esc_attr($data['title']) : esc_attr($data['url']); ?>">

    <div class="strict-inner" >
        <?php if ($show_image) : ?>
            <div class="strict-media">
                <img src="<?php echo esc_url($data['image']); ?>" 
                     class="strict-img" 
                     alt="<?php echo $show_title ? esc_attr($data['title']) : esc_attr('内容卡片'); ?>"
                     loading="lazy"
                     onerror="this.style.display='none'">
            </div>
        <?php endif; ?>

        <div class="strict-content <?php echo !$show_image ? 'strict-content-full' : ''; ?>">
            <?php if ($show_title || $force_show_url) : ?>
                <h3 class="strict-title">
                    <?php echo $show_title ? esc_html($data['title']) : esc_url($data['url']); ?>
                </h3>
            <?php endif; ?>

            <?php if ($show_desc) : ?>
                <div class="strict-desc"><?php echo esc_html($data['description']); ?></div>
            <?php endif; ?>
        </div>

        <span class="strict-overlay"></span>
    </div>
</a>
