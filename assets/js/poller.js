(function () {
    var node = document.getElementById("poller-state");
    if (!node) {
        return;
    }

    var state;
    try {
        state = JSON.parse(node.textContent);
    } catch (error) {
        return;
    }

    var timer = null;
    var inflight = false;
    var failures = 0;
    var TOP = 10;
    var showAll = false;
    var paintMore = null;
    var cloud = null;

    drawQr();

    var form = document.getElementById("poller-vote");
    if (form) {
        form.addEventListener("submit", onSubmit);
    }
    var dots = isDots() && form ? bindDots() : null;
    if (isWords()) {
        var cloudHost = document.querySelector("[data-poller-cloud]");
        if (cloudHost && typeof window.pollerCloud === "function") {
            cloud = window.pollerCloud(cloudHost);
        }
    }
    bindMore();
    if (isDots() || isWords()) {
        paintResults();
    }

    if (state.rest) {
        schedule(1500);
        document.addEventListener("visibilitychange", function () {
            if (!document.hidden) {
                clearTimeout(timer);
                tick();
            }
        });
    }

    function drawQr() {
        var host = document.getElementById("poller-qr");
        if (!host || typeof qrcode !== "function" || !state.urls || !state.urls.poll) {
            return;
        }
        var qr = qrcode(0, "M");
        qr.addData(state.urls.poll);
        qr.make();
        host.innerHTML = qr.createSvgTag({
            cellSize: 4,
            margin: 8,
            scalable: true,
            alt: state.i18n && state.i18n.qr ? state.i18n.qr : "QR code that opens this poll"
        });
    }

    function isDots() {
        return state.selection === "dots";
    }

    function isWords() {
        return state.selection === "words";
    }

    function format(template, values) {
        var i = 0;
        return String(template).replace(/%(\d\$)?s/g, function (match, position) {
            var index = position ? Number(position.charAt(0)) - 1 : i++;
            return String(values[index]);
        });
    }

    // Dot polls send choice id => dots, word clouds this voter's entries,
    // and the others a list of choice ids.
    function mineMap() {
        var out = {};
        var mine = state.mine || [];
        if (Array.isArray(mine)) {
            mine.forEach(function (id) {
                out[String(id)] = 1;
            });
            return out;
        }
        Object.keys(mine).forEach(function (id) {
            out[id] = Number(mine[id]);
        });
        return out;
    }

    function identity(value) {
        var image = value.image && value.image.url ? value.image.url : "";
        var choices = (value.choices || []).map(function (choice) {
            var picture = choice.image && choice.image.url ? choice.image.url : "";
            return [choice.id, choice.label, picture].join("|");
        }).join("\n");
        var rules = [value.selection, value.budget, value.per_choice, value.spend_all].join("|");
        return value.question + "\n" + image + "\n" + rules + "\n" + choices;
    }

    function merge(next) {
        var i18n = state.i18n;
        var nonce = state.nonce;
        var rest = state.rest;
        var restVotes = state.restVotes;
        var moderate = state.moderate;
        state = next;
        state.i18n = i18n;
        state.nonce = nonce;
        state.rest = rest;
        state.restVotes = restVotes;
        state.moderate = moderate;
    }

    function totalText(count) {
        if (!count) {
            return state.i18n.noVotes;
        }
        var text = count === 1 ? state.i18n.oneVote : state.i18n.manyVotes.replace("%s", String(count));
        if (isDots()) {
            text += " · " + (state.dots === 1 ? state.i18n.oneDot : state.i18n.manyDots.replace("%s", String(state.dots)));
        }
        return text;
    }

    function paintResults() {
        var total = document.getElementById("poller-total");
        if (total) {
            total.textContent = totalText(state.ballots);
        }

        var byId = {};
        state.choices.forEach(function (choice) {
            byId[String(choice.id)] = choice;
        });

        if (isWords()) {
            paintWords();
        }

        document.querySelectorAll(".poller-result[data-choice]").forEach(function (row) {
            var choice = byId[row.getAttribute("data-choice")];
            if (!choice) {
                return;
            }
            var count = row.querySelector(".poller-count");
            var bar = row.querySelector(".poller-bar");
            if (count) {
                count.textContent = String(choice.votes);
            }
            if (bar) {
                var total = isDots() ? state.dots : state.ballots;
                var pct = total ? (choice.votes / total) * 100 : 0;
                bar.style.width = pct + "%";
            }
        });

        if (isDots()) {
            rankRows();
        }

        var isClosed = state.status !== "open";
        var closed = document.querySelector("[data-poller-closed]");
        if (closed) {
            closed.hidden = !isClosed;
        }
        if (form) {
            form.hidden = isClosed;
        }
    }

    // Most dots first; ties keep the poll's order. Rows past the top 10 fold away.
    function rankRows() {
        var list = document.querySelector(".poller-result-list");
        if (!list) {
            return;
        }
        var order = state.choices.map(function (choice, index) {
            return { choice: choice, index: index };
        });
        order.sort(function (a, b) {
            return (b.choice.votes - a.choice.votes) || (a.index - b.index);
        });
        order.forEach(function (item, rank) {
            var row = list.querySelector('.poller-result[data-choice="' + String(item.choice.id) + '"]');
            if (!row) {
                return;
            }
            row.classList.toggle("poller-result--extra", rank >= TOP);
            list.appendChild(row);
        });
    }

    function bindMore() {
        var more = document.querySelector("[data-poller-more]");
        var section = document.querySelector(".poller-results");
        if (!more || !section) {
            return;
        }
        section.classList.add("poller-results--folded");
        more.hidden = false;
        paintMore = function () {
            var count = isWords() ? state.words.length : state.choices.length;
            section.classList.toggle("poller-results--folded", !showAll);
            more.hidden = count <= TOP;
            more.textContent = showAll ? state.i18n.showTop : format(state.i18n.showAll, [count]);
            more.setAttribute("aria-expanded", showAll ? "true" : "false");
        };
        more.addEventListener("click", function () {
            showAll = !showAll;
            paintMore();
        });
        paintMore();
    }

    // Words come and go, unlike choices. Rows are matched by key and patched
    // in place, so an admin's Hide button and keyboard focus survive a refresh.
    function paintWords() {
        var words = state.words || [];
        var list = document.querySelector("[data-poller-words]");
        var empty = document.querySelector("[data-poller-empty]");
        var yours = {};
        (state.mine_keys || []).forEach(function (key) {
            yours[key] = true;
        });

        if (empty) {
            empty.hidden = words.length > 0;
        }
        if (cloud) {
            cloud.draw(words);
        }
        if (list) {
            var old = {};
            list.querySelectorAll(".poller-result[data-word]").forEach(function (row) {
                old[row.getAttribute("data-word")] = row;
            });
            words.forEach(function (word, rank) {
                var row = old[word.key];
                if (row) {
                    delete old[word.key];
                } else {
                    row = wordRow(word);
                }
                paintWordRow(row, word, rank, !!yours[word.key]);
                if (list.children[rank] !== row) {
                    list.insertBefore(row, list.children[rank] || null);
                }
            });
            Object.keys(old).forEach(function (key) {
                old[key].remove();
            });
        }
        if (paintMore) {
            paintMore();
        }
    }

    // Same markup the page renders for each word.
    function wordRow(word) {
        var row = el("div", "poller-result");
        row.setAttribute("data-word", word.key);
        var label = el("div", "poller-result-label");
        label.appendChild(el("span", "poller-result-name"));
        label.appendChild(el("span", "poller-yours", state.i18n.yourWord));
        if (state.moderate) {
            label.appendChild(hideForm(word));
        }
        row.appendChild(label);
        row.appendChild(el("div", "poller-count"));
        var track = el("div", "poller-track");
        track.setAttribute("aria-hidden", "true");
        track.appendChild(el("span", "poller-bar"));
        row.appendChild(track);
        return row;
    }

    function paintWordRow(row, word, rank, mine) {
        row.classList.toggle("poller-result--extra", rank >= TOP);
        row.querySelector(".poller-result-name").textContent = word.text;
        row.querySelector(".poller-yours").hidden = !mine;
        row.querySelector(".poller-count").textContent = String(word.count);
        row.querySelector(".poller-bar").style.width = (state.ballots ? (word.count / state.ballots) * 100 : 0) + "%";
        // The most common spelling can change; hiding goes by its key either way.
        var field = row.querySelector('.poller-hide-word input[name="word"]');
        var button = row.querySelector(".poller-hide-word button");
        if (field && button && state.moderate) {
            field.value = word.text;
            button.setAttribute("aria-label", format(state.moderate.hideLabel, [word.text]));
        }
    }

    // Same form the page renders for admins without JavaScript.
    function hideForm(word) {
        var m = state.moderate;
        var form = el("form", "poller-hide-word");
        form.method = "post";
        form.action = m.url;
        [
            ["action", "poller_hide_word"],
            ["poll_id", String(m.poll)],
            ["_wpnonce", m.nonce],
            ["_wp_http_referer", state.urls.poll],
            ["word", word.text],
            ["hide", "1"]
        ].forEach(function (pair) {
            var input = document.createElement("input");
            input.type = "hidden";
            input.name = pair[0];
            input.value = pair[1];
            form.appendChild(input);
        });
        var button = el("button", "", m.hide);
        button.type = "submit";
        button.setAttribute("aria-label", format(m.hideLabel, [word.text]));
        form.appendChild(button);
        return form;
    }

    function el(tag, className, text) {
        var node = document.createElement(tag);
        if (className) {
            node.className = className;
        }
        if (text !== undefined) {
            node.textContent = text;
        }
        return node;
    }

    function fillWords() {
        var mine = Array.isArray(state.mine) ? state.mine : [];
        form.querySelectorAll("[data-word-input]").forEach(function (input, index) {
            input.value = mine[index] || "";
        });
    }

    function paintMine() {
        var mine = mineMap();
        document.querySelectorAll(".poller-result[data-choice] .poller-yours").forEach(function (el) {
            var row = el.closest(".poller-result");
            var have = row ? mine[row.getAttribute("data-choice")] : 0;
            el.hidden = !have;
            if (have && isDots()) {
                el.textContent = format(state.i18n.yourDots, [have]);
            }
        });
        if (dots) {
            dots.paint();
            return;
        }
        var button = form ? form.querySelector('button[type="submit"]') : null;
        if (button && button.dataset.saving !== "1") {
            button.textContent = Object.keys(mine).length ? state.i18n.updateVote : state.i18n.vote;
        }
    }

    function bindDots() {
        var budget = Number(state.budget) || 0;
        var perChoice = Number(state.per_choice) || 1;
        var counter = form.querySelector("[data-poller-counter]");
        var rows = Array.prototype.slice.call(form.querySelectorAll("[data-dot-row]"));
        var button = form.querySelector('button[type="submit"]');

        function valueOf(input) {
            if (input.type === "checkbox") {
                return input.checked ? 1 : 0;
            }
            return Number(input.value) || 0;
        }

        function placed() {
            return rows.reduce(function (sum, row) {
                return sum + valueOf(row.querySelector("[data-dot-input]"));
            }, 0);
        }

        function picks() {
            var out = {};
            rows.forEach(function (row) {
                var input = row.querySelector("[data-dot-input]");
                var n = valueOf(input);
                var match = /\[(\d+)\]/.exec(input.name);
                if (n > 0 && match) {
                    out[match[1]] = n;
                }
            });
            return out;
        }

        function paint() {
            var used = placed();
            var left = Math.max(0, budget - used);
            if (counter) {
                var pips = counter.querySelector(".poller-dots-pips");
                if (pips) {
                    pips.textContent = budget <= 12 ? new Array(used + 1).join("● ") + new Array(left + 1).join("○ ") : "";
                }
                var text = counter.querySelector(".poller-dots-left");
                if (text) {
                    text.textContent = format(state.i18n.dotsLeft, [left, budget]);
                }
            }
            rows.forEach(function (row) {
                var input = row.querySelector("[data-dot-input]");
                var n = valueOf(input);
                row.classList.toggle("is-on", n > 0);
                if (input.type === "checkbox") {
                    input.disabled = !input.checked && left === 0;
                    return;
                }
                var count = row.querySelector(".poller-stepper-count");
                if (count) {
                    count.textContent = String(n);
                }
                var minus = row.querySelector('[data-step="-1"]');
                var plus = row.querySelector('[data-step="1"]');
                if (minus) {
                    minus.disabled = n <= 0;
                }
                if (plus) {
                    plus.disabled = n >= perChoice || left === 0;
                }
            });
            if (!button || button.dataset.saving === "1") {
                return;
            }
            if (state.spend_all && left > 0) {
                button.disabled = true;
                button.textContent = left === 1 ? state.i18n.toPlaceOne : state.i18n.toPlaceMany.replace("%s", String(left));
            } else if (used === 0) {
                button.disabled = true;
                button.textContent = state.i18n.placeDot;
            } else {
                button.disabled = false;
                button.textContent = Object.keys(mineMap()).length ? state.i18n.updateVote : state.i18n.vote;
            }
        }

        form.classList.add("poller-dots-live");
        if (counter) {
            counter.hidden = false;
        }
        rows.forEach(function (row) {
            var stepper = row.querySelector(".poller-stepper");
            if (stepper) {
                stepper.hidden = false;
            }
        });

        form.addEventListener("change", function (event) {
            var input = event.target;
            if (!input.matches || !input.matches("[data-dot-input]")) {
                return;
            }
            // Only checkboxes can overshoot here. Once this script runs, CSS hides
            // the <select>, so the − / + stepper is the only way to change those
            // counts, and its click handler already stops at the budget.
            if (placed() > budget && input.type === "checkbox") {
                input.checked = false;
            }
            paint();
        });

        form.addEventListener("click", function (event) {
            var step = event.target.closest("[data-step]");
            if (!step) {
                return;
            }
            var row = step.closest("[data-dot-row]");
            var select = row ? row.querySelector("select[data-dot-input]") : null;
            if (!select) {
                return;
            }
            var delta = Number(step.getAttribute("data-step"));
            var next = Math.min(perChoice, Math.max(0, valueOf(select) + delta));
            if (delta > 0 && placed() >= budget) {
                return;
            }
            select.value = String(next);
            paint();
        });

        var filterWrap = form.querySelector("[data-poller-filter]");
        var filter = filterWrap ? filterWrap.querySelector("input") : null;
        if (filter) {
            filterWrap.hidden = false;
            filter.addEventListener("input", function () {
                var needle = filter.value.trim().toLowerCase();
                rows.forEach(function (row) {
                    row.hidden = needle !== "" && (row.getAttribute("data-label") || "").indexOf(needle) === -1;
                });
            });
        }

        paint();
        return { paint: paint, picks: picks };
    }

    function summary() {
        var parts = [totalText(state.ballots)];
        if (isWords()) {
            (state.words || []).forEach(function (word) {
                parts.push(word.text + " " + word.count);
            });
            return parts.join(". ") + ".";
        }
        state.choices.forEach(function (choice) {
            var count = String(choice.votes);
            if (isDots()) {
                count = choice.votes === 1 ? state.i18n.oneDot : state.i18n.manyDots.replace("%s", count);
            }
            parts.push(choice.label + " " + count);
        });
        return parts.join(". ") + ".";
    }

    function apply(next, fromVote) {
        if (!fromVote) {
            var older = Number(next.revision) < Number(state.revision);
            var same = Number(next.revision) === Number(state.revision) && next.status === state.status;
            if (older || same) {
                return;
            }
        }
        if (identity(state) !== identity(next)) {
            window.location.reload();
            return;
        }
        merge(next);
        paintResults();
        paintMine();
        var live = document.getElementById("poller-live");
        if (live) {
            live.textContent = summary();
        }
    }

    function showError(message) {
        var el = document.querySelector("[data-poller-error]");
        if (!el) {
            return;
        }
        el.textContent = message || "";
        el.hidden = !message;
    }

    function setPaused(on) {
        var el = document.querySelector("[data-poller-paused]");
        if (!el) {
            return;
        }
        el.textContent = on ? state.i18n.paused : "";
        el.hidden = !on;
    }

    function schedule(ms) {
        clearTimeout(timer);
        timer = setTimeout(tick, ms);
    }

    function tick() {
        if (inflight || !state.rest) {
            return;
        }
        if (document.hidden) {
            schedule(4000);
            return;
        }
        inflight = true;
        fetch(state.rest, {
            credentials: "same-origin",
            headers: { Accept: "application/json" }
        }).then(function (res) {
            if (!res.ok) {
                throw new Error("status");
            }
            return res.json();
        }).then(function (next) {
            failures = 0;
            setPaused(false);
            apply(next, false);
        }).catch(function () {
            failures += 1;
            if (failures >= 3) {
                setPaused(true);
            }
        }).then(function () {
            inflight = false;
            schedule(1500);
        });
    }

    function onSubmit(event) {
        if (!state.restVotes) {
            return;
        }
        event.preventDefault();
        var choices;
        if (isWords()) {
            choices = Array.prototype.map.call(form.querySelectorAll("[data-word-input]"), function (input) {
                return input.value.trim();
            }).filter(function (value) {
                return value !== "";
            });
            if (!choices.length) {
                showError(state.i18n.enterWord);
                return;
            }
        } else if (dots) {
            choices = dots.picks();
            if (!Object.keys(choices).length) {
                showError(state.i18n.placeDot);
                return;
            }
        } else {
            choices = new FormData(form).getAll("choices[]").map(function (value) {
                return Number(value);
            });
            if (!choices.length) {
                showError(state.selection === "multi" ? state.i18n.chooseSome : state.i18n.chooseOne);
                return;
            }
        }
        var button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        button.dataset.saving = "1";
        button.textContent = state.i18n.saving;
        showError("");

        fetch(state.restVotes, {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json",
                Accept: "application/json",
                "X-Poller-Nonce": state.nonce
            },
            body: JSON.stringify({ choices: choices })
        }).then(function (res) {
            return res.json().then(function (body) {
                return { ok: res.ok, body: body };
            });
        }).then(function (result) {
            if (!result.ok) {
                var message = result.body && result.body.message ? result.body.message : state.i18n.voteFailed;
                showError(message);
                return;
            }
            apply(result.body, true);
            if (isWords()) {
                fillWords();
            }
        }).catch(function () {
            showError(state.i18n.voteFailed);
        }).then(function () {
            button.disabled = false;
            button.dataset.saving = "";
            paintMine();
        });
    }
})();
