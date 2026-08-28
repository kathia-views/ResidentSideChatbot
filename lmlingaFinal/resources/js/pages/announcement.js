/**
 * Announcement composer — frontend prototype only.
 * Target Group and Zone Coverage are independent dimensions.
 * Isolated demo reach counts; no Laravel/backend persistence.
 */

const MESSAGE_MAX = 500;

function parseDemoReach(root) {
    try {
        return JSON.parse(root.getAttribute('data-demo-reach') || '{}');
    } catch {
        return {};
    }
}

function selectedAudienceType(root) {
    return root.querySelector('[data-announce-audience-type]:checked')?.value || 'all';
}

function selectedZoneCoverageType(root) {
    return root.querySelector('[data-announce-zone-coverage]:checked')?.value || 'all';
}

function selectedChipLabels(root, kind) {
    return Array.from(root.querySelectorAll(`[data-announce-chip="${kind}"]:checked`))
        .map((input) => input.getAttribute('data-announce-label') || input.value)
        .filter(Boolean);
}

function selectedChipValues(root, kind) {
    return Array.from(root.querySelectorAll(`[data-announce-chip="${kind}"]:checked`))
        .map((input) => input.value);
}

function ageUnit(root, which) {
    const value = root.querySelector(`[data-announce-age-${which}-unit]`)?.value || 'months';
    return value === 'years' ? 'years' : 'months';
}

function unitWord(unit, count) {
    if (unit === 'years') {
        return Number(count) === 1 ? 'year' : 'years';
    }
    return Number(count) === 1 ? 'month' : 'months';
}

function toMonths(value, unit) {
    const amount = Number(value);
    if (!Number.isFinite(amount) || amount < 0) {
        return null;
    }
    return unit === 'years' ? amount * 12 : amount;
}

function readCustomAge(root) {
    const fromRaw = root.querySelector('[data-announce-age-from]')?.value?.trim() || '';
    const toRaw = root.querySelector('[data-announce-age-to]')?.value?.trim() || '';
    const fromUnit = ageUnit(root, 'from');
    const toUnit = ageUnit(root, 'to');

    return {
        fromRaw,
        toRaw,
        fromUnit,
        toUnit,
        fromMonths: fromRaw === '' ? null : toMonths(fromRaw, fromUnit),
        toMonths: toRaw === '' ? null : toMonths(toRaw, toUnit),
    };
}

function customAgeLabel(root) {
    const { fromRaw, toRaw, fromUnit, toUnit } = readCustomAge(root);

    if (fromRaw === '' && toRaw === '') {
        return null;
    }

    if (fromRaw !== '' && toRaw !== '') {
        if (fromUnit === toUnit) {
            return `Ages ${fromRaw}–${toRaw} ${unitWord(fromUnit, toRaw)}`;
        }
        return `Ages ${fromRaw} ${unitWord(fromUnit, fromRaw)}–${toRaw} ${unitWord(toUnit, toRaw)}`;
    }

    if (fromRaw !== '') {
        return `Ages ${fromRaw}+ ${unitWord(fromUnit, fromRaw)}`;
    }

    return `Up to ${toRaw} ${unitWord(toUnit, toRaw)}`;
}

function customAgeValidationMessage(root) {
    const age = readCustomAge(root);

    if (age.fromRaw === '' && age.toRaw === '') {
        return null;
    }

    if (age.fromRaw !== '' && age.fromMonths === null) {
        return 'Enter a valid From age.';
    }

    if (age.toRaw !== '' && age.toMonths === null) {
        return 'Enter a valid To age.';
    }

    if (age.fromMonths !== null && age.toMonths !== null && age.fromMonths > age.toMonths) {
        return 'Custom age range is invalid. “From” must be less than or equal to “To” (compared in months).';
    }

    return null;
}

function customZones(root) {
    if (!Array.isArray(root._lmlCustomZones)) {
        root._lmlCustomZones = [];
    }
    return root._lmlCustomZones;
}

function normalizeZoneLabel(value) {
    return value.trim().replace(/\s+/g, ' ');
}

function zoneLabels(root) {
    return [...selectedChipLabels(root, 'zone'), ...customZones(root)];
}

function renderCustomZones(root) {
    const list = root.querySelector('[data-announce-custom-zone-list]');
    if (!list) {
        return;
    }

    list.innerHTML = '';
    customZones(root).forEach((label, index) => {
        const item = document.createElement('li');
        item.className = 'lml-announce__custom-zone-chip';

        const text = document.createElement('span');
        text.textContent = label;

        const remove = document.createElement('button');
        remove.type = 'button';
        remove.className = 'lml-announce__custom-zone-remove lml-focus-ring';
        remove.setAttribute('aria-label', `Remove ${label}`);
        remove.innerHTML = '<i class="bi bi-x" aria-hidden="true"></i>';
        remove.addEventListener('click', () => {
            root._lmlCustomZones = customZones(root).filter((_, i) => i !== index);
            renderCustomZones(root);
            root.dispatchEvent(new CustomEvent('lml-announce-refresh'));
        });

        item.append(text, remove);
        list.appendChild(item);
    });
}

