Answers of WordPress 7.1.2 (SQLite, PHP 8.3, `php -S`) to a request while a
must-use plugin with a fatal error at load (`ab_live_missing_function();`)
was in place, measured 06.10.2026: WordPress starts its test report
(wp_start_scraping_edited_file_errors()) only after the must-use and
network-active plugins have loaded, so none of them carries the markers.

- error-page.html: the home page, WP_DEBUG_DISPLAY false.
- error-page-display.html: the same with WP_DEBUG_DISPLAY true (PHP prints the error first).
- error-json.txt: the REST route with `Accept: application/json`, WP_DEBUG_DISPLAY false.
- error-json-display.txt: the same with WP_DEBUG_DISPLAY true (data.error with file and line).
