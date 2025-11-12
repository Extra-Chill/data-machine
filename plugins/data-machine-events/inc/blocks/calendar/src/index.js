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

registerBlockType('datamachine-events/calendar', {
    edit: function Edit({ attributes, setAttributes }) {
        const { 
            defaultView, 
            showSearch,
            enablePagination
        } = attributes;
        
        const blockProps = useBlockProps({
            className: 'datamachine-events-calendar-editor'
        });

        return (
            <>
                <InspectorControls>
                    <PanelBody title={__('Display Settings', 'datamachine-events')}>
                        <SelectControl
                            label={__('Default View', 'datamachine-events')}
                            value={defaultView}
                            options={[
                                { label: __('List View', 'datamachine-events'), value: 'list' },
                                { label: __('Grid View', 'datamachine-events'), value: 'grid' }
                            ]}
                            onChange={(value) => setAttributes({ defaultView: value })}
                        />
                        
                        <ToggleControl
                            label={__('Show Search Box', 'datamachine-events')}
                            checked={showSearch}
                            onChange={(value) => setAttributes({ showSearch: value })}
                        />
                    </PanelBody>

                    <PanelBody title={__('Pagination', 'datamachine-events')} initialOpen={false}>
                        <ToggleControl
                            label={__('Enable Pagination', 'datamachine-events')}
                            checked={enablePagination}
                            onChange={(value) => setAttributes({ enablePagination: value })}
                        />
                        <p className="description">
                            {__('Events per page is controlled by WordPress Reading Settings (Dashboard → Settings → Reading → "Blog pages show at most").', 'datamachine-events')}
                        </p>
                    </PanelBody>
                </InspectorControls>

                <div {...blockProps}>
                    <div className="datamachine-events-calendar-placeholder">
                        <div className="datamachine-events-calendar-icon">
                            📅
                        </div>
                        <h3>{__('Data Machine Events Calendar', 'datamachine-events')}</h3>
                        <p>
                            {__('Displaying upcoming events in', 'datamachine-events')} {defaultView} {__('view with chronological pagination', 'datamachine-events')}
                        </p>
                        {showSearch && (
                            <div className="datamachine-events-calendar-filters-preview">
                                <p><strong>{__('Search enabled for filtering events', 'datamachine-events')}</strong></p>
                            </div>
                        )}
                    </div>
                </div>
            </>
        );
    }
}); 