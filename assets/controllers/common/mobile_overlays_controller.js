/*
 * This file is part of Part-DB (https://github.com/Part-DB/Part-DB-symfony).
 */

import { Controller } from "@hotwired/stimulus";
import Collapse from "bootstrap/js/dist/collapse";

/* stimulusFetch: 'lazy' */

export default class extends Controller {
    toggleSidebar(event) {
        event.preventDefault();
        this._toggleTarget("#sidebar-container", ["#navbarContent"]);
    }

    toggleNavbar(event) {
        event.preventDefault();
        this._toggleTarget("#navbarContent", ["#sidebar-container"]);
    }

    openCamera() {
        this._hideTargets(["#sidebar-container", "#navbarContent"]);
    }

    _toggleTarget(targetSelector, otherSelectors) {
        const target = document.querySelector(targetSelector);
        if (!target) {
            return;
        }

        this._hideTargets(otherSelectors);

        const collapse = Collapse.getOrCreateInstance(target, { toggle: false });
        if (target.classList.contains("show")) {
            collapse.hide();
        } else {
            collapse.show();
        }
    }

    _hideTargets(selectors) {
        selectors.forEach((selector) => {
            const element = document.querySelector(selector);
            if (!element || !element.classList.contains("show")) {
                return;
            }

            Collapse.getOrCreateInstance(element, { toggle: false }).hide();
        });
    }
}
