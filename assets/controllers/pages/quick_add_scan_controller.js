/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 */

import { Controller } from "@hotwired/stimulus";
import { Html5QrcodeScanner, Html5Qrcode } from "@part-db/html5-qrcode";
import Modal from "bootstrap/js/dist/modal";

/* stimulusFetch: 'lazy' */

export default class extends Controller {
    static targets = ["reader"];
    static phomymoTabName = "partdb-phomymo";

    static values = {
        lookupUrl: String,
        confirmUrl: String,
        clearPendingUrl: String,
        storageLookupUrl: String,
        storageCreateUrl: String,
        csrfToken: String,
    };

    _scanner = null;
    _modal = null;
    _busy = false;
    _cameraStopped = false;
    _modalHiddenBound = false;
    _lastDecodedText = "";
    _activeScanToken = null;
    _scanStorageMode = false;

    connect() {
        if (this._scanner) {
            return;
        }

        const modalElement = document.getElementById("quick-add-confirm-modal");
        this._modal = modalElement ? new Modal(modalElement) : null;

        if (modalElement && !this._modalHiddenBound) {
            modalElement.addEventListener("hidden.bs.modal", () => {
                this._activeScanToken = null;
                this._busy = false;
                this._lastDecodedText = "";
                this._setStatus("", "secondary");
                this._clearPendingTokens();
                if (this._cameraStopped) {
                    this._startScanner();
                }
            });
            this._modalHiddenBound = true;
        }

        Html5Qrcode.getCameras().catch(() => {
            document.getElementById("scanner-warning")?.classList.remove("d-none");
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
        this._cameraStopped = false;
    }

    disconnect() {
        const scanner = this._scanner;
        this._scanner = null;
        this._busy = false;
        this._cameraStopped = false;
        this._lastDecodedText = "";
        this._activeScanToken = null;

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
        this._setStatus(this._scanStorageMode ? "Checking storage barcode..." : "Checking barcode...", "info");

        try {
            const response = await fetch(this._scanStorageMode ? this.storageLookupUrlValue : this.lookupUrlValue, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                },
                body: JSON.stringify({ input: normalized }),
            });

            const data = await response.json();
            if (!response.ok || !data.ok) {
                if (data?.isEigp114) {
                    await this._stopCameraStream();
                    this._setStatus("EIGP114 detected. Waited 20 seconds for provider info. Please try again.", "warning");
                    this._busy = false;
                    return;
                }
                this._setStatus(data.message || "This code cannot be used.", "warning");
                this._busy = false;
                return;
            }

            if (this._scanStorageMode) {
                this._applyScannedStorageLocation(data);
                this._scanStorageMode = false;
                this._busy = false;
                this._setStatus(`Storage location set to ${data.storageLocationName}.`, "success");
                return;
            }

            if (data.redirectUrl) {
                window.location.href = data.redirectUrl;
                return;
            }

            if (data?.isEigp114) {
                await this._stopCameraStream();
            }

            this._activeScanToken = data.scanToken;
            this._fillModal(data);
            this._modal?.show();
            this._setStatus("", "secondary");
        } catch (_) {
            this._setStatus("Failed to process the scan.", "danger");
            this._busy = false;
        }
    }

    toggleLocation() {
        // no-op, storage selector is always shown
    }

    startStorageScanMode() {
        this._scanStorageMode = true;
        this._lastDecodedText = "";
        this._setStatus("Scan a storage location label now...", "info");
    }

    async createStorageLocation() {
        const name = window.prompt("New storage location name:");
        if (!name || !name.trim()) {
            return;
        }

        try {
            const response = await fetch(this.storageCreateUrlValue, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                },
                body: JSON.stringify({
                    _csrf_token: this.csrfTokenValue,
                    name: name.trim(),
                }),
            });

            const data = await response.json();
            if (!response.ok || !data.ok) {
                this._setStatus(data.message || "Could not create storage location.", "danger");
                return;
            }

            this._insertOrSelectStorageLocation(data.storageLocationId, data.storageLocationName);
            this._setStatus(`Storage location "${data.storageLocationName}" created and selected.`, "success");

