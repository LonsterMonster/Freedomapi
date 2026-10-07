# Tests

Run `php tests/security-tests.php` with PHP 8.1+ and PDO SQLite. These tests use WordPress doubles and an in-memory SQLite database. The actual patched Core gateway/runtime/cache/logger and original Core log sanitizer are loaded, so the extension boundary is tested against repository code rather than a newly invented Core implementation. The tests do not boot WordPress or a real MySQL server. Complete the staging browser checklist in SETUP.md before considering a public launch.
