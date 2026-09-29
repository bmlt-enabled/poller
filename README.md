# Poller

A WordPress plugin for anonymous polls. Someone creates a poll, puts the code and QR code on a screen, and everyone votes from their phone. The tally updates live for the whole room.

## Polls

- Text choices, or picture choices
- An optional picture shown with the question
- One choice, or more than one
- Open or closed. Closing stops new votes and leaves the tally up
- After the first vote, the choices stay put so the tally does not change shape

## How people join

- Scan the QR code on the room display
- Or open the join page (`/poller/` on the site) and enter the 6-character code

Codes leave out `0`, `1`, `I`, `L`, and `O`.

## Anonymity

A vote is not tied to an account. The browser keeps a random token in a cookie, and the database stores only a salted hash of that token. Poll pages show counts, not people. There is no address stored on a ballot.

The same browser can change its vote until the poll is closed; that updates the one ballot instead of adding another. Clearing the site’s cookies lets that browser cast another ballot. This is a room poll, not a secret-ballot election.

## Install

Copy this folder to `wp-content/plugins/poller` and activate it. Activation creates the tables and the `/poller/` routes. In wp-admin, open **Poller** and add a poll, then open **Room display**.

Pretty permalinks are the normal way to the join page, the poll, and the room display. With plain permalinks, those same screens are still available from the links in wp-admin.

## Development

Requires PHP 8.0+. No build step.

```bash
php tests/run.php
php bin/preview.php
php -S 127.0.0.1:8899 -t . bin/preview.php
```

The preview server shows the public pages without WordPress.

## License

GPLv2 or later. The QR code generator in `assets/js/qrcode.js` is MIT, copyright Kazuhiko Arase. Atkinson Hyperlegible is used under the SIL Open Font License; see `assets/fonts/OFL.txt`.
