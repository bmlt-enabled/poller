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

    drawQr();

    var form = document.getElementById("poller-vote");
    if (form) {
        form.addEventListener("submit", onSubmit);
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

    function identity(value) {
        var image = value.image && value.image.url ? value.image.url : "";
        var choices = (value.choices || []).map(function (choice) {
            var picture = choice.image && choice.image.url ? choice.image.url : "";
            return [choice.id, choice.label, picture].join("|");
        }).join("\n");
        return value.question + "\n" + image + "\n" + choices;
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
        if (count === 1) {
            return state.i18n.oneVote;
        }
        return state.i18n.manyVotes.replace("%s", String(count));
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
                var pct = state.ballots ? (choice.votes / state.ballots) * 100 : 0;
                bar.style.width = pct + "%";
            }
        });

        var isClosed = state.status !== "open";
        var closed = document.querySelector("[data-poller-closed]");
        if (closed) {
            closed.hidden = !isClosed;
        }
        if (form) {
            form.hidden = isClosed;
        }
    }

    function paintMine() {
        var mine = {};
        (state.mine || []).forEach(function (id) {
            mine[String(id)] = true;
        });
        document.querySelectorAll(".poller-yours").forEach(function (el) {
            var row = el.closest(".poller-result");
            el.hidden = !row || !mine[row.getAttribute("data-choice")];
        });
        var button = form ? form.querySelector('button[type="submit"]') : null;
        if (button && button.dataset.saving !== "1") {
            button.textContent = (state.mine || []).length ? state.i18n.updateVote : state.i18n.vote;
        }
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
        var data = new FormData(form);
        var choices = data.getAll("choices[]");
        if (!choices.length) {
            showError(state.selection === "multi" ? state.i18n.chooseSome : state.i18n.chooseOne);
            return;
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
            body: JSON.stringify({
                choices: choices.map(function (value) {
                    return Number(value);
                })
            })
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
