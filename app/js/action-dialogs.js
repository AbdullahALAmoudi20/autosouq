// Links still reach the inline booking section or recovery page without JavaScript.
document.querySelectorAll('[data-dialog]').forEach(link => {
    const dialog = document.getElementById(link.dataset.dialog);
    if (!dialog || typeof dialog.showModal !== 'function') return;
    dialog.removeAttribute('open');
    const button = document.createElement('button');
    button.type = 'button';
    button.className = link.className;
    button.textContent = link.textContent;
    button.setAttribute('aria-haspopup', 'dialog');
    button.setAttribute('aria-controls', dialog.id);
    link.replaceWith(button);
    const open = () => {
        dialog.showModal();
        (dialog.querySelector('[role="alert"]') || dialog.querySelector('h2')).focus();
    };
    button.addEventListener('click', open);
    dialog.querySelector('[data-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => button.focus());
    if (location.hash === '#' + dialog.id) open();
});
