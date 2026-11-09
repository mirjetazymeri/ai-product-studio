import { errorMessage, postJson, showText } from './ai-panel';

function setupForm(form) {
    const find = (name) => form.querySelector(`[data-analysis-${name}]`);

    const prompt = find('prompt');
    const error = find('error');
    const start = find('start');
    const preview = find('preview');
    const label = find('label');
    const analyzeButton = find('analyze');
    const againButton = find('again');
    const createButton = find('create');

    let busy = false;

    const setBusy = (value, activeButton = null) => {
        busy = value;
        prompt.readOnly = value;
        [analyzeButton, againButton, createButton].forEach((button) => (button.disabled = value));

        form.querySelectorAll('[data-analysis-busy-icon]').forEach((icon) => {
            icon.hidden = !(value && activeButton?.contains(icon));
        });
        form.querySelectorAll('[data-analysis-idle-icon]').forEach((icon) => (icon.hidden = value));

        label.textContent = value ? 'Analyzing…' : 'Analyze with AI';
    };

    const clearFieldErrors = () => {
        form.querySelectorAll('[id$="-error"]').forEach((element) => element.remove());
        form.querySelectorAll('.field-invalid').forEach((element) => element.classList.remove('field-invalid'));
    };

    const fillPreview = (product) => {
        form.querySelectorAll('[data-analysis-field]').forEach((input) => {
            input.value = product[input.dataset.analysisField] ?? '';
        });

        clearFieldErrors();
        start.hidden = true;
        preview.hidden = false;
        preview.scrollIntoView({ block: 'nearest' });
    };

    // The description is only read here, so every failure leaves it in the textarea.
    const analyze = async (activeButton) => {
        if (busy) {
            return;
        }

        if (!prompt.value.trim()) {
            showText(error, 'Describe your product first.');
            prompt.focus();

            return;
        }

        setBusy(true, activeButton);
        showText(error, '');

        const result = await postJson(form.dataset.analyzeUrl, form.dataset.csrf, { prompt: prompt.value });

        setBusy(false);

        if (result.ok) {
            fillPreview(result.data.product);
        } else {
            showText(error, errorMessage(result));
            error.scrollIntoView({ block: 'nearest' });
        }
    };

    analyzeButton.addEventListener('click', () => analyze(analyzeButton));
    againButton.addEventListener('click', () => analyze(againButton));

    form.addEventListener('submit', (event) => {
        if (busy) {
            event.preventDefault();

            return;
        }

        createButton.disabled = true;
    });

    // Returning with the Back button must not leave Create Product disabled.
    window.addEventListener('pageshow', () => {
        createButton.disabled = busy;
    });
}

export function setupProductAnalysis() {
    document.querySelectorAll('[data-product-analysis]').forEach(setupForm);
}
