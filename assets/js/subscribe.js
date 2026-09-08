document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('leadForm');
    if (!form) return;

    const errorBox = document.getElementById('error-msg');
    const successBox = document.getElementById('simple-msg');
    const submitBtn = document.getElementById('leadSubmitBtn');

    function clearMessages() {
        if (errorBox) {
            errorBox.classList.add('hidden');
            errorBox.innerHTML = '';
        }
        if (successBox) {
            successBox.classList.add('hidden');
            successBox.innerHTML = '';
        }
    }

    function setLoading(isLoading) {
        if (!submitBtn) return;
        submitBtn.disabled = isLoading;
        submitBtn.style.opacity = isLoading ? '0.8' : '1';
        submitBtn.innerHTML = isLoading ? 'Submitting...' : 'Get job leads';
    }

    function isEmailValid(email) {
        return /^\S+@\S+\.\S+$/.test(email);
    }

    function isZipValid(zip) {
        // 12345 ou 12345-6789
        return /^\d{5}(-\d{4})?$/.test(zip);
    }

    function isStateValid(state) {
        // Agora é obrigatório e deve ter 2 letras
        return /^[A-Za-z]{2}$/.test(state);
    }

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        clearMessages();

        const formData = new FormData(form);

        const firstName = (formData.get('first_name') || '').trim();
        const lastName = (formData.get('last_name') || '').trim();
        const email = (formData.get('email') || '').trim();
        const jobKeyword = (formData.get('job_keyword') || '').trim();
        const city = (formData.get('city') || '').trim();
        const state = (formData.get('state') || '').trim();
        const zip = (formData.get('zip') || '').trim();
        const consent = formData.get('consent'); // "1" ou null

        const errors = [];

        // ==== TODOS OBRIGATÓRIOS ====
        if (!firstName) {
            errors.push('First name is required');
        }

        if (!lastName) {
            errors.push('Last name is required');
        }

        if (!email) {
            errors.push('Email is required');
        } else if (!isEmailValid(email)) {
            errors.push('Enter a valid email');
        }

        if (!jobKeyword) {
            errors.push('Job keyword is required');
        }

        if (!city) {
            errors.push('City is required');
        }

        if (!state) {
            errors.push('State is required');
        } else if (!isStateValid(state)) {
            errors.push('Enter a valid 2-letter state code (e.g., SC)');
        }

        if (!zip) {
            errors.push('ZIP is required');
        } else if (!isZipValid(zip)) {
            errors.push('Enter a valid ZIP (e.g., 29201 or 29201-1234)');
        }

        if (!consent) {
            errors.push('You must accept consent to subscribe');
        }

        if (errors.length) {
            if (errorBox) {
                errorBox.innerHTML = errors.join(' - ');
                errorBox.classList.remove('hidden');
            }
            return;
        }

        setLoading(true);

        try {
            const url = form.getAttribute('action') || 'functions/subscribe-submit.php';

            const response = await fetch(url, {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            const rawText = await response.text();
            let data = null;

            try {
                data = JSON.parse(rawText);
            } catch (jsonError) {
                console.error('Invalid JSON from server:', rawText);
            }

            if (!response.ok) {
                console.error('HTTP error:', response.status, rawText);
                if (errorBox) {
                    errorBox.textContent = 'Server error (' + response.status + '). Please try again later.';
                    errorBox.classList.remove('hidden');
                }
                if (typeof turnstile !== 'undefined') turnstile.reset();
                return;
            }

            if (!data) {
                if (errorBox) {
                    errorBox.textContent = 'Unexpected server response. Please try again.';
                    errorBox.classList.remove('hidden');
                }
                if (typeof turnstile !== 'undefined') turnstile.reset();
                return;
            }

            if (data.success) {
                if (successBox) {
                    successBox.textContent = data.message || 'Subscribed successfully. Check your inbox soon.';
                    successBox.classList.remove('hidden');

                    window.location.href = 'subscribe-thankyou.php';
                }
                form.reset();
            } else {
                if (errorBox) {
                    if (Array.isArray(data.errors)) {
                        errorBox.innerHTML = data.errors.join(' - ');
                    } else {
                        errorBox.textContent = data.message || 'Something went wrong. Please try again.';
                    }
                    errorBox.classList.remove('hidden');
                }
                if (typeof turnstile !== 'undefined') turnstile.reset();
            }
        } catch (err) {
            console.error('Fetch error:', err);
            if (errorBox) {
                errorBox.textContent = 'Connection error. Please try again in a moment.';
                errorBox.classList.remove('hidden');
            }
            if (typeof turnstile !== 'undefined') turnstile.reset();
        } finally {
            setLoading(false);
        }
    });
});
