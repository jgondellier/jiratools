// Accessibilité (design system Cardif) : icônes décoratives masquées aux lecteurs d'écran,
// boutons et liens « icône seule » nommés d'après leur info-bulle.
const enhanceA11y = (root) => {
    root.querySelectorAll('i.bi:not([aria-hidden])').forEach((icon) => icon.setAttribute('aria-hidden', 'true'));
    root.querySelectorAll('a[title]:not([aria-label]), button[title]:not([aria-label])').forEach((el) => {
        if ('' === el.textContent.trim()) {
            el.setAttribute('aria-label', el.getAttribute('title'));
        }
    });
};
enhanceA11y(document);

// Rafraîchissement automatique d'un bloc : <div data-autorefresh="120" data-url="...">
document.querySelectorAll('[data-autorefresh]').forEach((el) => {
    const seconds = parseInt(el.dataset.autorefresh, 10);
    if (!seconds) {
        return;
    }
    let remaining = seconds;
    const tick = async () => {
        remaining--;
        const counter = el.querySelector('[data-refresh-counter]');
        if (counter) {
            counter.textContent = remaining + ' s';
        }
        if (remaining > 0) {
            return;
        }
        remaining = seconds;
        try {
            const response = await fetch(el.dataset.url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
            if (response.ok) {
                el.innerHTML = await response.text();
                enhanceA11y(el);
            }
        } catch (e) {
            console.warn('Rafraîchissement impossible', e);
        }
    };
    setInterval(tick, 1000);
});

// Boutons "copier"
document.querySelectorAll('[data-copy-target]').forEach((btn) => {
    btn.addEventListener('click', async () => {
        const target = document.querySelector(btn.dataset.copyTarget);
        const text = target.dataset.copyHtml !== undefined ? target.innerHTML : target.innerText;
        try {
            if (target.dataset.copyHtml !== undefined && window.ClipboardItem) {
                await navigator.clipboard.write([new ClipboardItem({
                    'text/html': new Blob([text], { type: 'text/html' }),
                    'text/plain': new Blob([target.innerText], { type: 'text/plain' }),
                })]);
            } else {
                await navigator.clipboard.writeText(text);
            }
            const original = btn.innerHTML;
            btn.innerHTML = '<i class="bi bi-check2" aria-hidden="true"></i> Copié';
            setTimeout(() => { btn.innerHTML = original; }, 1500);
        } catch (e) {
            alert('Copie impossible : sélectionnez le texte manuellement.');
        }
    });
});

// Confirmation des suppressions
document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (e) => {
        if (!confirm(form.dataset.confirm)) {
            e.preventDefault();
        }
    });
});
