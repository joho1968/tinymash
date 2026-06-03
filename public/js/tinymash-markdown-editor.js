(function () {
    "use strict";

    var headingStorageKey = "tinymash.editor.headingLevel";

    function selection(field) {
        var start = Number.isInteger(field.selectionStart) ? field.selectionStart : field.value.length;
        var end = Number.isInteger(field.selectionEnd) ? field.selectionEnd : start;
        return { start: start, end: end, text: field.value.slice(start, end) };
    }

    function changed(field, replacement, start, end, mode) {
        field.setRangeText(replacement, start, end, mode || "select");
        field.focus();
        field.dispatchEvent(new Event("input", { bubbles: true }));
    }

    function prefixLines(field, prefix, placeholder) {
        var range = selection(field);
        var text = range.text || placeholder;
        changed(field, text.split("\n").map(function (line) { return prefix + line; }).join("\n"), range.start, range.end, "select");
    }

    function wrapBlock(field, prefix, suffix, placeholder) {
        var range = selection(field);
        var text = range.text || placeholder;
        var leading = range.start > 0 && field.value.charAt(range.start - 1) !== "\n" ? "\n\n" : "";
        var trailing = range.end < field.value.length && field.value.charAt(range.end) !== "\n" ? "\n\n" : "";
        changed(field, leading + prefix + text + suffix + trailing, range.start, range.end, "select");
    }

    function insertCallout(field, type) {
        var range = selection(field);
        var text = range.text.trim() || "Callout text";
        var leading = range.start > 0 && field.value.charAt(range.start - 1) !== "\n" ? "\n\n" : "";
        var trailing = range.end < field.value.length && field.value.charAt(range.end) !== "\n" ? "\n\n" : "";
        changed(field, leading + "> [!" + type + "]\n" + text.split("\n").map(function (line) { return "> " + line; }).join("\n") + trailing, range.start, range.end, "select");
    }

    function insertTable(field) {
        var range = selection(field);
        var text = range.text.trim();
        var replacement = "| Column 1 | Column 2 |\n| --- | --- |\n| Value 1 | Value 2 |";
        if (text !== "") {
            var rows = text.split("\n").map(function (line) { return line.trim(); }).filter(function (line) { return line !== ""; });
            var cells = rows.map(function (line) {
                if (line.includes("|")) { return line.split("|").map(function (cell) { return cell.trim(); }).filter(Boolean); }
                if (line.includes("\t")) { return line.split("\t").map(function (cell) { return cell.trim(); }).filter(Boolean); }
                return [line];
            });
            if (cells.length > 0 && cells.every(function (row) { return row.length === 1; })) {
                var spaced = rows.map(function (line) { return line.split(/\s+/).map(function (cell) { return cell.trim(); }).filter(Boolean); });
                var width = spaced[0] ? spaced[0].length : 0;
                if (width > 1 && spaced.every(function (row) { return row.length === width; })) { cells = spaced; }
            }
            var lines = cells.map(function (row) { return row.length > 0 ? "| " + row.join(" | ") + " |" : ""; }).filter(Boolean);
            if (lines.length > 0) {
                var count = Math.max(1, (lines[0].match(/\|/g) || []).length - 1);
                replacement = [lines[0], "| " + Array.from({ length: count }, function () { return "---"; }).join(" | ") + " |"].concat(lines.slice(1)).join("\n");
            }
        }
        var leading = range.start > 0 && field.value.charAt(range.start - 1) !== "\n" ? "\n\n" : "";
        var trailing = range.end < field.value.length && field.value.charAt(range.end) !== "\n" ? "\n\n" : "";
        changed(field, leading + replacement + trailing, range.start, range.end, "select");
    }

    function insertShortcode(field, button) {
        var name = String(button.dataset.shortcodeName || "").trim();
        if (!/^[a-z][a-z0-9_-]*$/.test(name)) { return; }
        var range = selection(field);
        var block = button.dataset.shortcodeBlock === "1";
        var replacement = String(button.dataset.shortcodeExample || "").trim() || "[" + name + "]";
        if (block && range.text.trim() !== "") { replacement = "[" + name + "]" + range.text + "[/" + name + "]"; }
        var leading = block && range.start > 0 && field.value.charAt(range.start - 1) !== "\n" ? "\n\n" : "";
        var trailing = block && range.end < field.value.length && field.value.charAt(range.end) !== "\n" ? "\n\n" : "";
        changed(field, leading + replacement + trailing, range.start, range.end, block ? "select" : "end");
    }

    var emojiPickerModalElement = null;
    var emojiPickerField = null;
    var emojiPickerReturnModalElement = null;
    var emojiPickerModulePromise = null;
    var emojiAutocompleteEntries = [];
    var emojiAutocompletePromise = null;
    var linkModalElement = null;
    var linkModalField = null;
    var linkModalReturnModalElement = null;
    var linkModalRange = null;
    var linkModalTargets = [];

    function escapeHtml(value) {
        var node = document.createElement("div");
        node.textContent = String(value || "");
        return node.innerHTML;
    }

    function ensureEmojiAutocompleteEntries() {
        if (emojiAutocompleteEntries.length > 0) { return Promise.resolve(emojiAutocompleteEntries); }
        if (emojiAutocompletePromise) { return emojiAutocompletePromise; }
        emojiAutocompletePromise = fetch("/ext/emoji-picker-data/en/github/data", { credentials: "same-origin" })
            .then(function (response) {
                if (!response.ok) { throw new Error("emoji autocomplete data unavailable"); }
                return response.json();
            })
            .then(function (payload) {
                var entries = [];
                if (!Array.isArray(payload)) { return entries; }
                payload.forEach(function (record) {
                    if (!record || typeof record !== "object" || typeof record.emoji !== "string") { return; }
                    var annotation = typeof record.annotation === "string" ? record.annotation : "";
                    var tags = Array.isArray(record.tags) ? record.tags.filter(function (tag) { return typeof tag === "string"; }) : [];
                    var shortcodes = Array.isArray(record.shortcodes) ? record.shortcodes.filter(function (shortcode) { return typeof shortcode === "string" && shortcode !== ""; }) : [];
                    shortcodes.forEach(function (shortcode, index) {
                        var normalized = shortcode.toLowerCase();
                        entries.push({
                            emoji: record.emoji,
                            shortcode: normalized,
                            annotation: annotation,
                            primary: index === 0,
                            searchText: [normalized, annotation].concat(tags).join(" ").toLowerCase()
                        });
                    });
                });
                emojiAutocompleteEntries = entries.sort(function (left, right) {
                    if (left.primary !== right.primary) { return left.primary ? -1 : 1; }
                    return left.shortcode.localeCompare(right.shortcode);
                });
                return emojiAutocompleteEntries;
            })
            .catch(function (error) {
                emojiAutocompletePromise = null;
                throw error;
            });
        return emojiAutocompletePromise;
    }

    function bootstrapModal(element) {
        if (!element || !window.bootstrap || !window.bootstrap.Modal) { return null; }
        return window.bootstrap.Modal.getOrCreateInstance(element);
    }

    function suspendParentModalDirtyCheck(modalElement) {
        if (!modalElement || typeof window.tinymashSuspendModalDirtyCheck !== "function") { return; }
        window.tinymashSuspendModalDirtyCheck(modalElement);
    }

    function stickyCheckbox(storageKey, fallback) {
        try {
            var stored = window.localStorage.getItem(storageKey);
            if (stored === "1") { return true; }
            if (stored === "0") { return false; }
        } catch (error) { /* optional persistence */ }
        return Boolean(fallback);
    }

    function writeStickyCheckbox(storageKey, checked) {
        try { window.localStorage.setItem(storageKey, checked ? "1" : "0"); } catch (error) { /* optional persistence */ }
    }

    function markdownLinkText(value) {
        return String(value || "link text").replace(/\\/g, "\\\\").replace(/\]/g, "\\]");
    }

    function markdownLinkUrl(value) {
        return String(value || "").trim().replace(/\)/g, "%29").replace(/\s/g, "%20");
    }

    function safeExternalUrl(value) {
        var url = String(value || "").trim();
        if (url === "") { return ""; }
        if (/^www\./i.test(url)) { url = "https://" + url; }
        if (/^(https?:|mailto:)/i.test(url)) { return url; }
        return "";
    }

    function targetsForEditor(editor) {
        if (Array.isArray(editor.tmMarkdownInternalLinkTargets)) {
            return editor.tmMarkdownInternalLinkTargets;
        }
        var raw = editor.getAttribute("data-tm-markdown-internal-link-targets") || "[]";
        try {
            var parsed = JSON.parse(raw);
            var targets = Array.isArray(parsed) ? parsed.filter(function (target) {
                return target && typeof target === "object" && typeof target.url === "string" && target.url.charAt(0) === "/";
            }) : [];
            if (targets.length > 0) { editor.tmMarkdownInternalLinkTargets = targets; }
            return targets;
        } catch (error) {
            return [];
        }
    }

    function loadTargetsForEditor(editor) {
        var current = targetsForEditor(editor);
        if (current.length > 0) { return Promise.resolve(current); }
        var url = editor.getAttribute("data-tm-markdown-internal-link-targets-url") || "";
        if (url === "") { return Promise.resolve([]); }
        if (editor.tmMarkdownInternalLinkTargetsPromise) {
            return editor.tmMarkdownInternalLinkTargetsPromise;
        }
        editor.tmMarkdownInternalLinkTargetsPromise = fetch(url, {
            cache: "no-store",
            credentials: "same-origin",
            headers: { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" }
        }).then(function (response) {
            return response.json().catch(function () { return {}; }).then(function (payload) {
                if (!response.ok) { throw new Error(payload.error || "Unable to load link targets."); }
                return Array.isArray(payload.targets) ? payload.targets : [];
            });
        }).then(function (targets) {
            var filtered = targets.filter(function (target) {
                return target && typeof target === "object" && typeof target.url === "string" && target.url.charAt(0) === "/";
            });
            editor.tmMarkdownInternalLinkTargets = filtered;
            return filtered;
        }).catch(function (error) {
            editor.tmMarkdownInternalLinkTargetsPromise = null;
            throw error;
        });
        return editor.tmMarkdownInternalLinkTargetsPromise;
    }

    function ensureLinkModal() {
        if (linkModalElement) { return linkModalElement; }
        linkModalElement = document.createElement("div");
        linkModalElement.className = "modal fade";
        linkModalElement.id = "tm-markdown-link-modal";
        linkModalElement.tabIndex = -1;
        linkModalElement.setAttribute("aria-labelledby", "tm-markdown-link-title");
        linkModalElement.setAttribute("aria-hidden", "true");
        linkModalElement.innerHTML = ''
            + '<div class="modal-dialog modal-dialog-scrollable modal-lg"><div class="modal-content">'
            + '<div class="modal-header"><h2 class="modal-title fs-5" id="tm-markdown-link-title">Insert link</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div>'
            + '<div class="modal-body d-grid gap-3">'
            + '<div data-tm-markdown-link-panel="external"><label class="form-label small mb-1" for="tm-markdown-link-external-url">URL</label><input class="form-control" id="tm-markdown-link-external-url" type="url" inputmode="url" placeholder="https://example.com"><div class="invalid-feedback">Enter an HTTP, HTTPS, or mailto URL.</div></div>'
            + '<div data-tm-markdown-link-panel="internal" class="d-none"><label class="form-label small mb-1" for="tm-markdown-link-internal-filter">Filter targets</label><input class="form-control mb-2" id="tm-markdown-link-internal-filter" type="search" autocomplete="off" placeholder="Type to filter"><label class="form-label small mb-1" for="tm-markdown-link-internal-target">Target</label><select class="form-select" id="tm-markdown-link-internal-target" size="8"></select></div>'
            + '<div><label class="form-label small mb-1" for="tm-markdown-link-text">Link text</label><input class="form-control" id="tm-markdown-link-text" type="text" placeholder="link text"></div>'
            + '<div class="form-check"><input class="form-check-input" id="tm-markdown-link-new-tab" type="checkbox" value="1"><label class="form-check-label" for="tm-markdown-link-new-tab">Open in new tab</label></div>'
            + '</div><div class="modal-footer"><button class="btn btn-outline-secondary" type="button" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary" type="button" data-tm-markdown-link-insert>Insert link</button></div>'
            + '</div></div>';
        document.body.appendChild(linkModalElement);
        var filter = linkModalElement.querySelector("#tm-markdown-link-internal-filter");
        if (filter) {
            filter.addEventListener("input", function () { renderInternalLinkOptions(filter.value || ""); });
        }
        var target = linkModalElement.querySelector("#tm-markdown-link-internal-target");
        if (target) {
            target.addEventListener("change", function () {
                var text = linkModalElement.querySelector("#tm-markdown-link-text");
                var selected = target.selectedOptions[0];
                if (text && text.value.trim() === "" && selected) { text.value = selected.getAttribute("data-title") || ""; }
            });
        }
        var insert = linkModalElement.querySelector("[data-tm-markdown-link-insert]");
        if (insert) { insert.addEventListener("click", insertLinkFromModal); }
        linkModalElement.addEventListener("hidden.bs.modal", function () {
            if (!linkModalReturnModalElement) { return; }
            var returnElement = linkModalReturnModalElement;
            linkModalReturnModalElement = null;
            var returnModal = bootstrapModal(returnElement);
            if (returnModal) { returnModal.show(); }
        });
        return linkModalElement;
    }

    function renderInternalLinkOptions(filterValue) {
        if (!linkModalElement) { return; }
        var target = linkModalElement.querySelector("#tm-markdown-link-internal-target");
        if (!target) { return; }
        var needle = String(filterValue || "").trim().toLowerCase();
        var matches = linkModalTargets.filter(function (entry) {
            var haystack = [entry.label, entry.path, entry.type, entry.scope, entry.author_slug, entry.title].filter(Boolean).join(" ").toLowerCase();
            return needle === "" || haystack.includes(needle);
        });
        var html = ['<option value="">' + (linkModalTargets.length > 0 ? "Choose target" : "No targets loaded") + '</option>'];
        matches.forEach(function (entry) {
            html.push('<option value="' + escapeHtml(entry.url || "") + '" data-title="' + escapeHtml(entry.title || "") + '">' + escapeHtml(entry.label || entry.title || entry.url || "Untitled content") + '</option>');
        });
        target.innerHTML = html.join("");
        target.selectedIndex = matches.length > 0 ? 1 : 0;
        var text = linkModalElement.querySelector("#tm-markdown-link-text");
        var selected = target.selectedOptions[0];
        if (text && text.value.trim() === "" && selected) { text.value = selected.getAttribute("data-title") || ""; }
    }

    function openLinkModal(field, editor, mode) {
        var modalElement = ensureLinkModal();
        var modal = bootstrapModal(modalElement);
        if (!modal) { return; }
        var range = selection(field);
        linkModalField = field;
        linkModalRange = { start: range.start, end: range.end, text: range.text };
        linkModalTargets = targetsForEditor(editor);
        var externalPanel = modalElement.querySelector('[data-tm-markdown-link-panel="external"]');
        var internalPanel = modalElement.querySelector('[data-tm-markdown-link-panel="internal"]');
        var title = modalElement.querySelector("#tm-markdown-link-title");
        var url = modalElement.querySelector("#tm-markdown-link-external-url");
        var filter = modalElement.querySelector("#tm-markdown-link-internal-filter");
        var text = modalElement.querySelector("#tm-markdown-link-text");
        var newTab = modalElement.querySelector("#tm-markdown-link-new-tab");
        var isInternal = mode === "internal";
        if (title) { title.textContent = isInternal ? "Insert internal link" : "Insert external link"; }
        if (externalPanel) { externalPanel.classList.toggle("d-none", isInternal); }
        if (internalPanel) { internalPanel.classList.toggle("d-none", !isInternal); }
        if (url) { url.value = ""; url.classList.remove("is-invalid"); }
        if (filter) { filter.value = ""; }
        if (text) { text.value = range.text.trim(); }
        if (newTab) { newTab.checked = stickyCheckbox(isInternal ? "tinymash.markdown.internalLinkNewTab" : "tinymash.markdown.externalLinkNewTab", !isInternal); }
        if (isInternal) {
            linkModalTargets = targetsForEditor(editor);
            if (linkModalTargets.length > 0) {
                renderInternalLinkOptions("");
            } else {
                var target = modalElement.querySelector("#tm-markdown-link-internal-target");
                if (target) { target.innerHTML = '<option value="">Loading targets...</option>'; }
                loadTargetsForEditor(editor).then(function (targets) {
                    linkModalTargets = targets;
                    renderInternalLinkOptions(filter ? filter.value || "" : "");
                }).catch(function (error) {
                    var target = modalElement.querySelector("#tm-markdown-link-internal-target");
                    if (target) { target.innerHTML = '<option value="">' + escapeHtml(error.message || "Unable to load targets.") + '</option>'; }
                });
            }
        }
        var originModalElement = field.closest(".modal.show");
        if (originModalElement && originModalElement !== modalElement) {
            linkModalReturnModalElement = originModalElement;
            originModalElement.addEventListener("hidden.bs.modal", function () { modal.show(); }, { once: true });
            var originModal = bootstrapModal(originModalElement);
            suspendParentModalDirtyCheck(originModalElement);
            if (originModal) { originModal.hide(); }
        } else {
            linkModalReturnModalElement = null;
            modal.show();
        }
        window.setTimeout(function () {
            var focusTarget = isInternal ? filter : url;
            if (focusTarget) { focusTarget.focus(); }
        }, 160);
    }

    function insertLinkFromModal() {
        if (!linkModalElement || !linkModalField || !linkModalRange) { return; }
        var title = linkModalElement.querySelector("#tm-markdown-link-title");
        var isInternal = title && title.textContent.indexOf("internal") !== -1;
        var text = linkModalElement.querySelector("#tm-markdown-link-text");
        var newTab = linkModalElement.querySelector("#tm-markdown-link-new-tab");
        var linkText = text && text.value.trim() !== "" ? text.value.trim() : (linkModalRange.text.trim() || "link text");
        var url = "";
        if (isInternal) {
            var target = linkModalElement.querySelector("#tm-markdown-link-internal-target");
            var selected = target ? target.selectedOptions[0] : null;
            if (!selected || !selected.value) { return; }
            url = selected.value;
            if (linkText === "link text") { linkText = selected.getAttribute("data-title") || linkText; }
            writeStickyCheckbox("tinymash.markdown.internalLinkNewTab", Boolean(newTab && newTab.checked));
        } else {
            var urlField = linkModalElement.querySelector("#tm-markdown-link-external-url");
            url = safeExternalUrl(urlField ? urlField.value : "");
            if (url === "") {
                if (urlField) { urlField.classList.add("is-invalid"); urlField.focus(); }
                return;
            }
            writeStickyCheckbox("tinymash.markdown.externalLinkNewTab", Boolean(newTab && newTab.checked));
        }
        var markdown = newTab && newTab.checked
            ? '<a href="' + escapeHtml(url) + '" target="_blank" rel="noopener noreferrer">' + escapeHtml(linkText) + '</a>'
            : '[' + markdownLinkText(linkText) + '](' + markdownLinkUrl(url) + ')';
        changed(linkModalField, markdown, linkModalRange.start, linkModalRange.end, "end");
        var modal = bootstrapModal(linkModalElement);
        if (modal) { modal.hide(); }
    }

    function ensureEmojiPickerModal() {
        if (emojiPickerModalElement) { return emojiPickerModalElement; }
        emojiPickerModalElement = document.createElement("div");
        emojiPickerModalElement.className = "modal fade";
        emojiPickerModalElement.id = "tm-markdown-emoji-picker-modal";
        emojiPickerModalElement.tabIndex = -1;
        emojiPickerModalElement.setAttribute("aria-labelledby", "tm-markdown-emoji-picker-title");
        emojiPickerModalElement.setAttribute("aria-hidden", "true");
        emojiPickerModalElement.innerHTML = '<div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content"><div class="modal-header"><h2 class="modal-title fs-5" id="tm-markdown-emoji-picker-title">Emoji picker</h2><button class="btn-close" type="button" data-bs-dismiss="modal" aria-label="Close"></button></div><div class="modal-body"><emoji-picker class="tm-editor-emoji-picker w-100" data-source="/ext/emoji-picker-data/en/github/data"></emoji-picker></div></div></div>';
        document.body.appendChild(emojiPickerModalElement);
        var picker = emojiPickerModalElement.querySelector("emoji-picker");
        if (picker) {
            picker.addEventListener("emoji-click", function (event) {
                var detail = event && event.detail ? event.detail : {};
                var unicode = String(detail.unicode || (detail.emoji && detail.emoji.unicode) || "");
                if (unicode === "" || !emojiPickerField) { return; }
                var range = selection(emojiPickerField);
                changed(emojiPickerField, unicode, range.start, range.end, "end");
                var modal = bootstrapModal(emojiPickerModalElement);
                if (modal) { modal.hide(); }
            });
        }
        emojiPickerModalElement.addEventListener("hidden.bs.modal", function () {
            if (!emojiPickerReturnModalElement) { return; }
            var returnElement = emojiPickerReturnModalElement;
            emojiPickerReturnModalElement = null;
            var returnModal = bootstrapModal(returnElement);
            if (returnModal) { returnModal.show(); }
        });
        return emojiPickerModalElement;
    }

    function openEmojiPicker(field) {
        var modalElement = ensureEmojiPickerModal();
        var modal = bootstrapModal(modalElement);
        if (!modal) { return; }
        emojiPickerField = field;
        var picker = modalElement.querySelector("emoji-picker");
        var theme = document.documentElement.getAttribute("data-bs-theme") || "light";
        if (picker) {
            picker.classList.toggle("dark", theme === "dark");
            picker.classList.toggle("light", theme !== "dark");
        }
        if (!emojiPickerModulePromise) {
            emojiPickerModulePromise = import("/ext/emoji-picker-element/index.js").catch(function (error) {
                emojiPickerModulePromise = null;
                console.error("[tinymash] emoji picker load error", error);
            });
        }
        var originModalElement = field.closest(".modal.show");
        if (originModalElement && originModalElement !== modalElement) {
            emojiPickerReturnModalElement = originModalElement;
            originModalElement.addEventListener("hidden.bs.modal", function () { modal.show(); }, { once: true });
            var originModal = bootstrapModal(originModalElement);
            suspendParentModalDirtyCheck(originModalElement);
            if (originModal) { originModal.hide(); }
            return;
        }
        modal.show();
    }

    function headingLevel(root, requested) {
        var level = String(requested || "1").replace(/[^1-6]/g, "") || "1";
        root.dataset.tmMarkdownHeadingLevel = level;
        var label = root.querySelector("[data-tm-markdown-heading-label]");
        if (label) { label.textContent = "H" + level; }
        try { window.localStorage.setItem(headingStorageKey, level); } catch (error) { /* optional persistence */ }
        return level;
    }

    function initialize(root) {
        (root || document).querySelectorAll("[data-tm-markdown-editor]:not([data-tm-markdown-editor-ready])").forEach(function (editor) {
            var field = editor.querySelector("[data-tm-markdown-field]");
            if (!field) { return; }
            editor.setAttribute("data-tm-markdown-editor-ready", "1");
            var autocomplete = editor.querySelector("[data-tm-markdown-emoji-autocomplete]");
            var autocompleteMatches = [];
            var autocompleteIndex = -1;
            var autocompleteTokenStart = -1;
            var autocompleteTokenEnd = -1;
            var autocompleteRequestId = 0;
            var stored = "1";
            try { stored = window.localStorage.getItem(headingStorageKey) || "1"; } catch (error) { stored = "1"; }
            headingLevel(editor, stored);
            function hideEmojiAutocomplete() {
                autocompleteMatches = [];
                autocompleteIndex = -1;
                autocompleteTokenStart = -1;
                autocompleteTokenEnd = -1;
                if (autocomplete) {
                    autocomplete.classList.add("d-none");
                    autocomplete.innerHTML = "";
                }
            }
            function emojiShortcodeQuery() {
                if (!autocomplete || field.selectionStart !== field.selectionEnd) { return null; }
                var match = field.value.slice(0, field.selectionStart).match(/(^|[\s([>{])(:[a-z0-9_+-]{1,40})$/i);
                if (!match) { return null; }
                return { query: match[2].slice(1).toLowerCase(), start: field.selectionStart - match[2].length, end: field.selectionStart };
            }
            function renderEmojiAutocomplete() {
                if (!autocomplete || autocompleteMatches.length === 0) {
                    hideEmojiAutocomplete();
                    return;
                }
                autocomplete.innerHTML = autocompleteMatches.map(function (entry, index) {
                    var active = index === autocompleteIndex;
                    return '<button class="list-group-item list-group-item-action d-flex align-items-center gap-2'
                        + (active ? ' active' : '')
                        + '" type="button" data-tm-markdown-emoji-index="' + index + '" role="option" aria-selected="' + (active ? 'true' : 'false') + '">'
                        + '<span class="tm-emoji fs-5" aria-hidden="true">' + escapeHtml(entry.emoji) + '</span>'
                        + '<span class="d-flex flex-column align-items-start text-start"><span class="font-monospace">:' + escapeHtml(entry.shortcode) + ':</span>'
                        + '<span class="small opacity-75">' + escapeHtml(entry.annotation) + '</span></span></button>';
                }).join("");
                autocomplete.style.top = (field.offsetHeight + 8) + "px";
                autocomplete.classList.remove("d-none");
            }
            function applyEmojiShortcode(entry) {
                if (!entry || autocompleteTokenStart < 0 || autocompleteTokenEnd < autocompleteTokenStart) { return; }
                var nextCharacter = field.value.charAt(autocompleteTokenEnd);
                var suffix = nextCharacter === "" || /\s/.test(nextCharacter) ? " " : "";
                changed(field, ":" + entry.shortcode + ":" + suffix, autocompleteTokenStart, autocompleteTokenEnd, "end");
                hideEmojiAutocomplete();
            }
            function updateEmojiAutocomplete() {
                var requestId = ++autocompleteRequestId;
                var query = emojiShortcodeQuery();
                if (!query) {
                    hideEmojiAutocomplete();
                    return;
                }
                ensureEmojiAutocompleteEntries().then(function (entries) {
                    if (requestId !== autocompleteRequestId) { return; }
                    var latest = emojiShortcodeQuery();
                    if (!latest || latest.query !== query.query || latest.start !== query.start || latest.end !== query.end) { return; }
                    autocompleteTokenStart = query.start;
                    autocompleteTokenEnd = query.end;
                    autocompleteMatches = entries.filter(function (entry) {
                        return entry.shortcode.startsWith(query.query) || entry.searchText.includes(query.query);
                    }).slice(0, 8);
                    autocompleteIndex = autocompleteMatches.length > 0 ? 0 : -1;
                    renderEmojiAutocomplete();
                }).catch(function (error) {
                    hideEmojiAutocomplete();
                    console.error("[tinymash] emoji autocomplete error", error);
                });
            }
            if (autocomplete && autocomplete.id !== "tm-editor-emoji-autocomplete") {
                field.addEventListener("input", updateEmojiAutocomplete);
                field.addEventListener("keydown", function (event) {
                    if (autocompleteMatches.length === 0) { return; }
                    if (event.key === "ArrowDown" || event.key === "ArrowUp") {
                        event.preventDefault();
                        autocompleteIndex = event.key === "ArrowDown"
                            ? (autocompleteIndex + 1) % autocompleteMatches.length
                            : (autocompleteIndex <= 0 ? autocompleteMatches.length - 1 : autocompleteIndex - 1);
                        renderEmojiAutocomplete();
                    } else if (event.key === "Enter" || event.key === "Tab") {
                        event.preventDefault();
                        applyEmojiShortcode(autocompleteMatches[autocompleteIndex]);
                    } else if (event.key === "Escape") {
                        event.preventDefault();
                        hideEmojiAutocomplete();
                    }
                });
                field.addEventListener("blur", function () { window.setTimeout(hideEmojiAutocomplete, 120); });
                autocomplete.addEventListener("mousedown", function (event) { event.preventDefault(); });
                autocomplete.addEventListener("click", function (event) {
                    var button = event.target.closest("[data-tm-markdown-emoji-index]");
                    if (!button) { return; }
                    applyEmojiShortcode(autocompleteMatches[Number(button.dataset.tmMarkdownEmojiIndex)]);
                });
            }
            editor.addEventListener("click", function (event) {
                var button = event.target.closest("[data-tm-markdown-action]");
                if (!button || !editor.contains(button)) { return; }
                var action = button.dataset.tmMarkdownAction || "";
                if (action === "wrap") {
                    var range = selection(field);
                    changed(field, String(button.dataset.prefix || "") + (range.text || String(button.dataset.placeholder || "text")) + String(button.dataset.suffix || ""), range.start, range.end, "select");
                } else if (action === "prefix-lines") {
                    prefixLines(field, String(button.dataset.prefix || ""), String(button.dataset.placeholder || "Text"));
                } else if (action === "wrap-block") {
                    wrapBlock(field, String(button.dataset.prefix || ""), String(button.dataset.suffix || ""), String(button.dataset.placeholder || "code"));
                } else if (action === "heading-level") {
                    prefixLines(field, "#".repeat(Number(headingLevel(editor, button.dataset.level))) + " ", "Heading");
                } else if (action === "heading-apply") {
                    prefixLines(field, "#".repeat(Number(editor.dataset.tmMarkdownHeadingLevel || "1")) + " ", "Heading");
                } else if (action === "callout") {
                    insertCallout(field, String(button.dataset.callout || "NOTE"));
                } else if (action === "table") {
                    insertTable(field);
                } else if (action === "shortcode") {
                    insertShortcode(field, button);
                } else if (action === "external-link") {
                    openLinkModal(field, editor, "external");
                } else if (action === "internal-link") {
                    openLinkModal(field, editor, "internal");
                } else if (action === "emoji-picker") {
                    openEmojiPicker(field);
                }
            });
        });
    }

    window.tmInitializeMarkdownEditors = initialize;
    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", function () { initialize(document); }, { once: true });
    } else {
        initialize(document);
    }
}());
