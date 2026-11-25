// Enhance the plain code field after load so verification also works without JavaScript.
(() => {
    const group = document.getElementById('code-entry');
    const first = document.getElementById('code');
    const boxes = [first];
    for (let i = 1; i < 6; i++) {
        const box = first.cloneNode(false);
        box.removeAttribute('id');
        box.removeAttribute('autofocus');
        box.autocomplete = 'off';
        box.value = '';
        group.append(box);
        boxes.push(box);
    }
    group.classList.add('code-boxes');

    function distribute(index, value) {
        const digits = value.replace(/\D/g, '');
        if (digits.length === 6) index = 0;
        const count = Math.min(digits.length, 6 - index);
        for (let i = 0; i < count; i++) boxes[index + i].value = digits[i];
        const next = boxes[Math.min(index + count, 5)];
        next.focus();
        next.select();
    }

    boxes.forEach((box, index) => {
        box.name = 'digits[]';
        box.pattern = '[0-9]';
        box.setAttribute('aria-label', 'Digit ' + (index + 1) + ' of 6');
        box.addEventListener('focus', () => box.select());
        box.addEventListener('click', () => box.select());
        box.addEventListener('input', (event) => {
            if (!box.value) return;
            const value = event.inputType === 'insertText' && event.data?.length === 1
                ? event.data : box.value;
            box.value = '';
            distribute(index, value);
        });
        box.addEventListener('paste', (event) => {
            event.preventDefault();
            distribute(index, event.clipboardData.getData('text'));
        });
        box.addEventListener('keydown', (event) => {
            if (event.key === 'Backspace' && !box.value && index > 0) {
                event.preventDefault();
                boxes[index - 1].value = '';
                boxes[index - 1].focus();
            }
        });
    });
    if (first.value) distribute(0, first.value);
})();
