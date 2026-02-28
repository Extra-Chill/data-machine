/**
 * WordPress dependencies
 */
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

const ALLOWED_BLOCKS = [
    'core/paragraph',
    'core/heading',
    'core/image',
    'core/list',
    'core/quote',
    'core/gallery',
    'core/video',
    'core/audio',
    'core/embed',
    'core/separator',
    'core/spacer',
    'core/columns',
    'core/column',
    'core/group',
    'core/freeform',
    'core/html'
];

const DESCRIPTION_TEMPLATE = [
    ['core/paragraph', { placeholder: __('Add event description…', 'data-machine-events') }]
];

/**
 * Event Details Block Registration
 *
 * Block for event data storage with InnerBlocks support.
 */
registerBlockType('data-machine-events/event-details', {
    /**
     * Block editor component with comprehensive event data fields and InnerBlocks support
     * @param root0
     * @param root0.attributes
     * @param root0.setAttributes
     * @param root0.clientId
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
            className: 'datamachine-event-details-block'
        });

        const handleAttributeChange = (field, value) => {
            setAttributes({ [field]: value });
        };

        const handleTimeChange = (field, value) => {
            setAttributes({ [field]: value });
        };

        return (
            <div {...blockProps}>
                    <div className="datamachine-event-details-editor">
                        <div className="event-description-area">
                            <h4>{__('Event Description', 'data-machine-events')}</h4>
                            <div className="event-description-inner">
                                <InnerBlocks
                                    allowedBlocks={ALLOWED_BLOCKS}
                                    template={DESCRIPTION_TEMPLATE}
                                    templateLock={false}
                                />
                            </div>
                        </div>
                        
                        <div className="event-dates">
                            <h4>{__('Event Dates & Times', 'data-machine-events')}</h4>
                            <div className="date-time-grid">
                                <div className="date-time-field">
                                    <label>{__('Start Date', 'data-machine-events')}</label>
                                    <input
                                        type="date"
                                        value={startDate}
                                        onChange={(e) => handleAttributeChange('startDate', e.target.value)}
                                    />
                                </div>
                                <div className="date-time-field">
                                    <label>{__('Start Time', 'data-machine-events')}</label>
                                    <input
                                        type="time"
                                        value={startTime}
                                        onChange={(e) => handleTimeChange('startTime', e.target.value)}
                                    />
                                </div>
                                <div className="date-time-field">
                                    <label>{__('End Date', 'data-machine-events')}</label>
                                    <input
                                        type="date"
                                        value={endDate}
                                        onChange={(e) => handleAttributeChange('endDate', e.target.value)}
                                    />
                                </div>
                                <div className="date-time-field">
                                    <label>{__('End Time', 'data-machine-events')}</label>
                                    <input
                                        type="time"
                                        value={endTime}
                                        onChange={(e) => handleTimeChange('endTime', e.target.value)}
                                    />
                                </div>
                            </div>
                        </div>

                        <div className="event-location">
                            <h4>{__('Location', 'data-machine-events')}</h4>
                            <TextControl
                                label={__('Venue', 'data-machine-events')}
                                value={venue}
                                onChange={(value) => setAttributes({ venue: value })}
                            />
                            <TextControl
                                label={__('Address', 'data-machine-events')}
                                value={address}
                                onChange={(value) => setAttributes({ address: value })}
                            />
                        </div>

                        <div className="event-details">
                            <h4>{__('Event Details', 'data-machine-events')}</h4>
                            <TextControl
                                label={__('Price', 'data-machine-events')}
                                value={price}
                                onChange={(value) => handleAttributeChange('price', value)}
                            />
                            <TextControl
                                label={__('Ticket URL', 'data-machine-events')}
                                value={ticketUrl}
                                onChange={(value) => handleAttributeChange('ticketUrl', value)}
                                type="url"
                            />
                        </div>

                        <div className="event-schema">
                            <h4>{__('Schema Information', 'data-machine-events')}</h4>
                            <TextControl
                                label={__('Performer/Artist', 'data-machine-events')}
                                value={performer}
                                onChange={(value) => setAttributes({ performer: value })}
                                help={__('Name of the performing artist or group', 'data-machine-events')}
                            />
                            <TextControl
                                label={__('Organizer', 'data-machine-events')}
                                value={organizer}
                                onChange={(value) => setAttributes({ organizer: value })}
                                help={__('Name of the event organizer', 'data-machine-events')}
                            />
                            <TextControl
                                label={__('Organizer URL', 'data-machine-events')}
                                value={organizerUrl}
                                onChange={(value) => setAttributes({ organizerUrl: value })}
                                type="url"
                                help={__('Website of the event organizer', 'data-machine-events')}
                            />
                        </div>

                        <Notice status="info" isDismissible={false}>
                            {__('This block is the primary data store for event information. Changes here are automatically saved to the event.', 'data-machine-events')}
                        </Notice>
                    </div>
                </div>
        );
    },

    /**
     * Block save component
     * Returns InnerBlocks.Content for persistence with server-side rendering
     */
    save: function Save() {
        const blockProps = useBlockProps.save();
        return (
            <div {...blockProps}>
                <InnerBlocks.Content />
            </div>
        );
    }
}); 