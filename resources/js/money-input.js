/** A máscara trabalha apenas com strings; o servidor continua validando valor e limites. */
export function formatReaisInput(value) {
    if (value === '' || !/^\d+(?:,\d*)?$/.test(value)) return value;
    const digits = value.replace(',', '').replace(/^0+/, '') || '0';
    if (digits.length > 19) return value;
    const padded = digits.padStart(3, '0');
    return `${padded.slice(0, -2)},${padded.slice(-2)}`;
}

/** Na colagem, um valor inteiro representa reais; não descartar sinais ou texto inválido. */
export function pastedReais(value) {
    if (value.length > 20 || !/^\d+(?:,\d{1,2})?$/.test(value)) return null;
    const [whole, fraction = ''] = value.split(',');
    const digits = (whole + fraction.padEnd(2, '0')).replace(/^0+/, '') || '0';
    return digits.length <= 19 ? formatReaisInput(digits) : null;
}

export function maskReaisInput(input) {
    input.setCustomValidity('');
    const rightDigits = input.value.slice(input.selectionStart ?? input.value.length).replace(/\D/g, '').length;
    // O teclado decimal pode enviar uma vírgula; o separador já é inserido pela máscara.
    const value = /^[\d,]+$/.test(input.value) ? input.value.replace(/,/g, '') : input.value;
    input.value = formatReaisInput(value);
    let cursor = input.value.length;
    let remaining = rightDigits;
    while (cursor > 0 && remaining > 0) {
        cursor -= 1;
        if (/\d/.test(input.value[cursor])) remaining -= 1;
    }
    input.setSelectionRange(cursor, cursor);
}

export function pasteReaisInput(event) {
    event.preventDefault();
    const input = event.currentTarget;
    const formatted = pastedReais(event.clipboardData.getData('text'));
    if (formatted === null) {
        input.setCustomValidity('Cole um valor em reais, como 10,50, sem separador de milhares.');
        input.reportValidity();
        return;
    }
    input.setCustomValidity('');
    input.value = formatted;
    input.setSelectionRange(formatted.length, formatted.length);
}
