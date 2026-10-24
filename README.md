# Poller

A WordPress plugin for anonymous polls. Someone creates a poll, puts the code and QR code on a screen, and everyone votes from their phone. The tally updates live for the whole room.

## Polls

- Text choices, or picture choices
- An optional picture shown with the question
- One choice, or more than one
- Dot voting: each person spreads a set number of dots across a long list (up to 100 items). Stacking dots on one item and "use every dot" are per-poll settings. The tally ranks items by dots
- Word cloud: each person types up to N words or short phrases (1–10, default 3) and can change them until the poll closes. The room display draws a live cloud, sized by how many people entered each word; the results page adds a ranked list. "Hope", "hope!" and " HOPE " count as one word, shown with the most common spelling. Entries are up to 24 characters and 3 words
- Paste a list to add one choice per line
- Open or closed. Closing stops new votes and leaves the tally up
- After the first vote, the choices and voting rules stay put so the tally does not change shape

## How people join

- Scan the QR code on the room display
- Or open the join page (`/poller/` on the site) and enter the 6-character code

Codes leave out `0`, `1`, `I`, `L`, and `O`.

## Anonymity

A vote is not tied to an account. The browser keeps a random token in a cookie, and the database stores only a salted hash of that token. Poll pages show counts, not people. There is no address stored on a ballot.

Word cloud answers are free text, so they are only shown in aggregate: each word and how many ballots entered it, never which ballot. A browser sees its own entries back so it can edit them. Voter text is stored in its own table, apart from the admin's choices. Because the room display is public, an admin can hide a word (from the results on the poll page, or the Words list on the poll's edit screen); hidden words drop out of the cloud and the results on the next refresh.

The same browser can change its vote until the poll is closed; that updates the one ballot instead of adding another. Clearing the site’s cookies lets that browser cast another ballot. This is a room poll, not a secret-ballot election.

## Install

Copy this folder to `wp-content/plugins/poller` and activate it. Activation creates the tables and the `/poller/` routes. In wp-admin, open **Poller** and add a poll, then open **Room display**.

Pretty permalinks are the normal way to the join page, the poll, and the room display. With plain permalinks, those same screens are still available from the links in wp-admin.

## Contributing

Requires PHP 8.0+. See [CONTRIBUTING.md](CONTRIBUTING.md) for local setup, tests, coding standards, building, and releases.

## License

GPLv2 or later. The QR code generator in `assets/js/qrcode.js` is MIT, copyright Kazuhiko Arase. The word cloud layout in `assets/js/cloud.js` is written for this plugin. Atkinson Hyperlegible is used under the SIL Open Font License; see `assets/fonts/OFL.txt`.