function addCustomZone(root) {
    const input = root.querySelector('[data-announce-custom-zone-input]');
    const raw = normalizeZoneLabel(input?.value || '');
    setError(root, 'custom-zone', '');

    if (!raw) {
        setError(root, 'custom-zone', 'Enter a custom zone or purok name.');
        markInvalid(input);
        return false;
    }

    const exists = zoneLabels(root).some(
        (label) => label.toLowerCase() === raw.toLowerCase(),
    );
    if (exists) {
        setError(root, 'custom-zone', 'That zone is already selected.');
        markInvalid(input);
        return false;
    }

    customZones(root).push(raw);
    if (input) {
        input.value = '';
        input.removeAttribute('aria-invalid');
        input.classList.remove('is-invalid');
    }
    renderCustomZones(root);
    return true;
}

function audienceValues(root) {
    const type = selectedAudienceType(root);

    if (type === 'all') {
        return [];
    }

    if (type === 'age') {
        const labels = selectedChipLabels(root, 'age');
        const custom = customAgeLabel(root);
        if (custom && !customAgeValidationMessage(root)) {
            labels.push(custom);
        }
        return labels;
    }

    if (type === 'active_maternal') {
        return ['Active Maternal'];
    }

    if (type === 'active_fp_user') {
        return ['Active FP User'];
    }

    return [];
}

function audiencePreviewText(root) {
    const type = selectedAudienceType(root);

    if (type === 'all') {
        return 'Audience: All Residents';
    }

    if (type === 'age') {
        const labels = selectedChipLabels(root, 'age');
        const custom = customAgeLabel(root);
        if (custom) {
            labels.push(custom);
        }
        return labels.length
            ? `Audience: ${labels.join(', ')}`
            : 'Audience: Select age groups';
    }

    if (type === 'active_maternal') {
        return 'Audience: Active Maternal';
    }

    if (type === 'active_fp_user') {
        return 'Audience: Active FP User';
    }

    return 'Audience: All Residents';
}

function coveragePreviewText(root) {
    const coverage = selectedZoneCoverageType(root);

    if (coverage === 'all') {
        return 'Coverage: All Zones';
    }

    const labels = zoneLabels(root);
    return labels.length
        ? `Coverage: ${labels.join(', ')}`
        : 'Coverage: Select zones';
}

function audienceBaseReach(root, demo) {
    const type = selectedAudienceType(root);

    if (type === 'all') {
        return Number(demo.all) || 635;
    }

    if (type === 'age') {
        const map = demo.age || {};
        let total = selectedChipValues(root, 'age').reduce(
            (sum, key) => sum + (Number(map[key]) || 0),
            0,
        );
        if (customAgeLabel(root) && !customAgeValidationMessage(root)) {
            total += Number(map.custom) || 25;
        }
        return total;
    }

    // Active Maternal / Active FP User — no demo counts until backend targeting exists.
    if (type === 'active_maternal' || type === 'active_fp_user') {
        return null;
    }

    return 0;
}

function zoneCapacity(demo) {
    const map = demo.zone || {};
    return Object.entries(map)
        .filter(([key]) => key !== 'custom')
        .reduce((sum, [, value]) => sum + (Number(value) || 0), 0) || 635;
}

function zoneShare(root, demo) {
    const coverage = selectedZoneCoverageType(root);
    if (coverage === 'all') {
        return 1;
    }

    const map = demo.zone || {};
    let selected = selectedChipValues(root, 'zone').reduce(
        (sum, key) => sum + (Number(map[key]) || 0),
        0,
    );
    selected += customZones(root).length * (Number(map.custom) || 18);

    const capacity = zoneCapacity(demo);
    if (!selected || !capacity) {
        return 0;
    }

    return Math.min(1, selected / capacity);
}

function estimateReach(root, demo) {
    const base = audienceBaseReach(root, demo);
    if (base === null) {
        return null;
    }

    const share = zoneShare(root, demo);
    return Math.max(0, Math.round(base * share));
}

