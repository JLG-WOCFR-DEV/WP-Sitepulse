# Code Review Notes

## High Priority

- **REST permission filter bypass** – `sitepulse_uptime_rest_schedule_permission_check()` casts the result of the `sitepulse_uptime_rest_schedule_permission` filter to a boolean before returning. Any callback that returns a `WP_Error` (which is the common pattern for REST permissions) will be converted to `true`, unintentionally authorising the request. This breaks expectations for integrators that rely on returning an error to deny access and effectively disables custom authentication failures.【F:sitepulse_FR/modules/uptime_tracker.php†L184-L202】

- **Incident start regression on unsorted logs** – `sitepulse_normalize_uptime_log()` derives `incident_start` values while iterating the raw log in its original order and only sorts entries by timestamp afterwards. When the stored log is newest-first (which can happen with legacy data or external imports that already include timestamps), downtime entries inherit the start time from the next *newer* row instead of the first outage sample. After the final sort this leaves older entries with an `incident_start` that is more recent than the sample timestamp, under-reporting downtime duration in the UI and archives.【F:sitepulse_FR/modules/uptime_tracker.php†L880-L969】

## Suggestions

- Ensure permission callbacks return either `true`, `false` or a `WP_Error` without forcing a cast, e.g. `return apply_filters(...) ?: new WP_Error(...)`.
- Normalise the log order (e.g. sort by timestamp ascending) *before* calculating `incident_start`, or process the data in chronological order to keep the propagation logic correct.
