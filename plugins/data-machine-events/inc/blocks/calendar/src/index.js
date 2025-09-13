import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { 
    InspectorControls, 
    useBlockProps 
} from '@wordpress/block-editor';
import { 
    PanelBody, 
    SelectControl, 
    RangeControl, 
    ToggleControl 
} from '@wordpress/components';

registerBlockType('dm-events/calendar', {
    edit: function Edit({ attributes, setAttributes }) {
        const { 
            defaultView, 
            showSearch,
            enablePagination
        } = attributes;
        
        const blockProps = useBlockProps({
            className: 'dm-events-calendar-editor'
        });

        return (
            <>
                <InspectorControls>
                    <PanelBody title={__('Display Settings', 'dm-events')}>
                        <SelectControl
                            label={__('Default View', 'dm-events')}
                            value={defaultView}
                            options={[
                                { label: __('List View', 'dm-events'), value: 'list' },
                                { label: __('Grid View', 'dm-events'), value: 'grid' }
                            ]}
                            onChange={(value) => setAttributes({ defaultView: value })}
                        />
                        
                        <ToggleControl
                            label={__('Show Search Box', 'dm-events')}
                            checked={showSearch}
                            onChange={(value) => setAttributes({ showSearch: value })}
                        />
                    </PanelBody>

                    <PanelBody title={__('Pagination', 'dm-events')} initialOpen={false}>
                        <ToggleControl
                            label={__('Enable Pagination', 'dm-events')}
                            checked={enablePagination}
                            onChange={(value) => setAttributes({ enablePagination: value })}
                        />
                        <p className="description">
                            {__('Events per page is controlled by WordPress Reading Settings (Dashboard → Settings → Reading → "Blog pages show at most").', 'dm-events')}
                        </p>
                    </PanelBody>
                </InspectorControls>

                <div {...blockProps}>
                    <div className="dm-events-calendar-placeholder">
                        <div className="dm-events-calendar-icon">
                            📅
                        </div>
                        <h3>{__('Data Machine Events Calendar', 'dm-events')}</h3>
                        <p>
                            {__('Displaying upcoming events in', 'dm-events')} {defaultView} {__('view with chronological pagination', 'dm-events')}
                        </p>
                        {showSearch && (
                            <div className="dm-events-calendar-filters-preview">
                                <p><strong>{__('Search enabled for filtering events', 'dm-events')}</strong></p>
                            </div>
                        )}
                    </div>
                </div>
            </>
        );
    }
}); 