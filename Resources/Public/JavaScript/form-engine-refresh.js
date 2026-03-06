import FormEngine from "@typo3/backend/form-engine.js";
import {default as Modal} from "@typo3/backend/modal.js";
import Severity from "@typo3/backend/severity.js";
import {selector} from "@typo3/core/literals.js";

class FormEngineRefresh {
    constructor() {
        this.registerClickHandler();
    }

    registerClickHandler() {
        document.querySelectorAll('[data-cantofal-id]').forEach((element) => {
            element.addEventListener('click', () => {
                const id = element.dataset.cantofalId;

                const actionElement = document.createElement('input');
                actionElement.type = 'hidden';
                actionElement.name = 'cantoFileId';
                actionElement.value = id;

                const form = document.querySelector(`form[name="${FormEngine.formName}"]`);

                if (FormEngine.hasChange()) {
                    this.showReloadModal(form, actionElement);
                } else {
                    form.appendChild(actionElement);
                    FormEngine.formElement.submit();
                }
            });
        });
    }

    showReloadModal(form, actionElement) {
        const title = TYPO3.lang["file_reload.label.confirm.title"] || "Do you want to save before reloading?";
        const content = TYPO3.lang["file_reload.label.confirm.content"] || "You need to save your changes before reloading asset information. Do you want to save and reload now?";

        const cancel = {
            text: TYPO3.lang["file_reload.buttons.confirm.cancel"] || "Cancel",
            btnClass: "btn-default",
            name: "cancel"
        };
        const no = {
            text: TYPO3.lang["file_reload.buttons.confirm.no"] || "No, just reload",
            btnClass: "btn-default",
            name: "no"
        };
        const yes = {
            text: TYPO3.lang["file_reload.buttons.confirm.yes"] || "Yes, save and reload now",
            btnClass: "btn-primary",
            name: "yes",
            active: true
        };

        Modal.confirm(title, content, Severity.info, [cancel, no, yes])
            .addEventListener("button.clicked", (event) => {
                Modal.dismiss();
                switch (event.target.name) {
                    case 'no':
                        form.appendChild(actionElement);
                        FormEngine.formElement.submit();
                        break;
                    case 'yes':
                        form.appendChild(actionElement);
                        FormEngine.saveDocument();
                        break;
                }
            });
    }
}

export default new FormEngineRefresh;
