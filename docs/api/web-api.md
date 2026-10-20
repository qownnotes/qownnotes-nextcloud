# Web interface endpoints

These internal endpoints require a logged-in user and a CSRF token and are disabled in API-only mode.
They do not change the Notes API or the legacy QOwnNotes API.

## Fetch a link title

`POST /apps/qownnotes/link/title` with JSON `{ "url": "https://example.com/" }` returns
`{ "title": "Example Domain" }`. An empty title means no title was found in the first 256 KiB of HTML.

Only public HTTP(S) URLs without embedded credentials are accepted. Initial URLs and redirects are checked
with Nextcloud's remote host validator; local addresses are rejected even when the instance allows local remote servers.
Requests use Nextcloud's public HTTP client, do not forward the user's credentials, have a 10-second timeout,
follow at most three HTTP(S) redirects, and read at most 256 KiB of the final HTML page.
The endpoint allows 20 requests per user per minute. Invalid URLs return 400; fetch failures or non-HTML responses return 503.
