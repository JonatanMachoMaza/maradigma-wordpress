(function() {
    function switchMaradigmaMode(mode) {
        var sections = document.querySelectorAll('.maradigma-mode-section');
        sections.forEach(function(s) {
            s.style.display = 'none';
        });

        if (!mode) {
            return;
        }

        var active = document.getElementById('maradigma-mode-' + mode);
        if (active) {
            active.style.display = '';
        }
    }

    document.addEventListener('change', function(e) {
        if (!e.target || !e.target.name) {
            return;
        }
        if (e.target.name === 'maradigma_boats_page[mode]') {
            switchMaradigmaMode(e.target.value || '');
        }
    });

    document.addEventListener('DOMContentLoaded', function() {
        var checked = document.querySelector('input[name="maradigma_boats_page[mode]"]:checked');
        switchMaradigmaMode(checked ? (checked.value || '') : '');
    });
})();
