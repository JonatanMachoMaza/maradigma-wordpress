(function () {
    if (typeof MaradigmaConfig === 'undefined') {
        return;
    }

    function randomBookingToken() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID().replace(/-/g, '');
        }

        if (window.crypto && typeof window.crypto.getRandomValues === 'function') {
            var bytes = new Uint8Array(24);
            window.crypto.getRandomValues(bytes);
            return Array.prototype.map.call(bytes, function (byte) {
                return byte.toString(16).padStart(2, '0');
            }).join('');
        }

        return Date.now().toString(36) + Math.random().toString(36).slice(2) + Math.random().toString(36).slice(2);
    }

    function getBookingSession() {
        var storageKey = 'maradigma_booking_session_v1';

        try {
            var stored = String(window.localStorage.getItem(storageKey) || '').trim();
            if (/^[A-Za-z0-9_-]{20,128}$/.test(stored)) {
                return stored;
            }

            var created = randomBookingToken();
            window.localStorage.setItem(storageKey, created);
            return created;
        } catch (error) {
            return randomBookingToken();
        }
    }

    var bookingSession = getBookingSession();
    var bookingIdempotencyKeys = {};

    function getFormData(form) {
        var data = {};
        var elements = form.querySelectorAll('input, select, textarea');
        elements.forEach(function (el) {
            if (!el.name) return;
            if ((el.type === 'checkbox' || el.type === 'radio') && !el.checked) return;
            data[el.name] = el.value;
        });
        return data;
    }

    function postJson(url, payload) {
        var headers = {
            'Content-Type': 'application/json'
        };

        if (
            String(url || '') === String(MaradigmaConfig.restUrlBooking || '')
            && MaradigmaConfig.bookingNonce
        ) {
            headers['X-Maradigma-Booking-Nonce'] = String(MaradigmaConfig.bookingNonce);
            headers['X-Maradigma-Booking-Session'] = bookingSession;

            var idempotencyScope = String(url || '') + '|' + JSON.stringify(payload || {});
            if (!bookingIdempotencyKeys[idempotencyScope]) {
                bookingIdempotencyKeys[idempotencyScope] = randomBookingToken();
            }
            headers['Idempotency-Key'] = bookingIdempotencyKeys[idempotencyScope];
        }

        return fetch(url, {
            method: 'POST',
            headers: headers,
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        }).then(function (res) {
            return res.json();
        });
    }

    document.addEventListener('click', function (ev) {
        // Quote
        if (ev.target && ev.target.id === 'maradigma-btn-quote') {
            ev.preventDefault();
            var form = document.getElementById('maradigma-boat-booking-form');
            if (!form) return;

            var payload = getFormData(form);
            var quoteBox = document.getElementById('maradigma-quote-result');
            if (quoteBox) quoteBox.textContent = 'Calculating...';

            postJson(MaradigmaConfig.restUrlQuote, payload).then(function (response) {
                if (!response || !response.success) {
                    if (quoteBox) quoteBox.textContent = 'Error calculating quote.';
                    return;
                }
                var data = response.data || {};
                if (quoteBox) {
                    // TODO: formatear bonito el breakdown
                    quoteBox.textContent = JSON.stringify(data.price_breakdown || data);
                }
            }).catch(function () {
                if (quoteBox) quoteBox.textContent = 'Error calculating quote.';
            });
        }

        // Booking
        if (ev.target && ev.target.id === 'maradigma-btn-book') {
            ev.preventDefault();
            var form = document.getElementById('maradigma-boat-booking-form');
            if (!form) return;

            var payload = getFormData(form);
            var msgBox = document.getElementById('maradigma-booking-messages');
            if (msgBox) msgBox.textContent = 'Sending booking...';

            postJson(MaradigmaConfig.restUrlBooking, payload).then(function (response) {
                if (!response || !response.success) {
                    if (msgBox) msgBox.textContent = 'Error creating booking.';
                    return;
                }
                var data = response.data || {};
                if (data.url_payment) {
                    window.location.href = data.url_payment;
                    return;
                }
                if (msgBox) msgBox.textContent = 'Booking created successfully.';
            }).catch(function () {
                if (msgBox) msgBox.textContent = 'Error creating booking.';
            });
        }
    });
})();
