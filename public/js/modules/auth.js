import { qs, qsa } from '../core/dom.js';
import { icon } from '../core/icons.js';

function initPasswordToggles() {
  qsa('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
      const wrapper = button.closest('.nx-auth__password');
      const input = qs('input', wrapper);
      if (!input) return;

      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      button.setAttribute('aria-pressed', show ? 'true' : 'false');
      button.setAttribute('aria-label', show ? 'Ocultar contraseña' : 'Mostrar contraseña');
      button.innerHTML = icon(show ? 'eye-off' : 'eye');
      input.focus();
    });
  });
}

function initCapsWarnings() {
  qsa('[data-password-input]').forEach((input) => {
    const field = input.closest('.nx-field');
    const warning = qs('[data-caps-warning]', field);

    const update = (event) => {
      if (!warning) return;
      const active = Boolean(event.getModifierState?.('CapsLock'));
      warning.classList.toggle('is-visible', active);
    };

    input.addEventListener('keydown', update);
    input.addEventListener('keyup', update);
    input.addEventListener('blur', () => warning?.classList.remove('is-visible'));
  });
}

function strength(value) {
  let score = 0;
  if (value.length >= 8) score += 1;
  if (value.length >= 12) score += 1;
  if (/[a-z]/.test(value) && /[A-Z]/.test(value)) score += 1;
  if (/\d/.test(value) && /[^A-Za-z0-9]/.test(value)) score += 1;
  return Math.min(4, score);
}

function initStrength() {
  const input = qs('[data-password-strength-input]');
  const meter = qs('[data-password-strength]');
  const label = qs('[data-password-strength-label]');
  if (!input || !meter) return;

  const labels = [
    'Usa una frase larga o una combinación de letras, números y símbolos.',
    'Contraseña débil: agrega más caracteres.',
    'Contraseña aceptable: mezcla mayúsculas y números.',
    'Contraseña buena: añade un símbolo para reforzarla.',
    'Contraseña fuerte: no la reutilices en otros servicios.',
  ];

  const update = () => {
    const score = strength(input.value);
    meter.dataset.score = String(score);
    meter.setAttribute('aria-hidden', 'false');
    if (label) {
      label.textContent = labels[score];
      label.classList.toggle('is-strong', score >= 3);
    }
  };

  input.addEventListener('input', update);
  update();
}

function initConfirmPassword() {
  const password = qs('#password');
  const confirmation = qs('#password_confirm');
  if (!password || !confirmation) return;

  const validate = () => {
    if (!confirmation.value) {
      confirmation.setCustomValidity('');
      return;
    }
    confirmation.setCustomValidity(
      confirmation.value === password.value ? '' : 'Las contraseñas no coinciden.',
    );
  };

  password.addEventListener('input', validate);
  confirmation.addEventListener('input', validate);
}

export function initAuth() {
  initPasswordToggles();
  initCapsWarnings();
  initStrength();
  initConfirmPassword();
}
