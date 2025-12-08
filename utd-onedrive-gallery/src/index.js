import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { Fragment } from '@wordpress/element';

registerBlockType('utd-onedrive-gallery/gallery', {
    title: __('OneDrive Gallery', 'utd-onedrive-gallery'),
    icon: 'format-gallery',
    category: 'widgets',
    attributes: {
        sharedLink: {
            type: 'string',
            default: ''
        }
    },
    edit: ({ attributes, setAttributes }) => {
        const blockProps = useBlockProps();
        return (
            <Fragment>
                <InspectorControls>
                    <PanelBody title={__('OneDrive Settings', 'utd-onedrive-gallery')} initialOpen={true}>
                        <TextControl
                            label={__('Shared Folder Link', 'utd-onedrive-gallery')}
                            value={attributes.sharedLink}
                            onChange={(value) => setAttributes({ sharedLink: value })}
                            help={__('Paste a public OneDrive shared folder URL for this block instance.', 'utd-onedrive-gallery')}
                        />
                    </PanelBody>
                </InspectorControls>
                <div {...blockProps}>
                    <p>{__('OneDrive Gallery — using the shared link you set in block settings.', 'utd-onedrive-gallery')}</p>
                    {attributes.sharedLink ? <p><strong>{__('Link:')}</strong> {attributes.sharedLink}</p> : <p>{__('No shared link set for this block instance — it will use the global plugin setting if available.', 'utd-onedrive-gallery')}</p>}
                </div>
            </Fragment>
        );
    },
    save: () => null,
});
