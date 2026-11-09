const FALLBACK_MESSAGES = {
    0: 'We couldn\'t reach the server. Check your connection and try again.',
    401: 'Your session has ended. Please log in again.',
    403: 'You don\'t have access to this product.',
    404: 'This item no longer exists.',
    419: 'Your session has expired. Reload the page and try again.',
};

const STATUS_DISPLAY = {
    good: { label: 'Good', classes: 'bg-emerald-50 text-emerald-700 ring-emerald-200' },
    needs_improvement: { label: 'Needs improvement', classes: 'bg-amber-50 text-amber-700 ring-amber-200' },
    missing: { label: 'Missing', classes: 'bg-red-50 text-red-700 ring-red-200' },
};

const REVIEW_DELAY_MS = 500;

export async function postJson(url, csrf, body) {
    let response;

    try {
        response = await fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-TOKEN': csrf },
            body: JSON.stringify(body),
        });
    } catch {
        return { ok: false, status: 0, data: {} };
    }

    let data = {};

    try {
        data = await response.json();
    } catch {
        // A non-JSON error page falls through to the fallback message.
    }

    return { ok: response.ok, status: response.status, data };
}

export function errorMessage({ status, data }) {
    const fallback = FALLBACK_MESSAGES[status] ?? 'Something went wrong. Please try again.';
    // Laravel's own auth and CSRF errors aren't written for end users.
    const message = status in FALLBACK_MESSAGES ? fallback : (data.message ?? fallback);

    return data.retry_after ? `${message} You can retry in about ${data.retry_after} seconds.` : message;
}

export function showText(element, text) {
    element.textContent = text ?? '';
    element.hidden = !text;
}

// All AI text reaches the page through textContent, so it can never become markup.
function element(tag, className, text) {
    const node = document.createElement(tag);

    node.className = className;

    if (text !== undefined) {
        node.textContent = text;
    }

    return node;
}

function renderList(list, wrap, items) {
    list.replaceChildren(...items.map((item) => element('li', '', item)));
    wrap.hidden = items.length === 0;
}

function autosize(textarea) {
    textarea.style.height = 'auto';
    textarea.style.height = `${textarea.scrollHeight + 2}px`;
}

function asText(value) {
    return Array.isArray(value) ? value.join(', ') : (value ?? '');
}

function wordCount(text) {
    return text.trim() === '' ? 0 : text.trim().split(/\s+/).length;
}

