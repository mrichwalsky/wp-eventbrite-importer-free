# Eventbrite Events Sync

WordPress plugin to sync Eventbrite organizer events into a custom post type and render upcoming events with shortcode.

## Features

- Custom post type: `Eventbrite Events` (`eventbriteevent`)
- Admin settings page under `Settings -> Eventbrite Events`
- Eventbrite API token + organizer ID storage
- Manual sync button + hourly cron sync
- Shortcode: `[eb_upcoming_events]` (supports `limit`, example: `[eb_upcoming_events limit="6"]`)
- Frontend styles loaded from `assets/css/eventbrite-events-sync.css` (no inline shortcode CSS)

## Install

1. Copy the `eventbrite-events-sync` folder into `wp-content/plugins/`.
2. Activate `Eventbrite Events Sync` in WP Admin.
3. Go to `Settings -> Eventbrite Events`.
4. Enter:
   - Eventbrite API token
   - Organizer ID (default set to `7915942412`)
   - Events limit
5. Save settings and click `Run Sync Now`.

## Notes

- Synced events are stored as posts in the `eventbriteevent` post type.
- Eventbrite event ID is stored in post meta `_eb_event_id` and used for upserts.

## License

GNU General Public License v3.0 (GPL-3.0).
