/**
 * Admin Create Health Worker account — UI validation only.
 * Does not persist accounts. Redirects to a demo worker profile after a valid submit.
 */

function isValidEmail(value) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value);
}

function isValidMobile(value) {
    const digits = value.replace(/\D/g, '');
    return digits.length >= 10 && digits.length <= 13;
}

function getField(root, name) {
    return root.querySelector(`[data-hw-create-field="${name}"]`);
}

function getError(root, name) {
    return root.querySelector(`[data-hw-create-error="${name}"]`);
}

function fieldValue(root, name) {
    const field = getField(root, name);
    return field && typeof field.value === 'string' ? field.value.trim() : '';
}

function setError(root, name, message) {
    const field = getField(root, name);
    const error = getError(root, name);
    if (!field || !error) {
        return;
    }

    if (message) {
        field.classList.add('is-invalid');
        field.setAttribute('aria-invalid', 'true');
        error.hidden = false;
        error.textContent = message;
        return;
    }

    field.classList.remove('is-invalid');
    field.removeAttribute('aria-invalid');
    error.hidden = true;
    error.textContent = '';
}

function validate(root) {
    const required = ['first_name', 'last_name', 'email', 'mobile', 'role', 'status', 'password', 'password_confirmation'];
    let firstInvalid = null;

    required.forEach((name) => {
        const value = fieldValue(root, name);
        let message = '';

        if (!value) {
            message = 'This field is required.';
        } else if (name === 'email' && !isValidEmail(value)) {
            message = 'Enter a valid email address.';
        } else if (name === 'mobile' && !isValidMobile(value)) {
            message = 'Enter a valid mobile number.';
        } else if (name === 'password' && value.length < 8) {
            message = 'Password must be at least 8 characters.';
        } else if (name === 'password_confirmation' && value !== fieldValue(root, 'password')) {
            message = 'Password and Confirm Password must match.';
        }

        setError(root, name, message);
        if (message && !firstInvalid) {
            firstInvalid = getField(root, name);
        }
    });

    return firstInvalid;
}

function generatePassword() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
    let result = 'Tmp-';
    for (let i = 0; i < 8; i += 1) {
        result += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    return result;
}

function initCreate(root) {
    const form = root.querySelector('[data-hw-create-form]');
    const alertEl = root.querySelector('[data-hw-create-alert]');
    const toast = root.querySelector('[data-hw-create-toast]');
    const generateBtn = root.querySelector('[data-hw-create-generate]');
    const generateStatus = root.querySelector('[data-hw-create-generate-status]');
    const profileUrl = root.dataset.profileUrl || '/user-management';

    generateBtn?.addEventListener('click', () => {
        const password = generatePassword();
        const passwordField = getField(root, 'password');
        const confirmField = getField(root, 'password_confirmation');
        if (passwordField) {
            passwordField.value = password;
        }
        if (confirmField) {
            confirmField.value = password;
        }
        setError(root, 'password', '');
        setError(root, 'password_confirmation', '');
        if (generateStatus) {
            generateStatus.textContent = 'Temporary password generated. Copy it before leaving this screen.';
        }
    });

    form?.addEventListener('submit', (event) => {
        event.preventDefault();
        const firstInvalid = validate(root);
        if (firstInvalid) {
            if (alertEl) {
                alertEl.hidden = false;
                alertEl.textContent = 'Please complete all required information before creating this account.';
            }
            firstInvalid.focus();
            return;
        }

        if (alertEl) {
            alertEl.hidden = true;
            alertEl.textContent = '';
        }
        if (toast) {
            toast.hidden = false;
            toast.textContent = 'Account details captured for UI preview. Persistence is a backend task.';
        }

        window.setTimeout(() => {
            window.location.href = `${profileUrl}${profileUrl.includes('?') ? '&' : '?'}created=1`;
        }, 800);
    });
}

document.querySelectorAll('[data-lml-hw-create]').forEach((root) => {
    initCreate(root);
});
