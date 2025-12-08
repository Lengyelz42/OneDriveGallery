import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { useBlockProps } from '@wordpress/block-editor';

registerBlockType('utd-onedrive-gallery/gallery', {
    title: __('OneDrive Gallery', 'utd-onedrive-gallery'),
    icon: 'format-gallery',
    category: 'widgets',
    edit: () => {
        return (
            <div {...useBlockProps()}>
                <p>{__('This block will display your OneDrive gallery. It uses the [onedrive_gallery] shortcode on the frontend.', 'utd-onedrive-gallery')}</p>
            </div>
        );
    },
    save: () => null, // Renders via PHP
});