function formatDate(value) {
    if (!value) {
        return 'Select a date';
    }

    const date = new Date(`${value}T00:00:00`);
    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

function formatTime(value) {
    if (!value) {
        return '';
    }

    const [hoursRaw, minutesRaw] = value.split(':');
    const hours = Number(hoursRaw);
    const minutes = Number(minutesRaw);
    if (!Number.isFinite(hours) || !Number.isFinite(minutes)) {
        return value;
    }

    const suffix = hours >= 12 ? 'PM' : 'AM';
    const hour12 = ((hours + 11) % 12) + 1;
    return `${hour12}:${String(minutes).padStart(2, '0')} ${suffix}`;
}

function setError(root, key, message) {
    const el = root.querySelector(`[data-announce-error="${key}"]`);
    if (!el) {
        return;
    }

    if (!message) {
        el.hidden = true;
        el.textContent = '';
        return;
    }

    el.hidden = false;
    el.textContent = message;
}

function clearErrors(root) {
    root.querySelectorAll('[data-announce-error]').forEach((el) => {
        el.hidden = true;
        el.textContent = '';
    });

    root.querySelectorAll('[aria-invalid="true"]').forEach((el) => {
        el.removeAttribute('aria-invalid');
        el.classList.remove('is-invalid');
    });
}

function markInvalid(input) {
    if (!input) {
        return;
    }
    input.setAttribute('aria-invalid', 'true');
    input.classList.add('is-invalid');
}

function validate(root) {
    clearErrors(root);
    let ok = true;

    const title = root.querySelector('[data-announce-title]');
    const message = root.querySelector('[data-announce-message]');
    const date = root.querySelector('[data-announce-date]');

    if (!title?.value.trim()) {
        setError(root, 'title', 'Title is required.');
        markInvalid(title);
        ok = false;
    }

    if (!message?.value.trim()) {
        setError(root, 'message', 'Message is required.');
        markInvalid(message);
        ok = false;
    }

    if (!date?.value) {
        setError(root, 'date', 'Date is required.');
        markInvalid(date);
        ok = false;
    }

    const type = selectedAudienceType(root);
    if (type === 'age') {
        const hasChip = selectedChipValues(root, 'age').length > 0;
        const customError = customAgeValidationMessage(root);
        const hasCustom = Boolean(customAgeLabel(root)) && !customError;

        if (customError) {
            setError(root, 'custom-age', customError);
            markInvalid(root.querySelector('[data-announce-age-from]'));
            markInvalid(root.querySelector('[data-announce-age-to]'));
            ok = false;
        }

        if (!hasChip && !hasCustom) {
            setError(
                root,
                'audience',
                'Select at least one age group or enter a valid custom age range.',
            );
            ok = false;
        }
    }

    if (selectedZoneCoverageType(root) === 'specific' && zoneLabels(root).length === 0) {
        setError(root, 'zones', 'Select at least one zone.');
        ok = false;
    }

    return ok;
}

function syncPanels(root) {
    const type = selectedAudienceType(root);
    root.querySelectorAll('[data-announce-panel]').forEach((panel) => {
        const show = panel.getAttribute('data-announce-panel') === type;
        panel.hidden = !show;
    });

    const zonePanel = root.querySelector('[data-announce-zone-panel]');
    if (zonePanel) {
        zonePanel.hidden = selectedZoneCoverageType(root) !== 'specific';
    }
}

function syncPreview(root, demo) {
    const title = root.querySelector('[data-announce-title]')?.value.trim() || '';
    const message = root.querySelector('[data-announce-message]')?.value.trim() || '';
    const date = root.querySelector('[data-announce-date]')?.value || '';
    const time = root.querySelector('[data-announce-time]')?.value || '';
    const place = root.querySelector('[data-announce-place]')?.value.trim() || '';

    const titleEl = root.querySelector('[data-announce-preview-title]');
    const messageEl = root.querySelector('[data-announce-preview-message]');
    const dateEl = root.querySelector('[data-announce-preview-date]');
    const timeRow = root.querySelector('[data-announce-preview-time-row]');
    const timeEl = root.querySelector('[data-announce-preview-time]');
    const placeRow = root.querySelector('[data-announce-preview-place-row]');
    const placeEl = root.querySelector('[data-announce-preview-place]');
    const audienceEl = root.querySelector('[data-announce-preview-audience]');
    const coverageEl = root.querySelector('[data-announce-preview-coverage]');
    const reachCount = root.querySelector('[data-announce-reach-count]');
    const counter = root.querySelector('[data-announce-counter]');

    if (titleEl) {
        titleEl.textContent = title || 'Your announcement title';
    }
    if (messageEl) {
        messageEl.textContent = message || 'Your announcement message will appear here.';
    }
    if (dateEl) {
        dateEl.textContent = formatDate(date);
    }

    if (timeRow && timeEl) {
        if (time) {
            timeRow.hidden = false;
            timeEl.textContent = formatTime(time);
        } else {
            timeRow.hidden = true;
            timeEl.textContent = '';
        }
    }

    if (placeRow && placeEl) {
        if (place) {
            placeRow.hidden = false;
            placeEl.textContent = place;
        } else {
            placeRow.hidden = true;
            placeEl.textContent = '';
        }
    }

    if (audienceEl) {
        audienceEl.textContent = audiencePreviewText(root);
    }

    if (coverageEl) {
        coverageEl.textContent = coveragePreviewText(root);
    }

    if (reachCount) {
        const reach = estimateReach(root, demo);
        reachCount.textContent = reach === null ? '—' : String(reach);
    }

    if (counter) {
        const length = root.querySelector('[data-announce-message]')?.value.length || 0;
        counter.textContent = `${length} / ${MESSAGE_MAX}`;
    }
}

function buildPayload(root, status) {
    const type = selectedAudienceType(root);
    const zoneType = selectedZoneCoverageType(root);
    const time = root.querySelector('[data-announce-time]')?.value || '';
    const place = root.querySelector('[data-announce-place]')?.value.trim() || '';
    const custom = readCustomAge(root);

    return {
        title: root.querySelector('[data-announce-title]')?.value.trim() || '',
        message: root.querySelector('[data-announce-message]')?.value.trim() || '',
        date: root.querySelector('[data-announce-date]')?.value || '',
        time: time || null,
        place: place || null,
        audience: {
            type,
            values: audienceValues(root),
            customAge: type === 'age' && customAgeLabel(root) && !customAgeValidationMessage(root)
                ? {
                    from: custom.fromRaw === '' ? null : Number(custom.fromRaw),
                    fromUnit: custom.fromUnit,
                    to: custom.toRaw === '' ? null : Number(custom.toRaw),
                    toUnit: custom.toUnit,
                    fromMonths: custom.fromMonths,
                    toMonths: custom.toMonths,
                }
                : null,
        },
        zones: {
            type: zoneType,
            values: zoneType === 'specific' ? zoneLabels(root) : [],
        },
        status,
    };
}

function showStatus(root, text, isError = false) {
    const status = root.querySelector('[data-announce-status]');
    if (!status) {
        return;
    }
    status.hidden = false;
    status.textContent = text;
    status.classList.toggle('lml-announce__status--error', isError);
    status.classList.toggle('lml-announce__status--ok', !isError);
}

function handleSubmit(root, status) {
    if (!validate(root)) {
        showStatus(root, 'Please complete the required fields before continuing.', true);
        const firstInvalid = root.querySelector('[aria-invalid="true"], [data-announce-error]:not([hidden])');
        firstInvalid?.scrollIntoView?.({ behavior: 'smooth', block: 'center' });
        return;
    }

    const payload = buildPayload(root, status);
    // Frontend prototype only — replace later with Laravel persistence.
    console.log('[LMLinga Announcement prototype]', payload);

    showStatus(
        root,
        'Announcement posted locally (prototype only — not stored in the database).',
        false,
    );
}

function initAnnouncement(root) {
    const demo = parseDemoReach(root);
    const form = root.querySelector('[data-announce-form]');

    const refresh = () => {
        syncPanels(root);
        syncPreview(root, demo);
    };

    root.addEventListener('lml-announce-refresh', refresh);

    root.querySelectorAll('[data-announce-audience-type], [data-announce-zone-coverage]').forEach((input) => {
        input.addEventListener('change', refresh);
    });

    root.querySelectorAll([
        '[data-announce-title]',
        '[data-announce-message]',
        '[data-announce-date]',
        '[data-announce-time]',
        '[data-announce-place]',
        '[data-announce-age-from]',
        '[data-announce-age-to]',
        '[data-announce-age-from-unit]',
        '[data-announce-age-to-unit]',
        '[data-announce-chip]',
    ].join(',')).forEach((el) => {
        el.addEventListener('input', refresh);
        el.addEventListener('change', refresh);
    });

    const zoneToggle = root.querySelector('[data-announce-custom-zone-toggle]');
    const zoneForm = root.querySelector('[data-announce-custom-zone-form]');
    const zoneInput = root.querySelector('[data-announce-custom-zone-input]');
    const zoneAdd = root.querySelector('[data-announce-custom-zone-add]');

    zoneToggle?.addEventListener('click', () => {
        const open = Boolean(zoneForm?.hidden);
        if (zoneForm) {
            zoneForm.hidden = !open;
        }
        zoneToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            zoneInput?.focus();
        }
    });

    zoneAdd?.addEventListener('click', () => {
        if (addCustomZone(root)) {
            refresh();
        }
    });

    zoneInput?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            if (addCustomZone(root)) {
                refresh();
            }
        }
    });

    form?.addEventListener('submit', (event) => {
        event.preventDefault();
        handleSubmit(root, 'sent');
    });

    renderCustomZones(root);
    refresh();
}

document.querySelectorAll('[data-lml-announcement]').forEach((root) => {
    initAnnouncement(root);
});
