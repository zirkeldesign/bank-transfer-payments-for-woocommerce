/* global btpwAdmin */
(function () {
    'use strict';

    document.addEventListener('DOMContentLoaded', function () {
        var button = document.getElementById('btpw-create-financial-address');

        if (!button) {
            return;
        }

        button.addEventListener('click', function () {
            var spinner = button.parentNode.querySelector('.spinner');

            button.disabled = true;
            if (spinner) {
                spinner.classList.add('is-active');
            }

            fetch(btpwAdmin.restUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': btpwAdmin.nonce
                },
                body: JSON.stringify({
                    // The customer id is deliberately NOT sent: the server
                    // resolves it from the user, so a request cannot address
                    // someone else's Stripe customer.
                    user_id: button.dataset.userId
                })
            })
                .then(function (response) {
                    return response.json().then(function (data) {
                        return { ok: response.ok, data: data };
                    });
                })
                .then(function (result) {
                    if (result.ok) {
                        window.alert(btpwAdmin.strings.created);
                        window.location.reload();
                        return;
                    }

                    var message = (result.data && result.data.message) || btpwAdmin.strings.unknownError;
                    window.alert(btpwAdmin.strings.errorPrefix + message);
                })
                .catch(function () {
                    window.alert(btpwAdmin.strings.genericError);
                })
                .finally(function () {
                    button.disabled = false;
                    if (spinner) {
                        spinner.classList.remove('is-active');
                    }
                });
        });
    });
})();
