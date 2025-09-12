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

/**
 * Event Details Block Registration
 *
 * Block for event data storage with InnerBlocks support.
 */
registerBlockType('dm-events/event-details', {
    /**
     * Block edit component
     *
     * @param {Object} props Block properties
     */
    edit: function Edit({ attributes, setAttributes, clientId }) {
        const {
            startDate,
            endDate,
            startTime,
            endTime,
            venue,
            address,
            price,
            ticketUrl,
            showVenue,
            showPrice,
            showTicketLink,
            performer,
            performerType,
            organizer,
            organizerType,
            organizerUrl,
            eventStatus,
            previousStartDate,
            priceCurrency,
            offerAvailability
        } = attributes;

        const blockProps = useBlockProps({
            className: 'dm-event-details-block'
        });

        /**
         * Handle attribute changes
         *
         * @param {string} field Field name
         * @param {string} value Field value
         */
        const handleAttributeChange = (field, value) => {
            setAttributes({ [field]: value });
        };

        /**
         * Handle time field changes
         *
         * @param {string} field Field name
         * @param {string} value Time value
         */
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

                        <div className="event-schema">
                            <h4>{__('Schema Information', 'dm-events')}</h4>
                            <TextControl
                                label={__('Performer/Artist', 'dm-events')}
                                value={performer}
                                onChange={(value) => setAttributes({ performer: value })}
                                help={__('Name of the performing artist or group', 'dm-events')}
                            />
                            <TextControl
                                label={__('Organizer', 'dm-events')}
                                value={organizer}
                                onChange={(value) => setAttributes({ organizer: value })}
                                help={__('Name of the event organizer', 'dm-events')}
                            />
                            <TextControl
                                label={__('Organizer URL', 'dm-events')}
                                value={organizerUrl}
                                onChange={(value) => setAttributes({ organizerUrl: value })}
                                type="url"
                                help={__('Website of the event organizer', 'dm-events')}
                            />
                        </div>

                        <Notice status="info" isDismissible={false}>
                            {__('This block is the primary data store for event information. Changes here are automatically saved to the event.', 'dm-events')}
                        </Notice>
                    </div>
                </div>
        );
    },

    /**
     * Block save component
     * Returns null for dynamic server-side rendering
     */
    save: function Save() {
        return null;
    }
}); 