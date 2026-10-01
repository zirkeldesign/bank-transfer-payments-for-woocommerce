/* global qrcode */
(function () {
    'use strict';

    function decode(b64) {
        var bin = atob(b64);
        var bytes = new Uint8Array(bin.length);
        for (var i = 0; i < bin.length; i++) {
            bytes[i] = bin.charCodeAt(i);
        }
        return new TextDecoder('utf-8').decode(bytes);
    }

    function render(el) {
        var raw = el.getAttribute('data-girocode');

        if (!raw || typeof qrcode === 'undefined') {
            return;
        }

        var payload = decode(raw);

        try {
            // Type number 0 = auto-fit; error-correction level 'M' per EPC069-12.
            var qr = qrcode(0, 'M');
            qr.addData(payload);
            qr.make();
            el.innerHTML = qr.createSvgTag({ cellSize: 4, margin: 8, scalable: true });
        } catch (e) {
            // Leave the fallback instructions in place if generation fails.
            el.setAttribute('data-girocode-error', '1');
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        var nodes = document.querySelectorAll('.btpw-girocode[data-girocode]');
        Array.prototype.forEach.call(nodes, render);
    });
})();
