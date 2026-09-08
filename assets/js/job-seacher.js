document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('job-search-form');
    if (!form) return;

    const keywordEl = document.getElementById('keyword');
    const locationEl = document.getElementById('location');
    const errorBox = document.getElementById('job-search-error');

    function showError(message) {
        if (!errorBox) return;
        errorBox.textContent = message;
        errorBox.classList.remove('hidden');
    }

    function hideError() {
        if (!errorBox) return;
        errorBox.textContent = '';
        errorBox.classList.add('hidden');
    }

    form.addEventListener('submit', function (e) {
        const keyword = (keywordEl.value || '').trim();
        const location = (locationEl.value || '').trim();

        let errors = [];

        if (!keyword) {
            errors.push('Please enter a job title or keyword.');
        }

        if (!location) {
            errors.push('Please enter a location.');
        }

        if (errors.length > 0) {
            e.preventDefault(); // impede o submit
            showError(errors.join(' '));

            // highlight nos campos
            keywordEl.classList.toggle('border-red-500', !keyword);
            locationEl.classList.toggle('border-red-500', !location);
        } else {
            hideError();
            keywordEl.classList.remove('border-red-500');
            locationEl.classList.remove('border-red-500');
        }
    });

    // Remove erro quando o usuário começa a digitar
    [keywordEl, locationEl].forEach(function (input) {
        input.addEventListener('input', function () {
            hideError();
            input.classList.remove('border-red-500');
        });
    });
});