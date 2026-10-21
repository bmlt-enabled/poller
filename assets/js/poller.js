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

    drawQr();

    var form = document.getElementById("poller-vote");
    if (form) {
        form.addEventListener("submit", onSubmit);
    }
    var dots = isDots() && form ? bindDots() : null;
    bindMore();
    if (isDots()) {
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

    function format(template, values) {
        var i = 0;
        return String(template).replace(/%(\d\$)?s/g, function (match, position) {
            var index = position ? Number(position.charAt(0)) - 1 : i++;
            return String(values[index]);
        });
    }

    // Dot polls send choice id => dots. The others send a list of choice ids.
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
        state = next;
        state.i18n = i18n;
        state.nonce = nonce;
        state.rest = rest;
        state.restVotes = restVotes;
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

        document.querySelectorAll(".poller-result").forEach(function (row) {
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
        var paint = function () {
            section.classList.toggle("poller-results--folded", !showAll);
            more.textContent = showAll ? state.i18n.showTop : format(state.i18n.showAll, [state.choices.length]);
            more.setAttribute("aria-expanded", showAll ? "true" : "false");
        };
        more.addEventListener("click", function () {
            showAll = !showAll;
            paint();
        });
        paint();
    }

    function paintMine() {
        var mine = mineMap();
        document.querySelectorAll(".poller-yours").forEach(function (el) {
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
        state.choices.forEach(function (choice) {
            parts.push(choice.label + " " + choice.votes);
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
        if (dots) {
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
        }).catch(function () {
            showError(state.i18n.voteFailed);
        }).then(function () {
            button.disabled = false;
            button.dataset.saving = "";
            paintMine();
        });
    }
})();
