=== Poller ===
Contributors: bmlt-enabled
Tags: poll, voting, qr-code, anonymous
Requires at least: 6.4
Tested up to: 6.8
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Anonymous polls people join with a code or a QR code, with a live tally everyone can see.

== Description ==

Create a poll with text choices or picture choices. Each poll is one choice or more than one, and it can include a picture with the question.

Open the room display to show a large code and a QR code. People scan it, or open the join page and type the code. Everyone sees the tally update while the poll is open.

Votes are anonymous. The plugin stores a salted hash of a random browser token and the counts. It does not store an account or an address with the ballot.

== Installation ==

1. Upload the `poller` folder to `/wp-content/plugins/`.
2. Activate Poller.
3. Open Poller in the dashboard and add a poll.
4. Open the room display and let people scan or enter the code.

== Changelog ==

= 0.1.0 =
* First release.
