=== Custom Card ===

Contributors: a506488043

Donate link: https://www.saiita.top

Tags: Custom Card

Requires at least: 6.7.1

Requires PHP: 8.1.30

Stable tag: 1.0

Tested up to: 6.7.1


License: GPLv2 or later License URI: http://www.gnu.org/licenses/gpl-2.0.html


== Description ==

Custom Card Plugin 是一个用于在 WordPress 网站中快速添加美观的卡片布局的插件。支持灵活的内容展示,包括图片、标题、描述文字和链接,适用于博客文章中插入网站的场景。

== 功能 ==

- 响应式布局,适配桌面端和移动端。
- 支持自定义图片、标题、描述文字和链接。
- 提供简单易用的短代码,快速插入卡片。
- 灵活的自定义样式,适合不同设计需求。

== 安装 ==

1. 下载插件的 ZIP 文件。
2. 登录 WordPress 后台,导航到 **插件 > 安装插件**。
3. 点击 **上传插件** 按钮,选择 ZIP 文件并点击 **安装**。
4. 安装完成后,点击 **启用插件**。
5. 插件启用后,可以通过短代码或小工具在页面中添加自定义卡片。

== 使用方法 ==

### # 使用方法

### 使用短代码

使用

1. 在编辑器中插入以下短代码:

   ```html
   [custom_card title="卡片标题" description="卡片描述内容" image_url="https://example.com/image.jpg" link_url="https://example.com"]
   ```

2. 替换短代码中的参数值,生成您需要的卡片内容。

### 短代码参数

- `title`:卡片的标题(必填)。
- `description`:卡片的描述内容(可选)。
- `image_url`:卡片的图片链接地址(必填)。
- `link_url`:卡片的跳转链接(可选)。

### 自定义样式

1. 插件默认样式文件位于 `/assets/css/style.css`。
2. 您可以通过 WordPress 外观设置自定义 CSS,覆盖默认样式。

== 常见问题 ==

### 1. 插件安装后没有效果?

请确认以下几点:

- 插件是否正确启用。
- 是否在页面或文章中插入了短代码。
- 图片链接是否有效。

### 2. 如何修改卡片的样式?

您可以在 WordPress 外观中的 **自定义 CSS** 中添加样式,也可以直接编辑插件的 `style.css` 文件。

### 3. 插件支持多语言吗?

当前版本仅支持英文和简体中文,后续版本将支持更多语言。

== 更新日志 ==

### 1.0.0

- 初始版本发布。
- 实现卡片的基本布局和样式。

== 声明 == 本插件基于 GPL v2 许可证发布,您可以自由使用、修改和分发。

== 联系方式 == 如需支持或报告问题,请联系:

- 作者网站: https://www.saita.top
- 支持邮箱: chenghoufeng@saiita.top