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

registerBlockType('chill-events/calendar', {
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
            className: 'chill-events-calendar-editor'
        });

        return (
            <>
                <InspectorControls>
                    <PanelBody title={__('Display Settings', 'chill-events')}>
                        <SelectControl
                            label={__('Default View', 'chill-events')}
                            value={defaultView}
                            options={[
                                { label: __('List View', 'chill-events'), value: 'list' },
                                { label: __('Grid View', 'chill-events'), value: 'grid' }
                            ]}
                            onChange={(value) => setAttributes({ defaultView: value })}
                        />
                        
                        <RangeControl
                            label={__('Number of Events to Show', 'chill-events')}
                            value={eventsToShow}
                            onChange={(value) => setAttributes({ eventsToShow: value })}
                            min={1}
                            max={50}
                        />
                        
                        <ToggleControl
                            label={__('Show Past Events', 'chill-events')}
                            checked={showPastEvents}
                            onChange={(value) => setAttributes({ showPastEvents: value })}
                        />
                    </PanelBody>

                    <PanelBody title={__('Filter Options', 'chill-events')} initialOpen={false}>
                        <ToggleControl
                            label={__('Show Filter Bar', 'chill-events')}
                            checked={showFilters}
                            onChange={(value) => setAttributes({ showFilters: value })}
                        />
                        
                        <ToggleControl
                            label={__('Show Search Box', 'chill-events')}
                            checked={showSearch}
                            onChange={(value) => setAttributes({ showSearch: value })}
                        />
                        
                        <ToggleControl
                            label={__('Show Date Filter', 'chill-events')}
                            checked={showDateFilter}
                            onChange={(value) => setAttributes({ showDateFilter: value })}
                        />
                        
                        <ToggleControl
                            label={__('Show View Toggle', 'chill-events')}
                            checked={showViewToggle}
                            onChange={(value) => setAttributes({ showViewToggle: value })}
                        />
                        
                        <SelectControl
                            label={__('Default Date Range', 'chill-events')}
                            value={defaultDateRange}
                            options={[
                                { label: __('Current Month', 'chill-events'), value: 'current' },
                                { label: __('Next Month', 'chill-events'), value: 'next' },
                                { label: __('Next 3 Months', 'chill-events'), value: 'next3' },
                                { label: __('All Upcoming', 'chill-events'), value: 'upcoming' }
                            ]}
                            onChange={(value) => setAttributes({ defaultDateRange: value })}
                        />
                    </PanelBody>

                    <PanelBody title={__('Pagination', 'chill-events')} initialOpen={false}>
                        <ToggleControl
                            label={__('Enable Pagination', 'chill-events')}
                            checked={enablePagination}
                            onChange={(value) => setAttributes({ enablePagination: value })}
                        />
                        
                        <RangeControl
                            label={__('Events Per Page', 'chill-events')}
                            value={eventsPerPage}
                            onChange={(value) => setAttributes({ eventsPerPage: value })}
                            min={6}
                            max={24}
                        />
                    </PanelBody>
                </InspectorControls>

                <div {...blockProps}>
                    <div className="chill-events-calendar-placeholder">
                        <div className="chill-events-calendar-icon">
                            📅
                        </div>
                        <h3>{__('Chill Events Calendar', 'chill-events')}</h3>
                        <p>
                            {__('Displaying', 'chill-events')} {eventsToShow} {__('events in', 'chill-events')} {defaultView} {__('view', 'chill-events')}
                            {showPastEvents && __(', including past events', 'chill-events')}
                        </p>
                        {showFilters && (
                            <div className="chill-events-calendar-filters-preview">
                                <p><strong>{__('Filter Bar Enabled:', 'chill-events')}</strong></p>
                                <ul>
                                    {showSearch && <li>{__('Search', 'chill-events')}</li>}
                                    {showDateFilter && <li>{__('Date Filter', 'chill-events')}</li>}
                                    {showViewToggle && <li>{__('View Toggle', 'chill-events')}</li>}
                                </ul>
                            </div>
                        )}
                    </div>
                </div>
            </>
        );
    }
}); 