/**
 * Wires the native TYPO3 page-tree/folder browser popups (the same "Browse" widget
 * FormEngine uses for type=group/type=folder fields) to two plain inputs on the review
 * form (Review.html), which is a custom Extbase Fluid form, not a FormEngine record-edit
 * screen - so the standard FormEngine wiring doesn't apply automatically.
 *
 * Confirmed against TYPO3 12.4's own @typo3/backend/element-browser.js and the (equally
 * non-FormEngine) real-world usage in @typo3/belog/backend-log.js: the popup always
 * communicates its selection back via a window "message" event, never a callback function,
 * regardless of whether the opener is a real FormEngine form.
 */
import Modal from '@typo3/backend/modal.js';
import { MessageUtility } from '@typo3/backend/utility/message-utility.js';

class ElementBrowserFields {
  constructor() {
    document.querySelectorAll('.t3js-element-browser').forEach((button) => {
      const target = document.getElementById(button.dataset.triggerFor);
      if (!target) {
        return;
      }

      // bparams position 0 is the field-name reference echoed back in the postMessage
      // fieldName (see AbstractElementBrowser::getBParamDataAttributes()); position 3
      // (after "|||") restricts db-mode browsing to the pages table - irrelevant for
      // folder mode, so left empty there.
      button.dataset.params = button.dataset.mode === 'db' ? `${target.name}|||pages` : target.name;

      button.addEventListener('click', (event) => {
        event.preventDefault();
        Modal.advanced({
          type: Modal.types.iframe,
          content: `${button.dataset.target}&mode=${button.dataset.mode}&bparams=${button.dataset.params}`,
          size: Modal.sizes.large,
        });
      });
    });

    window.addEventListener('message', (event) => {
      if (
        !MessageUtility.verifyOrigin(event.origin)
        || event.data.actionName !== 'typo3:elementBrowser:elementAdded'
        || typeof event.data.fieldName !== 'string'
        || typeof event.data.value !== 'string'
      ) {
        return;
      }

      const target = document.querySelector(`[name="${event.data.fieldName}"]`);
      if (!target) {
        return;
      }

      // Page picks arrive as "pages_123" (table_uid); folder picks arrive as the FAL
      // combined identifier "1:/bip-dokumenty/uchwaly/" (storageUid:path) - this module
      // only ever needs the trailing uid or the bare path, never the table/storage prefix.
      target.value = event.data.value.includes(':')
        ? event.data.value.substring(event.data.value.indexOf(':') + 1)
        : event.data.value.split('_').pop();
    });
  }
}

export default new ElementBrowserFields();