            if (data.printUrl && window.confirm("Print a new label for this storage location now?")) {
                this._openPhomymoTab(data.printUrl);
            }
        } catch (_) {
            this._setStatus("Failed to create storage location.", "danger");
        }
    }

    async confirmAdd() {
        if (!this._activeScanToken) {
            return;
        }

        const amountInput = document.getElementById("quick-add-amount");
        const storageLocationSelect = document.getElementById("quick-add-storage-location");
        const categorySelect = document.getElementById("quick-add-category");
        const amount = parseFloat(amountInput?.value ?? "0");
        const storageLocationId = parseInt(storageLocationSelect?.value ?? "0", 10) || 0;
        const categoryValue = String(categorySelect?.value ?? "");
        const categoryId = parseInt(categoryValue, 10) || 0;
        const categoryPath = categoryValue.startsWith("__provider__:") ? categoryValue.slice("__provider__:".length) : "";

        if (!(amount > 0)) {
            this._setStatus("Please enter a valid amount greater than 0.", "warning");
            return;
        }

        try {
            const response = await fetch(this.confirmUrlValue, {
                method: "POST",
                headers: {
                    "Content-Type": "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                },
                body: JSON.stringify({
                    _csrf_token: this.csrfTokenValue,
                    scanToken: this._activeScanToken,
                    amount,
                    categoryId,
                    categoryPath,
                    storageLocationId,
                }),
            });

            const data = await response.json();
            if (data.redirectUrl) {
                window.location.href = data.redirectUrl;
                return;
            }
            if (!response.ok || !data.ok) {
                this._setStatus(data.message || "Could not add part.", "danger");
                return;
            }

            this._modal?.hide();
            this._setStatus(data.message || "Part added.", "success");

            if (data.printUrl && window.confirm("Print a label for this part now?")) {
                this._openPhomymoTab(data.printUrl);
            }
        } catch (_) {
            this._setStatus("Failed to add part.", "danger");
        }
    }

    _fillModal(data) {
        const title = document.getElementById("quick-add-part-name");
        const image = document.getElementById("quick-add-part-image");
        const amount = document.getElementById("quick-add-amount");
        const category = document.getElementById("quick-add-category");
        const storageLocation = document.getElementById("quick-add-storage-location");

        if (title) {
            title.textContent = data.name ?? "Unnamed part";
        }
        if (amount) {
            amount.value = data.amount ?? 1;
        }
        if (storageLocation) {
            storageLocation.value = "";
        }
        if (category) {
            Array.from(category.options)
                .filter(option => option.dataset.providerCategory === "1")
                .forEach(option => option.remove());

            if (data.autoCategoryId) {
                category.value = String(data.autoCategoryId);
            } else if (data.autoCategoryPath) {
                const option = document.createElement("option");
                option.value = `__provider__:${data.autoCategoryPath}`;
                option.textContent = data.autoCategoryPath;
                option.dataset.providerCategory = "1";
                category.appendChild(option);
                category.value = option.value;
            }
        }

        if (image) {
            if (data.imageUrl) {
                image.src = data.imageUrl;
                image.classList.remove("d-none");
            } else {
                image.src = "";
                image.classList.add("d-none");
            }
        }
    }

    _setStatus(message, level) {
        const status = document.getElementById("quick-add-status");
        if (!status) {
            return;
        }

        if (!message) {
            status.classList.add("d-none");
            status.textContent = "";
            return;
        }

        status.className = `alert alert-${level}`;
        status.textContent = message;
    }

    _applyScannedStorageLocation(data) {
        this._insertOrSelectStorageLocation(data.storageLocationId, data.storageLocationName);
    }

    _insertOrSelectStorageLocation(id, label) {
        const select = document.getElementById("quick-add-storage-location");
        if (!select) {
            return;
        }
        const value = String(id);
        let option = Array.from(select.options).find(o => o.value === value);
        if (!option) {
            option = document.createElement("option");
            option.value = value;
            option.textContent = label || value;
            select.appendChild(option);
        } else if (label) {
            option.textContent = label;
        }
        select.value = value;
    }

    _openPhomymoTab(url) {
        const phomymoTab = window.open(url, this.constructor.phomymoTabName);
        if (phomymoTab) {
            phomymoTab.focus();
            return;
        }

        window.location.href = url;
    }

    _clearPendingTokens() {
        if (!this.hasClearPendingUrlValue || !this.clearPendingUrlValue) {
            return;
        }

        fetch(this.clearPendingUrlValue, {
            method: "POST",
            headers: {
                "X-Requested-With": "XMLHttpRequest",
            },
        }).catch(() => {});
    }

    async _stopCameraStream() {
        const scanner = this._scanner;
        this._scanner = null;

        if (!scanner) {
            this._cameraStopped = true;
            return;
        }

        try {
            const p = scanner.clear?.();
            if (p && typeof p.then === "function") {
                await p.catch(() => {});
            }
        } catch (_) {
            // ignore
        }

        this._cameraStopped = true;
    }

    _startScanner() {
        if (this._scanner) {
            return;
        }
        this.connect();
    }
}
