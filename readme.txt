=== Poller ===
Contributors: bmlt-enabled
Tags: poll, voting, qr-code, anonymous, word-cloud
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Anonymous polls people join with a code or a QR code, with a live tally everyone can see.

== Description ==

Create a poll with text choices or picture choices. Each poll is one choice, more than one, dot voting, or a word cloud, and it can include a picture with the question. With dot voting, each person spreads a set number of dots across up to 100 items, and the tally ranks the items by dots. With a word cloud, each person types a few words or short phrases, and the room display shows them as a live cloud, larger when more people entered the same word.

Open the room display to show a large code and a QR code. People scan it, or open the join page and type the code. Everyone sees the tally update while the poll is open.

Votes are anonymous. The plugin stores a salted hash of a random browser token and the counts. It does not store an account or an address with the ballot. Word cloud answers are only ever shown together, as counts per word, never by who wrote them. An admin can hide a word from the cloud and the results.

== Installation ==

1. Upload the `poller` folder to `/wp-content/plugins/`.
2. Activate Poller.
3. Open Poller in the dashboard and add a poll.
4. Open the room display and let people scan or enter the code.

== Changelog ==

= 0.4.0 =
* Reset votes starts a new round on the same poll instead of deleting answers. The code, QR code and choices stay, the live tally starts empty, and earlier rounds' results are listed under Past rounds on the poll's edit screen.
* Duplicate a poll from the poll list. The copy keeps the question, picture, voting rules and choices, gets a new code, and starts closed with no votes, so you can tweak it and run it again.

= 0.3.0 =
* Word cloud: each person enters up to 10 words or short phrases (3 by default), and the room display builds a live cloud sized by how many people entered each word. Case and punctuation variants count as one word.
* Free text is shown only in aggregate. Admins can hide a word from the cloud and the results, from the poll page or the poll's edit screen.

= 0.2.0 =
* Dot voting: each person spreads a set number of dots across up to 100 items, with optional stacking and "use every dot" rules.
* Paste a list of choices, one per line.

= 0.1.0 =
* First release.
