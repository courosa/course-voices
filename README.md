# Course Voices

A lightweight RSS and Atom aggregator for a class. Created by Dr. Alec Couros.

- Text-first post cards with titles, excerpts, dates, and editable author names.
- Bulk import blog lists from CSV or pasted spreadsheet rows.
- Course and semester management with a shared instructor access key.
- Automatic feed updates, newest-first sorting, search, and contributor filters.
- Browser-local read tracking; students need no accounts.
- PHP and flat files. No WordPress, plugins, or database.

## Requirements

PHP 8.1+ with cURL, DOM, SimpleXML and mbstring; Apache with .htaccess support; HTTPS. The PHP process must be able to write to data/. DNS and outbound HTTP/HTTPS must be available for feeds.

## Install

1. Upload the project files to your web root, including both .htaccess files.
2. Copy data/courses.example.php to data/courses.php.
3. Run `php setup.php` from the command line. Save the generated instructor key privately. Setup refuses to overwrite an existing key.
4. Open `/admin.php?new=1`, enter your key, and create the first course. Select it as the active course.
5. Add student names and blog URLs, then save and check the feeds.
6. Add a hosting cron job that runs every 15 minutes: `*/15 * * * * /usr/local/bin/php /absolute/path/to/refresh.php >/dev/null 2>&1`.

Use your host's actual PHP executable and project path. Readers also trigger a feed check when the saved data is stale. The server throttles ordinary checks to once per 15 minutes. For a deliberate manual refresh from the command line, run `php refresh.php --force`.

## Blog lists and names

Use CSV columns `Name, Blog URL, Feed URL`, tab-separated spreadsheet rows, or one blog URL per line. Feed URL is optional when the blog advertises its feed. Use a category-specific feed when only part of a blog belongs to a course. Display names are stored exactly as entered; use fuller names to distinguish students with matching first names and last initials.

## Storage and privacy

Keep data/ private and back it up regularly. Live rosters, posts, hashes, access keys, and server-specific backups are deliberately excluded from this repository. Data files are protected by a PHP guard and Apache access controls. Never commit production data or instructor keys. Protect admin access with HTTPS. Read status stays in the reader's browser and is not synchronized between devices.

Previously collected posts are retained when a feed fails or drops older items. A feed usually exposes only a limited recent history; this app cannot recover posts never supplied by the feed. Date filters can keep a semester focused on its own work.

## Design and licenses

Dark neutral frame, green masthead, restrained gold accents, readable text-only cards. No University of Regina logos.

Code: MIT License, copyright 2026 Dr. Alec Couros. Original site content: CC BY 4.0. Aggregated student posts retain their authors' rights and original licenses; see CONTENT-LICENSE.md.

This repository contains source code, not automatic deployment credentials. Deploy reviewed changes to your own hosting environment.
