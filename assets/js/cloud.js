/*
 * Word cloud layout for Poller. No dependencies.
 *
 * Words are placed biggest first along a spiral. Each word remembers its
 * spot, and the next redraw tries that spot first, so a live cloud grows in
 * place instead of reshuffling every refresh. When the words do not all fit,
 * the whole cloud is drawn smaller and placed again.
 *
 *   var cloud = pollerCloud(host);
 *   cloud.draw([{ key: "hope", text: "Hope", count: 4 }, ...]);
 */
(function () {
    var MIN_FONT = 12;
    var TRIES = 14;

    function hash(text) {
        var h = 0;
        for (var i = 0; i < text.length; i++) {
            h = (h * 31 + text.charCodeAt(i)) | 0;
        }
        return Math.abs(h);
    }

    function overlaps(a, list) {
        for (var i = 0; i < list.length; i++) {
            var b = list[i];
            if (a.x < b.x + b.w && b.x < a.x + a.w && a.y < b.y + b.h && b.y < a.y + a.h) {
                return true;
            }
        }
        return false;
    }

    function create(host) {
        var spots = {};
        var nodes = {};
        var widths = {};
        var last = [];
        var probe = document.createElement("span");
        probe.className = "poller-cloud-probe";
        probe.setAttribute("aria-hidden", "true");

        host.classList.add("poller-cloud--placed");
        host.textContent = "";
        host.appendChild(probe);

        // Width of the text at a 100px font size.
        function measure(text) {
            if (widths[text] === undefined) {
                probe.textContent = text;
                widths[text] = probe.getBoundingClientRect().width || text.length * 55;
            }
            return widths[text];
        }

        // Spiral out from (sx, sy) for the first spot inside the box that
        // touches no placed word. Coordinates are the box's top-left corner.
        function find(box, sx, sy, width, height, placed) {
            var step = Math.max(1, Math.min(width, height) / 160);
            var stretch = width / height;
            var limit = Math.max(width, height);
            // Steps of about 4px along the curve, however far out it has gone.
            for (var angle = 0, r = 0; r <= limit; angle += r > 0 ? Math.min(0.3, 4 / r) : 0.3) {
                r = step * angle / 2;
                box.x = sx + r * Math.cos(angle) * stretch - box.w / 2;
                box.y = sy + r * Math.sin(angle) - box.h / 2;
                if (box.x < 0 || box.y < 0 || box.x + box.w > width || box.y + box.h > height) {
                    continue;
                }
                if (!overlaps(box, placed)) {
                    return true;
                }
            }
            return false;
        }

        // With strict on, stop at the first word that does not fit: the cloud
        // will be drawn smaller anyway. The last try places what it can.
        function layout(words, width, height, base, strict) {
            var top = words.length ? words[0].count : 1;
            var placed = [];
            var boxes = {};
            var missed = 0;
            words.every(function (word) {
                // Square root so a word with twice the people is not twice as wide.
                var weight = top > 1 ? 0.32 + 0.68 * Math.sqrt((word.count - 1) / (top - 1)) : 1;
                var font = Math.max(MIN_FONT, base * weight);
                var pad = Math.max(2, font * 0.12);
                var box = {
                    w: measure(word.text) * font / 100 + pad * 2,
                    h: font * 1.05 + pad,
                    font: font,
                    pad: pad
                };
                var spot = spots[word.key];
                var sx = spot ? spot.x * width : width / 2;
                var sy = spot ? spot.y * height : height / 2;
                if (!spot) {
                    // A small nudge so equal words do not all start on the same line.
                    var turn = (hash(word.key) % 360) * Math.PI / 180;
                    sx += Math.cos(turn) * box.h * 0.5;
                    sy += Math.sin(turn) * box.h * 0.5;
                }
                if (find(box, sx, sy, width, height, placed)) {
                    placed.push(box);
                    boxes[word.key] = box;
                } else {
                    missed++;
                }
                return !(strict && missed);
            });
            return { boxes: boxes, missed: missed };
        }

        function paint(words, boxes, width, height) {
            var keep = {};
            words.forEach(function (word, index) {
                var box = boxes[word.key];
                if (!box) {
                    return;
                }
                keep[word.key] = true;
                spots[word.key] = {
                    x: (box.x + box.w / 2) / width,
                    y: (box.y + box.h / 2) / height
                };
                var node = nodes[word.key];
                if (!node) {
                    node = document.createElement("span");
                    node.className = "poller-cloud-word poller-cloud-word--" + "abcd".charAt(hash(word.key) % 4) + " is-new";
                    node.addEventListener("animationend", function () {
                        node.classList.remove("is-new");
                    });
                    nodes[word.key] = node;
                    host.appendChild(node);
                }
                node.textContent = word.text;
                node.classList.toggle("poller-cloud-word--top", index === 0);
                node.style.fontSize = box.font.toFixed(1) + "px";
                node.style.left = (box.x + box.pad).toFixed(1) + "px";
                node.style.top = (box.y + box.pad / 2).toFixed(1) + "px";
            });
            Object.keys(nodes).forEach(function (key) {
                if (!keep[key]) {
                    nodes[key].remove();
                    delete nodes[key];
                    delete spots[key];
                }
            });
        }

        function draw(words) {
            last = (words || []).slice(0, 100);
            var width = host.clientWidth;
            var height = host.clientHeight;
            if (!width || !height) {
                return;
            }
            var base = Math.min(height * 0.3, width * 0.16);
            var result = layout(last, width, height, base, true);
            for (var i = 0; i < TRIES && result.missed > 0 && base > MIN_FONT; i++) {
                base *= 0.87;
                result = layout(last, width, height, base, true);
            }
            if (result.missed > 0) {
                // Still too many: keep the biggest words and leave the rest to the list.
                result = layout(last, width, height, base, false);
            }
            paint(last, result.boxes, width, height);
        }

        function redraw() {
            widths = {};
            draw(last);
        }

        var resizeTimer = null;
        window.addEventListener("resize", function () {
            clearTimeout(resizeTimer);
            resizeTimer = setTimeout(function () {
                draw(last);
            }, 150);
        });
        if (document.fonts && document.fonts.ready) {
            document.fonts.ready.then(redraw);
        }

        return { draw: draw };
    }

    window.pollerCloud = create;
})();
