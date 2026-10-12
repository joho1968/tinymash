(function () {
    "use strict";

    var escapeHtml = function (value) {
        return String(value || "").replace(/[&<>"']/g, function (character) {
            return ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#039;" })[character];
        });
    };

    var getModal = function (modalElement) {
        if (!window.bootstrap || !window.bootstrap.Modal) {
            return null;
        }
        return window.bootstrap.Modal.getOrCreateInstance(modalElement);
    };

    var labelForRecord = function (record) {
        var title = record.alt_text || record.filename || record.media_id;
        return title + (record.owner_username ? " (" + record.owner_username + ")" : "");
    };

    var browserPath = function (path) {
        path = String(path || "");
        return typeof window.tinymashBrowserPath === "function" ? window.tinymashBrowserPath(path) : path;
    };

    var initPicker = function (modalElement) {
        var grid = modalElement.querySelector("[data-tm-admin-image-grid]");
        var filter = modalElement.querySelector("[data-tm-admin-image-filter]");
        var uploadInput = modalElement.querySelector("[data-tm-admin-image-upload]");
        var uploadButton = modalElement.querySelector("[data-tm-admin-image-upload-button]");
        var uploadStatus = modalElement.querySelector("[data-tm-admin-image-upload-status]");
        var recordsByKey = {};
        var source = "recent";
        var activeField = null;
        var contextName = modalElement.getAttribute("data-context-name") || "context_id";

        var getContextId = function () {
            return activeField ? (activeField.getAttribute("data-tm-admin-image-context-id") || "") : "";
        };

        var cacheKey = function (sourceName, contextId) {
            return sourceName + ":" + String(contextId || "");
        };

        var currentRecords = function () {
            return recordsByKey[cacheKey(source, getContextId())] || [];
        };

        var renderGrid = function () {
            if (!grid) {
                return;
            }

            var needle = filter ? String(filter.value || "").trim().toLowerCase() : "";
            var filtered = currentRecords().filter(function (record) {
                if (needle === "") {
                    return true;
                }
                return [record.alt_text, record.filename, record.media_id, record.owner_username].join(" ").toLowerCase().includes(needle);
            });

            if (!filtered.length) {
                grid.innerHTML = '<div class="col-12 small text-body-secondary">No matching images are available.</div>';
                return;
            }

            grid.innerHTML = filtered.map(function (record) {
                var title = record.alt_text || record.filename || record.media_id;
                var thumbnail = browserPath(record.thumbnail_url || record.display_url || record.url);
                var dimensions = Number(record.width) > 0 && Number(record.height) > 0 ? String(record.width) + "x" + String(record.height) : "";
                var detail = [record.owner_username, dimensions].filter(Boolean).join(" | ");
                return '<div class="col-12 col-md-6 col-xl-4"><button class="btn text-start border rounded-3 bg-body h-100 w-100 p-2 d-flex gap-2 overflow-hidden" type="button" data-tm-admin-image-choice="' + escapeHtml(record.media_id) + '" data-tm-admin-image-label="' + escapeHtml(labelForRecord(record)) + '"><img class="rounded-2 object-fit-cover flex-shrink-0" src="' + escapeHtml(thumbnail) + '" alt="" loading="lazy" decoding="async" style="width:4.5rem;height:4.5rem;min-width:4.5rem;"><span class="d-flex flex-column gap-1 flex-grow-1 overflow-hidden" style="min-width:0;"><span class="fw-semibold text-truncate" title="' + escapeHtml(title) + '">' + escapeHtml(title) + '</span><span class="small text-body-secondary text-truncate" title="' + escapeHtml(record.filename || record.media_id) + '">' + escapeHtml(record.filename || record.media_id) + '</span><span class="small text-body-secondary">' + escapeHtml(detail) + '</span></span></button></div>';
            }).join("");
        };

        var rememberRecord = function (record, sourceNames) {
            var contextId = getContextId();
            (Array.isArray(sourceNames) && sourceNames.length ? sourceNames : ["recent", "library"]).forEach(function (sourceName) {
                var key = cacheKey(sourceName, contextId);
                if (!Array.isArray(recordsByKey[key])) {
                    recordsByKey[key] = [];
                }
                recordsByKey[key] = recordsByKey[key].filter(function (existing) {
                    return existing.media_id !== record.media_id;
                });
                recordsByKey[key].unshift(record);
                if (recordsByKey[key].length > 500) {
                    recordsByKey[key] = recordsByKey[key].slice(0, 500);
                }
            });
        };

        var setActiveField = function (mediaId, label, thumbnail) {
            if (!activeField) {
                return;
            }
            var input = activeField.querySelector("[data-tm-admin-image-input]");
            var status = activeField.querySelector("[data-tm-admin-image-status]");
            var clearButton = activeField.querySelector("[data-tm-admin-image-clear]");
            var preview = activeField.querySelector("[data-tm-admin-image-preview]");
            var statusLabel = "Selected: " + label;
            if (input) {
                input.value = mediaId || "";
            }
            if (status) {
                status.textContent = statusLabel;
                status.title = statusLabel;
            }
            if (clearButton) {
                clearButton.classList.remove("d-none");
            }
            if (preview && thumbnail) {
                preview.outerHTML = '<img src="' + escapeHtml(thumbnail) + '" alt="" loading="lazy" decoding="async" style="width:3rem;height:3rem;object-fit:cover;" data-tm-admin-image-preview>';
            }
        };

        var loadRecords = function (sourceName) {
            var contextId = getContextId();
            var key = cacheKey(sourceName, contextId);
            if (Array.isArray(recordsByKey[key])) {
                source = sourceName;
                renderGrid();
                return;
            }

            recordsByKey[key] = [];
            if (grid) {
                grid.innerHTML = '<div class="col-12 small text-body-secondary">Loading images...</div>';
            }
            var data = new FormData();
            data.set("tinymash_csrf", modalElement.getAttribute("data-csrf-token") || "");
            data.set("source", sourceName);
            data.set(contextName, contextId);
            fetch(modalElement.getAttribute("data-picker-url") || "", {
                method: "POST",
                body: data,
                credentials: "same-origin",
                headers: { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" }
            }).then(function (response) {
                return response.json().then(function (payload) {
                    if (!response.ok) {
                        throw new Error(payload.error || "Unable to load images.");
                    }
                    return payload;
                });
            }).then(function (payload) {
                recordsByKey[key] = Array.isArray(payload.records) ? payload.records : [];
                source = sourceName;
                renderGrid();
            }).catch(function (error) {
                recordsByKey[key] = [];
                if (grid) {
                    grid.innerHTML = '<div class="col-12 text-danger small">' + escapeHtml(error.message || "Unable to load images.") + '</div>';
                }
            });
        };

        document.querySelectorAll("[data-tm-admin-image-open]").forEach(function (button) {
            button.addEventListener("click", function () {
                activeField = button.closest("[data-tm-admin-image-field]");
                source = "recent";
                if (filter) {
                    filter.value = "";
                }
                if (uploadInput) {
                    uploadInput.value = "";
                }
                modalElement.querySelectorAll("[data-tm-admin-image-source]").forEach(function (sourceButton) {
                    sourceButton.classList.toggle("active", sourceButton.getAttribute("data-tm-admin-image-source") === source);
                });
                loadRecords(source);
                var modal = getModal(modalElement);
                if (modal) {
                    modal.show();
                }
            });
        });

        modalElement.querySelectorAll("[data-tm-admin-image-source]").forEach(function (button) {
            button.addEventListener("click", function () {
                source = button.getAttribute("data-tm-admin-image-source") === "library" ? "library" : "recent";
                modalElement.querySelectorAll("[data-tm-admin-image-source]").forEach(function (sourceButton) {
                    sourceButton.classList.toggle("active", sourceButton === button);
                });
                if (filter) {
                    filter.value = "";
                }
                loadRecords(source);
            });
        });

        document.querySelectorAll("[data-tm-admin-image-clear]").forEach(function (button) {
            button.addEventListener("click", function () {
                var field = button.closest("[data-tm-admin-image-field]");
                var input = field ? field.querySelector("[data-tm-admin-image-input]") : null;
                var status = field ? field.querySelector("[data-tm-admin-image-status]") : null;
                var preview = field ? field.querySelector("[data-tm-admin-image-preview]") : null;
                if (input) {
                    input.value = "";
                }
                if (status) {
                    status.textContent = "No image selected.";
                    status.title = "No image selected.";
                }
                if (preview) {
                    preview.outerHTML = '<span class="bi bi-image text-body-secondary" aria-hidden="true" data-tm-admin-image-preview></span>';
                }
                button.classList.add("d-none");
            });
        });

        if (uploadButton && uploadInput) {
            uploadButton.addEventListener("click", function () {
                var file = uploadInput.files && uploadInput.files.length ? uploadInput.files[0] : null;
                var contextId = getContextId();
                if (!file || !contextId) {
                    if (uploadStatus) {
                        uploadStatus.textContent = "Choose an image file first.";
                    }
                    return;
                }

                uploadButton.disabled = true;
                uploadInput.disabled = true;
                if (uploadStatus) {
                    uploadStatus.textContent = "Uploading image...";
                }
                var data = new FormData();
                data.set("tinymash_csrf", modalElement.getAttribute("data-csrf-token") || "");
                data.set(contextName, contextId);
                data.set(modalElement.getAttribute("data-upload-field-name") || "image", file);
                fetch(modalElement.getAttribute("data-upload-url") || "", {
                    method: "POST",
                    body: data,
                    credentials: "same-origin",
                    headers: { "Accept": "application/json", "X-Requested-With": "XMLHttpRequest" }
                }).then(function (response) {
                    return response.json().then(function (payload) {
                        if (!response.ok) {
                            throw new Error(payload.error || "Image upload failed.");
                        }
                        return payload;
                    });
                }).then(function (payload) {
                    var record = payload.record || {};
                    if (!record.media_id) {
                        throw new Error("Image upload did not return a usable media record.");
                    }
                    rememberRecord(record);
                    setActiveField(record.media_id, labelForRecord(record), browserPath(record.thumbnail_url || record.display_url || record.url));
                    uploadInput.value = "";
                    if (uploadStatus) {
                        uploadStatus.textContent = "Image uploaded and selected.";
                    }
                    renderGrid();
                }).catch(function (error) {
                    if (uploadStatus) {
                        uploadStatus.textContent = error.message || "Image upload failed.";
                    }
                }).finally(function () {
                    if (!uploadButton.hasAttribute("data-disabled-by-policy")) {
                        uploadButton.disabled = false;
                    }
                    if (!uploadInput.hasAttribute("data-disabled-by-policy")) {
                        uploadInput.disabled = false;
                    }
                });
            });
        }

        if (filter) {
            filter.addEventListener("input", renderGrid);
        }

        if (grid) {
            grid.addEventListener("click", function (event) {
                var button = event.target instanceof Element ? event.target.closest("[data-tm-admin-image-choice]") : null;
                if (!button || !activeField) {
                    return;
                }
                var mediaId = button.getAttribute("data-tm-admin-image-choice") || "";
                var label = button.getAttribute("data-tm-admin-image-label") || "";
                var record = currentRecords().find(function (candidate) {
                    return candidate.media_id === mediaId;
                }) || {};
                if (record.media_id) {
                    rememberRecord(record, ["recent"]);
                }
                setActiveField(mediaId, label, browserPath(record.thumbnail_url || record.display_url || record.url || ""));
                var modal = getModal(modalElement);
                if (modal) {
                    modal.hide();
                }
            });
        }
    };

    document.querySelectorAll("[data-tm-admin-image-picker]").forEach(initPicker);
}());
