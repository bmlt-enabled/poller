(function () {
    document.querySelectorAll("[data-poller-copy]").forEach(function (button) {
        button.addEventListener("click", function () {
            var id = button.getAttribute("data-poller-copy");
            var node = id ? document.getElementById(id) : null;
            if (!node) {
                return;
            }
            var text = "value" in node && node.value ? node.value : node.textContent.trim();
            var previous = button.textContent;
            var copied = (window.pollerAdmin && pollerAdmin.copied) || "Copied";
            var done = function () {
                button.textContent = copied;
                window.setTimeout(function () {
                    button.textContent = previous;
                }, 1500);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(text).then(done).catch(function () {
                    if (node.select) {
                        node.focus();
                        node.select();
                    }
                });
                return;
            }
            if (node.select) {
                node.focus();
                node.select();
            }
        });
    });

    document.querySelectorAll("[data-poller-confirm]").forEach(function (form) {
        form.addEventListener("submit", function (event) {
            var message = form.getAttribute("data-poller-confirm");
            if (message && !window.confirm(message)) {
                event.preventDefault();
            }
        });
    });

    var wrap = document.getElementById("poller-choices");
    if (!wrap) {
        bindPrompt();
        return;
    }

    wrap.addEventListener("click", function (event) {
        var remove = event.target.closest(".poller-remove-choice");
        if (remove) {
            var rows = wrap.querySelectorAll(".poller-admin-choice");
            if (rows.length <= 2) {
                return;
            }
            var row = remove.closest(".poller-admin-choice");
            if (row) {
                row.remove();
            }
            return;
        }

        var pick = event.target.closest(".poller-pick-choice");
        if (!pick) {
            return;
        }
        var choice = pick.closest(".poller-admin-choice");
        openFrame(function (attachment) {
            var input = choice.querySelector(".poller-choice-image-id");
            var preview = choice.querySelector(".poller-choice-preview");
            if (input) {
                input.value = String(attachment.id);
            }
            if (preview) {
                preview.innerHTML = "";
                var img = document.createElement("img");
                img.src = thumb(attachment);
                img.alt = "";
                preview.appendChild(img);
            }
        });
    });

    document.querySelectorAll('input[name="kind"]').forEach(function (input) {
        input.addEventListener("change", function () {
            if (!input.checked) {
                return;
            }
            wrap.dataset.kind = input.value;
            var placeholder = input.value === "image" ? wrap.dataset.placeholderImage : wrap.dataset.placeholderText;
            wrap.querySelectorAll('input[name="choice_label[]"]').forEach(function (field) {
                field.placeholder = placeholder || "";
            });
        });
    });

    var add = document.getElementById("poller-add-choice");
    var template = document.getElementById("poller-choice-template");
    if (add && template) {
        add.addEventListener("click", function () {
            var max = Number(wrap.dataset.max || "12");
            if (wrap.querySelectorAll(".poller-admin-choice").length >= max) {
                return;
            }
            wrap.appendChild(template.content.cloneNode(true));
            var kind = document.querySelector('input[name="kind"]:checked');
            if (kind && kind.value === "image") {
                var fields = wrap.querySelectorAll('input[name="choice_label[]"]');
                var last = fields[fields.length - 1];
                if (last) {
                    last.placeholder = wrap.dataset.placeholderImage || "";
                }
            }
        });
    }

    bindPrompt();

    function bindPrompt() {
        var pick = document.getElementById("poller-pick-prompt");
        var clear = document.getElementById("poller-clear-prompt");
        var input = document.getElementById("poller-image-id");
        var preview = document.getElementById("poller-prompt-preview");
        if (!pick || !input || !preview) {
            return;
        }
        pick.addEventListener("click", function () {
            openFrame(function (attachment) {
                input.value = String(attachment.id);
                preview.innerHTML = "";
                var img = document.createElement("img");
                img.src = thumb(attachment);
                img.alt = "";
                preview.appendChild(img);
                if (clear) {
                    clear.hidden = false;
                }
            });
        });
        if (clear) {
            clear.addEventListener("click", function () {
                input.value = "0";
                preview.innerHTML = "";
                clear.hidden = true;
            });
        }
    }

    function openFrame(onSelect) {
        var settings = window.pollerAdmin || {};
        if (!window.wp || !wp.media) {
            return;
        }
        var frame = wp.media({
            title: settings.choose || "Choose a picture",
            button: { text: settings.use || "Use this picture" },
            library: { type: "image" },
            multiple: false
        });
        frame.on("select", function () {
            onSelect(frame.state().get("selection").first().toJSON());
        });
        frame.open();
    }

    function thumb(attachment) {
        if (attachment.sizes && attachment.sizes.thumbnail) {
            return attachment.sizes.thumbnail.url;
        }
        if (attachment.sizes && attachment.sizes.medium) {
            return attachment.sizes.medium.url;
        }
        return attachment.url;
    }
})();
