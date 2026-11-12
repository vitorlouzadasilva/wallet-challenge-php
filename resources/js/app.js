import './bootstrap';
import '../css/app.css';
import { maskReaisInput, pasteReaisInput } from './money-input';

document.querySelectorAll('[data-money-input]').forEach((input) => {
    input.addEventListener('input', () => maskReaisInput(input));
    input.addEventListener('paste', pasteReaisInput);
});
