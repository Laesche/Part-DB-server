/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 */

import { Controller } from "@hotwired/stimulus";
import { Html5QrcodeScanner, Html5Qrcode } from "@part-db/html5-qrcode";

/* stimulusFetch: 'lazy' */

export default class extends Controller {
    static targets = ["reader", "currentLocation", "status"];

    static values = {
        scanUrl: String,
        csrfToken: String,
    };

    _scanner = null;
    _busy = false;
    _lastDecodedText = "";
    _currentStorageLocationId = 0;
    _currentStorageLocationName = "";

    connect() {
        if (this._scanner) {
            return;
        }

        Html5Qrcode.getCameras().catch(() => {
            this._setStatus("No camera found. Please check camera permissions in your browser.", "warning");
        });

        const isMobile = window.matchMedia("(max-width: 768px)").matches;
        const qrboxFunction = (viewfinderWidth, viewfinderHeight) => {
            const minEdgeSize = Math.min(viewfinderWidth, viewfinderHeight);
            const qrboxSize = Math.floor(minEdgeSize * 0.7);
            return { width: qrboxSize, height: qrboxSize };
        };

        this._scanner = new Html5QrcodeScanner(
            this.readerTarget.id,
            {
                fps: 10,
                qrbox: qrboxFunction,
                ...(isMobile ? { aspectRatio: 1.0 } : {}),
                experimentalFeatures: {
                    useBarCodeDetectorIfSupported: true,
                },
            },
            false
        );

        this._scanner.render(this.onScanSuccess.bind(this));
        this._renderCurrentLocation();
        this._setStatus("Scan a storage location label to begin allocating items.", "info");
    }

    disconnect() {
        const scanner = this._scanner;
        this._scanner = null;
        this._busy = false;
        this._lastDecodedText = "";

        if (!scanner) {
            return;
        }

        try {
            const p = scanner.clear?.();
            if (p && typeof p.then === "function") {
                p.catch(() => {});
            }
        } catch (_) {
            // ignore
        }
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
                    storageLocationId: this._currentStorageLocationId,
                }),
            });

            const data = await response.json();
            if (!response.ok || !data.ok) {
                this._setStatus(data.message || "This code cannot be used here.", "warning");
                this._busy = false;
                return;
            }

            if (data.storageLocationId && data.storageLocationName) {
                this._currentStorageLocationId = Number(data.storageLocationId) || 0;
                this._currentStorageLocationName = String(data.storageLocationName);
                this._renderCurrentLocation();
            }

            this._setStatus(data.message || "Scan processed.", "success");
            this._busy = false;
        } catch (_) {
            this._setStatus("Failed to process the scan.", "danger");
            this._busy = false;
        }
    }

    resetLocation() {
        this._currentStorageLocationId = 0;
        this._currentStorageLocationName = "";
        this._renderCurrentLocation();
        this._lastDecodedText = "";
        this._setStatus("Current storage location cleared. Scan a storage location label.", "info");
    }

    _renderCurrentLocation() {
        if (!this.hasCurrentLocationTarget) {
            return;
        }

        if (this._currentStorageLocationId > 0 && this._currentStorageLocationName) {
            this.currentLocationTarget.className = "alert alert-success mb-3";
            this.currentLocationTarget.textContent = `Current location: ${this._currentStorageLocationName}`;
            return;
        }

        this.currentLocationTarget.className = "alert alert-secondary mb-3";
        this.currentLocationTarget.textContent = "Current location: none selected";
    }

    _setStatus(message, level) {
        if (!this.hasStatusTarget) {
            return;
        }

        this.statusTarget.className = `alert alert-${level}`;
        this.statusTarget.textContent = message;
    }
}
