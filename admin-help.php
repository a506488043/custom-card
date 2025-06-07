<?php
/**
 * 网站卡片插件帮助页面
 */

// 安全检查：防止直接访问PHP文件
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

/**
 * 渲染帮助页面
 */
public function render_help_page() {
    ?>
    <div class="wrap">
        <h1>网站卡片使用帮助</h1>
        
        <!-- 三个方框放在第一行 -->
        <div style="display: flex; gap: 20px; flex-wrap: wrap;">
            <div class="card" style="flex: 1; min-width: 300px;">
                <h2>基本用法</h2>
                
                <h3>短代码用法</h3>
                <p>您可以使用以下短代码在文章或页面中插入网站卡片：</p>
                <pre style="overflow-x: auto; white-space: pre-wrap; word-break: break-word;"><code>[custom_card url="https://example.com"]</code></pre>
                
                <h3>区块编辑器</h3>
                <p>在区块编辑器中，您可以直接添加"网站卡片"区块，然后输入URL。</p>
                
                <h3>高级用法</h3>
                <p>您可以使用以下参数自定义卡片：</p>
                <div style="max-width: 100%; overflow-x: auto;">
                    <pre style="white-space: pre-wrap; word-break: break-word; font-size: 12px;"><code>[custom_card url="https://example.com" title="自定义标题" description="自定义描述" image="https://example.com/image.jpg"]</code></pre>
                </div>
                <p>注意：如果提供了自定义参数，插件将优先使用这些参数，而不是从URL获取的元数据。</p>
            </div>
            
            <div class="card" style="flex: 1; min-width: 300px;">
                <h2>常见问题</h2>
                
                <div class="chfm-faq-item">
                    <h3>为什么我的卡片没有显示图片？</h3>
                    <p>可能的原因：</p>
                    <ul>
                        <li>目标网站没有提供图片元数据（og:image 或类似标签）</li>
                        <li>图片URL无效或无法访问</li>
                        <li>您在设置中禁用了图片显示</li>
                    </ul>
                </div>
                
                <div class="chfm-faq-item">
                    <h3>如何清除卡片缓存？</h3>
                    <p>您可以在"缓存状态"页面中点击"清理所有缓存"按钮，或者在卡片列表中删除特定的缓存项。</p>
                </div>
                
                <div class="chfm-faq-item">
                    <h3>如何自定义卡片样式？</h3>
                    <p>您可以在主题的 style.css 文件中添加自定义CSS来覆盖默认样式。主要的CSS类包括：</p>
                    <ul>
                        <li><code>.strict-card</code> - 卡片容器</li>
                        <li><code>.strict-inner</code> - 卡片内部容器</li>
                        <li><code>.strict-media</code> - 图片容器</li>
                        <li><code>.strict-content</code> - 内容容器</li>
                        <li><code>.strict-title</code> - 标题</li>
                        <li><code>.strict-desc</code> - 描述</li>
                    </ul>
                </div>
            </div>
            
            <div class="card" style="flex: 1; min-width: 300px;">
                <h2>技术支持</h2>
                
                <p>如果您遇到任何问题或需要帮助，请联系插件作者。</p>
                
                <h3>调试信息</h3>
                <p>在寻求支持时，请提供以下信息：</p>
                <ul>
                    <li>WordPress版本：<?php echo get_bloginfo('version'); ?></li>
                    <li>PHP版本：<?php echo phpversion(); ?></li>
                    <li>插件版本：<?php echo Chf_Card_Plugin_Core::PLUGIN_VERSION; ?></li>
                    <li>服务器信息：<?php echo $_SERVER['SERVER_SOFTWARE']; ?></li>
                </ul>
            </div>
        </div>
    </div>
    <?php
}

