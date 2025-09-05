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
            eventsToShow, 
            showPastEvents,
            showFilters,
            showSearch,
            showDateFilter,
            showViewToggle,
            defaultDateRange,
            enablePagination,
            eventsPerPage
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
                        
                        <RangeControl
                            label={__('Number of Events to Show', 'dm-events')}
                            value={eventsToShow}
                            onChange={(value) => setAttributes({ eventsToShow: value })}
                            min={1}
                            max={50}
                        />
                        
                        <ToggleControl
                            label={__('Show Past Events', 'dm-events')}
                            checked={showPastEvents}
                            onChange={(value) => setAttributes({ showPastEvents: value })}
                        />
                    </PanelBody>

                    <PanelBody title={__('Filter Options', 'dm-events')} initialOpen={false}>
                        <ToggleControl
                            label={__('Show Filter Bar', 'dm-events')}
                            checked={showFilters}
                            onChange={(value) => setAttributes({ showFilters: value })}
                        />
                        
                        <ToggleControl
                            label={__('Show Search Box', 'dm-events')}
                            checked={showSearch}
                            onChange={(value) => setAttributes({ showSearch: value })}
                        />
                        
                        <ToggleControl
                            label={__('Show Date Filter', 'dm-events')}
                            checked={showDateFilter}
                            onChange={(value) => setAttributes({ showDateFilter: value })}
                        />
                        
                        <ToggleControl
                            label={__('Show View Toggle', 'dm-events')}
                            checked={showViewToggle}
                            onChange={(value) => setAttributes({ showViewToggle: value })}
                        />
                        
                        <SelectControl
                            label={__('Default Date Range', 'dm-events')}
                            value={defaultDateRange}
                            options={[
                                { label: __('Current Month', 'dm-events'), value: 'current' },
                                { label: __('Next Month', 'dm-events'), value: 'next' },
                                { label: __('Next 3 Months', 'dm-events'), value: 'next3' },
                                { label: __('All Upcoming', 'dm-events'), value: 'upcoming' }
                            ]}
                            onChange={(value) => setAttributes({ defaultDateRange: value })}
                        />
                    </PanelBody>

                    <PanelBody title={__('Pagination', 'dm-events')} initialOpen={false}>
                        <ToggleControl
                            label={__('Enable Pagination', 'dm-events')}
                            checked={enablePagination}
                            onChange={(value) => setAttributes({ enablePagination: value })}
                        />
                        
                        <RangeControl
                            label={__('Events Per Page', 'dm-events')}
                            value={eventsPerPage}
                            onChange={(value) => setAttributes({ eventsPerPage: value })}
                            min={6}
                            max={24}
                        />
                    </PanelBody>
                </InspectorControls>

                <div {...blockProps}>
                    <div className="dm-events-calendar-placeholder">
                        <div className="dm-events-calendar-icon">
                            📅
                        </div>
                        <h3>{__('Data Machine Events Calendar', 'dm-events')}</h3>
                        <p>
                            {__('Displaying', 'dm-events')} {eventsToShow} {__('events in', 'dm-events')} {defaultView} {__('view', 'dm-events')}
                            {showPastEvents && __(', including past events', 'dm-events')}
                        </p>
                        {showFilters && (
                            <div className="dm-events-calendar-filters-preview">
                                <p><strong>{__('Filter Bar Enabled:', 'dm-events')}</strong></p>
                                <ul>
                                    {showSearch && <li>{__('Search', 'dm-events')}</li>}
                                    {showDateFilter && <li>{__('Date Filter', 'dm-events')}</li>}
                                    {showViewToggle && <li>{__('View Toggle', 'dm-events')}</li>}
                                </ul>
                            </div>
                        )}
                    </div>
                </div>
            </>
        );
    }
}); 