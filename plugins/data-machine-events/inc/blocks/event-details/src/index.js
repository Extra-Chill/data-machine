import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { 
    useBlockProps,
    InnerBlocks
} from '@wordpress/block-editor';
import { 
    TextControl, 
    Notice
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useState, useEffect } from '@wordpress/element';

registerBlockType('dm-events/event-details', {
    edit: function Edit({ attributes, setAttributes, clientId }) {
        // Check if we're in the correct post type
        const { postType } = useSelect(select => ({
            postType: select('core/editor').getCurrentPostType()
        }), []);
        
        // If not in dm_events post type, show a message
        if (postType !== 'dm_events') {
            return (
                <div className="dm-event-details-block-error">
                    <p>{__('Event Details blocks can only be used in Events posts.', 'dm-events')}</p>
                </div>
            );
        }
        const {
            startDate,
            endDate,
            startTime,
            endTime,
            venue,
            address,
            artist,
            price,
            ticketUrl,
            showVenue,
            showArtist,
            showPrice,
            showTicketLink
        } = attributes;

        const blockProps = useBlockProps({
            className: 'dm-event-details-block'
        });

        // Block-first architecture - all data is stored in block attributes

        // Block-first architecture - no need to load from meta, block attributes are the primary data store

        // Block-first architecture - block attributes are the primary data store
        const handleAttributeChange = (field, value) => {
            setAttributes({ [field]: value });
        };

        const handleTimeChange = (field, value) => {
            setAttributes({ [field]: value });
        };

        return (
            <div {...blockProps}>
                    <div className="dm-event-details-editor">
                        <div className="event-description-area">
                            <h4>{__('Event Description', 'dm-events')}</h4>
                            <div className="event-description-inner">
                                <InnerBlocks
                                    allowedBlocks={['core/paragraph', 'core/heading', 'core/image', 'core/list', 'core/quote']}
                                    template={[
                                        ['core/paragraph', { placeholder: __('Add event description...', 'dm-events') }]
                                    ]}
                                    templateLock={false}
                                />
                            </div>
                        </div>
                        
                        <div className="event-dates">
                            <h4>{__('Event Dates & Times', 'dm-events')}</h4>
                            <div className="date-time-grid">
                                <div className="date-time-field">
                                    <label>{__('Start Date', 'dm-events')}</label>
                                    <input
                                        type="date"
                                        value={startDate}
                                        onChange={(e) => handleAttributeChange('startDate', e.target.value)}
                                    />
                                </div>
                                <div className="date-time-field">
                                    <label>{__('Start Time', 'dm-events')}</label>
                                    <input
                                        type="time"
                                        value={startTime}
                                        onChange={(e) => handleTimeChange('startTime', e.target.value)}
                                    />
                                </div>
                                <div className="date-time-field">
                                    <label>{__('End Date', 'dm-events')}</label>
                                    <input
                                        type="date"
                                        value={endDate}
                                        onChange={(e) => handleAttributeChange('endDate', e.target.value)}
                                    />
                                </div>
                                <div className="date-time-field">
                                    <label>{__('End Time', 'dm-events')}</label>
                                    <input
                                        type="time"
                                        value={endTime}
                                        onChange={(e) => handleTimeChange('endTime', e.target.value)}
                                    />
                                </div>
                            </div>
                        </div>

                        <div className="event-location">
                            <h4>{__('Location', 'dm-events')}</h4>
                            <TextControl
                                label={__('Venue', 'dm-events')}
                                value={venue}
                                onChange={(value) => setAttributes({ venue: value })}
                            />
                            <TextControl
                                label={__('Address', 'dm-events')}
                                value={address}
                                onChange={(value) => setAttributes({ address: value })}
                            />
                        </div>

                        <div className="event-details">
                            <h4>{__('Event Details', 'dm-events')}</h4>
                            <TextControl
                                label={__('Artist/Performer', 'dm-events')}
                                value={artist}
                                onChange={(value) => handleAttributeChange('artist', value)}
                            />
                            <TextControl
                                label={__('Price', 'dm-events')}
                                value={price}
                                onChange={(value) => handleAttributeChange('price', value)}
                            />
                            <TextControl
                                label={__('Ticket URL', 'dm-events')}
                                value={ticketUrl}
                                onChange={(value) => handleAttributeChange('ticketUrl', value)}
                                type="url"
                            />
                        </div>



                        <Notice status="info" isDismissible={false}>
                            {__('This block is the primary data store for event information. Changes here are automatically saved to the event.', 'dm-events')}
                        </Notice>
                    </div>
                </div>
        );
    },

    save: function Save() {
        // For blocks with InnerBlocks, we need to save the InnerBlocks content
        return <InnerBlocks.Content />;
    }
}); 