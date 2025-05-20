<?php
/**
 * 卡片模板 - 严谨版字段显示控制
 */
 if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly
$show_title = !empty($data['title']);
$show_image = !empty($data['image']);
$show_desc = !empty($data['description']);
$force_show_url = !$show_title && !$show_desc && !$show_image; // 三者都没有时显示URL
?>
<a href="<?php echo esc_url($data['url']); ?>" 
   class="strict-card" 
   target="_blank"
   style="background: #f5f5f5;"
   rel="noopener noreferrer"
   aria-label="<?php echo $show_title ? esc_attr($data['title']) : esc_attr($data['url']); ?>">

    <div class="strict-inner" >
        <?php if ($show_image) : ?>
            <div class="strict-media">
                <img src="<?php echo esc_url($data['image']); ?>" 
                     class="strict-img" 
                     alt="<?php echo $show_title ? esc_attr($data['title']) : '内容卡片'; ?>"
                     loading="lazy">
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