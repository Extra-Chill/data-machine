<?php
// Basic unit tests for GoogleCalendarUtils

use DataMachineEvents\Steps\EventImport\Handlers\GoogleCalendar\GoogleCalendarUtils;

class GoogleCalendarUtilsTest extends \PHPUnit\Framework\TestCase {

    public function test_generate_ics_url_from_calendar_id() {
        $id = 'starlightchscalendar@gmail.com';
        $expected = 'https://calendar.google.com/calendar/ical/starlightchscalendar%40gmail.com/public/basic.ics';
        $this->assertEquals($expected, GoogleCalendarUtils::generate_ics_url_from_calendar_id($id));

        $group_id = 'en.uk#holiday@group.v.calendar.google.com';
        $expected2 = 'https://calendar.google.com/calendar/ical/en.uk%23holiday%40group.v.calendar.google.com/public/basic.ics';
        $this->assertEquals($expected2, GoogleCalendarUtils::generate_ics_url_from_calendar_id($group_id));
    }

    public function test_is_calendar_url_like() {
        $this->assertTrue(GoogleCalendarUtils::is_calendar_url_like('https://calendar.google.com/calendar/ical/example@gmail.com/public/basic.ics'));
        $this->assertTrue(GoogleCalendarUtils::is_calendar_url_like('https://example.com/my.ics'));
        $this->assertTrue(GoogleCalendarUtils::is_calendar_url_like('calendar.google.com/feed')); // partial string
        $this->assertFalse(GoogleCalendarUtils::is_calendar_url_like('just-a-random-id@example.com'));
    }

    public function test_resolve_calendar_url() {
        $config = ['calendar_url' => 'https://calendar.google.com/calendar/ical/example@gmail.com/public/basic.ics'];
        $this->assertEquals($config['calendar_url'], GoogleCalendarUtils::resolve_calendar_url($config));

        $config2 = ['calendar_id' => 'starlightchscalendar@gmail.com'];
        $this->assertEquals('https://calendar.google.com/calendar/ical/starlightchscalendar%40gmail.com/public/basic.ics', GoogleCalendarUtils::resolve_calendar_url($config2));

        $config3 = [];
        $this->assertNull(GoogleCalendarUtils::resolve_calendar_url($config3));
    }
}
