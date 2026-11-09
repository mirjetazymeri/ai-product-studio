function setupProductSelection() {
    const bar = document.querySelector('[data-bulk-bar]');

    if (!bar) {
        return;
    }

    const boxes = [...document.querySelectorAll('[data-product-checkbox]')];
    const selectPage = document.querySelector('[data-select-page]');
    const selectMatching = bar.querySelector('[data-select-matching]');
    const allMatching = document.querySelector('[data-all-matching]');
    const count = bar.querySelector('[data-bulk-count]');
    const total = Number(bar.dataset.total);

    const update = () => {
        const checked = boxes.filter((box) => box.checked).length;
        const pageFull = checked === boxes.length && boxes.length > 0;

        // "All matching" only makes sense while the whole page stays selected.
        if (!pageFull) {
            allMatching.disabled = true;
        }

        count.textContent = allMatching.disabled ? checked : total.toLocaleString();
        bar.hidden = checked === 0;
        selectPage.checked = pageFull;
        selectMatching.hidden = !pageFull || total <= boxes.length || !allMatching.disabled;
    };

    selectPage.addEventListener('change', () => {
        boxes.forEach((box) => (box.checked = selectPage.checked));
        update();
    });

    boxes.forEach((box) => box.addEventListener('change', update));

    selectMatching.addEventListener('click', () => {
        allMatching.disabled = false;
        allMatching.value = '1';
        update();
    });

    update();
}

function setupBulkEstimate() {
    const form = document.querySelector('[data-bulk-estimate]');

    if (!form) {
        return;
    }

    const find = (name) => form.querySelector(`[data-estimate-${name}]`);
    const remaining = Number(form.dataset.remaining);

    const update = () => {
        const products = form.querySelectorAll('[data-estimate-product]:checked').length;
        const languages = form.querySelectorAll('[data-estimate-language]:checked').length;
        const requests = products * languages;

        find('products').textContent = products;
        find('languages').textContent = languages;
        find('requests').textContent = requests;
        find('warning').hidden = requests <= remaining;
        find('submit').disabled = requests === 0 || requests > remaining;
    };

    form.addEventListener('change', update);
    update();

    const audience = form.querySelector('[data-ai-preference="audience"]');
    const toggleAudience = () => {
        form.querySelector('[data-ai-custom-audience-row]').hidden = audience.value !== 'custom';
    };

    audience.addEventListener('change', toggleAudience);
    toggleAudience();
}

function setupImportStrategy() {
    const form = document.querySelector('[data-import-strategy]');

    if (!form) {
        return;
    }

    const update = () => {
        const strategy = form.querySelector('input[name="strategy"]:checked')?.value;

        form.querySelectorAll('[data-strategy-panel]').forEach((panel) => {
            panel.hidden = panel.dataset.strategyPanel !== strategy;
        });
    };

    form.addEventListener('change', update);
    update();
}

function setupSingleSubmit() {
    document.querySelectorAll('[data-single-submit]').forEach((form) => {
        const buttons = () => [...form.querySelectorAll('button[type="submit"]')];

        form.addEventListener('submit', (event) => {
            if (form.dataset.submitted) {
                event.preventDefault();

                return;
            }

            form.dataset.submitted = 'true';

            // Deferred so the clicked button's name and value are still sent.
            setTimeout(() => buttons().forEach((button) => (button.disabled = true)));
        });

        // Returning with the Back button must not leave the form locked.
        window.addEventListener('pageshow', () => {
            delete form.dataset.submitted;
            buttons().forEach((button) => (button.disabled = false));
        });
    });
}

function setupAutosize() {
    document.querySelectorAll('[data-autosize]').forEach((textarea) => {
        const resize = () => {
            textarea.style.height = 'auto';
            textarea.style.height = `${textarea.scrollHeight + 2}px`;
        };

        textarea.addEventListener('input', resize);
        resize();
    });
}

function setupAutoRefresh() {
    const element = document.querySelector('[data-auto-refresh]');

    if (element) {
        setTimeout(() => window.location.reload(), Number(element.dataset.autoRefresh));
    }
}

export function setupCatalog() {
    setupProductSelection();
    setupBulkEstimate();
    setupImportStrategy();
    setupSingleSubmit();
    setupAutosize();
    setupAutoRefresh();
}
