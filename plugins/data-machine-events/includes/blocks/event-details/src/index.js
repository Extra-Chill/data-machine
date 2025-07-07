import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import { 
    InspectorControls, 
    useBlockProps,
    RichText,
    PanelColorSettings,
    getColorClassName
} from '@wordpress/block-editor';
import { 
    PanelBody, 
    TextControl, 
    ToggleControl, 
    SelectControl,
    DateTimePicker,
    Button,
    Notice
} from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useState, useEffect } from '@wordpress/element';

registerBlockType('chill-events/event-details', {
    edit: function Edit({ attributes, setAttributes, clientId }) {
        // Check if we're in the correct post type
        const { postType } = useSelect(select => ({
            postType: select('core/editor').getCurrentPostType()
        }), []);
        
        // If not in chill_events post type, show a message
        if (postType !== 'chill_events') {
            return (
                <div className="chill-event-details-block-error">
                    <p>{__('Event Details blocks can only be used in Events posts.', 'chill-events')}</p>
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
            description,
            showVenue,
            showArtist,
            showPrice,
            showTicketLink,
            layout
        } = attributes;

        const blockProps = useBlockProps({
            className: 'chill-event-details-block'
        });

        // Get post meta for initial values (only on first load)
        const postId = useSelect(select => select('core/editor').getCurrentPostId(), []);
        const [metaLoaded, setMetaLoaded] = useState(false);

        useEffect(() => {
            if (postId && !metaLoaded) {
                // Load existing meta values and populate block attributes
                wp.apiFetch({ path: `/wp/v2/chill_events/${postId}` }).then(post => {
                    if (post.meta) {
                        const newAttributes = {};
                        
                        // Only set attributes if they're empty (first load)
                        if (!startDate && post.meta._chill_event_start_date) {
                            newAttributes.startDate = post.meta._chill_event_start_date;
                        }
                        if (!endDate && post.meta._chill_event_end_date) {
                            newAttributes.endDate = post.meta._chill_event_end_date;
                        }
                        if (!artist && post.meta._chill_event_artist_name) {
                            newAttributes.artist = post.meta._chill_event_artist_name;
                        }
                        if (!price && post.meta._chill_event_price) {
                            newAttributes.price = post.meta._chill_event_price;
                        }
                        if (!ticketUrl && post.meta._chill_event_ticket_url) {
                            newAttributes.ticketUrl = post.meta._chill_event_ticket_url;
                        }
                        
                        if (Object.keys(newAttributes).length > 0) {
                            setAttributes(newAttributes);
                        }
                    }
                    setMetaLoaded(true);
                }).catch(error => {
                    console.error('Failed to load meta:', error);
                    setMetaLoaded(true);
                });
            }
        }, [postId, metaLoaded]);

        // Sync block attributes to post meta when they change
        const updateMeta = (key, value) => {
            if (postId) {
                wp.apiFetch({
                    path: `/wp/v2/chill_events/${postId}`,
                    method: 'POST',
                    data: {
                        meta: {
                            [key]: value
                        }
                    }
                }).catch(error => {
                    console.error('Failed to update meta:', error);
                });
            }
        };

        const handleAttributeChange = (field, value) => {
            setAttributes({ [field]: value });
            
            // Sync to post meta for compatibility
            const metaMapping = {
                startDate: '_chill_event_start_date',
                endDate: '_chill_event_end_date',
                artist: '_chill_event_artist_name',
                price: '_chill_event_price',
                ticketUrl: '_chill_event_ticket_url'
            };
            
            if (metaMapping[field]) {
                updateMeta(metaMapping[field], value);
            }
        };

        return (
            <>
                <InspectorControls>
                    <PanelBody title={__('Display Settings', 'chill-events')}>
                        <ToggleControl
                            label={__('Show Venue', 'chill-events')}
                            checked={showVenue}
                            onChange={(value) => setAttributes({ showVenue: value })}
                        />
                        <ToggleControl
                            label={__('Show Artist', 'chill-events')}
                            checked={showArtist}
                            onChange={(value) => setAttributes({ showArtist: value })}
                        />
                        <ToggleControl
                            label={__('Show Price', 'chill-events')}
                            checked={showPrice}
                            onChange={(value) => setAttributes({ showPrice: value })}
                        />
                        <ToggleControl
                            label={__('Show Ticket Link', 'chill-events')}
                            checked={showTicketLink}
                            onChange={(value) => setAttributes({ showTicketLink: value })}
                        />
                        <SelectControl
                            label={__('Layout', 'chill-events')}
                            value={layout}
                            options={[
                                { label: __('Compact', 'chill-events'), value: 'compact' },
                                { label: __('Detailed', 'chill-events'), value: 'detailed' },
                                { label: __('Minimal', 'chill-events'), value: 'minimal' }
                            ]}
                            onChange={(value) => setAttributes({ layout: value })}
                        />
                    </PanelBody>
                </InspectorControls>

                <div {...blockProps}>
                    <div className="chill-event-details-editor">
                        <div className="event-dates">
                            <h4>{__('Event Dates & Times', 'chill-events')}</h4>
                            <div className="date-time-grid">
                                <div className="date-time-field">
                                    <label>{__('Start Date', 'chill-events')}</label>
                                    <input
                                        type="date"
                                        value={startDate}
                                        onChange={(e) => handleAttributeChange('startDate', e.target.value)}
                                    />
                                </div>
                                <div className="date-time-field">
                                    <label>{__('Start Time', 'chill-events')}</label>
                                    <input
                                        type="time"
                                        value={startTime}
                                        onChange={(e) => setAttributes({ startTime: e.target.value })}
                                    />
                                </div>
                                <div className="date-time-field">
                                    <label>{__('End Date', 'chill-events')}</label>
                                    <input
                                        type="date"
                                        value={endDate}
                                        onChange={(e) => handleAttributeChange('endDate', e.target.value)}
                                    />
                                </div>
                                <div className="date-time-field">
                                    <label>{__('End Time', 'chill-events')}</label>
                                    <input
                                        type="time"
                                        value={endTime}
                                        onChange={(e) => setAttributes({ endTime: e.target.value })}
                                    />
                                </div>
                            </div>
                        </div>

                        <div className="event-location">
                            <h4>{__('Location', 'chill-events')}</h4>
                            <TextControl
                                label={__('Venue', 'chill-events')}
                                value={venue}
                                onChange={(value) => setAttributes({ venue: value })}
                            />
                            <TextControl
                                label={__('Address', 'chill-events')}
                                value={address}
                                onChange={(value) => setAttributes({ address: value })}
                            />
                        </div>

                        <div className="event-details">
                            <h4>{__('Event Details', 'chill-events')}</h4>
                            <TextControl
                                label={__('Artist/Performer', 'chill-events')}
                                value={artist}
                                onChange={(value) => handleAttributeChange('artist', value)}
                            />
                            <TextControl
                                label={__('Price', 'chill-events')}
                                value={price}
                                onChange={(value) => handleAttributeChange('price', value)}
                            />
                            <TextControl
                                label={__('Ticket URL', 'chill-events')}
                                value={ticketUrl}
                                onChange={(value) => handleAttributeChange('ticketUrl', value)}
                                type="url"
                            />
                        </div>

                        <div className="event-description">
                            <h4>{__('Event Description', 'chill-events')}</h4>
                            <TextControl
                                label={__('Description', 'chill-events')}
                                value={description}
                                onChange={(value) => setAttributes({ description: value })}
                                multiline
                            />
                        </div>

                        <Notice status="info" isDismissible={false}>
                            {__('This block is the primary data store for event information. Changes here are automatically saved to the event.', 'chill-events')}
                        </Notice>
                    </div>
                </div>
            </>
        );
    },

    save: function Save() {
        // Return null for dynamic blocks - content is rendered by PHP
        return null;
    }
}); 