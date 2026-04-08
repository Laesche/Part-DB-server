/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 */

import { Controller } from "@hotwired/stimulus";
import { Html5Qrcode } from "@part-db/html5-qrcode";

/* stimulusFetch: 'lazy' */

const CAMERA_STORAGE_KEY = "stock-terminal-camera-id";

export default class extends Controller {
    static targets = [
        "reader",
        "status",
        "locationBanner",
        "modal",
        "modalTitle",
        "modalSubtitle",
        "partPanel",
        "storagePanel",
        "partName",
        "partCategory",
        "partStock",
        "partLocation",
        "partAmount",
        "storageName",
        "stockAmount",
        "cameraModal",
        "cameraList",
        "searchModal",
        "searchInput",
        "searchResults",
    ];

    static values = {
        scanUrl: String,
        stockUrl: String,
        partDetailsUrlTemplate: String,
        searchUrlTemplate: String,
        partUrlTemplate: String,
        csrfToken: String,
    };

    _scanner = null;
    _scannerRunning = false;
    _busy = false;
    _lastDecodedText = "";
    _activeLocation = null;
    _activePart = null;
    _activeStorage = null;
    _cameras = [];
    _searchTimer = null;

    async connect() {
        if (this._scanner) {
            return;
        }

        this._scanner = new Html5Qrcode(this.readerTarget.id);
        await this._initializeScanner();
    }

    async disconnect() {
        if (this._searchTimer) {
            clearTimeout(this._searchTimer);
        }

        await this._stopScanner();

        if (!this._scanner) {
            return;
        }

        try {
            await this._scanner.clear();
        } catch (_) {
            // ignore
        }

        this._scanner = null;
    }

    async onScanSuccess(decodedText) {
        if (this._busy || !decodedText) {
            return;
        }

        const normalized = String(decodedText).trim();
        if (!normalized || normalized === this._lastDecodedText) {
            return;
        }

        this._busy = true;
        this._lastDecodedText = normalized;
        this._setStatus("Processing scan...", "info");

        try {
            const response = await fetch(this.scanUrlValue, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                },
                body: JSON.stringify({
                    _csrf_token: this.csrfTokenValue,
                    input: normalized,
                    storageLocationId: this._activeLocation?.id ?? 0,
                }),
            });

            const data = await response.json();
            if (!response.ok || !data.ok) {
                this._setStatus(data.message || "This code cannot be used here.", "danger");
                this._busy = false;
                return;
            }

            this._setStatus(data.message || "Scan processed.", "success");

