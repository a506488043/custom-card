const { registerBlockType } = wp.blocks;
const { TextControl } = wp.components;
const { useBlockProps } = wp.blockEditor;

registerBlockType('custom-card/card-block', {
    edit: ({ attributes, setAttributes }) => {
        const blockProps = useBlockProps();
        return wp.element.createElement(
            "div",
            blockProps,
            wp.element.createElement(TextControl, {
                label: "请输入卡片URL",
                value: attributes.url,
                onChange: (val) => setAttributes({ url: val }),
                placeholder: "https://example.com"
            })
        );
    },
    save: () => {
        // 使用动态渲染，前端由 PHP 输出卡片
        return null;
    }
});
