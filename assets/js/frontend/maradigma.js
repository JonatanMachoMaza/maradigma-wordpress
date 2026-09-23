(function () {
    if (typeof MaradigmaConfig === 'undefined') {
        return;
    }

    var apiClient = new window.MaradigmaApiClient();

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
        return apiClient.postJson(url, payload);
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
    });
})();