            if (data.mode === "storage" && data.storageLocation) {
                this._activeStorage = data.storageLocation;
                this._showStorageModal(data.storageLocation);
            } else if (data.mode === "part" && data.part) {
                this._activePart = data.part;
                this._showPartModal(data.part);
            }
        } catch (_) {
            this._setStatus("Failed to process the scan.", "danger");
        }

        this._busy = false;
    }

    closeModal() {
        this.modalTarget.classList.add("d-none");
        this._activePart = null;
        this._activeStorage = null;
        this._lastDecodedText = "";
    }

    openSearch() {
        this.searchModalTarget.classList.remove("d-none");
        this.searchInputTarget.value = "";
        this.searchResultsTarget.innerHTML = "";
        window.setTimeout(() => this.searchInputTarget.focus(), 50);
    }

    closeSearch() {
        this.searchModalTarget.classList.add("d-none");
        this.searchInputTarget.value = "";
        this.searchResultsTarget.innerHTML = "";
    }

    onSearchInput() {
        if (this._searchTimer) {
            clearTimeout(this._searchTimer);
        }

        this._searchTimer = window.setTimeout(() => {
            this._performSearch().catch(() => {
                this.searchResultsTarget.innerHTML = `<div class="terminal-search-empty">Search failed.</div>`;
            });
        }, 180);
    }

    async selectSearchResult(event) {
        const button = event.currentTarget;
        const partId = Number(button?.dataset.partId ?? 0);
        if (!partId) {
            return;
        }

        const url = this.partDetailsUrlTemplateValue
            .replace("__ID__", String(partId))
            + `?storageLocationId=${encodeURIComponent(String(this._activeLocation?.id ?? 0))}`;

        try {
            const response = await fetch(url, {
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                },
            });
            const data = await response.json();
            if (!response.ok || !data.ok || !data.part) {
                this._setStatus(data.message || "Could not open part from search.", "danger");
                return;
            }

            this.closeSearch();
            this._activePart = data.part;
            this._showPartModal(data.part);
            this._setStatus(data.message || "Part opened from search.", "success");
        } catch (_) {
            this._setStatus("Could not open part from search.", "danger");
        }
    }

    activateLocationFlow() {
        if (!this._activeStorage) {
            return;
        }

        this._activeLocation = {
            id: this._activeStorage.id,
            fullPath: this._activeStorage.fullPath,
        };
        this._renderActiveLocation();
        this._setStatus(`Next scanned parts will be assigned to ${this._activeStorage.fullPath}.`, "success");
        this.closeModal();
    }

    clearLocationFlow() {
        this._activeLocation = null;
        this._renderActiveLocation();
        this._setStatus("Add-to-location mode disabled.", "info");
    }

    openStoragePartsList() {
        if (this._activeStorage?.partsListUrl) {
            window.location.href = this._activeStorage.partsListUrl;
        }
    }

    openPart() {
        if (this._activePart?.openUrl) {
            window.location.href = this._activePart.openUrl;
        }
    }

    async addStock() {
        await this._changeStock("add");
    }

    async reduceStock() {
        await this._changeStock("reduce");
    }

    async reopenCameraPicker() {
        await this._stopScanner();
        this._showCameraModal();
    }

    async chooseCamera(event) {
        const cameraId = String(event.currentTarget?.dataset.cameraId ?? "");
        if (!cameraId) {
            return;
        }

        localStorage.setItem(CAMERA_STORAGE_KEY, cameraId);
        this.cameraModalTarget.classList.add("d-none");
        await this._startScanner(cameraId);
    }

    async _initializeScanner() {
        try {
            this._cameras = await Html5Qrcode.getCameras();
        } catch (_) {
            this._setStatus("No camera found. Please check camera permissions in your browser.", "warning");
            return;
        }

        if (!Array.isArray(this._cameras) || this._cameras.length === 0) {
            this._setStatus("No camera found. Please check camera permissions in your browser.", "warning");
            return;
        }

        const savedCameraId = localStorage.getItem(CAMERA_STORAGE_KEY);
        const matchingCamera = this._cameras.find((camera) => camera.id === savedCameraId);

        if (matchingCamera) {
            await this._startScanner(matchingCamera.id);
            return;
        }

        if (this._cameras.length === 1) {
            localStorage.setItem(CAMERA_STORAGE_KEY, this._cameras[0].id);
            await this._startScanner(this._cameras[0].id);
            return;
        }

        this._showCameraModal();
    }

    async _startScanner(cameraId) {
        if (!this._scanner) {
            return;
        }

        await this._stopScanner();

        const isMobile = window.matchMedia("(max-width: 768px)").matches;
        const qrbox = (viewfinderWidth, viewfinderHeight) => {
            const minEdgeSize = Math.min(viewfinderWidth, viewfinderHeight);
            const qrboxSize = Math.floor(minEdgeSize * 0.72);
            return { width: qrboxSize, height: qrboxSize };
        };

        try {
            await this._scanner.start(
                cameraId,
                {
                    fps: 10,
                    qrbox,
                    ...(isMobile ? { aspectRatio: 1.0 } : {}),
                    experimentalFeatures: {
                        useBarCodeDetectorIfSupported: true,
                    },
                },
                this.onScanSuccess.bind(this),
                () => {}
            );
            this._scannerRunning = true;
            this._setStatus("Scanner ready. Point the camera at a code.", "info");
        } catch (_) {
            this._showCameraModal();
            this._setStatus("Could not start the selected camera.", "danger");
        }
    }

    async _stopScanner() {
        if (!this._scanner || !this._scannerRunning) {
            return;
        }

        try {
            await this._scanner.stop();
        } catch (_) {
            // ignore
        }

        this._scannerRunning = false;
    }

    _showCameraModal() {
        if (!this.hasCameraModalTarget) {
            return;
        }

        this.cameraListTarget.innerHTML = this._cameras.map((camera) => `
            <button type="button" class="terminal-camera-option" data-camera-id="${this._escapeHtml(camera.id)}" data-action="click->pages--stock-terminal#chooseCamera">
                <span class="terminal-camera-option__name">${this._escapeHtml(camera.label || "Camera")}</span>
                <span class="terminal-camera-option__meta">${this._escapeHtml(camera.id)}</span>
            </button>
        `).join("");
        this.cameraModalTarget.classList.remove("d-none");
    }

    async _performSearch() {
        const query = this.searchInputTarget.value.trim();
        if (query === "") {
            this.searchResultsTarget.innerHTML = `<div class="terminal-search-empty">Type a part name, category, or description.</div>`;
            return;
        }

        const url = this.searchUrlTemplateValue.replace("__QUERY__", encodeURIComponent(query));
        const response = await fetch(url, {
            headers: {
                "X-Requested-With": "XMLHttpRequest",
            },
        });
        const results = await response.json();

        if (!Array.isArray(results) || results.length === 0) {
            this.searchResultsTarget.innerHTML = `<div class="terminal-search-empty">No parts found.</div>`;
            return;
        }

        this.searchResultsTarget.innerHTML = results.slice(0, 20).map((part) => `
            <button type="button" class="terminal-search-result" data-part-id="${this._escapeHtml(part.id)}" data-action="click->pages--stock-terminal#selectSearchResult">
                <span class="terminal-search-result__title">${this._escapeHtml(part.name)}</span>
                <span class="terminal-search-result__meta">${this._escapeHtml(part.category || "")}</span>
                <span class="terminal-search-result__description">${this._escapeHtml(part.description || "")}</span>
            </button>
        `).join("");
    }

    async _changeStock(action) {
        if (!this._activePart) {
            return;
        }

        const amount = parseFloat(this.stockAmountTarget.value || "0");
        if (!(amount > 0)) {
            this._setStatus("Enter an amount greater than 0.", "warning");
            return;
        }

        try {
            const response = await fetch(this.stockUrlValue, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                },
                body: JSON.stringify({
                    _csrf_token: this.csrfTokenValue,
                    action,
                    amount,
                    partId: this._activePart.id,
                    lotId: this._activePart.lotId ?? 0,
                    storageLocationId: this._activeLocation?.id ?? 0,
                }),
            });

            const data = await response.json();
            if (!response.ok || !data.ok) {
                this._setStatus(data.message || "Could not change stock.", "danger");
                return;
            }

            this._activePart = data.part;
            this._showPartModal(data.part);
            this._setStatus(data.message || "Stock updated.", "success");
            this.stockAmountTarget.value = "1";
        } catch (_) {
            this._setStatus("Failed to change stock.", "danger");
        }
    }

    _showPartModal(part) {
        this.modalTitleTarget.textContent = part.newlyCreated ? "New Part Added" : "Part";
        this.modalSubtitleTarget.textContent = part.newlyCreated
            ? "The scanned code created a new Part-DB entry."
            : "Inspect stock and book parts in or out.";
        this.partPanelTarget.classList.remove("d-none");
        this.storagePanelTarget.classList.add("d-none");

        this.partNameTarget.textContent = part.name || "Unnamed part";
        this.partCategoryTarget.textContent = part.category || "No category";
        this.partStockTarget.textContent = part.stockUnknown
            ? `>= ${this._formatAmount(part.overallStock)} overall`
            : `${this._formatAmount(part.overallStock)} overall`;
        this.partLocationTarget.textContent = part.storageLocationsLabel || "No storage location assigned";
        this.partAmountTarget.textContent = part.lotUnknown
            ? "Selected lot stock unknown"
            : `Selected lot: ${this._formatAmount(part.lotAmount ?? 0)}`;

        this.modalTarget.classList.remove("d-none");
    }

    _showStorageModal(storage) {
        this.modalTitleTarget.textContent = "Storage Location";
        this.modalSubtitleTarget.textContent = "Choose what to do with this location.";
        this.partPanelTarget.classList.add("d-none");
        this.storagePanelTarget.classList.remove("d-none");

        this.storageNameTarget.textContent = storage.fullPath || storage.name || "Storage Location";
        this.modalTarget.classList.remove("d-none");
    }

    _renderActiveLocation() {
        if (!this.hasLocationBannerTarget) {
            return;
        }

        if (this._activeLocation?.id) {
            this.locationBannerTarget.classList.remove("d-none");
            this.locationBannerTarget.innerHTML = `
                <span>Adding scanned parts to <strong>${this._escapeHtml(this._activeLocation.fullPath)}</strong></span>
                <button type="button" class="terminal-chip terminal-chip--danger" data-action="click->pages--stock-terminal#clearLocationFlow">Exit</button>
            `;
            return;
        }

        this.locationBannerTarget.classList.add("d-none");
        this.locationBannerTarget.innerHTML = "";
    }

    _setStatus(message, level) {
        if (!this.hasStatusTarget) {
            return;
        }

        this.statusTarget.className = `terminal-status terminal-status--${level}`;
        this.statusTarget.textContent = message;
    }

    _formatAmount(value) {
        const number = Number(value ?? 0);
        if (Number.isInteger(number)) {
            return String(number);
        }

        return number.toFixed(3).replace(/\.?0+$/, "");
    }

    _escapeHtml(value) {
        return String(value)
            .replaceAll("&", "&amp;")
            .replaceAll("<", "&lt;")
            .replaceAll(">", "&gt;")
            .replaceAll("\"", "&quot;")
            .replaceAll("'", "&#039;");
    }
}