function setupPanel(panel) {
    const find = (name) => panel.querySelector(`[data-ai-${name}]`);
    const fields = [...panel.querySelectorAll('[data-ai-field]')];
    const preferenceControls = [...panel.querySelectorAll('[data-ai-preference]')];
    const existingLanguages = JSON.parse(panel.dataset.existingLanguages);

    const setup = find('setup');
    const preview = find('preview');
    const language = find('language');
    const generateButton = find('generate');
    const generateLabel = find('generate-label');
    const buttons = [generateButton, find('regenerate'), find('cancel'), find('save')];

    let busy = false;
    let saveUrl = null;
    let reviewUrl = null;
    let hasExisting = false;
    let existingContent = null;
    let reviewTimer = null;
    let reviewSequence = 0;

    const setBusy = (value, activeButton = null) => {
        busy = value;
        panel.dataset.aiBusy = String(value);
        panel.setAttribute('aria-busy', String(value));
        buttons.forEach((button) => (button.disabled = value));
        language.disabled = value;
        preferenceControls.forEach((control) => (control.disabled = value));

        panel.querySelectorAll('[data-ai-busy-icon]').forEach((icon) => {
            icon.hidden = !(value && activeButton?.contains(icon));
        });
        panel.querySelectorAll('[data-ai-idle-icon]').forEach((icon) => (icon.hidden = value));

        generateLabel.textContent = value ? 'Generating… this can take a minute' : 'Generate with AI';
    };

    const updateExistingWarning = () => {
        find('existing-warning').hidden = !existingLanguages.includes(language.value);
    };

    const updateCustomAudience = () => {
        const select = panel.querySelector('[data-ai-preference="audience"]');

        find('custom-audience-row').hidden = select.value !== 'custom';
    };

    const clearFieldErrors = () => {
        panel.querySelectorAll('[data-ai-field-error]').forEach((node) => showText(node, ''));
    };

    const updateCounters = () => {
        fields.forEach((input) => {
            const counter = panel.querySelector(`[data-ai-counter="${input.dataset.aiField}"]`);

            if (counter) {
                const count = counter.dataset.aiCounterUnit === 'words' ? wordCount(input.value) : [...input.value].length;

                counter.textContent = `${count.toLocaleString()} · target ${counter.dataset.aiCounterTarget}`;
            }
        });
    };

    const renderCheck = (check) => {
        const display = STATUS_DISPLAY[check.status];
        const row = element('li', 'flex items-center justify-between gap-3');

        row.append(
            element('span', 'text-gray-700', check.label),
            element('span', `rounded-md px-2 py-0.5 text-xs font-medium whitespace-nowrap ring-1 ring-inset ${display.classes}`, display.label),
        );

        return row;
    };

    const renderReview = ({ seo, quality }) => {
        find('seo-score').textContent = seo.score;
        find('seo-bar').style.width = `${seo.score}%`;
        find('seo-checks').replaceChildren(...seo.checks.map(renderCheck));

        renderList(find('seo-warnings'), find('seo-warnings-wrap'), seo.warnings);
        renderList(find('seo-suggestions'), find('seo-suggestions-wrap'), seo.suggestions);
        renderList(find('quality'), find('quality-wrap'), quality.map((issue) => issue.message));

        panel.querySelectorAll('[data-ai-feedback]').forEach((node) => {
            const notes = seo.checks
                .filter((check) => check.field === node.dataset.aiFeedback && check.status !== 'good')
                .map((check) => check.message);

            node.textContent = notes.join(' ');
        });
    };

    const renderComparison = () => {
        if (!existingContent) {
            find('compare-wrap').hidden = true;

            return;
        }

        let changed = 0;

        const rows = fields.map((input) => {
            const current = asText(existingContent[input.dataset.aiField]);
            const isChanged = current.trim() !== input.value.trim();
            const row = element('div', 'py-3');
            const heading = element('div', 'flex items-center justify-between gap-3');
            const columns = element('div', 'mt-2 grid gap-3 sm:grid-cols-2');
            const column = (title, text, classes) => {
                const box = element('div', `min-w-0 rounded-md p-2.5 ${classes}`);

                box.append(
                    element('p', 'text-xs font-medium text-gray-500', title),
                    element('p', 'mt-0.5 text-sm break-words whitespace-pre-line', text || '—'),
                );

                return box;
            };

            changed += isChanged ? 1 : 0;

            heading.append(
                element('h5', 'text-sm font-medium', input.labels[0].textContent),
                element(
                    'span',
                    `rounded-md px-2 py-0.5 text-xs font-medium ${isChanged ? 'bg-indigo-50 text-indigo-700' : 'bg-gray-100 text-gray-600'}`,
                    isChanged ? 'Changed' : 'Same',
                ),
            );

            columns.append(
                column('Saved', current, 'bg-gray-50'),
                column('New', input.value, isChanged ? 'bg-indigo-50' : 'bg-gray-50'),
            );

            row.append(heading, columns);

            return row;
        });

        find('compare').replaceChildren(...rows);
        find('compare-count').textContent = `(${changed} of ${fields.length} fields changed)`;
        find('compare-wrap').hidden = false;
    };

    const currentFields = () => Object.fromEntries(fields.map((input) => [input.dataset.aiField, input.value]));

    const cancelPendingReview = () => {
        clearTimeout(reviewTimer);
        reviewSequence += 1;
    };

    // Runs Laravel's rule-based checks only. It makes no AI request.
    const scheduleReview = () => {
        clearTimeout(reviewTimer);

        reviewTimer = setTimeout(async () => {
            reviewSequence += 1;

            const sequence = reviewSequence;
            const result = await postJson(reviewUrl, panel.dataset.csrf, currentFields());

            // An older response must not replace feedback for newer text.
            if (result.ok && sequence === reviewSequence) {
                renderReview(result.data.review);
            }
        }, REVIEW_DELAY_MS);
    };

    const showSetup = () => {
        cancelPendingReview();
        preview.hidden = true;
        setup.hidden = false;
        saveUrl = null;
        reviewUrl = null;
    };

    const fillPreview = (data) => {
        cancelPendingReview();

        fields.forEach((input) => {
            input.value = asText(data.content[input.dataset.aiField]);
        });

        saveUrl = data.save_url;
        reviewUrl = data.review_url;
        hasExisting = Boolean(data.existing);
        existingContent = data.existing;

        find('preview-language').textContent = data.language;
        find('confirm-language').textContent = data.language;
        find('preferences').textContent = data.preferences;
        find('confirm').checked = false;
        find('confirm-row').hidden = !hasExisting;
        find('usage').textContent = data.usage.total === null ? '' : `This generation used ${data.usage.total.toLocaleString()} tokens.`;
        find('remaining').textContent = data.remaining_today;

        clearFieldErrors();
        showText(find('preview-error'), '');
        setup.hidden = true;
        preview.hidden = false;

        panel.querySelectorAll('[data-ai-autosize]').forEach(autosize);
        updateCounters();
        renderComparison();
        renderReview(data.review);

        const dialog = panel.closest('dialog');

        if (dialog) {
            dialog.scrollTo({ top: 0 });
        } else {
            preview.scrollIntoView({ block: 'nearest' });
        }
    };

    const generate = async (regenerate, activeButton, errorNode) => {
        if (busy) {
            return;
        }

        setBusy(true, activeButton);
        showText(errorNode, '');
        cancelPendingReview();

        const preferences = Object.fromEntries(preferenceControls.map((control) => [control.dataset.aiPreference, control.value]));

        const result = await postJson(panel.dataset.generateUrl, panel.dataset.csrf, {
            ...preferences,
            language: language.value,
            regenerate,
        });

        setBusy(false);

        if (result.ok) {
            fillPreview(result.data);
        } else {
            showText(errorNode, errorMessage(result));
        }
    };

    const save = async () => {
        if (busy || !saveUrl) {
            return;
        }

        if (hasExisting && !find('confirm').checked) {
            showText(find('preview-error'), 'Tick the box to confirm you want to replace the existing content.');

            return;
        }

        cancelPendingReview();
        setBusy(true, find('save'));
        clearFieldErrors();
        showText(find('preview-error'), '');

        const result = await postJson(saveUrl, panel.dataset.csrf, {
            ...currentFields(),
            confirm_overwrite: find('confirm').checked,
        });

        if (result.ok) {
            window.location.assign(result.data.redirect);

            return;
        }

        setBusy(false);

        if (result.status === 422 && result.data.errors) {
            Object.entries(result.data.errors).forEach(([key, messages]) => {
                const node = panel.querySelector(`[data-ai-field-error="${key.split('.')[0]}"]`);

                if (node) {
                    showText(node, messages[0]);
                }
            });
        }

        if (result.data.code === 'content_exists') {
            hasExisting = true;
            find('confirm-row').hidden = false;
        }

        showText(find('preview-error'), errorMessage(result));
    };

    language.addEventListener('change', updateExistingWarning);
    panel.querySelector('[data-ai-preference="audience"]').addEventListener('change', updateCustomAudience);
    generateButton.addEventListener('click', () => generate(false, generateButton, find('error')));
    find('regenerate').addEventListener('click', () => generate(true, find('regenerate'), find('preview-error')));
    find('cancel').addEventListener('click', showSetup);

    preview.addEventListener('input', (event) => {
        if (event.target.matches('[data-ai-autosize]')) {
            autosize(event.target);
        }

        if (event.target.matches('[data-ai-field]') && !busy) {
            updateCounters();
            renderComparison();
            scheduleReview();
        }
    });

    preview.addEventListener('submit', (event) => {
        event.preventDefault();
        save();
    });

    updateExistingWarning();
    updateCustomAudience();
}

function setupDialogs() {
    const dialog = document.querySelector('#ai-dialog');

    if (!dialog) {
        return;
    }

    const isBusy = () => dialog.querySelector('[data-ai-panel]').dataset.aiBusy === 'true';

    document.querySelector('[data-ai-open]').addEventListener('click', () => dialog.showModal());

    dialog.querySelector('[data-ai-close]').addEventListener('click', () => {
        if (!isBusy()) {
            dialog.close();
        }
    });

    // Escape must not hide a request that is still running.
    dialog.addEventListener('cancel', (event) => {
        if (isBusy()) {
            event.preventDefault();
        }
    });
}

export function setupAiPanels() {
    document.querySelectorAll('[data-ai-panel]').forEach(setupPanel);
    setupDialogs();
}
