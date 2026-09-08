document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('partnerForm');
    if (!form) return;

    const errorBox = document.getElementById('partner-error-msg');
    const successBox = document.getElementById('partner-simple-msg');
    const submitBtn = document.getElementById('partnerSubmitBtn');

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
        submitBtn.innerHTML = isLoading ? 'Submitting...' : 'Submit partner request';
    }

    function isEmailValid(email) {
        return /^\S+@\S+\.\S+$/.test(email);
    }

    function isUrlLikelyValid(url) {
        // Validação simples, suficiente pro form
        if (!url) return false;
        return /^(https?:\/\/)/i.test(url);
    }

    form.addEventListener('submit', async function (e) {
        e.preventDefault();

        clearMessages();

        const formData = new FormData(form);

        const companyName = (formData.get('company_name') || '').trim();
        const website = (formData.get('website') || '').trim();
        const contactEmail = (formData.get('contact_email') || '').trim();
        const budget = (formData.get('monthly_budget') || '').trim();
        const message = (formData.get('message') || '').trim();

        const errors = [];

        if (!companyName) {
            errors.push('Company name is required');
        }

        if (!website) {
            errors.push('Website is required');
        } else if (!isUrlLikelyValid(website)) {
            errors.push('Enter a valid website URL (starting with http or https)');
        }

        if (!contactEmail) {
            errors.push('Contact email is required');
        } else if (!isEmailValid(contactEmail)) {
            errors.push('Enter a valid contact email');
        }

        // message e budget opcionais, então não obrigo nada aqui

        if (errors.length) {
            if (errorBox) {
                errorBox.innerHTML = errors.join(' - ');
                errorBox.classList.remove('hidden');
            }
            return;
        }

        setLoading(true);

        try {
            const url = form.getAttribute('action') || 'functions/partner-submit.php';

            const response = await fetch(url, {
                method: 'POST',
                body: formData,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
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
                    successBox.textContent = data.message || 'Request sent successfully. We will get back to you soon.';
                    successBox.classList.remove('hidden');
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
