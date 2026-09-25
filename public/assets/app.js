// Прогрессивное улучшение: без JS всё работает обычными формами.
document.addEventListener('click', async (event) => {
    const copy = event.target.closest('[data-copy]');
    if (!copy) return;
    const field = document.getElementById(copy.dataset.copy);
    const text = field ? field.value : '';
    try {
        await navigator.clipboard.writeText(text);
    } catch {
        field.select();
        document.execCommand('copy');
    }
    const label = copy.textContent;
    copy.textContent = 'Скопировано ✓';
    setTimeout(() => { copy.textContent = label; }, 1500);
});

document.addEventListener('submit', (event) => {
    const form = event.target;
    if (form.dataset.confirm && !confirm(form.dataset.confirm)) {
        event.preventDefault();
        return;
    }
    if (form.dataset.busy) {
        const button = form.querySelector('button');
        if (button) {
            button.disabled = true;
            button.textContent = form.dataset.busy;
        }
    }
});

document.querySelectorAll('input[type=range][data-output]').forEach((range) => {
    const out = document.getElementById(range.dataset.output);
    range.addEventListener('input', () => { out.textContent = range.value; });
});
