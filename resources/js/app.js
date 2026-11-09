import { setupAiPanels } from './ai-panel';
import { setupCatalog } from './catalog';
import { setupProductAnalysis } from './product-analysis';

function setupSidebar() {
    const sidebar = document.querySelector('#sidebar');
    const backdrop = document.querySelector('#sidebar-backdrop');

    if (!sidebar || !backdrop) {
        return;
    }

    const toggle = (open) => {
        sidebar.classList.toggle('-translate-x-full', !open);
        backdrop.classList.toggle('hidden', !open);
        document.querySelector('[data-sidebar-open]').setAttribute('aria-expanded', String(open));
    };

    document.querySelector('[data-sidebar-open]').addEventListener('click', () => toggle(true));
    document.querySelector('[data-sidebar-close]').addEventListener('click', () => toggle(false));
    backdrop.addEventListener('click', () => toggle(false));
    document.addEventListener('keydown', (event) => event.key === 'Escape' && toggle(false));
}

function setupTabs() {
    document.querySelectorAll('[data-tabs]').forEach((container) => {
        const tabs = [...container.querySelectorAll('[role="tab"]')];

        const activate = (tab) => {
            tabs.forEach((candidate) => {
                const selected = candidate === tab;

                candidate.setAttribute('aria-selected', String(selected));
                candidate.tabIndex = selected ? 0 : -1;
                document.getElementById(candidate.getAttribute('aria-controls')).hidden = !selected;
            });
        };

        tabs.forEach((tab, index) => {
            tab.addEventListener('click', () => activate(tab));

            tab.addEventListener('keydown', (event) => {
                const step = { ArrowRight: 1, ArrowLeft: -1 }[event.key];

                if (step) {
                    const next = tabs[(index + step + tabs.length) % tabs.length];

                    activate(next);
                    next.focus();
                }
            });
        });
    });
}

function setupConfirmations() {
    const dialog = document.querySelector('#confirm-dialog');

    if (!dialog) {
        return;
    }

    let pendingForm = null;

    document.addEventListener('submit', (event) => {
        if (event.target.dataset.confirm === undefined) {
            return;
        }

        event.preventDefault();
        pendingForm = event.target;
        dialog.querySelector('[data-confirm-message]').textContent = pendingForm.dataset.confirm;
        dialog.showModal();
    });

    dialog.querySelector('[data-confirm-cancel]').addEventListener('click', () => dialog.close());
    dialog.querySelector('[data-confirm-accept]').addEventListener('click', () => pendingForm?.submit());
}

function setupAutoSubmit() {
    document.querySelectorAll('[data-auto-submit]').forEach((control) => {
        control.addEventListener('change', () => control.form.requestSubmit());
    });
}

setupSidebar();
setupTabs();
setupConfirmations();
setupAutoSubmit();
setupAiPanels();
setupProductAnalysis();
setupCatalog();
