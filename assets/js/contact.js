document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('contactForm');
    if (!form) return;

    const errorBox = document.getElementById('error-msg');
    const successBox = document.getElementById('simple-msg');
    const submitBtn = form.querySelector('button[type="submit"]');

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
        submitBtn.innerHTML = isLoading ? 'Sending...' : 'Send Message';
    }

    function isEmailValid(email) {
        return /^\S+@\S+\.\S+$/.test(email);
    }

    form.addEventListener('submit', async function (e) {
        e.preventDefault();

        clearMessages();

        const formData = new FormData(form);

        const name = (formData.get('name') || '').trim();
        const email = (formData.get('email') || '').trim();
        const subject = (formData.get('subject') || '').trim();
        const message = (formData.get('comments') || '').trim();

        const errors = [];

        // TODOS obrigatórios
        if (!name) errors.push('Name is required');
        if (!email) errors.push('Email is required');
        else if (!isEmailValid(email)) errors.push('Enter a valid email');
        if (!subject) errors.push('Subject is required');
        if (!message) errors.push('Message is required');

        if (errors.length) {
            if (errorBox) {
                errorBox.innerHTML = errors.join(' - ');
                errorBox.classList.remove('hidden');
            }
            return;
        }

        setLoading(true);

        try {
            const url = form.getAttribute('action') || 'functions/contact-submit.php';

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
                    successBox.textContent = data.message || 'Message sent successfully. We will get back to you soon.';
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
