import { __ } from '@wordpress/i18n';
import { useBlockProps } from '@wordpress/block-editor';

export default function Edit() {
  const blockProps = useBlockProps();
  return (
    <div {...blockProps}>
      <p>{__('卡片将在前端展示，当前为编辑器占位符。', 'custom-card')}</p>
    </div>
  );
}