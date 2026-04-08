/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 */

import { Controller } from "@hotwired/stimulus";

/* stimulusFetch: 'lazy' */

export default class extends Controller {
    static phomymoTabName = "partdb-phomymo";

    async submit(event) {
        event.preventDefault();

        const form = event.currentTarget;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        try {
            const response = await fetch(form.action || window.location.href, {
                method: form.method || "POST",
                body: new FormData(form),
                credentials: "same-origin",
            });

            if (!response.ok) {
                form.submit();
                return;
            }

            const finalUrl = response.url;
            if (!finalUrl || !this._openPhomymoTab(finalUrl)) {
                form.submit();
                return;
            }
        } catch (_) {
            form.submit();
        }
    }

    _openPhomymoTab(url) {
        const phomymoTab = window.open("", this.constructor.phomymoTabName);
        if (!phomymoTab) {
            return false;
        }

        if (this._sendLabelToExistingPhomymoTab(phomymoTab, url)) {
            phomymoTab.focus();
            return true;
        }

        phomymoTab.location.href = url;
        phomymoTab.focus();
        return true;
    }

    _sendLabelToExistingPhomymoTab(phomymoTab, url) {
        try {
            const tabLocation = phomymoTab.location;
            const isPhomymoTab = tabLocation?.origin === window.location.origin
                && tabLocation?.pathname?.includes("/phomymo/");
            if (!isPhomymoTab) {
                return false;
            }

            const targetUrl = new URL(url, window.location.origin);
            const encoded = targetUrl.searchParams.get("autolabel");
            const autoprintValue = String(targetUrl.searchParams.get("autoprint") ?? "").toLowerCase();
            const autoprint = autoprintValue === "1" || autoprintValue === "true" || autoprintValue === "yes";
            if (!encoded) {
                return false;
            }

            if (typeof phomymoTab.phomymoLoadAutoLabel === "function") {
                phomymoTab.phomymoLoadAutoLabel(encoded, autoprint);
                return true;
            }
        } catch (_) {
            return false;
        }

        return false;
    }
}
