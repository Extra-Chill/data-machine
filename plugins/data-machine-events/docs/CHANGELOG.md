# Changelog

All notable changes to Data Machine Events will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.1] - 2025-11-20

### Added
- **CHANGELOG.md**: Introduced changelog documentation for version tracking
- **Version bump**: Updated from 0.1.0 to 0.1.1 to mark changelog introduction

## [0.1.0] - 2025-11-XX

### Added
- **Initial Release**: Complete events management plugin with block-first architecture
- **Event Details Block**: Rich event data storage with 15+ attributes (dates, times, venue, pricing, performers, organizers)
- **Calendar Block**: Advanced event display with multiple templates and filtering
- **Venue Taxonomy**: Complete venue management with 9 meta fields and admin interface
- **AI Integration**: Data Machine pipeline support for automated event imports
- **REST API Endpoints**: Full REST API implementation under `datamachine/v1/events/*`
- **Import Handlers**: Ticketmaster, Dice FM, Google Calendar, and Universal Web Scraper integrations
- **Schema.org Support**: Google Event JSON-LD structured data generation
- **Map Integration**: Leaflet.js venue mapping with 5 free tile layer options
- **Admin Interface**: Comprehensive settings page with display preferences
- **Template System**: Modular calendar display with 7 specialized templates
- **Performance Optimization**: Background sync to meta fields for efficient queries