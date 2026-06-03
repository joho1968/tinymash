(function () {
    "use strict";

    const player = document.getElementById("tm-signage-player");
    if (!player) {
        return;
    }

    const stage = player.querySelector(".tm-signage-stage");
    const endpoint = player.dataset.slideEndpoint || "";
    const manifestEndpoint = player.dataset.manifestEndpoint || "";
    const startPrompt = player.querySelector("[data-signage-start-prompt]");
    let slides = [];
    try {
        slides = JSON.parse(player.dataset.slides || "[]");
    } catch (error) {
        slides = [];
    }

    let currentIndex = -1;
    let currentFrame = null;
    let timer = null;
    let paused = false;
    let started = !startPrompt;
    let controlsTimer = null;
    let requestSerial = 0;
    let manifestHash = player.dataset.manifestHash || "";
    let manifestRefresh = null;

    const transitionNames = new Set(["none", "fade", "slide-left", "slide-right", "slide-up", "slide-down"]);
    const fitNames = new Set(["cover", "contain", "stretch", "center", "tile"]);
    const overlayAreas = new Set(["none", "full", "content"]);

    function overlayArea(background) {
        const area = String(background.overlay_area || "");
        if (overlayAreas.has(area)) {
            return area;
        }
        return background.overlay && background.overlay !== "none" ? "full" : "none";
    }

    function applyOverlayVars(element, background) {
        const rgb = String(background.overlay_rgb || (background.overlay === "light" ? "255 255 255" : "0 0 0"));
        const opacity = Math.max(0, Math.min(0.9, Number(background.overlay_opacity) || (background.overlay === "light" ? 0.54 : 0.46)));
        element.style.setProperty("--tm-signage-overlay-rgb", rgb);
        element.style.setProperty("--tm-signage-overlay-opacity", String(opacity));
    }

    function revealControls() {
        if (!started) {
            return;
        }
        player.classList.add("tm-signage-controls-visible");
        window.clearTimeout(controlsTimer);
        controlsTimer = window.setTimeout(function () {
            if (!player.querySelector(".tm-signage-controls:focus-within")) {
                player.classList.remove("tm-signage-controls-visible");
            }
        }, 2800);
    }

    function setToggleIcon() {
        const button = player.querySelector('[data-signage-command="toggle"]');
        if (!button) {
            return;
        }
        const icon = button.querySelector(".bi");
        const text = button.querySelector(".visually-hidden");
        button.title = paused ? "Play playback" : "Pause playback";
        if (icon) {
            icon.className = paused ? "bi bi-play-fill" : "bi bi-pause-fill";
        }
        if (text) {
            text.textContent = paused ? "Play playback" : "Pause playback";
        }
    }

    function clearSchedule() {
        window.clearTimeout(timer);
        timer = null;
    }

    function buildFrame(payload) {
        const frame = document.createElement("section");
        frame.className = "tm-signage-frame";
        frame.setAttribute("aria-label", "Current slide");

        const background = payload.background || {};
        if (background.url) {
            const backgroundElement = document.createElement("div");
            const fit = fitNames.has(background.fit) ? background.fit : "cover";
            backgroundElement.className = "tm-signage-background tm-signage-fit-" + fit;
            if (fit === "tile") {
                backgroundElement.style.backgroundImage = "url(" + JSON.stringify(background.url) + ")";
            }
            const image = document.createElement("img");
            image.src = background.url;
            image.alt = "";
            image.setAttribute("aria-hidden", "true");
            backgroundElement.appendChild(image);
            frame.appendChild(backgroundElement);
        }
        const area = overlayArea(background);
        if (area === "full") {
            const overlay = document.createElement("div");
            overlay.className = "tm-signage-overlay";
            applyOverlayVars(overlay, background);
            frame.appendChild(overlay);
        }

        const surface = document.createElement("div");
        surface.className = "tm-signage-surface";
        if (area === "content") {
            surface.classList.add("tm-signage-overlay-content");
            applyOverlayVars(surface, background);
        }
        surface.innerHTML = payload.html || "";
        frame.appendChild(surface);
        return frame;
    }

    function scheduleNext(seconds) {
        clearSchedule();
        if (paused) {
            return;
        }
        const delay = Math.max(2, Number(seconds) || 10) * 1000;
        timer = window.setTimeout(function () {
            showRelative(1);
        }, delay);
    }

    function applyManifest(manifest) {
        if (!manifest || !Array.isArray(manifest.slides)) {
            return;
        }
        slides = manifest.slides.filter(function (slide) {
            return slide && typeof slide.id === "string" && slide.id !== "";
        }).map(function (slide) {
            return { id: slide.id };
        });
        if (typeof manifest.hash === "string" && manifest.hash !== "") {
            manifestHash = manifest.hash;
            player.dataset.manifestHash = manifestHash;
        }
        const colorMode = manifest.color_mode === "light" ? "light" : "dark";
        document.documentElement.dataset.bsTheme = colorMode;
        document.body.classList.toggle("tm-signage-mode-light", colorMode === "light");
        document.body.classList.toggle("tm-signage-mode-dark", colorMode === "dark");
        player.dataset.honorReducedMotion = manifest.honor_reduced_motion ? "1" : "0";
        const contentScale = Math.max(60, Math.min(200, Number(manifest.content_scale_percent) || 100));
        player.dataset.contentScalePercent = String(contentScale);
        player.style.setProperty("--tm-signage-content-scale", String(contentScale / 100));
    }

    function refreshManifest() {
        if (!manifestEndpoint) {
            return Promise.resolve();
        }
        if (manifestRefresh) {
            return manifestRefresh;
        }
        const headers = { "Accept": "application/json" };
        if (manifestHash) {
            headers["If-None-Match"] = '"' + manifestHash + '"';
        }
        manifestRefresh = fetch(manifestEndpoint, {
            cache: "no-store",
            credentials: "same-origin",
            headers: headers
        }).then(function (response) {
            if (response.status === 304) {
                return null;
            }
            if (!response.ok) {
                throw new Error("manifest unavailable");
            }
            return response.json();
        }).then(function (manifest) {
            if (manifest) {
                applyManifest(manifest);
            }
        }).catch(function () {
            return null;
        }).finally(function () {
            manifestRefresh = null;
        });
        return manifestRefresh;
    }

    function present(payload, index) {
        const transition = transitionNames.has(payload.transition) ? payload.transition : "fade";
        const nextFrame = buildFrame(payload);
        nextFrame.classList.add("tm-signage-enter-" + transition);
        stage.appendChild(nextFrame);

        const previous = currentFrame;
        if (transition === "none") {
            if (previous) {
                previous.remove();
            } else {
                const empty = stage.querySelector(".tm-signage-empty");
                if (empty) { empty.remove(); }
            }
            currentFrame = nextFrame;
            currentIndex = index;
            scheduleNext(payload.delay_seconds);
            return;
        }
        if (currentFrame) {
            previous.addEventListener("transitionend", function () {
                previous.remove();
            }, { once: true });
            window.setTimeout(function () {
                previous.remove();
            }, 800);
        } else {
            const empty = stage.querySelector(".tm-signage-empty");
            if (empty) {
                empty.remove();
            }
        }
        window.requestAnimationFrame(function () {
            window.requestAnimationFrame(function () {
                nextFrame.classList.remove("tm-signage-enter-" + transition);
                if (previous) {
                    previous.classList.add("tm-signage-exit-" + transition);
                }
            });
        });
        currentFrame = nextFrame;
        currentIndex = index;
        scheduleNext(payload.delay_seconds);
    }

    async function loadIndex(index, attempts) {
        if (!slides.length || attempts >= slides.length) {
            if (!currentFrame) {
                const detail = stage.querySelector(".tm-signage-empty-detail");
                if (detail) {
                    detail.textContent = "No available slides in this loop. Retrying...";
                }
            }
            scheduleNext(10);
            return;
        }
        const normalizedIndex = (index + slides.length) % slides.length;
        const slide = slides[normalizedIndex];
        const serial = ++requestSerial;
        try {
            const response = await fetch(endpoint + encodeURIComponent(slide.id), {
                cache: "no-store",
                credentials: "same-origin",
                headers: { "Accept": "application/json" }
            });
            if (!response.ok) {
                throw new Error("slide unavailable");
            }
            const payload = await response.json();
            if (serial !== requestSerial) {
                return;
            }
            present(payload, normalizedIndex);
        } catch (error) {
            if (serial !== requestSerial) {
                return;
            }
            loadIndex(normalizedIndex + 1, attempts + 1);
        }
    }

    async function showRelative(direction) {
        if (!started) {
            return;
        }
        clearSchedule();
        if (direction > 0 && currentIndex >= 0 && currentIndex + direction >= slides.length) {
            await refreshManifest();
            loadIndex(0, 0);
            return;
        }
        const start = currentIndex < 0 ? (direction < 0 ? slides.length - 1 : 0) : currentIndex + direction;
        loadIndex(start, 0);
    }

    function togglePlayback() {
        paused = !paused;
        setToggleIcon();
        if (paused) {
            clearSchedule();
        } else if (currentIndex >= 0) {
            showRelative(1);
        } else {
            showRelative(1);
        }
    }

    function toggleFullscreen() {
        if (document.fullscreenElement) {
            const exiting = document.exitFullscreen();
            if (exiting && typeof exiting.catch === "function") {
                exiting.catch(function () {});
            }
        } else if (player.requestFullscreen) {
            const entering = player.requestFullscreen();
            if (entering && typeof entering.catch === "function") {
                entering.catch(function () {});
            }
        }
    }

    function startPlayback(fullscreen) {
        if (fullscreen && !document.fullscreenElement) {
            toggleFullscreen();
        }
        if (startPrompt) {
            startPrompt.remove();
        }
        if (started) {
            return;
        }
        started = true;
        showRelative(1);
    }

    player.addEventListener("pointermove", revealControls);
    player.addEventListener("pointerdown", revealControls);
    player.querySelectorAll("[data-signage-command]").forEach(function (button) {
        button.addEventListener("click", function () {
            revealControls();
            const command = button.dataset.signageCommand;
            if (command === "previous") {
                showRelative(-1);
            } else if (command === "next") {
                showRelative(1);
            } else if (command === "toggle") {
                togglePlayback();
            } else if (command === "fullscreen") {
                toggleFullscreen();
            }
        });
    });
    if (startPrompt) {
        const fullscreenStart = startPrompt.querySelector("[data-signage-start-fullscreen]");
        const windowStart = startPrompt.querySelector("[data-signage-start-window]");
        if (fullscreenStart) {
            fullscreenStart.addEventListener("click", function () {
                startPlayback(true);
            });
        }
        if (windowStart) {
            windowStart.addEventListener("click", function () {
                startPlayback(false);
            });
        }
    }
    document.addEventListener("keydown", function (event) {
        if (!started) {
            return;
        }
        if (event.key === "ArrowRight") {
            showRelative(1);
            revealControls();
        } else if (event.key === "ArrowLeft") {
            showRelative(-1);
            revealControls();
        } else if (event.key === " " || event.key === "Spacebar") {
            event.preventDefault();
            togglePlayback();
            revealControls();
        } else if (event.key.toLowerCase() === "f") {
            toggleFullscreen();
            revealControls();
        }
    });

    setToggleIcon();
    if (started) {
        showRelative(1);
    }
}());
